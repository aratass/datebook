<?php

use zemis\datebook\Datebook;
use zemis\datebook\models\Settings;
use zemis\datebook\records\FeedTokenRecord;
use zemis\datebook\tests\Support\Fixtures;

beforeAll(fn() => Fixtures::boot());

/**
 * Unfolds an ICS document so each property is on one line.
 */
function datebookUnfold(string $ics): string
{
    return str_replace("\r\n ", '', $ics);
}

it('creates one private feed link per user and keeps it', function() {
    $editor = Fixtures::user('editor', Fixtures::editorPermissions());
    $feeds = Fixtures::plugin()->feeds;

    expect($feeds->getFeedUrl($editor))->toBeNull();

    $url = $feeds->getFeedUrl($editor, true);
    expect($url)->toStartWith('http://localhost:8080/datebook/feed/')
        ->toEndWith('.ics')
        ->and($feeds->getFeedUrl($editor))->toBe($url)
        ->and(FeedTokenRecord::find()->where(['userId' => $editor->id])->count())->toBe(1);

    // The token is stored as a hash, not in clear text.
    $token = $feeds->getToken($editor);
    $record = FeedTokenRecord::findOne(['userId' => $editor->id]);
    expect($record->tokenHash)->toBe(hash('sha256', $token))
        ->and($record->token)->not->toContain($token);
});

it('serves the ICS feed for a valid token and hides it for a bad one', function() {
    $editor = Fixtures::user('editor', Fixtures::editorPermissions());
    $entry = Fixtures::entry('news', [
        'title' => 'Feed entry, with a comma; and a semicolon',
        'postDate' => new DateTime('+2 days 14:30', new DateTimeZone('UTC')),
        'author' => $editor,
    ]);
    $token = Fixtures::plugin()->feeds->resetToken($editor);

    $response = $this->get("/datebook/feed/$token.ics")->assertOk();
    $ics = $response->content;
    $flat = datebookUnfold($ics);

    expect($response->getHeaders()->get('Content-Type'))->toContain('text/calendar')
        ->and($ics)->toStartWith("BEGIN:VCALENDAR\r\n")
        ->and($ics)->toEndWith("END:VCALENDAR\r\n")
        ->and($flat)->toContain('PRODID:-//Zemis//Datebook//EN')
        ->and($flat)->toContain("UID:post-$entry->id-$entry->siteId@localhost")
        ->and($flat)->toContain('SUMMARY:Goes live: Feed entry\, with a comma\; and a semicolon')
        ->and($flat)->toContain('DTSTART:' . $entry->postDate->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z'))
        ->and($flat)->toContain('CATEGORIES:News')
        ->and($flat)->toContain("URL:" . $entry->getCpEditUrl());

    // Every line fits in 75 octets, as RFC 5545 requires.
    foreach (explode("\r\n", rtrim($ics)) as $line) {
        expect(strlen($line))->toBeLessThanOrEqual(75);
    }

    $this->withExceptionHandling()
        ->get('/datebook/feed/' . str_repeat('x', 40) . '.ics')
        ->assertNotFound();
});

it('includes expiry dates and scheduled drafts in the feed', function() {
    [$entry, $draft, $schedule, $editor] = Fixtures::scheduledDraft(['title' => 'Feed draft'], '+3 days');
    $entry->expiryDate = new DateTime('+5 days', new DateTimeZone('UTC'));
    Craft::$app->getElements()->saveElement($entry);
    $token = Fixtures::plugin()->feeds->resetToken($editor);

    $flat = datebookUnfold($this->get("/datebook/feed/$token.ics")->assertOk()->content);

    expect($flat)->toContain("UID:expiry-$entry->id-$entry->siteId@localhost")
        ->and($flat)->toContain('SUMMARY:Expires: Old title')
        ->and($flat)->toContain("UID:draft-$draft->id-$draft->siteId@localhost")
        ->and($flat)->toContain('SUMMARY:Draft goes live: Feed draft (Spring update)')
        ->and($flat)->toContain('DTSTART:' . $schedule->publishAt->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z'));
});

it('limits the feed to the sections asked for and to sections the user may view', function() {
    $editor = Fixtures::user('editor', Fixtures::editorPermissions(['news']));
    $date = new DateTime('+1 day 09:00', new DateTimeZone('UTC'));
    Fixtures::entry('news', ['title' => 'Allowed news', 'postDate' => $date]);
    Fixtures::entry('blog', ['title' => 'Hidden blog', 'postDate' => $date]);
    $token = Fixtures::plugin()->feeds->resetToken($editor);

    $flat = datebookUnfold($this->get("/datebook/feed/$token.ics")->assertOk()->content);
    expect($flat)->toContain('Allowed news')->not->toContain('Hidden blog');

    $flat = datebookUnfold($this->get("/datebook/feed/$token.ics?sections=blog")->assertOk()->content);
    expect($flat)->not->toContain('Allowed news')->not->toContain('Hidden blog');
});

it('stops serving a feed when the link is reset or the permission is gone', function() {
    $editor = Fixtures::user('editor', Fixtures::editorPermissions());
    $feeds = Fixtures::plugin()->feeds;
    $old = $feeds->resetToken($editor);
    $this->get("/datebook/feed/$old.ics")->assertOk();

    $new = $feeds->resetToken($editor);
    expect($new)->not->toBe($old);
    $this->withExceptionHandling()->get("/datebook/feed/$old.ics")->assertNotFound();
    $this->get("/datebook/feed/$new.ics")->assertOk();

    Fixtures::grant($editor, array_values(array_diff(Fixtures::editorPermissions(), [Datebook::PERMISSION_SUBSCRIBE])));
    $this->withExceptionHandling()->get("/datebook/feed/$new.ics")->assertNotFound();

    Fixtures::grant($editor, Fixtures::editorPermissions());
    $feeds->revokeToken($editor);
    $this->withExceptionHandling()->get("/datebook/feed/$new.ics")->assertNotFound();
});

it('turns feeds off with the setting', function() {
    $editor = Fixtures::user('editor', Fixtures::editorPermissions());
    $token = Fixtures::plugin()->feeds->resetToken($editor);
    $settings = Fixtures::plugin()->getSettings();
    $settings->enableFeeds = false;

    try {
        expect(Fixtures::plugin()->feeds->canSubscribe($editor))->toBeFalse();
        $this->withExceptionHandling()->get("/datebook/feed/$token.ics")->assertNotFound();
        $this->actingAs($editor)->get('/admin/datebook')->assertOk()->assertDontSee('id="datebook-subscribe"');
    } finally {
        $settings->enableFeeds = true;
    }

    $this->actingAs($editor)->get('/admin/datebook')->assertOk()->assertSee('id="datebook-subscribe"');
});

it('hands out and resets the feed link through the control panel actions', function() {
    $editor = Fixtures::user('editor', Fixtures::editorPermissions());

    $response = $this->actingAs($editor)->postJson('/admin/actions/datebook/feed/url')->assertOk();
    $url = json_decode($response->content, true)['url'];
    expect($url)->toContain('/datebook/feed/');

    $response = $this->actingAs($editor)->postJson('/admin/actions/datebook/feed/reset')->assertOk();
    $newUrl = json_decode($response->content, true)['url'];
    expect($newUrl)->toContain('/datebook/feed/')->not->toBe($url)
        ->and(Fixtures::plugin()->feeds->getFeedUrl($editor))->toBe($newUrl);
});

it('needs the subscribe permission for the feed link', function() {
    $editor = Fixtures::user('editor', array_values(array_diff(Fixtures::editorPermissions(), [Datebook::PERMISSION_SUBSCRIBE])));

    $this->withExceptionHandling()
        ->actingAs($editor)
        ->postJson('/admin/actions/datebook/feed/url')
        ->assertForbidden();

    expect(FeedTokenRecord::find()->where(['userId' => $editor->id])->exists())->toBeFalse();
});

it('stops serving the feed of a suspended user', function() {
    $editor = Fixtures::user('editor', Fixtures::editorPermissions());
    $token = Fixtures::plugin()->feeds->resetToken($editor);
    $this->get("/datebook/feed/$token.ics")->assertOk();

    Craft::$app->getUsers()->suspendUser($editor);

    $this->withExceptionHandling()->get("/datebook/feed/$token.ics")->assertNotFound();
});

it('stops serving the feed when the user loses access to Datebook', function() {
    $editor = Fixtures::user('editor', Fixtures::editorPermissions());
    $token = Fixtures::plugin()->feeds->resetToken($editor);

    Fixtures::grant($editor, array_values(array_diff(Fixtures::editorPermissions(), ['accessPlugin-datebook'])));

    $this->withExceptionHandling()->get("/datebook/feed/$token.ics")->assertNotFound();
});

it('writes the same UTC times whatever the system time zone is', function() {
    $editor = Fixtures::user('editor', Fixtures::editorPermissions());
    Fixtures::entry('news', ['title' => 'Zone test', 'postDate' => new DateTime('+3 days 21:15', new DateTimeZone('UTC'))]);
    $feeds = Fixtures::plugin()->feeds;
    $site = Fixtures::primarySite();
    $now = new DateTime('now', new DateTimeZone('UTC'));
    $start = fn(string $ics) => preg_match('/SUMMARY:Goes live: Zone test.*?\r\n/s', $ics) ? (preg_match_all('/DTSTART:(\d{8}T\d{6}Z)/', $ics, $m) ? $m[1] : []) : [];

    $inLosAngeles = $feeds->render($editor, $site, null, $now);
    try {
        Craft::$app->setTimeZone('Asia/Tokyo');
        $inTokyo = $feeds->render($editor, $site, null, $now);
    } finally {
        Craft::$app->setTimeZone(Fixtures::TIME_ZONE);
    }

    $expected = (new DateTime('+3 days 21:15', new DateTimeZone('UTC')))->format('Ymd\THis\Z');
    expect($start($inLosAngeles))->toContain($expected)
        ->and($start($inTokyo))->toContain($expected)
        ->and(str_replace("\r\n ", '', $inTokyo))->toContain('SUMMARY:Goes live: Zone test');
});

/**
 * Runs the callback with some general config settings changed.
 *
 * @param array<string, mixed> $changes
 */
function datebookWithConfig(array $changes, callable $callback): mixed
{
    $generalConfig = Craft::$app->getConfig()->getGeneral();
    $original = [];
    foreach ($changes as $name => $value) {
        $original[$name] = $generalConfig->$name;
        $generalConfig->$name = $value;
    }

    try {
        return $callback();
    } finally {
        foreach ($original as $name => $value) {
            $generalConfig->$name = $value;
        }
    }
}

it('builds feed links from the control panel address in headless mode', function() {
    $editor = Fixtures::user('editor', Fixtures::editorPermissions());
    $feeds = Fixtures::plugin()->feeds;

    // The primary site points to a front end that Craft does not serve.
    $url = datebookWithConfig(
        ['headlessMode' => true, 'baseCpUrl' => 'https://cms.example.com/'],
        fn() => $feeds->getFeedUrl($editor, true),
    );

    expect($url)->toBe('https://cms.example.com/datebook/feed/' . $feeds->getToken($editor) . '.ics');
});

it('serves the feed in headless mode', function() {
    $editor = Fixtures::user('editor', Fixtures::editorPermissions());
    $token = Fixtures::plugin()->feeds->resetToken($editor);

    datebookWithConfig(['headlessMode' => true], function() use ($token) {
        $response = $this->get("/datebook/feed/$token.ics")->assertOk();
        expect($response->content)->toStartWith("BEGIN:VCALENDAR\r\n");
    });
});

it('keeps the site address in headless mode when the control panel has no path of its own', function() {
    $editor = Fixtures::user('editor', Fixtures::editorPermissions());
    $feeds = Fixtures::plugin()->feeds;
    $siteUrl = $feeds->getFeedUrl($editor, true);

    // Every request to cms.example.com is a control panel request then, so the feed could not answer there.
    $url = datebookWithConfig(
        ['headlessMode' => true, 'baseCpUrl' => 'https://cms.example.com/', 'cpTrigger' => null],
        fn() => $feeds->getFeedUrl($editor),
    );

    expect($url)->toBe($siteUrl)
        ->and($url)->toStartWith(Fixtures::primarySite()->getBaseUrl() . 'datebook/feed/');
});

it('uses the feed link address from the settings', function() {
    $editor = Fixtures::user('editor', Fixtures::editorPermissions());
    $feeds = Fixtures::plugin()->feeds;
    $token = $feeds->resetToken($editor);
    $siteUrl = $feeds->urlForToken($token);
    $settings = Fixtures::plugin()->getSettings();
    putenv('DATEBOOK_TEST_FEED_URL=https://calendar.example.com/craft/');
    $settings->feedBaseUrl = '$DATEBOOK_TEST_FEED_URL';

    try {
        expect($feeds->urlForToken($token))->toBe("https://calendar.example.com/craft/datebook/feed/$token.ics")
            ->and(datebookWithConfig(['omitScriptNameInUrls' => false], fn() => $feeds->urlForToken($token)))
            ->toBe('https://calendar.example.com/craft/index.php?p=' . rawurlencode("datebook/feed/$token.ics"));

        // An environment variable that is not set in this environment is ignored.
        $settings->feedBaseUrl = '$DATEBOOK_TEST_MISSING_URL';
        expect($feeds->urlForToken($token))->toBe($siteUrl);
    } finally {
        $settings->feedBaseUrl = '';
        putenv('DATEBOOK_TEST_FEED_URL');
    }
});

it('only accepts a full address for feed links', function(string $value, bool $valid) {
    $settings = new Settings(['feedBaseUrl' => $value]);

    expect($settings->validate(['feedBaseUrl']))->toBe($valid);
})->with([
    'empty' => ['', true],
    'https' => ['https://cms.example.com', true],
    'http with a path' => ['http://example.com/craft/', true],
    'no scheme' => ['cms.example.com', false],
    'a path only' => ['/datebook', false],
]);
