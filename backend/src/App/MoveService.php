<?php

declare(strict_types=1);

namespace Wohnpilot\App;

use Wohnpilot\Domain\Checklist;
use Wohnpilot\Domain\Day;
use Wohnpilot\Domain\GermanNumber;
use Wohnpilot\Domain\WorkingDay;
use Wohnpilot\Note\NoteDocument;
use Wohnpilot\Storage\StoredNote;

/**
 * Moving: a move-out checklist made from the data of the current home
 * (meters, lease, contracts, open defects, the landlord's devices), a new
 * home with a move-in checklist, and the switch to it. The old home is
 * archived, never deleted: it keeps `auszug` and is no longer the active one.
 * Checklists are notes with Markdown tasks in objekte/<id>/umzug/.
 */
final class MoveService
{
    public const STATE_ACTIVE = 'aktiv';
    public const STATE_PLANNED = 'geplant';
    public const STATE_FORMER = 'frueher';

    private const MEDIUM_NAMES = ['strom' => 'Strom', 'wasser' => 'Wasser', 'gas' => 'Gas', 'waerme' => 'Wärme', 'pv' => 'PV', 'gartenwasser' => 'Gartenwasser', 'oel' => 'Öl'];

    public function __construct(
        private readonly NoteRepository $notes,
        private readonly HouseholdService $household,
        private readonly Clock $clock,
        private readonly ?MeterService $meters = null,
        private readonly ?LeaseService $lease = null,
        private readonly ?ContractService $contracts = null,
        private readonly ?DefectService $defects = null,
        private readonly ?DeviceService $devices = null,
    ) {
    }

    /** Every home with its state and checklists: the current one, a planned one, former ones (newest first). */
    public function status(): array
    {
        $active = $this->household->household()['aktives_objekt'] ?? null;
        $checklists = [];
        foreach ($this->notes->byType('checkliste') as $stored) {
            $summary = self::checklistSummary($stored);
            $checklists[$summary['objekt']][] = $summary;
        }
        $homes = [];
        foreach ($this->notes->byType('objekt') as $stored) {
            $data = NoteDocument::parse($stored->content)->frontmatter()->all();
            $id = (string) ($data['id'] ?? '');
            $state = $id === $active ? self::STATE_ACTIVE : (is_string($data['auszug'] ?? null) ? self::STATE_FORMER : self::STATE_PLANNED);
            $homes[] = [
                'id' => $id,
                'art' => $data['art'] ?? null,
                'adresse' => $data['adresse'] ?? null,
                'einzug' => is_string($data['einzug'] ?? null) ? $data['einzug'] : null,
                'auszug' => is_string($data['auszug'] ?? null) ? $data['auszug'] : null,
                'wohnflaeche_m2' => $data['wohnflaeche_m2'] ?? null,
                'state' => $state,
                'checklists' => $checklists[$id] ?? [],
            ];
        }
        $order = [self::STATE_ACTIVE => 0, self::STATE_PLANNED => 1, self::STATE_FORMER => 2];
        usort($homes, static fn (array $a, array $b): int => [$order[$a['state']], $b['auszug'] ?? '', $a['id']] <=> [$order[$b['state']], $a['auszug'] ?? '', $b['id']]);
        return ['active' => $active, 'homes' => $homes];
    }

    /**
     * Plans moving out of the current home on `auszug`: writes the date to
     * the home and makes the checklist (once; a later date keeps your ticks).
     *
     * @param array<string, mixed> $input auszug
     */
    public function moveOut(array $input, User $user): array
    {
        $date = (new Input($input, true))->date('auszug', required: true)->fields()['auszug'];
        $object = $this->household->activeObject() ?? throw new AppException('no_object', 409, 'Create an object first');
        $id = (string) $object['id'];
        if (is_string($object['einzug'] ?? null) && $date < $object['einzug']) {
            throw AppException::validation(['auszug' => 'before_move_in']);
        }
        $this->setField($id, 'auszug', $date, $user);
        $path = "objekte/$id/umzug/auszug.md";
        if (!$this->notes->exists($path)) {
            $this->notes->create($path, NoteDocument::create(
                ['typ' => 'checkliste', 'id' => $this->checklistId("auszug-$id"), 'objekt' => $id, 'art' => 'auszug', 'datum' => $date, 'erstellt_von' => $user->id],
                self::markdown($this->moveOutTasks($object, $date)),
            ));
        }
        return $this->status();
    }

    /**
     * The new home, not active yet, with its move-in checklist.
     *
     * @param array<string, mixed> $input art, bezeichnung, adresse, einzug, wohnflaeche_m2, raeume
     */
    public function moveIn(array $input, User $user): array
    {
        if (!is_string($input['einzug'] ?? null) || !Day::isValid($input['einzug'])) {
            throw AppException::validation(['einzug' => ($input['einzug'] ?? '') === '' ? 'required' : 'date']);
        }
        $object = $this->household->createObject($input, $user, activate: false);
        $id = (string) $object['id'];
        $this->notes->create("objekte/$id/umzug/einzug.md", NoteDocument::create(
            ['typ' => 'checkliste', 'id' => $this->checklistId("einzug-$id"), 'objekt' => $id, 'art' => 'einzug', 'datum' => $input['einzug'], 'erstellt_von' => $user->id],
            self::markdown($this->moveInTasks()),
        ));
        return $this->status() + ['created' => $id];
    }

    /**
     * Makes the planned home the active one. The old home keeps its move-out
     * date (the day before moving in, if none was set) and is archived.
     */
    public function switchTo(string $id, User $user): array
    {
        $target = $this->household->object($id) ?? throw AppException::notFound("Objekt $id");
        $old = $this->household->activeObject();
        if ($old !== null && $old['id'] === $id) {
            throw new AppException('already_active', 409, 'This home is the active one');
        }
        if (is_string($target['auszug'] ?? null)) {
            throw new AppException('archived', 409, 'Former homes stay archived');
        }
        if ($old !== null && !is_string($old['auszug'] ?? null)) {
            $moveIn = is_string($target['einzug'] ?? null) ? $target['einzug'] : $this->clock->today();
            $this->setField((string) $old['id'], 'auszug', Day::addDays($moveIn, -1), $user);
        }
        $this->household->makeActive($id, $user);
        return $this->status();
    }

    public function checklist(string $id): array
    {
        $stored = $this->notes->findById('checkliste', $id) ?? throw AppException::notFound("Checkliste $id");
        $note = NoteDocument::parse($stored->content);
        $home = $this->household->object((string) $note->frontmatter()->get('objekt'));
        return self::checklistSummary($stored) + [
            'etag' => $stored->etag,
            'home' => $home === null ? null : ['id' => $home['id'], 'adresse' => $home['adresse'] ?? null, 'art' => $home['art'] ?? null],
            'sections' => Checklist::sections($stored->content),
        ];
    }

    /**
     * Ticks task $index of the latest version, if it still has $text;
     * otherwise nothing is written and the answer is a conflict.
     */
    public function tick(string $id, int $index, string $text, bool $done, User $user): array
    {
        $stored = $this->notes->findById('checkliste', $id) ?? throw AppException::notFound("Checkliste $id");
        $missing = false;
        $this->notes->modifyLatest($stored->path, static function (NoteDocument $note) use ($index, $text, $done, $user, &$missing): NoteDocument {
            $before = $note->toString();
            $after = Checklist::tick($before, $index, $text, $done);
            if ($after === null) {
                $missing = true;
                return $note;
            }
            if ($after === $before) {
                return $note;
            }
            $changed = NoteDocument::parse($after);
            $changed->frontmatter()->set('geaendert_von', $user->id);
            return $changed;
        });
        if ($missing) {
            throw new AppException('conflict', 409, 'The checklist changed meanwhile', ['checklist' => $this->checklist($id)]);
        }
        return $this->checklist($id);
    }

    /** @return array<string, list<string>> heading => tasks */
    private function moveOutTasks(array $object, string $date): array
    {
        $lease = $this->lease?->lease();
        $before = [];
        if ($lease !== null) {
            // Ends with the month of the move: notice by the third working day, months − 1 before
            $noticeBy = WorkingDay::nth(Day::addMonths(Day::month($date), -(max(1, $lease->noticeMonths) - 1)), 3);
            $before[] = 'Mietvertrag kündigen, schriftlich und von allen unterschrieben (muss bis ' . self::date($noticeBy) . ' beim Vermieter sein)';
        }
        $before[] = 'Übergabetermin mit dem Vermieter ausmachen';
        foreach ($this->contracts?->active() ?? [] as $contract) {
            $name = $contract->name !== '' ? $contract->name : $contract->provider;
            $task = match ($contract->category) {
                'energie', 'internet' => "$name: Umzug melden oder zum " . self::date($date) . ' kündigen',
                'versicherung' => "$name: neue Adresse melden",
                'rundfunk' => 'Rundfunkbeitrag: neue Adresse melden',
                default => null,
            };
            if ($task !== null) {
                $before[] = $task;
            }
        }
        $before[] = 'Nachsendeauftrag bei der Post stellen';
        $before[] = 'Schönheitsreparaturen laut Mietvertrag prüfen';
        foreach ($this->defects?->list() ?? [] as $defect) {
            if ($defect['evaluation']['open']) {
                $before[] = "Offenen Mangel klären: {$defect['title']}";
            }
        }

        $day = [];
        $meters = $this->meters?->list() ?? [];
        $perMedium = array_count_values(array_column($meters, 'medium'));
        foreach ($meters as $meter) {
            $medium = self::MEDIUM_NAMES[$meter['medium']] ?? ucfirst((string) $meter['medium']);
            $where = ($perMedium[$meter['medium']] ?? 0) > 1 && ($meter['room'] ?? '') !== '' ? ' (' . self::room((string) $meter['room']) . ')' : '';
            $day[] = "Endstand $medium$where ablesen, mit Foto";
        }
        foreach ($this->devices?->list() ?? [] as $device) {
            if (($device['fields']['eigentum'] ?? null) === 'vermieter') {
                $day[] = "{$device['name']} bleibt da: Zustand fotografieren";
            }
        }
        $day[] = 'Schlüssel zählen und übergeben';
        $day[] = 'Übergabeprotokoll mit Zählerständen und Schlüsseln unterschreiben';

        $after = ['Ummelden beim Bürgeramt (innerhalb von 2 Wochen nach dem Einzug)'];
        $deposit = $lease?->deposit['betrag_eur'] ?? null;
        $after[] = is_numeric($deposit)
            ? 'Kaution zurückfordern (' . GermanNumber::format((float) $deposit, fmod((float) $deposit, 1.0) == 0.0 ? 0 : 2) . ' €)'
            : 'Kaution zurückfordern';
        $after[] = 'Letzte Nebenkostenabrechnung prüfen (der Vermieter hat dafür bis ' . self::date((string) ((int) substr($date, 0, 4) + 1) . '-12-31') . ' Zeit)';

        return ['Vorher' => $before, 'Am Auszugstag' => $day, 'Danach' => $after];
    }

    /** @return array<string, list<string>> */
    private function moveInTasks(): array
    {
        $after = ['Anmelden beim Bürgeramt mit der Wohnungsgeberbestätigung (innerhalb von 2 Wochen)'];
        foreach ($this->contracts?->active() ?? [] as $contract) {
            $name = $contract->name !== '' ? $contract->name : $contract->provider;
            if (in_array($contract->category, ['energie', 'internet'], true)) {
                $after[] = "$name: auf die neue Adresse umstellen";
            }
        }
        array_push(
            $after,
            'Neue Adresse an Bank, Arbeitgeber und Versicherungen',
            'Hausratversicherung: neue Wohnfläche melden',
            'Mietvertrag in Wohnpilot eintragen',
            'Abfuhrkalender der neuen Adresse eintragen',
            'Rauchmelder prüfen',
        );
        return [
            'Am Einzugstag' => [
                'Anfangsstände aller Zähler ablesen, mit Foto, und die Zähler in Wohnpilot anlegen',
                'Übergabeprotokoll prüfen: Mängel, Schlüssel, Zählerstände',
                'Vorhandene Mängel mit Fotos festhalten',
            ],
            'Danach' => $after,
        ];
    }

    /** @param array<string, list<string>> $sections */
    private static function markdown(array $sections): string
    {
        $parts = [];
        foreach ($sections as $title => $tasks) {
            $parts[] = "## $title\n\n" . implode('', array_map(static fn (string $t): string => "- [ ] $t\n", $tasks));
        }
        return implode("\n", $parts);
    }

    private static function checklistSummary(StoredNote $stored): array
    {
        $fields = NoteDocument::parse($stored->content)->frontmatter()->all();
        return [
            'id' => (string) ($fields['id'] ?? ''),
            'objekt' => (string) ($fields['objekt'] ?? ''),
            'art' => (string) ($fields['art'] ?? ''),
            'datum' => is_string($fields['datum'] ?? null) ? $fields['datum'] : null,
        ] + Checklist::progress($stored->content);
    }

    private function checklistId(string $base): string
    {
        return Slug::unique(Slug::from($base), fn (string $slug): bool => $this->notes->findById('checkliste', $slug) !== null);
    }

    private function setField(string $objectId, string $key, string $value, User $user): void
    {
        $stored = $this->notes->findById('objekt', $objectId) ?? throw AppException::notFound("Objekt $objectId");
        $this->notes->modifyLatest($stored->path, static function (NoteDocument $note) use ($key, $value, $user): NoteDocument {
            if ($note->frontmatter()->get($key) !== $value) {
                $note->frontmatter()->set($key, $value);
                $note->frontmatter()->set('geaendert_von', $user->id);
            }
            return $note;
        });
    }

    private static function date(string $iso): string
    {
        return Day::parse($iso)->format('d.m.Y');
    }

    private static function room(string $slug): string
    {
        return ucfirst(str_replace(['ae', 'oe', 'ue'], ['ä', 'ö', 'ü'], $slug));
    }
}
