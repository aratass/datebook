<?php

namespace zemis\datebook\services;

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use DateTime;
use DateTimeZone;
use Throwable;
use yii\base\Component;
use zemis\datebook\helpers\Drafts;
use zemis\datebook\models\Schedule;

/**
 * Email notices about scheduled drafts.
 *
 * The texts are system messages, so they can be edited in
 * Utilities → System Messages.
 */
class Notifications extends Component
{
    public const KEY_PUBLISHED = 'datebook_draft_published';
    public const KEY_FAILED = 'datebook_draft_failed';

    /**
     * @return array<array{key: string, heading: string, subject: string, body: string}>
     */
    public static function defaultMessages(): array
    {
        return [
            [
                'key' => self::KEY_PUBLISHED,
                'heading' => Craft::t('datebook', 'When a scheduled draft is published'),
                'subject' => 'Published: {{ entryTitle }}',
                'body' => "Hi {{ user.friendlyName|e }},\n\n" .
                    "The draft \"{{ draftName|e }}\" of \"{{ entryTitle|e }}\" was published as scheduled on {{ publishedAt }}.\n\n" .
                    "Open the entry: {{ entryUrl }}",
            ],
            [
                'key' => self::KEY_FAILED,
                'heading' => Craft::t('datebook', 'When a scheduled draft cannot be published'),
                'subject' => 'Not published: {{ entryTitle }}',
                'body' => "Hi {{ user.friendlyName|e }},\n\n" .
                    "The draft \"{{ draftName|e }}\" of \"{{ entryTitle|e }}\" was scheduled for {{ scheduledFor }}, but it could not be published.\n\n" .
                    "Reason: {{ error|e }}\n\n" .
                    "Open the draft, fix the problem and schedule it again: {{ draftUrl }}",
            ],
        ];
    }

    /**
     * @param int[] $userIds
     */
    public function sendPublished(Entry $entry, string $draftName, array $userIds): int
    {
        return $this->send(self::KEY_PUBLISHED, $userIds, fn(User $user) => [
            'entryTitle' => Calendar::titleFor($entry),
            'draftName' => $draftName,
            'entryUrl' => $entry->getCpEditUrl(),
            'publishedAt' => $this->formatDate(DateTimeHelper::now(), $user),
        ]);
    }

    /**
     * @param int[] $userIds
     */
    public function sendFailed(Entry $draft, Schedule $schedule, string $error, array $userIds): int
    {
        return $this->send(self::KEY_FAILED, $userIds, fn(User $user) => [
            'entryTitle' => Calendar::titleFor($draft),
            'draftName' => Drafts::name($draft),
            'draftUrl' => $draft->getCpEditUrl(),
            'scheduledFor' => $this->formatDate($schedule->publishAt, $user),
            'error' => $error,
        ]);
    }

    /**
     * Sends a message to each active user once. Mail errors are logged, never thrown,
     * so a broken mail setup cannot stop drafts from being published.
     *
     * @param int[] $userIds
     * @param callable(User): array<string, mixed> $variables
     * @return int Number of messages sent
     */
    private function send(string $key, array $userIds, callable $variables): int
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (!$userIds) {
            return 0;
        }

        $sent = 0;
        $users = User::find()->id($userIds)->status(User::STATUS_ACTIVE)->all();

        foreach ($users as $user) {
            if (!$user->email) {
                continue;
            }

            try {
                $message = Craft::$app->getMailer()
                    ->composeFromKey($key, ['user' => $user] + $variables($user))
                    ->setTo($user);

                if ($message->send()) {
                    $sent++;
                }
            } catch (Throwable $e) {
                Craft::warning("Could not send the $key email to user $user->id: {$e->getMessage()}", __METHOD__);
            }
        }

        return $sent;
    }

    private function formatDate(DateTime $date, User $user): string
    {
        $timeZoneName = $user->getPreference('timeZone') ?: Craft::$app->getTimeZone();

        try {
            $timeZone = new DateTimeZone($timeZoneName);
        } catch (Throwable) {
            $timeZone = new DateTimeZone(Craft::$app->getTimeZone());
        }

        $local = clone $date;
        $local->setTimezone($timeZone);

        return $local->format('Y-m-d H:i') . ' (' . $timeZone->getName() . ')';
    }
}
