CREATE TABLE overtime_notification_settings (
    id TINYINT UNSIGNED NOT NULL,
    message TEXT NOT NULL,
    channel_id VARCHAR(255) NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
