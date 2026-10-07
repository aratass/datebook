<?php

namespace zemis\datebook\services;

use Craft;
use craft\base\Element;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\elements\User;
use craft\errors\InvalidElementException;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Queue;
use craft\web\View;
use DateTime;
use DateTimeZone;
use Throwable;
use yii\base\Component;
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
 * Due drafts are published by the `datebook/drafts/publish` console command
 * or by a queue job that is pushed with a delay when a draft is scheduled.
 */
class Schedules extends Component
{
    public const RESULT_PUBLISHED = 'published';
    public const RESULT_FAILED = 'failed';
    public const RESULT_RETRY = 'retry';
    public const RESULT_MISSING = 'missing';

    private const DUE_CHECK_CACHE_KEY = 'datebook:dueCheck';
    private const PUBLISH_LOCK = 'datebook:publishDue';

    /** Seconds before a draft can be scheduled, so it is not published while the user still sees the form. */
    public int $minimumLeadSeconds = 60;

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

        $this->pushJob($publishAt);

        return Schedule::fromRecord($record);
    }

    /**
     * Removes the schedule from a draft.
     *
     * @throws ScheduleException if the user may not change the draft
     */
    public function unschedule(Entry $draft, User $user): bool
    {
        $record = ScheduleRecord::findOne(['draftId' => $draft->id]);
        if (!$record) {
            return false;
        }

        if (
            !$user->can(Datebook::PERMISSION_SCHEDULE_DRAFTS) ||
            !Datebook::getInstance()->calendar->canEditSite($user, (int)$draft->siteId) ||
            !Craft::$app->getElements()->canSave($draft, $user)
        ) {
            throw new ScheduleException(Craft::t('datebook', 'You are not allowed to change this schedule.'));
        }

        return (bool)$record->delete();
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
     * @return array{published: int, failed: int, retry: int, missing: int}
     */
    public function publishDue(?DateTime $now = null): array
    {
        $counts = [self::RESULT_PUBLISHED => 0, self::RESULT_FAILED => 0, self::RESULT_RETRY => 0, self::RESULT_MISSING => 0];
        $mutex = Craft::$app->getMutex();

        if (!$mutex->acquire(self::PUBLISH_LOCK, 5)) {
            Craft::info('Another process is publishing scheduled drafts.', __METHOD__);
            return $counts;
        }

        try {
            foreach ($this->getDueSchedules($now) as $schedule) {
                $counts[$this->publish($schedule)]++;
            }
        } finally {
            $mutex->release(self::PUBLISH_LOCK);
            Craft::$app->getCache()->delete(self::DUE_CHECK_CACHE_KEY);
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
     * Applies one scheduled draft to its live entry.
     *
     * Permissions are checked again for the user who scheduled the draft, because
     * they may have changed since.
     *
     * @return string One of the RESULT_* constants
     */
    public function publish(Schedule $schedule): string
    {
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

        $lockKey = "element:$draft->canonicalId";
        $mutex = Craft::$app->getMutex();
        if (!$mutex->acquire($lockKey, 15)) {
            ScheduleRecord::updateAll(['attempts' => $schedule->attempts + 1], ['id' => $schedule->id]);
            return self::RESULT_RETRY;
        }

        $userComponent = Craft::$app->getUser();
        $previousIdentity = $userComponent->getIdentity(false);
        $draftName = Drafts::name($draft);
        $creatorId = Drafts::creatorId($draft);

        try {
            // Act as the user who scheduled the draft, so the new revision is credited to them.
            $userComponent->setIdentity($user);

            $errors = $this->validateDraft($draft);
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
     * Pushes a publish job that becomes available at the given time.
     */
    public function pushJob(DateTime $runAt): void
    {
        $delay = max(0, $runAt->getTimestamp() - time());
        Queue::push(new PublishDrafts(), null, $delay);
    }

    /**
     * Pushes a publish job when drafts are due. Runs at most once a minute.
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

            $hasDue = ScheduleRecord::find()
                ->where(['status' => ScheduleRecord::STATUS_PENDING])
                ->andWhere(['<=', 'publishAt', Db::prepareDateForDb(DateTimeHelper::now())])
                ->exists();

            if ($hasDue) {
                Queue::push(new PublishDrafts());
                // Do not push another job while this one waits in the queue.
                $cache->set(self::DUE_CHECK_CACHE_KEY, 1, 300);
            }
        } catch (Throwable $e) {
            Craft::warning('Could not check for due drafts: ' . $e->getMessage(), __METHOD__);
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
     * @return string|null Why the draft is not valid, or null when it is
     */
    private function validateDraft(Entry $draft): ?string
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
