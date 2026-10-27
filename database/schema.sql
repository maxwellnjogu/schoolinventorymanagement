-- =====================================================================
-- St. Jude High School Inventory Management System
-- Complete Database Schema (Phase 1 to Phase 6)
-- Target: MySQL 5.7+ / 8.0+ / MariaDB 10.3+
-- Engine: InnoDB, Charset: utf8mb4, Collation: utf8mb4_unicode_ci
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `school_inventory` 
DEFAULT CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

USE `school_inventory`;

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Table: departments
-- High school departments (e.g. Science, ICT, Boarding, Sports, etc.)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `departments`;
CREATE TABLE `departments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(20) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `head_name` VARCHAR(100) NULL DEFAULT NULL,
  `contact_email` VARCHAR(120) NULL DEFAULT NULL,
  `description` TEXT NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_dept_code` (`code`),
  UNIQUE KEY `uk_dept_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table: locations
-- Storage facilities and departmental stores in the school
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `locations`;
CREATE TABLE `locations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(20) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `type` ENUM('main_store', 'department_store', 'laboratory', 'workshop', 'kitchen', 'library', 'office') NOT NULL DEFAULT 'main_store',
  `description` TEXT NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_loc_code` (`code`),
  KEY `idx_loc_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table: categories
-- Item categorization (Stationery, Science Chemicals, Consumables, Assets)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `categories`;
CREATE TABLE `categories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(20) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `is_asset_category` TINYINT(1) NOT NULL DEFAULT 0,
  `description` TEXT NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cat_code` (`code`),
  KEY `idx_cat_is_asset` (`is_asset_category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table: users
-- School personnel with Role-Based Access Control
-- Roles: admin, storekeeper, department_head, auditor
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL,
  `email` VARCHAR(120) NOT NULL,
  `full_name` VARCHAR(100) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('admin', 'storekeeper', 'department_head', 'auditor') NOT NULL DEFAULT 'storekeeper',
  `department_id` INT UNSIGNED NULL DEFAULT NULL,
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `last_login` DATETIME NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_users_username` (`username`),
  UNIQUE KEY `uk_users_email` (`email`),
  KEY `idx_users_role` (`role`),
  KEY `idx_users_status` (`status`),
  CONSTRAINT `fk_users_department` FOREIGN KEY (`department_id`) 
    REFERENCES `departments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table: login_attempts
-- Brute-force and rate-limiting protection table
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `login_attempts`;
CREATE TABLE `login_attempts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip_address` VARCHAR(45) NOT NULL,
  `username` VARCHAR(50) NOT NULL,
  `attempted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_attempts_ip_time` (`ip_address`, `attempted_at`),
  KEY `idx_attempts_user_time` (`username`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table: suppliers
-- School vendors and contractors
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `suppliers`;
CREATE TABLE `suppliers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `supplier_code` VARCHAR(30) NOT NULL,
  `company_name` VARCHAR(150) NOT NULL,
  `contact_person` VARCHAR(100) NULL DEFAULT NULL,
  `phone` VARCHAR(30) NULL DEFAULT NULL,
  `email` VARCHAR(120) NULL DEFAULT NULL,
  `address` TEXT NULL DEFAULT NULL,
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_supp_code` (`supplier_code`),
  KEY `idx_supp_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table: items
-- Consumable stock and master catalog items
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `items`;
CREATE TABLE `items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id` INT UNSIGNED NOT NULL,
  `location_id` INT UNSIGNED NULL DEFAULT NULL,
  `item_code` VARCHAR(50) NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `description` TEXT NULL DEFAULT NULL,
  `unit_of_measure` VARCHAR(30) NOT NULL DEFAULT 'Pcs',
  `is_asset` TINYINT(1) NOT NULL DEFAULT 0,
  `unit_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `quantity_on_hand` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `reorder_level` DECIMAL(12,2) NOT NULL DEFAULT 10.00,
  `status` ENUM('active', 'discontinued') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_item_code` (`item_code`),
  KEY `idx_item_category` (`category_id`),
  KEY `idx_item_location` (`location_id`),
  KEY `idx_item_is_asset` (`is_asset`),
  KEY `idx_item_reorder` (`quantity_on_hand`, `reorder_level`),
  CONSTRAINT `fk_items_category` FOREIGN KEY (`category_id`) 
    REFERENCES `categories` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_items_location` FOREIGN KEY (`location_id`) 
    REFERENCES `locations` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table: purchase_orders
-- Orders placed with approved suppliers
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `purchase_orders`;
CREATE TABLE `purchase_orders` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `po_number` VARCHAR(50) NOT NULL,
  `supplier_id` INT UNSIGNED NOT NULL,
  `order_date` DATE NOT NULL,
  `expected_date` DATE NULL DEFAULT NULL,
  `status` ENUM('draft', 'ordered', 'partially_received', 'received', 'cancelled') NOT NULL DEFAULT 'draft',
  `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `notes` TEXT NULL DEFAULT NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_po_number` (`po_number`),
  KEY `idx_po_supplier` (`supplier_id`),
  KEY `idx_po_status` (`status`),
  CONSTRAINT `fk_po_supplier` FOREIGN KEY (`supplier_id`) 
    REFERENCES `suppliers` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_po_user` FOREIGN KEY (`created_by`) 
    REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table: purchase_order_items
-- Line items within a purchase order
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `purchase_order_items`;
CREATE TABLE `purchase_order_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `po_id` INT UNSIGNED NOT NULL,
  `item_id` INT UNSIGNED NOT NULL,
  `quantity_ordered` DECIMAL(12,2) NOT NULL,
  `unit_price` DECIMAL(12,2) NOT NULL,
  `total_price` DECIMAL(12,2) NOT NULL,
  `quantity_received` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_poi_order` (`po_id`),
  KEY `idx_poi_item` (`item_id`),
  CONSTRAINT `fk_poi_order` FOREIGN KEY (`po_id`) 
    REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_poi_item` FOREIGN KEY (`item_id`) 
    REFERENCES `items` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table: goods_received_notes (GRN)
-- Goods intake: Stock INCREASES ONLY upon creation of GRN
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `goods_received_notes`;
CREATE TABLE `goods_received_notes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `grn_number` VARCHAR(50) NOT NULL,
  `po_id` INT UNSIGNED NULL DEFAULT NULL,
  `supplier_id` INT UNSIGNED NOT NULL,
  `delivery_note_number` VARCHAR(80) NULL DEFAULT NULL,
  `received_date` DATE NOT NULL,
  `received_by` INT UNSIGNED NOT NULL,
  `remarks` TEXT NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_grn_number` (`grn_number`),
  KEY `idx_grn_po` (`po_id`),
  KEY `idx_grn_supplier` (`supplier_id`),
  KEY `idx_grn_user` (`received_by`),
  CONSTRAINT `fk_grn_po` FOREIGN KEY (`po_id`) 
    REFERENCES `purchase_orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_grn_supplier` FOREIGN KEY (`supplier_id`) 
    REFERENCES `suppliers` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_grn_user` FOREIGN KEY (`received_by`) 
    REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table: goods_received_items
-- Line items received in a GRN
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `goods_received_items`;
CREATE TABLE `goods_received_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `grn_id` INT UNSIGNED NOT NULL,
  `po_item_id` INT UNSIGNED NULL DEFAULT NULL,
  `item_id` INT UNSIGNED NOT NULL,
  `quantity_received` DECIMAL(12,2) NOT NULL,
  `unit_cost` DECIMAL(12,2) NOT NULL,
  `total_cost` DECIMAL(12,2) NOT NULL,
  `remarks` VARCHAR(255) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_gri_grn` (`grn_id`),
  KEY `idx_gri_item` (`item_id`),
  CONSTRAINT `fk_gri_grn` FOREIGN KEY (`grn_id`) 
    REFERENCES `goods_received_notes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_gri_item` FOREIGN KEY (`item_id`) 
    REFERENCES `items` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table: stock_requests
-- Requisitions initiated by department heads
-- Note: Approval does NOT reduce stock per business rules!
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `stock_requests`;
CREATE TABLE `stock_requests` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_number` VARCHAR(50) NOT NULL,
  `department_id` INT UNSIGNED NOT NULL,
  `requested_by` INT UNSIGNED NOT NULL,
  `request_date` DATE NOT NULL,
  `status` ENUM('pending', 'approved', 'rejected', 'partially_issued', 'issued', 'cancelled') NOT NULL DEFAULT 'pending',
  `approved_by` INT UNSIGNED NULL DEFAULT NULL,
  `approval_date` DATETIME NULL DEFAULT NULL,
  `rejection_reason` TEXT NULL DEFAULT NULL,
  `remarks` TEXT NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sr_number` (`request_number`),
  KEY `idx_sr_dept` (`department_id`),
  KEY `idx_sr_user` (`requested_by`),
  KEY `idx_sr_status` (`status`),
  CONSTRAINT `fk_sr_dept` FOREIGN KEY (`department_id`) 
    REFERENCES `departments` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_sr_requester` FOREIGN KEY (`requested_by`) 
    REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_sr_approver` FOREIGN KEY (`approved_by`) 
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table: stock_request_items
-- Requisition lines
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `stock_request_items`;
CREATE TABLE `stock_request_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` INT UNSIGNED NOT NULL,
  `item_id` INT UNSIGNED NOT NULL,
  `quantity_requested` DECIMAL(12,2) NOT NULL,
  `quantity_approved` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `quantity_issued` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `remarks` VARCHAR(255) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sri_request` (`request_id`),
  KEY `idx_sri_item` (`item_id`),
  CONSTRAINT `fk_sri_request` FOREIGN KEY (`request_id`) 
    REFERENCES `stock_requests` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_sri_item` FOREIGN KEY (`item_id`) 
    REFERENCES `items` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table: stock_issues
-- Actual physical stock issuing: Stock DECREASES ONLY when issued!
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `stock_issues`;
CREATE TABLE `stock_issues` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `issue_number` VARCHAR(50) NOT NULL,
  `request_id` INT UNSIGNED NOT NULL,
  `department_id` INT UNSIGNED NOT NULL,
  `issued_by` INT UNSIGNED NOT NULL,
  `issued_to` VARCHAR(100) NOT NULL,
  `issue_date` DATE NOT NULL,
  `remarks` TEXT NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_issue_number` (`issue_number`),
  KEY `idx_issue_request` (`request_id`),
  KEY `idx_issue_dept` (`department_id`),
  KEY `idx_issue_user` (`issued_by`),
  CONSTRAINT `fk_issue_request` FOREIGN KEY (`request_id`) 
    REFERENCES `stock_requests` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_issue_dept` FOREIGN KEY (`department_id`) 
    REFERENCES `departments` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_issue_issuer` FOREIGN KEY (`issued_by`) 
    REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table: stock_issue_items
-- Stock issue line items
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `stock_issue_items`;
CREATE TABLE `stock_issue_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `issue_id` INT UNSIGNED NOT NULL,
  `item_id` INT UNSIGNED NOT NULL,
  `quantity_issued` DECIMAL(12,2) NOT NULL,
  `remarks` VARCHAR(255) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sii_issue` (`issue_id`),
  KEY `idx_sii_item` (`item_id`),
  CONSTRAINT `fk_sii_issue` FOREIGN KEY (`issue_id`) 
    REFERENCES `stock_issues` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_sii_item` FOREIGN KEY (`item_id`) 
    REFERENCES `items` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table: fixed_assets
-- Individual fixed assets: computers, furniture, vehicles, lab equipment
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `fixed_assets`;
CREATE TABLE `fixed_assets` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `asset_tag` VARCHAR(50) NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `category_id` INT UNSIGNED NOT NULL,
  `department_id` INT UNSIGNED NOT NULL,
  `location_id` INT UNSIGNED NOT NULL,
  `item_id` INT UNSIGNED NULL DEFAULT NULL,
  `serial_number` VARCHAR(100) NULL DEFAULT NULL,
  `model` VARCHAR(100) NULL DEFAULT NULL,
  `manufacturer` VARCHAR(100) NULL DEFAULT NULL,
  `purchase_date` DATE NOT NULL,
  `purchase_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `condition_status` ENUM('new', 'good', 'fair', 'damaged', 'disposed') NOT NULL DEFAULT 'good',
  `status` ENUM('in_use', 'under_maintenance', 'disposed', 'written_off') NOT NULL DEFAULT 'in_use',
  `assigned_to` VARCHAR(100) NULL DEFAULT NULL,
  `notes` TEXT NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_fa_tag` (`asset_tag`),
  KEY `idx_fa_category` (`category_id`),
  KEY `idx_fa_dept` (`department_id`),
  KEY `idx_fa_location` (`location_id`),
  KEY `idx_fa_condition` (`condition_status`),
  KEY `idx_fa_status` (`status`),
  CONSTRAINT `fk_fa_category` FOREIGN KEY (`category_id`) 
    REFERENCES `categories` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_fa_dept` FOREIGN KEY (`department_id`) 
    REFERENCES `departments` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_fa_location` FOREIGN KEY (`location_id`) 
    REFERENCES `locations` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_fa_item` FOREIGN KEY (`item_id`) 
    REFERENCES `items` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table: asset_transfers
-- Inter-departmental and inter-location asset custody transfers
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `asset_transfers`;
CREATE TABLE `asset_transfers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `asset_id` INT UNSIGNED NOT NULL,
  `transfer_date` DATE NOT NULL,
  `from_department_id` INT UNSIGNED NOT NULL,
  `to_department_id` INT UNSIGNED NOT NULL,
  `from_location_id` INT UNSIGNED NOT NULL,
  `to_location_id` INT UNSIGNED NOT NULL,
  `from_assigned_to` VARCHAR(100) NULL DEFAULT NULL,
  `to_assigned_to` VARCHAR(100) NULL DEFAULT NULL,
  `transferred_by` INT UNSIGNED NOT NULL,
  `reason` TEXT NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_at_asset` (`asset_id`),
  KEY `idx_at_user` (`transferred_by`),
  CONSTRAINT `fk_at_asset` FOREIGN KEY (`asset_id`) REFERENCES `fixed_assets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_at_from_dept` FOREIGN KEY (`from_department_id`) REFERENCES `departments` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_at_to_dept` FOREIGN KEY (`to_department_id`) REFERENCES `departments` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_at_from_loc` FOREIGN KEY (`from_location_id`) REFERENCES `locations` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_at_to_loc` FOREIGN KEY (`to_location_id`) REFERENCES `locations` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_at_user` FOREIGN KEY (`transferred_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table: asset_maintenance
-- Maintenance, repair, and servicing logs for fixed assets
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `asset_maintenance`;
CREATE TABLE `asset_maintenance` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `asset_id` INT UNSIGNED NOT NULL,
  `maintenance_date` DATE NOT NULL,
  `completion_date` DATE NULL DEFAULT NULL,
  `maintenance_type` ENUM('preventive', 'repair', 'servicing', 'inspection', 'calibration') NOT NULL DEFAULT 'servicing',
  `service_provider` VARCHAR(150) NOT NULL,
  `cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('scheduled', 'in_progress', 'completed') NOT NULL DEFAULT 'completed',
  `description` TEXT NOT NULL,
  `condition_after` ENUM('new', 'good', 'fair', 'damaged') NULL DEFAULT 'good',
  `performed_by` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_am_asset` (`asset_id`),
  KEY `idx_am_user` (`performed_by`),
  CONSTRAINT `fk_am_asset` FOREIGN KEY (`asset_id`) REFERENCES `fixed_assets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_am_user` FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table: asset_disposals
-- Official disposal and write-off records
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `asset_disposals`;
CREATE TABLE `asset_disposals` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `asset_id` INT UNSIGNED NOT NULL,
  `disposal_date` DATE NOT NULL,
  `disposal_method` ENUM('sold', 'scrapped', 'donated', 'written_off') NOT NULL DEFAULT 'scrapped',
  `proceeds` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `reason` TEXT NOT NULL,
  `authorized_by` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ad_asset` (`asset_id`),
  KEY `idx_ad_user` (`authorized_by`),
  CONSTRAINT `fk_ad_asset` FOREIGN KEY (`asset_id`) REFERENCES `fixed_assets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ad_user` FOREIGN KEY (`authorized_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table: stock_movements
-- Immutable ledger of all inventory transactions
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `stock_movements`;
CREATE TABLE `stock_movements` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reference_type` ENUM('grn', 'issue', 'adjustment', 'return', 'damage') NOT NULL,
  `reference_id` INT UNSIGNED NOT NULL,
  `reference_number` VARCHAR(50) NOT NULL,
  `item_id` INT UNSIGNED NOT NULL,
  `location_id` INT UNSIGNED NULL DEFAULT NULL,
  `movement_type` ENUM('in', 'out', 'adjustment') NOT NULL,
  `quantity` DECIMAL(12,2) NOT NULL,
  `unit_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `balance_after` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `performed_by` INT UNSIGNED NOT NULL,
  `notes` TEXT NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sm_item` (`item_id`),
  KEY `idx_sm_reftype` (`reference_type`, `reference_id`),
  KEY `idx_sm_user` (`performed_by`),
  KEY `idx_sm_created` (`created_at`),
  CONSTRAINT `fk_sm_item` FOREIGN KEY (`item_id`) 
    REFERENCES `items` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_sm_user` FOREIGN KEY (`performed_by`) 
    REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table: audit_logs
-- User activity audit trails
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NULL DEFAULT NULL,
  `action` VARCHAR(100) NOT NULL,
  `entity_type` VARCHAR(50) NOT NULL,
  `entity_id` INT UNSIGNED NULL DEFAULT NULL,
  `details` TEXT NULL DEFAULT NULL,
  `ip_address` VARCHAR(45) NULL DEFAULT NULL,
  `user_agent` VARCHAR(255) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_al_user` (`user_id`),
  KEY `idx_al_action` (`action`),
  KEY `idx_al_entity` (`entity_type`, `entity_id`),
  KEY `idx_al_created` (`created_at`),
  CONSTRAINT `fk_al_user` FOREIGN KEY (`user_id`) 
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
