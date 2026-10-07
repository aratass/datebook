<?php

use craft\helpers\DateTimeHelper;
use zemis\datebook\models\CalendarItem;
use zemis\datebook\models\CalendarQuery;
use zemis\datebook\tests\Support\Fixtures;

beforeAll(fn() => Fixtures::boot());

it('shows the month view with entries', function() {
    $entry = Fixtures::entry('news', ['title' => 'Autumn launch', 'postDate' => new DateTime('2026-10-14 09:30:00', new DateTimeZone('UTC'))]);

    $this->actingAs(Fixtures::admin())
        ->get('/admin/datebook?view=month&date=2026-10-01')
        ->assertOk()
        ->assertSee('Autumn launch')
        ->assertSee('data-element-id="' . $entry->id . '"');
});

it('shows the week and list views', function(string $view) {
    Fixtures::entry('news', ['title' => 'Weekly roundup', 'postDate' => new DateTime('2026-10-14 09:30:00', new DateTimeZone('UTC'))]);

    $this->actingAs(Fixtures::admin())
        ->get("/admin/datebook?view=$view&date=2026-10-14")
        ->assertOk()
        ->assertSee('Weekly roundup');
})->with(['week', 'list']);

it('returns items as JSON', function() {
    $entry = Fixtures::entry('news', ['title' => 'JSON entry', 'postDate' => new DateTime('2026-10-14 09:30:00', new DateTimeZone('UTC'))]);

    $response = $this->actingAs(Fixtures::admin())
        ->http('get', '/admin/actions/datebook/calendar/items?start=2026-10-01&end=2026-11-01')
        ->addHeader('Accept', 'application/json')
        ->send()
        ->assertOk();

    $data = json_decode($response->content, true);
    $keys = array_column($data['items'], 'key');
    expect($keys)->toContain('post-' . $entry->id . '-' . $entry->siteId);
});

it('includes expiry dates only when asked', function() {
    $entry = Fixtures::entry('news', [
        'title' => 'Expiring offer',
        'postDate' => new DateTime('2026-10-01 08:00:00', new DateTimeZone('UTC')),
        'expiryDate' => new DateTime('2026-10-20 08:00:00', new DateTimeZone('UTC')),
    ]);
    $admin = Fixtures::admin();
    $calendar = Fixtures::plugin()->calendar;
    $range = [new DateTime('2026-10-15 00:00:00', new DateTimeZone('UTC')), new DateTime('2026-10-25 00:00:00', new DateTimeZone('UTC'))];

    $with = $calendar->getItems(new CalendarQuery($range[0], $range[1], $entry->siteId, includeExpiry: true), $admin);
    $without = $calendar->getItems(new CalendarQuery($range[0], $range[1], $entry->siteId, includeExpiry: false), $admin);

    expect(array_map(fn(CalendarItem $i) => $i->getKey(), $with))->toContain('expiry-' . $entry->id . '-' . $entry->siteId)
        ->and($without)->toBeEmpty();
});

it('filters by section, author and status', function() {
    // Live entries need a post date in the past, so this test works relative to today.
    $author = Fixtures::user('writer', Fixtures::editorPermissions(['news', 'blog']));
    $date = new DateTime('-3 days 10:00', new DateTimeZone('UTC'));
    $news = Fixtures::entry('news', ['title' => 'News item', 'postDate' => $date, 'author' => $author]);
    $blog = Fixtures::entry('blog', ['title' => 'Blog item', 'postDate' => $date]);
    $disabled = Fixtures::entry('news', ['title' => 'Disabled item', 'postDate' => $date, 'enabled' => false]);
    $admin = Fixtures::admin();
    $calendar = Fixtures::plugin()->calendar;
    $start = new DateTime('-10 days 00:00', new DateTimeZone('UTC'));
    $end = new DateTime('+10 days 00:00', new DateTimeZone('UTC'));
    $siteId = $news->siteId;
    $titles = fn(array $items) => array_map(fn(CalendarItem $i) => $i->title, $items);

    $bySection = $calendar->getItems(new CalendarQuery($start, $end, $siteId, sectionIds: [Fixtures::section('blog')->id]), $admin);
    expect($titles($bySection))->toBe(['Blog item']);

    $byAuthor = $calendar->getItems(new CalendarQuery($start, $end, $siteId, authorId: $author->id), $admin);
    expect($titles($byAuthor))->toBe(['News item']);

    $liveOnly = $calendar->getItems(new CalendarQuery($start, $end, $siteId, statuses: ['live']), $admin);
    expect($titles($liveOnly))->toContain('News item')->toContain('Blog item')->not->toContain('Disabled item');

    $disabledOnly = $calendar->getItems(new CalendarQuery($start, $end, $siteId, statuses: ['disabled']), $admin);
    expect($titles($disabledOnly))->toBe(['Disabled item']);

    $pendingOnly = $calendar->getItems(new CalendarQuery($start, $end, $siteId, statuses: ['pending']), $admin);
    expect($titles($pendingOnly))->toBeEmpty();
});

it('moves an entry to another day and keeps the time', function() {
    $entry = Fixtures::entry('news', ['title' => 'Move me', 'postDate' => new DateTime('2026-10-14 09:30:00', new DateTimeZone('UTC'))]);

    $response = $this->actingAs(Fixtures::admin())
        ->postJson('/admin/actions/datebook/calendar/reschedule', [
            'kind' => 'post',
            'elementId' => $entry->id,
            'siteId' => $entry->siteId,
            'day' => '2026-10-20',
        ])
        ->assertOk();

    $data = json_decode($response->content, true);
    expect($data['item']['localDate'])->toBe('2026-10-20');

    $fresh = \craft\elements\Entry::find()->id($entry->id)->status(null)->one();
    $utc = DateTimeHelper::toDateTime($fresh->postDate)->setTimezone(new DateTimeZone(Craft::$app->getTimeZone()));
    $original = (new DateTime('2026-10-14 09:30:00', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(Craft::$app->getTimeZone()));
    expect($utc->format('Y-m-d'))->toBe('2026-10-20')
        ->and($utc->format('H:i'))->toBe($original->format('H:i'));
});

it('sets an exact date and time', function() {
    $entry = Fixtures::entry('news', ['title' => 'Exact time', 'postDate' => new DateTime('2026-10-14 09:30:00', new DateTimeZone('UTC'))]);

    $this->actingAs(Fixtures::admin())
        ->postJson('/admin/actions/datebook/calendar/reschedule', [
            'kind' => 'post',
            'elementId' => $entry->id,
            'siteId' => $entry->siteId,
            'dateTime' => '2026-10-22T16:45',
        ])
        ->assertOk();

    $fresh = \craft\elements\Entry::find()->id($entry->id)->status(null)->one();
    $local = (clone $fresh->postDate)->setTimezone(new DateTimeZone(Craft::$app->getTimeZone()));
    expect($local->format('Y-m-d H:i'))->toBe('2026-10-22 16:45');
});

it('refuses a post date after the expiry date', function() {
    $entry = Fixtures::entry('news', [
        'postDate' => new DateTime('2026-10-14 09:30:00', new DateTimeZone('UTC')),
        'expiryDate' => new DateTime('2026-10-16 09:30:00', new DateTimeZone('UTC')),
    ]);

    $response = $this->actingAs(Fixtures::admin())
        ->postJson('/admin/actions/datebook/calendar/reschedule', [
            'kind' => 'post',
            'elementId' => $entry->id,
            'siteId' => $entry->siteId,
            'day' => '2026-10-20',
        ]);

    expect($response->getStatusCode())->toBe(400);
    $fresh = \craft\elements\Entry::find()->id($entry->id)->status(null)->one();
    expect($fresh->postDate->getTimestamp())->toBe((new DateTime('2026-10-14 09:30:00', new DateTimeZone('UTC')))->getTimestamp());
});
