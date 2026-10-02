<?php

declare(strict_types=1);

namespace BvlionBatch5\Dating;

use BvlionBatch5\Slack\SlackClient;
use DateTimeImmutable;
use DateTimeZone;

final class DatingNotificationService
{
    public function __construct(
        private DatingRepository $datingRepository,
        private SlackClient $slackClient,
        private DateTimeZone $timezone,
    ) {
    }

    public function notify(?DateTimeImmutable $now = null): void
    {
        $today = ($now ?? new DateTimeImmutable('now', $this->timezone))
            ->setTimezone($this->timezone)
            ->setTime(0, 0);
        $messagesByChannel = [];

        foreach ($this->datingRepository->findAll() as $dating) {
            $message = null;
            $targetDateValue = $dating['target_date'];

            if (
                strlen($targetDateValue) === 4
                && $today->format('md') === $targetDateValue
            ) {
                $message = $dating['message'];
            }

            if (strlen($targetDateValue) === 8) {
                $targetDate = DateTimeImmutable::createFromFormat(
                    '!Ymd',
                    $targetDateValue,
                    $this->timezone,
                );

                if (
                    $targetDate !== false
                    && $targetDate->format('Ymd') === $targetDateValue
                ) {
                    $elapsedDays = (int) $targetDate
                        ->diff($today)
                        ->format('%r%a');

                    if (
                        $elapsedDays >= 0
                        && $elapsedDays % 100 === 0
                    ) {
                        $message = sprintf(
                            $dating['message'],
                            number_format($elapsedDays),
                        );
                    }
                }
            }

            if ($message !== null && $message !== '') {
                $messagesByChannel[$dating['channel_id']][] = $message;
            }
        }

        foreach ($messagesByChannel as $channelId => $messages) {
            $this->slackClient->postCustomMessage(
                $channelId,
                implode("\n", $messages),
                'dating-bot-' . $today->format('Ymd'),
                'https://4s.ambitious-i.net/icon/life118.png',
            );
        }
    }
}
