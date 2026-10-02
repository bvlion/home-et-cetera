CREATE TABLE mail_processing_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    mailbox_identifier VARCHAR(191) NOT NULL,
    uid_validity BIGINT UNSIGNED NOT NULL,
    uid BIGINT UNSIGNED NOT NULL,
    slack_posted TINYINT UNSIGNED NOT NULL DEFAULT 0,
    completed TINYINT UNSIGNED NOT NULL DEFAULT 0,
    slack_timestamp VARCHAR(32) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    completed_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY unique_mail_message (
        mailbox_identifier,
        uid_validity,
        uid
    ),
    KEY completed_history (completed, completed_at)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
