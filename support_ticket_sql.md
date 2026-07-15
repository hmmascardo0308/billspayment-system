<!--
Support Ticket Schema (MySQL - InnoDB, utf8mb4)

This file contains CREATE TABLE statements for the support-ticket system described
in `support_ticket.md`.

Notes:
- The application is expected to generate `ticket_number` with the format
  `TKT-YYYYMMDD-XXXX` before inserting into `tickets` (an optional sequence
  helper example is provided at the bottom).
- `ticket_info` uses `ticket_number` as its PK and FK -> `tickets.ticket_number`.
- Supplemental detail tables (wrongbiller / overstatedamount / cancelledtransaction)
  reference `ticket_info.ticket_number` and are inserted only when applicable.
-->

```sql
-- Create schema and set it as the current database
CREATE SCHEMA IF NOT EXISTS `support_ticket` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `support_ticket`;

-- ------------------------------------------------------------------
-- Live-read views: expose `mldb.subbiller` as support_ticket views
-- Requires the DB user to have `SELECT` on `mldb.subbiller`
-- These views are used by the UI to populate Subbiller/Partner dropdowns
-- and for validating `ticket_info.wrong_biller_id` and `ticket_info.biller_name`.
-- ------------------------------------------------------------------
CREATE OR REPLACE VIEW `support_ticket`.`vw_mldb_partners` AS
SELECT DISTINCT
  TRIM(COALESCE(partner_id_kpx, '')) AS partner_ext_id,
  TRIM(COALESCE(partner_name, '')) AS partner_name
FROM mldb.subbiller
WHERE COALESCE(TRIM(partner_id_kpx), '') <> ''
  AND COALESCE(TRIM(partner_name), '') <> '';

CREATE OR REPLACE VIEW `support_ticket`.`vw_mldb_subbillers` AS
SELECT
  CAST(sub_billers_id AS CHAR) AS subbiller_ext_id,
  TRIM(COALESCE(sub_billers_name, 'UNKNOWN')) AS subbiller_name,
  TRIM(COALESCE(partner_id_kpx, '')) AS partner_ext_id
FROM mldb.subbiller;


-- Use a safe charset and engine
SET FOREIGN_KEY_CHECKS = 0;

-- Lookup: Ticket Types
CREATE TABLE IF NOT EXISTS `ticket_types` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `label` VARCHAR(150) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ticket_types_label` (`label`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Main tickets table (routing / ownership / state)
CREATE TABLE IF NOT EXISTS `tickets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_number` VARCHAR(32) NOT NULL,
  `reference_number` VARCHAR(100) DEFAULT NULL,
  `source` ENUM('KPX','KP7') NOT NULL DEFAULT 'KPX',
  `partner_ext_id` VARCHAR(128) DEFAULT NULL,

  -- user ids are stored as numeric references; FK constraints to users
  -- are not declared here to avoid dependency on the host application's
  -- `users` table schema (optional: add FK in your migration if `users` exists).
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_by_role` ENUM('BRANCH','VPO','CAD') NOT NULL DEFAULT 'BRANCH',
  `assigned_to` BIGINT UNSIGNED DEFAULT NULL,
  `vpo_owner` BIGINT UNSIGNED DEFAULT NULL,
  `cad_owner` BIGINT UNSIGNED DEFAULT NULL,

  `current_handler_role` ENUM('BRANCH','VPO','CAD') NOT NULL DEFAULT 'VPO',
  `status` ENUM('open','accepted','resolving','resolved','closed') NOT NULL DEFAULT 'open',
  `allow_branch_reply` TINYINT(1) NOT NULL DEFAULT 1,
  `close_type` ENUM('auto','immediate') DEFAULT NULL,
  `auto_close_at` DATETIME DEFAULT NULL,
  `closed_at` DATETIME DEFAULT NULL,

  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tickets_ticket_number` (`ticket_number`),
  KEY `idx_tickets_status` (`status`),
  KEY `idx_tickets_current_handler_role` (`current_handler_role`),
  KEY `idx_tickets_assigned_to` (`assigned_to`),
  KEY `idx_tickets_partner_ext_id` (`partner_ext_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One-to-one ticket details table: ticket_info
-- PK is ticket_number and FK -> tickets.ticket_number
CREATE TABLE IF NOT EXISTS `ticket_info` (
  `ticket_number` VARCHAR(32) NOT NULL,
  `ticket_type_id` BIGINT UNSIGNED DEFAULT NULL,
  `reason` TEXT DEFAULT NULL,

  -- Manual-entry-like fields mirrored from TRL manual flow
  `transfer_datetime` DATETIME DEFAULT NULL,
  `ref_no` VARCHAR(255) DEFAULT NULL,
  `wrong_biller_id` VARCHAR(100) DEFAULT NULL,
  `biller_name` VARCHAR(255) DEFAULT NULL,
  `account_no` VARCHAR(100) DEFAULT NULL,
  `account_name` VARCHAR(255) DEFAULT NULL,
  `payment_branch_id` VARCHAR(100) DEFAULT NULL,
  `payment_branch_name` VARCHAR(255) DEFAULT NULL,
  `amount` DECIMAL(20,2) DEFAULT NULL,
  `type_of_request` VARCHAR(100) DEFAULT NULL,
  `meta` JSON DEFAULT NULL,

  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`ticket_number`),
  CONSTRAINT `fk_ticket_info_ticket` FOREIGN KEY (`ticket_number`) REFERENCES `tickets`(`ticket_number`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ticket_info_type` FOREIGN KEY (`ticket_type_id`) REFERENCES `ticket_types`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Supplemental detail tables (insert only when applicable)
CREATE TABLE IF NOT EXISTS `ticket_info_wrongbiller` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_number` VARCHAR(32) NOT NULL,
  `correct_biller_id` VARCHAR(100) DEFAULT NULL,
  `correct_biller_name` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tiw_ticket_number` (`ticket_number`),
  CONSTRAINT `fk_tiw_ticket_info` FOREIGN KEY (`ticket_number`) REFERENCES `ticket_info`(`ticket_number`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ticket_info_overstatedamount` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_number` VARCHAR(32) NOT NULL,
  `wrong_amount` DECIMAL(20,2) DEFAULT NULL,
  `correct_amount` DECIMAL(20,2) DEFAULT NULL,
  `difference` DECIMAL(20,2) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tioa_ticket_number` (`ticket_number`),
  CONSTRAINT `fk_tioa_ticket_info` FOREIGN KEY (`ticket_number`) REFERENCES `ticket_info`(`ticket_number`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ticket_info_cancelledtransaction` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_number` VARCHAR(32) NOT NULL,
  `wrong_amount` DECIMAL(20,2) DEFAULT NULL,
  `correct_amount` DECIMAL(20,2) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tict_ticket_number` (`ticket_number`),
  CONSTRAINT `fk_tict_ticket_info` FOREIGN KEY (`ticket_number`) REFERENCES `ticket_info`(`ticket_number`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Trail / audit table (timeline entries). Each entry always has created_at.
CREATE TABLE IF NOT EXISTS `ticket_trails` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id` BIGINT UNSIGNED NOT NULL,
  `type` ENUM('message','accept','transfer','resolve','close','auto_close') NOT NULL DEFAULT 'message',
  `sender_id` BIGINT UNSIGNED DEFAULT NULL,
  `sender_role` ENUM('BRANCH','VPO','CAD','SYSTEM') NOT NULL DEFAULT 'BRANCH',
  `target_role` ENUM('BRANCH','VPO','CAD') DEFAULT NULL,
  `message` TEXT DEFAULT NULL,
  `meta` JSON DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_trails_ticket_id` (`ticket_id`),
  KEY `idx_trails_created_at` (`created_at`),
  CONSTRAINT `fk_trails_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Attachments stored as BLOBs (optionally switch to file paths if desired)
CREATE TABLE IF NOT EXISTS `ticket_attachments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_trail_id` BIGINT UNSIGNED DEFAULT NULL,
  `ticket_id` BIGINT UNSIGNED DEFAULT NULL,
  `file_name` VARCHAR(255) DEFAULT NULL,
  `mime_type` VARCHAR(120) DEFAULT NULL,
  `file_size` BIGINT UNSIGNED DEFAULT NULL,
  `file_data` LONGBLOB,
  `meta` JSON DEFAULT NULL,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_attachments_trail` (`ticket_trail_id`),
  KEY `idx_attachments_ticket` (`ticket_id`),
  CONSTRAINT `fk_attachments_trail` FOREIGN KEY (`ticket_trail_id`) REFERENCES `ticket_trails`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_attachments_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional helper: daily sequence table for ticket number generation (application-friendly)
CREATE TABLE IF NOT EXISTS `ticket_number_seq` (
  `seq_date` DATE NOT NULL,
  `last_seq` INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`seq_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- Example: generate a ticket_number safely in a transaction (application can run these statements)
--
-- START TRANSACTION;
-- SELECT last_seq INTO @last_seq FROM ticket_number_seq WHERE seq_date = CURDATE() FOR UPDATE;
-- IF @last_seq IS NULL THEN
--   INSERT INTO ticket_number_seq (seq_date, last_seq) VALUES (CURDATE(), 1);
--   SET @seq = 1;
-- ELSE
--   UPDATE ticket_number_seq SET last_seq = last_seq + 1 WHERE seq_date = CURDATE();
--   SELECT last_seq INTO @seq FROM ticket_number_seq WHERE seq_date = CURDATE();
-- END IF;
-- SET @ticket_number = CONCAT('TKT-', DATE_FORMAT(CURDATE(), '%Y%m%d'), '-', LPAD(@seq, 4, '0'));
-- -- Then insert into tickets and ticket_info inside the same transaction using @ticket_number
-- COMMIT;

```
