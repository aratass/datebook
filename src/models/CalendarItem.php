<?php

namespace zemis\datebook\models;

use DateTime;
use DateTimeInterface;
use DateTimeZone;

/**
 * One thing on the calendar: an entry going live, an entry expiring,
 * or a draft that is scheduled to be published.
 */
final class CalendarItem
{
    public const KIND_POST = 'post';
    public const KIND_EXPIRY = 'expiry';
    public const KIND_DRAFT = 'draft';

    public const STATUS_LIVE = 'live';
    public const STATUS_PENDING = 'pending';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_FAILED = 'failed';

    public function __construct(
        public string $kind,
        public int $elementId,
        public int $canonicalId,
        public int $siteId,
        public string $title,
        public string $status,
        public DateTime $date,
        public ?int $sectionId = null,
        public string $sectionName = '',
        public ?string $cpEditUrl = null,
        public ?string $authorName = null,
        public bool $canReschedule = false,
        public ?string $draftName = null,
        public ?string $error = null,
    ) {
    }

    /**
     * A stable key that identifies the item on the page and in feeds.
     */
    public function getKey(): string
    {
        return sprintf('%s-%d-%d', $this->kind, $this->elementId, $this->siteId);
    }

    /**
     * The label shown before the title, for example "Expires".
     */
    public function getKindLabel(): string
    {
        return match ($this->kind) {
            self::KIND_EXPIRY => \Craft::t('datebook', 'Expires'),
            self::KIND_DRAFT => \Craft::t('datebook', 'Draft goes live'),
            default => \Craft::t('datebook', 'Goes live'),
        };
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_LIVE => \Craft::t('app', 'Live'),
            self::STATUS_PENDING => \Craft::t('app', 'Pending'),
            self::STATUS_EXPIRED => \Craft::t('app', 'Expired'),
            self::STATUS_DISABLED => \Craft::t('app', 'Disabled'),
            self::STATUS_SCHEDULED => \Craft::t('datebook', 'Scheduled'),
            self::STATUS_FAILED => \Craft::t('datebook', 'Failed'),
            default => $this->status,
        };
    }

    /**
     * The date in the given time zone. The stored date is never changed.
     */
    public function getLocalDate(DateTimeZone $timeZone): DateTime
    {
        $date = clone $this->date;
        $date->setTimezone($timeZone);

        return $date;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(DateTimeZone $timeZone): array
    {
        $local = $this->getLocalDate($timeZone);

        return [
            'key' => $this->getKey(),
            'kind' => $this->kind,
            'kindLabel' => $this->getKindLabel(),
            'elementId' => $this->elementId,
            'canonicalId' => $this->canonicalId,
            'siteId' => $this->siteId,
            'title' => $this->title,
            'draftName' => $this->draftName,
            'status' => $this->status,
            'statusLabel' => $this->getStatusLabel(),
            'date' => $this->date->format(DateTimeInterface::ATOM),
            'localDate' => $local->format('Y-m-d'),
            'localTime' => $local->format('H:i'),
            'sectionId' => $this->sectionId,
            'sectionName' => $this->sectionName,
            'cpEditUrl' => $this->cpEditUrl,
            'authorName' => $this->authorName,
            'canReschedule' => $this->canReschedule,
            'error' => $this->error,
        ];
    }
}
