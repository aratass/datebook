<?php

use craft\db\Table;
use craft\elements\Entry;
use craft\helpers\Db;
use zemis\datebook\Datebook;
use zemis\datebook\jobs\PublishDrafts;
use zemis\datebook\records\ScheduleRecord;
use zemis\datebook\services\Schedules;
use zemis\datebook\tests\Support\Fixtures;
use zemis\datebook\tests\Support\SqsLikeQueue;

beforeAll(fn() => Fixtures::boot());

/**
 * Lets the given number of seconds pass.
 *
 * Time cannot really pass in a test, so every stored time moves back instead:
 * schedules, the jobs waiting in the queue and the job Datebook remembers.
 */
function datebookTravel(int $seconds): void
{
    foreach (ScheduleRecord::find()->all() as $record) {
        $publishAt = (new DateTime($record->publishAt, new DateTimeZone('UTC')))->modify("-$seconds seconds");
        ScheduleRecord::updateAll(['publishAt' => Db::prepareDateForDb($publishAt)], ['id' => $record->id]);
    }

    foreach (Fixtures::publishJobs() as $row) {
        Db::update(Table::QUEUE, ['timePushed' => (int)$row['timePushed'] - $seconds], ['id' => $row['id']]);
    }

    $cache = Craft::$app->getCache();
    $last = $cache->get('datebook:nextJob');
    if (is_array($last)) {
        $last['at'] -= $seconds;
        $cache->set('datebook:nextJob', $last);
    }
}

/**
 * Lets the given number of seconds pass and runs what the queue has due by then.
 */
function datebookRunJobLater(int $seconds): void
{
    datebookTravel($seconds);
    Craft::$app->getQueue()->run();
}

it('schedules a draft two hours ahead on a queue that allows 15 minute delays at most', function() {
    $sqs = new SqsLikeQueue();

    [, $draft] = $sqs->use(fn() => Fixtures::scheduledDraft(['title' => 'Cloud draft'], '+2 hours'));

    expect(ScheduleRecord::find()->where(['draftId' => $draft->id, 'status' => ScheduleRecord::STATUS_PENDING])->exists())->toBeTrue()
        ->and($sqs->delays)->toBe([Schedules::MAX_QUEUE_DELAY]);
});

it('saves schedules from the sidebar and the calendar when the queue fails', function() {
    $sqs = new SqsLikeQueue(['broken' => true]);
    $editor = Fixtures::user('editor', Fixtures::editorPermissions());
    $entry = Fixtures::entry('news', ['author' => $editor]);
    $draft = Fixtures::draft($entry, $editor, ['title' => 'Queue is down']);
    $timeZone = new DateTimeZone(Craft::$app->getTimeZone());
    $when = new DateTime('+1 day 10:15', $timeZone);
    $later = (clone $when)->modify('+2 days');

    $sqs->use(function() use ($editor, $draft, $when, $later) {
        $this->actingAs($editor)
            ->postJson('/admin/actions/datebook/schedules/save', [
                'draftId' => $draft->id,
                'siteId' => $draft->siteId,
                'publishAt' => $when->format('Y-m-d\TH:i'),
            ])
            ->assertOk();

        $this->actingAs($editor)
            ->postJson('/admin/actions/datebook/calendar/reschedule', [
                'kind' => 'draft',
                'elementId' => $draft->id,
                'siteId' => $draft->siteId,
                'day' => $later->format('Y-m-d'),
            ])
            ->assertOk();
    });

    $schedule = Fixtures::plugin()->schedules->getScheduleByDraftId($draft->id);
    expect($schedule)->not->toBeNull()
        ->and($schedule->publishAt->setTimezone($timeZone)->format('Y-m-d H:i'))->toBe($later->format('Y-m-d') . ' 10:15')
        ->and($sqs->delays)->toBe([]);
});

it('reaches a draft due in 40 minutes through a chain of jobs', function() {
    $sqs = new SqsLikeQueue();

    $sqs->use(function() use ($sqs) {
        [$entry, $draft] = Fixtures::scheduledDraft(['title' => 'Reached in steps'], '+40 minutes');
        expect($sqs->delays)->toBe([900]);

        // 15 minutes later the job runs. 25 minutes to go, so the next job waits 15 minutes again.
        datebookRunJobLater(900);
        expect($sqs->delays)->toHaveCount(2)
            ->and($sqs->delays[1])->toBe(900)
            ->and(Fixtures::publishJobs())->toHaveCount(1);

        // 30 minutes later: 10 minutes to go, so the next job runs at the exact time.
        datebookRunJobLater(900);
        expect($sqs->delays)->toHaveCount(3)
            ->and($sqs->delays[2])->toBeGreaterThanOrEqual(598)
            ->and($sqs->delays[2])->toBeLessThanOrEqual(600)
            ->and(Fixtures::publishJobs())->toHaveCount(1);

        // 40 minutes later: the draft goes live and the chain ends.
        datebookRunJobLater(600);
        expect(Entry::find()->id($entry->id)->status(null)->one()->title)->toBe('Reached in steps')
            ->and(ScheduleRecord::find()->where(['draftId' => $draft->id])->exists())->toBeFalse()
            ->and($sqs->delays)->toHaveCount(3)
            ->and(Fixtures::publishJobs())->toBeEmpty();
    });
});

it('keeps one chain of jobs however many drafts are scheduled', function() {
    $sqs = new SqsLikeQueue();

    $sqs->use(function() use ($sqs) {
        Fixtures::scheduledDraft([], '+2 hours');
        Fixtures::scheduledDraft([], '+3 hours');
        Fixtures::scheduledDraft([], '+1 day');
        expect($sqs->delays)->toBe([900]);

        // A draft due before the waiting job needs a job of its own.
        Fixtures::scheduledDraft([], '+5 minutes');
        expect($sqs->delays)->toHaveCount(2)
            ->and($sqs->delays[1])->toBeGreaterThanOrEqual(298)
            ->and($sqs->delays[1])->toBeLessThanOrEqual(300);

        // An extra run, for example from a job left over from earlier, does not start
        // a second chain while the next job is waiting.
        (new PublishDrafts())->execute(Craft::$app->getQueue());
        expect($sqs->delays)->toHaveCount(2);

        // 15 minutes later both jobs are due. The first one publishes the due draft and
        // pushes the next job in place of the other one, so one job is left.
        datebookRunJobLater(900);
        expect($sqs->delays)->toHaveCount(3)
            ->and(Fixtures::publishJobs())->toHaveCount(1);
    });
});

it('checks again in a minute when a due draft could not be published yet', function() {
    $sqs = new SqsLikeQueue();

    $sqs->use(function() use ($sqs) {
        [$entry] = Fixtures::scheduledDraft(['title' => 'Locked for now'], '+10 minutes');
        $mutex = Craft::$app->getMutex();
        expect($mutex->acquire("element:$entry->id"))->toBeTrue();

        try {
            datebookRunJobLater(600);
        } finally {
            $mutex->release("element:$entry->id");
        }

        expect(Entry::find()->id($entry->id)->status(null)->one()->title)->toBe('Old title')
            ->and(end($sqs->delays))->toBe(Fixtures::plugin()->schedules->retryDelay);

        // A minute later the entry is free and the draft goes live.
        datebookRunJobLater(60);
        expect(Entry::find()->id($entry->id)->status(null)->one()->title)->toBe('Locked for now')
            ->and(Fixtures::publishJobs())->toBeEmpty();
    });
});

it('does not fill the queue with jobs while nothing runs it', function() {
    $sqs = new SqsLikeQueue();

    $sqs->use(function() use ($sqs) {
        Fixtures::scheduledDraft(['title' => 'Quiet weekend'], '+3 days');
        $schedules = Fixtures::plugin()->schedules;
        $jobIds = fn() => array_column(Fixtures::publishJobs(), 'id');
        $first = $jobIds();
        expect($first)->toHaveCount(1);

        // Visitors keep the site busy, but nothing runs the queue, for example on a
        // headless site where nobody opens the control panel.
        datebookTravel(960);
        Craft::$app->getCache()->delete('datebook:dueCheck');
        $schedules->queueDueDraftsIfNeeded();

        // The job is a minute late. It waits for the queue to get to it.
        expect($jobIds())->toBe($first);

        // Hours later it has been replaced a few times, but there is still only one.
        for ($i = 0; $i < 12; $i++) {
            datebookTravel(1200);
            Craft::$app->getCache()->delete('datebook:dueCheck');
            $schedules->queueDueDraftsIfNeeded();
            expect($jobIds())->toHaveCount(1);
        }

        expect($jobIds())->not->toBe($first)
            ->and(count($sqs->delays))->toBeGreaterThan(10);

        // Once the queue runs again, the chain carries on from the job that is left.
        datebookRunJobLater(900);
        expect($jobIds())->toHaveCount(1);
    });
});

it('ends the chain when no draft is scheduled', function() {
    $sqs = new SqsLikeQueue();

    $sqs->use(fn() => (new PublishDrafts())->execute(Craft::$app->getQueue()));

    expect($sqs->delays)->toBe([]);
});

it('lets a leftover job finish quietly after Datebook was disabled or uninstalled', function() {
    Fixtures::scheduledDraft(['title' => 'Plugin gone'], '+2 hours');
    $jobs = Fixtures::publishJobs();
    expect($jobs)->toHaveCount(1);
    // The job is due.
    Db::update(Table::QUEUE, ['timePushed' => time() - 1000], ['id' => $jobs[0]['id']]);
    Fixtures::plugin()->schedules->forgetQueuedJobs();

    // Craft does not load a plugin that is disabled or uninstalled, so neither the
    // plugin nor its translations are there while the queue runs the job.
    $plugin = Yii::$app->loadedModules[Datebook::class];
    $i18n = Craft::$app->getI18n();
    $translations = $i18n->translations['datebook'] ?? null;
    unset(Yii::$app->loadedModules[Datebook::class], $i18n->translations['datebook']);
    try {
        expect(Datebook::getInstance())->toBeNull();
        Craft::$app->getQueue()->run();
    } finally {
        Yii::$app->loadedModules[Datebook::class] = $plugin;
        $i18n->translations['datebook'] = $translations;
    }

    // The job is done, without an error and without a next job.
    expect(Fixtures::publishJobs())->toBeEmpty()
        ->and(ScheduleRecord::find()->where(['status' => ScheduleRecord::STATUS_PENDING])->count())->toBe(1);
});
