<?php

declare(strict_types=1);

namespace BvlionBatch5\Mail;

use BvlionBatch5\Slack\SlackClient;
use DateTimeImmutable;
use RuntimeException;

final class MailProcessingService
{
    private const PDF_FILENAME = 'mail.pdf';

    private LegacyDateFormatConverter $dateFormatConverter;

    public function __construct(
        private MailRuleRepository $mailRuleRepository,
        private ImapMailbox $mailbox,
        private MimeMessageDecoder $mimeMessageDecoder,
        private HtmlToPdfConverter $htmlToPdfConverter,
        private MailProcessingHistoryRepository $historyRepository,
        private SlackClient $slackClient,
        private string $mailboxIdentifier,
    ) {
        $this->dateFormatConverter = new LegacyDateFormatConverter();
    }

    /**
     * @return array{success: bool, failure_count: int}
     */
    public function process(): array
    {
        $rules = $this->mailRuleRepository->findEnabledRules();

        if ($rules === []) {
            return ['success' => true, 'failure_count' => 0];
        }

        $failureCount = 0;
        $matchedUids = [];
        $this->mailbox->connect(false, false);

        try {
            $uidValidity = $this->mailbox->getUidValidity();
            $messages = $this->mailbox->searchMessages();

            foreach ($rules as $rule) {
                if ($rule['target_from'] === '') {
                    continue;
                }

                foreach ($messages as $message) {
                    if (isset($matchedUids[$message['uid']])) {
                        continue;
                    }

                    if (
                        mb_stripos(
                            $message['sender'],
                            $rule['target_from'],
                        ) === false
                        && mb_stripos(
                            $message['subject'],
                            $rule['target_from'],
                        ) === false
                    ) {
                        continue;
                    }

                    $matchedUids[$message['uid']] = true;

                    try {
                        $history = $this->historyRepository->find(
                            $this->mailboxIdentifier,
                            $uidValidity,
                            $message['uid'],
                        );

                        if (($history['completed'] ?? false) === true) {
                            continue;
                        }

                        if (
                            ($history['slack_posted'] ?? false) !== true
                            && $rule['channel_id'] !== null
                        ) {
                            $slackTimestamp = $this->postToSlack(
                                $message['uid'],
                                $rule,
                            );
                            $this->historyRepository->recordSlackPosted(
                                $this->mailboxIdentifier,
                                $uidValidity,
                                $message['uid'],
                                $slackTimestamp,
                            );
                        }

                        $this->mailbox->markMessageAsSeen($message['uid']);
                        $this->mailbox->moveMessage(
                            $message['uid'],
                            $rule['to_folder'],
                        );
                        $this->historyRepository->markCompleted(
                            $this->mailboxIdentifier,
                            $uidValidity,
                            $message['uid'],
                        );
                    } catch (RuntimeException) {
                        $failureCount++;
                    }
                }
            }
        } finally {
            $this->mailbox->disconnect();
        }

        return [
            'success' => $failureCount === 0,
            'failure_count' => $failureCount,
        ];
    }

    /**
     * @param array{
     *     target_from: string,
     *     to_folder: string,
     *     channel_id: string|null,
     *     user_name: string|null,
     *     icon_url: string|null,
     *     prefix_format: string|null
     * } $rule
     */
    private function postToSlack(int $uid, array $rule): string
    {
        $content = $this->mailbox->readMessage(
            $uid,
            $this->mimeMessageDecoder,
        );

        if (!is_string($rule['user_name']) || !is_string($rule['icon_url'])) {
            throw new RuntimeException(
                'Mail rule is missing Slack display data.',
            );
        }

        $displayName = $this->buildSlackDisplayName(
            $rule['user_name'],
            $rule['prefix_format'],
            $content['received_at'],
        );

        if ($content['html_body'] !== '') {
            $pdf = $this->htmlToPdfConverter->convert(
                $content['html_body'],
                $content['inline_images'] ?? [],
                mailLogContext: [
                    'mail_uid' => $uid,
                    'subject' => $content['subject'],
                    'received_at' => $content['received_at']?->format(DATE_ATOM),
                ],
            );

            return $this->slackClient->postPdfFile(
                $rule['channel_id'],
                self::PDF_FILENAME,
                $pdf,
                sprintf('件名：%s', $content['subject']),
                $displayName,
                $rule['icon_url'],
            );
        }

        return $this->slackClient->postCustomMessage(
            $rule['channel_id'],
            sprintf(
                "件名：%s\n----------\n%s\n----------",
                $content['subject'],
                $content['body'],
            ),
            $displayName,
            $rule['icon_url'],
        );
    }

    /**
     * Reproduces BvlionBatch4's Mail#getSlackUserName(): the display
     * name is the plain user_name when prefix_format is empty, or
     * user_name with the mail's received date appended (formatted per
     * prefix_format) otherwise. As in the old implementation, when the
     * received date cannot be determined this silently falls back to
     * the plain user_name instead of failing the mail.
     */
    private function buildSlackDisplayName(
        string $userName,
        ?string $prefixFormat,
        ?DateTimeImmutable $receivedAt,
    ): string {
        if ($prefixFormat === null || $prefixFormat === '' || $receivedAt === null) {
            return $userName;
        }

        return $userName . $this->dateFormatConverter->format(
            $receivedAt,
            $prefixFormat,
        );
    }
}
