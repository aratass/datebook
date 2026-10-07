<?php

namespace zemis\datebook\widgets;

use Craft;
use craft\base\Widget;
use craft\elements\User;
use craft\helpers\Cp;
use craft\helpers\UrlHelper;
use craft\web\View;
use DateTime;
use DateTimeZone;
use zemis\datebook\Datebook;
use zemis\datebook\helpers\Dates;
use zemis\datebook\models\CalendarQuery;
use zemis\datebook\web\assets\calendar\CalendarAsset;

/**
 * Dashboard widget that lists what goes live, expires or gets published soon.
 */
class Upcoming extends Widget
{
    /** @var int How many days to show, starting today. */
    public int $days = 14;

    /** @var bool Whether expiry dates are listed. */
    public bool $showExpiry = true;

    /** @var bool Whether scheduled drafts are listed. */
    public bool $showDrafts = true;

    public static function displayName(): string
    {
        return Craft::t('datebook', 'Upcoming content');
    }

    public static function icon(): ?string
    {
        return 'calendar';
    }

    public static function isSelectable(): bool
    {
        $user = Craft::$app->getUser()->getIdentity();

        return $user instanceof User && $user->can('accessPlugin-datebook') && parent::isSelectable();
    }

    public function getTitle(): ?string
    {
        return Craft::t('datebook', 'Next {days} days', ['days' => $this->days]);
    }

    /**
     * @return array<int, array<int|string, mixed>>
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['days'], 'integer', 'min' => 1, 'max' => 60];
        $rules[] = [['showExpiry', 'showDrafts'], 'boolean'];

        return $rules;
    }

    public function getSettingsHtml(): ?string
    {
        return Cp::textFieldHtml([
            'label' => Craft::t('datebook', 'Days'),
            'instructions' => Craft::t('datebook', 'How many days to show, starting today (1 to 60).'),
            'id' => 'days',
            'name' => 'days',
            'type' => 'number',
            'min' => 1,
            'max' => 60,
            'size' => 4,
            'value' => $this->days,
            'errors' => $this->getErrors('days'),
        ]) .
        Cp::lightswitchFieldHtml([
            'label' => Craft::t('datebook', 'Show expiry dates'),
            'id' => 'showExpiry',
            'name' => 'showExpiry',
            'on' => $this->showExpiry,
        ]) .
        Cp::lightswitchFieldHtml([
            'label' => Craft::t('datebook', 'Show scheduled drafts'),
            'id' => 'showDrafts',
            'name' => 'showDrafts',
            'on' => $this->showDrafts,
        ]);
    }

    public function getBodyHtml(): ?string
    {
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user instanceof User || !$user->can('accessPlugin-datebook')) {
            return null;
        }

        $plugin = Datebook::getInstance();
        $site = $plugin->calendar->resolveSite($user, Cp::requestedSite()?->handle);
        if (!$site) {
            return null;
        }

        $timeZone = new DateTimeZone(Craft::$app->getTimeZone());
        $start = Dates::startOfDay(new DateTime('now', $timeZone));
        $end = Dates::addDays($start, max(1, min(60, $this->days)));

        $statuses = ['live', 'pending', 'disabled'];
        if ($this->showDrafts) {
            $statuses[] = 'drafts';
        }

        $query = new CalendarQuery(
            start: $start,
            end: $end,
            siteId: (int)$site->id,
            statuses: $statuses,
            includeExpiry: $this->showExpiry,
        );

        $items = $plugin->calendar->getItems($query, $user);
        $days = $plugin->calendar->groupByDay($items, $timeZone);
        $formatter = Craft::$app->getFormatter();
        $labels = [];
        foreach (array_keys($days) as $day) {
            $labels[$day] = $formatter->asDate(Dates::parseDay((string)$day, $timeZone), 'EEEE, d MMM');
        }

        $view = Craft::$app->getView();
        $view->registerAssetBundle(CalendarAsset::class);

        return $view->renderTemplate('datebook/_widgets/upcoming.twig', [
            'days' => $days,
            'labels' => $labels,
            'timeZone' => $timeZone,
            'today' => $start->format('Y-m-d'),
            'calendarUrl' => UrlHelper::cpUrl('datebook', ['view' => 'list', 'site' => $site->handle]),
        ], View::TEMPLATE_MODE_CP);
    }
}
