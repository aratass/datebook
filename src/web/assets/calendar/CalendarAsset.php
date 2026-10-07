<?php

namespace zemis\datebook\web\assets\calendar;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;
use craft\web\View;

/**
 * Scripts and styles for the calendar, the widget and the draft sidebar panel.
 */
class CalendarAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CpAsset::class];
        $this->js = ['calendar.js'];
        $this->css = ['calendar.css'];

        parent::init();
    }

    public function registerAssetFiles($view): void
    {
        parent::registerAssetFiles($view);

        if ($view instanceof View) {
            $view->registerTranslations('datebook', [
                'Anyone with this link can see your calendar. Reset it if you shared it by mistake.',
                'Author',
                'Copied.',
                'Copy',
                'Could not load your calendar link.',
                'Could not reschedule this item.',
                'Could not reset your calendar link.',
                'Could not save the schedule.',
                'Date and time',
                'Enter a date and time.',
                'Open',
                'Paste this link into Google Calendar, Outlook or Apple Calendar as a calendar subscription.',
                'Reset link',
                'Reset your calendar link? The old link stops working right away.',
                'Save',
                'Schedule',
                'Section',
                'Show less',
                'Status',
                'This entry is live. Moving its post date into the future hides it until then. Continue?',
                'This moves the post date into the past, so the entry goes live now if it is enabled. Continue?',
                'Time zone: {timeZone}',
                'Update schedule',
                'Your private calendar link',
                '{num} more',
            ]);
        }
    }
}
