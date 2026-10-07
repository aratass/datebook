<?php
/**
 * Application config for the Datebook test project.
 *
 * Mail goes nowhere: the null transport accepts every message, so tests can
 * check what would be sent without a mail server. The cache lives in memory,
 * so a test run never depends on files left behind by the previous one.
 */

use craft\helpers\App;
use yii\caching\ArrayCache;

return [
    'id' => App::env('CRAFT_APP_ID') ?: 'datebook-test',
    'components' => [
        'cache' => [
            'class' => ArrayCache::class,
            'serializer' => false,
        ],
        'mailer' => function() {
            $config = App::mailerConfig();
            $config['transport'] = ['dsn' => 'null://null'];

            return Craft::createObject($config);
        },
    ],
];
