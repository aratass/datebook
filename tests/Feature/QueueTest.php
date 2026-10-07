<?php

use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\helpers\Db;
use zemis\datebook\jobs\PublishDrafts;
use zemis\datebook\records\ScheduleRecord;
use zemis\datebook\tests\Support\Fixtures;

beforeAll(fn() => Fixtures::boot());

/**
 * Publish jobs that are waiting in Craft's queue.
 *
 * @return array<int, array<string, mixed>>
 */
function datebookQueuedJobs(): array
{
    return (new Query())
        ->from(Table::QUEUE)
        ->where(['description' => 'Publishing scheduled drafts'])
        ->all();
}

it('publishes a scheduled draft when Craft runs the delayed queue job', function() {
    [$entry, $draft] = Fixtures::scheduledDraft(['title' => 'Through the queue']);
    expect(datebookQueuedJobs())->not->toBeEmpty();

    // Fast forward: the draft is due and the delayed job is available.
    ScheduleRecord::updateAll(['publishAt' => Db::prepareDateForDb(new DateTime('-1 minute'))], ['draftId' => $draft->id]);
    Db::update(Table::QUEUE, ['delay' => 0], ['description' => 'Publishing scheduled drafts']);

    Craft::$app->getQueue()->run();

    expect(Entry::find()->id($entry->id)->status(null)->one()->title)->toBe('Through the queue')
        ->and(ScheduleRecord::find()->where(['draftId' => $draft->id])->exists())->toBeFalse()
        ->and(datebookQueuedJobs())->toBeEmpty();
});

it('pushes a publish job for due drafts at most once a minute', function() {
    [, $draft] = Fixtures::scheduledDraft();
    ScheduleRecord::updateAll(['publishAt' => Db::prepareDateForDb(new DateTime('-5 minutes'))], ['draftId' => $draft->id]);
    Db::delete(Table::QUEUE, ['description' => 'Publishing scheduled drafts']);
    Craft::$app->getCache()->delete('datebook:dueCheck');
    $schedules = Fixtures::plugin()->schedules;

    $schedules->queueDueDraftsIfNeeded();
    $jobs = datebookQueuedJobs();
    expect($jobs)->toHaveCount(1)
        ->and((int)$jobs[0]['delay'])->toBe(0);

    // A second request in the same minute does not push another job.
    $schedules->queueDueDraftsIfNeeded();
    expect(datebookQueuedJobs())->toHaveCount(1);

    Craft::$app->getCache()->delete('datebook:dueCheck');
});

it('does not push a job when nothing is due', function() {
    Fixtures::scheduledDraft();
    Db::delete(Table::QUEUE, ['description' => 'Publishing scheduled drafts']);
    Craft::$app->getCache()->delete('datebook:dueCheck');

    Fixtures::plugin()->schedules->queueDueDraftsIfNeeded();

    expect(datebookQueuedJobs())->toBeEmpty();
    Craft::$app->getCache()->delete('datebook:dueCheck');
});

it('can run the publish job any number of times', function() {
    [$entry, $draft] = Fixtures::scheduledDraft(['title' => 'Only once']);
    Fixtures::ageRevisions($entry);
    ScheduleRecord::updateAll(['publishAt' => Db::prepareDateForDb(new DateTime('-1 minute'))], ['draftId' => $draft->id]);
    $queue = Craft::$app->getQueue();

    (new PublishDrafts())->execute($queue);
    $revisions = (new Query())->from(Table::REVISIONS)->where(['canonicalId' => $entry->id])->count();
    (new PublishDrafts())->execute($queue);
    (new PublishDrafts())->execute($queue);

    expect(Entry::find()->id($entry->id)->status(null)->one()->title)->toBe('Only once')
        ->and((new Query())->from(Table::REVISIONS)->where(['canonicalId' => $entry->id])->count())->toBe($revisions);
});
