<?php

declare(strict_types=1);

namespace Wohnpilot\App;

use Wohnpilot\Domain\Meter;
use Wohnpilot\Note\NoteDocument;
use Wohnpilot\Storage\AttachmentStore;

/**
 * What all entries have in common: deleting one. Only at the version the
 * app has seen (ETag), so a change made elsewhere is never lost unseen.
 * Its own files in anhaenge/<bereich>/<id>/ go with it; Nextcloud keeps the
 * note in its trash bin. The lease, homes and the household are not
 * deletable here.
 */
final class EntryService
{
    /** Deletable note types => their folder below anhaenge/ */
    public const DELETABLE = [
        'wartung' => 'wartung',
        'termin' => 'termine',
        'mangel' => 'maengel',
        'geraet' => 'geraete',
        'frist' => 'fristen',
        'vertrag' => 'vertraege',
        'kontakt' => 'kontakte',
        'wissen' => 'wissen',
        'zaehler' => 'zaehler',
        'nebenkosten' => 'nebenkosten',
        'checkliste' => 'umzug',
    ];

    public function __construct(
        private readonly NoteRepository $notes,
        private readonly AttachmentStore $files,
    ) {
    }

    public function delete(string $type, string $id, string $etag): void
    {
        $folder = self::DELETABLE[$type] ?? throw AppException::notFound("Typ $type");
        $stored = $this->notes->findById($type, $id) ?? throw AppException::notFound("$type $id");
        if ($etag === '') {
            throw AppException::validation(['etag' => 'required']);
        }

        $paths = [...array_column(PhotoService::of($stored), 'path'), ...array_column(DocumentService::of($stored), 'path')];
        if ($type === 'zaehler') {
            // Proof photos of the readings
            $paths = [...$paths, ...array_map(static fn ($r): string => $r->photo, Meter::fromNote(NoteDocument::parse($stored->content))->readings)];
        }
        $prefix = AttachmentStore::FOLDER . "/$folder/$id/";
        $own = array_values(array_unique(array_filter($paths, static fn (string $p): bool => str_starts_with($p, $prefix))));

        $this->notes->delete($stored->path, $etag);
        foreach ($own as $path) {
            try {
                $this->files->deleteFile($path);
            } catch (\Throwable) {
                // A file left behind does no harm; the note is gone
            }
        }
    }
}
