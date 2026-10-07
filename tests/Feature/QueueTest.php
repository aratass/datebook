<?php

use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\helpers\Db;
use zemis\datebook\jobs\PublishDrafts;
use zemis\datebook\records\ScheduleRecord;
use zemis\datebook\services\Schedules;
use zemis\datebook\tests\Support\Fixtures;

beforeAll(fn() => Fixtures::boot());

it('publishes a scheduled draft when Craft runs the delayed queue job', function() {
    [$entry, $draft] = Fixtures::scheduledDraft(['title' => 'Through the queue']);
    expect(Fixtures::publishJobs())->not->toBeEmpty();

    // Fast forward: the draft is due and the delayed job is available.
    ScheduleRecord::updateAll(['publishAt' => Db::prepareDateForDb(new DateTime('-1 minute'))], ['draftId' => $draft->id]);
    Db::update(Table::QUEUE, ['delay' => 0], ['description' => 'Publishing scheduled drafts']);

    Craft::$app->getQueue()->run();

    expect(Entry::find()->id($entry->id)->status(null)->one()->title)->toBe('Through the queue')
        ->and(ScheduleRecord::find()->where(['draftId' => $draft->id])->exists())->toBeFalse()
        ->and(Fixtures::publishJobs())->toBeEmpty();
});

it('pushes a publish job for due drafts at most once a minute', function() {
    [, $draft] = Fixtures::scheduledDraft();
    ScheduleRecord::updateAll(['publishAt' => Db::prepareDateForDb(new DateTime('-5 minutes'))], ['draftId' => $draft->id]);
    Db::delete(Table::QUEUE, ['description' => 'Publishing scheduled drafts']);
    Craft::$app->getCache()->delete('datebook:dueCheck');
    $schedules = Fixtures::plugin()->schedules;

    $schedules->queueDueDraftsIfNeeded();
    $jobs = Fixtures::publishJobs();
    expect($jobs)->toHaveCount(1)
        ->and((int)$jobs[0]['delay'])->toBe(0);

    // A second request in the same minute does not push another job.
    $schedules->queueDueDraftsIfNeeded();
    expect(Fixtures::publishJobs())->toHaveCount(1);

    Craft::$app->getCache()->delete('datebook:dueCheck');
});

it('does not push another job while one is waiting for a later draft', function() {
    Fixtures::scheduledDraft();
    expect(Fixtures::publishJobs())->toHaveCount(1);
    Craft::$app->getCache()->delete('datebook:dueCheck');

    Fixtures::plugin()->schedules->queueDueDraftsIfNeeded();

    expect(Fixtures::publishJobs())->toHaveCount(1);
    Craft::$app->getCache()->delete('datebook:dueCheck');
});

it('replaces a lost job while drafts are scheduled', function() {
    Fixtures::scheduledDraft();
    // The job is gone, for example because someone cleared the queue.
    Db::delete(Table::QUEUE, ['description' => 'Publishing scheduled drafts']);
    $schedules = Fixtures::plugin()->schedules;
    $schedules->forgetQueuedJobs();

    $schedules->queueDueDraftsIfNeeded();

    $jobs = Fixtures::publishJobs();
    expect($jobs)->toHaveCount(1)
        ->and((int)$jobs[0]['delay'])->toBe(Schedules::MAX_QUEUE_DELAY);
    Craft::$app->getCache()->delete('datebook:dueCheck');
});

it('replaces a due job that someone removed from the queue', function() {
    [, $draft] = Fixtures::scheduledDraft();
    ScheduleRecord::updateAll(['publishAt' => Db::prepareDateForDb(new DateTime('-1 minute'))], ['draftId' => $draft->id]);

    // Datebook still remembers the job, and it is due, but the queue was cleared.
    $cache = Craft::$app->getCache();
    $last = $cache->get('datebook:nextJob');
    expect($last)->toBeArray();
    $cache->set('datebook:nextJob', ['id' => $last['id'], 'at' => time() - 30]);
    Db::delete(Table::QUEUE, ['description' => 'Publishing scheduled drafts']);
    $cache->delete('datebook:dueCheck');

    Fixtures::plugin()->schedules->queueDueDraftsIfNeeded();

    $jobs = Fixtures::publishJobs();
    expect($jobs)->toHaveCount(1)
        ->and((int)$jobs[0]['delay'])->toBe(0)
        ->and((string)$jobs[0]['id'])->not->toBe((string)$last['id']);
    $cache->delete('datebook:dueCheck');
});

it('does not push a job when no draft is scheduled', function() {
    Db::delete(Table::QUEUE, ['description' => 'Publishing scheduled drafts']);
    Craft::$app->getCache()->delete('datebook:dueCheck');

    Fixtures::plugin()->schedules->queueDueDraftsIfNeeded();

    expect(Fixtures::publishJobs())->toBeEmpty();
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
