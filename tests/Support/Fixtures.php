<?php

namespace zemis\datebook\tests\Support;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\elements\User;
use craft\enums\CmsEdition;
use craft\enums\PropagationMethod;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fields\PlainText;
use craft\helpers\Db;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\Site;
use DateTime;
use DateTimeZone;
use RuntimeException;
use zemis\datebook\Datebook;

/**
 * Builds the content the tests need.
 */
final class Fixtures
{
    public const SECOND_SITE = 'second';

    /** The system time zone the tests run in. It has daylight saving time. */
    public const TIME_ZONE = 'America/Los_Angeles';

    /**
     * Creates what every test needs. Safe to call more than once.
     */
    public static function boot(): void
    {
        $plugins = Craft::$app->getPlugins();
        if (!$plugins->isPluginInstalled('datebook')) {
            $plugins->installPlugin('datebook');
        }

        // User permissions need the Pro edition. This is allowed on local test domains.
        if (Craft::$app->edition !== CmsEdition::Pro) {
            Craft::$app->setEdition(CmsEdition::Pro);
        }

        // The tests expect a system time zone with daylight saving time. Craft sets
        // this one on install, but the running process only picks it up on the next
        // boot, so set it here as well.
        $projectConfig = Craft::$app->getProjectConfig();
        if ($projectConfig->get('system.timeZone') !== self::TIME_ZONE) {
            $projectConfig->set('system.timeZone', self::TIME_ZONE);
        }
        Craft::$app->setTimeZone(self::TIME_ZONE);

        self::site(self::SECOND_SITE, 'Second site', 'de-DE');

        // Craft caches whether the install is multi-site. In the run that creates the
        // second site, the cache would still say no and element queries would skip the
        // site filter.
        Craft::$app->getIsMultiSite(true);
        Craft::$app->getIsMultiSite(true, true);

        $article = self::entryType('article', 'Article');
        $page = self::entryType('page', 'Page');
        self::section('news', 'News', Section::TYPE_CHANNEL, $article);
        self::section('blog', 'Blog', Section::TYPE_CHANNEL, $article);
        self::section('homepage', 'Homepage', Section::TYPE_SINGLE, $page);
    }

    public static function plugin(): Datebook
    {
        $plugin = Datebook::getInstance();
        if (!$plugin) {
            throw new RuntimeException('Datebook is not installed.');
        }

        return $plugin;
    }

    public static function primarySite(): Site
    {
        return Craft::$app->getSites()->getPrimarySite();
    }

    public static function secondSite(): Site
    {
        $site = Craft::$app->getSites()->getSiteByHandle(self::SECOND_SITE, true);
        if (!$site) {
            throw new RuntimeException('The second site is missing.');
        }

        return $site;
    }

    public static function section(string $handle, string $name = '', string $type = Section::TYPE_CHANNEL, ?EntryType $entryType = null): Section
    {
        $entries = Craft::$app->getEntries();
        $section = $entries->getSectionByHandle($handle);
        if ($section) {
            return $section;
        }

        $siteSettings = [];
        foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
            $siteSettings[] = new Section_SiteSettings([
                'siteId' => $site->id,
                'enabledByDefault' => true,
                'hasUrls' => $type === Section::TYPE_SINGLE,
                'uriFormat' => $type === Section::TYPE_SINGLE ? $handle : null,
                'template' => $type === Section::TYPE_SINGLE ? '_page' : null,
            ]);
        }

        $section = new Section([
            'name' => $name ?: ucfirst($handle),
            'handle' => $handle,
            'type' => $type,
            'enableVersioning' => true,
            'propagationMethod' => PropagationMethod::All,
            'siteSettings' => $siteSettings,
        ]);
        $section->setEntryTypes([$entryType ?? self::entryType('article', 'Article')]);

        if (!$entries->saveSection($section)) {
            throw new RuntimeException('Could not save section: ' . implode(' ', $section->getFirstErrors()));
        }

        return $section;
    }

    /**
     * A section whose entries need a summary, a required plain text field. Safe to
     * call more than once.
     */
    public static function summarySection(): Section
    {
        $section = Craft::$app->getEntries()->getSectionByHandle('features');
        if ($section) {
            return $section;
        }

        $fields = Craft::$app->getFields();
        $field = $fields->getFieldByHandle('datebookSummary');
        if (!$field) {
            $field = new PlainText(['name' => 'Summary', 'handle' => 'datebookSummary']);
            if (!$fields->saveField($field)) {
                throw new RuntimeException('Could not save field: ' . implode(' ', $field->getFirstErrors()));
            }
        }

        $entryType = Craft::$app->getEntries()->getEntryTypeByHandle('feature') ?? new EntryType([
            'name' => 'Feature',
            'handle' => 'feature',
        ]);
        $layout = new FieldLayout(['type' => Entry::class]);
        $tab = new FieldLayoutTab(['name' => 'Content', 'layout' => $layout, 'sortOrder' => 1]);
        $tab->setElements([
            new EntryTitleField(['required' => true]),
            new CustomField($field, ['required' => true]),
        ]);
        $layout->setTabs([$tab]);
        $entryType->setFieldLayout($layout);
        if (!Craft::$app->getEntries()->saveEntryType($entryType)) {
            throw new RuntimeException('Could not save entry type: ' . implode(' ', $entryType->getFirstErrors()));
        }

        $section = self::section('features', 'Features', Section::TYPE_CHANNEL, $entryType);
        self::forgetPermissionList();

        return $section;
    }

    /**
     * Craft remembers the list of all permissions for the whole process, and drops
     * permissions that are not on it when they are saved. Sections made after the
     * first test of a run are not on it until the list is built again.
     */
    private static function forgetPermissionList(): void
    {
        $service = Craft::$app->getUserPermissions();
        if (method_exists($service, 'reset')) {
            $service->reset();
            return;
        }

        // Craft 5.8.12 and older have no reset(), so clear the remembered lists by hand.
        $properties = ['_allPermissions' => null, '_allPermissionNames' => null, '_permissionsByGroupId' => [], '_permissionsByUserId' => []];
        foreach ($properties as $name => $value) {
            if (property_exists($service, $name)) {
                (new \ReflectionProperty($service, $name))->setValue($service, $value);
            }
        }
    }

    public static function entryType(string $handle, string $name): EntryType
    {
        $entries = Craft::$app->getEntries();
        $entryType = $entries->getEntryTypeByHandle($handle);
        if ($entryType && $entryType->hasTitleField) {
            return $entryType;
        }

        $entryType ??= new EntryType([
            'name' => $name,
            'handle' => $handle,
        ]);

        // In Craft 5 an entry type has a title field only when its field layout includes one.
        $layout = new FieldLayout(['type' => Entry::class]);
        $tab = new FieldLayoutTab(['name' => 'Content', 'layout' => $layout, 'sortOrder' => 1]);
        $tab->setElements([new EntryTitleField(['required' => true])]);
        $layout->setTabs([$tab]);
        $entryType->setFieldLayout($layout);

        if (!$entries->saveEntryType($entryType)) {
            throw new RuntimeException('Could not save entry type: ' . implode(' ', $entryType->getFirstErrors()));
        }

        return $entryType;
    }

    public static function site(string $handle, string $name, string $language): Site
    {
        $sites = Craft::$app->getSites();
        $site = $sites->getSiteByHandle($handle, true);
        if ($site) {
            return $site;
        }

        $site = new Site([
            'groupId' => self::primarySite()->groupId,
            'name' => $name,
            'handle' => $handle,
            'language' => $language,
            'hasUrls' => true,
            'baseUrl' => 'http://localhost:8080/' . $handle . '/',
            'primary' => false,
        ]);

        if (!$sites->saveSite($site)) {
            throw new RuntimeException('Could not save site: ' . implode(' ', $site->getFirstErrors()));
        }

        return $site;
    }

    /**
     * Creates a user with the given permissions. Handles in braces, like
     * `saveEntries:{news}` or `editSite:{second}`, are replaced with UIDs.
     *
     * @param string[] $permissions
     */
    public static function user(string $username, array $permissions = [], bool $admin = false): User
    {
        $suffix = bin2hex(random_bytes(4));
        $user = new User([
            'username' => "$username-$suffix",
            'email' => "$username-$suffix@example.com",
            'admin' => $admin,
            'active' => true,
        ]);

        if (!Craft::$app->getElements()->saveElement($user, false)) {
            throw new RuntimeException('Could not save user.');
        }

        if ($permissions) {
            self::grant($user, $permissions);
        }

        return $user;
    }

    /**
     * Replaces the user's permissions.
     *
     * @param string[] $permissions
     */
    public static function grant(User $user, array $permissions): void
    {
        $resolved = array_map(function(string $permission) {
            return preg_replace_callback('/\{([a-zA-Z0-9_-]+)\}/', function($m) {
                $section = Craft::$app->getEntries()->getSectionByHandle($m[1]);
                if ($section) {
                    return $section->uid;
                }
                $site = Craft::$app->getSites()->getSiteByHandle($m[1], true);
                if ($site) {
                    return $site->uid;
                }
                throw new RuntimeException("Unknown handle in permission: {$m[1]}");
            }, $permission);
        }, $permissions);

        Craft::$app->getUserPermissions()->saveUserPermissions($user->id, $resolved);
    }

    /**
     * Permissions of a typical editor for the given sections.
     *
     * @param string[] $sections
     * @return string[]
     */
    public static function editorPermissions(array $sections = ['news'], bool $peers = true): array
    {
        $permissions = [
            'accessCp',
            'accessPlugin-datebook',
            Datebook::PERMISSION_RESCHEDULE,
            Datebook::PERMISSION_SCHEDULE_DRAFTS,
            Datebook::PERMISSION_SUBSCRIBE,
            'editSite:{default}',
            'editSite:{' . self::SECOND_SITE . '}',
        ];

        foreach ($sections as $section) {
            array_push(
                $permissions,
                "viewEntries:{{$section}}",
                "createEntries:{{$section}}",
                "saveEntries:{{$section}}",
                "viewPeerEntryDrafts:{{$section}}",
                "savePeerEntryDrafts:{{$section}}",
            );
            if ($peers) {
                array_push($permissions, "viewPeerEntries:{{$section}}", "savePeerEntries:{{$section}}");
            }
        }

        return $permissions;
    }

    /**
     * Creates and saves an entry.
     *
     * @param array<string, mixed> $attributes
     */
    public static function entry(string $sectionHandle, array $attributes = []): Entry
    {
        $section = self::section($sectionHandle);
        $entry = new Entry();
        $entry->sectionId = $section->id;
        $entry->typeId = $section->getEntryTypes()[0]->id;
        $entry->siteId = $attributes['siteId'] ?? self::primarySite()->id;
        $entry->title = $attributes['title'] ?? 'Entry ' . bin2hex(random_bytes(3));
        $entry->enabled = $attributes['enabled'] ?? true;
        $entry->postDate = $attributes['postDate'] ?? new DateTime('-1 day');
        $entry->expiryDate = $attributes['expiryDate'] ?? null;

        if (isset($attributes['author'])) {
            $entry->setAuthorIds([$attributes['author']->id]);
        } elseif ($section->type !== Section::TYPE_SINGLE) {
            $admin = User::find()->admin(true)->status(null)->one();
            $entry->setAuthorIds($admin ? [$admin->id] : []);
        }

        if (!Craft::$app->getElements()->saveElement($entry)) {
            throw new RuntimeException('Could not save entry: ' . implode(' ', $entry->getFirstErrors()));
        }

        return $entry;
    }

    /**
     * Creates a saved (not provisional) draft of a live entry.
     *
     * @param array<string, mixed> $attributes
     */
    public static function draft(Entry $entry, User $creator, array $attributes = [], string $name = 'Update'): Entry
    {
        /** @var Entry $draft */
        $draft = Craft::$app->getDrafts()->createDraft($entry, $creator->id, $name, null, $attributes);

        return $draft;
    }

    /**
     * An editor, a live entry they wrote, a draft of it and a schedule for the draft.
     *
     * @param array<string, mixed> $draftAttributes
     * @return array{0: Entry, 1: Entry, 2: \zemis\datebook\models\Schedule, 3: User}
     */
    public static function scheduledDraft(array $draftAttributes = ['title' => 'New title'], string $when = '+2 hours', string $section = 'news'): array
    {
        $editor = self::user('editor', self::editorPermissions([$section]));
        $entry = self::entry($section, ['title' => 'Old title', 'author' => $editor]);
        $draft = self::draft($entry, $editor, $draftAttributes, 'Spring update');
        $schedule = self::plugin()->schedules->schedule($draft, new DateTime($when), $editor);

        return [$entry, $draft, $schedule, $editor];
    }

    /**
     * Datebook's publish jobs in Craft's queue table, oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function publishJobs(): array
    {
        return (new Query())
            ->from(Table::QUEUE)
            ->where(['description' => 'Publishing scheduled drafts'])
            ->orderBy(['id' => SORT_ASC])
            ->all();
    }

    /**
     * Moves the creation date of an entry's revisions into the past. Craft does not
     * create a new revision when the entry was saved in the same second as its
     * last revision, which is what happens inside a fast test.
     */
    public static function ageRevisions(Entry $entry, string $modify = '-10 minutes'): void
    {
        $revisionIds = (new Query())
            ->select(['id'])
            ->from(Table::REVISIONS)
            ->where(['canonicalId' => $entry->id])
            ->column();

        if ($revisionIds) {
            Db::update(Table::ELEMENTS, [
                'dateCreated' => Db::prepareDateForDb(new DateTime($modify)),
                'dateUpdated' => Db::prepareDateForDb(new DateTime($modify)),
            ], ['revisionId' => $revisionIds]);
        }

        Db::update(Table::ELEMENTS, [
            'dateUpdated' => Db::prepareDateForDb(new DateTime($modify)),
        ], ['id' => $entry->id]);
    }

    /**
     * Runs a console action without colors and returns its exit code and output.
     *
     * @param class-string<\yii\console\Controller> $controllerClass
     * @param array<string, mixed> $params
     * @return array{0: int, 1: string}
     */
    public static function console(string $controllerClass, string $action, array $params = []): array
    {
        OutputCapture::register();
        OutputCapture::$buffer = '';
        $controller = new $controllerClass('datebook-test', Craft::$app, ['color' => false, 'interactive' => false]);
        $filter = stream_filter_append(STDOUT, OutputCapture::FILTER, STREAM_FILTER_WRITE);

        try {
            $exitCode = (int)$controller->runAction($action, $params);
        } finally {
            stream_filter_remove($filter);
        }

        return [$exitCode, OutputCapture::$buffer];
    }

    /**
     * Runs the callback with MySQL foreign key checks turned off, to simulate rows
     * that lost their parent. Only MySQL supports this per session.
     */
    public static function withoutForeignKeys(callable $callback): void
    {
        $db = Craft::$app->getDb();
        $db->createCommand('SET FOREIGN_KEY_CHECKS=0')->execute();
        try {
            $callback();
        } finally {
            $db->createCommand('SET FOREIGN_KEY_CHECKS=1')->execute();
        }
    }

    public static function admin(): User
    {
        $admin = User::find()->admin(true)->status(null)->orderBy(['elements.id' => SORT_ASC])->one();
        if (!$admin instanceof User) {
            throw new RuntimeException('No admin user found.');
        }

        return $admin;
    }

    public static function at(string $value, string $timeZone = 'UTC'): DateTime
    {
        return new DateTime($value, new DateTimeZone($timeZone));
    }
}
