-- Migration: Add client-specific project permissions
-- Date: 2026-03-03
-- Description: Allows admin to grant users permission to create/edit projects for specific clients

-- Add new permission types for project management
INSERT INTO project_permissions_types (permission_type, description, category, is_active) 
VALUES 
    ('create_project', 'Can create new projects for this client', 'project_management', 1),
    ('edit_project', 'Can edit projects for this client', 'project_management', 1)
ON DUPLICATE KEY UPDATE 
    description = VALUES(description),
    category = VALUES(category),
    is_active = VALUES(is_active);

-- Create client_permissions table for client-specific permissions
CREATE TABLE IF NOT EXISTS `client_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `permission_type` varchar(50) NOT NULL,
  `granted_by` int(11) DEFAULT NULL,
  `granted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_client_user_permission` (`client_id`,`user_id`,`permission_type`),
  KEY `idx_client_permissions_client` (`client_id`),
  KEY `idx_client_permissions_user` (`user_id`),
  KEY `idx_client_permissions_active` (`is_active`),
  KEY `fk_cp_granted_by` (`granted_by`),
  CONSTRAINT `fk_cp_granted_by` FOREIGN KEY (`granted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cp_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Create client_permissions_types table
CREATE TABLE IF NOT EXISTS `client_permissions_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `permission_type` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(30) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `permission_type` (`permission_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Insert default client permission types
INSERT INTO client_permissions_types (permission_type, description, category, is_active) 
VALUES 
    ('create_project', 'Can create new projects for this client', 'project_management', 1),
    ('edit_project', 'Can edit projects for this client', 'project_management', 1),
    ('view_project', 'Can view projects for this client', 'project_management', 1),
    ('delete_project', 'Can delete projects for this client', 'project_management', 1)
ON DUPLICATE KEY UPDATE 
    description = VALUES(description),
    category = VALUES(category),
    is_active = VALUES(is_active);
