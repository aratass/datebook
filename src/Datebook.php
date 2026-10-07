<?php

namespace zemis\datebook;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Entry;
use craft\events\DefineHtmlEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterEmailMessagesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Dashboard;
use craft\services\Gc;
use craft\services\SystemMessages;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use yii\base\Event;
use zemis\datebook\models\Settings;
use zemis\datebook\services\Calendar;
use zemis\datebook\services\Feeds;
use zemis\datebook\services\Notifications;
use zemis\datebook\services\Schedules;
use zemis\datebook\widgets\Upcoming;

/**
 * Datebook: a content calendar for Craft CMS.
 *
 * @property-read Calendar $calendar
 * @property-read Schedules $schedules
 * @property-read Feeds $feeds
 * @property-read Notifications $notifications
 * @method Settings getSettings()
 */
class Datebook extends Plugin
{
    /** Lets a user move entries and scheduled drafts on the calendar. */
    public const PERMISSION_RESCHEDULE = 'datebook-reschedule';

    /** Lets a user schedule drafts of live entries. */
    public const PERMISSION_SCHEDULE_DRAFTS = 'datebook-scheduleDrafts';

    /** Lets a user subscribe to their private calendar feed. */
    public const PERMISSION_SUBSCRIBE = 'datebook-subscribe';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    /**
     * @return array<string, mixed>
     */
    public static function config(): array
    {
        return [
            'components' => [
                'calendar' => Calendar::class,
                'schedules' => Schedules::class,
                'feeds' => Feeds::class,
                'notifications' => Notifications::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerPermissions();
        $this->registerUrlRules();
        $this->registerWidget();
        $this->registerSystemMessages();
        $this->registerDraftSidebar();
        $this->registerGarbageCollection();

        // Make sure a publish job exists when drafts are due. The check is cheap and
        // runs at most once a minute, so a missed or cleared queue job still gets picked up.
        Craft::$app->onInit(function() {
            if (!Craft::$app->getRequest()->getIsConsoleRequest()) {
                $this->schedules->queueDueDraftsIfNeeded();
            }
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        if ($item !== null) {
            $item['label'] = Craft::t('datebook', 'Datebook');
        }

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('datebook/_settings.twig', [
            'settings' => $this->getSettings(),
            'sectionOptions' => $this->calendar->getSectionOptions(),
        ]);
    }

    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('datebook', 'Datebook'),
                    'permissions' => [
                        self::PERMISSION_RESCHEDULE => [
                            'label' => Craft::t('datebook', 'Reschedule entries and drafts on the calendar'),
                            'info' => Craft::t('datebook', 'Users also need permission to save the entry.'),
                        ],
                        self::PERMISSION_SCHEDULE_DRAFTS => [
                            'label' => Craft::t('datebook', 'Schedule drafts to publish later'),
                            'info' => Craft::t('datebook', 'Users also need permission to save the entry and the draft.'),
                        ],
                        self::PERMISSION_SUBSCRIBE => [
                            'label' => Craft::t('datebook', 'Subscribe to a private calendar feed'),
                        ],
                    ],
                ];
            }
        );
    }

    private function registerUrlRules(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules['datebook'] = 'datebook/calendar/index';
        });

        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_SITE_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules['datebook/feed/<token:[A-Za-z0-9_-]{20,128}>.ics'] = 'datebook/feed/ics';
        });
    }

    private function registerWidget(): void
    {
        Event::on(Dashboard::class, Dashboard::EVENT_REGISTER_WIDGET_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = Upcoming::class;
        });
    }

    private function registerSystemMessages(): void
    {
        Event::on(SystemMessages::class, SystemMessages::EVENT_REGISTER_MESSAGES, function(RegisterEmailMessagesEvent $event) {
            foreach (Notifications::defaultMessages() as $message) {
                $event->messages[] = $message;
            }
        });
    }

    private function registerDraftSidebar(): void
    {
        Event::on(Entry::class, Element::EVENT_DEFINE_SIDEBAR_HTML, function(DefineHtmlEvent $event) {
            /** @var Entry $entry */
            $entry = $event->sender;
            $html = $this->schedules->getSidebarHtml($entry);
            if ($html !== '') {
                $event->html .= $html;
            }
        });
    }

    private function registerGarbageCollection(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, function() {
            $this->schedules->deleteOrphans();
        });
    }
}
