<?php

namespace zemis\datebook\records;

use craft\db\ActiveRecord;

/**
 * A draft that should be applied to its live entry at a set time.
 *
 * @property int $id
 * @property int $draftId Element ID of the draft
 * @property int $canonicalId Element ID of the live entry
 * @property int $siteId Site the draft was scheduled from
 * @property int|null $userId User who scheduled the draft
 * @property string $publishAt When to publish (UTC)
 * @property string $status `pending` or `failed`
 * @property string|null $error Why the last attempt failed
 * @property int $attempts How many times publishing was tried
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class ScheduleRecord extends ActiveRecord
{
    public const TABLE = '{{%datebook_schedules}}';

    public const STATUS_PENDING = 'pending';
    public const STATUS_FAILED = 'failed';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
