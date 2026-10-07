<?php
/**
 * Shared bootstrap for the Datebook test project.
 */

define('CRAFT_BASE_PATH', __DIR__);
define('CRAFT_VENDOR_PATH', CRAFT_BASE_PATH . '/vendor');

require_once CRAFT_VENDOR_PATH . '/autoload.php';

if (class_exists(Dotenv\Dotenv::class) && file_exists(CRAFT_BASE_PATH . '/.env')) {
    Dotenv\Dotenv::createUnsafeImmutable(CRAFT_BASE_PATH)->safeLoad();
}

define('CRAFT_ENVIRONMENT', getenv('CRAFT_ENVIRONMENT') ?: 'dev');
