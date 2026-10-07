<?php

namespace zemis\datebook\tests\Support;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\queue\jobs\Proxy;
use RuntimeException;
use yii\queue\Queue;

/**
 * Stands in for the queue on Craft Cloud. Craft passes every job on to it as a
 * proxy queue, and it sends them to Amazon SQS, which only accepts delays from
 * 0 to 900 seconds. Set $broken to make every push fail.
 */
final class SqsLikeQueue extends Queue
{
    /** @var int[] Delays of the Datebook jobs that were accepted */
    public array $delays = [];

    public bool $broken = false;

    protected function pushMessage($message, $ttr, $delay, $priority)
    {
        if ($this->broken) {
            throw new RuntimeException('The queue service is not available.');
        }

        if ($delay > 900) {
            throw new RuntimeException("Value $delay for parameter DelaySeconds is invalid. Reason: must be between 0 and 900, if provided.");
        }

        // Craft sends the ID of the job it saved. Only keep track of Datebook's jobs.
        $job = $this->serializer->unserialize($message);
        $description = $job instanceof Proxy
            ? (new Query())->select(['description'])->from(Table::QUEUE)->where(['id' => $job->jobId])->scalar()
            : null;
        if ($description === 'Publishing scheduled drafts') {
            $this->delays[] = (int)$delay;
        }

        return uniqid('', true);
    }

    public function status($id)
    {
        return self::STATUS_WAITING;
    }

    /**
     * Runs the callback with this queue behind Craft's queue, as on Craft Cloud.
     */
    public function use(callable $callback): mixed
    {
        $queue = Craft::$app->getQueue();
        if (!$queue instanceof \craft\queue\Queue) {
            throw new RuntimeException('The tests need the database queue that Craft uses by default.');
        }

        $original = $queue->proxyQueue;
        $queue->proxyQueue = $this;
        try {
            return $callback();
        } finally {
            $queue->proxyQueue = $original;
        }
    }
}
