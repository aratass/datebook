<?php

namespace zemis\datebook\tests\Support;

use craft\queue\BaseJob;

/**
 * Stands in for the other work in Craft's queue, like updating search indexes or
 * resaving entries. It is pushed with Craft's default priority.
 */
final class OtherJob extends BaseJob
{
    public function execute($queue): void
    {
    }

    protected function defaultDescription(): ?string
    {
        return 'Other work';
    }
}
