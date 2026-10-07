<?php

namespace zemis\datebook\helpers;

use DateTime;
use zemis\datebook\models\CalendarItem;

/**
 * Builds iCalendar (RFC 5545) feeds.
 *
 * Times are written in UTC, so every calendar app shows them in the
 * viewer's own time zone without needing VTIMEZONE blocks.
 */
final class Ics
{
    /** How long each event lasts in the feed, in minutes. */
    public const EVENT_MINUTES = 15;

    /**
     * @param CalendarItem[] $items
     */
    public static function calendar(string $name, array $items, string $host, DateTime $now): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Zemis//Datebook//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . self::text($name),
            'X-PUBLISHED-TTL:PT1H',
            'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
        ];

        $stamp = self::utc($now);
        $host = preg_replace('/[^A-Za-z0-9.-]/', '', $host) ?: 'localhost';

        foreach ($items as $item) {
            $start = Dates::toUtc($item->date);
            $end = Dates::addMinutes($start, self::EVENT_MINUTES);
            $summary = $item->getKindLabel() . ': ' . $item->title;
            if ($item->draftName) {
                $summary .= ' (' . $item->draftName . ')';
            }

            $description = [];
            if ($item->sectionName !== '') {
                $description[] = \Craft::t('datebook', 'Section: {name}', ['name' => $item->sectionName]);
            }
            $description[] = \Craft::t('datebook', 'Status: {status}', ['status' => $item->getStatusLabel()]);
            if ($item->authorName) {
                $description[] = \Craft::t('datebook', 'Author: {name}', ['name' => $item->authorName]);
            }
            if ($item->error) {
                $description[] = \Craft::t('datebook', 'Error: {error}', ['error' => $item->error]);
            }
            if ($item->cpEditUrl) {
                $description[] = $item->cpEditUrl;
            }

            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . $item->getKey() . '@' . $host;
            $lines[] = 'DTSTAMP:' . $stamp;
            $lines[] = 'DTSTART:' . self::utc($start);
            $lines[] = 'DTEND:' . self::utc($end);
            $lines[] = 'SUMMARY:' . self::text($summary);
            $lines[] = 'DESCRIPTION:' . self::text(implode("\n", $description));
            if ($item->sectionName !== '') {
                $lines[] = 'CATEGORIES:' . self::text($item->sectionName);
            }
            if ($item->cpEditUrl) {
                $lines[] = 'URL:' . self::uri($item->cpEditUrl);
            }
            $lines[] = 'TRANSP:TRANSPARENT';
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map([self::class, 'fold'], $lines)) . "\r\n";
    }

    /**
     * Formats a date as a UTC date-time value, for example 20261002T143000Z.
     */
    public static function utc(DateTime $date): string
    {
        return Dates::toUtc($date)->format('Ymd\THis\Z');
    }

    /**
     * Escapes a TEXT value.
     */
    public static function text(string $value): string
    {
        // Control characters are not allowed, except for line breaks which become \n.
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';

        return str_replace(
            ['\\', ';', ',', "\r\n", "\r", "\n"],
            ['\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'],
            $value,
        );
    }

    /**
     * Cleans a URI value. URIs are not escaped like TEXT values, but they may not
     * contain line breaks or other control characters.
     */
    public static function uri(string $value): string
    {
        return preg_replace('/[\x00-\x1F\x7F\s]/', '', $value) ?? '';
    }

    /**
     * Folds a content line so no line is longer than 75 octets.
     * Multibyte characters are never split.
     */
    public static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $result = '';
        $current = '';
        $limit = 75;

        foreach (mb_str_split($line, 1, 'UTF-8') as $char) {
            if (strlen($current) + strlen($char) > $limit) {
                $result .= $current . "\r\n ";
                $current = '';
                // A continuation line starts with a space, which counts toward the 75 octets.
                $limit = 74;
            }
            $current .= $char;
        }

        return $result . $current;
    }
}
