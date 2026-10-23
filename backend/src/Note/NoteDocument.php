<?php

declare(strict_types=1);

namespace Wohnpilot\Note;

/**
 * A Markdown note with optional YAML frontmatter.
 *
 * parse() followed by toString() returns the input byte for byte; edits
 * through frontmatter() and body() change only the lines they concern.
 */
final class NoteDocument
{
    private function __construct(
        private string $opening,
        private ?Frontmatter $frontmatter,
        private string $closing,
        private readonly Body $body,
        private readonly string $eol,
        private readonly bool $hadFrontmatter = true,
    ) {
    }

    public static function parse(string $text): self
    {
        $eol = Lines::detectEol($text);
        $lines = Lines::split($text);

        if ($lines !== [] && Lines::content($lines[0]) === '---' && Lines::terminator($lines[0]) !== '') {
            for ($i = 1, $count = count($lines); $i < $count; $i++) {
                $content = Lines::content($lines[$i]);
                if ($content === '---' || $content === '...') {
                    return new self(
                        $lines[0],
                        new Frontmatter(array_slice($lines, 1, $i - 1), $eol),
                        $lines[$i],
                        new Body(implode('', array_slice($lines, $i + 1)), $eol),
                        $eol,
                    );
                }
            }
        }

        return new self('', null, '', new Body($text, $eol), $eol, false);
    }

    /** Creates a new note from frontmatter values and body text. */
    public static function create(array $frontmatter, string $body = '', string $eol = "\n"): self
    {
        $lines = [];
        foreach ($frontmatter as $key => $value) {
            $lines[] = $key . ': ' . YamlInline::format($value) . $eol;
        }
        return self::parse('---' . $eol . implode('', $lines) . '---' . $eol . $body);
    }

    public function hasFrontmatter(): bool
    {
        return $this->frontmatter !== null && ($this->hadFrontmatter || $this->frontmatter->lines() !== []);
    }

    /** The frontmatter; a note without one gets a block that is written once it has keys. */
    public function frontmatter(): Frontmatter
    {
        if ($this->frontmatter === null) {
            $this->frontmatter = new Frontmatter([], $this->eol);
            $this->opening = "---" . $this->eol;
            $this->closing = "---" . $this->eol;
        }
        return $this->frontmatter;
    }

    public function body(): Body
    {
        return $this->body;
    }

    public function eol(): string
    {
        return $this->eol;
    }

    public function toString(): string
    {
        if (!$this->hasFrontmatter()) {
            return $this->body->toString();
        }
        return $this->opening . $this->frontmatter->toString() . $this->closing . $this->body->toString();
    }
}
