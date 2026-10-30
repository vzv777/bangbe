-- =====================================================
-- ระบบจัดการวัตถุดิบและขายหน้าร้าน (POS) ร้านบังเป้ ระยอง
-- เลือกฐานข้อมูลใน phpMyAdmin ก่อน แล้วค่อยกด Import (ใช้ได้ทั้ง XAMPP และโฮสต์ออนไลน์)
-- =====================================================

SET NAMES utf8mb4;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS order_items, orders, recipes, menus, stock_movements, stock_in, ingredients, suppliers, categories, users;
SET FOREIGN_KEY_CHECKS = 1;

-- ผู้ใช้งาน (admin = ผู้ดูแล, cashier = แคชเชียร์)
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('admin','cashier') NOT NULL DEFAULT 'cashier',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ประเภทวัตถุดิบ
CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255) NULL
) ENGINE=InnoDB;

-- ผู้จำหน่าย
CREATE TABLE suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    contact_name VARCHAR(100) NULL,
    phone VARCHAR(30) NULL,
    address VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- วัตถุดิบ
-- unit = หน่วยที่ใช้เก็บสต็อก (หน่วยเล็กสุด เช่น กรัม)
-- purchase_unit / purchase_factor = หน่วยตอนซื้อ เช่น กิโลกรัม = 1000 กรัม
CREATE TABLE ingredients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    category_id INT NOT NULL,
    unit VARCHAR(30) NOT NULL,
    purchase_unit VARCHAR(50) NULL,
    purchase_factor DECIMAL(12,4) NOT NULL DEFAULT 1,
    quantity DECIMAL(14,2) NOT NULL DEFAULT 0,
    min_quantity DECIMAL(14,2) NOT NULL DEFAULT 0,
    cost_per_unit DECIMAL(14,4) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id)
) ENGINE=InnoDB;

-- การรับเข้าวัตถุดิบ (แต่ละครั้งที่รับ = 1 ล็อต มีวันหมดอายุของตัวเอง)
CREATE TABLE stock_in (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ingredient_id INT NOT NULL,
    supplier_id INT NULL,
    received_date DATE NOT NULL,
    qty_input DECIMAL(14,2) NOT NULL,
    input_unit VARCHAR(50) NOT NULL,
    base_qty DECIMAL(14,2) NOT NULL,
    total_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
    expiry_date DATE NULL,
    expiry_cleared TINYINT(1) NOT NULL DEFAULT 0,
    note VARCHAR(255) NULL,
    user_id INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ingredient_id) REFERENCES ingredients(id),
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ประวัติการเคลื่อนไหวสต็อกทุกรายการ
-- in = รับเข้า, sale = ขาย, withdraw = เบิกใช้/ของเสีย, adjust = ปรับยอดจากการนับ, void = คืนสต็อกจากการยกเลิกบิล
CREATE TABLE stock_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ingredient_id INT NOT NULL,
    type ENUM('in','sale','withdraw','adjust','void') NOT NULL,
    qty_change DECIMAL(14,2) NOT NULL,
    balance_after DECIMAL(14,2) NOT NULL,
    ref_type VARCHAR(30) NULL,
    ref_id INT NULL,
    reason VARCHAR(100) NULL,
    note VARCHAR(255) NULL,
    user_id INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ref (ref_type, ref_id),
    INDEX idx_created (created_at),
    FOREIGN KEY (ingredient_id) REFERENCES ingredients(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- เมนูอาหาร
CREATE TABLE menus (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    menu_group VARCHAR(50) NOT NULL DEFAULT 'อาหาร',
    price DECIMAL(10,2) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- สูตรอาหาร: เมนู 1 จานใช้วัตถุดิบอะไรเท่าไร (หน่วยเดียวกับ ingredients.unit)
CREATE TABLE recipes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    menu_id INT NOT NULL,
    ingredient_id INT NOT NULL,
    quantity DECIMAL(12,2) NOT NULL,
    UNIQUE KEY uq_menu_ing (menu_id, ingredient_id),
    FOREIGN KEY (menu_id) REFERENCES menus(id) ON DELETE CASCADE,
    FOREIGN KEY (ingredient_id) REFERENCES ingredients(id)
) ENGINE=InnoDB;

-- บิลขาย
CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_no VARCHAR(20) NOT NULL UNIQUE,
    total DECIMAL(12,2) NOT NULL,
    payment_method ENUM('cash','transfer') NOT NULL,
    cash_received DECIMAL(12,2) NULL,
    change_amount DECIMAL(12,2) NULL,
    status ENUM('paid','void') NOT NULL DEFAULT 'paid',
    user_id INT NOT NULL,
    void_by INT NULL,
    void_reason VARCHAR(255) NULL,
    void_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (void_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    menu_id INT NULL,
    menu_name VARCHAR(150) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    qty INT NOT NULL,
    subtotal DECIMAL(12,2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (menu_id) REFERENCES menus(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =====================================================
-- ข้อมูลตัวอย่าง (ควรแทนที่ด้วยข้อมูลจริงของร้าน)
-- =====================================================

-- รหัสผ่าน: admin / admin123 และ cashier / cashier123 (เปลี่ยนหลังติดตั้ง)
INSERT INTO users (username, password, full_name, role) VALUES
('admin',   '$2y$10$pgYh..v94Lf4zbny7f8ZaOi0NRzn1s3YcliTk8645aiguMJLs.y8O', 'ผู้ดูแลระบบ', 'admin'),
('cashier', '$2y$10$41QiuW3xP6nLrjRmkVrwc.cvzeP5D2gG1nS8gcTkrBFnhoVPdNhS6', 'พนักงานแคชเชียร์', 'cashier');

INSERT INTO categories (name, description) VALUES
('เนื้อสัตว์', 'ไก่ เนื้อวัว อาหารทะเล'),
('ผัก', 'ผักสดและสมุนไพร'),
('เครื่องปรุง', 'ซอส เครื่องเทศ น้ำมัน'),
('วัตถุดิบแห้ง', 'ข้าว แป้ง ของแห้ง'),
('เครื่องดื่ม', 'วัตถุดิบสำหรับเครื่องดื่ม');

INSERT INTO suppliers (name, contact_name, phone, address) VALUES
('ตลาดสดเทศบาลระยอง', 'ร้านป้าแดง', '081-000-0001', 'ตลาดเทศบาล อ.เมืองระยอง'),
('แม็คโคร ระยอง', NULL, '038-000-002', 'ถ.สุขุมวิท อ.เมืองระยอง');

INSERT INTO ingredients (name, category_id, unit, purchase_unit, purchase_factor, quantity, min_quantity, cost_per_unit) VALUES
('ไก่ (สะโพก)',      1, 'กรัม', 'กิโลกรัม', 1000, 12000, 5000, 0.0850),
('เนื้อวัว',          1, 'กรัม', 'กิโลกรัม', 1000, 4000, 2000, 0.2800),
('ข้าวบาสมาติ',       4, 'กรัม', 'กิโลกรัม', 1000, 15000, 5000, 0.0650),
('ข้าวสวย (ข้าวสาร)', 4, 'กรัม', 'กิโลกรัม', 1000, 20000, 5000, 0.0300),
('เครื่องเทศข้าวหมก', 3, 'กรัม', 'ถุง (500 ก.)', 500, 1500, 500, 0.3000),
('หอมแดง',            2, 'กรัม', 'กิโลกรัม', 1000, 3000, 1000, 0.0700),
('ผักชี',             2, 'กรัม', 'กิโลกรัม', 1000, 150, 300, 0.1200),
('แตงกวา',            2, 'กรัม', 'กิโลกรัม', 1000, 2500, 1000, 0.0300),
('ไข่ไก่',             1, 'ฟอง', 'แผง (30 ฟอง)', 30, 90, 30, 4.0000),
('น้ำมันพืช',          3, 'มล.', 'ลิตร', 1000, 8000, 2000, 0.0550),
('ผงชา',              5, 'กรัม', 'ถุง (400 ก.)', 400, 1200, 400, 0.2500),
('นมข้นหวาน',         5, 'มล.', 'กระป๋อง (380 มล.)', 380, 2280, 760, 0.0700);

-- ยอดยกมาเริ่มต้น บันทึกเป็นการปรับยอด เพื่อให้มีประวัติครบ
INSERT INTO stock_movements (ingredient_id, type, qty_change, balance_after, reason, note, user_id)
SELECT id, 'adjust', quantity, quantity, 'ยอดยกมา', 'ตั้งค่าเริ่มต้นระบบ', 1 FROM ingredients;

-- ล็อตรับเข้าตัวอย่าง (มีล็อตใกล้หมดอายุ เพื่อทดสอบการแจ้งเตือน)
INSERT INTO stock_in (ingredient_id, supplier_id, received_date, qty_input, input_unit, base_qty, total_cost, expiry_date, note, user_id) VALUES
(1, 1, CURDATE() - INTERVAL 2 DAY, 12, 'กิโลกรัม', 12000, 1020, CURDATE() + INTERVAL 2 DAY, 'ตัวอย่าง', 1),
(8, 1, CURDATE() - INTERVAL 3 DAY, 2.5, 'กิโลกรัม', 2500, 75, CURDATE() + INTERVAL 1 DAY, 'ตัวอย่าง', 1),
(12, 2, CURDATE() - INTERVAL 10 DAY, 6, 'กระป๋อง (380 มล.)', 2280, 160, CURDATE() + INTERVAL 90 DAY, 'ตัวอย่าง', 1);

INSERT INTO menus (name, menu_group, price) VALUES
('ข้าวหมกไก่', 'อาหาร', 60),
('ข้าวหมกเนื้อ', 'อาหาร', 80),
('ไก่ทอด', 'อาหาร', 50),
('ข้าวผัดไก่', 'อาหาร', 50),
('ไข่ดาว', 'อาหาร', 10),
('ชาชัก', 'เครื่องดื่ม', 25),
('น้ำเปล่า', 'เครื่องดื่ม', 10);

INSERT INTO recipes (menu_id, ingredient_id, quantity) VALUES
(1, 1, 200), (1, 3, 150), (1, 5, 10), (1, 6, 20), (1, 7, 5), (1, 8, 30),
(2, 2, 180), (2, 3, 150), (2, 5, 12), (2, 6, 20), (2, 7, 5), (2, 8, 30),
(3, 1, 250), (3, 10, 60),
(4, 1, 120), (4, 4, 180), (4, 9, 1), (4, 10, 20), (4, 6, 15),
(5, 9, 1), (5, 10, 15),
(6, 11, 8), (6, 12, 30);
-- น้ำเปล่าไม่มีสูตร = ไม่ตัดสต็อก
