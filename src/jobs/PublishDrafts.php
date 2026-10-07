<?php

namespace zemis\datebook\jobs;

use Craft;
use craft\queue\BaseJob;
use zemis\datebook\Datebook;

/**
 * Publishes every scheduled draft that is due.
 *
 * The job is safe to run any number of times. When a draft is scheduled, a copy
 * of this job is pushed with a delay so it becomes available at the right time.
 */
class PublishDrafts extends BaseJob
{
    public function execute($queue): void
    {
        Datebook::getInstance()->schedules->publishDue();
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('datebook', 'Publishing scheduled drafts');
    }
}
