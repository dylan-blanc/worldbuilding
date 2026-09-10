-- Removes the discontinued moderation-email queue; in-app notifications and MinIO deletion remain active.
USE worldbuilding;

DROP TABLE IF EXISTS email_outbox;
