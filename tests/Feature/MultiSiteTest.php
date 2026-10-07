<?php

use craft\elements\Entry;
use zemis\datebook\Datebook;
use zemis\datebook\models\CalendarItem;
use zemis\datebook\models\CalendarQuery;
use zemis\datebook\tests\Support\Fixtures;

beforeAll(fn() => Fixtures::boot());

function datebookRange(): array
{
    return [new DateTime('-10 days 00:00', new DateTimeZone('UTC')), new DateTime('+10 days 00:00', new DateTimeZone('UTC'))];
}

it('shows each site its own copy of an entry', function() {
    $entry = Fixtures::entry('news', ['title' => 'Everywhere', 'postDate' => new DateTime('+1 day 10:00', new DateTimeZone('UTC'))]);
    $second = Fixtures::secondSite();
    $admin = Fixtures::admin();
    [$start, $end] = datebookRange();
    $calendar = Fixtures::plugin()->calendar;

    $primaryItems = $calendar->getItems(new CalendarQuery($start, $end, Fixtures::primarySite()->id), $admin);
    $secondItems = $calendar->getItems(new CalendarQuery($start, $end, $second->id), $admin);

    expect(array_map(fn(CalendarItem $i) => $i->getKey(), $primaryItems))->toBe(["post-$entry->id-" . Fixtures::primarySite()->id])
        ->and(array_map(fn(CalendarItem $i) => $i->getKey(), $secondItems))->toBe(["post-$entry->id-$second->id"])
        ->and($secondItems[0]->siteId)->toBe((int)$second->id);
});

it('hides sites the user may not edit', function() {
    $permissions = array_values(array_diff(Fixtures::editorPermissions(), ['editSite:{' . Fixtures::SECOND_SITE . '}']));
    $editor = Fixtures::user('editor', $permissions);
    Fixtures::entry('news', ['title' => 'Site bound', 'postDate' => new DateTime('+1 day 10:00', new DateTimeZone('UTC'))]);
    $second = Fixtures::secondSite();
    [$start, $end] = datebookRange();
    $calendar = Fixtures::plugin()->calendar;

    expect(array_map(fn($site) => $site->handle, $calendar->getSites($editor)))->toBe(['default'])
        ->and($calendar->resolveSite($editor, $second->handle)->handle)->toBe('default')
        ->and($calendar->getItems(new CalendarQuery($start, $end, $second->id), $editor))->toBeEmpty()
        ->and($calendar->getItems(new CalendarQuery($start, $end, Fixtures::primarySite()->id), $editor))->toHaveCount(1);

    // Asking for the other site in the URL falls back to a site the user may see.
    $this->actingAs($editor)
        ->get('/admin/datebook?site=' . $second->handle)
        ->assertOk()
        ->assertSee('Site bound');
});

it('moves the entry in the requested site only when it is propagated', function() {
    $entry = Fixtures::entry('news', ['title' => 'Moved in site two', 'postDate' => new DateTime('2026-10-14 09:30:00', new DateTimeZone('UTC'))]);
    $second = Fixtures::secondSite();

    $this->actingAs(Fixtures::admin())
        ->postJson('/admin/actions/datebook/calendar/reschedule', [
            'kind' => 'post',
            'elementId' => $entry->id,
            'siteId' => $second->id,
            'day' => '2026-10-21',
        ])
        ->assertOk();

    // Post dates are shared by all sites of an entry, so both copies moved.
    foreach ([Fixtures::primarySite()->id, $second->id] as $siteId) {
        $fresh = Entry::find()->id($entry->id)->siteId($siteId)->status(null)->one();
        $local = (clone $fresh->postDate)->setTimezone(new DateTimeZone(Craft::$app->getTimeZone()));
        expect($local->format('Y-m-d'))->toBe('2026-10-21');
    }
});

it('keeps the local time of day when a move crosses a daylight saving change', function() {
    // The system time zone is America/Los_Angeles, where DST ends on 1 November 2026.
    expect(Craft::$app->getTimeZone())->toBe('America/Los_Angeles');
    $timeZone = new DateTimeZone('America/Los_Angeles');
    $entry = Fixtures::entry('news', ['title' => 'DST move', 'postDate' => new DateTime('2026-10-30 09:30:00', $timeZone)]);
    expect((clone $entry->postDate)->setTimezone(new DateTimeZone('UTC'))->format('H:i'))->toBe('16:30');

    $this->actingAs(Fixtures::admin())
        ->postJson('/admin/actions/datebook/calendar/reschedule', [
            'kind' => 'post',
            'elementId' => $entry->id,
            'siteId' => $entry->siteId,
            'day' => '2026-11-03',
        ])
        ->assertOk();

    $fresh = Entry::find()->id($entry->id)->status(null)->one();
    $local = (clone $fresh->postDate)->setTimezone($timeZone);
    $utc = (clone $fresh->postDate)->setTimezone(new DateTimeZone('UTC'));
    expect($local->format('Y-m-d H:i'))->toBe('2026-11-03 09:30')
        ->and($utc->format('H:i'))->toBe('17:30');
});

it('groups items by the day in the system time zone, not in UTC', function() {
    // 23:30 in Los Angeles on 14 October is 06:30 UTC on 15 October.
    $timeZone = new DateTimeZone('America/Los_Angeles');
    $entry = Fixtures::entry('news', ['title' => 'Late evening', 'postDate' => new DateTime('2026-10-14 23:30:00', $timeZone)]);
    $query = new CalendarQuery(new DateTime('2026-10-01', $timeZone), new DateTime('2026-11-01', $timeZone), $entry->siteId);
    $calendar = Fixtures::plugin()->calendar;

    $byDay = $calendar->groupByDay($calendar->getItems($query, Fixtures::admin()), $timeZone);

    expect(array_keys($byDay))->toBe(['2026-10-14'])
        ->and($byDay['2026-10-14'][0]->toArray($timeZone)['localTime'])->toBe('23:30');

    $this->actingAs(Fixtures::admin())
        ->get('/admin/datebook?view=month&date=2026-10-01')
        ->assertOk()
        ->assertSee('data-day="2026-10-14"');
});

it('schedules a draft at the local time the user typed', function() {
    $editor = Fixtures::user('editor', Fixtures::editorPermissions());
    $entry = Fixtures::entry('news', ['author' => $editor]);
    $draft = Fixtures::draft($entry, $editor, ['title' => 'Local time']);

    $this->actingAs($editor)
        ->postJson('/admin/actions/datebook/schedules/save', [
            'draftId' => $draft->id,
            'siteId' => $draft->siteId,
            'publishAt' => '2027-03-14T02:30', // does not exist in Los Angeles: DST starts at 02:00
        ])
        ->assertOk();

    $schedule = Fixtures::plugin()->schedules->getScheduleByDraftId($draft->id);
    $utc = (clone $schedule->publishAt)->setTimezone(new DateTimeZone('UTC'));
    // PHP moves a time inside the DST gap forward by an hour: 03:30 PDT is 10:30 UTC.
    expect($utc->format('Y-m-d H:i'))->toBe('2027-03-14 10:30');
});

it('respects the sections setting', function() {
    $settings = Fixtures::plugin()->getSettings();
    $news = Fixtures::section('news');
    $date = new DateTime('+1 day 10:00', new DateTimeZone('UTC'));
    Fixtures::entry('news', ['title' => 'In settings', 'postDate' => $date]);
    Fixtures::entry('blog', ['title' => 'Not in settings', 'postDate' => $date]);
    [$start, $end] = datebookRange();
    $settings->sections = [$news->uid];

    try {
        $items = Fixtures::plugin()->calendar->getItems(new CalendarQuery($start, $end, Fixtures::primarySite()->id), Fixtures::admin());
        expect(array_map(fn(CalendarItem $i) => $i->title, $items))->toBe(['In settings']);
    } finally {
        $settings->sections = '*';
    }
});

it('rejects item ranges longer than 92 days', function() {
    $this->withExceptionHandling()
        ->actingAs(Fixtures::admin())
        ->http('get', '/admin/actions/datebook/calendar/items?start=2026-01-01&end=2026-06-01')
        ->addHeader('Accept', 'application/json')
        ->send()
        ->assertStatus(400);
});

it('needs the reschedule permission to move a scheduled draft', function() {
    [, $draft, $schedule, $editor] = Fixtures::scheduledDraft();
    Fixtures::grant($editor, array_values(array_diff(Fixtures::editorPermissions(), [Datebook::PERMISSION_RESCHEDULE])));

    $this->withExceptionHandling()
        ->actingAs($editor)
        ->postJson('/admin/actions/datebook/calendar/reschedule', [
            'kind' => 'draft',
            'elementId' => $draft->id,
            'siteId' => $draft->siteId,
            'day' => (new DateTime('+9 days'))->format('Y-m-d'),
        ])
        ->assertForbidden();

    expect(Fixtures::plugin()->schedules->getScheduleByDraftId($draft->id)->publishAt->getTimestamp())
        ->toBe($schedule->publishAt->getTimestamp());
});
