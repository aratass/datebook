<?php

use zemis\datebook\tests\Support\Fixtures;
use zemis\datebook\widgets\Upcoming;

beforeAll(fn() => Fixtures::boot());

it('lists what happens in the next 14 days and nothing later', function() {
    $editor = Fixtures::user('editor', Fixtures::editorPermissions());
    Fixtures::entry('news', ['title' => 'Soon', 'postDate' => new DateTime('+2 days 09:00', new DateTimeZone('UTC'))]);
    Fixtures::entry('news', ['title' => 'Later', 'postDate' => new DateTime('+20 days 09:00', new DateTimeZone('UTC'))]);
    Fixtures::entry('news', ['title' => 'Already live', 'postDate' => new DateTime('-2 days 09:00', new DateTimeZone('UTC'))]);
    Fixtures::entry('news', [
        'title' => 'Ends soon',
        'postDate' => new DateTime('-5 days 09:00', new DateTimeZone('UTC')),
        'expiryDate' => new DateTime('+4 days 09:00', new DateTimeZone('UTC')),
    ]);
    [, , , $scheduler] = Fixtures::scheduledDraft(['title' => 'Draft soon'], '+3 days');

    $this->actingAs($editor);
    $html = (string)(new Upcoming(['days' => 14]))->getBodyHtml();

    expect($html)->toContain('Soon')
        ->toContain('Ends soon')
        ->toContain('Expires:')
        ->toContain('Draft soon')
        ->toContain('Draft goes live:')
        ->toContain('Open the calendar')
        ->not->toContain('Later')
        ->not->toContain('Already live');

    $html = (string)(new Upcoming(['days' => 14, 'showExpiry' => false, 'showDrafts' => false]))->getBodyHtml();
    expect($html)->toContain('Soon')
        ->not->toContain('Expires:')
        ->not->toContain('Draft soon');

    $this->actingAs($scheduler);
});

it('shows an empty state and hides itself from users without plugin access', function() {
    $editor = Fixtures::user('editor', Fixtures::editorPermissions(['blog']));
    $this->actingAs($editor);

    expect((string)(new Upcoming())->getBodyHtml())->toContain('Nothing is scheduled for these days.')
        ->and(Upcoming::isSelectable())->toBeTrue();

    $outsider = Fixtures::user('outsider', ['accessCp']);
    $this->actingAs($outsider);

    expect((new Upcoming())->getBodyHtml())->toBeNull()
        ->and(Upcoming::isSelectable())->toBeFalse();
});

it('renders on the dashboard', function() {
    $editor = Fixtures::user('editor', Fixtures::editorPermissions());
    Fixtures::entry('news', ['title' => 'Dashboard item', 'postDate' => new DateTime('+1 day 09:00', new DateTimeZone('UTC'))]);
    $this->actingAs($editor);

    $widget = new Upcoming(['days' => 7]);
    expect(Craft::$app->getDashboard()->saveWidget($widget))->toBeTrue();

    $this->actingAs($editor)
        ->get('/admin/dashboard')
        ->assertOk()
        ->assertSee('Next 7 days')
        ->assertSee('Dashboard item');
});
