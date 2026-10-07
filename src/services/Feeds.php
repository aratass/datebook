<?php

namespace zemis\datebook\services;

use Craft;
use craft\elements\User;
use craft\helpers\App;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use craft\models\Site;
use DateTime;
use Throwable;
use yii\base\Component;
use zemis\datebook\Datebook;
use zemis\datebook\helpers\Dates;
use zemis\datebook\helpers\Ics;
use zemis\datebook\models\CalendarQuery;
use zemis\datebook\records\FeedTokenRecord;

/**
 * Private calendar feeds (ICS). Each user gets one secret link that shows the
 * items they are allowed to see. The link can be reset at any time.
 */
class Feeds extends Component
{
    public function canSubscribe(User $user): bool
    {
        return Datebook::getInstance()->getSettings()->enableFeeds
            && $user->getStatus() === User::STATUS_ACTIVE
            && $user->can('accessPlugin-datebook')
            && $user->can(Datebook::PERMISSION_SUBSCRIBE);
    }

    /**
     * The user's feed URL. Creates a token if the user has none and `$create` is true.
     */
    public function getFeedUrl(User $user, bool $create = false): ?string
    {
        $token = $this->getToken($user);
        if ($token === null && $create) {
            $token = $this->resetToken($user);
        }

        return $token !== null ? $this->urlForToken($token) : null;
    }

    public function urlForToken(string $token): string
    {
        $path = "datebook/feed/$token.ics";

        $baseUrl = $this->getFeedBaseUrl();
        if ($baseUrl !== null) {
            return $this->joinUrl($baseUrl, $path);
        }

        $primarySite = Craft::$app->getSites()->getPrimarySite();

        return UrlHelper::siteUrl($path, null, null, $primarySite->id);
    }

    /**
     * Where feed links start when that is not the primary site's URL: the
     * `feedBaseUrl` setting, or the control panel's address in headless mode,
     * because the site URL then belongs to a front end that Craft does not serve.
     */
    public function getFeedBaseUrl(): ?string
    {
        $setting = trim((string)App::parseEnv(Datebook::getInstance()->getSettings()->feedBaseUrl));
        if (preg_match('#^https?://[^/\s]+#i', $setting)) {
            return $setting;
        }
        if ($setting !== '') {
            // For example an environment variable that is not set in this environment.
            Craft::warning("The feed link address \"$setting\" is not a full URL, so it is ignored.", __METHOD__);
        }

        // Without a control panel trigger, every request to that address is a control
        // panel request, and the feed route only works on site requests.
        $generalConfig = Craft::$app->getConfig()->getGeneral();
        if ($generalConfig->headlessMode && $generalConfig->cpTrigger) {
            return UrlHelper::baseCpUrl();
        }

        return null;
    }

    /**
     * Creates a new token for the user. The old link stops working.
     */
    public function resetToken(User $user): string
    {
        $security = Craft::$app->getSecurity();
        $token = $security->generateRandomString(40);

        $record = FeedTokenRecord::findOne(['userId' => $user->id]) ?? new FeedTokenRecord();
        $record->userId = (int)$user->id;
        $record->tokenHash = $this->hash($token);
        $record->token = base64_encode($security->encryptByKey($token));
        $record->lastUsedAt = null;
        $record->save(false);

        return $token;
    }

    /**
     * Deletes the user's token. The link stops working.
     */
    public function revokeToken(User $user): void
    {
        FeedTokenRecord::deleteAll(['userId' => $user->id]);
    }

    /**
     * The user's current token, or null if there is none or it cannot be read
     * (for example after the security key changed).
     */
    public function getToken(User $user): ?string
    {
        $record = FeedTokenRecord::findOne(['userId' => $user->id]);
        if (!$record) {
            return null;
        }

        try {
            $decoded = base64_decode($record->token, true);
            $token = $decoded !== false ? Craft::$app->getSecurity()->decryptByKey($decoded) : false;
        } catch (Throwable) {
            $token = false;
        }

        if (!is_string($token) || $token === '' || !hash_equals($record->tokenHash, $this->hash($token))) {
            return null;
        }

        return $token;
    }

    /**
     * Finds the active user a token belongs to.
     */
    public function findUserByToken(string $token): ?User
    {
        if (!preg_match('/^[A-Za-z0-9_-]{20,128}$/', $token)) {
            return null;
        }

        $record = FeedTokenRecord::findOne(['tokenHash' => $this->hash($token)]);
        if (!$record) {
            return null;
        }

        $user = User::find()->id($record->userId)->status(null)->one();
        if (!$user instanceof User || !$this->canSubscribe($user)) {
            return null;
        }

        // Remember when the feed was last read, at most once an hour.
        $lastUsed = $record->lastUsedAt ? DateTimeHelper::toDateTime($record->lastUsedAt) : false;
        if (!$lastUsed || $lastUsed->getTimestamp() < time() - 3600) {
            FeedTokenRecord::updateAll(['lastUsedAt' => Db::prepareDateForDb(DateTimeHelper::now())], ['id' => $record->id]);
        }

        return $user;
    }

    /**
     * Builds the ICS feed for the user.
     *
     * @param int[]|null $sectionIds
     */
    public function render(User $user, Site $site, ?array $sectionIds = null, ?DateTime $now = null): string
    {
        $settings = Datebook::getInstance()->getSettings();
        $now ??= DateTimeHelper::now();
        $start = Dates::addDays($now, -max(0, $settings->feedPastDays));
        $end = Dates::addDays($now, max(1, $settings->feedFutureDays));

        $query = new CalendarQuery(
            start: $start,
            end: $end,
            siteId: (int)$site->id,
            sectionIds: $sectionIds,
            includeExpiry: $settings->showExpiryDates,
        );

        $items = Datebook::getInstance()->calendar->getItems($query, $user);
        $name = Craft::t('datebook', '{site} content calendar', ['site' => Craft::t('site', $site->getName())]);
        $host = (string)(parse_url((string)$site->getBaseUrl(), PHP_URL_HOST) ?: 'localhost');

        return Ics::calendar($name, $items, $host, $now);
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Adds a route path to a base URL, with `index.php` when Craft is set to show it.
     */
    private function joinUrl(string $baseUrl, string $path): string
    {
        $baseUrl = rtrim($baseUrl, '/');
        $generalConfig = Craft::$app->getConfig()->getGeneral();

        if ($generalConfig->omitScriptNameInUrls) {
            return "$baseUrl/$path";
        }

        if ($generalConfig->usePathInfo || !$generalConfig->pathParam) {
            return "$baseUrl/index.php/$path";
        }

        return "$baseUrl/index.php?" . http_build_query([$generalConfig->pathParam => $path]);
    }
}
