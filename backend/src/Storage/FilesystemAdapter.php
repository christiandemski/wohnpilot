<?php

declare(strict_types=1);

namespace Wohnpilot\Storage;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Notes and their attachments as files in a local folder. Used for local
 * development and tests; the ETag is a hash of the content.
 */
final class FilesystemAdapter implements StorageAdapter, AttachmentStore
{
    public function __construct(private readonly string $root)
    {
        if (!is_dir($root)) {
            throw new StorageException("Storage folder does not exist: $root");
        }
    }

    public function list(): array
    {
        $notes = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = str_replace('\\', '/', substr($file->getPathname(), strlen($this->root) + 1));
            if (NotePath::isValid($path)) {
                $notes[] = new NoteInfo($path, self::etag(file_get_contents($file->getPathname())), $file->getMTime());
            }
        }
        usort($notes, static fn (NoteInfo $a, NoteInfo $b): int => strcmp($a->path, $b->path));
        return $notes;
    }

    public function get(string $path): StoredNote
    {
        $file = $this->file($path);
        if (!is_file($file)) {
            throw NotFoundException::forPath($path);
        }
        clearstatcache(true, $file);
        $content = file_get_contents($file);
        return new StoredNote($path, $content, self::etag($content), filemtime($file));
    }

    public function create(string $path, string $content): StoredNote
    {
        $file = $this->file($path);
        if (is_file($file)) {
            throw AlreadyExistsException::forPath($path);
        }
        if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0775, true) && !is_dir(dirname($file))) {
            throw new StorageException("Cannot create folder for $path");
        }
        $this->write($file, $content);
        return $this->get($path);
    }

    public function update(string $path, string $content, string $etag): StoredNote
    {
        $file = $this->file($path);
        $handle = @fopen($file, 'r+');
        if ($handle === false) {
            throw NotFoundException::forPath($path);
        }
        try {
            flock($handle, LOCK_EX);
            $current = stream_get_contents($handle);
            if (self::etag($current) !== $etag) {
                throw new ConflictException(new StoredNote($path, $current, self::etag($current), filemtime($file)));
            }
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $content);
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        return $this->get($path);
    }

    public function delete(string $path, ?string $etag = null): void
    {
        $file = $this->file($path);
        $handle = @fopen($file, 'r');
        if ($handle === false) {
            throw NotFoundException::forPath($path);
        }
        try {
            flock($handle, LOCK_EX);
            $current = stream_get_contents($handle);
            if ($etag !== null && self::etag($current) !== $etag) {
                throw new ConflictException(new StoredNote($path, $current, self::etag($current), filemtime($file)));
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        unlink($file);
    }

    public function putFile(string $path, string $bytes): void
    {
        AttachmentPath::assertWritable($path);
        $file = $this->root . '/' . $path;
        if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0775, true) && !is_dir(dirname($file))) {
            throw new StorageException("Cannot create folder for $path");
        }
        $this->write($file, $bytes);
    }

    public function getFile(string $path): string
    {
        AttachmentPath::assertReadable($path);
        $file = $this->root . '/' . $path;
        if (!is_file($file)) {
            throw NotFoundException::forPath($path);
        }
        return (string) file_get_contents($file);
    }

    public function deleteFile(string $path): void
    {
        AttachmentPath::assertWritable($path);
        $file = $this->root . '/' . $path;
        if (is_file($file)) {
            unlink($file);
        }
    }

    private function file(string $path): string
    {
        NotePath::assertValid($path);
        return $this->root . '/' . $path;
    }

    private function write(string $file, string $content): void
    {
        if (file_put_contents($file, $content, LOCK_EX) === false) {
            throw new StorageException("Cannot write $file");
        }
    }

    private static function etag(string $content): string
    {
        return md5($content);
    }
}
