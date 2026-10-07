<?php

namespace zemis\datebook\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use DateTimeZone;
use yii\console\ExitCode;
use zemis\datebook\Datebook;

/**
 * Publishes and lists scheduled drafts.
 *
 * Add `php craft datebook/drafts/publish` to cron (every minute) to publish
 * drafts on time, even when nobody is using the control panel.
 */
class DraftsController extends Controller
{
    public $defaultAction = 'publish';

    /**
     * Publishes every scheduled draft that is due.
     */
    public function actionPublish(): int
    {
        $counts = Datebook::getInstance()->schedules->publishDue();

        $this->line(sprintf(
            'Published: %d. Failed: %d. Waiting for a lock: %d. Missing: %d. Changed meanwhile: %d.',
            $counts['published'],
            $counts['failed'],
            $counts['retry'],
            $counts['missing'],
            $counts['skipped'],
        ));

        if ($counts['failed'] > 0) {
            $this->line('Failed drafts are marked on the calendar with the reason.', Console::FG_YELLOW);
        }

        return ExitCode::OK;
    }

    /**
     * Lists all scheduled drafts.
     */
    public function actionList(): int
    {
        $schedules = Datebook::getInstance()->schedules->getAllSchedules();
        if (!$schedules) {
            $this->line('No drafts are scheduled.');
            return ExitCode::OK;
        }

        $timeZone = new DateTimeZone(Craft::$app->getTimeZone());
        foreach ($schedules as $schedule) {
            $date = clone $schedule->publishAt;
            $date->setTimezone($timeZone);
            $line = sprintf(
                'Draft %d of entry %d: %s at %s',
                $schedule->draftId,
                $schedule->canonicalId,
                $schedule->status,
                $date->format('Y-m-d H:i T'),
            );
            if ($schedule->error) {
                $line .= ' (' . $schedule->error . ')';
            }
            $this->line($line, $schedule->isFailed() ? Console::FG_RED : Console::FG_GREEN);
        }

        return ExitCode::OK;
    }

    /**
     * Writes one line to STDOUT. Colors are only used when they are enabled, and
     * the terminal is not probed when they were turned off.
     */
    private function line(string $text, int ...$format): void
    {
        if ($format && $this->isColorEnabled()) {
            $text = Console::ansiFormat($text, $format);
        }

        fwrite(STDOUT, $text . PHP_EOL);
    }
}
