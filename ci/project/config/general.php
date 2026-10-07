<?php
/**
 * General config for the Datebook test project.
 * Database settings come from CRAFT_DB_* environment variables.
 */

use craft\config\GeneralConfig;
use craft\helpers\App;

return GeneralConfig::create()
    ->devMode((bool)App::env('CRAFT_DEV_MODE'))
    ->allowAdminChanges(true)
    ->disallowRobots(true)
    ->omitScriptNameInUrls(true)
    ->runQueueAutomatically(false)
    ->defaultWeekStartDay(1)
    ->securityKey(App::env('CRAFT_SECURITY_KEY') ?: 'datebook-test-security-key-change-me')
    ->aliases([
        '@web' => App::env('PRIMARY_SITE_URL') ?: 'http://localhost:8080',
        '@webroot' => dirname(__DIR__) . '/web',
    ]);
