-- =====================================================================
-- St. Jude High School Inventory Management System
-- Initial Seed Data
-- =====================================================================

USE `school_inventory`;

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Seed Departments
-- ---------------------------------------------------------------------
INSERT INTO `departments` (`id`, `code`, `name`, `head_name`, `contact_email`, `description`) VALUES
(1, 'DEP-ADMIN', 'School Administration', 'Principal Dr. Peter Kamau', 'admin@stjudehigh.ac.ke', 'Executive oversight, records, bursary, and general management'),
(2, 'DEP-SCI', 'Science Department', 'Mr. David Omondi', 'science@stjudehigh.ac.ke', 'Chemistry, Physics, Biology, and General Science laboratories'),
(3, 'DEP-ICT', 'ICT & Computer Studies', 'Ms. Faith Wanjiku', 'ict@stjudehigh.ac.ke', 'Computer laboratories, school network infrastructure, and multimedia'),
(4, 'DEP-BRD', 'Boarding & Catering', 'Mrs. Grace Chebet', 'boarding@stjudehigh.ac.ke', 'Student dormitories, dining hall, kitchen supplies, and rations'),
(5, 'DEP-SPT', 'Sports & Physical Education', 'Mr. Joseph Kiprono', 'sports@stjudehigh.ac.ke', 'Athletics, football, basketball, rugby equipment, and games uniforms')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- ---------------------------------------------------------------------
-- Seed Storage Locations
-- ---------------------------------------------------------------------
INSERT INTO `locations` (`id`, `code`, `name`, `type`, `description`) VALUES
(1, 'LOC-MAIN', 'Central Stores (Warehouse)', 'main_store', 'Main central receiving and stock distribution warehouse'),
(2, 'LOC-CHM', 'Chemistry Lab Preparation Room', 'laboratory', 'Safe chemical reagent storage, glassware shelves, and fume hoods'),
(3, 'LOC-ICT1', 'Computer Lab 1 Storage Cabinet', 'laboratory', 'ICT peripherals, spare cables, keyboards, and components'),
(4, 'LOC-DNG', 'Kitchen & Foodstuff Pantry', 'kitchen', 'Dry food rations, cooking oil, rice, beans, and dining utensils'),
(5, 'LOC-LIB', 'Main Library Archive Room', 'library', 'Reference textbooks, stationery stocks, and past exam booklets')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- ---------------------------------------------------------------------
-- Seed Categories
-- ---------------------------------------------------------------------
INSERT INTO `categories` (`id`, `code`, `name`, `is_asset_category`, `description`) VALUES
(1, 'CAT-STAT', 'Stationery & Office Supplies', 0, 'Exercise books, chalk, printing paper, pens, toner cartridges'),
(2, 'CAT-CHEM', 'Science Chemicals & Reagents', 0, 'Laboratory chemicals, indicators, acids, salts, and test reagents'),
(3, 'CAT-CLEAN', 'Cleaning & Sanitation Consumables', 0, 'Detergents, disinfectants, mops, brooms, and sanitizers'),
(4, 'CAT-FOOD', 'Boarding Foodstuff & Groceries', 0, 'Dry grains, flour, cooking fats, sugar, and dry rations'),
(5, 'CAT-ICT', 'Computer & ICT Hardware (Assets)', 1, 'Desktop computers, laptops, projectors, switches, and UPS units'),
(6, 'CAT-FURN', 'Furniture & Fittings (Assets)', 1, 'Desks, student chairs, lab stools, steel cabinets, and bunk beds'),
(7, 'CAT-LABEQ', 'Laboratory Equipment (Assets)', 1, 'Microscopes, autoclaves, Bunsen burners, and digital balances'),
(8, 'CAT-VEH', 'School Transport & Vehicles (Assets)', 1, 'School buses, vans, tractors, and utility vehicles')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- ---------------------------------------------------------------------
-- Seed Users (Default Development Accounts)
-- Passwords:
-- admin           => Admin@123
-- storekeeper     => Store@123
-- hod_science     => Hod@123
-- auditor         => Audit@123
-- ---------------------------------------------------------------------
INSERT INTO `users` (`id`, `username`, `email`, `full_name`, `password_hash`, `role`, `department_id`, `status`) VALUES
(1, 'admin', 'principal@stjudehigh.ac.ke', 'Dr. Peter Kamau (Principal)', '$2y$10$nP7I3oxxaSxKg8qi5BB1B.pUte4wa/AeDar.UBMHj4kAp/upTBOFW', 'admin', 1, 'active'),
(2, 'storekeeper', 'stores@stjudehigh.ac.ke', 'Samuel Mutua (Storekeeper)', '$2y$10$WvNepDsBW7J0Shydo0Smn.tbV04X4O2GXSNpPN1QZNEbJhZGimOyC', 'storekeeper', 1, 'active'),
(3, 'hod_science', 'science.hod@stjudehigh.ac.ke', 'David Omondi (HOD Science)', '$2y$10$1jgdZNgTGGGlNTkLAyJ.MO3JgjZHJRyzrvyjXCI/TXYDHaWV/2FBe', 'department_head', 2, 'active'),
(4, 'auditor', 'internal.audit@stjudehigh.ac.ke', 'Lucy Mwangi (Internal Auditor)', '$2y$10$BRxD98VYHDuB43JmqedQQecSrnHrQkBcot8Q18DBqqiUUAnwqKBOO', 'auditor', 1, 'active')
ON DUPLICATE KEY UPDATE `full_name` = VALUES(`full_name`);

-- ---------------------------------------------------------------------
-- Seed Suppliers
-- ---------------------------------------------------------------------
INSERT INTO `suppliers` (`id`, `supplier_code`, `company_name`, `contact_person`, `phone`, `email`, `address`, `status`) VALUES
(1, 'SUP-001', 'Nairobi Educational Publishers & Supplies', 'Moses Kariuki', '+254 722 100 200', 'orders@nepsupplies.co.ke', 'Commercial Street, Industrial Area, Nairobi', 'active'),
(2, 'SUP-002', 'Apex Science & Lab Equipment Ltd', 'Dr. Beatrice Achieng', '+254 733 456 789', 'sales@apexlabs.co.ke', 'Enterprise Road, Nairobi', 'active'),
(3, 'SUP-003', 'Silicon Valley Tech Solutions', 'Kevin Kimani', '+254 711 987 654', 'info@svtech.co.ke', 'Kenyatta Avenue, Nairobi', 'active'),
(4, 'SUP-004', 'Highland Farmers Grain Distributors', 'Esther Cherono', '+254 720 333 444', 'sales@highlandgrain.co.ke', 'Nakuru-Eldoret Highway', 'active')
ON DUPLICATE KEY UPDATE `company_name` = VALUES(`company_name`);

-- ---------------------------------------------------------------------
-- Seed Items (Consumables and Catalog Items)
-- Currency: KSh (unit_cost)
-- ---------------------------------------------------------------------
INSERT INTO `items` (`id`, `category_id`, `location_id`, `item_code`, `name`, `description`, `unit_of_measure`, `is_asset`, `unit_cost`, `quantity_on_hand`, `reorder_level`, `status`) VALUES
(1, 1, 1, 'ITM-PAP-A4', 'A4 Printing Paper (Carton of 5 Reams)', 'White 80gsm photocopier paper for exams & administrative letters', 'Carton', 0, 3200.00, 45.00, 15.00, 'active'),
(2, 1, 1, 'ITM-NB-200', 'A4 Ruled Exercise Books (200 Pages)', 'Official school embossed exercise books for student notes', 'Dozen', 0, 950.00, 120.00, 30.00, 'active'),
(3, 1, 1, 'ITM-CHK-WHT', 'Anti-Dust White Blackboard Chalk', 'Non-toxic dustless chalk boxes (100 sticks per box)', 'Box', 0, 350.00, 8.00, 20.00, 'active'), -- Low Stock alert demo
(4, 2, 2, 'ITM-CHM-HCL', 'Hydrochloric Acid (Analytic Grade 37%)', '2.5L Winchester glass bottle for Chemistry practical lessons', 'Bottle', 0, 2800.00, 4.00, 6.00, 'active'), -- Low Stock alert demo
(5, 2, 2, 'ITM-CHM-NAOH', 'Sodium Hydroxide Pellets (AR 98%)', '500g secure plastic container for volumetric analysis', 'Tin', 0, 1200.00, 18.00, 5.00, 'active'),
(6, 4, 4, 'ITM-FOOD-MAI', 'Grade 1 White Dry Maize Grain', '90kg bags for school posho meal and githeri boarding rations', 'Bag (90kg)', 0, 4500.00, 85.00, 25.00, 'active'),
(7, 3, 1, 'ITM-CLN-DIS', 'Hospital Grade Liquid Disinfectant', '20L jerrycan concentrated pine antiseptic fluid', 'Jerrycan', 0, 3800.00, 2.00, 5.00, 'active'), -- Low Stock alert demo
(8, 5, 3, 'ITM-AST-PC', 'HP ProDesk Core i5 Desktop Computer', 'Standard student computer unit with 19-inch LED monitor', 'Set', 1, 58000.00, 40.00, 5.00, 'active')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- ---------------------------------------------------------------------
-- Seed Fixed Assets (Individual tagged assets)
-- ---------------------------------------------------------------------
INSERT INTO `fixed_assets` (`id`, `asset_tag`, `name`, `category_id`, `department_id`, `location_id`, `item_id`, `serial_number`, `model`, `manufacturer`, `purchase_date`, `purchase_cost`, `condition_status`, `assigned_to`, `notes`) VALUES
(1, 'SJHS/ICT/PC/001', 'HP ProDesk Desktop 400 G7', 5, 3, 3, 8, 'CZC1234567', 'ProDesk 400', 'HP Inc.', '2025-02-10', 58000.00, 'good', 'Lab 1 Workstation 01', 'Computer Lab 1 student workstation'),
(2, 'SJHS/ICT/PC/002', 'HP ProDesk Desktop 400 G7', 5, 3, 3, 8, 'CZC1234568', 'ProDesk 400', 'HP Inc.', '2025-02-10', 58000.00, 'good', 'Lab 1 Workstation 02', 'Computer Lab 1 student workstation'),
(3, 'SJHS/SCI/MIC/001', 'Olympus Student Binocular Microscope CX23', 7, 2, 2, NULL, 'MIC-OLY-8821', 'CX23 LED', 'Olympus', '2024-05-18', 42500.00, 'good', 'Biology Lab Bench 1', 'Main biology magnification unit'),
(4, 'SJHS/VEH/BUS/001', 'Isuzu FSR 62-Passenger School Bus', 8, 1, 1, NULL, 'KDD 450Z', 'FSR 33L', 'General Motors / Isuzu', '2023-01-15', 8500000.00, 'good', 'Mr. Peter Mwenda (Driver)', 'Official school transport bus')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- ---------------------------------------------------------------------
-- Seed Audit Log Entry
-- ---------------------------------------------------------------------
INSERT INTO `audit_logs` (`user_id`, `action`, `entity_type`, `entity_id`, `details`, `ip_address`, `user_agent`) VALUES
(1, 'SYSTEM_INITIALIZATION', 'system', 1, 'Phase 1 high school inventory database initialized with baseline seed data.', '127.0.0.1', 'CLI/Setup Script');

SET FOREIGN_KEY_CHECKS = 1;
