<?php

namespace zemis\datebook\records;

use craft\db\ActiveRecord;

/**
 * A private calendar feed token. Each user has at most one.
 *
 * The token is stored twice: as a SHA-256 hash for lookups, and encrypted with
 * the site's security key so the user can copy the link again later.
 *
 * @property int $id
 * @property int $userId
 * @property string $tokenHash
 * @property string $token Encrypted token (base64)
 * @property string|null $lastUsedAt
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class FeedTokenRecord extends ActiveRecord
{
    public const TABLE = '{{%datebook_feedtokens}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
