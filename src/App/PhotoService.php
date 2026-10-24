<?php

declare(strict_types=1);

namespace Wohnpilot\App;

use Wohnpilot\Note\NoteDocument;
use Wohnpilot\Note\NoteImages;
use Wohnpilot\Storage\AttachmentPath;
use Wohnpilot\Storage\AttachmentStore;
use Wohnpilot\Storage\NotePath;
use Wohnpilot\Storage\NotFoundException;
use Wohnpilot\Storage\StoredNote;

/**
 * Photos of defects, devices, maintenance tasks and knowledge pages, and of
 * letters for contracts, the lease and deadlines. The file goes to
 * anhaenge/<folder>/<id>/<day>-<n>.<ext>, the note gets an image line with
 * the path relative to it, so the Notes app shows the photo too.
 */
final class PhotoService
{
    /** Note type => folder below anhaenge/ */
    public const TYPES = ['mangel' => 'maengel', 'geraet' => 'geraete', 'wartung' => 'wartung', 'wissen' => 'wissen', 'vertrag' => 'vertraege', 'mietvertrag' => 'mietvertrag', 'frist' => 'fristen'];
    public const MAX_BYTES = 10 * 1024 * 1024;
    private const SIGNATURES = ['jpg' => "\xFF\xD8\xFF", 'png' => "\x89PNG\r\n\x1A\n"];

    public function __construct(
        private readonly NoteRepository $notes,
        private readonly AttachmentStore $files,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Photos in a note's text, with the URL the app loads them from.
     *
     * @return list<array{path: string, alt: string, url: string}>
     */
    public static function of(StoredNote $stored): array
    {
        $text = NoteDocument::parse($stored->content)->body()->text();
        return array_values(array_map(
            static fn (array $image): array => ['path' => $image['path'], 'alt' => $image['alt'], 'url' => '/api/files/' . implode('/', array_map('rawurlencode', explode('/', $image['path'])))],
            array_filter(NoteImages::in($text, NotePath::folder($stored->path)), static fn (array $image): bool => str_starts_with((string) AttachmentPath::type($image['path']), 'image/')),
        ));
    }

    /** @return list<array{path: string, alt: string, url: string}> the note's photos afterwards */
    public function add(string $type, string $id, string $bytes, string $alt, User $user): array
    {
        $stored = $this->find($type, $id);
        if (strlen($bytes) > self::MAX_BYTES) {
            throw AppException::validation(['foto' => 'too_large']);
        }
        $extension = self::extension($bytes) ?? throw AppException::validation(['foto' => 'not_an_image']);

        $folder = AttachmentStore::FOLDER . '/' . self::TYPES[$type] . '/' . $id;
        $day = $this->clock->today();
        $taken = array_column(self::of($stored), 'path');
        for ($n = 1; in_array($path = "$folder/$day-$n.$extension", $taken, true); $n++) {
        }
        $this->files->putFile($path, $bytes);

        $noteFolder = NotePath::folder($stored->path);
        try {
            $stored = $this->notes->modifyLatest($stored->path, static function (NoteDocument $note) use ($alt, $path, $noteFolder, $user): NoteDocument {
                $note = NoteImages::append($note, $alt, NoteImages::relative($noteFolder, $path));
                $note->frontmatter()->set('geaendert_von', $user->id);
                return $note;
            });
        } catch (\Throwable $e) {
            // Without the line in the note nobody would find the file again.
            $this->files->deleteFile($path);
            throw $e;
        }
        return self::of($stored);
    }

    /** @return list<array{path: string, alt: string, url: string}> the note's photos afterwards */
    public function remove(string $type, string $id, string $path, User $user): array
    {
        $stored = $this->find($type, $id);
        if (!in_array($path, array_column(self::of($stored), 'path'), true)) {
            throw AppException::notFound("Foto $path");
        }
        $noteFolder = NotePath::folder($stored->path);
        $stored = $this->notes->modifyLatest($stored->path, static function (NoteDocument $note) use ($path, $noteFolder, $user): NoteDocument {
            $note = NoteImages::remove($note, $path, $noteFolder);
            $note->frontmatter()->set('geaendert_von', $user->id);
            return $note;
        });
        // Only our own files; an image pasted in the Notes app stays in its folder.
        if (str_starts_with($path, AttachmentStore::FOLDER . '/')) {
            $this->files->deleteFile($path);
        }
        return self::of($stored);
    }

    /** @return array{string, string} bytes and media type of a photo or document */
    public function file(string $path): array
    {
        try {
            AttachmentPath::assertReadable($path);
            return [$this->files->getFile($path), (string) AttachmentPath::type($path)];
        } catch (\InvalidArgumentException | NotFoundException) {
            throw AppException::notFound('Datei');
        }
    }

    private function find(string $type, string $id): StoredNote
    {
        if (!isset(self::TYPES[$type])) {
            throw AppException::notFound("Typ $type");
        }
        return $this->notes->findById($type, $id) ?? throw AppException::notFound("$type $id");
    }

    /** JPEG and PNG by their first bytes, WebP by its RIFF header; the client sends JPEG. */
    public static function extension(string $bytes): ?string
    {
        foreach (self::SIGNATURES as $extension => $signature) {
            if (str_starts_with($bytes, $signature)) {
                return $extension;
            }
        }
        return str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' ? 'webp' : null;
    }
}
