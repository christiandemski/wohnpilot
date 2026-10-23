<?php

declare(strict_types=1);

namespace Wohnpilot\App;

use Wohnpilot\Domain\Consumption;
use Wohnpilot\Domain\CostEstimate;
use Wohnpilot\Domain\Day;
use Wohnpilot\Domain\Meter;
use Wohnpilot\Domain\MeterNote;
use Wohnpilot\Domain\Plausibility;
use Wohnpilot\Domain\Reading;
use Wohnpilot\Domain\ReadingSchedule;
use Wohnpilot\Note\NoteDocument;
use Wohnpilot\Storage\ConflictException;
use Wohnpilot\Storage\StoredNote;

/**
 * Meters of the active object: overview, reading round, detail, new readings.
 * Everything derived (consumption, due dates, estimates) is computed here
 * on every request and never written back.
 */
final class MeterService
{
    public const MEDIA = ['strom', 'wasser', 'gas', 'waerme', 'pv', 'gartenwasser', 'oel', 'sonstiges'];
    public const INTERVALS = ['monatlich', 'quartalsweise', 'halbjaehrlich', 'jaehrlich'];
    public const OCCASIONS = ['regulär', 'Stichtag', 'Einzug', 'Auszug', 'Zählerwechsel'];

    private const DEFAULT_UNITS = ['strom' => 'kWh', 'pv' => 'kWh', 'waerme' => 'kWh', 'gas' => 'm³', 'wasser' => 'm³', 'gartenwasser' => 'm³', 'oel' => 'l'];

    public function __construct(
        private readonly NoteRepository $notes,
        private readonly HouseholdService $household,
        private readonly Clock $clock,
    ) {
    }

    /** Meters of the active object in reading-round order: by room as listed in the object, then by id. */
    public function list(): array
    {
        $object = $this->household->activeObject();
        if ($object === null) {
            return [];
        }
        $rooms = array_flip($object['raeume']);
        $readingDay = $this->household->readingDay();
        $today = $this->clock->today();

        $meters = array_map(
            fn (StoredNote $stored): array => $this->summary($stored, $readingDay, $today),
            $this->notes->byType('zaehler', $object['id']),
        );
        usort($meters, static fn (array $a, array $b): int => [$rooms[$a['room']] ?? PHP_INT_MAX, $a['id']] <=> [$rooms[$b['room']] ?? PHP_INT_MAX, $b['id']]);
        return $meters;
    }

    public function overview(): array
    {
        $object = $this->household->activeObject();
        $meters = $this->list();
        $due = array_values(array_filter($meters, static fn (array $m): bool => $m['schedule']['status'] !== ReadingSchedule::STATUS_OK));
        usort($due, static fn (array $a, array $b): int => $a['schedule']['dueDate'] <=> $b['schedule']['dueDate']);

        $next = null;
        if ($meters !== []) {
            $first = $due[0] ?? null;
            if ($first === null) {
                $byDate = $meters;
                usort($byDate, static fn (array $a, array $b): int => $a['schedule']['dueDate'] <=> $b['schedule']['dueDate']);
                $first = $byDate[0];
            }
            $next = [
                'kind' => 'ablesung',
                'dueDate' => $first['schedule']['dueDate'],
                'daysUntil' => $first['schedule']['daysUntil'],
                'status' => $first['schedule']['status'],
                'meterCount' => max(count($due), 1),
            ];
        }

        return [
            'today' => $this->clock->today(),
            'household' => ['name' => $this->household->household()['name'] ?? null],
            'object' => $object,
            'next' => $next,
            'meters' => $meters,
        ];
    }

    public function detail(string $id): array
    {
        $stored = $this->find($id);
        $meter = Meter::fromNote(NoteDocument::parse($stored->content));
        $consumption = new Consumption($meter);
        $today = $this->clock->today();

        return $this->summary($stored, $this->household->readingDay(), $today) + [
            'readings' => array_map(self::readingData(...), array_reverse($meter->readings)),
            'months' => $consumption->monthly($today, 12),
            'years' => $consumption->yearly(),
            'estimate' => CostEstimate::forYear($consumption, $today),
            'notes' => trim(NoteDocument::parse($stored->content)->body()->text()),
        ];
    }

    /**
     * Adds a reading to the newest version of the note. Sending the same
     * reading twice (e.g. after a lost answer) does not add it twice.
     *
     * @param array<string, mixed> $input date, value, occasion
     */
    public function addReading(string $id, array $input, User $user): array
    {
        $date = (string) ($input['date'] ?? $this->clock->today());
        $value = $input['value'] ?? null;
        $occasion = (string) ($input['occasion'] ?? Reading::REGULAR);
        $errors = [];
        if (!Day::isValid($date)) {
            $errors['date'] = 'date';
        } elseif ($date > $this->clock->today()) {
            $errors['date'] = 'future';
        }
        if (!is_int($value) && !is_float($value)) {
            $errors['value'] = 'number';
        } elseif ($value < 0) {
            $errors['value'] = 'negative';
        }
        if (!in_array($occasion, self::OCCASIONS, true)) {
            $errors['occasion'] = 'unknown';
        }
        if ($errors !== []) {
            throw AppException::validation($errors);
        }

        $stored = $this->find($id);
        $reading = new Reading($date, (float) $value, $occasion);
        $this->notes->modifyLatest($stored->path, static function (NoteDocument $note) use ($reading, $user): NoteDocument {
            $decimals = (int) $note->frontmatter()->get('nachkommastellen', 0);
            foreach (Meter::fromNote($note)->readings as $existing) {
                if ($existing->date === $reading->date
                    && round($existing->value, $decimals) === round($reading->value, $decimals)
                    && $existing->occasion === $reading->occasion) {
                    return $note;
                }
            }
            return MeterNote::addReading($note, $reading, $user->id);
        });

        return $this->detail($id);
    }

    /** @param array<string, mixed> $input */
    public function create(array $input, User $user): array
    {
        $object = $this->household->activeObject() ?? throw new AppException('no_object', 409, 'Create an object first');
        $fields = $this->validate($input, true, $object['raeume']);
        $base = Slug::from((string) ($input['bezeichnung'] ?? '')) ?: Slug::from($fields['medium'] . ' ' . ($fields['raum'] ?? ''));
        $id = Slug::unique($base ?: 'zaehler', fn (string $slug): bool => $this->notes->findById('zaehler', $slug) !== null);

        $frontmatter = ['typ' => 'zaehler', 'id' => $id, 'objekt' => $object['id']] + $fields + ['erstellt_von' => $user->id];
        $note = MeterNote::withEmptyTable(NoteDocument::create($frontmatter));
        $this->notes->create("objekte/{$object['id']}/zaehler/$id.md", $note);

        return $this->detail($id);
    }

    /**
     * Updates master data with a three-way merge (see FieldMerge).
     *
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $original
     */
    public function update(string $id, array $changes, array $original, string $etag, User $user): array
    {
        $object = $this->household->activeObject();
        $changes = $this->validate($changes, false, $object['raeume'] ?? []);
        $stored = $this->find($id);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $current = $attempt === 0 && $stored->etag === $etag ? $stored : $this->notes->load($stored->path);
            $note = NoteDocument::parse($current->content);
            $conflicts = FieldMerge::apply($note->frontmatter(), $changes, $original);
            if ($conflicts !== []) {
                throw new AppException('conflict', 409, 'Changed elsewhere', ['fields' => $conflicts]);
            }
            $note->frontmatter()->set('geaendert_von', $user->id);
            try {
                $this->notes->update($current->path, $note, $current->etag);
                return $this->detail($id);
            } catch (ConflictException) {
                continue;
            }
        }
        throw new AppException('conflict', 409, 'Changed elsewhere');
    }

    private function find(string $id): StoredNote
    {
        return $this->notes->findById('zaehler', $id) ?? throw AppException::notFound("Zähler $id");
    }

    private function summary(StoredNote $stored, int $readingDay, string $today): array
    {
        $note = NoteDocument::parse($stored->content);
        $fm = $note->frontmatter();
        $meter = Meter::fromNote($note);
        $consumption = new Consumption($meter);
        $latest = $meter->latest();
        $months = $consumption->monthly($today, 6);

        return [
            'id' => $meter->id,
            'path' => $stored->path,
            'etag' => $stored->etag,
            'name' => $fm->get('bezeichnung'),
            'medium' => $meter->medium,
            'unit' => $meter->unit,
            'number' => $meter->number,
            'room' => $meter->room,
            'digits' => $meter->digits,
            'decimals' => $meter->decimals,
            'interval' => $meter->interval,
            'tariff' => $fm->get('tarif'),
            'latest' => $latest === null ? null : self::readingData($latest),
            'schedule' => ReadingSchedule::next($meter, $readingDay, $today),
            'check' => [
                'typicalDailyRate' => $latest === null ? null : $consumption->typicalDailyRate($latest->date),
                'highFactor' => Plausibility::HIGH_FACTOR,
            ],
            'currentMonth' => $months[count($months) - 1],
            'recentMonths' => $months,
            'modifiedBy' => $fm->get('geaendert_von') ?? $fm->get('erstellt_von'),
        ];
    }

    private static function readingData(Reading $reading): array
    {
        return ['date' => $reading->date, 'value' => $reading->value, 'occasion' => $reading->occasion, 'photo' => $reading->photo, 'by' => $reading->by];
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string> $rooms
     * @return array<string, mixed>
     */
    private function validate(array $input, bool $creating, array $rooms): array
    {
        $errors = [];
        $fields = [];
        $has = static fn (string $key): bool => $creating || array_key_exists($key, $input);

        if ($has('medium')) {
            if (!in_array($input['medium'] ?? null, self::MEDIA, true)) {
                $errors['medium'] = 'required';
            } else {
                $fields['medium'] = $input['medium'];
            }
        }
        if (array_key_exists('bezeichnung', $input)) {
            $fields['bezeichnung'] = trim((string) $input['bezeichnung']) ?: null;
        }
        if (array_key_exists('zaehlernummer', $input)) {
            $fields['zaehlernummer'] = trim((string) $input['zaehlernummer']) ?: null;
        }
        if ($has('einheit')) {
            $unit = trim((string) ($input['einheit'] ?? '')) ?: (self::DEFAULT_UNITS[$fields['medium'] ?? ''] ?? '');
            if ($unit === '') {
                $errors['einheit'] = 'required';
            }
            $fields['einheit'] = $unit;
        }
        foreach (['stellen' => [1, 9, 6], 'nachkommastellen' => [0, 4, 0]] as $key => [$min, $max, $default]) {
            if ($has($key)) {
                $value = $input[$key] ?? $default;
                if (!is_int($value) || $value < $min || $value > $max) {
                    $errors[$key] = 'range';
                }
                $fields[$key] = $value;
            }
        }
        if (array_key_exists('raum', $input)) {
            $room = Slug::from((string) $input['raum']);
            if ($room !== '' && $rooms !== [] && !in_array($room, $rooms, true)) {
                $errors['raum'] = 'unknown';
            }
            $fields['raum'] = $room ?: null;
        }
        if ($has('ablese_intervall')) {
            $interval = $input['ablese_intervall'] ?? 'monatlich';
            if (!in_array($interval, self::INTERVALS, true)) {
                $errors['ablese_intervall'] = 'unknown';
            }
            $fields['ablese_intervall'] = $interval;
        }
        if (array_key_exists('tarif', $input)) {
            $tarif = [];
            foreach (['arbeitspreis_ct', 'grundpreis_eur_monat', 'abschlag_eur'] as $key) {
                $value = $input['tarif'][$key] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }
                if (!is_numeric($value) || $value < 0) {
                    $errors["tarif.$key"] = 'number';
                    continue;
                }
                $tarif[$key] = $value + 0;
            }
            $fields['tarif'] = $tarif === [] ? null : $tarif;
        }
        if ($errors !== []) {
            throw AppException::validation($errors);
        }
        return $creating ? array_filter($fields, static fn ($v) => $v !== null) : $fields;
    }
}
