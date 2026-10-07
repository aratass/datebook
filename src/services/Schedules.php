<?php

namespace zemis\datebook\services;

use Craft;
use craft\base\Element;
use craft\db\Connection;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\elements\User;
use craft\errors\InvalidElementException;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\helpers\Queue;
use craft\queue\Queue as CraftQueue;
use craft\queue\QueueInterface;
use craft\web\View;
use DateTime;
use DateTimeZone;
use Throwable;
use yii\base\Component;
use yii\db\Expression;
use yii\queue\Queue as BaseQueue;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use zemis\datebook\Datebook;
use zemis\datebook\errors\RescheduleException;
use zemis\datebook\errors\ScheduleException;
use zemis\datebook\helpers\Drafts;
use zemis\datebook\jobs\PublishDrafts;
use zemis\datebook\models\CalendarItem;
use zemis\datebook\models\CalendarQuery;
use zemis\datebook\models\Schedule;
use zemis\datebook\records\ScheduleRecord;
use zemis\datebook\web\assets\calendar\CalendarAsset;

/**
 * Scheduled drafts: drafts of live entries that are applied at a set time.
 *
 * Due drafts are published by the `datebook/drafts/publish` console command or
 * by queue jobs. A job is never delayed by more than 15 minutes, because some
 * queues refuse longer delays. While drafts are scheduled, each job pushes the
 * next one, so a job runs at least every 15 minutes and at the minute a draft is due.
 * Only one such chain of jobs is kept: before a job is pushed, Craft's queue table is
 * checked for one that is waiting. The job pushed last is also remembered in the cache,
 * which saves that check while the job is not due yet.
 */
class Schedules extends Component
{
    public const RESULT_PUBLISHED = 'published';
    public const RESULT_FAILED = 'failed';
    public const RESULT_RETRY = 'retry';
    public const RESULT_MISSING = 'missing';
    public const RESULT_SKIPPED = 'skipped';

    /**
     * The longest delay, in seconds, that a publish job is pushed with. Craft Cloud's
     * queue runs on Amazon SQS, which refuses delays over 15 minutes.
     */
    public const MAX_QUEUE_DELAY = 900;

    /**
     * The priority publish jobs are pushed with. Craft runs jobs with a lower number first
     * and gives most jobs 1024, so a due draft does not wait behind a long queue.
     */
    public const JOB_PRIORITY = 100;

    private const DUE_CHECK_CACHE_KEY = 'datebook:dueCheck';
    private const NEXT_JOB_CACHE_KEY = 'datebook:nextJob';
    private const PUBLISH_LOCK = 'datebook:publishDue';

    /** How long the job pushed last is remembered, in seconds. */
    private const JOB_MEMORY_SECONDS = 604800;

    /** Seconds before a draft can be scheduled, so it is not published while the user still sees the form. */
    public int $minimumLeadSeconds = 60;

    /** Seconds before the next job when a due draft could not be published yet, for example because its entry was locked. */
    public int $retryDelay = 60;

    /** Seconds a job may still wait in the queue after its time before it is replaced by a new one. */
    public int $lateJobSeconds = 300;

    public function getScheduleByDraftId(int $draftId): ?Schedule
    {
        $record = ScheduleRecord::findOne(['draftId' => $draftId]);

        return $record ? Schedule::fromRecord($record) : null;
    }

    /**
     * Returns why the user cannot schedule the draft, or `null` if they can.
     */
    public function getScheduleError(Entry $draft, User $user): ?string
    {
        if (!$draft->id || !$draft->getIsDraft() || $draft->isProvisionalDraft) {
            return Craft::t('datebook', 'Only saved drafts can be scheduled.');
        }

        if ($draft->getIsUnpublishedDraft()) {
            return Craft::t('datebook', 'This entry is not live yet. Set its post date instead.');
        }

        if (!Datebook::getInstance()->calendar->showsSection($draft->getSection())) {
            return Craft::t('datebook', 'This section is not on the Datebook calendar.');
        }

        if (!$user->can(Datebook::PERMISSION_SCHEDULE_DRAFTS)) {
            return Craft::t('datebook', 'You are not allowed to schedule drafts.');
        }

        if (!Datebook::getInstance()->calendar->canEditSite($user, (int)$draft->siteId)) {
            return Craft::t('datebook', 'You are not allowed to edit this site.');
        }

        if (!$this->canPublish($draft, $user)) {
            return Craft::t('datebook', 'You are not allowed to publish this draft.');
        }

        return null;
    }

    /**
     * Whether the user may save the draft and its live entry. This is the check
     * Craft makes before it applies a draft.
     */
    public function canPublish(Entry $draft, User $user): bool
    {
        $elements = Craft::$app->getElements();
        if (!$elements->canSave($draft, $user)) {
            return false;
        }

        // Elements::canSaveCanonical() does the same, but only exists since Craft 5.6.
        $canonical = $draft->getCanonical(true);

        return $canonical->id !== $draft->id && $elements->canSave($canonical, $user);
    }

    /**
     * Whether the user may remove the draft's schedule. This also works in sections
     * that were taken off the calendar after the draft was scheduled.
     */
    public function canUnschedule(Entry $draft, User $user): bool
    {
        return $user->can(Datebook::PERMISSION_SCHEDULE_DRAFTS)
            && Datebook::getInstance()->calendar->canEditSite($user, (int)$draft->siteId)
            && Craft::$app->getElements()->canSave($draft, $user);
    }

    /**
     * Schedules a draft to be published at the given time.
     *
     * @throws ScheduleException
     */
    public function schedule(Entry $draft, DateTime $publishAt, User $user): Schedule
    {
        $error = $this->getScheduleError($draft, $user);
        if ($error !== null) {
            throw new ScheduleException($error);
        }

        if ($publishAt->getTimestamp() < time() + $this->minimumLeadSeconds) {
            throw new ScheduleException(Craft::t('datebook', 'Choose a time at least one minute from now.'));
        }

        $schedule = $this->withEntryLock($draft, function() use ($draft, $publishAt, $user): Schedule {
            $record = ScheduleRecord::findOne(['draftId' => $draft->id]) ?? new ScheduleRecord();
            $record->draftId = (int)$draft->id;
            $record->canonicalId = (int)$draft->getCanonicalId();
            $record->siteId = (int)$draft->siteId;
            $record->userId = (int)$user->id;
            $record->publishAt = (string)Db::prepareDateForDb($publishAt);
            $record->status = ScheduleRecord::STATUS_PENDING;
            $record->error = null;
            $record->attempts = 0;

            if (!$record->save()) {
                throw new ScheduleException(implode(' ', $record->getFirstErrors()) ?: Craft::t('datebook', 'The schedule could not be saved.'));
            }

            return Schedule::fromRecord($record);
        });

        // A queue error never undoes the schedule. Jobs pushed later, and the console
        // command, still find the draft.
        $this->pushJob($schedule->publishAt);

        return $schedule;
    }

    /**
     * Removes the schedule from a draft.
     *
     * @throws ScheduleException if the user may not change the draft
     */
    public function unschedule(Entry $draft, User $user): bool
    {
        if (!ScheduleRecord::find()->where(['draftId' => $draft->id])->exists()) {
            return false;
        }

        if (!$this->canUnschedule($draft, $user)) {
            throw new ScheduleException(Craft::t('datebook', 'You are not allowed to change this schedule.'));
        }

        // The lock makes sure the draft is not being published at this very moment.
        return $this->withEntryLock($draft, fn() => ScheduleRecord::deleteAll(['draftId' => $draft->id]) > 0);
    }

    /**
     * Moves a scheduled draft to a new time (used by the calendar).
     *
     * @throws ForbiddenHttpException|NotFoundHttpException|RescheduleException
     */
    public function reschedule(int $draftId, int $siteId, DateTime $publishAt, User $user): CalendarItem
    {
        $record = ScheduleRecord::findOne(['draftId' => $draftId]);
        $draft = $record ? $this->findDraft($draftId, $siteId) : null;
        if (!$record || !$draft) {
            throw new NotFoundHttpException(Craft::t('datebook', 'The scheduled draft could not be found.'));
        }

        if (!Craft::$app->getElements()->canView($draft, $user)) {
            throw new ForbiddenHttpException(Craft::t('datebook', 'You are not allowed to change this draft.'));
        }

        try {
            $schedule = $this->schedule($draft, $publishAt, $user);
        } catch (ScheduleException $e) {
            throw new RescheduleException($e->getMessage(), 0, $e);
        }

        return $this->createItem($schedule, $draft, $user);
    }

    /**
     * Scheduled draft items for the calendar.
     *
     * @param int[] $sectionIds Sections the user may see
     * @return CalendarItem[]
     */
    public function getItems(CalendarQuery $query, array $sectionIds, User $user): array
    {
        $records = ScheduleRecord::find()
            ->where(['>=', 'publishAt', Db::prepareDateForDb($query->start)])
            ->andWhere(['<', 'publishAt', Db::prepareDateForDb($query->end)])
            ->orderBy(['publishAt' => SORT_ASC])
            ->all();

        if (!$records) {
            return [];
        }

        /** @var ScheduleRecord[] $records */
        $draftIds = array_map(fn(ScheduleRecord $record) => (int)$record->draftId, $records);
        $drafts = Entry::find()
            ->drafts()
            ->id($draftIds)
            ->siteId($query->siteId)
            ->status(null)
            ->with(['authors'])
            ->indexBy('id')
            ->all();

        $elements = Craft::$app->getElements();
        $items = [];

        foreach ($records as $record) {
            $draft = $drafts[$record->draftId] ?? null;
            if (!$draft instanceof Entry || !in_array((int)$draft->sectionId, $sectionIds, true)) {
                continue;
            }
            if ($query->authorId && !in_array($query->authorId, $draft->getAuthorIds(), true)) {
                continue;
            }
            if (!$elements->canView($draft, $user)) {
                continue;
            }

            $items[] = $this->createItem(Schedule::fromRecord($record), $draft, $user);
        }

        return $items;
    }

    /**
     * Publishes every draft that is due.
     *
     * @return array{published: int, failed: int, retry: int, missing: int, skipped: int}
     */
    public function publishDue(?DateTime $now = null): array
    {
        $now ??= DateTimeHelper::now();
        $counts = [
            self::RESULT_PUBLISHED => 0,
            self::RESULT_FAILED => 0,
            self::RESULT_RETRY => 0,
            self::RESULT_MISSING => 0,
            self::RESULT_SKIPPED => 0,
        ];
        $mutex = Craft::$app->getMutex();

        if (!$mutex->acquire(self::PUBLISH_LOCK, 5)) {
            Craft::info('Another process is publishing scheduled drafts.', __METHOD__);
            return $counts;
        }

        try {
            foreach ($this->getDueSchedules($now) as $schedule) {
                $counts[$this->publish($schedule, $now)]++;
            }
        } finally {
            $mutex->release(self::PUBLISH_LOCK);
        }

        return $counts;
    }

    /**
     * @return Schedule[]
     */
    public function getDueSchedules(?DateTime $now = null): array
    {
        $now ??= DateTimeHelper::now();

        /** @var ScheduleRecord[] $records */
        $records = ScheduleRecord::find()
            ->where(['status' => ScheduleRecord::STATUS_PENDING])
            ->andWhere(['<=', 'publishAt', Db::prepareDateForDb($now)])
            ->orderBy(['publishAt' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        return self::toSchedules($records);
    }

    /**
     * @return Schedule[]
     */
    public function getAllSchedules(): array
    {
        /** @var ScheduleRecord[] $records */
        $records = ScheduleRecord::find()->orderBy(['publishAt' => SORT_ASC, 'id' => SORT_ASC])->all();

        return self::toSchedules($records);
    }

    /**
     * When the next pending draft is due, or `null` when no draft is waiting.
     */
    public function getNextPublishAt(): ?DateTime
    {
        $publishAt = ScheduleRecord::find()
            ->select(['publishAt'])
            ->where(['status' => ScheduleRecord::STATUS_PENDING])
            ->orderBy(['publishAt' => SORT_ASC])
            ->scalar();

        if (!is_string($publishAt) || $publishAt === '') {
            return null;
        }

        $date = DateTimeHelper::toDateTime($publishAt);

        return $date instanceof DateTime ? $date : null;
    }

    /**
     * Applies one scheduled draft to its live entry.
     *
     * The schedule is read again once the entry is locked, because it may have been
     * removed or moved after the list of due drafts was made. Permissions are checked
     * again for the user who scheduled the draft, because they may have changed since.
     *
     * @param DateTime|null $now The time the draft must be due by
     * @return string One of the RESULT_* constants
     */
    public function publish(Schedule $schedule, ?DateTime $now = null): string
    {
        $now ??= DateTimeHelper::now();

        $draft = $this->findDraft($schedule->draftId, $schedule->siteId);
        if (!$draft) {
            ScheduleRecord::deleteAll(['id' => $schedule->id]);
            return self::RESULT_MISSING;
        }

        // The same lock Craft takes when someone applies a draft by hand.
        $lockKey = "element:$draft->canonicalId";
        $mutex = Craft::$app->getMutex();
        if (!$mutex->acquire($lockKey, 15)) {
            ScheduleRecord::updateAllCounters(['attempts' => 1], ['id' => $schedule->id]);
            return self::RESULT_RETRY;
        }

        $userComponent = Craft::$app->getUser();
        $previousIdentity = $userComponent->getIdentity(false);
        $draftName = '';
        $creatorId = null;

        try {
            // Someone may have unscheduled the draft, moved it to a later time, or published
            // it while this process waited. Only publish what is still pending and due.
            $current = $this->getScheduleByDraftId($schedule->draftId);
            if (
                $current === null ||
                $current->status !== ScheduleRecord::STATUS_PENDING ||
                $current->publishAt->getTimestamp() > $now->getTimestamp()
            ) {
                return self::RESULT_SKIPPED;
            }
            $schedule = $current;

            // Load the draft again too, so the latest saved version goes live.
            $draft = $this->findDraft($schedule->draftId, $schedule->siteId);
            if (!$draft) {
                ScheduleRecord::deleteAll(['id' => $schedule->id]);
                return self::RESULT_MISSING;
            }

            $canonical = $draft->getCanonical(true);
            if ($canonical->id === $draft->id || $canonical->trashed) {
                return $this->fail($schedule, $draft, Craft::t('datebook', 'The live entry no longer exists.'));
            }

            $user = $schedule->userId ? User::find()->id($schedule->userId)->status(null)->one() : null;
            if (!$user instanceof User) {
                return $this->fail($schedule, $draft, Craft::t('datebook', 'The user who scheduled this draft no longer exists.'));
            }
            if ($user->getStatus() !== User::STATUS_ACTIVE) {
                return $this->fail($schedule, $draft, Craft::t('datebook', 'The user who scheduled this draft is not active.'));
            }
            if (!$user->can(Datebook::PERMISSION_SCHEDULE_DRAFTS) || !$this->canPublish($draft, $user)) {
                return $this->fail($schedule, $draft, Craft::t('datebook', 'The user who scheduled this draft is no longer allowed to publish it.'));
            }

            $draftName = Drafts::name($draft);
            $creatorId = Drafts::creatorId($draft);

            // Act as the user who scheduled the draft, so the new revision is credited to them.
            $userComponent->setIdentity($user);

            // Check the draft with what changed on the live entry since it was made, as Craft
            // does when someone opens the draft. Craft saves those changes into the draft when
            // it applies it.
            $merge = $draft::trackChanges() && ElementHelper::isOutdated($draft);
            $errors = $this->validateDraft($draft, $merge);
            if ($errors !== null) {
                return $this->fail($schedule, $draft, $errors);
            }

            $entry = Craft::$app->getDrafts()->applyDraft($draft);
        } catch (InvalidElementException $e) {
            return $this->fail($schedule, $draft, $this->errorSummary($e->element->getFirstErrors()) ?: $e->getMessage());
        } catch (Throwable $e) {
            Craft::error("Could not publish scheduled draft $schedule->draftId: {$e->getMessage()}", __METHOD__);
            Craft::$app->getErrorHandler()->logException($e);
            return $this->fail($schedule, $draft, $e->getMessage());
        } finally {
            $userComponent->setIdentity($previousIdentity);
            $mutex->release($lockKey);
        }

        // The schedule row goes away with the draft. This also covers databases without foreign keys.
        ScheduleRecord::deleteAll(['id' => $schedule->id]);

        Craft::info("Published scheduled draft $schedule->draftId to entry $entry->id.", __METHOD__);

        if (Datebook::getInstance()->getSettings()->notifyOnPublish) {
            Datebook::getInstance()->notifications->sendPublished($entry, $draftName, array_filter([$schedule->userId, $creatorId]));
        }

        return self::RESULT_PUBLISHED;
    }

    /**
     * Makes sure a publish job runs by the given time.
     *
     * The job is pushed with a delay of at most 15 minutes, ahead of other jobs (see
     * [[JOB_PRIORITY]]). Nothing is pushed while a publish job waits in the queue and
     * runs early enough, because each job pushes the next one (see [[queueNextCheck()]]).
     * The queue itself is asked, so this also holds when the cache keeps nothing. A job
     * that still waits [[lateJobSeconds]] after its time is replaced, so publish jobs do
     * not pile up while nothing runs the queue. Queue errors are logged, never thrown.
     *
     * @return bool Whether a job is waiting to run by that time
     */
    public function pushJob(DateTime $runAt): bool
    {
        $now = time();
        $runAtTimestamp = max($now, min($runAt->getTimestamp(), $now + self::MAX_QUEUE_DELAY));

        try {
            $cache = Craft::$app->getCache();
            $last = self::toJob($cache->get(self::NEXT_JOB_CACHE_KEY));

            // A shortcut: the job pushed last is not due yet and runs early enough.
            if ($last !== null && $last['at'] > $now && $last['at'] <= $runAtTimestamp) {
                return true;
            }

            // The cache may have lost the job, or keep nothing at all, so ask the queue.
            $waitingJobs = $this->findWaitingJobs();
            $lateIds = [];

            foreach ($waitingJobs ?? array_filter([$this->stillWaiting($last)]) as $job) {
                if ($job['at'] > $runAtTimestamp) {
                    break;
                }

                // A job that still waits long after its time, for example because nothing
                // runs the queue, is replaced.
                if ($now - $job['at'] >= $this->lateJobSeconds) {
                    if ($job['id'] !== null) {
                        $lateIds[] = $job['id'];
                    }
                    continue;
                }

                // This job runs early enough, and it pushes the next one itself. While it
                // waits for the queue to get to it, another one would only wait behind it.
                if ($job['at'] > $now) {
                    $cache->set(self::NEXT_JOB_CACHE_KEY, $job, self::JOB_MEMORY_SECONDS);
                }
                $this->removeJobs($lateIds);

                return true;
            }

            try {
                $id = Queue::push(new PublishDrafts(), self::JOB_PRIORITY, $runAtTimestamp - $now);
            } catch (Throwable $e) {
                $this->removeUnsentJobs($waitingJobs);
                throw $e;
            }
            $cache->set(self::NEXT_JOB_CACHE_KEY, ['id' => $id, 'at' => $runAtTimestamp], self::JOB_MEMORY_SECONDS);
        } catch (Throwable $e) {
            Craft::warning("Could not add a job for scheduled drafts to the queue: {$e->getMessage()}", __METHOD__);
            return false;
        }

        // The new job does everything the late ones would have done.
        $this->removeJobs($lateIds);

        return true;
    }

    /**
     * Pushes the next publish job while drafts are scheduled: at the time the next draft
     * is due, or in 15 minutes when that is later. Publish jobs call this when they finish.
     */
    public function queueNextCheck(): void
    {
        try {
            $next = $this->getNextPublishAt();
            if ($next === null) {
                return;
            }

            $now = DateTimeHelper::now();
            if ($next <= $now) {
                // A due draft is still waiting, for example because its entry was locked.
                $next = (clone $now)->modify("+$this->retryDelay seconds");
            }

            $this->pushJob($next);
        } catch (Throwable $e) {
            Craft::warning('Could not plan the next check for scheduled drafts: ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Makes sure a publish job is waiting while drafts are scheduled. Runs on web
     * requests, at most once a minute while the cache works, so drafts still go out
     * when a job was lost or the queue was cleared.
     */
    public function queueDueDraftsIfNeeded(): void
    {
        try {
            $cache = Craft::$app->getCache();
            if ($cache->get(self::DUE_CHECK_CACHE_KEY) !== false) {
                return;
            }
            $cache->set(self::DUE_CHECK_CACHE_KEY, 1, 60);

            if (!Craft::$app->getIsInstalled() || !Craft::$app->getDb()->tableExists(ScheduleRecord::TABLE)) {
                return;
            }

            // A due draft gets a job that runs right away.
            $next = $this->getNextPublishAt();
            if ($next !== null) {
                $this->pushJob($next);
            }
        } catch (Throwable $e) {
            Craft::warning('Could not check for due drafts: ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Forgets which publish job was pushed last, so the next check asks the queue.
     */
    public function forgetQueuedJobs(): void
    {
        $cache = Craft::$app->getCache();
        $cache->delete(self::NEXT_JOB_CACHE_KEY);
        $cache->delete(self::DUE_CHECK_CACHE_KEY);
    }

    /**
     * Takes the publish jobs that wait in the queue out of it. Runs when Datebook is
     * uninstalled, because Craft cannot run them once the plugin's files are removed.
     * A job that is running already finishes on its own.
     */
    public function removeWaitingJobs(): void
    {
        try {
            $jobs = $this->findWaitingJobs();
            if ($jobs !== null) {
                $ids = array_column($jobs, 'id');
            } else {
                // This queue cannot be searched. Take out the job pushed last, if it is known.
                $last = self::toJob(Craft::$app->getCache()->get(self::NEXT_JOB_CACHE_KEY));
                $ids = $last !== null && $last['id'] !== null ? [$last['id']] : [];
            }

            $this->removeJobs($ids);
            $this->forgetQueuedJobs();
        } catch (Throwable $e) {
            Craft::warning("Could not remove the jobs for scheduled drafts from the queue: {$e->getMessage()}", __METHOD__);
        }
    }

    /**
     * Datebook's publish jobs that wait in Craft's queue table, the one that runs first
     * first. Jobs that are running or failed do not count. Returns `null` when the queue
     * keeps its jobs somewhere else or the table cannot be read.
     *
     * Craft keeps every job in this table, also when it hands the jobs on to a queue
     * service, as on Craft Cloud. Publish jobs are found by their class name, which is
     * part of the stored job, so they are found whatever language they were pushed in.
     *
     * @return array<int, array{id: string, at: int}>|null
     */
    private function findWaitingJobs(): ?array
    {
        $queue = Craft::$app->getQueue();
        if (!$queue instanceof CraftQueue || !$queue->db instanceof Connection) {
            return null;
        }

        $db = $queue->db;
        $params = [':datebookJobClass' => bin2hex(PublishDrafts::class)];
        $isPublishJob = $db->getIsPgsql()
            ? new Expression("position(decode(:datebookJobClass, 'hex') in [[job]]) > 0", $params)
            : new Expression('LOCATE(UNHEX(:datebookJobClass), [[job]]) > 0', $params);

        try {
            $rows = (new Query())
                ->select(['id', 'timePushed', 'delay'])
                ->from($queue->tableName)
                ->where([
                    // The application component ID, which Craft uses when no channel is set
                    'channel' => $queue->channel ?? 'queue',
                    'fail' => false,
                    'timeUpdated' => null,
                ])
                ->andWhere($isPublishJob)
                ->all($db);
        } catch (Throwable $e) {
            Craft::warning("Could not look for publish jobs in the queue: {$e->getMessage()}", __METHOD__);
            return null;
        }

        $jobs = [];
        foreach ($rows as $row) {
            $jobs[] = ['id' => (string)$row['id'], 'at' => (int)$row['timePushed'] + (int)$row['delay']];
        }
        usort($jobs, fn(array $a, array $b) => [$a['at'], (int)$a['id']] <=> [$b['at'], (int)$b['id']]);

        return $jobs;
    }

    /**
     * For a queue without Craft's queue table: the job pushed last if it still waits.
     * When the queue cannot tell, the job counts as waiting, but it is never taken out.
     *
     * @param array{id: string|null, at: int}|null $job
     * @return array{id: string|null, at: int}|null
     */
    private function stillWaiting(?array $job): ?array
    {
        if ($job === null) {
            return null;
        }

        $status = $this->jobStatus($job['id']);
        if ($status === BaseQueue::STATUS_WAITING) {
            return $job;
        }

        // A job that is running, finished or failed does not count.
        return $status === null ? ['id' => null, 'at' => $job['at']] : null;
    }

    /**
     * Takes out publish jobs that appeared in the queue table while a push failed.
     *
     * Craft saves a job in its table before it hands it on to a queue service such as
     * Amazon SQS. When the hand-off fails, the saved job never runs, so it must not stop
     * the next check from pushing a new one.
     *
     * @param array<int, array{id: string, at: int}>|null $before The jobs that waited before the push
     */
    private function removeUnsentJobs(?array $before): void
    {
        if ($before === null) {
            return;
        }

        $newIds = array_diff(array_column($this->findWaitingJobs() ?? [], 'id'), array_column($before, 'id'));
        $this->removeJobs(array_values($newIds));
    }

    /**
     * Reads a job as remembered in the cache.
     *
     * @return array{id: string|null, at: int}|null
     */
    private static function toJob(mixed $value): ?array
    {
        if (!is_array($value) || !is_int($value['at'] ?? null)) {
            return null;
        }

        $id = $value['id'] ?? null;

        return ['id' => is_string($id) && $id !== '' ? $id : null, 'at' => $value['at']];
    }

    /**
     * The queue status of a job (one of the `STATUS_*` constants of the queue), or
     * `null` when the queue cannot tell.
     */
    private function jobStatus(?string $id): ?int
    {
        if ($id === null) {
            return null;
        }

        try {
            return Craft::$app->getQueue()->status($id);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Takes publish jobs out of the queue.
     *
     * @param string[] $ids
     */
    private function removeJobs(array $ids): void
    {
        $queue = Craft::$app->getQueue();
        if (!$queue instanceof QueueInterface) {
            return;
        }

        foreach ($ids as $id) {
            try {
                $queue->release($id);
            } catch (Throwable $e) {
                Craft::warning("Could not remove job $id from the queue: {$e->getMessage()}", __METHOD__);
            }
        }
    }

    /**
     * Deletes schedules whose draft is gone (for databases where foreign keys were removed).
     */
    public function deleteOrphans(): int
    {
        $orphanIds = (new Query())
            ->select(['s.id'])
            ->from(['s' => ScheduleRecord::TABLE])
            ->leftJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[s.draftId]]')
            ->where(['or', ['e.id' => null], ['e.draftId' => null]])
            ->column();

        if (!$orphanIds) {
            return 0;
        }

        return ScheduleRecord::deleteAll(['id' => $orphanIds]);
    }

    /**
     * HTML for the scheduling panel in the draft editor sidebar.
     */
    public function getSidebarHtml(Entry $entry): string
    {
        if (!$entry->id || !$entry->getIsDraft() || $entry->isProvisionalDraft || $entry->getIsUnpublishedDraft()) {
            return '';
        }

        $user = Craft::$app->getUser()->getIdentity();
        if (!$user instanceof User || !$user->can('accessPlugin-datebook')) {
            return '';
        }

        $schedule = $this->getScheduleByDraftId((int)$entry->id);
        $error = $this->getScheduleError($entry, $user);
        if ($error !== null && $schedule === null) {
            return '';
        }

        $timeZone = new DateTimeZone(Craft::$app->getTimeZone());
        $view = Craft::$app->getView();
        $view->registerAssetBundle(CalendarAsset::class);
        $id = 'datebook-schedule-' . $entry->id . '-' . mt_rand();

        $html = $view->renderTemplate('datebook/_sidebar/schedule.twig', [
            'id' => $id,
            'draft' => $entry,
            'schedule' => $schedule,
            'canEdit' => $error === null,
            'canCancel' => $schedule !== null && $this->canUnschedule($entry, $user),
            'note' => $error,
            'timeZone' => $timeZone->getName(),
            'publishAtLocal' => $schedule ? $this->localValue($schedule->publishAt, $timeZone) : null,
            'minLocal' => $this->localValue(new DateTime('+2 minutes'), $timeZone),
        ], View::TEMPLATE_MODE_CP);

        $view->registerJsWithVars(fn($id) => "new Datebook.SchedulePanel($id);", [$id]);

        return $html;
    }

    public function findDraft(int $draftId, int $siteId): ?Entry
    {
        $draft = Entry::find()
            ->drafts()
            ->id($draftId)
            ->siteId($siteId)
            ->status(null)
            ->one();

        if (!$draft) {
            // The draft may not exist in that site anymore. Use any site it does exist in.
            $draft = Entry::find()
                ->drafts()
                ->id($draftId)
                ->site('*')
                ->preferSites([$siteId])
                ->unique()
                ->status(null)
                ->one();
        }

        return $draft instanceof Entry ? $draft : null;
    }

    /**
     * Runs the callback while holding the lock Craft uses for the live entry, so a
     * schedule never changes while its draft is being published.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     * @throws ScheduleException if the entry stays locked
     */
    private function withEntryLock(Entry $draft, callable $callback): mixed
    {
        $lockKey = 'element:' . $draft->getCanonicalId();
        $mutex = Craft::$app->getMutex();
        if (!$mutex->acquire($lockKey, 15)) {
            throw new ScheduleException(Craft::t('datebook', 'This entry is being saved right now. Try again in a moment.'));
        }

        try {
            return $callback();
        } finally {
            $mutex->release($lockKey);
        }
    }

    /**
     * @param ScheduleRecord[] $records
     * @return Schedule[]
     */
    private static function toSchedules(array $records): array
    {
        return array_map(fn(ScheduleRecord $record) => Schedule::fromRecord($record), $records);
    }

    private function createItem(Schedule $schedule, Entry $draft, User $user): CalendarItem
    {
        $elements = Craft::$app->getElements();
        $section = $draft->getSection();
        $canReschedule = $user->can(Datebook::PERMISSION_RESCHEDULE) && $this->getScheduleError($draft, $user) === null;

        return new CalendarItem(
            kind: CalendarItem::KIND_DRAFT,
            elementId: (int)$draft->id,
            canonicalId: (int)$draft->getCanonicalId(),
            siteId: (int)$draft->siteId,
            title: Calendar::titleFor($draft),
            status: $schedule->isFailed() ? CalendarItem::STATUS_FAILED : CalendarItem::STATUS_SCHEDULED,
            date: clone $schedule->publishAt,
            sectionId: $section?->id,
            sectionName: $section ? Craft::t('site', (string)$section->name) : '',
            cpEditUrl: $draft->getCpEditUrl(),
            authorName: $draft->getAuthor()?->getName(),
            canReschedule: $canReschedule && $elements->canView($draft, $user),
            draftName: Drafts::name($draft),
            error: $schedule->error,
        );
    }

    private function fail(Schedule $schedule, Entry $draft, string $error): string
    {
        ScheduleRecord::updateAll([
            'status' => ScheduleRecord::STATUS_FAILED,
            'error' => mb_substr($error, 0, 2000),
            'attempts' => $schedule->attempts + 1,
            'dateUpdated' => Db::prepareDateForDb(DateTimeHelper::now()),
        ], ['id' => $schedule->id]);

        Craft::warning("Scheduled draft $schedule->draftId was not published: $error", __METHOD__);

        if (Datebook::getInstance()->getSettings()->notifyOnFailure) {
            Datebook::getInstance()->notifications->sendFailed($draft, $schedule, $error, array_filter([$schedule->userId, Drafts::creatorId($draft)]));
        }

        return self::RESULT_FAILED;
    }

    /**
     * Validates the draft in every site it exists in, with the rules for live
     * content wherever it is enabled. Craft makes the same check across sites
     * before it applies a draft from the control panel.
     *
     * With `$merge`, changes made to the live entry since the draft was made are
     * brought into each copy first, in memory only. Elements::mergeCanonicalChanges()
     * would save them, and that save fills an empty title with a placeholder such as
     * "Entry 12", so a draft without a title would pass the check and go live with it.
     *
     * @return string|null Why the draft is not valid, or null when it is
     */
    private function validateDraft(Entry $draft, bool $merge = false): ?string
    {
        /** @var Entry[] $siteDrafts */
        $siteDrafts = Entry::find()
            ->drafts()
            ->id($draft->id)
            ->site('*')
            ->status(null)
            ->all();

        // Check the site the draft was scheduled from first, with the loaded draft.
        $toCheck = [$draft];
        foreach ($siteDrafts as $siteDraft) {
            if ((int)$siteDraft->siteId !== (int)$draft->siteId) {
                $toCheck[] = $siteDraft;
            }
        }

        $errors = [];
        foreach ($toCheck as $siteDraft) {
            if ($merge) {
                $siteDraft->mergeCanonicalChanges();
            }

            if ($siteDraft->enabled && $siteDraft->getEnabledForSite()) {
                $siteDraft->setScenario(Element::SCENARIO_LIVE);
            }

            if ($siteDraft->validate()) {
                continue;
            }

            $summary = $this->errorSummary($siteDraft->getFirstErrors());
            if ($siteDraft !== $draft) {
                $site = Craft::$app->getSites()->getSiteById((int)$siteDraft->siteId, true);
                $summary = Craft::t('datebook', '{site}: {errors}', [
                    'site' => $site ? Craft::t('site', $site->getName()) : $siteDraft->siteId,
                    'errors' => $summary,
                ]);
            }
            $errors[] = $summary;
        }

        return $errors ? implode(' ', $errors) : null;
    }

    /**
     * Joins validation errors into plain text. In control panel requests Craft
     * marks field names with asterisks, and some core messages contain HTML.
     *
     * @param array<string, string> $errors
     */
    private function errorSummary(array $errors): string
    {
        $lines = [];
        foreach ($errors as $error) {
            $text = html_entity_decode(strip_tags((string)$error), ENT_QUOTES | ENT_HTML5);
            $text = preg_replace('/\*([^*\r\n]+)\*/', '$1', $text) ?? $text;
            $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
            if ($text !== '') {
                $lines[] = $text;
            }
        }

        return implode(' ', $lines);
    }

    private function localValue(DateTime $date, DateTimeZone $timeZone): string
    {
        $local = clone $date;
        $local->setTimezone($timeZone);

        return $local->format('Y-m-d\TH:i');
    }
}
