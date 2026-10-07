<?php

use zemis\datebook\Datebook;
use zemis\datebook\models\CalendarItem;
use zemis\datebook\models\CalendarQuery;
use zemis\datebook\tests\Support\Fixtures;

beforeAll(fn() => Fixtures::boot());

function datebookItems(\craft\elements\User $user, ?int $siteId = null): array
{
    $query = new CalendarQuery(
        new DateTime('2026-10-01', new DateTimeZone('UTC')),
        new DateTime('2026-11-01', new DateTimeZone('UTC')),
        $siteId ?? Fixtures::primarySite()->id,
    );

    return Fixtures::plugin()->calendar->getItems($query, $user);
}

function datebookTitles(array $items): array
{
    return array_map(fn(CalendarItem $item) => $item->title, $items);
}

it('keeps users without plugin access out', function() {
    $user = Fixtures::user('outsider', ['accessCp']);

    $this->withExceptionHandling()
        ->actingAs($user)
        ->get('/admin/datebook')
        ->assertForbidden();
});

it('lets editors with plugin access open the calendar', function() {
    $user = Fixtures::user('editor', Fixtures::editorPermissions());

    $this->actingAs($user)
        ->get('/admin/datebook')
        ->assertOk();
});

it('only shows sections the user may view', function() {
    $user = Fixtures::user('editor', Fixtures::editorPermissions(['news']));
    $date = new DateTime('2026-10-10 10:00', new DateTimeZone('UTC'));
    Fixtures::entry('news', ['title' => 'Visible news', 'postDate' => $date]);
    Fixtures::entry('blog', ['title' => 'Hidden blog', 'postDate' => $date]);

    expect(datebookTitles(datebookItems($user)))->toContain('Visible news')->not->toContain('Hidden blog');
});

it('hides entries by other authors without the peer permission', function() {
    $user = Fixtures::user('writer', Fixtures::editorPermissions(['news'], peers: false));
    $date = new DateTime('2026-10-10 10:00', new DateTimeZone('UTC'));
    Fixtures::entry('news', ['title' => 'My own post', 'postDate' => $date, 'author' => $user]);
    Fixtures::entry('news', ['title' => 'Someone else', 'postDate' => $date]);

    expect(datebookTitles(datebookItems($user)))->toContain('My own post')->not->toContain('Someone else');
});

it('does not let users move entries they cannot save', function() {
    $viewer = Fixtures::user('viewer', [
        'accessCp',
        'accessPlugin-datebook',
        Datebook::PERMISSION_RESCHEDULE,
        'viewEntries:{news}',
        'viewPeerEntries:{news}',
        'editSite:{default}',
    ]);
    $entry = Fixtures::entry('news', ['postDate' => new DateTime('2026-10-10 10:00', new DateTimeZone('UTC'))]);

    $items = datebookItems($viewer);
    expect($items)->toHaveCount(1)
        ->and($items[0]->canReschedule)->toBeFalse();

    $this->withExceptionHandling()
        ->actingAs($viewer)
        ->postJson('/admin/actions/datebook/calendar/reschedule', [
            'kind' => 'post',
            'elementId' => $entry->id,
            'siteId' => $entry->siteId,
            'day' => '2026-10-12',
        ])
        ->assertForbidden();
});

it('needs the reschedule permission to move entries', function() {
    $permissions = array_values(array_diff(Fixtures::editorPermissions(), [Datebook::PERMISSION_RESCHEDULE]));
    $editor = Fixtures::user('editor', $permissions);
    $entry = Fixtures::entry('news', ['postDate' => new DateTime('2026-10-10 10:00', new DateTimeZone('UTC'))]);

    expect(datebookItems($editor)[0]->canReschedule)->toBeFalse();

    $this->withExceptionHandling()
        ->actingAs($editor)
        ->postJson('/admin/actions/datebook/calendar/reschedule', [
            'kind' => 'post',
            'elementId' => $entry->id,
            'siteId' => $entry->siteId,
            'day' => '2026-10-12',
        ])
        ->assertForbidden();
});

it('lets editors move entries they can save', function() {
    $editor = Fixtures::user('editor', Fixtures::editorPermissions());
    $entry = Fixtures::entry('news', ['postDate' => new DateTime('2026-10-10 10:00', new DateTimeZone('UTC'))]);

    expect(datebookItems($editor)[0]->canReschedule)->toBeTrue();

    $this->actingAs($editor)
        ->postJson('/admin/actions/datebook/calendar/reschedule', [
            'kind' => 'post',
            'elementId' => $entry->id,
            'siteId' => $entry->siteId,
            'day' => '2026-10-12',
        ])
        ->assertOk();
});

it('needs the schedule permission to schedule drafts', function() {
    $permissions = array_values(array_diff(Fixtures::editorPermissions(), [Datebook::PERMISSION_SCHEDULE_DRAFTS]));
    $editor = Fixtures::user('editor', $permissions);
    $entry = Fixtures::entry('news', ['author' => $editor]);
    $draft = Fixtures::draft($entry, $editor, ['title' => 'Not allowed']);

    expect(Fixtures::plugin()->schedules->getScheduleError($draft, $editor))->not->toBeNull();

    $response = $this->actingAs($editor)
        ->postJson('/admin/actions/datebook/schedules/save', [
            'draftId' => $draft->id,
            'siteId' => $draft->siteId,
            'publishAt' => (new DateTime('+1 day'))->format('Y-m-d\TH:i'),
        ]);

    expect($response->getStatusCode())->toBe(400);
});

it('needs permission to publish the live entry to schedule its draft', function() {
    // Can save drafts of peers, but not the live entries themselves.
    $editor = Fixtures::user('drafter', [
        'accessCp',
        'accessPlugin-datebook',
        Datebook::PERMISSION_SCHEDULE_DRAFTS,
        'viewEntries:{news}',
        'viewPeerEntries:{news}',
        'viewPeerEntryDrafts:{news}',
        'savePeerEntryDrafts:{news}',
        'editSite:{default}',
    ]);
    $entry = Fixtures::entry('news');
    $draft = Fixtures::draft($entry, $editor, ['title' => 'Needs a publisher']);

    expect(Fixtures::plugin()->schedules->getScheduleError($draft, $editor))
        ->toBe('You are not allowed to publish this draft.');
});
