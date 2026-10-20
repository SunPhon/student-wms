CREATE DATABASE IF NOT EXISTS student_wms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE student_wms;

CREATE TABLE IF NOT EXISTS users (
 id INT AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(100) NOT NULL,
 username VARCHAR(60) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 role ENUM('admin','staff') NOT NULL DEFAULT 'staff',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS products (
 id INT AUTO_INCREMENT PRIMARY KEY,
 sku VARCHAR(60) NOT NULL UNIQUE,
 name VARCHAR(180) NOT NULL,
 category VARCHAR(100) DEFAULT 'ทั่วไป',
 unit VARCHAR(30) NOT NULL DEFAULT 'ชิ้น',
 min_stock INT NOT NULL DEFAULT 0,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS locations (
 id INT AUTO_INCREMENT PRIMARY KEY,
 code VARCHAR(40) NOT NULL UNIQUE,
 zone VARCHAR(60) NOT NULL DEFAULT 'A',
 max_capacity INT NOT NULL DEFAULT 1000,
 active TINYINT(1) NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS lots (
 id INT AUTO_INCREMENT PRIMARY KEY,
 product_id INT NOT NULL,
 lot_no VARCHAR(80) NOT NULL,
 location_id INT NOT NULL,
 qty INT NOT NULL DEFAULT 0,
 received_at DATE NOT NULL,
 expiry_date DATE NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_product_lot_location (product_id, lot_no, location_id),
 FOREIGN KEY (product_id) REFERENCES products(id),
 FOREIGN KEY (location_id) REFERENCES locations(id),
 INDEX idx_lot_fifo (product_id, received_at, id),
 INDEX idx_lot_fefo (product_id, expiry_date, id)
);

CREATE TABLE IF NOT EXISTS movements (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 ref_no VARCHAR(50) NOT NULL,
 movement_type ENUM('RECEIVE','ISSUE','ADJUST') NOT NULL,
 product_id INT NOT NULL,
 lot_id INT NULL,
 from_location_id INT NULL,
 to_location_id INT NULL,
 qty INT NOT NULL,
 note VARCHAR(255) NULL,
 user_id INT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (product_id) REFERENCES products(id),
 FOREIGN KEY (lot_id) REFERENCES lots(id) ON DELETE SET NULL,
 FOREIGN KEY (from_location_id) REFERENCES locations(id) ON DELETE SET NULL,
 FOREIGN KEY (to_location_id) REFERENCES locations(id) ON DELETE SET NULL,
 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
 INDEX idx_movement_created (created_at),
 INDEX idx_movement_product (product_id)
);

INSERT IGNORE INTO locations(code,zone,max_capacity) VALUES
('A-01-01','A',500),('A-01-02','A',500),('B-01-01','B',800),('C-01-01','C',1000);
INSERT IGNORE INTO products(sku,name,category,unit,min_stock) VALUES
('SKU-1001','กล่องบรรจุสินค้า','บรรจุภัณฑ์','ชิ้น',50),
('SKU-1002','เทปกาว','อุปกรณ์คลัง','ม้วน',20),
('SKU-1003','น้ำยาทำความสะอาด','สินค้าอุปโภค','ขวด',30);
