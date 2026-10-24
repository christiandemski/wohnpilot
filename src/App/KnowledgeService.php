<?php

declare(strict_types=1);

namespace Wohnpilot\App;

use Wohnpilot\Note\NoteDocument;
use Wohnpilot\Storage\StoredNote;

/**
 * Knowledge of the active home (`typ: wissen`, folder `wissen/`): where
 * things are, values, how to operate things, emergency steps. The text is
 * Markdown and edited as a whole; the emergency sheet collects steps,
 * places and contacts per emergency.
 */
final class KnowledgeService
{
    public const CATEGORIES = ['ort', 'kennwert', 'bedienung', 'notfall', 'sonstiges'];

    public function __construct(
        private readonly NoteRepository $notes,
        private readonly ObjectNotes $objects,
        private readonly ContactService $contacts,
    ) {
    }

    /** @return list<array<string, mixed>> by category, then title */
    public function list(): array
    {
        $order = array_flip(self::CATEGORIES);
        $pages = array_map(fn (StoredNote $s): array => $this->summary($s), $this->objects->all('wissen'));
        usort($pages, static fn (array $a, array $b): int => [$order[$a['category']] ?? 99, mb_strtolower($a['title'])] <=> [$order[$b['category']] ?? 99, mb_strtolower($b['title'])]);
        return $pages;
    }

    public function detail(string $id): array
    {
        $stored = $this->find($id);
        $note = NoteDocument::parse($stored->content);
        return $this->summary($stored) + [
            'text' => $note->body()->toString(),
            'photos' => PhotoService::of($stored),
            'documents' => DocumentService::of($stored),
        ];
    }

    /** @param array<string, mixed> $input titel, kategorie, raum, notfall, szenario, bezieht_sich_auf, text */
    public function create(array $input, User $user): array
    {
        $fields = self::validate($input, true);
        $text = is_string($input['text'] ?? null) ? trim(str_replace("\r\n", "\n", $input['text'])) : '';
        $stored = $this->objects->create('wissen', 'wissen', (string) $fields['titel'], $fields, $user, $text === '' ? '' : $text . "\n");
        return $this->detail((string) NoteDocument::parse($stored->content)->frontmatter()->get('id'));
    }

    /**
     * Fields and text in one write; the text conflicts only if it changed elsewhere too.
     *
     * @param array<string, mixed> $changes may hold `text`
     * @param array<string, mixed> $original may hold `text`
     */
    public function update(string $id, array $changes, array $original, string $etag, User $user): array
    {
        $text = null;
        if (array_key_exists('text', $changes)) {
            $text = ['mine' => (string) $changes['text'], 'original' => (string) ($original['text'] ?? '')];
            unset($changes['text'], $original['text']);
        }
        $this->notes->updateFields($this->find($id), self::validate($changes, false), $original, $etag, $user->id, text: $text);
        return $this->detail($id);
    }

    /**
     * The emergency sheet: per emergency the steps (a page with
     * `kategorie: notfall`, `szenario: …`), the places that matter
     * (`notfall: [...]`) and the contacts to call.
     *
     * @return list<array{id: string, steps: ?array{id: string, text: string}, places: list<array<string, mixed>>, contacts: list<array<string, mixed>>}>
     */
    public function emergency(): array
    {
        $pages = [];
        foreach ($this->objects->all('wissen') as $stored) {
            $note = NoteDocument::parse($stored->content);
            $pages[] = ['fm' => $note->frontmatter()->all(), 'text' => trim($note->body()->text()), 'stored' => $stored];
        }
        $sheet = [];
        foreach (ContactService::SCENARIOS as $scenario) {
            $steps = null;
            $places = [];
            foreach ($pages as $page) {
                $fm = $page['fm'];
                if (($fm['kategorie'] ?? null) === 'notfall' && ($fm['szenario'] ?? null) === $scenario && $steps === null) {
                    $steps = ['id' => (string) $fm['id'], 'text' => (string) preg_replace('/^\s*!\[[^\]\n]*\]\([^)]*\)\s*$/m', '', $page['text'])];
                } elseif (in_array($scenario, (array) ($fm['notfall'] ?? []), true)) {
                    $photos = PhotoService::of($page['stored']);
                    $places[] = [
                        'id' => (string) $fm['id'],
                        'title' => (string) ($fm['titel'] ?? $fm['id']),
                        'room' => (string) ($fm['raum'] ?? ''),
                        // First sentence: "Hinter dem Wasserzähler an der Wand, blauer Griff."
                        'hint' => self::firstSentence($page['text']),
                        'photo' => $photos[0] ?? null,
                    ];
                }
            }
            $sheet[] = ['id' => $scenario, 'steps' => $steps === null ? null : ['id' => $steps['id'], 'text' => trim($steps['text'])], 'places' => $places, 'contacts' => $this->contacts->forEmergency($scenario)];
        }
        return $sheet;
    }

    private static function firstSentence(string $text): string
    {
        $line = trim((string) strtok(ltrim($text), "\n"));
        if ($line === '' || str_starts_with($line, '|') || str_starts_with($line, '![')) {
            return '';
        }
        return preg_match('/^(.+?[.!?])(\s|$)/u', $line, $m) ? $m[1] : $line;
    }

    private function find(string $id): StoredNote
    {
        return $this->objects->find('wissen', $id, 'Wissen');
    }

    private function summary(StoredNote $stored): array
    {
        $fm = NoteDocument::parse($stored->content)->frontmatter();
        return [
            'id' => (string) $fm->get('id', ''),
            'path' => $stored->path,
            'etag' => $stored->etag,
            'title' => (string) ($fm->get('titel') ?? $fm->get('id', '')),
            'category' => (string) $fm->get('kategorie', 'sonstiges'),
            'fields' => $fm->all(),
            'modifiedBy' => $fm->get('geaendert_von') ?? $fm->get('erstellt_von'),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private static function validate(array $input, bool $creating): array
    {
        $check = (new Input($input, $creating))
            ->string('titel', required: true)
            ->enum('kategorie', self::CATEGORIES, default: 'sonstiges')
            ->string('raum')
            ->enumList('notfall', ContactService::SCENARIOS)
            ->enum('szenario', ContactService::SCENARIOS)
            ->slugList('bezieht_sich_auf');
        if ($check->value('kategorie') === 'notfall' && $creating && $check->value('szenario') === null) {
            $check->error('szenario', 'required');
        }
        return $check->fields();
    }
}
