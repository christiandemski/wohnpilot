<?php

declare(strict_types=1);

namespace Wohnpilot\Storage;

/**
 * Files next to the notes (photos), with paths relative to the Wohnpilot
 * root like "anhaenge/maengel/fenster-schlafzimmer/2026-10-05-1.jpg".
 * Wohnpilot writes below "anhaenge/" only; it may read images the Notes
 * app put elsewhere (".attachments.123/foto.jpg").
 */
interface AttachmentStore
{
    public const FOLDER = 'anhaenge';
    public const TYPES = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];

    /** Creates folders as needed and replaces a file of the same path. */
    public function putFile(string $path, string $bytes): void;

    /** @throws NotFoundException */
    public function getFile(string $path): string;

    /** Nothing happens if the file does not exist. */
    public function deleteFile(string $path): void;
}
