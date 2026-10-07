<?php

use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\Queue as QueueHelper;
use zemis\datebook\Datebook;
use zemis\datebook\jobs\PublishDrafts;
use zemis\datebook\records\ScheduleRecord;
use zemis\datebook\services\Schedules;
use zemis\datebook\tests\Support\Fixtures;
use zemis\datebook\tests\Support\OtherJob;

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

it('finds its waiting job whatever language the job was pushed in', function() {
    Fixtures::scheduledDraft();
    // The job was pushed while the control panel was in German.
    Db::update(Table::QUEUE, ['description' => 'Geplante Entwürfe veröffentlichen'], ['description' => 'Publishing scheduled drafts']);
    $schedules = Fixtures::plugin()->schedules;
    $schedules->forgetQueuedJobs();

    $schedules->queueDueDraftsIfNeeded();

    expect(Fixtures::publishJobs())->toBeEmpty()
        ->and((int)(new Query())->from(Table::QUEUE)->where(['description' => 'Geplante Entwürfe veröffentlichen'])->count())->toBe(1);
    Craft::$app->getCache()->delete('datebook:dueCheck');
});

it('lets a publish job that runs in time stand in for a late one', function() {
    Fixtures::scheduledDraft([], '+2 hours');
    Fixtures::scheduledDraft([], '+5 minutes');
    [$late, $inTime] = Fixtures::publishJobs();
    // The first job is long overdue, for example because nothing ever ran it.
    Db::update(Table::QUEUE, ['timePushed' => time() - 2000], ['id' => $late['id']]);
    $schedules = Fixtures::plugin()->schedules;
    $schedules->forgetQueuedJobs();

    $schedules->queueDueDraftsIfNeeded();

    // No new job: the other one runs in time and does the work of both.
    expect(array_column(Fixtures::publishJobs(), 'id'))->toBe([$inTime['id']]);
    Craft::$app->getCache()->delete('datebook:dueCheck');
});

it('runs a publish job that replaced a late one before the jobs that came in meanwhile', function() {
    // Only the jobs of this test are in the queue.
    Db::delete(Table::QUEUE);
    [$entry, $draft] = Fixtures::scheduledDraft(['title' => 'Busy queue'], '+10 minutes');

    // More work comes in after the publish job, for example search index updates.
    $otherIds = [];
    for ($i = 0; $i < 3; $i++) {
        $otherIds[] = QueueHelper::push(new OtherJob());
    }

    // 16 minutes later a long job still keeps the queue busy. The draft is due and its job
    // is late, so the next check replaces the job.
    ScheduleRecord::updateAll(['publishAt' => Db::prepareDateForDb(new DateTime('-6 minutes'))], ['draftId' => $draft->id]);
    Db::update(Table::QUEUE, ['timePushed' => time() - 960], ['description' => 'Publishing scheduled drafts']);
    $schedules = Fixtures::plugin()->schedules;
    $schedules->forgetQueuedJobs();
    $schedules->queueDueDraftsIfNeeded();
    expect(Fixtures::publishJobs())->toHaveCount(1);

    // Once the long job is done, the queue runs the new publish job first.
    expect(Craft::$app->getQueue()->executeJob())->toBeTrue()
        ->and(Entry::find()->id($entry->id)->status(null)->one()->title)->toBe('Busy queue')
        ->and((int)(new Query())->from(Table::QUEUE)->where(['id' => $otherIds])->count())->toBe(3);
    Craft::$app->getCache()->delete('datebook:dueCheck');
});

it('takes its waiting publish jobs out of the queue when it is uninstalled', function() {
    Fixtures::scheduledDraft([], '+2 hours');
    Fixtures::scheduledDraft([], '+5 minutes');
    $otherId = QueueHelper::push(new OtherJob());
    [$running, $waiting] = Fixtures::publishJobs();
    // One publish job is running right now. It finishes on its own.
    Db::update(Table::QUEUE, ['timeUpdated' => time(), 'dateReserved' => Db::prepareDateForDb(new DateTime())], ['id' => $running['id']]);

    // Craft calls afterUninstall() once Datebook's tables are dropped. A real uninstall
    // would also end the database transaction of the test, so only this step runs here.
    (new ReflectionMethod(Datebook::class, 'afterUninstall'))->invoke(Fixtures::plugin());

    expect(array_column(Fixtures::publishJobs(), 'id'))->toBe([$running['id']])
        ->and((new Query())->from(Table::QUEUE)->where(['id' => $waiting['id']])->exists())->toBeFalse()
        ->and((new Query())->from(Table::QUEUE)->where(['id' => $otherId])->exists())->toBeTrue();
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
