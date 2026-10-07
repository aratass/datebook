<?php

use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\mail\Mailer;
use yii\base\Event;
use yii\console\ExitCode;
use yii\mail\MailEvent;
use zemis\datebook\console\controllers\DraftsController;
use zemis\datebook\errors\ScheduleException;
use zemis\datebook\jobs\PublishDrafts;
use zemis\datebook\models\CalendarItem;
use zemis\datebook\models\CalendarQuery;
use zemis\datebook\records\ScheduleRecord;
use zemis\datebook\tests\Support\Fixtures;

beforeAll(fn() => Fixtures::boot());

it('schedules a draft and pushes a delayed publish job', function() {
    [, $draft] = Fixtures::scheduledDraft();

    $record = ScheduleRecord::findOne(['draftId' => $draft->id]);
    expect($record)->not->toBeNull()
        ->and($record->status)->toBe(ScheduleRecord::STATUS_PENDING);

    $job = (new Query())
        ->from('{{%queue}}')
        ->where(['description' => 'Publishing scheduled drafts'])
        ->orderBy(['id' => SORT_DESC])
        ->one();

    expect($job)->not->toBeFalse()
        ->and((int)$job['delay'])->toBeGreaterThan(7000)
        ->and((int)$job['delay'])->toBeLessThanOrEqual(7200);
});

it('publishes due drafts and removes the schedule', function() {
    [$entry, $draft] = Fixtures::scheduledDraft(['title' => 'Published title']);

    $counts = Fixtures::plugin()->schedules->publishDue(new DateTime('+3 hours'));

    expect($counts['published'])->toBe(1)
        ->and(Entry::find()->id($entry->id)->status(null)->one()->title)->toBe('Published title')
        ->and(Entry::find()->drafts()->id($draft->id)->status(null)->exists())->toBeFalse()
        ->and(ScheduleRecord::find()->where(['draftId' => $draft->id])->exists())->toBeFalse();
});

it('leaves drafts alone until they are due', function() {
    [$entry, $draft] = Fixtures::scheduledDraft();

    $counts = Fixtures::plugin()->schedules->publishDue(new DateTime('+1 hour'));

    expect($counts['published'])->toBe(0)
        ->and(Entry::find()->id($entry->id)->status(null)->one()->title)->toBe('Old title')
        ->and(ScheduleRecord::findOne(['draftId' => $draft->id])->status)->toBe(ScheduleRecord::STATUS_PENDING);
});

it('refuses a time in the past', function() {
    $editor = Fixtures::user('editor', Fixtures::editorPermissions());
    $entry = Fixtures::entry('news', ['author' => $editor]);
    $draft = Fixtures::draft($entry, $editor, ['title' => 'Late']);

    Fixtures::plugin()->schedules->schedule($draft, new DateTime('-5 minutes'), $editor);
})->throws(ScheduleException::class);

it('refuses provisional drafts and drafts of entries that are not live yet', function() {
    $editor = Fixtures::user('editor', Fixtures::editorPermissions());
    $entry = Fixtures::entry('news', ['author' => $editor]);
    $schedules = Fixtures::plugin()->schedules;

    /** @var Entry $provisional */
    $provisional = Craft::$app->getDrafts()->createDraft($entry, $editor->id, null, null, [], true);
    expect($schedules->getScheduleError($provisional, $editor))->not->toBeNull();

    $new = new Entry();
    $new->sectionId = Fixtures::section('news')->id;
    $new->typeId = Fixtures::section('news')->getEntryTypes()[0]->id;
    $new->title = 'Brand new';
    Craft::$app->getDrafts()->saveElementAsDraft($new, $editor->id);
    expect($new->getIsUnpublishedDraft())->toBeTrue()
        ->and($schedules->getScheduleError($new, $editor))->not->toBeNull();
});

it('marks a draft as failed when it does not validate', function() {
    [$entry, $draft] = Fixtures::scheduledDraft(['title' => 'Will fail']);

    // Empty the title after scheduling. Drafts may be saved without a title, live entries may not.
    $draft->title = '';
    Craft::$app->getElements()->saveElement($draft, false);

    $counts = Fixtures::plugin()->schedules->publishDue(new DateTime('+3 hours'));
    $record = ScheduleRecord::findOne(['draftId' => $draft->id]);

    expect($counts['failed'])->toBe(1)
        ->and($record->status)->toBe(ScheduleRecord::STATUS_FAILED)
        ->and($record->error)->toContain('Title')
        ->and(Entry::find()->id($entry->id)->status(null)->one()->title)->toBe('Old title');
});

it('fails when the person who scheduled the draft lost permission', function() {
    [$entry, $draft, , $editor] = Fixtures::scheduledDraft();

    Fixtures::grant($editor, array_diff(Fixtures::editorPermissions(), ['datebook-scheduleDrafts']));

    $counts = Fixtures::plugin()->schedules->publishDue(new DateTime('+3 hours'));

    expect($counts['failed'])->toBe(1)
        ->and(ScheduleRecord::findOne(['draftId' => $draft->id])->error)->toContain('no longer allowed')
        ->and(Entry::find()->id($entry->id)->status(null)->one()->title)->toBe('Old title');
});

it('removes the schedule when the draft is deleted', function() {
    [, $draft] = Fixtures::scheduledDraft();

    Craft::$app->getElements()->deleteElement($draft, true);

    expect(ScheduleRecord::find()->where(['draftId' => $draft->id])->exists())->toBeFalse();
});

it('unschedules a draft', function() {
    [, $draft, , $editor] = Fixtures::scheduledDraft();

    expect(Fixtures::plugin()->schedules->unschedule($draft, $editor))->toBeTrue()
        ->and(ScheduleRecord::find()->where(['draftId' => $draft->id])->exists())->toBeFalse();
});

it('credits the new revision to the person who scheduled the draft', function() {
    [$entry, , , $editor] = Fixtures::scheduledDraft();
    // Craft skips a new revision when the entry was last saved in the same second
    // as the previous revision, so make the first revision older.
    Fixtures::ageRevisions($entry);

    Fixtures::plugin()->schedules->publishDue(new DateTime('+3 hours'));

    $creatorId = (new Query())
        ->select(['creatorId'])
        ->from('{{%revisions}}')
        ->where(['canonicalId' => $entry->id])
        ->orderBy(['num' => SORT_DESC])
        ->scalar();

    expect((int)$creatorId)->toBe((int)$editor->id);
});

it('publishes due drafts from the console command', function() {
    [$entry, $draft] = Fixtures::scheduledDraft(['title' => 'From the console']);
    ScheduleRecord::updateAll(['publishAt' => Db::prepareDateForDb(new DateTime('-1 minute'))], ['draftId' => $draft->id]);

    [$exitCode, $output] = Fixtures::console(DraftsController::class, 'publish');

    expect($exitCode)->toBe(ExitCode::OK)
        ->and($output)->toContain('Published: 1.')
        ->and(Entry::find()->id($entry->id)->status(null)->one()->title)->toBe('From the console')
        ->and(ScheduleRecord::find()->where(['draftId' => $draft->id])->exists())->toBeFalse();

    [$exitCode, $output] = Fixtures::console(DraftsController::class, 'list');
    expect($exitCode)->toBe(ExitCode::OK)
        ->and($output)->toContain('No drafts are scheduled');
});

it('lists scheduled drafts from the console command', function() {
    [$entry, $draft] = Fixtures::scheduledDraft();

    [$exitCode, $output] = Fixtures::console(DraftsController::class, 'list');

    expect($exitCode)->toBe(ExitCode::OK)
        ->and($output)->toContain("Draft $draft->id of entry $entry->id: pending at");
});

it('publishes due drafts from the queue job', function() {
    [$entry, $draft] = Fixtures::scheduledDraft(['title' => 'From the queue']);
    ScheduleRecord::updateAll(['publishAt' => Db::prepareDateForDb(new DateTime('-1 minute'))], ['draftId' => $draft->id]);

    (new PublishDrafts())->execute(Craft::$app->getQueue());

    expect(Entry::find()->id($entry->id)->status(null)->one()->title)->toBe('From the queue');
});

it('emails the scheduler when a draft is published and the setting is on', function() {
    $settings = Fixtures::plugin()->getSettings();
    $settings->notifyOnPublish = true;
    $sent = [];
    $handler = function(MailEvent $event) use (&$sent) {
        $sent[] = $event->message;
    };
    Event::on(Mailer::class, Mailer::EVENT_AFTER_SEND, $handler);

    try {
        [, , , $editor] = Fixtures::scheduledDraft();
        Fixtures::plugin()->schedules->publishDue(new DateTime('+3 hours'));
    } finally {
        Event::off(Mailer::class, Mailer::EVENT_AFTER_SEND, $handler);
        $settings->notifyOnPublish = false;
    }

    expect($sent)->toHaveCount(1)
        ->and(array_keys($sent[0]->getTo()))->toBe([$editor->email])
        ->and($sent[0]->getSubject())->toContain('New title');
});

it('emails when a scheduled draft fails', function() {
    $sent = [];
    $handler = function(MailEvent $event) use (&$sent) {
        $sent[] = $event->message;
    };
    Event::on(Mailer::class, Mailer::EVENT_AFTER_SEND, $handler);

    try {
        [, $draft] = Fixtures::scheduledDraft();
        $draft->title = '';
        Craft::$app->getElements()->saveElement($draft, false);
        Fixtures::plugin()->schedules->publishDue(new DateTime('+3 hours'));
    } finally {
        Event::off(Mailer::class, Mailer::EVENT_AFTER_SEND, $handler);
    }

    expect($sent)->toHaveCount(1)
        ->and($sent[0]->getSubject())->toContain('Not published');
});

it('shows scheduled and failed drafts on the calendar', function() {
    [, $draft, $schedule, $editor] = Fixtures::scheduledDraft(['title' => 'Draft on calendar']);
    $query = new CalendarQuery(new DateTime('-1 day'), new DateTime('+2 days'), $draft->siteId);

    $items = array_values(array_filter(
        Fixtures::plugin()->calendar->getItems($query, $editor),
        fn(CalendarItem $item) => $item->kind === CalendarItem::KIND_DRAFT,
    ));

    expect($items)->toHaveCount(1)
        ->and($items[0]->status)->toBe(CalendarItem::STATUS_SCHEDULED)
        ->and($items[0]->title)->toBe('Draft on calendar')
        ->and($items[0]->draftName)->toBe('Spring update')
        ->and($items[0]->date->getTimestamp())->toBe($schedule->publishAt->getTimestamp());

    ScheduleRecord::updateAll(['status' => ScheduleRecord::STATUS_FAILED, 'error' => 'Broken'], ['draftId' => $draft->id]);
    $failed = array_values(array_filter(
        Fixtures::plugin()->calendar->getItems($query, $editor),
        fn(CalendarItem $item) => $item->kind === CalendarItem::KIND_DRAFT,
    ));

    expect($failed[0]->status)->toBe(CalendarItem::STATUS_FAILED)
        ->and($failed[0]->error)->toBe('Broken');
});

it('moves a scheduled draft to another day and keeps the time', function() {
    [, $draft, $schedule, $editor] = Fixtures::scheduledDraft([], '+2 days');
    $timeZone = new DateTimeZone(Craft::$app->getTimeZone());
    $before = (clone $schedule->publishAt)->setTimezone($timeZone);
    $target = (clone $before)->modify('+3 days')->format('Y-m-d');

    $this->actingAs($editor)
        ->postJson('/admin/actions/datebook/calendar/reschedule', [
            'kind' => 'draft',
            'elementId' => $draft->id,
            'siteId' => $draft->siteId,
            'day' => $target,
        ])
        ->assertOk();

    $after = Fixtures::plugin()->schedules->getScheduleByDraftId($draft->id)->publishAt->setTimezone($timeZone);
    expect($after->format('Y-m-d'))->toBe($target)
        ->and($after->format('H:i'))->toBe($before->format('H:i'));
});

it('saves and cancels a schedule through the sidebar actions', function() {
    $editor = Fixtures::user('editor', Fixtures::editorPermissions());
    $entry = Fixtures::entry('news', ['author' => $editor]);
    $draft = Fixtures::draft($entry, $editor, ['title' => 'Sidebar']);
    $when = (new DateTime('+1 day', new DateTimeZone(Craft::$app->getTimeZone())))->format('Y-m-d\TH:i');

    $this->actingAs($editor)
        ->postJson('/admin/actions/datebook/schedules/save', [
            'draftId' => $draft->id,
            'siteId' => $draft->siteId,
            'publishAt' => $when,
        ])
        ->assertOk();

    expect(ScheduleRecord::find()->where(['draftId' => $draft->id])->exists())->toBeTrue();

    $this->actingAs($editor)
        ->postJson('/admin/actions/datebook/schedules/cancel', [
            'draftId' => $draft->id,
            'siteId' => $draft->siteId,
        ])
        ->assertOk();

    expect(ScheduleRecord::find()->where(['draftId' => $draft->id])->exists())->toBeFalse();
});

it('adds the scheduling panel to draft pages only', function() {
    $editor = Fixtures::user('editor', Fixtures::editorPermissions());
    $entry = Fixtures::entry('news', ['author' => $editor]);
    $draft = Fixtures::draft($entry, $editor, ['title' => 'Panel']);
    $this->actingAs($editor);

    expect($draft->getSidebarHtml(false))->toContain('Publish this draft later')
        ->and($entry->getSidebarHtml(false))->not->toContain('Publish this draft later');
});

it('reports failed drafts in the console', function() {
    [, $draft] = Fixtures::scheduledDraft();
    $draft->title = '';
    Craft::$app->getElements()->saveElement($draft, false);
    ScheduleRecord::updateAll(['publishAt' => Db::prepareDateForDb(new DateTime('-1 minute'))], ['draftId' => $draft->id]);

    [$exitCode, $output] = Fixtures::console(DraftsController::class, 'publish');
    expect($exitCode)->toBe(ExitCode::OK)
        ->and($output)->toContain('Published: 0. Failed: 1.');

    [, $output] = Fixtures::console(DraftsController::class, 'list');
    expect($output)->toContain("Draft $draft->id of entry")
        ->and($output)->toContain(': failed at')
        ->and($output)->toContain('Title cannot be blank.');
});

it('shows the scheduled time in the system time zone in the draft sidebar', function() {
    $editor = Fixtures::user('editor', Fixtures::editorPermissions());
    $entry = Fixtures::entry('news', ['author' => $editor]);
    $draft = Fixtures::draft($entry, $editor, ['title' => 'Sidebar time']);
    // 18:00 UTC on 10 January is 10:00 in Los Angeles (UTC-8 in winter).
    Fixtures::plugin()->schedules->schedule($draft, new DateTime('2027-01-10 18:00:00', new DateTimeZone('UTC')), $editor);
    $this->actingAs($editor);

    $html = $draft->getSidebarHtml(false);

    expect($html)->toContain('value="2027-01-10T10:00"')
        ->and($html)->toContain('America/Los_Angeles');
});

it('needs access to Datebook for the scheduling actions', function() {
    $editor = Fixtures::user('editor', array_values(array_diff(Fixtures::editorPermissions(), ['accessPlugin-datebook'])));
    $entry = Fixtures::entry('news', ['author' => $editor]);
    $draft = Fixtures::draft($entry, $editor, ['title' => 'No access']);

    $this->withExceptionHandling()
        ->actingAs($editor)
        ->postJson('/admin/actions/datebook/schedules/save', [
            'draftId' => $draft->id,
            'siteId' => $draft->siteId,
            'publishAt' => (new DateTime('+1 day'))->format('Y-m-d\TH:i'),
        ])
        ->assertForbidden();

    expect(ScheduleRecord::find()->where(['draftId' => $draft->id])->exists())->toBeFalse()
        ->and($draft->getSidebarHtml(false))->not->toContain('Publish this draft later');
});
