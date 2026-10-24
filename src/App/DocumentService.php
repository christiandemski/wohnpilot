<?php

declare(strict_types=1);

namespace Wohnpilot\App;

use Wohnpilot\Note\NoteDocument;
use Wohnpilot\Note\NoteImages;
use Wohnpilot\Storage\AttachmentStore;
use Wohnpilot\Storage\NotePath;
use Wohnpilot\Storage\StoredNote;

/**
 * Documents (PDF) of contracts, the lease, devices, defects and knowledge
 * pages, e.g. a letter shared from the phone. Like photos they live in
 * anhaenge/<folder>/<id>/ and the note gets a link line relative to it:
 * `[Brief vom 15.10.](../anhaenge/vertraege/strom-stadtwerke/2026-10-24-brief.pdf)`.
 */
final class DocumentService
{
    /** Note type => folder below anhaenge/ */
    public const TYPES = ['vertrag' => 'vertraege', 'mietvertrag' => 'mietvertrag', 'geraet' => 'geraete', 'mangel' => 'maengel', 'wissen' => 'wissen', 'frist' => 'fristen', 'nebenkosten' => 'nebenkosten'];
    public const MAX_BYTES = 20 * 1024 * 1024;
    /** [title](path.pdf), not an image; groups 1 = title, 2 = path */
    private const LINK = '(?<!!)\[([^\]\n]+)\]\(\s*<?([^)\s>]+\.pdf)>?\s*\)';

    public function __construct(
        private readonly NoteRepository $notes,
        private readonly AttachmentStore $files,
        private readonly Clock $clock,
    ) {
    }

    /** @return list<array{path: string, title: string, url: string}> */
    public static function of(StoredNote $stored): array
    {
        $text = NoteDocument::parse($stored->content)->body()->text();
        preg_match_all('/' . self::LINK . '/iu', $text, $matches, PREG_SET_ORDER);
        $documents = [];
        foreach ($matches as [, $title, $src]) {
            $path = NoteImages::resolve(NotePath::folder($stored->path), rawurldecode($src));
            if ($path !== null) {
                $documents[] = ['path' => $path, 'title' => $title, 'url' => '/api/files/' . implode('/', array_map('rawurlencode', explode('/', $path)))];
            }
        }
        return $documents;
    }

    /**
     * Everything filed with an entry: its documents and its photos.
     *
     * @return array{documents: list<array{path: string, title: string, url: string}>, photos: list<array{path: string, alt: string, url: string}>}
     */
    public function files(string $type, string $id): array
    {
        $stored = $this->find($type, $id);
        return ['documents' => self::of($stored), 'photos' => PhotoService::of($stored)];
    }

    /** @return list<array{path: string, title: string, url: string}> the note's documents afterwards */
    public function add(string $type, string $id, string $bytes, string $title, User $user): array
    {
        $stored = $this->find($type, $id);
        if (strlen($bytes) > self::MAX_BYTES) {
            throw AppException::validation(['dokument' => 'too_large']);
        }
        if (!str_starts_with($bytes, '%PDF-')) {
            throw AppException::validation(['dokument' => 'not_a_pdf']);
        }
        $title = trim((string) preg_replace('/[\[\]\s]+/u', ' ', $title)) ?: 'Dokument';
        $base = AttachmentStore::FOLDER . '/' . self::TYPES[$type] . '/' . $id . '/' . $this->clock->today() . '-' . (Slug::from($title) ?: 'dokument');
        $taken = array_column(self::of($stored), 'path');
        $path = "$base.pdf";
        for ($n = 2; in_array($path, $taken, true); $n++) {
            $path = "$base-$n.pdf";
        }
        $this->files->putFile($path, $bytes);

        $noteFolder = NotePath::folder($stored->path);
        try {
            $stored = $this->notes->modifyLatest($stored->path, static function (NoteDocument $note) use ($title, $path, $noteFolder, $user): NoteDocument {
                $all = rtrim($note->toString(), "\r\n");
                $eol = $note->eol();
                $last = substr($all, (int) strrpos("\n" . $all, "\n"));
                $separator = trim($last) === '---' || preg_match('/^\[.*\]\(.*\.pdf\)$/i', trim($last)) ? '' : $eol;
                $note = NoteDocument::parse($all . $eol . $separator . "[$title](" . NoteImages::relative($noteFolder, $path) . ')' . $eol);
                $note->frontmatter()->set('geaendert_von', $user->id);
                return $note;
            });
        } catch (\Throwable $e) {
            $this->files->deleteFile($path);
            throw $e;
        }
        return self::of($stored);
    }

    /** @return list<array{path: string, title: string, url: string}> */
    public function remove(string $type, string $id, string $path, User $user): array
    {
        $stored = $this->find($type, $id);
        if (!in_array($path, array_column(self::of($stored), 'path'), true)) {
            throw AppException::notFound("Dokument $path");
        }
        $noteFolder = NotePath::folder($stored->path);
        $stored = $this->notes->modifyLatest($stored->path, static function (NoteDocument $note) use ($path, $noteFolder, $user): NoteDocument {
            $kept = [];
            foreach (preg_split('/(?<=\n)/', $note->toString()) as $line) {
                if (preg_match('/^' . self::LINK . '$/iu', trim($line), $m) && NoteImages::resolve($noteFolder, rawurldecode($m[2])) === $path) {
                    continue;
                }
                $kept[] = $line;
            }
            $text = (string) preg_replace('/(\r?\n)(?:\r?\n)+$/', '$1', implode('', $kept));
            $note = NoteDocument::parse($text);
            $note->frontmatter()->set('geaendert_von', $user->id);
            return $note;
        });
        if (str_starts_with($path, AttachmentStore::FOLDER . '/')) {
            $this->files->deleteFile($path);
        }
        return self::of($stored);
    }

    private function find(string $type, string $id): StoredNote
    {
        if (!isset(self::TYPES[$type])) {
            throw AppException::notFound("Typ $type");
        }
        return $this->notes->findById($type, $id) ?? throw AppException::notFound("$type $id");
    }
}
