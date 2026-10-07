<?php

use craft\base\Element;
use craft\elements\Entry;
use zemis\datebook\records\ScheduleRecord;
use zemis\datebook\tests\Support\Fixtures;

beforeAll(fn() => Fixtures::boot());

it('does not publish a draft that is invalid in another site', function() {
    [$entry, $draft] = Fixtures::scheduledDraft(['title' => 'Valid here']);
    $second = Fixtures::secondSite();

    // Titles are translated per site. Empty the title of the draft in the second site only,
    // the way a draft autosave stores it: with the essentials scenario, so nothing is filled in.
    $secondDraft = Entry::find()->drafts()->id($draft->id)->siteId($second->id)->status(null)->one();
    expect($secondDraft)->not->toBeNull();
    $secondDraft->setScenario(Element::SCENARIO_ESSENTIALS);
    $secondDraft->title = '';
    Craft::$app->getElements()->saveElement($secondDraft, false, false);
    expect(Entry::find()->drafts()->id($draft->id)->siteId($second->id)->status(null)->one()->title)->toBeEmpty();

    $counts = Fixtures::plugin()->schedules->publishDue(new DateTime('+3 hours'));
    $record = ScheduleRecord::findOne(['draftId' => $draft->id]);

    expect($counts['failed'])->toBe(1)
        ->and($record->status)->toBe(ScheduleRecord::STATUS_FAILED)
        ->and($record->error)->toBe('Second site: Title cannot be blank.')
        ->and(Entry::find()->id($entry->id)->siteId($second->id)->status(null)->one()->title)->toBe('Old title')
        ->and(Entry::find()->id($entry->id)->status(null)->one()->title)->toBe('Old title');
});

it('publishes the changes made to a draft in the second site', function() {
    $editor = Fixtures::user('editor', Fixtures::editorPermissions());
    $entry = Fixtures::entry('news', ['title' => 'Old title', 'author' => $editor]);
    $second = Fixtures::secondSite();
    $draft = Fixtures::draft($entry, $editor, ['title' => 'New primary title']);

    $secondDraft = Entry::find()->drafts()->id($draft->id)->siteId($second->id)->status(null)->one();
    $secondDraft->title = 'Neuer Titel';
    expect(Craft::$app->getElements()->saveElement($secondDraft))->toBeTrue();

    // Scheduled from the second site.
    Fixtures::plugin()->schedules->schedule($secondDraft, new DateTime('+2 hours'), $editor);
    expect(ScheduleRecord::findOne(['draftId' => $draft->id])->siteId)->toBe((int)$second->id);

    $counts = Fixtures::plugin()->schedules->publishDue(new DateTime('+3 hours'));

    expect($counts['published'])->toBe(1)
        ->and(Entry::find()->id($entry->id)->siteId($second->id)->status(null)->one()->title)->toBe('Neuer Titel')
        ->and(Entry::find()->id($entry->id)->status(null)->one()->title)->toBe('New primary title');
});

it('refuses to move items in a site the user may not edit', function() {
    $permissions = array_values(array_diff(Fixtures::editorPermissions(), ['editSite:{' . Fixtures::SECOND_SITE . '}']));
    $editor = Fixtures::user('editor', $permissions);
    $postDate = new DateTime('2026-10-14 09:30:00', new DateTimeZone('UTC'));
    $entry = Fixtures::entry('news', ['postDate' => $postDate]);
    $second = Fixtures::secondSite();

    $this->withExceptionHandling()
        ->actingAs($editor)
        ->postJson('/admin/actions/datebook/calendar/reschedule', [
            'kind' => 'post',
            'elementId' => $entry->id,
            'siteId' => $second->id,
            'day' => '2026-10-16',
        ])
        ->assertForbidden();

    $fresh = Entry::find()->id($entry->id)->status(null)->one();
    expect($fresh->postDate->getTimestamp())->toBe($postDate->getTimestamp());

    // The same move in a site the editor may edit works.
    $this->actingAs($editor)
        ->postJson('/admin/actions/datebook/calendar/reschedule', [
            'kind' => 'post',
            'elementId' => $entry->id,
            'siteId' => $entry->siteId,
            'day' => '2026-10-16',
        ])
        ->assertOk();
});

it('refuses to schedule or unschedule a draft from a site the user may not edit', function() {
    [, $draft, , $editor] = Fixtures::scheduledDraft();
    $second = Fixtures::secondSite();
    $secondDraft = Entry::find()->drafts()->id($draft->id)->siteId($second->id)->status(null)->one();
    Fixtures::grant($editor, array_values(array_diff(Fixtures::editorPermissions(), ['editSite:{' . Fixtures::SECOND_SITE . '}'])));
    $schedules = Fixtures::plugin()->schedules;

    expect($schedules->getScheduleError($secondDraft, $editor))->toBe('You are not allowed to edit this site.')
        ->and($schedules->getScheduleError($draft, $editor))->toBeNull()
        ->and(fn() => $schedules->unschedule($secondDraft, $editor))->toThrow(\zemis\datebook\errors\ScheduleException::class);

    // Newer Craft versions already refuse to show the draft in that site (403).
    // Older ones let the request through, and Datebook refuses it (400).
    $before = $schedules->getScheduleByDraftId($draft->id)->publishAt->getTimestamp();
    $response = $this->withExceptionHandling()
        ->actingAs($editor)
        ->postJson('/admin/actions/datebook/schedules/save', [
            'draftId' => $draft->id,
            'siteId' => $second->id,
            'publishAt' => (new DateTime('+1 day'))->format('Y-m-d\TH:i'),
        ]);

    expect($response->getStatusCode())->toBeIn([400, 403])
        ->and($schedules->getScheduleByDraftId($draft->id)->publishAt->getTimestamp())->toBe($before);
});
