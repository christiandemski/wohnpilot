<?php

declare(strict_types=1);

namespace Wohnpilot\App;

use Wohnpilot\Note\NoteDocument;
use Wohnpilot\Storage\StoredNote;

/**
 * Contacts (`typ: kontakt`, folder `kontakte/`). They belong to the
 * household and move with it; one tied to a home (the landlord) has
 * `objekt`. Entries point to them with `kontakt: <id>`.
 */
final class ContactService
{
    public const ROLES = ['vermieter', 'hausverwaltung', 'handwerker', 'notdienst', 'versorger', 'nachbar', 'versicherung', 'sonstiges'];
    /** Emergencies the emergency sheet knows */
    public const SCENARIOS = ['wasser', 'strom', 'gas', 'heizung'];
    /** Read as written: YAML would make +49… a number */
    private const PHONE_FIELDS = ['telefon', 'mobil'];

    public function __construct(
        private readonly NoteRepository $notes,
        private readonly HouseholdService $household,
    ) {
    }

    /** @return list<array<string, mixed>> contacts of the household and the active home, by name */
    public function list(): array
    {
        $object = $this->household->activeObject()['id'] ?? null;
        $contacts = [];
        foreach ($this->notes->byType('kontakt') as $stored) {
            $contact = $this->summary($stored);
            if ($contact['object'] === '' || $contact['object'] === $object) {
                $contacts[] = $contact;
            }
        }
        usort($contacts, static fn (array $a, array $b): int => mb_strtolower($a['name']) <=> mb_strtolower($b['name']));
        return $contacts;
    }

    /** Detail with every entry that links this contact. */
    public function detail(string $id): array
    {
        $stored = $this->find($id);
        $note = NoteDocument::parse($stored->content);
        return $this->summary($stored) + [
            'usedIn' => $this->usages($id),
            'notes' => trim($note->body()->text()),
        ];
    }

    /**
     * @param array<string, mixed> $input name, …; `zuhause: true` ties it to the active home
     */
    public function create(array $input, User $user): array
    {
        $fields = self::validate($input, true);
        $object = empty($input['zuhause']) ? null : ($this->household->activeObject()['id'] ?? null);
        $id = Slug::unique(
            Slug::from((string) $fields['name']) ?: 'kontakt',
            fn (string $slug): bool => $this->notes->findById('kontakt', $slug) !== null || $this->notes->exists("kontakte/$slug.md"),
        );
        $front = ['typ' => 'kontakt', 'id' => $id] + ($object === null ? [] : ['objekt' => $object]) + $fields + ['erstellt_von' => $user->id];
        $this->notes->create("kontakte/$id.md", NoteDocument::create($front));
        return $this->detail($id);
    }

    /**
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $original
     */
    public function update(string $id, array $changes, array $original, string $etag, User $user): array
    {
        $this->notes->updateFields($this->find($id), self::validate($changes, false), $original, $etag, $user->id);
        return $this->detail($id);
    }

    /** @return list<array{id: string, name: string, role: string, phone: ?string, mobile: ?string}> contacts that help with $scenario */
    public function forEmergency(string $scenario): array
    {
        return array_values(array_map(
            static fn (array $c): array => ['id' => $c['id'], 'name' => $c['name'], 'role' => (string) ($c['fields']['rolle'] ?? ''), 'phone' => $c['phone'], 'mobile' => $c['mobile']],
            array_filter($this->list(), static fn (array $c): bool => in_array($scenario, (array) ($c['fields']['notfall'] ?? []), true)),
        ));
    }

    /** The contact as vCard 3.0, to save it in the phone's address book. */
    public function vcard(string $id): array
    {
        $c = $this->summary($this->find($id));
        $f = $c['fields'];
        $escape = static fn (string $v): string => str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $v);
        $lines = ['BEGIN:VCARD', 'VERSION:3.0', 'FN:' . $escape($c['name']), 'N:' . $escape($c['name']) . ';;;;'];
        if (!empty($f['firma'])) {
            $lines[] = 'ORG:' . $escape((string) $f['firma']);
        }
        foreach ([['phone', 'WORK,VOICE'], ['mobile', 'CELL']] as [$key, $type]) {
            if ($c[$key] !== null && !str_starts_with($c[$key], '[')) {
                $lines[] = "TEL;TYPE=$type:" . $escape($c[$key]);
            }
        }
        if (!empty($f['email']) && !str_starts_with((string) $f['email'], '[')) {
            $lines[] = 'EMAIL;TYPE=INTERNET:' . $escape((string) $f['email']);
        }
        if (!empty($f['adresse']) && !str_starts_with((string) $f['adresse'], '[')) {
            $lines[] = 'ADR;TYPE=WORK:;;' . $escape((string) $f['adresse']) . ';;;;';
        }
        $lines[] = 'NOTE:' . $escape(trim('Wohnpilot · ' . ($f['gewerk'] ?? $f['rolle'] ?? '')));
        $lines[] = 'END:VCARD';
        return [implode("\r\n", $lines) . "\r\n", $c['id'] . '.vcf'];
    }

    /** @return list<array{type: string, id: string, title: string}> entries with `kontakt` pointing here */
    private function usages(string $id): array
    {
        $used = [];
        foreach ($this->notes->all() as $stored) {
            $fm = NoteDocument::parse($stored->content)->frontmatter();
            $links = $fm->get('kontakt');
            if (in_array($id, is_array($links) ? $links : [$links], true) && $fm->get('typ') !== 'kontakt') {
                $used[] = [
                    'type' => (string) $fm->get('typ', ''),
                    'id' => (string) $fm->get('id', ''),
                    'title' => (string) ($fm->get('titel') ?? $fm->get('name') ?? $fm->get('bezeichnung') ?? $fm->get('anbieter') ?? $fm->get('id', '')),
                ];
            }
        }
        return $used;
    }

    private function find(string $id): StoredNote
    {
        return $this->notes->findById('kontakt', $id) ?? throw AppException::notFound("Kontakt $id");
    }

    private function summary(StoredNote $stored): array
    {
        $fm = NoteDocument::parse($stored->content)->frontmatter();
        $fields = $fm->all();
        foreach (self::PHONE_FIELDS as $key) {
            if (array_key_exists($key, $fields)) {
                $fields[$key] = $fm->raw($key) ?? (string) $fields[$key];
            }
        }
        return [
            'id' => (string) $fm->get('id', ''),
            'path' => $stored->path,
            'etag' => $stored->etag,
            'object' => (string) $fm->get('objekt', ''),
            'name' => (string) ($fm->get('name') ?? $fm->get('id', '')),
            'fields' => $fields,
            'phone' => $fields['telefon'] ?? null,
            'mobile' => $fields['mobil'] ?? null,
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
            ->string('firma')
            ->enum('rolle', self::ROLES, default: 'sonstiges')
            ->string('gewerk')
            ->string('telefon')
            ->string('mobil')
            ->string('email')
            ->string('adresse')
            ->enumList('notfall', self::SCENARIOS);
        $email = $check->value('email');
        if (is_string($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $check->error('email', 'email');
        }
        foreach (self::PHONE_FIELDS as $key) {
            $phone = $check->value($key);
            if (is_string($phone) && !preg_match('/^\+?[0-9 ()\/.-]{3,30}$/', $phone)) {
                $check->error($key, 'phone');
            }
        }
        return $check->fields();
    }
}
