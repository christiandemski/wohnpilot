<?php

declare(strict_types=1);

namespace Wohnpilot\App;

use Wohnpilot\Auth\AccountStore;
use Wohnpilot\Note\NoteDocument;

/**
 * The people of the household (`personen` in _haushalt.md): who they are,
 * their role and how they sign in (BearID user id or e-mail). Entries keep
 * the id of a person who left, so the id is never given to someone else.
 */
final class PeopleService
{
    private const MAX_NAME = 80;

    public function __construct(
        private readonly NoteRepository $notes,
        private readonly HouseholdService $household,
        private readonly ?AccountStore $accounts = null,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(User $user): array
    {
        $emergency = array_flip(array_column($this->accounts?->all() ?? [], 'person'));
        $people = [];
        foreach ($this->household->household()['personen'] ?? [] as $person) {
            if (!is_array($person) || !is_string($person['id'] ?? null)) {
                continue;
            }
            $people[] = [
                'id' => $person['id'],
                'name' => (string) ($person['name'] ?? $person['id']),
                'rolle' => Roles::of($person['rolle'] ?? null),
                'email' => is_string($person['email'] ?? null) && $person['email'] !== '' ? $person['email'] : null,
                'bearid' => isset($person['bearid']) && $person['bearid'] !== '' ? (string) $person['bearid'] : null,
                'emergency' => isset($emergency[$person['id']]),
                'self' => $person['id'] === $user->id,
            ];
        }
        return $people;
    }

    /** @param array<string, mixed> $input name, rolle, email, bearid */
    public function create(array $input, User $user): array
    {
        $fields = self::validate($input, true);
        $this->change($user, function (array $people) use ($fields): array {
            self::checkUnique($people, $fields, null);
            $taken = array_merge(array_column($people, 'id'), $this->usedIds());
            $id = Slug::unique(
                Slug::from($fields['name']) ?: 'person',
                static fn (string $slug): bool => in_array($slug, $taken, true),
            );
            $people[] = ['id' => $id] + $fields;
            return $people;
        });
        return $this->list($user);
    }

    /** @param array<string, mixed> $changes name, rolle, email, bearid (empty removes) */
    public function update(string $id, array $changes, User $user): array
    {
        $fields = self::validate($changes, false);
        $this->change($user, static function (array $people) use ($id, $fields): array {
            $index = self::indexOf($people, $id);
            self::checkUnique($people, $fields, $id);
            foreach ($fields as $key => $value) {
                if ($value === null) {
                    unset($people[$index][$key]);
                } else {
                    $people[$index][$key] = $value;
                }
            }
            self::checkAdminLeft($people);
            return $people;
        });
        return $this->list($user);
    }

    public function remove(string $id, User $user): array
    {
        if ($id === $user->id) {
            throw new AppException('self', 409, 'You cannot remove yourself');
        }
        $this->change($user, static function (array $people) use ($id): array {
            array_splice($people, self::indexOf($people, $id), 1);
            self::checkAdminLeft($people);
            return $people;
        });
        return $this->list($user);
    }

    /** @param callable(list<array<string, mixed>>): list<array<string, mixed>> $change */
    private function change(User $user, callable $change): void
    {
        $stored = $this->notes->findById('haushalt', 'haushalt') ?? throw new AppException('no_household', 409, 'Create a home first');
        $this->notes->modifyLatest($stored->path, static function (NoteDocument $note) use ($change, $user): NoteDocument {
            $people = $note->frontmatter()->get('personen');
            $people = array_values(array_filter(is_array($people) ? $people : [], 'is_array'));
            $changed = $change($people);
            if ($changed !== $people) {
                $note->frontmatter()->set('personen', $changed);
                $note->frontmatter()->set('geaendert_von', $user->id);
            }
            return $note;
        });
    }

    /** Ids still named in entries (erstellt_von, geaendert_von, person) of people who left */
    private function usedIds(): array
    {
        $ids = [];
        foreach ($this->notes->all() as $stored) {
            $fields = NoteDocument::parse($stored->content)->frontmatter()->all();
            foreach (['erstellt_von', 'geaendert_von', 'person'] as $key) {
                if (is_string($fields[$key] ?? null)) {
                    $ids[$fields[$key]] = true;
                }
            }
        }
        return array_keys($ids);
    }

    /** @param list<array<string, mixed>> $people */
    private static function indexOf(array $people, string $id): int
    {
        foreach ($people as $index => $person) {
            if (($person['id'] ?? null) === $id) {
                return $index;
            }
        }
        throw AppException::notFound("Person $id");
    }

    /** Someone has to be able to manage the household. */
    private static function checkAdminLeft(array $people): void
    {
        foreach ($people as $person) {
            if (Roles::of($person['rolle'] ?? null) === Roles::ADMIN) {
                return;
            }
        }
        throw new AppException('last_admin', 409, 'The household needs an administrator');
    }

    /** An e-mail address or BearID account belongs to one person only, or sign-in would be ambiguous. */
    private static function checkUnique(array $people, array $fields, ?string $except): void
    {
        $errors = [];
        foreach ($people as $person) {
            if (($person['id'] ?? null) === $except) {
                continue;
            }
            if (isset($fields['email']) && mb_strtolower((string) ($person['email'] ?? '')) === $fields['email']) {
                $errors['email'] = 'taken';
            }
            if (isset($fields['bearid']) && (string) ($person['bearid'] ?? '') === $fields['bearid']) {
                $errors['bearid'] = 'taken';
            }
        }
        if ($errors !== []) {
            throw AppException::validation($errors);
        }
    }

    /** @return array<string, mixed> */
    private static function validate(array $input, bool $creating): array
    {
        $check = (new Input($input, $creating))
            ->string('name', required: true)
            ->enum('rolle', Roles::ALL, default: Roles::MEMBER)
            ->string('email')
            ->string('bearid');
        if (mb_strlen((string) $check->value('name')) > self::MAX_NAME) {
            $check->error('name', 'too_long');
        }
        $email = $check->value('email');
        if (is_string($email)) {
            filter_var($email, FILTER_VALIDATE_EMAIL) ? $check->set('email', mb_strtolower($email)) : $check->error('email', 'email');
        }
        $bearId = $check->value('bearid');
        if (is_string($bearId) && !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $bearId)) {
            $check->error('bearid', 'id');
        }
        return $check->fields();
    }
}
