<?php

declare(strict_types=1);

namespace Wohnpilot\App;

use Wohnpilot\Auth\Identity;
use Wohnpilot\Domain\Day;
use Wohnpilot\Note\NoteDocument;
use Wohnpilot\Storage\ConflictException;
use Wohnpilot\Storage\StoredNote;

/**
 * Household (_haushalt.md) and objects (objekte/<id>/_objekt.md).
 */
final class HouseholdService
{
    public const HOUSEHOLD_PATH = '_haushalt.md';
    public const OBJECT_TYPES = ['haus', 'wohnung'];
    public const DEFAULT_READING_DAY = 1;

    /** @param list<string> $bootstrapUsers usernames allowed in before a household exists */
    public function __construct(
        private readonly NoteRepository $notes,
        private readonly array $bootstrapUsers = [],
    ) {
    }

    /**
     * Maps a BearID identity to a person of the household. A person matches
     * by `bearid` (the OIDC subject) or by id = username. Before a household
     * exists, only usernames from WOHNPILOT_USERS get in.
     */
    public function userFor(Identity $identity): ?User
    {
        $household = $this->household();
        if ($household === null) {
            $username = Slug::from($identity->username);
            return in_array($username, $this->bootstrapUsers, true) && $username !== ''
                ? new User($username, $identity->name ?: $identity->username, $identity->subject)
                : null;
        }
        foreach ($household['personen'] as $person) {
            $matches = (($person['bearid'] ?? null) === $identity->subject)
                || (($person['id'] ?? null) === Slug::from($identity->username));
            if ($matches) {
                return new User((string) $person['id'], (string) ($person['name'] ?? $person['id']), $identity->subject);
            }
        }
        return null;
    }

    /** @return array<string, mixed>|null the household frontmatter, null before setup */
    public function household(): ?array
    {
        $stored = $this->notes->findById('haushalt', 'haushalt');
        if ($stored === null) {
            return null;
        }
        $data = NoteDocument::parse($stored->content)->frontmatter()->all();
        $data['personen'] = is_array($data['personen'] ?? null) ? $data['personen'] : [];
        return $data;
    }

    public function readingDay(): int
    {
        $day = $this->household()['einstellungen']['ablesetag'] ?? self::DEFAULT_READING_DAY;
        return is_int($day) && $day >= 1 && $day <= 31 ? $day : self::DEFAULT_READING_DAY;
    }

    /** @return array<string, mixed>|null frontmatter of the active object plus `etag` */
    public function activeObject(): ?array
    {
        $id = $this->household()['aktives_objekt'] ?? null;
        return is_string($id) ? $this->object($id) : null;
    }

    /** @return array<string, mixed>|null */
    public function object(string $id): ?array
    {
        $stored = $this->notes->findById('objekt', $id);
        if ($stored === null) {
            return null;
        }
        return self::objectData($stored);
    }

    /**
     * Creates an object and makes it the active one. Creates the household on
     * first use with the current user as administrator.
     *
     * @param array<string, mixed> $input art, bezeichnung, adresse, einzug, wohnflaeche_m2, raeume
     */
    public function createObject(array $input, User $user): array
    {
        $fields = self::validateObject($input, true);
        $id = Slug::unique(
            Slug::from((string) ($input['bezeichnung'] ?? '')) ?: Slug::from($fields['art'] . ' ' . ($fields['adresse'] ?? '')) ?: 'objekt',
            fn (string $slug): bool => $this->notes->exists("objekte/$slug/_objekt.md"),
        );

        $note = NoteDocument::create(['typ' => 'objekt', 'id' => $id] + $fields + ['erstellt_von' => $user->id]);
        $stored = $this->notes->create("objekte/$id/_objekt.md", $note);
        $this->setActiveObject($id, $user);

        return self::objectData($stored);
    }

    /**
     * Updates object fields with a three-way merge (see FieldMerge).
     *
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $original
     */
    public function updateObject(string $id, array $changes, array $original, string $etag, User $user): array
    {
        $changes = self::validateObject($changes, false);
        $stored = $this->notes->findById('objekt', $id) ?? throw AppException::notFound("Objekt $id");
        $path = $stored->path;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $current = $attempt === 0 && $stored->etag === $etag ? $stored : $this->notes->load($path);
            $note = NoteDocument::parse($current->content);
            $conflicts = FieldMerge::apply($note->frontmatter(), $changes, $original);
            if ($conflicts !== []) {
                throw new AppException('conflict', 409, 'Changed elsewhere', ['fields' => $conflicts, 'current' => self::objectData($current)]);
            }
            $note->frontmatter()->set('geaendert_von', $user->id);
            try {
                return self::objectData($this->notes->update($path, $note, $current->etag));
            } catch (ConflictException) {
                continue;
            }
        }
        throw new AppException('conflict', 409, 'Changed elsewhere');
    }

    private function setActiveObject(string $id, User $user): void
    {
        $existing = $this->notes->findById('haushalt', 'haushalt');
        if ($existing === null) {
            $this->notes->create(self::HOUSEHOLD_PATH, NoteDocument::create(
                [
                    'typ' => 'haushalt',
                    'id' => 'haushalt',
                    'name' => 'Unser Zuhause',
                    'personen' => [['id' => $user->id, 'name' => $user->name, 'rolle' => 'verwalter', 'bearid' => $user->subject]],
                    'aktives_objekt' => $id,
                    'speicher' => 'nextcloud',
                    'einstellungen' => ['ablesetag' => self::DEFAULT_READING_DAY],
                    'erstellt_von' => $user->id,
                ],
                "Haushalt mit allen Personen und Einstellungen.\n",
            ));
            return;
        }
        $this->notes->modifyLatest($existing->path, static function (NoteDocument $note) use ($id, $user): NoteDocument {
            if ($note->frontmatter()->get('aktives_objekt') !== $id) {
                $note->frontmatter()->set('aktives_objekt', $id);
                $note->frontmatter()->set('geaendert_von', $user->id);
            }
            return $note;
        });
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed> cleaned frontmatter fields
     */
    private static function validateObject(array $input, bool $creating): array
    {
        $errors = [];
        $fields = [];
        if ($creating || array_key_exists('art', $input)) {
            if (!in_array($input['art'] ?? null, self::OBJECT_TYPES, true)) {
                $errors['art'] = 'required';
            } else {
                $fields['art'] = $input['art'];
            }
        }
        foreach (['adresse' => 200] as $key => $max) {
            if (array_key_exists($key, $input)) {
                $value = trim((string) $input[$key]);
                if (mb_strlen($value) > $max) {
                    $errors[$key] = 'too_long';
                }
                $fields[$key] = $value === '' ? null : $value;
            }
        }
        if (array_key_exists('einzug', $input)) {
            $value = (string) $input['einzug'];
            if ($value !== '' && !Day::isValid($value)) {
                $errors['einzug'] = 'date';
            }
            $fields['einzug'] = $value === '' ? null : $value;
        }
        if (array_key_exists('wohnflaeche_m2', $input)) {
            $value = $input['wohnflaeche_m2'];
            if ($value !== null && $value !== '' && (!is_numeric($value) || $value <= 0)) {
                $errors['wohnflaeche_m2'] = 'number';
            }
            $fields['wohnflaeche_m2'] = $value === null || $value === '' ? null : $value + 0;
        }
        if (array_key_exists('raeume', $input)) {
            $rooms = is_array($input['raeume']) ? $input['raeume'] : [];
            $fields['raeume'] = array_values(array_unique(array_filter(array_map(
                static fn ($room): string => Slug::from((string) $room),
                $rooms,
            ))));
        }
        if ($errors !== []) {
            throw AppException::validation($errors);
        }
        return $creating ? array_filter($fields, static fn ($v) => $v !== null && $v !== []) : $fields;
    }

    private static function objectData(StoredNote $stored): array
    {
        $data = NoteDocument::parse($stored->content)->frontmatter()->all();
        $data['raeume'] = is_array($data['raeume'] ?? null) ? array_values($data['raeume']) : [];
        $data['etag'] = $stored->etag;
        return $data;
    }
}
