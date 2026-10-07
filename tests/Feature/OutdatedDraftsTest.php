<?php

use craft\base\Element;
use craft\db\Table;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use zemis\datebook\records\ScheduleRecord;
use zemis\datebook\tests\Support\Fixtures;

beforeAll(function() {
    Fixtures::boot();
    Fixtures::summarySection();
});

/**
 * A live entry saved before its summary became required, so the summary is empty.
 */
function datebookLegacyEntry(User $author): Entry
{
    $section = Fixtures::summarySection();
    $entry = new Entry();
    $entry->sectionId = $section->id;
    $entry->typeId = $section->getEntryTypes()[0]->id;
    $entry->title = 'Live title';
    $entry->setAuthorIds([$author->id]);
    $entry->postDate = new DateTime('-1 day');
    expect(Craft::$app->getElements()->saveElement($entry, false))->toBeTrue();

    return $entry;
}

/**
 * Makes the draft older than the last change to its live entry, so Craft sees it as outdated.
 */
function datebookOutdated(Entry $draft): Entry
{
    Db::update(Table::ELEMENTS, ['dateCreated' => Db::prepareDateForDb(new DateTime('-1 hour'))], ['id' => $draft->id]);
    $draft = Entry::find()->drafts()->id($draft->id)->status(null)->one();
    expect(ElementHelper::isOutdated($draft))->toBeTrue();

    return $draft;
}

it('brings live changes into an outdated draft before it is checked', function() {
    $editor = Fixtures::user('editor', Fixtures::editorPermissions(['features']));
    $entry = datebookLegacyEntry($editor);

    // The draft changes the title. Its summary is still empty, like the live one was.
    $draft = Fixtures::draft($entry, $editor, ['title' => 'Draft title'], 'Title change');

    // Later someone fills in the summary on the live entry.
    $live = Entry::find()->id($entry->id)->status(null)->one();
    $live->setFieldValue('datebookSummary', 'Filled on the live entry');
    expect(Craft::$app->getElements()->saveElement($live))->toBeTrue();
    $draft = datebookOutdated($draft);

    Fixtures::plugin()->schedules->schedule($draft, new DateTime('+2 hours'), $editor);
    $counts = Fixtures::plugin()->schedules->publishDue(new DateTime('+3 hours'));

    $published = Entry::find()->id($entry->id)->status(null)->one();
    expect($counts['published'])->toBe(1)
        ->and($counts['failed'])->toBe(0)
        ->and($published->title)->toBe('Draft title')
        ->and($published->getFieldValue('datebookSummary'))->toBe('Filled on the live entry');
});

it('still refuses a draft that is not valid after the live changes are in', function() {
    $editor = Fixtures::user('editor', Fixtures::editorPermissions(['features']));
    $entry = datebookLegacyEntry($editor);
    $draft = Fixtures::draft($entry, $editor, ['title' => 'Still no summary'], 'Title change');

    // The live entry changes, but its summary stays empty.
    $live = Entry::find()->id($entry->id)->status(null)->one();
    $live->slug = 'changed-on-the-live-entry';
    expect(Craft::$app->getElements()->saveElement($live, false))->toBeTrue();
    $draft = datebookOutdated($draft);

    Fixtures::plugin()->schedules->schedule($draft, new DateTime('+2 hours'), $editor);
    $counts = Fixtures::plugin()->schedules->publishDue(new DateTime('+3 hours'));

    $record = ScheduleRecord::findOne(['draftId' => $draft->id]);
    expect($counts['failed'])->toBe(1)
        ->and($record->error)->toStartWith('Summary cannot be blank.')
        ->and(Entry::find()->id($entry->id)->status(null)->one()->title)->toBe('Live title');
});

it('does not let an outdated draft without a title go live with a placeholder title', function() {
    $editor = Fixtures::user('editor', Fixtures::editorPermissions());
    $entry = Fixtures::entry('news', ['title' => 'Live title', 'author' => $editor]);
    $draft = Fixtures::draft($entry, $editor, [], 'No title yet');

    // The draft loses its title. Autosaves keep an emptied title field, because they
    // save drafts with the essentials scenario.
    $draft = Entry::find()->drafts()->id($draft->id)->status(null)->one();
    $draft->setScenario(Element::SCENARIO_ESSENTIALS);
    $draft->title = '';
    Craft::$app->getElements()->saveElement($draft);
    expect(Entry::find()->drafts()->id($draft->id)->status(null)->one()->title)->toBeEmpty();

    // Then the live entry changes, so the draft is outdated. Saving the merged draft
    // without checks would make Craft fill in a title such as "Entry 12".
    $live = Entry::find()->id($entry->id)->status(null)->one();
    $live->slug = 'changed-on-the-live-entry';
    expect(Craft::$app->getElements()->saveElement($live))->toBeTrue();
    $draft = datebookOutdated($draft);

    Fixtures::plugin()->schedules->schedule($draft, new DateTime('+2 hours'), $editor);
    $counts = Fixtures::plugin()->schedules->publishDue(new DateTime('+3 hours'));

    expect($counts['failed'])->toBe(1)
        ->and(ScheduleRecord::findOne(['draftId' => $draft->id])->error)->toStartWith('Title cannot be blank.')
        ->and(Entry::find()->id($entry->id)->status(null)->one()->title)->toBe('Live title')
        ->and((string)Entry::find()->drafts()->id($draft->id)->status(null)->one()->title)->toBe('');
});
