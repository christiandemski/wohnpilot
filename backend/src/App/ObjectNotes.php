<?php

declare(strict_types=1);

namespace Wohnpilot\App;

use Wohnpilot\Note\NoteDocument;
use Wohnpilot\Storage\StoredNote;

/**
 * Notes that belong to the active home, in objekte/<objekt>/<folder>/:
 * maintenance, appointments, defects, devices, waste.
 */
final class ObjectNotes
{
    public function __construct(
        private readonly NoteRepository $notes,
        private readonly HouseholdService $household,
    ) {
    }

    public function activeObjectId(): string
    {
        return (string) ($this->household->activeObject()['id'] ?? throw new AppException('no_object', 409, 'Create an object first'));
    }

    /** @return list<StoredNote> notes of $type in the active home (none without one) */
    public function all(string $type): array
    {
        $object = $this->household->activeObject();
        return $object === null ? [] : $this->notes->byType($type, (string) $object['id']);
    }

    public function find(string $type, string $id, string $what): StoredNote
    {
        return $this->notes->findById($type, $id) ?? throw AppException::notFound("$what $id");
    }

    /**
     * Creates the note in objekte/<objekt>/<folder>/ with an id from $base
     * that is free for this type.
     *
     * @param array<string, mixed> $fields frontmatter after typ, id and objekt
     */
    public function create(string $type, string $folder, string $base, array $fields, User $user, string $body = ''): StoredNote
    {
        $object = $this->activeObjectId();
        $dir = "objekte/$object/$folder";
        $id = Slug::unique(
            Slug::from($base) ?: $type,
            fn (string $slug): bool => $this->notes->findById($type, $slug) !== null || $this->notes->exists("$dir/$slug.md"),
        );
        $note = NoteDocument::create(['typ' => $type, 'id' => $id, 'objekt' => $object] + $fields + ['erstellt_von' => $user->id], $body);
        return $this->notes->create("$dir/$id.md", $note);
    }
}
