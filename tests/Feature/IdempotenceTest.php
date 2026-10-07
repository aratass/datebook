<?php

use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use zemis\datebook\records\ScheduleRecord;
use zemis\datebook\tests\Support\Fixtures;

beforeAll(fn() => Fixtures::boot());

it('does nothing the second time a due draft is published', function() {
    [$entry, $draft] = Fixtures::scheduledDraft(['title' => 'Once']);
    Fixtures::ageRevisions($entry);
    $schedules = Fixtures::plugin()->schedules;

    $first = $schedules->publishDue(new DateTime('+3 hours'));
    $revisions = (new Query())->from(Table::REVISIONS)->where(['canonicalId' => $entry->id])->count();

    $second = $schedules->publishDue(new DateTime('+3 hours'));

    expect($first['published'])->toBe(1)
        ->and($second)->toBe(['published' => 0, 'failed' => 0, 'retry' => 0, 'missing' => 0, 'skipped' => 0])
        ->and((new Query())->from(Table::REVISIONS)->where(['canonicalId' => $entry->id])->count())->toBe($revisions)
        ->and(Entry::find()->id($entry->id)->status(null)->one()->title)->toBe('Once')
        ->and(Entry::find()->drafts()->id($draft->id)->status(null)->exists())->toBeFalse();
});

it('keeps one schedule per draft when it is scheduled again', function() {
    [, $draft, $first, $editor] = Fixtures::scheduledDraft([], '+2 hours');

    $second = Fixtures::plugin()->schedules->schedule($draft, new DateTime('+5 hours'), $editor);

    expect($second->id)->toBe($first->id)
        ->and(ScheduleRecord::find()->where(['draftId' => $draft->id])->count())->toBe(1)
        ->and($second->publishAt->getTimestamp())->toBeGreaterThan($first->publishAt->getTimestamp());
});

it('resets a failed schedule when the draft is scheduled again', function() {
    [, $draft, , $editor] = Fixtures::scheduledDraft();
    ScheduleRecord::updateAll(['status' => ScheduleRecord::STATUS_FAILED, 'error' => 'Broken', 'attempts' => 3], ['draftId' => $draft->id]);

    $schedule = Fixtures::plugin()->schedules->schedule($draft, new DateTime('+4 hours'), $editor);

    expect($schedule->status)->toBe(ScheduleRecord::STATUS_PENDING)
        ->and($schedule->error)->toBeNull()
        ->and($schedule->attempts)->toBe(0);
});

it('does not publish while another process holds the publish lock', function() {
    [$entry] = Fixtures::scheduledDraft(['title' => 'Locked out']);
    $mutex = Craft::$app->getMutex();
    expect($mutex->acquire('datebook:publishDue'))->toBeTrue();

    try {
        $counts = Fixtures::plugin()->schedules->publishDue(new DateTime('+3 hours'));
    } finally {
        $mutex->release('datebook:publishDue');
    }

    expect($counts['published'])->toBe(0)
        ->and(Entry::find()->id($entry->id)->status(null)->one()->title)->toBe('Old title');

    // Once the lock is free the draft goes out.
    expect(Fixtures::plugin()->schedules->publishDue(new DateTime('+3 hours'))['published'])->toBe(1);
});

it('retries later when the entry itself is locked', function() {
    [$entry, $draft] = Fixtures::scheduledDraft(['title' => 'Entry locked']);
    $mutex = Craft::$app->getMutex();
    expect($mutex->acquire("element:$entry->id"))->toBeTrue();

    try {
        $counts = Fixtures::plugin()->schedules->publishDue(new DateTime('+3 hours'));
    } finally {
        $mutex->release("element:$entry->id");
    }

    $record = ScheduleRecord::findOne(['draftId' => $draft->id]);
    expect($counts['retry'])->toBe(1)
        ->and($record->status)->toBe(ScheduleRecord::STATUS_PENDING)
        ->and((int)$record->attempts)->toBe(1)
        ->and(Entry::find()->id($entry->id)->status(null)->one()->title)->toBe('Old title');
});

it('drops schedules whose draft disappeared', function() {
    [, $draft, $schedule] = Fixtures::scheduledDraft();
    $schedules = Fixtures::plugin()->schedules;

    // Simulate a database that lost its foreign keys: the draft is gone but the row stayed.
    Fixtures::withoutForeignKeys(function() use ($draft) {
        Craft::$app->getDb()->createCommand()->delete(Table::ELEMENTS, ['id' => $draft->id])->execute();
    });
    expect(ScheduleRecord::find()->where(['id' => $schedule->id])->exists())->toBeTrue();

    expect($schedules->deleteOrphans())->toBe(1)
        ->and(ScheduleRecord::find()->where(['id' => $schedule->id])->exists())->toBeFalse();
})->skip(fn() => !Craft::$app->getDb()->getIsMysql(), 'Uses a MySQL specific statement.');

it('removes the schedule when the live entry is deleted', function() {
    [$entry, $draft] = Fixtures::scheduledDraft();

    Craft::$app->getElements()->deleteElement($entry, true);

    expect(ScheduleRecord::find()->where(['draftId' => $draft->id])->exists())->toBeFalse();
})->skip(fn() => Craft::$app->getDb()->getIsPgsql(), 'Craft cannot hard-delete entries with revisions inside a test transaction on PostgreSQL.');

it('does not publish a draft whose live entry is in the trash', function() {
    [$entry, $draft] = Fixtures::scheduledDraft(['title' => 'Trashed']);

    Craft::$app->getElements()->deleteElement($entry);
    $counts = Fixtures::plugin()->schedules->publishDue(new DateTime('+3 hours'));

    $trashed = Entry::find()->id($entry->id)->status(null)->trashed()->one();
    expect($counts['published'])->toBe(0)
        ->and($trashed)->not->toBeNull()
        ->and($trashed->title)->toBe('Old title')
        ->and(ScheduleRecord::find()->where(['draftId' => $draft->id, 'status' => ScheduleRecord::STATUS_PENDING])->exists())->toBeFalse();
});

it('reports a missing draft and cleans up its schedule', function() {
    [, $draft, $schedule] = Fixtures::scheduledDraft();
    Fixtures::withoutForeignKeys(function() use ($draft) {
        Craft::$app->getDb()->createCommand()->delete(Table::ELEMENTS, ['id' => $draft->id])->execute();
    });
    expect(ScheduleRecord::find()->where(['id' => $schedule->id])->exists())->toBeTrue();

    $counts = Fixtures::plugin()->schedules->publishDue(new DateTime('+3 hours'));

    expect($counts['missing'])->toBe(1)
        ->and(ScheduleRecord::find()->where(['id' => $schedule->id])->exists())->toBeFalse();
})->skip(fn() => !Craft::$app->getDb()->getIsMysql(), 'Uses a MySQL specific statement.');
