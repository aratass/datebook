<?php

namespace zemis\datebook\helpers;

use Craft;
use craft\elements\User;
use DateInterval;
use DateTime;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Date math for the calendar. All functions are time zone aware and never
 * change the dates that are passed in.
 */
final class Dates
{
    public const VIEW_MONTH = 'month';
    public const VIEW_WEEK = 'week';
    public const VIEW_LIST = 'list';
    public const VIEWS = [self::VIEW_MONTH, self::VIEW_WEEK, self::VIEW_LIST];

    /**
     * The day a week starts on for the user (0 = Sunday, 1 = Monday, ...).
     */
    public static function weekStartDay(?User $user = null): int
    {
        $day = $user?->getPreference('weekStartDay');
        if ($day === null || $day === '') {
            $day = Craft::$app->getConfig()->getGeneral()->defaultWeekStartDay;
        }

        return max(0, min(6, (int)$day));
    }

    /**
     * Parses a `Y-m-d` string as midnight in the time zone. Falls back to today.
     */
    public static function parseDay(?string $value, DateTimeZone $timeZone): DateTime
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $date = DateTime::createFromFormat('!Y-m-d', $value, $timeZone);
            if ($date !== false && $date->format('Y-m-d') === $value) {
                return $date;
            }
        }

        return self::startOfDay(new DateTime('now', $timeZone));
    }

    /**
     * Parses a local date and time (`Y-m-d\TH:i`, `Y-m-d H:i` or with seconds) in the time zone.
     */
    public static function parseLocalDateTime(?string $value, DateTimeZone $timeZone): ?DateTime
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim(str_replace('T', ' ', $value));
        foreach (['!Y-m-d H:i:s', '!Y-m-d H:i'] as $format) {
            $date = DateTime::createFromFormat($format, $value, $timeZone);
            $errors = DateTime::getLastErrors();
            if ($date !== false && (!$errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date;
            }
        }

        return null;
    }

    public static function startOfDay(DateTime $date): DateTime
    {
        $day = clone $date;
        $day->setTime(0, 0);

        return $day;
    }

    /**
     * Adds whole days and keeps the wall clock time, so DST changes do not shift it.
     */
    public static function addDays(DateTime $date, int $days): DateTime
    {
        $result = clone $date;
        $result->modify(($days >= 0 ? '+' : '') . $days . ' days');

        return $result;
    }

    /**
     * The first day of the week that contains the date.
     */
    public static function startOfWeek(DateTime $date, int $weekStartDay): DateTime
    {
        $day = self::startOfDay($date);
        $diff = ((int)$day->format('w') - $weekStartDay + 7) % 7;

        return self::addDays($day, -$diff);
    }

    /**
     * The visible range for a view: [start, end) in the date's time zone.
     *
     * @return array{0: DateTime, 1: DateTime}
     */
    public static function range(string $view, DateTime $anchor, int $weekStartDay): array
    {
        switch ($view) {
            case self::VIEW_WEEK:
                $start = self::startOfWeek($anchor, $weekStartDay);
                return [$start, self::addDays($start, 7)];
            case self::VIEW_LIST:
                $start = self::startOfDay($anchor);
                $start->setDate((int)$start->format('Y'), (int)$start->format('n'), 1);
                $end = clone $start;
                $end->modify('first day of next month');
                return [$start, $end];
            case self::VIEW_MONTH:
                $first = self::startOfDay($anchor);
                $first->setDate((int)$first->format('Y'), (int)$first->format('n'), 1);
                $start = self::startOfWeek($first, $weekStartDay);
                $nextMonth = clone $first;
                $nextMonth->modify('first day of next month');
                $end = self::startOfWeek($nextMonth, $weekStartDay);
                if ($end < $nextMonth) {
                    $end = self::addDays($end, 7);
                }
                return [$start, $end];
            default:
                throw new InvalidArgumentException("Unknown view: $view");
        }
    }

    /**
     * The anchor date for the previous or next page of a view.
     */
    public static function shift(string $view, DateTime $anchor, int $direction): DateTime
    {
        $date = self::startOfDay($anchor);
        if ($view === self::VIEW_WEEK) {
            return self::addDays($date, 7 * $direction);
        }

        $date->setDate((int)$date->format('Y'), (int)$date->format('n'), 1);
        $date->modify(($direction >= 0 ? '+' : '-') . abs($direction) . ' month');

        return $date;
    }

    /** Upper limit for [[days()]]: a month grid has at most 42 days. */
    public const MAX_DAYS = 62;

    /**
     * Every day in [start, end), as `Y-m-d` strings. Never more than [[MAX_DAYS]].
     *
     * @return string[]
     */
    public static function days(DateTime $start, DateTime $end): array
    {
        $days = [];
        for ($day = self::startOfDay($start); $day < $end && count($days) < self::MAX_DAYS; $day = self::addDays($day, 1)) {
            $days[] = $day->format('Y-m-d');
        }

        return $days;
    }

    /**
     * Moves a date to another day and keeps its wall clock time in the time zone.
     *
     * If that time does not exist on the new day (a DST gap), PHP moves it
     * forward by the size of the gap, which is what a person would expect.
     */
    public static function moveToDay(DateTime $date, string $day, DateTimeZone $timeZone): DateTime
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            throw new InvalidArgumentException('The day must use the Y-m-d format.');
        }

        $local = clone $date;
        $local->setTimezone($timeZone);
        $moved = DateTime::createFromFormat('!Y-m-d H:i:s', $day . ' ' . $local->format('H:i:s'), $timeZone);
        if ($moved === false || substr($moved->format('Y-m-d'), 0, 10) !== $day) {
            throw new InvalidArgumentException("Invalid day: $day");
        }

        return $moved;
    }

    /**
     * Converts a date to UTC and returns a copy.
     */
    public static function toUtc(DateTime $date): DateTime
    {
        $utc = clone $date;
        $utc->setTimezone(new DateTimeZone('UTC'));

        return $utc;
    }

    public static function addMinutes(DateTime $date, int $minutes): DateTime
    {
        $result = clone $date;
        $result->add(new DateInterval('PT' . max(0, $minutes) . 'M'));

        return $result;
    }
}
