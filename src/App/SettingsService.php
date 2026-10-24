<?php

declare(strict_types=1);

namespace Wohnpilot\App;

use Wohnpilot\Note\NoteDocument;

/**
 * Settings of the household (`einstellungen` in `_haushalt.md`), shared by
 * everyone in it. For now: the Paperless address – without it the app hides
 * Paperless fields and links.
 */
final class SettingsService
{
    public function __construct(
        private readonly NoteRepository $notes,
        private readonly HouseholdService $household,
    ) {
    }

    /** @return array{paperlessUrl: ?string, readingDay: int} */
    public function get(): array
    {
        $settings = $this->household->household()['einstellungen'] ?? [];
        $paperless = is_array($settings) && is_string($settings['paperless_url'] ?? null) ? rtrim($settings['paperless_url'], '/') : null;
        return ['paperlessUrl' => $paperless, 'readingDay' => $this->household->readingDay()];
    }

    /** @param array<string, mixed> $input paperless_url (empty removes it) */
    public function save(array $input, User $user): array
    {
        $fields = (new Input($input, false))->url('paperless_url')->fields();
        $stored = $this->notes->findById('haushalt', 'haushalt') ?? throw new AppException('no_object', 409, 'Create an object first');
        if (array_key_exists('paperless_url', $fields)) {
            $url = $fields['paperless_url'] === null ? null : rtrim($fields['paperless_url'], '/');
            $this->notes->modifyLatest($stored->path, static function (NoteDocument $note) use ($url, $user): NoteDocument {
                $settings = $note->frontmatter()->get('einstellungen');
                if ((is_array($settings) ? $settings['paperless_url'] ?? null : null) === $url) {
                    return $note;
                }
                $note->frontmatter()->setIn('einstellungen', 'paperless_url', $url);
                $note->frontmatter()->set('geaendert_von', $user->id);
                return $note;
            });
        }
        return $this->get();
    }
}
