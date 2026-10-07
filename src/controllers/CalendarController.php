<?php

namespace zemis\datebook\controllers;

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\UrlHelper;
use craft\i18n\Locale;
use craft\web\Controller;
use craft\web\View;
use DateTime;
use DateTimeZone;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;
use zemis\datebook\Datebook;
use zemis\datebook\errors\RescheduleException;
use zemis\datebook\helpers\Dates;
use zemis\datebook\models\CalendarItem;
use zemis\datebook\models\CalendarQuery;
use zemis\datebook\web\assets\calendar\CalendarAsset;

/**
 * The calendar page, its JSON data and drag and drop rescheduling.
 */
class CalendarController extends Controller
{
    /** How many items a month cell shows before "+N more". */
    public const ITEMS_PER_DAY = 4;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission('accessPlugin-datebook');

        return true;
    }

    /**
     * Renders the calendar.
     */
    public function actionIndex(): Response
    {
        $user = $this->user();
        $plugin = Datebook::getInstance();
        $calendar = $plugin->calendar;
        $settings = $plugin->getSettings();
        $timeZone = new DateTimeZone(Craft::$app->getTimeZone());

        $state = $this->readState($settings->defaultView, $settings->showExpiryDates, $timeZone);
        $site = $calendar->resolveSite($user, $state['site']);
        if (!$site) {
            throw new ForbiddenHttpException(Craft::t('datebook', 'You are not allowed to see any site.'));
        }
        $state['site'] = $site->handle;

        $weekStartDay = Dates::weekStartDay($user);
        [$start, $end] = Dates::range($state['view'], $state['anchor'], $weekStartDay);

        $sections = $calendar->getSections($user);
        $sectionIds = array_map(fn($section) => (int)$section->id, $sections);
        $sectionFilter = $state['section'] !== null && in_array($state['section'], $sectionIds, true) ? $state['section'] : null;
        $state['section'] = $sectionFilter;

        $query = new CalendarQuery(
            start: $start,
            end: $end,
            siteId: (int)$site->id,
            sectionIds: $sectionFilter !== null ? [$sectionFilter] : null,
            authorId: $state['author'],
            statuses: $state['statuses'],
            includeExpiry: $state['expiry'],
        );

        $items = $calendar->getItems($query, $user);
        $byDay = $calendar->groupByDay($items, $timeZone);
        $today = (new DateTime('now', $timeZone))->format('Y-m-d');
        $anchorMonth = $state['anchor']->format('Y-m');
        $formatter = Craft::$app->getFormatter();

        $days = [];
        foreach (Dates::days($start, $end) as $ymd) {
            $date = Dates::parseDay($ymd, $timeZone);
            $days[] = [
                'date' => $ymd,
                'number' => $date->format('j'),
                'label' => $formatter->asDate($date, 'full'),
                'shortLabel' => $formatter->asDate($date, 'EEE d'),
                'isToday' => $ymd === $today,
                'isPast' => $ymd < $today,
                'inMonth' => substr($ymd, 0, 7) === $anchorMonth,
                'items' => $byDay[$ymd] ?? [],
            ];
        }

        $locale = Craft::$app->getFormattingLocale();
        $weekdayNames = [];
        for ($i = 0; $i < 7; $i++) {
            $weekdayNames[] = $locale->getWeekDayName(($weekStartDay + $i) % 7, Locale::LENGTH_MEDIUM);
        }

        $baseParams = $this->stateParams($state);
        $viewUrls = [];
        foreach (Dates::VIEWS as $view) {
            $viewUrls[$view] = UrlHelper::cpUrl('datebook', array_merge($baseParams, ['view' => $view]));
        }

        $feeds = $plugin->feeds;
        $this->view->registerAssetBundle(CalendarAsset::class);

        return $this->renderTemplate('datebook/_index.twig', [
            'state' => $state,
            'calendarView' => $state['view'],
            'site' => $site,
            'sites' => $calendar->getSites($user),
            'sections' => $sections,
            'authorOptions' => $calendar->getAuthorOptions($sectionIds),
            'statusOptions' => $this->statusOptions(),
            'days' => $days,
            'weeks' => array_chunk($days, 7),
            'weekdayNames' => $weekdayNames,
            'rangeLabel' => $this->rangeLabel($state['view'], $state['anchor'], $start, $end),
            'itemCount' => count($items),
            'limitReached' => $calendar->limitReached,
            'itemsPerDay' => self::ITEMS_PER_DAY,
            'timeZone' => $timeZone,
            'prevUrl' => UrlHelper::cpUrl('datebook', array_merge($baseParams, ['date' => Dates::shift($state['view'], $state['anchor'], -1)->format('Y-m-d')])),
            'nextUrl' => UrlHelper::cpUrl('datebook', array_merge($baseParams, ['date' => Dates::shift($state['view'], $state['anchor'], 1)->format('Y-m-d')])),
            'todayUrl' => UrlHelper::cpUrl('datebook', array_merge($baseParams, ['date' => $today])),
            'viewUrls' => $viewUrls,
            'canSubscribe' => $feeds->canSubscribe($user),
            'jsConfig' => [
                'today' => $today,
                // Only used when the browser cannot work out the time in the time zone itself.
                'now' => (new DateTime('now', $timeZone))->format('Y-m-d\TH:i'),
                'timeZone' => $timeZone->getName(),
            ],
        ]);
    }

    /**
     * Returns calendar items as JSON.
     *
     * Query params: `start` and `end` (Y-m-d, end is exclusive), `site`, `section`,
     * `author`, `status[]` and `expiry`.
     */
    public function actionItems(): Response
    {
        $this->requireAcceptsJson();
        $user = $this->user();
        $plugin = Datebook::getInstance();
        $timeZone = new DateTimeZone(Craft::$app->getTimeZone());
        $state = $this->readState('month', $plugin->getSettings()->showExpiryDates, $timeZone);

        $start = Dates::parseDay($this->request->getRequiredQueryParam('start'), $timeZone);
        $end = Dates::parseDay($this->request->getRequiredQueryParam('end'), $timeZone);
        if ($end <= $start || $start->diff($end)->days > 92) {
            throw new BadRequestHttpException('The range must be between 1 and 92 days.');
        }

        $site = $plugin->calendar->resolveSite($user, $state['site']);
        if (!$site) {
            throw new ForbiddenHttpException(Craft::t('datebook', 'You are not allowed to see any site.'));
        }

        $query = new CalendarQuery(
            start: $start,
            end: $end,
            siteId: (int)$site->id,
            sectionIds: $state['section'] !== null ? [$state['section']] : null,
            authorId: $state['author'],
            statuses: $state['statuses'],
            includeExpiry: $state['expiry'],
        );

        $items = $plugin->calendar->getItems($query, $user);

        return $this->asJson([
            'site' => $site->handle,
            'timeZone' => $timeZone->getName(),
            'limitReached' => $plugin->calendar->limitReached,
            'items' => array_map(fn(CalendarItem $item) => $item->toArray($timeZone), $items),
        ]);
    }

    /**
     * Moves an item. Send `kind`, `elementId`, `siteId` and either `day` (Y-m-d,
     * keeps the time of day) or `dateTime` (Y-m-d\TH:i).
     */
    public function actionReschedule(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $user = $this->user();
        $plugin = Datebook::getInstance();
        $timeZone = new DateTimeZone(Craft::$app->getTimeZone());

        $kind = (string)$this->request->getRequiredBodyParam('kind');
        $elementId = (int)$this->request->getRequiredBodyParam('elementId');
        $siteId = (int)$this->request->getRequiredBodyParam('siteId');
        $day = $this->request->getBodyParam('day');
        $dateTime = $this->request->getBodyParam('dateTime');

        try {
            if (is_string($dateTime) && $dateTime !== '') {
                $date = Dates::parseLocalDateTime($dateTime, $timeZone);
                if ($date === null) {
                    throw new RescheduleException(Craft::t('datebook', 'Enter a valid date and time.'));
                }
            } elseif (is_string($day) && $day !== '') {
                $current = $this->currentDate($kind, $elementId, $siteId);
                try {
                    $date = Dates::moveToDay($current, $day, $timeZone);
                } catch (\InvalidArgumentException) {
                    throw new RescheduleException(Craft::t('datebook', 'Enter a valid date.'));
                }
            } else {
                throw new BadRequestHttpException('Send a day or a dateTime.');
            }

            $item = $plugin->calendar->reschedule($kind, $elementId, $siteId, $date, $user);
        } catch (RescheduleException $e) {
            return $this->asFailure($e->getMessage());
        }

        $html = $this->getView()->renderTemplate('datebook/_partials/item.twig', [
            'item' => $item,
            'timeZone' => $timeZone,
            'layout' => (string)$this->request->getBodyParam('layout', 'month'),
        ], View::TEMPLATE_MODE_CP);

        return $this->asSuccess(Craft::t('datebook', 'Rescheduled to {date}.', [
            'date' => Craft::$app->getFormatter()->asDatetime($item->getLocalDate($timeZone), 'short'),
        ]), [
            'item' => $item->toArray($timeZone),
            'html' => $html,
        ]);
    }

    /**
     * The current date of an item, used to keep its time of day when it is dropped on another day.
     *
     * @throws RescheduleException
     */
    private function currentDate(string $kind, int $elementId, int $siteId): DateTime
    {
        $plugin = Datebook::getInstance();

        if ($kind === CalendarItem::KIND_DRAFT) {
            $schedule = $plugin->schedules->getScheduleByDraftId($elementId);
            if ($schedule) {
                return $schedule->publishAt;
            }
        } else {
            $entry = Entry::find()->id($elementId)->siteId($siteId)->status(null)->one();
            if ($entry instanceof Entry) {
                $date = $kind === CalendarItem::KIND_EXPIRY ? $entry->expiryDate : $entry->postDate;
                if ($date instanceof DateTime) {
                    return $date;
                }
            }
        }

        throw new RescheduleException(Craft::t('datebook', 'The item could not be found.'));
    }

    /**
     * Reads the view state from the query string.
     *
     * @return array{view: string, anchor: DateTime, site: ?string, section: ?int, author: ?int, statuses: string[], expiry: bool}
     */
    private function readState(string $defaultView, bool $defaultExpiry, DateTimeZone $timeZone): array
    {
        $request = $this->request;

        $view = (string)$request->getQueryParam('view', $defaultView);
        if (!in_array($view, Dates::VIEWS, true)) {
            $view = 'month';
        }

        $section = $request->getQueryParam('section');
        $author = $request->getQueryParam('author');
        $statuses = $request->getQueryParam('status');
        $statuses = is_array($statuses) ? array_values(array_intersect(CalendarQuery::STATUSES, $statuses)) : CalendarQuery::STATUSES;
        $expiry = $request->getQueryParam('expiry');

        $site = $request->getQueryParam('site');

        return [
            'view' => $view,
            'anchor' => Dates::parseDay($request->getQueryParam('date'), $timeZone),
            'site' => is_string($site) ? $site : null,
            'section' => is_numeric($section) ? (int)$section : null,
            'author' => is_numeric($author) && (int)$author > 0 ? (int)$author : null,
            'statuses' => $statuses,
            'expiry' => $expiry === null ? $defaultExpiry : in_array($expiry, ['1', 'true', 'on'], true),
        ];
    }

    /**
     * @param array{view: string, anchor: DateTime, site: ?string, section: ?int, author: ?int, statuses: string[], expiry: bool} $state
     * @return array<string, mixed>
     */
    private function stateParams(array $state): array
    {
        $params = [
            'view' => $state['view'],
            'date' => $state['anchor']->format('Y-m-d'),
            'site' => $state['site'],
            'section' => $state['section'],
            'author' => $state['author'],
            'expiry' => $state['expiry'] ? '1' : '0',
        ];

        if (count($state['statuses']) !== count(CalendarQuery::STATUSES)) {
            $params['status'] = $state['statuses'];
        }

        return array_filter($params, fn($value) => $value !== null && $value !== '');
    }

    /**
     * @return array<string, string>
     */
    private function statusOptions(): array
    {
        return [
            'live' => Craft::t('app', 'Live'),
            'pending' => Craft::t('app', 'Pending'),
            'expired' => Craft::t('app', 'Expired'),
            'disabled' => Craft::t('app', 'Disabled'),
            'drafts' => Craft::t('datebook', 'Scheduled drafts'),
        ];
    }

    private function rangeLabel(string $view, DateTime $anchor, DateTime $start, DateTime $end): string
    {
        $formatter = Craft::$app->getFormatter();

        if ($view === Dates::VIEW_WEEK) {
            $last = Dates::addDays($end, -1);
            return Craft::t('datebook', '{start} to {end}', [
                'start' => $formatter->asDate($start, 'medium'),
                'end' => $formatter->asDate($last, 'medium'),
            ]);
        }

        return $formatter->asDate($anchor, 'LLLL y');
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
