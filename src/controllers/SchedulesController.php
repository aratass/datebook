<?php

namespace zemis\datebook\controllers;

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use craft\web\Controller;
use DateTimeZone;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use zemis\datebook\Datebook;
use zemis\datebook\errors\ScheduleException;
use zemis\datebook\helpers\Dates;
use zemis\datebook\models\Schedule;

/**
 * Schedules and unschedules drafts from the draft editor sidebar.
 */
class SchedulesController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('accessPlugin-datebook');

        return true;
    }

    /**
     * Saves a schedule. Send `draftId`, `siteId` and `publishAt` (Y-m-d\TH:i in the user's time zone).
     */
    public function actionSave(): Response
    {
        $user = $this->user();
        $timeZone = new DateTimeZone(Craft::$app->getTimeZone());
        $draft = $this->draftFromRequest();

        $publishAt = Dates::parseLocalDateTime((string)$this->request->getRequiredBodyParam('publishAt'), $timeZone);
        if ($publishAt === null) {
            return $this->asFailure(Craft::t('datebook', 'Enter a valid date and time.'));
        }

        try {
            $schedule = Datebook::getInstance()->schedules->schedule($draft, $publishAt, $user);
        } catch (ScheduleException $e) {
            return $this->asFailure($e->getMessage());
        }

        return $this->asSuccess(Craft::t('datebook', 'Draft scheduled.'), $this->scheduleData($schedule, $timeZone));
    }

    /**
     * Removes a schedule. Send `draftId` and `siteId`.
     */
    public function actionCancel(): Response
    {
        $user = $this->user();
        $draft = $this->draftFromRequest();

        try {
            Datebook::getInstance()->schedules->unschedule($draft, $user);
        } catch (ScheduleException $e) {
            return $this->asFailure($e->getMessage());
        }

        return $this->asSuccess(Craft::t('datebook', 'The draft is no longer scheduled.'), [
            'statusText' => Craft::t('datebook', 'Not scheduled.'),
        ]);
    }

    private function draftFromRequest(): Entry
    {
        $draftId = (int)$this->request->getRequiredBodyParam('draftId');
        $siteId = (int)$this->request->getRequiredBodyParam('siteId');

        $draft = Datebook::getInstance()->schedules->findDraft($draftId, $siteId);
        if (!$draft) {
            throw new NotFoundHttpException(Craft::t('datebook', 'The draft could not be found.'));
        }

        if (!Craft::$app->getElements()->canView($draft, $this->user())) {
            throw new ForbiddenHttpException(Craft::t('datebook', 'You are not allowed to see this draft.'));
        }

        return $draft;
    }

    /**
     * @return array<string, string>
     */
    private function scheduleData(Schedule $schedule, DateTimeZone $timeZone): array
    {
        $local = clone $schedule->publishAt;
        $local->setTimezone($timeZone);

        return [
            'publishAt' => $local->format('Y-m-d\TH:i'),
            'statusText' => Craft::t('datebook', 'Will be published on {date}.', [
                'date' => Craft::$app->getFormatter()->asDatetime($local, 'medium'),
            ]),
        ];
    }

    /**
     * The logged in user. Access rules in beforeAction() make sure there is one.
     *
     * @throws ForbiddenHttpException
     */
    private function user(): User
    {
        $user = static::currentUser();
        if (!$user instanceof User) {
            throw new ForbiddenHttpException();
        }

        return $user;
    }
}
