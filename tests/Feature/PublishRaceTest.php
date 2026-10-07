<?php

use craft\elements\Entry;
use craft\services\Drafts;
use yii\base\Event;
use zemis\datebook\errors\ScheduleException;
use zemis\datebook\models\Schedule;
use zemis\datebook\records\ScheduleRecord;
use zemis\datebook\services\Schedules;
use zemis\datebook\tests\Support\Fixtures;

beforeAll(fn() => Fixtures::boot());

/**
 * The due schedule of the draft, as a publishing run that started before a change would have read it.
 */
function datebookDueSnapshot(Entry $draft, string $now = '+3 hours'): Schedule
{
    $due = array_values(array_filter(
        Fixtures::plugin()->schedules->getDueSchedules(new DateTime($now)),
        fn(Schedule $schedule) => $schedule->draftId === (int)$draft->id,
    ));
    expect($due)->toHaveCount(1);

    return $due[0];
}

it('does not publish a draft that was unscheduled after the due list was read', function() {
    [$entry, $draft, , $editor] = Fixtures::scheduledDraft(['title' => 'Unscheduled in time']);
    $schedules = Fixtures::plugin()->schedules;
    $snapshot = datebookDueSnapshot($draft);

    expect($schedules->unschedule($draft, $editor))->toBeTrue();

    expect($schedules->publish($snapshot, new DateTime('+3 hours')))->toBe(Schedules::RESULT_SKIPPED)
        ->and(Entry::find()->id($entry->id)->status(null)->one()->title)->toBe('Old title')
        ->and(Entry::find()->drafts()->id($draft->id)->status(null)->exists())->toBeTrue();
});

it('does not publish a draft that was moved to a later time after the due list was read', function() {
    [$entry, $draft, , $editor] = Fixtures::scheduledDraft(['title' => 'Moved to next week']);
    $schedules = Fixtures::plugin()->schedules;
    $snapshot = datebookDueSnapshot($draft);

    $schedules->schedule($draft, new DateTime('+7 days'), $editor);

    expect($schedules->publish($snapshot, new DateTime('+3 hours')))->toBe(Schedules::RESULT_SKIPPED)
        ->and(Entry::find()->id($entry->id)->status(null)->one()->title)->toBe('Old title')
        ->and(ScheduleRecord::findOne(['draftId' => $draft->id])->status)->toBe(ScheduleRecord::STATUS_PENDING);
});

it('does not publish a draft that was marked as failed after the due list was read', function() {
    [$entry, $draft] = Fixtures::scheduledDraft(['title' => 'Failed meanwhile']);
    $schedules = Fixtures::plugin()->schedules;
    $snapshot = datebookDueSnapshot($draft);

    ScheduleRecord::updateAll(['status' => ScheduleRecord::STATUS_FAILED, 'error' => 'Failed elsewhere'], ['draftId' => $draft->id]);

    expect($schedules->publish($snapshot, new DateTime('+3 hours')))->toBe(Schedules::RESULT_SKIPPED)
        ->and(Entry::find()->id($entry->id)->status(null)->one()->title)->toBe('Old title')
        ->and(ScheduleRecord::findOne(['draftId' => $draft->id])->error)->toBe('Failed elsewhere');
});

it('publishes with the latest schedule when it was moved earlier', function() {
    [$entry, $draft, , $editor] = Fixtures::scheduledDraft(['title' => 'Moved earlier'], '+2 hours');
    $schedules = Fixtures::plugin()->schedules;
    $snapshot = datebookDueSnapshot($draft);

    $schedules->schedule($draft, new DateTime('+90 minutes'), $editor);

    expect($schedules->publish($snapshot, new DateTime('+3 hours')))->toBe(Schedules::RESULT_PUBLISHED)
        ->and(Entry::find()->id($entry->id)->status(null)->one()->title)->toBe('Moved earlier');
});

it('checks the person who scheduled the draft last', function() {
    [$entry, $draft] = Fixtures::scheduledDraft(['title' => 'Taken over']);
    $schedules = Fixtures::plugin()->schedules;
    $snapshot = datebookDueSnapshot($draft);

    // Someone else takes the schedule over, then loses the permission to schedule drafts.
    $other = Fixtures::user('other', Fixtures::editorPermissions());
    $schedules->schedule($draft, new DateTime('+2 hours'), $other);
    Fixtures::grant($other, array_values(array_diff(Fixtures::editorPermissions(), ['datebook-scheduleDrafts'])));

    expect($schedules->publish($snapshot, new DateTime('+3 hours')))->toBe(Schedules::RESULT_FAILED)
        ->and(Entry::find()->id($entry->id)->status(null)->one()->title)->toBe('Old title')
        ->and((int)ScheduleRecord::findOne(['draftId' => $draft->id])->userId)->toBe((int)$other->id);
});

it('counts drafts that changed while the run was busy with others', function() {
    Fixtures::scheduledDraft(['title' => 'First'], '+2 hours');
    [$second, $secondDraft, , $editor] = Fixtures::scheduledDraft(['title' => 'Second'], '+150 minutes');
    $schedules = Fixtures::plugin()->schedules;

    // While the first draft is applied, someone moves the second one to tomorrow.
    $handler = function() use ($schedules, $secondDraft, $editor) {
        $schedules->schedule($secondDraft, new DateTime('+1 day'), $editor);
    };
    Event::on(Drafts::class, Drafts::EVENT_AFTER_APPLY_DRAFT, $handler);
    try {
        $counts = $schedules->publishDue(new DateTime('+3 hours'));
    } finally {
        Event::off(Drafts::class, Drafts::EVENT_AFTER_APPLY_DRAFT, $handler);
    }

    expect($counts)->toBe(['published' => 1, 'failed' => 0, 'retry' => 0, 'missing' => 0, 'skipped' => 1])
        ->and(Entry::find()->id($second->id)->status(null)->one()->title)->toBe('Old title')
        ->and(ScheduleRecord::findOne(['draftId' => $secondDraft->id])->status)->toBe(ScheduleRecord::STATUS_PENDING);
});

it('does not change a schedule while its entry is locked', function() {
    [$entry, $draft, , $editor] = Fixtures::scheduledDraft();
    $schedules = Fixtures::plugin()->schedules;
    $before = $schedules->getScheduleByDraftId($draft->id)->publishAt->getTimestamp();
    $mutex = Craft::$app->getMutex();
    expect($mutex->acquire("element:$entry->id"))->toBeTrue();

    try {
        expect(fn() => $schedules->unschedule($draft, $editor))->toThrow(ScheduleException::class)
            ->and(fn() => $schedules->schedule($draft, new DateTime('+5 hours'), $editor))->toThrow(ScheduleException::class);
    } finally {
        $mutex->release("element:$entry->id");
    }

    expect($schedules->getScheduleByDraftId($draft->id)->publishAt->getTimestamp())->toBe($before);
});
