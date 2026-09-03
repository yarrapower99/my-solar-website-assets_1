-- ============================================================
-- YarraPower Stock Management — Database Schema
-- Run this ONCE in your Plesk > Databases > phpMyAdmin
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ---- USERS ----
CREATE TABLE IF NOT EXISTS `users` (
    `id`            VARCHAR(36)  NOT NULL PRIMARY KEY,
    `name`          VARCHAR(255) NOT NULL,
    `username`      VARCHAR(100) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `role`          ENUM('View Only','Project Manager','Stock Manager','Director','Super User') NOT NULL DEFAULT 'View Only',
    `created_by`    VARCHAR(100) DEFAULT NULL,
    `created_at`    DATETIME     DEFAULT CURRENT_TIMESTAMP,
    `is_active`     TINYINT(1)   DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- WAREHOUSES ----
CREATE TABLE IF NOT EXISTS `warehouses` (
    `id`         VARCHAR(36)  NOT NULL PRIMARY KEY,
    `name`       VARCHAR(255) NOT NULL,
    `address`    TEXT         DEFAULT NULL,
    `created_at` DATETIME     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- PRODUCTS ----
CREATE TABLE IF NOT EXISTS `products` (
    `id`           VARCHAR(36)    NOT NULL PRIMARY KEY,
    `sku`          VARCHAR(100)   NOT NULL,
    `name`         VARCHAR(255)   NOT NULL,
    `category`     VARCHAR(100)   DEFAULT NULL,
    `brand`        VARCHAR(100)   DEFAULT NULL,
    `model`        VARCHAR(500)   DEFAULT NULL,
    `unit`         VARCHAR(50)    DEFAULT 'pcs',
    `stock`        INT            DEFAULT 0,
    `reserved`     INT            DEFAULT 0,
    `min_level`    INT            DEFAULT 0,
    `cost`         DECIMAL(12,2)  DEFAULT 0.00,
    `price`        DECIMAL(12,2)  DEFAULT 0.00,
    `supplier`     VARCHAR(255)   DEFAULT NULL,
    `warehouse_id` VARCHAR(36)    DEFAULT NULL,
    `location`     VARCHAR(255)   DEFAULT NULL,
    `notes`        TEXT           DEFAULT NULL,
    `created_at`   DATETIME       DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- SUPPLIERS ----
CREATE TABLE IF NOT EXISTS `suppliers` (
    `id`         VARCHAR(36)  NOT NULL PRIMARY KEY,
    `name`       VARCHAR(255) NOT NULL,
    `contact`    VARCHAR(255) DEFAULT NULL,
    `email`      VARCHAR(255) DEFAULT NULL,
    `phone`      VARCHAR(100) DEFAULT NULL,
    `country`    VARCHAR(100) DEFAULT NULL,
    `lead_time`  INT          DEFAULT 14,
    `notes`      TEXT         DEFAULT NULL,
    `created_at` DATETIME     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- PURCHASE ORDERS ----
CREATE TABLE IF NOT EXISTS `purchase_orders` (
    `id`            VARCHAR(36)   NOT NULL PRIMARY KEY,
    `number`        VARCHAR(50)   NOT NULL,
    `supplier_id`   VARCHAR(36)   DEFAULT NULL,
    `supplier_name` VARCHAR(255)  DEFAULT NULL,
    `status`        ENUM('Draft','Ordered','Received','Cancelled') DEFAULT 'Draft',
    `order_date`    DATE          DEFAULT NULL,
    `expected_date` DATE          DEFAULT NULL,
    `notes`         TEXT          DEFAULT NULL,
    `total`         DECIMAL(12,2) DEFAULT 0.00,
    `created_by`    VARCHAR(100)  DEFAULT NULL,
    `created_at`    DATETIME      DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `po_lines` (
    `id`           VARCHAR(36)   NOT NULL PRIMARY KEY,
    `po_id`        VARCHAR(36)   NOT NULL,
    `product_id`   VARCHAR(36)   DEFAULT NULL,
    `product_name` VARCHAR(255)  DEFAULT NULL,
    `sku`          VARCHAR(100)  DEFAULT NULL,
    `qty`          INT           DEFAULT 0,
    `cost`         DECIMAL(12,2) DEFAULT 0.00,
    `subtotal`     DECIMAL(12,2) DEFAULT 0.00,
    FOREIGN KEY (`po_id`) REFERENCES `purchase_orders`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- SALES ORDERS ----
CREATE TABLE IF NOT EXISTS `sales_orders` (
    `id`             VARCHAR(36)   NOT NULL PRIMARY KEY,
    `number`         VARCHAR(50)   NOT NULL,
    `customer`       VARCHAR(255)  NOT NULL,
    `project`        VARCHAR(255)  DEFAULT NULL,
    `date`           DATE          DEFAULT NULL,
    `dispatch_date`  DATE          DEFAULT NULL,
    `address`        TEXT          DEFAULT NULL,
    `notes`          TEXT          DEFAULT NULL,
    `warehouse_id`   VARCHAR(36)   DEFAULT NULL,
    `warehouse_name` VARCHAR(255)  DEFAULT NULL,
    `status`         ENUM('Allocated','Dispatched','Cancelled') DEFAULT 'Allocated',
    `total`          DECIMAL(12,2) DEFAULT 0.00,
    `created_at`     DATETIME      DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `so_lines` (
    `id`           VARCHAR(36)   NOT NULL PRIMARY KEY,
    `so_id`        VARCHAR(36)   NOT NULL,
    `product_id`   VARCHAR(36)   DEFAULT NULL,
    `product_name` VARCHAR(255)  DEFAULT NULL,
    `sku`          VARCHAR(100)  DEFAULT NULL,
    `qty`          INT           DEFAULT 0,
    `price`        DECIMAL(12,2) DEFAULT 0.00,
    `subtotal`     DECIMAL(12,2) DEFAULT 0.00,
    FOREIGN KEY (`so_id`) REFERENCES `sales_orders`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- BOOKINGS ----
CREATE TABLE IF NOT EXISTS `bookings` (
    `id`             VARCHAR(36)   NOT NULL PRIMARY KEY,
    `number`         VARCHAR(50)   NOT NULL,
    `customer`       VARCHAR(255)  NOT NULL,
    `project`        VARCHAR(255)  DEFAULT NULL,
    `date`           DATE          DEFAULT NULL,
    `expiry`         DATE          DEFAULT NULL,
    `notes`          TEXT          DEFAULT NULL,
    `warehouse_id`   VARCHAR(36)   DEFAULT NULL,
    `warehouse_name` VARCHAR(255)  DEFAULT NULL,
    `status`         ENUM('Active','Confirmed','Cancelled','Expired') DEFAULT 'Active',
    `total`          DECIMAL(12,2) DEFAULT 0.00,
    `linked_so`      VARCHAR(50)   DEFAULT NULL,
    `created_by`     VARCHAR(100)  DEFAULT NULL,
    `created_at`     DATETIME      DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `booking_lines` (
    `id`           VARCHAR(36)   NOT NULL PRIMARY KEY,
    `booking_id`   VARCHAR(36)   NOT NULL,
    `product_id`   VARCHAR(36)   DEFAULT NULL,
    `product_name` VARCHAR(255)  DEFAULT NULL,
    `sku`          VARCHAR(100)  DEFAULT NULL,
    `qty`          INT           DEFAULT 0,
    `price`        DECIMAL(12,2) DEFAULT 0.00,
    `subtotal`     DECIMAL(12,2) DEFAULT 0.00,
    FOREIGN KEY (`booking_id`) REFERENCES `bookings`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- ACTIVITY LOG ----
CREATE TABLE IF NOT EXISTS `activity_log` (
    `id`          INT          AUTO_INCREMENT PRIMARY KEY,
    `description` TEXT         NOT NULL,
    `type`        VARCHAR(20)  DEFAULT 'green',
    `created_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- APP SETTINGS ----
CREATE TABLE IF NOT EXISTS `app_settings` (
    `setting_key`   VARCHAR(100) NOT NULL PRIMARY KEY,
    `setting_value` TEXT         DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `app_counters` (
    `name`  VARCHAR(50) NOT NULL PRIMARY KEY,
    `value` INT         DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `app_units` (
    `unit`       VARCHAR(50) NOT NULL PRIMARY KEY,
    `sort_order` INT         DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- DEFAULT SEED DATA
-- ============================================================

INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`) VALUES
('company',  'YarraPower Pty Ltd'),
('abn',      ''),
('currency', 'THB'),
('fy',       'July');

INSERT IGNORE INTO `app_counters` (`name`, `value`) VALUES
('po', 1), ('so', 1), ('bk', 1);

INSERT IGNORE INTO `app_units` (`unit`, `sort_order`) VALUES
('pcs', 1), ('set', 2), ('box', 3), ('kWh', 4), ('m', 5),
('roll (100m)', 6), ('roll (1000m)', 7), ('roll (2000m)', 8), ('roll (3000m)', 9);

-- ============================================================
-- PROJECTS (project-management.html)
-- ============================================================

CREATE TABLE IF NOT EXISTS `projects` (
  `id`                VARCHAR(30)     NOT NULL,
  `type`              ENUM('Residential','C&I') NOT NULL DEFAULT 'Residential',
  `name`              VARCHAR(255)    NOT NULL,
  `customer`          VARCHAR(255)    NOT NULL,
  `status`            ENUM('Lead','Active','On Hold','Completed','Cancelled') NOT NULL DEFAULT 'Active',
  `phone`             VARCHAR(50)     DEFAULT NULL,
  `email`             VARCHAR(150)    DEFAULT NULL,
  `contact`           VARCHAR(150)    DEFAULT NULL,
  `address`           TEXT            DEFAULT NULL,
  `province`          VARCHAR(100)    DEFAULT NULL,
  `postcode`          VARCHAR(20)     DEFAULT NULL,
  `size`              DECIMAL(10,2)   DEFAULT NULL COMMENT 'System size in kWp',
  `panels`            SMALLINT        DEFAULT NULL COMMENT 'Number of panels',
  `panel_model`       VARCHAR(255)    DEFAULT NULL,
  `inverter`          VARCHAR(150)    DEFAULT NULL,
  `grid_conn`         VARCHAR(50)     DEFAULT NULL COMMENT 'On-Grid / Hybrid / Off-Grid',
  `battery_kwh`       DECIMAL(10,2)   DEFAULT NULL COMMENT 'Battery capacity in kWh',
  `battery_brand`     VARCHAR(150)    DEFAULT NULL,
  `install_date`      DATE            DEFAULT NULL,
  `value`             DECIMAL(15,2)   DEFAULT NULL COMMENT 'Quotation value THB',
  `quote_value`       DECIMAL(15,2)   DEFAULT NULL,
  `contract_value`    DECIMAL(15,2)   DEFAULT NULL,
  `pay_terms`         VARCHAR(255)    DEFAULT NULL,
  `sales`             VARCHAR(100)    DEFAULT NULL,
  `notes`             TEXT            DEFAULT NULL,
  -- C&I specific fields
  `ci_company`        VARCHAR(255)    DEFAULT NULL,
  `ci_taxid`          VARCHAR(50)     DEFAULT NULL,
  `ci_building`       VARCHAR(100)    DEFAULT NULL,
  `ci_grid_auth`      VARCHAR(100)    DEFAULT NULL COMMENT 'MEA / PEA / EGAT',
  `ci_peak_demand`    DECIMAL(10,2)   DEFAULT NULL COMMENT 'Peak demand in kVA',
  `ci_roof_area`      DECIMAL(10,2)   DEFAULT NULL COMMENT 'Roof area in sqm',
  `ci_roof_structure` VARCHAR(100)    DEFAULT NULL,
  `ci_contract_type`  VARCHAR(100)    DEFAULT NULL COMMENT 'Full EPC / Equipment Supply / EPC+O&M',
  `ci_special`        TEXT            DEFAULT NULL,
  -- Tracking (JSON blobs)
  `milestones`        TEXT            DEFAULT NULL COMMENT 'JSON array of milestone objects',
  `payment_periods`   TEXT            DEFAULT NULL COMMENT 'JSON array of payment installment objects',
  `traceability`      TEXT            DEFAULT NULL COMMENT 'JSON array of traceability step objects',
  `created_by`        VARCHAR(100)    DEFAULT NULL,
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_type`      (`type`),
  KEY `idx_status`    (`status`),
  KEY `idx_customer`  (`customer`(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SERIAL NUMBER TRACKING (stock-management.html)
-- ============================================================

-- Add serialized flag to existing products table
ALTER TABLE `products`
  ADD COLUMN IF NOT EXISTS `is_serialized` TINYINT(1) NOT NULL DEFAULT 0
  COMMENT '1 = unit-level serial tracking required (Panel/Inverter/RSD/Optimizer)';

-- Shipment records (one per container / delivery)
CREATE TABLE IF NOT EXISTS `shipments` (
  `id`           VARCHAR(36)   NOT NULL PRIMARY KEY,
  `ref`          VARCHAR(100)  DEFAULT NULL COMMENT 'Container or shipment reference',
  `po_number`    VARCHAR(100)  DEFAULT NULL,
  `supplier`     VARCHAR(200)  DEFAULT NULL,
  `arrival_date` DATE          NOT NULL    COMMENT 'Date goods arrived in Thailand — FIFO key',
  `received_by`  VARCHAR(100)  DEFAULT NULL,
  `notes`        TEXT          DEFAULT NULL,
  `created_at`   DATETIME      DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Individual serial unit records
CREATE TABLE IF NOT EXISTS `serial_units` (
  `id`               INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `serial_no`        VARCHAR(200)  NOT NULL,
  `product_id`       VARCHAR(36)   DEFAULT NULL,
  `product_type`     ENUM('Panel','Inverter','Battery') NOT NULL,
  `brand`            VARCHAR(100)  DEFAULT NULL,
  `model`            VARCHAR(200)  DEFAULT NULL,
  `watt_rating`      DECIMAL(8,2)  DEFAULT NULL COMMENT 'W for panels/RSD, kW for inverters',
  `manufacture_date` DATE          DEFAULT NULL,
  `shipment_id`      VARCHAR(36)   DEFAULT NULL,
  `arrival_date`     DATE          NOT NULL    COMMENT 'FIFO ordering key',
  `condition_status` ENUM('New','Damaged','Returned','Warranty Claim') NOT NULL DEFAULT 'New',
  `status`           ENUM('In Stock','Reserved','Released','Warranty Claim','Written Off') NOT NULL DEFAULT 'In Stock',
  `project_id`       VARCHAR(50)   DEFAULT NULL,
  `booking_id`       VARCHAR(36)   DEFAULT NULL,
  `booking_number`   VARCHAR(50)   DEFAULT NULL,
  `so_number`        VARCHAR(50)   DEFAULT NULL,
  `release_date`     DATE          DEFAULT NULL,
  `released_by`      VARCHAR(100)  DEFAULT NULL,
  `pallet_ref`       VARCHAR(100)  DEFAULT NULL COMMENT 'Pallet identifier — units sharing the same ref can be released as a group',
  `direct_to_site`   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = delivered direct to site, never in warehouse',
  `notes`            TEXT          DEFAULT NULL,
  `created_at`       DATETIME      DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_serial_no` (`serial_no`),
  INDEX `idx_product_type`  (`product_type`),
  INDEX `idx_status`        (`status`),
  INDEX `idx_arrival_date`  (`arrival_date`),
  INDEX `idx_project`       (`project_id`),
  INDEX `idx_booking`       (`booking_id`),
  FOREIGN KEY (`shipment_id`) REFERENCES `shipments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- PROJECT LINK ON PURCHASE ORDERS
-- ============================================================

ALTER TABLE `purchase_orders`
  ADD COLUMN IF NOT EXISTS `project_id`   VARCHAR(30)  DEFAULT NULL COMMENT 'Linked project ID e.g. YP-RES-2026-001',
  ADD COLUMN IF NOT EXISTS `project_name` VARCHAR(255) DEFAULT NULL COMMENT 'Denormalised project name for display';

-- ============================================================
-- YARRAEXAM RESULTS BACKEND
-- ============================================================

CREATE TABLE IF NOT EXISTS `exam_results` (
  `id`            INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `staff_id`      VARCHAR(100)  NOT NULL COMMENT 'Staff ID from YarraExam localStorage',
  `staff_name`    VARCHAR(255)  NOT NULL,
  `score`         INT           NOT NULL COMMENT 'Raw score out of 100',
  `passed`        TINYINT(1)    NOT NULL DEFAULT 0,
  `time_taken`    INT           DEFAULT NULL COMMENT 'Seconds taken to complete exam',
  `cat_a_score`   INT           DEFAULT NULL COMMENT 'Category A score',
  `cat_b_score`   INT           DEFAULT NULL COMMENT 'Category B score',
  `cat_c_score`   INT           DEFAULT NULL COMMENT 'Category C score',
  `cat_d_score`   INT           DEFAULT NULL COMMENT 'Category D score',
  `answers_json`  MEDIUMTEXT    DEFAULT NULL COMMENT 'Full answer snapshot for audit',
  `taken_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_staff`  (`staff_id`),
  INDEX `idx_passed` (`passed`),
  INDEX `idx_taken`  (`taken_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- YARRABOARD DOCUMENT REPORTS (stock-management.html)
-- ============================================================

CREATE TABLE IF NOT EXISTS `yb_document_reports` (
  `id`           VARCHAR(36)   NOT NULL PRIMARY KEY,
  `title`        VARCHAR(255)  NOT NULL,
  `description`  TEXT          DEFAULT NULL,
  `topic`        VARCHAR(50)   NOT NULL COMMENT 'booking/stock/finance/installation/procurement/hr',
  `authority`    VARCHAR(255)  DEFAULT '["all"]' COMMENT 'JSON array of audience groups',
  `period`       VARCHAR(100)  DEFAULT NULL COMMENT 'e.g. May 2026 / WK23 Jun 2026 / Q2 2026',
  `status`       ENUM('Request','Draft','Review','Final') NOT NULL DEFAULT 'Draft',
  `author`       VARCHAR(100)  DEFAULT NULL,
  `pages`        INT           DEFAULT 0,
  `file_size`    VARCHAR(50)   DEFAULT '—',
  `summary_json` MEDIUMTEXT    DEFAULT NULL COMMENT 'JSON snapshot of generated data',
  `generated_at` DATETIME      DEFAULT CURRENT_TIMESTAMP,
  `created_by`   VARCHAR(100)  DEFAULT NULL,
  KEY `idx_topic`  (`topic`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `app_counters` (`name`, `value`) VALUES ('doc_report', 1);

-- ============================================================
-- NOTE: The Super Admin account is created on FIRST LOGIN.
-- Open stock-management.html in your browser, and you will be
-- prompted to set up your admin username and password.
-- ============================================================
