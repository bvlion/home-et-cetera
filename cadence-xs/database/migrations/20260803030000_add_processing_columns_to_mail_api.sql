ALTER TABLE mail_api
    ADD COLUMN to_folder VARCHAR(255) NOT NULL AFTER target_from,
    ADD COLUMN channel_id VARCHAR(255) NOT NULL AFTER to_folder;
