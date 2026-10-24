<?php

declare(strict_types=1);

namespace Wohnpilot\App;

use Wohnpilot\Domain\Device;
use Wohnpilot\Note\NoteDocument;
use Wohnpilot\Storage\StoredNote;

/** Devices of the active home (`typ: geraet`, folder `geraete/`) with warranty and statutory liability. */
final class DeviceService
{
    public function __construct(
        private readonly NoteRepository $notes,
        private readonly ObjectNotes $objects,
        private readonly MaintenanceService $maintenance,
        private readonly Clock $clock,
    ) {
    }

    /** @return list<array<string, mixed>> by room, then name */
    public function list(): array
    {
        $today = $this->clock->today();
        $items = array_map(fn (StoredNote $s): array => $this->summary($s, $today), $this->objects->all('geraet'));
        usort($items, static fn (array $a, array $b): int => [$a['fields']['raum'] ?? '', mb_strtolower($a['name'])] <=> [$b['fields']['raum'] ?? '', mb_strtolower($b['name'])]);
        return $items;
    }

    /** @return list<Device> */
    public function devices(): array
    {
        return array_map(static fn (StoredNote $s): Device => Device::fromNote(NoteDocument::parse($s->content)), $this->objects->all('geraet'));
    }

    public function detail(string $id): array
    {
        $stored = $this->objects->find('geraet', $id, 'Gerät');
        $note = NoteDocument::parse($stored->content);
        $tasks = array_values(array_filter($this->maintenance->list(), static fn (array $t): bool => in_array($id, (array) ($t['fields']['bezieht_sich_auf'] ?? []), true)));
        return $this->summary($stored, $this->clock->today()) + [
            'maintenance' => array_map(static fn (array $t): array => ['id' => $t['id'], 'title' => $t['title'], 'evaluation' => $t['evaluation']], $tasks),
            'photos' => PhotoService::of($stored),
            'notes' => MaintenanceService::freeText($note),
        ];
    }

    /** @param array<string, mixed> $input */
    public function create(array $input, User $user): array
    {
        $fields = self::validate($input, true);
        $stored = $this->objects->create('geraet', 'geraete', (string) $fields['name'], $fields, $user);
        return $this->detail((string) NoteDocument::parse($stored->content)->frontmatter()->get('id'));
    }

    /**
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $original
     */
    public function update(string $id, array $changes, array $original, string $etag, User $user): array
    {
        $this->notes->updateFields($this->objects->find('geraet', $id, 'Gerät'), self::validate($changes, false), $original, $etag, $user->id);
        return $this->detail($id);
    }

    private function summary(StoredNote $stored, string $today): array
    {
        $note = NoteDocument::parse($stored->content);
        $fm = $note->frontmatter();
        $device = Device::fromNote($note);
        return [
            'id' => $device->id,
            'path' => $stored->path,
            'etag' => $stored->etag,
            'name' => $device->name,
            'fields' => $fm->all(),
            'evaluation' => $device->evaluate($today),
            'photoCount' => count(PhotoService::of($stored)),
            'modifiedBy' => $fm->get('geaendert_von') ?? $fm->get('erstellt_von'),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private static function validate(array $input, bool $creating): array
    {
        $check = (new Input($input, $creating))
            ->string('name', required: true)
            ->string('hersteller')
            ->string('modell')
            ->string('seriennummer')
            ->string('raum')
            ->enum('eigentum', Device::OWNERS, default: 'mieter')
            ->date('kaufdatum')
            ->string('haendler')
            ->number('preis_eur')
            ->int('garantie_monate', 0, 240)
            ->date('garantie_bis')
            ->int('baujahr', 1900, 2100)
            ->string('paperless');
        if (is_numeric($paperless = $check->value('paperless'))) {
            $check->set('paperless', (int) $paperless);
        }
        return $check->fields();
    }
}
