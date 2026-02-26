-- Full Database Schema
-- Generated/collected from database/schema.sql
-- Run this file on your live DB after taking a backup.

SET FOREIGN_KEY_CHECKS = 0;

-- Table: activity_log
DROP TABLE IF EXISTS `activity_log`;
CREATE TABLE `activity_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `entity_type` varchar(50) DEFAULT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `activity_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=387 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: admin_credentials
DROP TABLE IF EXISTS `admin_credentials`;
CREATE TABLE `admin_credentials` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `category` enum('Software','Device','Account','Server','Database','API','Other') DEFAULT 'Other',
  `username` varchar(255) DEFAULT NULL,
  `password_encrypted` text DEFAULT NULL,
  `url` varchar(500) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `tags` varchar(500) DEFAULT NULL,
  `last_used` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_admin_category` (`admin_id`,`category`),
  KEY `idx_tags` (`tags`),
  FULLTEXT KEY `idx_search` (`title`,`notes`,`tags`),
  CONSTRAINT `admin_credentials_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: admin_meetings
DROP TABLE IF EXISTS `admin_meetings`;
CREATE TABLE `admin_meetings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `meeting_with` varchar(255) DEFAULT NULL,
  `meeting_date` date NOT NULL,
  `meeting_time` time NOT NULL,
  `duration_minutes` int(11) DEFAULT 30,
  `location` varchar(255) DEFAULT NULL,
  `meeting_link` varchar(500) DEFAULT NULL,
  `reminder_minutes` int(11) DEFAULT 15,
  `reminder_sent` tinyint(1) DEFAULT 0,
  `status` enum('Scheduled','Completed','Cancelled','Rescheduled') DEFAULT 'Scheduled',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_admin_date` (`admin_id`,`meeting_date`),
  KEY `idx_admin_status` (`admin_id`,`status`),
  KEY `idx_reminder` (`meeting_date`,`meeting_time`,`reminder_sent`),
  FULLTEXT KEY `idx_search` (`title`,`description`,`meeting_with`),
  CONSTRAINT `admin_meetings_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

