<?php

namespace zemis\datebook\services;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\elements\User;
use craft\models\Section;
use craft\models\Site;
use DateTime;
use DateTimeInterface;
use DateTimeZone;
use yii\base\Component;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use zemis\datebook\Datebook;
use zemis\datebook\errors\RescheduleException;
use zemis\datebook\models\CalendarItem;
use zemis\datebook\models\CalendarQuery;

/**
 * Loads calendar items and moves them to new dates.
 */
class Calendar extends Component
{
    /** Upper limit of entries loaded per date type, to keep pages fast. */
    public int $limit = 1500;

    /** Set to true by [[getItems()]] when the limit was reached. */
    public bool $limitReached = false;

    /**
     * Sections shown on the calendar for the user: the sections allowed in the
     * plugin settings that the user may view. Singles are included because their
     * drafts can be scheduled, but they never show post or expiry dates.
     *
     * @return Section[]
     */
    public function getSections(?User $user): array
    {
        $allowedUids = Datebook::getInstance()->getSettings()->getSectionUids();
        $sections = [];

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            if ($allowedUids !== null && !in_array($section->uid, $allowedUids, true)) {
                continue;
            }
            if ($user !== null && !$user->can("viewEntries:$section->uid")) {
                continue;
            }
            $sections[] = $section;
        }

        return $sections;
    }

    /**
     * Options for the section setting.
     *
     * @return array<array{label: string, value: string}>
     */
    public function getSectionOptions(): array
    {
        $options = [];
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $options[] = ['label' => Craft::t('site', (string)$section->name), 'value' => (string)$section->uid];
        }

        return $options;
    }

    /**
     * Sites the user may see on the calendar.
     *
     * @return Site[]
     */
    public function getSites(User $user): array
    {
        $sites = Craft::$app->getSites()->getAllSites();
        if (!Craft::$app->getIsMultiSite()) {
            return $sites;
        }

        return array_values(array_filter($sites, fn(Site $site) => $user->can("editSite:$site->uid")));
    }

    /**
     * Whether the user may work with content in the site. On single-site
     * installs every user may.
     */
    public function canEditSite(User $user, int $siteId): bool
    {
        $site = Craft::$app->getSites()->getSiteById($siteId, true);
        if (!$site) {
            return false;
        }

        return !Craft::$app->getIsMultiSite() || $user->can("editSite:$site->uid");
    }

    /**
     * Returns the site to show: the requested one if the user may see it,
     * otherwise the primary site or the first allowed one.
     */
    public function resolveSite(User $user, ?string $handle): ?Site
    {
        $sites = $this->getSites($user);
        if ($handle !== null && $handle !== '') {
            foreach ($sites as $site) {
                if ($site->handle === $handle) {
                    return $site;
                }
            }
        }

        foreach ($sites as $site) {
            if ($site->primary) {
                return $site;
            }
        }

        return $sites[0] ?? null;
    }

    /**
     * Authors who wrote entries in the given sections, for the author filter.
     *
     * @param int[] $sectionIds
     * @return array<int, string> User names indexed by ID
     */
    public function getAuthorOptions(array $sectionIds): array
    {
        if (!$sectionIds) {
            return [];
        }

        $authorIds = (new Query())
            ->select(['entries_authors.authorId'])
            ->distinct()
            ->from(['entries_authors' => Table::ENTRIES_AUTHORS])
            ->innerJoin(['entries' => Table::ENTRIES], '[[entries.id]] = [[entries_authors.entryId]]')
            ->where(['entries.sectionId' => $sectionIds])
            ->limit(500)
            ->column();

        if (!$authorIds) {
            return [];
        }

        $options = [];
        foreach (User::find()->id($authorIds)->status(null)->all() as $user) {
            $options[$user->id] = $user->getName();
        }
        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }

    /**
     * Returns every item the user may see in the query range, sorted by date.
     *
     * @return CalendarItem[]
     */
    public function getItems(CalendarQuery $query, User $user): array
    {
        $this->limitReached = false;
        $sections = $this->getSections($user);
        $sectionIds = array_map(fn(Section $section) => (int)$section->id, $sections);
        if ($query->sectionIds !== null) {
            $sectionIds = array_values(array_intersect($sectionIds, array_map('intval', $query->sectionIds)));
        }

        if (!$sectionIds) {
            return [];
        }

        if (!$this->canEditSite($user, $query->siteId)) {
            return [];
        }

        $datedSectionIds = [];
        foreach ($sections as $section) {
            if ($section->type !== Section::TYPE_SINGLE && in_array((int)$section->id, $sectionIds, true)) {
                $datedSectionIds[] = (int)$section->id;
            }
        }

        $items = [];

        if ($datedSectionIds && $query->getEntryStatuses()) {
            foreach ($this->findEntries($query, $datedSectionIds, 'postDate') as $entry) {
                if ($this->canView($entry, $user)) {
                    $items[] = $this->createItem($entry, CalendarItem::KIND_POST, $user);
                }
            }

            if ($query->includeExpiry) {
                foreach ($this->findEntries($query, $datedSectionIds, 'expiryDate') as $entry) {
                    if ($this->canView($entry, $user)) {
                        $items[] = $this->createItem($entry, CalendarItem::KIND_EXPIRY, $user);
                    }
                }
            }
        }

        if ($query->includesDrafts()) {
            foreach (Datebook::getInstance()->schedules->getItems($query, $sectionIds, $user) as $item) {
                $items[] = $item;
            }
        }

        usort($items, function(CalendarItem $a, CalendarItem $b) {
            return [$a->date->getTimestamp(), $a->title, $a->getKey()] <=> [$b->date->getTimestamp(), $b->title, $b->getKey()];
        });

        return $items;
    }

    /**
     * Groups items by their day in the time zone.
     *
     * @param CalendarItem[] $items
     * @return array<string, CalendarItem[]> Items indexed by `Y-m-d`
     */
    public function groupByDay(array $items, DateTimeZone $timeZone): array
    {
        $days = [];
        foreach ($items as $item) {
            $days[$item->getLocalDate($timeZone)->format('Y-m-d')][] = $item;
        }

        return $days;
    }

    /**
     * Moves an item to a new date and time.
     *
     * @throws ForbiddenHttpException if the user may not move the item
     * @throws NotFoundHttpException if the item does not exist
     * @throws RescheduleException if the new date is not valid
     */
    public function reschedule(string $kind, int $elementId, int $siteId, DateTime $date, User $user): CalendarItem
    {
        if (!$user->can(Datebook::PERMISSION_RESCHEDULE)) {
            throw new ForbiddenHttpException(Craft::t('datebook', 'You are not allowed to reschedule items.'));
        }

        if (!$this->canEditSite($user, $siteId)) {
            throw new ForbiddenHttpException(Craft::t('datebook', 'You are not allowed to edit this site.'));
        }

        if ($kind === CalendarItem::KIND_DRAFT) {
            return Datebook::getInstance()->schedules->reschedule($elementId, $siteId, $date, $user);
        }

        if (!in_array($kind, [CalendarItem::KIND_POST, CalendarItem::KIND_EXPIRY], true)) {
            throw new RescheduleException(Craft::t('datebook', 'Unknown item type.'));
        }

        $entry = Entry::find()->id($elementId)->siteId($siteId)->status(null)->one();
        if (!$entry instanceof Entry || !$entry->getIsCanonical()) {
            throw new NotFoundHttpException(Craft::t('datebook', 'The entry could not be found.'));
        }

        $section = $entry->getSection();
        $allowed = array_map(fn(Section $s) => (int)$s->id, $this->getSections($user));
        if (!$section || $section->type === Section::TYPE_SINGLE || !in_array((int)$section->id, $allowed, true)) {
            throw new ForbiddenHttpException(Craft::t('datebook', 'This entry is not on the calendar.'));
        }

        $elements = Craft::$app->getElements();
        if (!$elements->canView($entry, $user) || !$elements->canSave($entry, $user)) {
            throw new ForbiddenHttpException(Craft::t('datebook', 'You are not allowed to change this entry.'));
        }

        if ($kind === CalendarItem::KIND_POST) {
            if ($entry->expiryDate && $date >= $entry->expiryDate) {
                throw new RescheduleException(Craft::t('datebook', 'The post date must be before the expiry date.'));
            }
            $entry->postDate = clone $date;
        } else {
            if (!$entry->postDate || $date <= $entry->postDate) {
                throw new RescheduleException(Craft::t('datebook', 'The expiry date must be after the post date.'));
            }
            $entry->expiryDate = clone $date;
        }

        $entry->setRevisionNotes(Craft::t('datebook', 'Rescheduled on the Datebook calendar.'));

        if (!$elements->saveElement($entry)) {
            $errors = $entry->getFirstErrors();
            throw new RescheduleException($errors ? implode(' ', $errors) : Craft::t('datebook', 'The entry could not be saved.'));
        }

        return $this->createItem($entry, $kind, $user);
    }

    public function canReschedule(Entry $entry, User $user): bool
    {
        return $user->can(Datebook::PERMISSION_RESCHEDULE) && Craft::$app->getElements()->canSave($entry, $user);
    }

    private function canView(Entry $entry, User $user): bool
    {
        return Craft::$app->getElements()->canView($entry, $user);
    }

    /**
     * @param int[] $sectionIds
     * @return Entry[]
     */
    private function findEntries(CalendarQuery $query, array $sectionIds, string $attribute): array
    {
        $entryQuery = Entry::find()
            ->siteId($query->siteId)
            ->sectionId($sectionIds)
            ->status($query->getEntryStatuses())
            ->with(['authors'])
            ->orderBy(["entries.$attribute" => SORT_ASC, 'elements.id' => SORT_ASC])
            ->limit($this->limit + 1);

        $range = [
            'and',
            '>= ' . $query->start->format(DateTimeInterface::ATOM),
            '< ' . $query->end->format(DateTimeInterface::ATOM),
        ];

        if ($attribute === 'postDate') {
            $entryQuery->postDate($range);
        } else {
            $entryQuery->expiryDate($range);
        }

        if ($query->authorId) {
            $entryQuery->authorId($query->authorId);
        }

        $entries = $entryQuery->all();
        if (count($entries) > $this->limit) {
            $this->limitReached = true;
            $entries = array_slice($entries, 0, $this->limit);
        }

        return $entries;
    }

    private function createItem(Entry $entry, string $kind, User $user): CalendarItem
    {
        $date = $kind === CalendarItem::KIND_EXPIRY ? $entry->expiryDate : $entry->postDate;
        $date ??= $entry->postDate ?? $entry->dateCreated ?? new DateTime();
        $section = $entry->getSection();

        return new CalendarItem(
            kind: $kind,
            elementId: (int)$entry->id,
            canonicalId: (int)$entry->getCanonicalId(),
            siteId: (int)$entry->siteId,
            title: self::titleFor($entry),
            status: $entry->getStatus() ?? CalendarItem::STATUS_DISABLED,
            date: clone $date,
            sectionId: $section?->id,
            sectionName: $section ? Craft::t('site', (string)$section->name) : '',
            cpEditUrl: $entry->getCpEditUrl(),
            authorName: $entry->getAuthor()?->getName(),
            canReschedule: $this->canReschedule($entry, $user),
        );
    }

    public static function titleFor(Entry $entry): string
    {
        $title = trim((string)$entry->title);

        return $title !== '' ? $title : Craft::t('datebook', 'Untitled entry {id}', ['id' => $entry->id]);
    }
}
