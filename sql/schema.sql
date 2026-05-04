-- ================================================================
--  BidhaaBora — Smart Grocery Management & Online Storefront
--  Database Schema v2.0
--  Author : Ramsley Ouma Odhiambo
--  Date   : 2026
--  Engine : MySQL 8.0+ / MariaDB 10.6+
-- ================================================================

CREATE DATABASE IF NOT EXISTS bidhaabora CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE bidhaabora;

-- ── ROLES ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS roles (
    role_id     INT AUTO_INCREMENT PRIMARY KEY,
    role_name   VARCHAR(60) NOT NULL UNIQUE,
    permissions JSON,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ── STAFF USERS ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS staff (
    staff_id      INT           AUTO_INCREMENT PRIMARY KEY,
    full_name     VARCHAR(150)  NOT NULL,
    username      VARCHAR(80)   NOT NULL UNIQUE,
    email         VARCHAR(150)  NOT NULL UNIQUE,
    password_hash VARCHAR(255)  NOT NULL,
    role_id       INT           NOT NULL DEFAULT 3,
    avatar_initials VARCHAR(4),
    is_active     TINYINT(1)    DEFAULT 1,
    last_login    DATETIME,
    created_at    DATETIME      DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(role_id)
);

-- ── ONLINE CUSTOMERS (optional account) ──────────────────────────
CREATE TABLE IF NOT EXISTS customers (
    customer_id   INT           AUTO_INCREMENT PRIMARY KEY,
    full_name     VARCHAR(150)  NOT NULL,
    email         VARCHAR(150)  UNIQUE,
    phone         VARCHAR(20),
    address       TEXT,
    password_hash VARCHAR(255),
    customer_type ENUM('Retail','Wholesale','Corporate') DEFAULT 'Retail',
    loyalty_pts   INT           DEFAULT 0,
    total_spent   DECIMAL(15,2) DEFAULT 0.00,
    from_online   TINYINT(1)    DEFAULT 0,
    is_active     TINYINT(1)    DEFAULT 1,
    registered_at DATETIME      DEFAULT CURRENT_TIMESTAMP
);

-- ── BRANCHES ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS branches (
    branch_id   INT          AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    address     TEXT,
    manager     VARCHAR(100),
    phone       VARCHAR(20),
    is_active   TINYINT(1)   DEFAULT 1,
    created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP
);

-- ── CATEGORIES ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS categories (
    cat_id      INT          AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL UNIQUE,
    icon        VARCHAR(10),
    description TEXT,
    created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP
);

-- ── SUPPLIERS ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS suppliers (
    supplier_id  INT          AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(150) NOT NULL,
    contact_name VARCHAR(100),
    email        VARCHAR(100),
    phone        VARCHAR(20),
    categories   VARCHAR(200),
    lead_time    VARCHAR(20)  DEFAULT '2 days',
    last_order   DATE,
    status       ENUM('Active','Inactive','Delayed','Blacklisted') DEFAULT 'Active',
    created_at   DATETIME     DEFAULT CURRENT_TIMESTAMP
);

-- ── PRODUCTS ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS products (
    product_id    INT            AUTO_INCREMENT PRIMARY KEY,
    sku           VARCHAR(50)    NOT NULL UNIQUE,
    barcode       VARCHAR(100)   UNIQUE,
    name          VARCHAR(200)   NOT NULL,
    description   TEXT,
    cat_id        INT,
    supplier_id   INT,
    icon_emoji    VARCHAR(10)    DEFAULT '📦',
    buying_price  DECIMAL(12,2)  NOT NULL DEFAULT 0.00,
    selling_price DECIMAL(12,2)  NOT NULL DEFAULT 0.00,
    wholesale_price DECIMAL(12,2) DEFAULT 0.00,
    tax_rate      DECIMAL(5,2)   DEFAULT 16.00,
    discount_pct  DECIMAL(5,2)   DEFAULT 0.00,
    deal_timer_hrs INT           DEFAULT 0,
    stock_qty     DECIMAL(10,2)  NOT NULL DEFAULT 0,
    reorder_level DECIMAL(10,2)  NOT NULL DEFAULT 10,
    unit          VARCHAR(20)    DEFAULT 'pcs',
    is_top_sell   TINYINT(1)     DEFAULT 0,
    is_active     TINYINT(1)     DEFAULT 1,
    created_by    INT,
    created_at    DATETIME       DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (cat_id)       REFERENCES categories(cat_id)   ON DELETE SET NULL,
    FOREIGN KEY (supplier_id)  REFERENCES suppliers(supplier_id) ON DELETE SET NULL,
    FOREIGN KEY (created_by)   REFERENCES staff(staff_id)       ON DELETE SET NULL
);

-- ── BRANCH STOCK (per-branch inventory) ──────────────────────────
CREATE TABLE IF NOT EXISTS branch_stock (
    bs_id       INT           AUTO_INCREMENT PRIMARY KEY,
    branch_id   INT           NOT NULL,
    product_id  INT           NOT NULL,
    stock_qty   DECIMAL(10,2) DEFAULT 0,
    updated_at  DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_branch_prod (branch_id, product_id),
    FOREIGN KEY (branch_id)  REFERENCES branches(branch_id),
    FOREIGN KEY (product_id) REFERENCES products(product_id)
);

-- ── PURCHASE ORDERS ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS purchase_orders (
    po_id        INT           AUTO_INCREMENT PRIMARY KEY,
    po_number    VARCHAR(30)   NOT NULL UNIQUE,
    supplier_id  INT           NOT NULL,
    branch_id    INT,
    created_by   INT           NOT NULL,
    po_date      DATE          NOT NULL,
    expected_date DATE,
    subtotal     DECIMAL(15,2) DEFAULT 0.00,
    total_amount DECIMAL(15,2) DEFAULT 0.00,
    status       ENUM('Draft','Submitted','Approved','Received','Partial','Cancelled') DEFAULT 'Draft',
    approved_by  INT,
    approved_at  DATETIME,
    notes        TEXT,
    created_at   DATETIME      DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id),
    FOREIGN KEY (branch_id)   REFERENCES branches(branch_id)   ON DELETE SET NULL,
    FOREIGN KEY (created_by)  REFERENCES staff(staff_id),
    FOREIGN KEY (approved_by) REFERENCES staff(staff_id)       ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS po_items (
    pi_id       INT           AUTO_INCREMENT PRIMARY KEY,
    po_id       INT           NOT NULL,
    product_id  INT           NOT NULL,
    qty_ordered DECIMAL(10,2) NOT NULL,
    qty_received DECIMAL(10,2) DEFAULT 0,
    unit_cost   DECIMAL(12,2) NOT NULL,
    line_total  DECIMAL(15,2) GENERATED ALWAYS AS (qty_ordered * unit_cost) STORED,
    batch_no    VARCHAR(50),
    expiry_date DATE,
    FOREIGN KEY (po_id)      REFERENCES purchase_orders(po_id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(product_id)
);

-- ── SALES ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sales (
    sale_id        INT           AUTO_INCREMENT PRIMARY KEY,
    receipt_no     VARCHAR(30)   NOT NULL UNIQUE,
    customer_id    INT,
    branch_id      INT,
    cashier_id     INT           NOT NULL,
    sale_date      DATETIME      DEFAULT CURRENT_TIMESTAMP,
    subtotal       DECIMAL(15,2) DEFAULT 0.00,
    discount_amt   DECIMAL(10,2) DEFAULT 0.00,
    tax_amount     DECIMAL(10,2) DEFAULT 0.00,
    total_amount   DECIMAL(15,2) DEFAULT 0.00,
    amount_paid    DECIMAL(15,2) DEFAULT 0.00,
    change_given   DECIMAL(10,2) GENERATED ALWAYS AS (amount_paid - total_amount) STORED,
    payment_method ENUM('Cash','M-PESA','Card','Cheque','Credit') DEFAULT 'Cash',
    mpesa_code     VARCHAR(30),
    status         ENUM('Completed','Voided','Refunded') DEFAULT 'Completed',
    notes          TEXT,
    FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON DELETE SET NULL,
    FOREIGN KEY (branch_id)   REFERENCES branches(branch_id)    ON DELETE SET NULL,
    FOREIGN KEY (cashier_id)  REFERENCES staff(staff_id)
);

CREATE TABLE IF NOT EXISTS sale_items (
    si_id       INT           AUTO_INCREMENT PRIMARY KEY,
    sale_id     INT           NOT NULL,
    product_id  INT           NOT NULL,
    quantity    DECIMAL(10,2) NOT NULL,
    unit_price  DECIMAL(12,2) NOT NULL,
    discount_pct DECIMAL(5,2) DEFAULT 0.00,
    line_total  DECIMAL(15,2) GENERATED ALWAYS AS (quantity * unit_price * (1 - discount_pct/100)) STORED,
    FOREIGN KEY (sale_id)    REFERENCES sales(sale_id)    ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(product_id)
);

-- ── ONLINE ORDERS ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS online_orders (
    order_id     INT          AUTO_INCREMENT PRIMARY KEY,
    order_no     VARCHAR(20)  NOT NULL UNIQUE,
    customer_id  INT,
    cust_name    VARCHAR(150) NOT NULL,
    cust_phone   VARCHAR(20)  NOT NULL,
    cust_email   VARCHAR(150),
    delivery_addr TEXT,
    delivery_method ENUM('pickup','courier','rider') DEFAULT 'pickup',
    payment_method  ENUM('mpesa','cod','card') DEFAULT 'mpesa',
    subtotal     DECIMAL(15,2) DEFAULT 0.00,
    delivery_fee DECIMAL(10,2) DEFAULT 0.00,
    tax_amount   DECIMAL(10,2) DEFAULT 0.00,
    total_amount DECIMAL(15,2) DEFAULT 0.00,
    status       ENUM('Pending','Confirmed','Dispatched','Delivered','Cancelled') DEFAULT 'Pending',
    notes        TEXT,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS order_items (
    oi_id      INT           AUTO_INCREMENT PRIMARY KEY,
    order_id   INT           NOT NULL,
    product_id INT           NOT NULL,
    quantity   DECIMAL(10,2) NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    line_total DECIMAL(15,2) GENERATED ALWAYS AS (quantity * unit_price) STORED,
    FOREIGN KEY (order_id)   REFERENCES online_orders(order_id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(product_id)
);

-- ── STOCK ADJUSTMENTS ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS stock_adjustments (
    adj_id      INT           AUTO_INCREMENT PRIMARY KEY,
    product_id  INT           NOT NULL,
    branch_id   INT,
    adj_type    ENUM('In','Out','Damaged','Expired','Return','Transfer','Correction','Waste') NOT NULL,
    quantity    DECIMAL(10,2) NOT NULL,
    reason      TEXT,
    reference   VARCHAR(50),
    adjusted_by INT           NOT NULL,
    adj_date    DATETIME      DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id)  REFERENCES products(product_id),
    FOREIGN KEY (branch_id)   REFERENCES branches(branch_id)   ON DELETE SET NULL,
    FOREIGN KEY (adjusted_by) REFERENCES staff(staff_id)
);

-- ── MPESA TRANSACTIONS ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS mpesa_transactions (
    txn_id        INT          AUTO_INCREMENT PRIMARY KEY,
    po_id         INT,
    sale_id       INT,
    txn_type      ENUM('STK_Push','B2C','C2B') DEFAULT 'STK_Push',
    phone_number  VARCHAR(20)  NOT NULL,
    amount        DECIMAL(12,2) NOT NULL,
    mpesa_receipt VARCHAR(30),
    status        ENUM('Pending','Success','Failed','Cancelled') DEFAULT 'Pending',
    description   VARCHAR(200),
    daraja_ref    VARCHAR(60),
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    confirmed_at  DATETIME,
    FOREIGN KEY (po_id)   REFERENCES purchase_orders(po_id)  ON DELETE SET NULL,
    FOREIGN KEY (sale_id) REFERENCES sales(sale_id)           ON DELETE SET NULL
);

-- ── ALERTS ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS alerts (
    alert_id   INT          AUTO_INCREMENT PRIMARY KEY,
    sev        ENUM('Critical','Warning','Info') NOT NULL,
    title      VARCHAR(250) NOT NULL,
    description TEXT,
    branch_id  INT,
    is_resolved TINYINT(1)  DEFAULT 0,
    resolved_by INT,
    resolved_at DATETIME,
    created_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id)   REFERENCES branches(branch_id)  ON DELETE SET NULL,
    FOREIGN KEY (resolved_by) REFERENCES staff(staff_id)       ON DELETE SET NULL
);

-- ── BRANCH TRANSFERS ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS branch_transfers (
    transfer_id  INT           AUTO_INCREMENT PRIMARY KEY,
    from_branch  INT           NOT NULL,
    to_branch    INT           NOT NULL,
    product_id   INT           NOT NULL,
    quantity     DECIMAL(10,2) NOT NULL,
    status       ENUM('Pending','Completed','Cancelled') DEFAULT 'Pending',
    notes        TEXT,
    transferred_by INT,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (from_branch)     REFERENCES branches(branch_id),
    FOREIGN KEY (to_branch)       REFERENCES branches(branch_id),
    FOREIGN KEY (product_id)      REFERENCES products(product_id),
    FOREIGN KEY (transferred_by)  REFERENCES staff(staff_id) ON DELETE SET NULL
);

-- ── EXPENSES ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS expenses (
    expense_id  INT           AUTO_INCREMENT PRIMARY KEY,
    category    VARCHAR(80),
    description VARCHAR(250)  NOT NULL,
    amount      DECIMAL(12,2) NOT NULL,
    branch_id   INT,
    expense_date DATE         NOT NULL,
    payment_method VARCHAR(30) DEFAULT 'Cash',
    reference   VARCHAR(80),
    recorded_by INT,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id)   REFERENCES branches(branch_id)  ON DELETE SET NULL,
    FOREIGN KEY (recorded_by) REFERENCES staff(staff_id)       ON DELETE SET NULL
);

-- ── AUDIT LOG ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS audit_log (
    log_id     INT AUTO_INCREMENT PRIMARY KEY,
    staff_id   INT,
    action     VARCHAR(150) NOT NULL,
    module     VARCHAR(60),
    record_id  INT,
    old_data   JSON,
    new_data   JSON,
    ip_address VARCHAR(45),
    logged_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (staff_id) REFERENCES staff(staff_id) ON DELETE SET NULL
);

-- ── INDEXES ───────────────────────────────────────────────────────
CREATE INDEX idx_prod_cat    ON products(cat_id);
CREATE INDEX idx_prod_sku    ON products(sku);
CREATE INDEX idx_prod_stock  ON products(stock_qty);
CREATE INDEX idx_prod_active ON products(is_active);
CREATE INDEX idx_sale_date   ON sales(sale_date);
CREATE INDEX idx_sale_branch ON sales(branch_id);
CREATE INDEX idx_ord_status  ON online_orders(status);
CREATE INDEX idx_adj_prod    ON stock_adjustments(product_id);
CREATE INDEX idx_mpesa_stat  ON mpesa_transactions(status);
CREATE INDEX idx_alert_sev   ON alerts(sev, is_resolved);

-- ── VIEWS ─────────────────────────────────────────────────────────
CREATE OR REPLACE VIEW vw_products AS
SELECT p.product_id, p.sku, p.barcode, p.name, p.icon_emoji, p.description,
       c.name AS category, c.cat_id, s.name AS supplier, p.supplier_id,
       p.buying_price, p.selling_price, p.wholesale_price, p.discount_pct,
       ROUND(p.selling_price*(1-p.discount_pct/100),2) AS discounted_price,
       p.tax_rate, p.deal_timer_hrs,
       ROUND((p.selling_price-p.buying_price)/NULLIF(p.selling_price,0)*100,1) AS margin_pct,
       p.stock_qty, p.reorder_level, p.unit,
       ROUND(p.stock_qty*p.buying_price,2) AS stock_value,
       CASE WHEN p.stock_qty=0 THEN 'Out of Stock'
            WHEN p.stock_qty<=p.reorder_level THEN 'Reorder Now'
            WHEN p.stock_qty<=p.reorder_level*1.5 THEN 'Low Stock'
            ELSE 'In Stock' END AS stock_status,
       p.is_top_sell, p.is_active, p.created_at
FROM products p
LEFT JOIN categories c  ON p.cat_id=c.cat_id
LEFT JOIN suppliers  s  ON p.supplier_id=s.supplier_id;

CREATE OR REPLACE VIEW vw_sales_daily AS
SELECT DATE(sale_date) AS sale_day,
       branch_id,
       COUNT(*) AS transactions,
       SUM(subtotal) AS gross,
       SUM(discount_amt) AS discounts,
       SUM(tax_amount) AS tax,
       SUM(total_amount) AS net_revenue,
       AVG(total_amount) AS avg_sale,
       SUM(CASE WHEN payment_method='Cash'   THEN total_amount ELSE 0 END) AS cash,
       SUM(CASE WHEN payment_method='M-PESA' THEN total_amount ELSE 0 END) AS mpesa,
       SUM(CASE WHEN payment_method='Card'   THEN total_amount ELSE 0 END) AS card
FROM sales WHERE status='Completed'
GROUP BY DATE(sale_date), branch_id;

CREATE OR REPLACE VIEW vw_top_selling AS
SELECT p.product_id, p.name, c.name AS category, p.icon_emoji,
       SUM(si.quantity) AS total_sold,
       SUM(si.line_total) AS total_revenue,
       SUM(si.quantity*(si.unit_price-p.buying_price)) AS total_profit
FROM sale_items si
JOIN products p ON si.product_id=p.product_id
LEFT JOIN categories c ON p.cat_id=c.cat_id
GROUP BY p.product_id, p.name, c.name, p.icon_emoji
ORDER BY total_revenue DESC;

CREATE OR REPLACE VIEW vw_customer_stats AS
SELECT c.customer_id, c.full_name, c.email, c.phone, c.customer_type, c.from_online,
       COUNT(DISTINCT s.sale_id) AS walk_in_orders,
       COUNT(DISTINCT o.order_id) AS online_orders,
       IFNULL(SUM(s.total_amount),0)+IFNULL(SUM(o.total_amount),0) AS lifetime_value,
       c.loyalty_pts, c.registered_at
FROM customers c
LEFT JOIN sales s ON c.customer_id=s.customer_id AND s.status='Completed'
LEFT JOIN online_orders o ON c.customer_id=o.customer_id
GROUP BY c.customer_id, c.full_name, c.email, c.phone, c.customer_type, c.from_online, c.loyalty_pts, c.registered_at;

-- ── TRIGGERS ──────────────────────────────────────────────────────
DELIMITER $$

CREATE TRIGGER trg_deduct_stock_on_sale
AFTER INSERT ON sale_items FOR EACH ROW
BEGIN
    UPDATE products SET stock_qty=GREATEST(0,stock_qty-NEW.quantity) WHERE product_id=NEW.product_id;
END$$

CREATE TRIGGER trg_restore_stock_on_void
AFTER UPDATE ON sales FOR EACH ROW
BEGIN
    IF NEW.status IN ('Voided','Refunded') AND OLD.status='Completed' THEN
        UPDATE products p
        JOIN sale_items si ON p.product_id=si.product_id
        SET p.stock_qty=p.stock_qty+si.quantity
        WHERE si.sale_id=NEW.sale_id;
    END IF;
END$$

CREATE TRIGGER trg_deduct_stock_online
AFTER INSERT ON order_items FOR EACH ROW
BEGIN
    UPDATE products SET stock_qty=GREATEST(0,stock_qty-NEW.quantity) WHERE product_id=NEW.product_id;
END$$

CREATE TRIGGER trg_update_sale_totals
AFTER INSERT ON sale_items FOR EACH ROW
BEGIN
    UPDATE sales
    SET subtotal=(SELECT SUM(line_total) FROM sale_items WHERE sale_id=NEW.sale_id),
        tax_amount=ROUND((SELECT SUM(line_total) FROM sale_items WHERE sale_id=NEW.sale_id)*0.16,2),
        total_amount=ROUND((SELECT SUM(line_total) FROM sale_items WHERE sale_id=NEW.sale_id)*1.16,2)
    WHERE sale_id=NEW.sale_id;
END$$

CREATE TRIGGER trg_add_stock_on_po_receive
AFTER UPDATE ON purchase_orders FOR EACH ROW
BEGIN
    IF NEW.status='Received' AND OLD.status<>'Received' THEN
        UPDATE products p
        JOIN po_items pi ON p.product_id=pi.product_id
        SET p.stock_qty=p.stock_qty+pi.qty_ordered, p.buying_price=pi.unit_cost
        WHERE pi.po_id=NEW.po_id;
    END IF;
END$$

CREATE TRIGGER trg_apply_adj
AFTER INSERT ON stock_adjustments FOR EACH ROW
BEGIN
    IF NEW.adj_type IN ('In','Return') THEN
        UPDATE products SET stock_qty=stock_qty+NEW.quantity WHERE product_id=NEW.product_id;
    ELSE
        UPDATE products SET stock_qty=GREATEST(0,stock_qty-NEW.quantity) WHERE product_id=NEW.product_id;
    END IF;
END$$

CREATE TRIGGER trg_update_customer_spend
AFTER UPDATE ON sales FOR EACH ROW
BEGIN
    IF NEW.status='Completed' AND NEW.customer_id IS NOT NULL THEN
        UPDATE customers
        SET total_spent=total_spent+NEW.total_amount,
            loyalty_pts=loyalty_pts+FLOOR(NEW.total_amount/100)
        WHERE customer_id=NEW.customer_id;
    END IF;
END$$

DELIMITER ;

-- ── STORED PROCEDURES ─────────────────────────────────────────────
DELIMITER $$

CREATE PROCEDURE sp_process_sale(
    IN p_cust_id INT, IN p_cashier_id INT, IN p_branch_id INT,
    IN p_method VARCHAR(20), IN p_paid DECIMAL(12,2), IN p_mpesa VARCHAR(30),
    OUT p_sale_id INT, OUT p_receipt VARCHAR(30)
)
BEGIN
    SET p_receipt=CONCAT('RCT-',DATE_FORMAT(NOW(),'%Y%m%d'),'-',LPAD(FLOOR(RAND()*99999),5,'0'));
    INSERT INTO sales(receipt_no,customer_id,branch_id,cashier_id,payment_method,amount_paid,mpesa_code,status)
    VALUES(p_receipt,p_cust_id,p_branch_id,p_cashier_id,p_method,p_paid,p_mpesa,'Completed');
    SET p_sale_id=LAST_INSERT_ID();
END$$

CREATE PROCEDURE sp_dashboard_kpis(IN p_branch_id INT)
BEGIN
    DECLARE v_rev DECIMAL(15,2); DECLARE v_sales INT;
    DECLARE v_prods INT; DECLARE v_low INT; DECLARE v_pend_orders INT;
    SELECT IFNULL(SUM(total_amount),0),COUNT(*) INTO v_rev,v_sales
    FROM sales WHERE DATE(sale_date)=CURDATE() AND status='Completed'
    AND (p_branch_id IS NULL OR branch_id=p_branch_id);
    SELECT COUNT(*) INTO v_prods FROM products WHERE is_active=1;
    SELECT COUNT(*) INTO v_low   FROM products WHERE stock_qty<=reorder_level AND is_active=1;
    SELECT COUNT(*) INTO v_pend_orders FROM online_orders WHERE status='Pending';
    SELECT v_rev AS today_revenue, v_sales AS today_sales,
           v_prods AS total_products, v_low AS low_stock,
           v_pend_orders AS pending_orders;
END$$

CREATE PROCEDURE sp_generate_monthly_report(IN p_month VARCHAR(7), IN p_branch_id INT)
BEGIN
    SELECT DATE_FORMAT(sale_date,'%Y-%m') AS month,
           COUNT(*) AS transactions,
           SUM(total_amount) AS revenue,
           SUM(tax_amount) AS vat,
           SUM(discount_amt) AS discounts
    FROM sales
    WHERE DATE_FORMAT(sale_date,'%Y-%m')=p_month AND status='Completed'
    AND (p_branch_id IS NULL OR branch_id=p_branch_id)
    GROUP BY DATE_FORMAT(sale_date,'%Y-%m');
END$$

DELIMITER ;

-- ================================================================
--  SEED DATA
-- ================================================================

INSERT INTO roles(role_name,permissions) VALUES
('Admin',         '{"all":true}'),
('Store Manager', '{"dashboard":true,"products":true,"purchases":true,"inventory":true,"suppliers":true,"sales":true,"reports":true,"alerts":true,"branches":true,"audit":true}'),
('Customer Support','{"dashboard":true,"customers":true,"orders":true,"alerts":true,"sales":true}'),
('Cashier',       '{"dashboard":true,"pos":true,"sales":true,"customers":true}');

INSERT INTO staff(full_name,username,email,password_hash,role_id,avatar_initials) VALUES
('Mitchell Wanga',   'mitchellwanga',   'mitchell@bidhaabora.co.ke', '$2y$12$dummyHashAdmin001ForMitchell', 1,'MW'),
('Willington Kutar', 'willingtonkutar', 'willington@bidhaabora.co.ke','$2y$12$dummyHashManager001ForWilling',2,'WK'),
('Wills George',     'willsgeorge',     'wills@bidhaabora.co.ke',    '$2y$12$dummyHashSupport001ForWills',  3,'WG'),
('Mike Clarence',    'mikeclarence',    'mike@bidhaabora.co.ke',     '$2y$12$dummyHashCashier001ForMike',   4,'MC'),
('Ramsley Odhiambo', 'ramsleyodhiambo', 'ramsley@bidhaabora.co.ke',  '$2y$12$dummyHashCashier002ForRamsley',4,'RO');
-- NOTE: All demo passwords = password_hash('password123', PASSWORD_BCRYPT, ['cost'=>12])
-- Regenerate with: echo password_hash('admin123', PASSWORD_BCRYPT);

INSERT INTO branches(name,address,manager,phone) VALUES
('CBD Branch',       'Tom Mboya St., Nairobi CBD',    'Mercy Masika',   '+254 700 100 001'),
('Westlands Branch', 'Ring Road, Westlands, Nairobi', 'Risper Adenyo',  '+254 700 100 002'),
('Karen Branch',     'Ngong Road, Karen, Nairobi',    'William Sifuna', '+254 700 100 003');

INSERT INTO categories(name,icon) VALUES
('Grains & Flour','🌾'),('Cooking Oils','🫙'),('Dairy & Eggs','🥛'),
('Beverages','🥤'),('Snacks & Confectionery','🍪'),('Cleaning & Hygiene','🧺'),
('Personal Care','🪥'),('Fresh Produce','🥦'),('Bakery & Pastries','🍞'),
('Meat & Fish','🍗'),('Frozen Foods','🧊'),('Rice & Pasta','🍚'),
('Pulses & Legumes','🫘'),('Alcohol & Spirits','🍺'),('Energy & Sports Drinks','⚡');

INSERT INTO suppliers(name,contact_name,email,phone,categories,lead_time,last_order,status) VALUES
('FreshFarms Ltd',    'Ann Wanjiru',  'info@freshfarms.ke',    '+254711001001','Produce, Dairy',      '2 days','2026-04-28','Active'),
('GrainMasters Co.',  'James Ochieng','orders@grainmasters.ke','+254711001002','Grains, Cereals, Rice','3 days','2026-04-25','Active'),
('MeatHub Suppliers', 'Peter Muthoni','supply@meathub.ke',     '+254711001003','Meat, Poultry, Fish',  '1 day', '2026-04-29','Active'),
('CoolChain Frozen',  'Lena Achieng', 'contact@coolchain.ke',  '+254711001004','Frozen, Ice Cream',    '4 days','2026-04-20','Delayed'),
('AquaPure Beverages','Samuel Kamau', 'sales@aquapure.ke',     '+254711001005','Beverages, Water',     '2 days','2026-04-27','Active'),
('Bidco Africa',      'Alice Kamau',  'alice@bidco.co.ke',     '+254711001006','Oils, Fats',           '2 days','2026-04-26','Active'),
('Unga Group Ltd',    'Peter Njoroge','peter@unga.co.ke',      '+254711001007','Flour, Meal, Grains',  '3 days','2026-04-24','Active'),
('Unilever EA',       'Mary Wanjiku', 'unilever@ea.co.ke',     '+254711001008','Cleaning, Snacks, Dairy','3 days','2026-04-23','Active'),
('Tusker Breweries',  'Dan Muiruri',  'supply@eabl.co.ke',     '+254711001009','Alcohol, Beverages',   '3 days','2026-04-22','Active');

-- ── PRODUCTS ─────────────────────────────────────────────────────
-- Existing products
INSERT INTO products(sku,name,cat_id,supplier_id,icon_emoji,buying_price,selling_price,discount_pct,deal_timer_hrs,stock_qty,reorder_level,unit,is_top_sell) VALUES
('SKU-001','Unga Dola Wheat Flour 2kg',     1,7,'🌾',145,175,0,0, 80,20,'bag', 1),
('SKU-002','Golden Fry Cooking Oil 1L',     2,6,'🫙',175,210,15,6,60,15,'btl', 1),
('SKU-003','KCC Fresh Milk 500ml',          3,1,'🥛', 52, 65,0,0,100,20,'pkt', 1),
('SKU-004','Delmonte Orange Juice 1L',      4,5,'🍊',110,140,10,4, 50,10,'btl', 0),
('SKU-005','Digestive Biscuits 200g',       5,8,'🍪', 65, 85,0,0, 60,15,'pkt', 1),
('SKU-006','Omo Washing Powder 1kg',        6,8,'🧺',180,220,0,0,  8,10,'bag', 0),
('SKU-007','Fresh Tomatoes (kg)',           8,1,'🍅', 60, 90,20,3,  4, 5,'kg',  1),
('SKU-008','Chicken Breast 1kg',           10,3,'🍗',280,350,0,0, 20, 5,'kg',  1),
('SKU-009','White Bread 400g',              9,5,'🍞', 50, 65,0,0, 90,20,'loaf',1),
('SKU-010','Colgate Toothpaste 75ml',       7,8,'🪥', 90,120,0,0, 45,10,'tube',0),
('SKU-011','Brookside Yoghurt 250ml',       3,1,'🥄', 75, 95,0,0, 55,10,'cup', 0),
('SKU-012','Coca-Cola 500ml',               4,5,'🥤', 55, 80,5,8, 80,20,'btl', 1),
('SKU-013','Soko Sugar 2kg',                1,7,'🍬',200,250,0,0, 70,20,'bag', 0),
('SKU-014','Blue Band Margarine 250g',      2,6,'🧈',130,165,0,0,  0,10,'tub', 0),
('SKU-015','Sunlight Dish Soap 500ml',      6,8,'🫧', 90,120,0,0, 50,10,'btl', 0),
('SKU-016','Indomie Noodles 70g',           5,8,'🍜', 20, 30,0,0,120,30,'pkt', 1),
('SKU-017','Eggs (tray of 30)',             3,1,'🥚',420,520,8,5, 15, 5,'tray',1),
('SKU-018','Kimbo Cooking Fat 500g',        2,6,'🫙',145,180,0,0, 35,10,'tin', 0),

-- NEW PRODUCTS — Grains & Flour
('SKU-019','Unga Pembe Maize Flour 2kg',    1,7,'🌽',130,165,0,0, 90,25,'bag', 1),
('SKU-020','Soko Maize Meal 2kg',           1,7,'🌽',120,150,0,0, 75,20,'bag', 1),
('SKU-021','Supa Uji Porridge Flour 400g',  1,7,'🥣', 75, 95,0,0, 60,15,'pkt', 0),
('SKU-022','Quaker Oats Porridge 500g',     1,8,'🥣',165,210,0,0, 40,10,'pkt', 0),

-- Rice & Pasta
('SKU-023','Pishori Rice 2kg',             12,2,'🍚',320,400,0,0, 45,10,'bag', 1),
('SKU-024','Biriani Rice 2kg',             12,2,'🍚',290,380,5,0, 30,10,'bag', 1),
('SKU-025','White Rice 2kg',               12,2,'🍚',220,280,0,0, 80,20,'bag', 1),
('SKU-026','Golden Penny Spaghetti 500g',  12,2,'🍝',110,145,0,0, 55,15,'pkt', 1),
('SKU-027','Pembe Pasta (Elbow) 500g',     12,7,'🫙',100,130,0,0, 40,10,'pkt', 0),

-- Condiments
('SKU-028','Kensalt Table Salt 500g',       1,7,'🧂', 45, 65,0,0,100,20,'pkt', 0),
('SKU-029','Royco Mchuzi Mix 75g',          1,7,'🧂', 40, 55,0,0, 80,20,'pkt', 0),
('SKU-030','Zesta Peanut Butter Smooth 400g',5,8,'🥜',280,360,10,0,35,10,'jar', 1),
('SKU-031','Zesta Jam Strawberry 400g',     5,8,'🍓',240,310,0,0, 30,10,'jar', 0),

-- Dairy & Eggs
('SKU-032','Tuzo Cream 250ml',              3,1,'🍦', 95,125,0,0, 40,10,'btl', 0),
('SKU-033','Mala (Fermented Milk) 500ml',   3,1,'🥛', 60, 80,0,0, 50,15,'pkt', 1),

-- Beverages
('SKU-034','Miranda Soda 500ml',            4,5,'🍊', 50, 75,0,0, 65,20,'btl', 1),
('SKU-035','Nescafe Gold Coffee 100g',      4,5,'☕',550,720,0,0, 25, 8,'jar', 0),
('SKU-036','Kericho Gold Tea Bags 50s',     4,8,'🍵',185,240,0,0, 45,10,'box', 1),
('SKU-037','Aquamist Mineral Water 500ml',  4,5,'💧', 25, 40,0,0,200,50,'btl', 1),
('SKU-038','Aquamist Mineral Water 1.5L',   4,5,'💧', 60, 90,0,0,100,30,'btl', 1),

-- Energy & Sports Drinks
('SKU-039','Red Bull Energy Drink 250ml',  15,5,'⚡',155,220,0,0, 40,10,'can', 0),
('SKU-040','Monster Energy 500ml',         15,5,'⚡',185,260,0,0, 35,10,'can', 0),
('SKU-041','Predator Energy 500ml',        15,5,'⚡',100,150,0,0, 50,15,'can', 0),

-- Alcohol
('SKU-042','Tusker Beer 500ml',            14,9,'🍺',140,200,0,0, 60,20,'btl', 1),
('SKU-043','White Cap Lager 500ml',        14,9,'🍺',130,185,0,0, 40,15,'btl', 0),
('SKU-044','Konyagi Gin 250ml',            14,9,'🍾',320,440,0,0, 20,10,'btl', 0),
('SKU-045','Smirnoff Vodka 250ml',         14,9,'🍸',380,520,0,0, 15, 8,'btl', 0),

-- Snacks & Confectionery
('SKU-046','Pringles Crisps Original 165g',5,8,'🥔',380,520,0,0, 25, 8,'can', 0),
('SKU-047','Lays Crisps Classic 28g',      5,8,'🥔', 45, 65,0,0, 80,25,'pkt', 1),
('SKU-048','McCains Chips Frozen 750g',    11,4,'🍟',320,440,0,0, 30,10,'bag',0),
('SKU-049','Mentos Sweets Mint Roll',       5,8,'🍬', 30, 45,0,0,120,40,'rol', 0),
('SKU-050','Kasuku Sweets Assorted 500g',  5,8,'🍬',180,250,0,0, 40,15,'bag', 0),
('SKU-051','Magnum Ice Cream Bar 86ml',    11,4,'🍦',220,320,0,0, 30,10,'bar', 0),
('SKU-052','Dairyland Ice Cream 1L',       11,4,'🍦',480,650,10,5,20,8,'tub', 0),

-- Fresh Produce
('SKU-053','Sukuma Wiki Kales (bunch)',     8,1,'🥬', 25, 40,0,0, 30, 8,'bch', 1),
('SKU-054','Spinach (bunch)',              8,1,'🌿', 25, 40,0,0, 20, 5,'bch', 1),
('SKU-055','Cabbage (head)',               8,1,'🥬', 60, 90,0,0, 25, 8,'hd',  1),
('SKU-056','Red Onions (kg)',              8,1,'🧅', 80,110,0,0, 40,10,'kg',  1),
('SKU-057','Ginger (100g)',               8,1,'🫚', 60, 90,0,0, 30, 8,'pkt', 0),
('SKU-058','Garlic (bulb)',               8,1,'🧄', 25, 40,0,0, 50,15,'bul', 0),
('SKU-059','Watermelon (whole)',           8,1,'🍉',350,500,0,0, 15, 5,'pcs', 1),
('SKU-060','Cucumber (each)',             8,1,'🥒', 30, 50,0,0, 40,10,'pcs', 0),
('SKU-061','Carrots (kg)',                8,1,'🥕', 70,100,0,0, 35,10,'kg',  1),
('SKU-062','Dhania Coriander Bunch',       8,1,'🌿', 20, 35,0,0, 50,15,'bch', 0),
('SKU-063','Green Capsicum (each)',        8,1,'🫑', 35, 55,0,0, 30,10,'pcs', 0),
('SKU-064','Red Capsicum (each)',          8,1,'🌶',  55, 80,0,0, 20, 8,'pcs', 0),
('SKU-065','Yellow Capsicum (each)',       8,1,'🫑', 55, 80,0,0, 15, 5,'pcs', 0),
('SKU-066','Green Beans (500g)',          13,1,'🫘', 90,130,0,0, 25, 8,'pkt', 0),

-- Bakery
('SKU-067','Celebration Cake (whole)',     9,1,'🎂',550,800,0,0, 10, 3,'pcs', 0),
('SKU-068','Cupcakes (box of 6)',          9,1,'🧁',250,380,10,0,15,5,'box', 0),
('SKU-069','Andazi Packet (10pcs)',        9,1,'🍩',120,180,0,0, 20, 8,'pkt', 1),
('SKU-070','Brown Bread 400g',             9,5,'🍞', 60, 80,0,0, 60,20,'loaf',1),

-- Meat & Fish
('SKU-071','Pork Chops 1kg',              10,3,'🥩',380,520,0,0, 15, 5,'kg',  0),
('SKU-072','Goat Meat (bone-in) 1kg',     10,3,'🐐',420,600,0,0, 12, 5,'kg',  0),
('SKU-073','Sardines / Omena 500g',       10,3,'🐟',120,180,0,0, 30,10,'pkt', 1),

-- Frozen
('SKU-074','McCain Frozen Peas 500g',     11,4,'🫛',180,250,0,0, 25, 8,'bag', 0),
('SKU-075','Spring Chicken Frozen 1kg',   11,4,'🐔',380,520,0,0, 20, 5,'bag', 0),
('SKU-076','Frozen Fish Fillet 500g',     11,4,'🐟',280,400,0,0, 18, 5,'pkt', 0);

-- Initial stock update for branch 1 (CBD)
INSERT INTO branch_stock(branch_id,product_id,stock_qty)
SELECT 1, product_id, stock_qty FROM products WHERE is_active=1;

-- Alerts seed
INSERT INTO alerts(sev,title,description,is_resolved) VALUES
('Critical','Whole Milk 1L — Out of stock · CBD Branch','0 units remaining · Reorder placed with FreshFarms · ETA May 3',0),
('Critical','Greek Yoghurt — Out of stock · Westlands','0 units remaining · No reorder initiated',0),
('Critical','Chicken Breast 1kg — Expiring May 3 · Westlands','14 units · Consider markdown or safe disposal',0),
('Warning','Tomatoes — Low stock · Karen Branch','8 units · Minimum threshold: 20 units',0),
('Warning','Frozen Peas 500g — Delivery delayed · CoolChain','2-day delay · New ETA: May 6, 2026',0),
('Warning','Omo Washing Powder — Low stock system-wide','8 units remaining across all branches',0),
('Info','FT-PO-0042 confirmed by MeatHub Suppliers','200 units arriving May 3, 2026',0),
('Info','Physical audit due May 5','Assigned to R. Odhiambo (Karen) and M. Clarence (Westlands)',0),
('Info','M-Pesa confirmed — FT-PO-0039','KSh 58,900 · Receipt: QJK7R4P1X2',0),
('Info','Email digest sent to manager@bidhaabora.ke','Daily summary · May 1, 2026 at 6:00 AM',0);

-- ================================================================
--  END OF SCHEMA
--  Compatible: MySQL 8.0+ / MariaDB 10.6+
--  Run: mysql -u root -p < sql/schema.sql
--  Or import via phpMyAdmin
-- ================================================================