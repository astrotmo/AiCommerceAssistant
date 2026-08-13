<?php declare(strict_types=1);

namespace AiCommerceAssistant\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1781000000CreateAicaPersistence extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1781000000;
    }

    public function update(Connection $connection): void
    {
        /*
         * Relationship policy: session deletion cascades messages, but preserves
         * contact submissions and session-level product references via nullable FKs.
         * Message deletion cascades its product references.
         */
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `aica_chat_session` (
                `id` BINARY(16) NOT NULL,
                `sales_channel_id` BINARY(16) NULL,
                `language_id` BINARY(16) NULL,
                `customer_id` BINARY(16) NULL,
                `storefront_session_identifier` VARCHAR(255) NOT NULL,
                `guest_fingerprint_hash` CHAR(64) NULL,
                `hashed_ip` CHAR(64) NULL,
                `hashed_user_agent` CHAR(64) NULL,
                `raw_ip` VARCHAR(64) NULL,
                `user_agent_raw` VARCHAR(512) NULL,
                `model_name` VARCHAR(255) NULL,
                `total_input_tokens` INT NULL,
                `total_output_tokens` INT NULL,
                `total_tokens` INT NULL,
                `total_message_count` INT NOT NULL DEFAULT 0,
                `first_message_at` DATETIME(3) NULL,
                `last_message_at` DATETIME(3) NULL,
                `average_response_latency_ms` DOUBLE NULL,
                `response_latency_sample_count` INT NOT NULL DEFAULT 0,
                `contact_form_shown` TINYINT(1) NOT NULL DEFAULT 0,
                `contact_form_submitted` TINYINT(1) NOT NULL DEFAULT 0,
                `debug_enabled_snapshot` TINYINT(1) NOT NULL DEFAULT 0,
                `store_raw_debug_payloads_snapshot` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.aica_chat_session.identifier` (`storefront_session_identifier`),
                KEY `idx.aica_chat_session.customer_id` (`customer_id`),
                KEY `idx.aica_chat_session.sales_channel_id` (`sales_channel_id`),
                KEY `idx.aica_chat_session.language_id` (`language_id`),
                KEY `idx.aica_chat_session.created_at` (`created_at`),
                CONSTRAINT `fk.aica_chat_session.sales_channel_id`
                    FOREIGN KEY (`sales_channel_id`) REFERENCES `sales_channel` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT `fk.aica_chat_session.language_id`
                    FOREIGN KEY (`language_id`) REFERENCES `language` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT `fk.aica_chat_session.customer_id`
                    FOREIGN KEY (`customer_id`) REFERENCES `customer` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');

        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `aica_chat_message` (
                `id` BINARY(16) NOT NULL,
                `session_id` BINARY(16) NOT NULL,
                `role` VARCHAR(32) NOT NULL,
                `message_index` INT NOT NULL,
                `raw_text` LONGTEXT NULL,
                `rendered_payload` JSON NULL,
                `message_type` VARCHAR(64) NULL,
                `block_kinds` JSON NULL,
                `token_input` INT NULL,
                `token_output` INT NULL,
                `token_total` INT NULL,
                `latency_ms` INT NULL,
                `model_name` VARCHAR(255) NULL,
                `request_id` VARCHAR(64) NULL,
                `contains_product_suggestions` TINYINT(1) NOT NULL DEFAULT 0,
                `contains_info_box` TINYINT(1) NOT NULL DEFAULT 0,
                `contains_formular` TINYINT(1) NOT NULL DEFAULT 0,
                `contains_tool_payload` TINYINT(1) NOT NULL DEFAULT 0,
                `is_error` TINYINT(1) NOT NULL DEFAULT 0,
                `debug_payload` JSON NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.aica_chat_message.session_idx` (`session_id`, `message_index`),
                KEY `idx.aica_chat_message.session_id` (`session_id`),
                KEY `idx.aica_chat_message.role` (`role`),
                KEY `idx.aica_chat_message.created_at` (`created_at`),
                CONSTRAINT `fk.aica_chat_message.session_id`
                    FOREIGN KEY (`session_id`) REFERENCES `aica_chat_session` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');

        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `aica_contact_submission` (
                `id` BINARY(16) NOT NULL,
                `session_id` BINARY(16) NULL,
                `customer_id` BINARY(16) NULL,
                `email` VARCHAR(254) NOT NULL,
                `name` VARCHAR(120) NULL,
                `phone` VARCHAR(40) NULL,
                `company` VARCHAR(120) NULL,
                `message_text` LONGTEXT NOT NULL,
                `product_reference_text` VARCHAR(255) NULL,
                `source_context` VARCHAR(255) NULL,
                `mail_target_address` VARCHAR(255) NOT NULL,
                `confirmation_mail_attempted` TINYINT(1) NOT NULL DEFAULT 0,
                `confirmation_mail_sent` TINYINT(1) NOT NULL DEFAULT 0,
                `confirmation_mail_error` TEXT NULL,
                `internal_notification_mail_attempted` TINYINT(1) NOT NULL DEFAULT 0,
                `internal_notification_mail_sent` TINYINT(1) NOT NULL DEFAULT 0,
                `internal_notification_mail_error` TEXT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                KEY `idx.aica_contact_submission.session_id` (`session_id`),
                KEY `idx.aica_contact_submission.customer_id` (`customer_id`),
                KEY `idx.aica_contact_submission.email` (`email`),
                KEY `idx.aica_contact_submission.created_at` (`created_at`),
                CONSTRAINT `fk.aica_contact_submission.session_id`
                    FOREIGN KEY (`session_id`) REFERENCES `aica_chat_session` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT `fk.aica_contact_submission.customer_id`
                    FOREIGN KEY (`customer_id`) REFERENCES `customer` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');

        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `aica_product_reference` (
                `id` BINARY(16) NOT NULL,
                `session_id` BINARY(16) NULL,
                `message_id` BINARY(16) NULL,
                `source` VARCHAR(32) NOT NULL,
                `product_id` VARCHAR(64) NULL,
                `product_number` VARCHAR(128) NULL,
                `product_name` VARCHAR(255) NULL,
                `reference_text` VARCHAR(255) NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                KEY `idx.aica_product_reference.session_id` (`session_id`),
                KEY `idx.aica_product_reference.message_id` (`message_id`),
                KEY `idx.aica_product_reference.source` (`source`),
                KEY `idx.aica_product_reference.product_id` (`product_id`),
                KEY `idx.aica_product_reference.product_number` (`product_number`),
                KEY `idx.aica_product_reference.created_at` (`created_at`),
                CONSTRAINT `fk.aica_product_reference.session_id`
                    FOREIGN KEY (`session_id`) REFERENCES `aica_chat_session` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT `fk.aica_product_reference.message_id`
                    FOREIGN KEY (`message_id`) REFERENCES `aica_chat_message` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
