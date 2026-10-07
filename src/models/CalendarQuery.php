<?php

namespace zemis\datebook\models;

use DateTime;
use InvalidArgumentException;

/**
 * Describes which items to load for the calendar.
 */
final class CalendarQuery
{
    /** Statuses a user can filter by. `drafts` stands for scheduled drafts. */
    public const STATUSES = ['live', 'pending', 'expired', 'disabled', 'drafts'];

    /**
     * @param DateTime $start Start of the range (inclusive)
     * @param DateTime $end End of the range (exclusive)
     * @param int $siteId The site whose content is shown
     * @param int[]|null $sectionIds Limit to these sections (null means every allowed section)
     * @param int|null $authorId Limit to entries by this author
     * @param string[] $statuses Any of [[STATUSES]]
     * @param bool $includeExpiry Whether expiry dates are included
     */
    public function __construct(
        public DateTime $start,
        public DateTime $end,
        public int $siteId,
        public ?array $sectionIds = null,
        public ?int $authorId = null,
        public array $statuses = self::STATUSES,
        public bool $includeExpiry = true,
    ) {
        if ($end <= $start) {
            throw new InvalidArgumentException('The end of the range must be after the start.');
        }

        $this->statuses = array_values(array_intersect(self::STATUSES, $statuses));
    }

    /**
     * Entry statuses to query, without the `drafts` pseudo status.
     *
     * @return string[]
     */
    public function getEntryStatuses(): array
    {
        return array_values(array_diff($this->statuses, ['drafts']));
    }

    public function includesDrafts(): bool
    {
        return in_array('drafts', $this->statuses, true);
    }
}
