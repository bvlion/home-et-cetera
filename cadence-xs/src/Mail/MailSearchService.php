<?php

declare(strict_types=1);

namespace BvlionBatch5\Mail;

final class MailSearchService
{
    public function __construct(
        private MailRuleRepository $mailRuleRepository,
        private ImapMailbox $mailbox,
    ) {
    }

    /**
     * @return list<array{uid: int, uid_validity: int}>
     */
    public function search(): array
    {
        $enabledTargets = $this->mailRuleRepository->findEnabledTargets();

        if ($enabledTargets === []) {
            return [];
        }

        $this->mailbox->connect();

        try {
            $uidValidity = $this->mailbox->getUidValidity();
            $messages = $this->mailbox->searchMessages();
        } finally {
            $this->mailbox->disconnect();
        }

        $matchedMessages = [];

        foreach ($messages as $message) {
            foreach ($enabledTargets as $target) {
                if ($target === '') {
                    continue;
                }

                if (
                    mb_stripos($message['sender'], $target) !== false
                    || mb_stripos($message['subject'], $target) !== false
                ) {
                    $matchedMessages[$message['uid']] = [
                        'uid' => $message['uid'],
                        'uid_validity' => $uidValidity,
                    ];
                    break;
                }
            }
        }

        return array_values($matchedMessages);
    }
}
