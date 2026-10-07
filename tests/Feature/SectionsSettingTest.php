<?php

use craft\elements\Entry;
use zemis\datebook\errors\ScheduleException;
use zemis\datebook\records\ScheduleRecord;
use zemis\datebook\tests\Support\Fixtures;

beforeAll(fn() => Fixtures::boot());

/**
 * Runs the callback with only the given sections on the calendar.
 *
 * @param string[] $handles
 */
function datebookWithSections(array $handles, callable $callback): mixed
{
    $settings = Fixtures::plugin()->getSettings();
    $original = $settings->sections;
    $settings->sections = array_map(fn(string $handle) => Fixtures::section($handle)->uid, $handles);

    try {
        return $callback();
    } finally {
        $settings->sections = $original;
    }
}

it('only lets people schedule drafts in sections on the calendar', function() {
    $editor = Fixtures::user('editor', Fixtures::editorPermissions(['news', 'blog']));
    $news = Fixtures::draft(Fixtures::entry('news', ['author' => $editor]), $editor, ['title' => 'News draft']);
    $blog = Fixtures::draft(Fixtures::entry('blog', ['author' => $editor]), $editor, ['title' => 'Blog draft']);
    $schedules = Fixtures::plugin()->schedules;
    $this->actingAs($editor);

    datebookWithSections(['blog'], function() use ($news, $blog, $editor, $schedules) {
        expect($schedules->getScheduleError($news, $editor))->toBe('This section is not on the Datebook calendar.')
            ->and(fn() => $schedules->schedule($news, new DateTime('+2 hours'), $editor))->toThrow(ScheduleException::class)
            ->and($news->getSidebarHtml(false))->not->toContain('Publish this draft later')
            ->and($schedules->getScheduleError($blog, $editor))->toBeNull()
            ->and($blog->getSidebarHtml(false))->toContain('Publish this draft later');

        $schedules->schedule($blog, new DateTime('+2 hours'), $editor);
    });

    expect(ScheduleRecord::find()->where(['draftId' => $news->id])->exists())->toBeFalse()
        ->and(ScheduleRecord::find()->where(['draftId' => $blog->id])->exists())->toBeTrue();
});

it('refuses the sidebar action for a section that is not on the calendar', function() {
    $editor = Fixtures::user('editor', Fixtures::editorPermissions(['news']));
    $draft = Fixtures::draft(Fixtures::entry('news', ['author' => $editor]), $editor, ['title' => 'Not here']);

    $response = datebookWithSections(['blog'], fn() => $this->actingAs($editor)
        ->postJson('/admin/actions/datebook/schedules/save', [
            'draftId' => $draft->id,
            'siteId' => $draft->siteId,
            'publishAt' => (new DateTime('+1 day'))->format('Y-m-d\TH:i'),
        ]));

    expect($response->getStatusCode())->toBe(400)
        ->and(json_decode($response->content, true)['message'] ?? '')->toBe('This section is not on the Datebook calendar.')
        ->and(ScheduleRecord::find()->where(['draftId' => $draft->id])->exists())->toBeFalse();
});

it('lets people unschedule a draft in a section that was taken off the calendar', function() {
    [, $draft, , $editor] = Fixtures::scheduledDraft(['title' => 'Off the calendar']);
    $this->actingAs($editor);

    datebookWithSections(['blog'], function() use ($draft, $editor) {
        expect($draft->getSidebarHtml(false))
            ->toContain('Will be published on')
            ->toContain('This section is not on the Datebook calendar.')
            ->toContain('datebook-schedule__cancel')
            ->not->toContain('datebook-schedule__input');

        $this->actingAs($editor)
            ->postJson('/admin/actions/datebook/schedules/cancel', [
                'draftId' => $draft->id,
                'siteId' => $draft->siteId,
            ])
            ->assertOk();
    });

    expect(ScheduleRecord::find()->where(['draftId' => $draft->id])->exists())->toBeFalse();
});

it('still publishes drafts scheduled before their section was taken off the calendar', function() {
    [$entry] = Fixtures::scheduledDraft(['title' => 'Scheduled earlier']);

    $counts = datebookWithSections(['blog'], fn() => Fixtures::plugin()->schedules->publishDue(new DateTime('+3 hours')));

    expect($counts['published'])->toBe(1)
        ->and(Entry::find()->id($entry->id)->status(null)->one()->title)->toBe('Scheduled earlier');
});
