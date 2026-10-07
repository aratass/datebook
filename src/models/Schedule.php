<?php

namespace zemis\datebook\models;

use craft\helpers\DateTimeHelper;
use DateTime;
use zemis\datebook\records\ScheduleRecord;

/**
 * A scheduled draft.
 */
final class Schedule
{
    public function __construct(
        public int $id,
        public int $draftId,
        public int $canonicalId,
        public int $siteId,
        public ?int $userId,
        public DateTime $publishAt,
        public string $status,
        public ?string $error = null,
        public int $attempts = 0,
    ) {
    }

    public static function fromRecord(ScheduleRecord $record): self
    {
        $publishAt = DateTimeHelper::toDateTime($record->publishAt);
        if ($publishAt === false) {
            throw new \UnexpectedValueException("Schedule $record->id has an invalid publish date.");
        }

        return new self(
            id: (int)$record->id,
            draftId: (int)$record->draftId,
            canonicalId: (int)$record->canonicalId,
            siteId: (int)$record->siteId,
            userId: $record->userId !== null ? (int)$record->userId : null,
            publishAt: $publishAt,
            status: (string)$record->status,
            error: $record->error,
            attempts: (int)$record->attempts,
        );
    }

    public function isFailed(): bool
    {
        return $this->status === ScheduleRecord::STATUS_FAILED;
    }
}
