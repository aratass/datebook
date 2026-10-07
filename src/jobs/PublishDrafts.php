<?php

namespace zemis\datebook\jobs;

use Craft;
use craft\queue\BaseJob;
use yii\base\InvalidConfigException;
use zemis\datebook\Datebook;

/**
 * Publishes every scheduled draft that is due.
 *
 * The job is safe to run any number of times. It is pushed with a delay of at most
 * 15 minutes. While drafts are still scheduled, it pushes the next job when it is
 * done, so later drafts are reached in steps.
 */
class PublishDrafts extends BaseJob
{
    public function execute($queue): void
    {
        // A job can still wait in the queue after Datebook was disabled or uninstalled.
        $plugin = Datebook::getInstance();
        if ($plugin === null) {
            return;
        }

        $schedules = $plugin->schedules;
        try {
            $schedules->publishDue();
        } finally {
            $schedules->queueNextCheck();
        }
    }

    protected function defaultDescription(): ?string
    {
        try {
            return Craft::t('datebook', 'Publishing scheduled drafts');
        } catch (InvalidConfigException) {
            // Craft reads the description while it runs the job, also after Datebook was
            // disabled or uninstalled. Its translations are not loaded then.
            return 'Publishing scheduled drafts';
        }
    }
}
