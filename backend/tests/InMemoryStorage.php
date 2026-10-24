<?php

declare(strict_types=1);

namespace Wohnpilot\Tests;

use Wohnpilot\Storage\NoteInfo;
use Wohnpilot\Storage\NotFoundException;
use Wohnpilot\Storage\StorageAdapter;
use Wohnpilot\Storage\StoredNote;

/** Simple storage for tests; ETag is the content hash, no conflict checks. */
final class InMemoryStorage implements StorageAdapter
{
    /** @var array<string, string> */
    public array $notes = [];
    public int $gets = 0;

    public static function fromFixtures(): self
    {
        $storage = new self();
        foreach (array_keys(Fixtures::allNotes()) as $path) {
            $storage->notes[$path] = Fixtures::read($path);
        }
        return $storage;
    }

    public function list(): array
    {
        return array_values(array_map(fn (string $path) => new NoteInfo($path, md5($this->notes[$path]), 1), array_keys($this->notes)));
    }

    public function get(string $path): StoredNote
    {
        $this->gets++;
        if (!isset($this->notes[$path])) {
            throw NotFoundException::forPath($path);
        }
        return new StoredNote($path, $this->notes[$path], md5($this->notes[$path]), 1);
    }

    public function create(string $path, string $content): StoredNote
    {
        $this->notes[$path] = $content;
        return $this->get($path);
    }

    public function update(string $path, string $content, string $etag): StoredNote
    {
        $this->notes[$path] = $content;
        return $this->get($path);
    }

    public function delete(string $path, ?string $etag = null): void
    {
        if (!isset($this->notes[$path])) {
            throw NotFoundException::forPath($path);
        }
        unset($this->notes[$path]);
    }
}
