<?php

use zemis\datebook\helpers\Dates;

$la = fn() => new DateTimeZone('America/Los_Angeles');

it('builds the month grid from the first to the last week of the month', function() use ($la) {
    // October 2026 starts on a Thursday and ends on a Saturday. Weeks start on Monday.
    [$start, $end] = Dates::range(Dates::VIEW_MONTH, new DateTime('2026-10-15 13:00', $la()), 1);

    expect($start->format('Y-m-d H:i'))->toBe('2026-09-28 00:00')
        ->and($end->format('Y-m-d'))->toBe('2026-11-02')
        ->and(Dates::days($start, $end))->toHaveCount(35)
        ->and($start->getTimezone()->getName())->toBe('America/Los_Angeles');

    // With weeks starting on Sunday the grid shifts by a day.
    [$start, $end] = Dates::range(Dates::VIEW_MONTH, new DateTime('2026-10-15', $la()), 0);
    expect($start->format('Y-m-d'))->toBe('2026-09-27')
        ->and($end->format('Y-m-d'))->toBe('2026-11-01');

    // A month that starts on the week start day still gets a full grid.
    [$start, $end] = Dates::range(Dates::VIEW_MONTH, new DateTime('2027-02-10', $la()), 1);
    expect($start->format('Y-m-d'))->toBe('2027-02-01')
        ->and($end->format('Y-m-d'))->toBe('2027-03-01')
        ->and(Dates::days($start, $end))->toHaveCount(28);
});

it('builds week and list ranges', function() use ($la) {
    [$start, $end] = Dates::range(Dates::VIEW_WEEK, new DateTime('2026-10-15', $la()), 1);
    expect($start->format('Y-m-d'))->toBe('2026-10-12')
        ->and($end->format('Y-m-d'))->toBe('2026-10-19');

    [$start, $end] = Dates::range(Dates::VIEW_LIST, new DateTime('2026-10-15', $la()), 1);
    expect($start->format('Y-m-d'))->toBe('2026-10-01')
        ->and($end->format('Y-m-d'))->toBe('2026-11-01');

    expect(fn() => Dates::range('year', new DateTime(), 1))->toThrow(InvalidArgumentException::class);
});

it('moves to the previous and next page', function() use ($la) {
    $anchor = new DateTime('2026-10-31', $la());

    expect(Dates::shift(Dates::VIEW_MONTH, $anchor, 1)->format('Y-m-d'))->toBe('2026-11-01')
        ->and(Dates::shift(Dates::VIEW_MONTH, $anchor, -1)->format('Y-m-d'))->toBe('2026-09-01')
        ->and(Dates::shift(Dates::VIEW_LIST, $anchor, 1)->format('Y-m-d'))->toBe('2026-11-01')
        ->and(Dates::shift(Dates::VIEW_WEEK, $anchor, 1)->format('Y-m-d'))->toBe('2026-11-07')
        ->and(Dates::shift(Dates::VIEW_WEEK, $anchor, -1)->format('Y-m-d'))->toBe('2026-10-24');
});

it('keeps the wall clock time when a day crosses a daylight saving change', function() use ($la) {
    $date = new DateTime('2026-10-30 09:30:00', $la());

    $moved = Dates::moveToDay($date, '2026-11-03', $la());
    expect($moved->format('Y-m-d H:i T'))->toBe('2026-11-03 09:30 PST')
        ->and($moved->getOffset())->toBe(-8 * 3600)
        ->and($date->format('Y-m-d H:i'))->toBe('2026-10-30 09:30');

    // Dates in another time zone are converted first, so the local time is kept.
    $utc = new DateTime('2026-10-30 16:30:00', new DateTimeZone('UTC'));
    expect(Dates::moveToDay($utc, '2026-11-03', $la())->format('H:i T'))->toBe('09:30 PST');

    // Adding days keeps the wall clock time as well.
    expect(Dates::addDays($date, 4)->format('Y-m-d H:i T'))->toBe('2026-11-03 09:30 PST');

    expect(fn() => Dates::moveToDay($date, '2026-13-45', $la()))->toThrow(InvalidArgumentException::class)
        ->and(fn() => Dates::moveToDay($date, 'tomorrow', $la()))->toThrow(InvalidArgumentException::class);
});

it('parses days and local date times strictly', function() use ($la) {
    expect(Dates::parseDay('2026-10-14', $la())->format('Y-m-d H:i:s T'))->toBe('2026-10-14 00:00:00 PDT')
        ->and(Dates::parseDay('2026-02-31', $la())->format('Y-m-d'))->toBe((new DateTime('now', $la()))->format('Y-m-d'))
        ->and(Dates::parseDay('junk', $la())->format('Y-m-d'))->toBe((new DateTime('now', $la()))->format('Y-m-d'))
        ->and(Dates::parseDay(null, $la())->format('H:i'))->toBe('00:00');

    expect(Dates::parseLocalDateTime('2026-10-14T16:45', $la())->format('c'))->toBe('2026-10-14T16:45:00-07:00')
        ->and(Dates::parseLocalDateTime('2026-10-14 16:45:30', $la())->format('H:i:s'))->toBe('16:45:30')
        ->and(Dates::parseLocalDateTime('2026-10-14T25:00', $la()))->toBeNull()
        ->and(Dates::parseLocalDateTime('2026-02-30T10:00', $la()))->toBeNull()
        ->and(Dates::parseLocalDateTime('', $la()))->toBeNull()
        ->and(Dates::parseLocalDateTime(null, $la()))->toBeNull();
});

it('converts to UTC without touching the original', function() use ($la) {
    $date = new DateTime('2026-10-14 09:30:00', $la());
    $utc = Dates::toUtc($date);

    expect($utc->format('c'))->toBe('2026-10-14T16:30:00+00:00')
        ->and($date->getTimezone()->getName())->toBe('America/Los_Angeles')
        ->and(Dates::addMinutes($utc, 15)->format('H:i'))->toBe('16:45')
        ->and(Dates::startOfWeek(new DateTime('2026-10-14', $la()), 1)->format('Y-m-d'))->toBe('2026-10-12')
        ->and(Dates::startOfWeek(new DateTime('2026-10-14', $la()), 0)->format('Y-m-d'))->toBe('2026-10-11');
});

it('never lists more than 62 days', function() use ($la) {
    $days = Dates::days(new DateTime('2026-01-01', $la()), new DateTime('2027-01-01', $la()));

    expect($days)->toHaveCount(Dates::MAX_DAYS)
        ->and($days[0])->toBe('2026-01-01')
        ->and($days[61])->toBe('2026-03-03');
});
