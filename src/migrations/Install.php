<?php

namespace zemis\datebook\migrations;

use craft\db\Migration;
use craft\db\Table;
use zemis\datebook\records\FeedTokenRecord;
use zemis\datebook\records\ScheduleRecord;

/**
 * Creates the plugin tables.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists(ScheduleRecord::TABLE)) {
            $this->createTable(ScheduleRecord::TABLE, [
                'id' => $this->primaryKey(),
                'draftId' => $this->integer()->notNull(),
                'canonicalId' => $this->integer()->notNull(),
                'siteId' => $this->integer()->notNull(),
                'userId' => $this->integer(),
                'publishAt' => $this->dateTime()->notNull(),
                'status' => $this->string(20)->notNull()->defaultValue(ScheduleRecord::STATUS_PENDING),
                'error' => $this->text(),
                'attempts' => $this->smallInteger()->unsigned()->notNull()->defaultValue(0),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, ScheduleRecord::TABLE, ['draftId'], true);
            $this->createIndex(null, ScheduleRecord::TABLE, ['status', 'publishAt']);
            $this->createIndex(null, ScheduleRecord::TABLE, ['canonicalId']);

            // A draft that is applied or deleted takes its schedule with it.
            $this->addForeignKey(null, ScheduleRecord::TABLE, ['draftId'], Table::ELEMENTS, ['id'], 'CASCADE');
            $this->addForeignKey(null, ScheduleRecord::TABLE, ['canonicalId'], Table::ELEMENTS, ['id'], 'CASCADE');
            $this->addForeignKey(null, ScheduleRecord::TABLE, ['siteId'], Table::SITES, ['id'], 'CASCADE', 'CASCADE');
            $this->addForeignKey(null, ScheduleRecord::TABLE, ['userId'], Table::USERS, ['id'], 'SET NULL');
        }

        if (!$this->db->tableExists(FeedTokenRecord::TABLE)) {
            $this->createTable(FeedTokenRecord::TABLE, [
                'id' => $this->primaryKey(),
                'userId' => $this->integer()->notNull(),
                'tokenHash' => $this->char(64)->notNull(),
                'token' => $this->text()->notNull(),
                'lastUsedAt' => $this->dateTime(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, FeedTokenRecord::TABLE, ['userId'], true);
            $this->createIndex(null, FeedTokenRecord::TABLE, ['tokenHash'], true);
            $this->addForeignKey(null, FeedTokenRecord::TABLE, ['userId'], Table::USERS, ['id'], 'CASCADE');
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(FeedTokenRecord::TABLE);
        $this->dropTableIfExists(ScheduleRecord::TABLE);

        return true;
    }
}
