<?php

use zemis\datebook\helpers\Ics;
use zemis\datebook\models\CalendarItem;

it('escapes text values', function() {
    expect(Ics::text('a,b;c\\d'))->toBe('a\,b\;c\\\\d')
        ->and(Ics::text("line one\r\nline two\nline three\rend"))->toBe('line one\nline two\nline three\nend')
        ->and(Ics::text("tab\tand\x07bell"))->toBe("tab\tandbell")
        ->and(Ics::uri("https://example.com/a b\n"))->toBe('https://example.com/ab');
});

it('folds long lines at 75 octets without splitting characters', function() {
    $line = 'SUMMARY:' . str_repeat('ąčęėįšųū', 20);
    $folded = Ics::fold($line);
    $parts = explode("\r\n", $folded);

    expect(count($parts))->toBeGreaterThan(1);
    foreach ($parts as $i => $part) {
        expect(strlen($part))->toBeLessThanOrEqual(75);
        if ($i > 0) {
            expect($part)->toStartWith(' ');
        }
        expect(mb_check_encoding($part, 'UTF-8'))->toBeTrue();
    }
    expect(str_replace("\r\n ", '', $folded))->toBe($line)
        ->and(Ics::fold('SHORT:line'))->toBe('SHORT:line')
        ->and(Ics::fold(str_repeat('x', 75)))->toBe(str_repeat('x', 75));
});

it('writes dates in UTC', function() {
    $date = new DateTime('2026-10-14 09:30:00', new DateTimeZone('America/Los_Angeles'));

    expect(Ics::utc($date))->toBe('20261014T163000Z')
        ->and($date->getTimezone()->getName())->toBe('America/Los_Angeles');
});

it('builds a calendar with one event per item', function() {
    $post = new CalendarItem(
        kind: CalendarItem::KIND_POST,
        elementId: 12,
        canonicalId: 12,
        siteId: 1,
        title: 'Launch, part one',
        status: CalendarItem::STATUS_PENDING,
        date: new DateTime('2026-10-14 09:30:00', new DateTimeZone('America/Los_Angeles')),
        sectionId: 3,
        sectionName: 'News',
        cpEditUrl: 'http://localhost:8080/admin/entries/news/12',
        authorName: 'Ada',
    );
    $draft = new CalendarItem(
        kind: CalendarItem::KIND_DRAFT,
        elementId: 40,
        canonicalId: 12,
        siteId: 1,
        title: 'Launch, part one',
        status: CalendarItem::STATUS_FAILED,
        date: new DateTime('2026-10-20 08:00:00', new DateTimeZone('UTC')),
        draftName: 'Fixes',
        error: 'Title cannot be blank.',
    );

    $ics = Ics::calendar('Site calendar', [$post, $draft], 'example.com', new DateTime('2026-10-01 00:00:00', new DateTimeZone('UTC')));
    $flat = str_replace("\r\n ", '', $ics);
    $lines = explode("\r\n", rtrim($ics));

    expect($lines[0])->toBe('BEGIN:VCALENDAR')
        ->and(end($lines))->toBe('END:VCALENDAR')
        ->and(substr_count($flat, 'BEGIN:VEVENT'))->toBe(2)
        ->and($flat)->toContain('X-WR-CALNAME:Site calendar')
        ->and($flat)->toContain('UID:post-12-1@example.com')
        ->and($flat)->toContain('DTSTAMP:20261001T000000Z')
        ->and($flat)->toContain('DTSTART:20261014T163000Z')
        ->and($flat)->toContain('DTEND:20261014T164500Z')
        ->and($flat)->toContain('SUMMARY:Goes live: Launch\, part one')
        ->and($flat)->toContain('DESCRIPTION:Section: News\nStatus: Pending\nAuthor: Ada\nhttp://localhost:8080/admin/entries/news/12')
        ->and($flat)->toContain('CATEGORIES:News')
        ->and($flat)->toContain('URL:http://localhost:8080/admin/entries/news/12')
        ->and($flat)->toContain('UID:draft-40-1@example.com')
        ->and($flat)->toContain('SUMMARY:Draft goes live: Launch\, part one (Fixes)')
        ->and($flat)->toContain('Error: Title cannot be blank.')
        ->and($ics)->not->toContain("\n\n");

    foreach ($lines as $line) {
        expect(strlen($line))->toBeLessThanOrEqual(75);
    }
});

it('cleans the host used in UIDs', function() {
    $item = new CalendarItem(CalendarItem::KIND_POST, 1, 1, 1, 'T', 'live', new DateTime('2026-10-14', new DateTimeZone('UTC')));

    $ics = Ics::calendar('x', [$item], 'bad host;name', new DateTime('2026-10-01', new DateTimeZone('UTC')));

    expect($ics)->toContain('UID:post-1-1@badhostname');
});
