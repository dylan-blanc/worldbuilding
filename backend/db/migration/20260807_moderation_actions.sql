-- Adds authenticated moderation notifications and the durable MinIO deletion queue.
-- Admin decisions commit the queue first, attempt immediate deletion, then leave failures for the daily CLI retry.
USE worldbuilding;

CREATE TABLE IF NOT EXISTS user_notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    page_id INT NULL,
    notification_type ENUM('moderation_content_removed', 'moderation_page_picture_removed') NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    details JSON NOT NULL,
    read_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX user_notification_list (user_id, read_at, created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS storage_deletion_outbox (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_id INT NULL,
    object_key VARCHAR(512) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    deletion_status ENUM('pending', 'processing', 'deleted', 'failed') NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    available_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_at TIMESTAMP NULL DEFAULT NULL,
    processed_at TIMESTAMP NULL DEFAULT NULL,
    last_error_code VARCHAR(64) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX storage_outbox_schedule (deletion_status, available_at, id),
    FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE SET NULL
);
