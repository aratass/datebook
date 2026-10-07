<?php

namespace zemis\datebook\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;

/**
 * Plugin settings.
 *
 * Every setting can also be set in `config/datebook.php`.
 */
class Settings extends Model
{
    /**
     * @var string|string[] Section UIDs to show on the calendar, or `*` for every
     * channel and structure section.
     */
    public string|array $sections = '*';

    /** @var string The view that opens first: `month`, `week` or `list`. */
    public string $defaultView = 'month';

    /** @var bool Whether expiry dates are shown by default. */
    public bool $showExpiryDates = true;

    /** @var bool Whether users may create private calendar feed links. */
    public bool $enableFeeds = true;

    /** @var int How many days back the calendar feed reaches. */
    public int $feedPastDays = 30;

    /** @var int How many days ahead the calendar feed reaches. */
    public int $feedFutureDays = 180;

    /**
     * @var string Where feed links start, for example `https://cms.example.com`. Leave empty
     * to use the primary site's URL, or the control panel's address in headless mode.
     * Environment variables and aliases work.
     */
    public string $feedBaseUrl = '';

    /** @var bool Email the scheduler and the draft creator when a scheduled draft is published. */
    public bool $notifyOnPublish = false;

    /** @var bool Email the scheduler and the draft creator when a scheduled draft fails. */
    public bool $notifyOnFailure = true;

    /**
     * Returns the selected section UIDs, or `null` when all sections are allowed.
     *
     * @return string[]|null
     */
    public function getSectionUids(): ?array
    {
        if ($this->sections === '*' || $this->sections === '' || $this->sections === []) {
            return null;
        }

        return array_values(array_filter((array)$this->sections, fn($uid) => $uid !== ''));
    }

    /**
     * @return array<int, array<int|string, mixed>>
     */
    protected function defineRules(): array
    {
        return [
            [['defaultView'], 'in', 'range' => ['month', 'week', 'list']],
            [['feedPastDays'], 'integer', 'min' => 0, 'max' => 366],
            [['feedFutureDays'], 'integer', 'min' => 1, 'max' => 731],
            [['showExpiryDates', 'enableFeeds', 'notifyOnPublish', 'notifyOnFailure'], 'boolean'],
            [['sections'], 'validateSections'],
            [['feedBaseUrl'], 'trim'],
            [['feedBaseUrl'], 'validateFeedBaseUrl'],
        ];
    }

    public function validateFeedBaseUrl(string $attribute): void
    {
        $value = $this->$attribute;
        if ($value === '') {
            return;
        }

        $url = trim((string)App::parseEnv($value));
        if (!preg_match('#^https?://[^/\s]+#i', $url)) {
            $this->addError($attribute, Craft::t('datebook', 'Enter a full address that starts with https:// or http://.'));
        }
    }

    public function validateSections(string $attribute): void
    {
        $value = $this->$attribute;
        if ($value === '*' || $value === '') {
            return;
        }
        if (!is_array($value)) {
            $this->addError($attribute, 'Sections must be "*" or a list of section UIDs.');
            return;
        }
        foreach ($value as $uid) {
            if (!is_string($uid)) {
                $this->addError($attribute, 'Sections must be "*" or a list of section UIDs.');
                return;
            }
        }
    }
}
