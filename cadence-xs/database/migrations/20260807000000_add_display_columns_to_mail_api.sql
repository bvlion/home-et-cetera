ALTER TABLE mail_api
    ADD COLUMN user_name VARCHAR(255) NULL AFTER channel_id,
    ADD COLUMN icon_url VARCHAR(512) NULL AFTER user_name,
    ADD COLUMN prefix_format VARCHAR(255) NULL AFTER icon_url;
