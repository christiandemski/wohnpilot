<?php

declare(strict_types=1);

namespace Wohnpilot\Calendar;

use Wohnpilot\Domain\Day;
use Wohnpilot\Domain\GermanNumber;

/**
 * The agenda as an iCalendar feed (RFC 5545) to subscribe to: every dated
 * item as an all-day event with reminders. Unlike the API this writes German
 * sentences, because a calendar app shows the text as it is.
 */
final class IcsFeed
{
    /** Reminders before the day at 09:00: a week ahead and the day before. */
    private const WEEK_BEFORE = '-P6DT15H';
    private const DAY_BEFORE = '-PT15H';

    public function __construct(private readonly string $appUrl)
    {
    }

    /**
     * @param list<array<string, mixed>> $agenda items of AgendaService::forOverview
     * @param string $stamp creation time in UTC, like 20261024T080000Z
     */
    public function build(array $agenda, string $stamp): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Wohnpilot//Fristen//DE',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:Wohnpilot',
            'X-WR-CALDESC:' . self::text('Kündigungstage, Fristen und Termine aus Wohnpilot'),
            'REFRESH-INTERVAL;VALUE=DURATION:PT6H',
            'X-PUBLISHED-TTL:PT6H',
        ];
        foreach ($agenda as $item) {
            $event = $this->event($item);
            if ($event === null) {
                continue;
            }
            [$summary, $description, $path, $alarms] = $event;
            $date = (string) $item['date'];
            array_push(
                $lines,
                'BEGIN:VEVENT',
                'UID:' . $item['kind'] . '-' . ($item['ref']['id'] ?? 'x') . '-' . $date . '@wohnpilot',
                'DTSTAMP:' . $stamp,
                'DTSTART;VALUE=DATE:' . str_replace('-', '', $date),
                'DTEND;VALUE=DATE:' . str_replace('-', '', Day::addDays($date, 1)),
                'SUMMARY:' . self::text($summary),
                'TRANSP:TRANSPARENT',
            );
            if ($description !== '') {
                $lines[] = 'DESCRIPTION:' . self::text($description . "\n\n" . $this->appUrl . $path);
            }
            $lines[] = 'URL:' . $this->appUrl . $path;
            foreach ($alarms as $trigger) {
                array_push($lines, 'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:' . self::text($summary), 'TRIGGER:' . $trigger, 'END:VALARM');
            }
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(self::fold(...), $lines)) . "\r\n";
    }

    /** @return array{string, string, string, list<string>}|null summary, description, app path, alarm triggers */
    private function event(array $item): ?array
    {
        $name = (string) ($item['name'] ?? '');
        $contract = '/vertraege/' . rawurlencode((string) ($item['ref']['id'] ?? ''));
        $both = [self::WEEK_BEFORE, self::DAY_BEFORE];

        return match ($item['kind']) {
            'kuendigung' => [
                "Letzter Tag: $name kündigen",
                'Die Kündigung muss heute beim Anbieter sein.'
                    . (empty($item['renewsMonths']) ? '' : ' Sonst verlängert sich der Vertrag um ' . self::months((int) $item['renewsMonths']) . '.'),
                $contract,
                $both,
            ],
            'vertragsende' => [
                empty($item['cancelled']) ? "$name läuft aus" : "$name endet",
                'Letzter Vertragstag. Denk an Zählerstände, Rückgabe von Geräten und die Schlussrechnung.',
                $contract,
                [self::DAY_BEFORE],
            ],
            'sonderkuendigung' => [
                "Letzter Tag: Sonderkündigung $name",
                'Neue Preise ab ' . self::date((string) $item['effective']) . '. Bis heute kannst du wegen der Preisänderung kündigen.',
                $contract,
                $both,
            ],
            'zustimmung' => [
                'Letzter Tag: Mieterhöhung zustimmen',
                'Neue Miete ab ' . self::date((string) $item['validFrom'])
                    . (isset($item['coldRent']) ? ', ' . GermanNumber::format((float) $item['coldRent'], fmod((float) $item['coldRent'], 1.0) == 0.0 ? 0 : 2) . ' € kalt' : '')
                    . '. Prüf Mietspiegel und Begründung, bevor du zustimmst.',
                '/mietvertrag',
                $both,
            ],
            'frist' => [
                $name,
                isset($item['from']) && !empty($item['meterIds']) ? 'Ablesen ab ' . self::date((string) $item['from']) . '.' : '',
                '/fristen/' . rawurlencode((string) ($item['ref']['id'] ?? '')),
                $both,
            ],
            // Voluntary readings are no appointment.
            default => null,
        };
    }

    private static function date(string $iso): string
    {
        return Day::parse($iso)->format('d.m.Y');
    }

    private static function months(int $n): string
    {
        return $n === 1 ? '1 Monat' : "$n Monate";
    }

    /** TEXT value: backslash, semicolon, comma and line breaks escaped. */
    private static function text(string $value): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\\;', '\\,', '\\n', '\\n'], $value);
    }

    /** Lines longer than 75 octets continue on the next line after a space, never inside a UTF-8 character. */
    private static function fold(string $line): string
    {
        $parts = [];
        $limit = 75;
        while (strlen($line) > $limit) {
            $cut = $limit;
            while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
                $cut--;
            }
            $parts[] = substr($line, 0, $cut);
            $line = substr($line, $cut);
            $limit = 74;
        }
        $parts[] = $line;
        return implode("\r\n ", $parts);
    }
}
