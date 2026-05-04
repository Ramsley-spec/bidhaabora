# 🌿 BidhaaBora — Smart Grocery Management & Online Storefront

> **Version 2.0.0** · PHP + MySQL · Built for Kenyan grocery retailers  
> *Author: Ramsley Ouma Odhiambo · 2026*

---

## 📋 Table of contents

1. [Project overview](#1-project-overview)
2. [Tech stack](#2-tech-stack)
3. [Folder structure](#3-folder-structure)
4. [Database schema](#4-database-schema)
5. [Installation & setup](#5-installation--setup)
6. [Configuration](#6-configuration)
7. [Default credentials](#7-default-credentials)
8. [Staff roles & permissions](#8-staff-roles--permissions)
9. [Key modules](#9-key-modules)
10. [M-Pesa Daraja integration](#10-m-pesa-daraja-integration)
11. [API endpoints](#11-api-endpoints)
12. [Security checklist](#12-security-checklist)
13. [Known issues & fixes](#13-known-issues--fixes)
14. [Deployment guide](#14-deployment-guide)
15. [Changelog](#15-changelog)

---

## 1. Project overview

BidhaaBora is a full-featured web application for small and medium Kenyan grocery stores. It combines a customer-facing online storefront with a staff admin workspace — all in a single PHP + MySQL application served from any LAMP/WAMP stack.

**Core outcomes**

| Goal | How BidhaaBora solves it |
|---|---|
| Real-time inventory visibility | Per-branch `branch_stock` table + `vw_products` view with computed `stock_status` |
| Automatic stock alerts | `AlertModel::checkAndCreateLowStockAlerts()` + seeded critical/warning/info alerts |
| Faster supplier ordering | Full purchase-order workflow: Draft → Submitted → Approved → Received |
| Better business decisions | Dashboard KPIs, `vw_sales_daily`, `vw_top_selling`, monthly revenue stored procedure |
| M-Pesa payments | Daraja STK Push via `MpesaModel` with callback processing |
| Multi-branch support | `branches` table + per-branch stock, sales, and reporting filters |

---

## 2. Tech stack

| Layer | Technology |
|---|---|
| Frontend | Single-file HTML + Vanilla JS + CSS custom properties (no build step) |
| Backend | PHP 8.1+ |
| Database | MySQL 8.0+ / MariaDB 10.6+ |
| ORM / DB layer | Custom `DB` singleton class (PDO, prepared statements) |
| Authentication | PHP sessions + `password_hash()` / `password_verify()` (bcrypt cost 12) |
| Payments | Safaricom Daraja API (STK Push, B2C, C2B) via cURL |
| Server | Apache / Nginx + PHP-FPM (or XAMPP / WAMP locally) |
| Fonts | DM Sans, DM Serif Display, JetBrains Mono (Google Fonts CDN) |

---

## 3. Folder structure

```
bidhaabora/
├── config/
│   ├── config.php          # All constants — DB, Daraja, app settings
│   ├── db.php              # DB singleton (PDO wrapper)
│   └── helpers.php         # Utility functions, CSRF, audit log
│
├── modules/
│   ├── ProductModel.php    # Product CRUD, category & supplier helpers
│   ├── SalesModel.php      # POS sales, voiding, KPI queries
│   ├── OrderModel.php      # Online order create/update
│   ├── PurchaseModel.php   # Purchase order workflow
│   ├── SupplierModel.php   # Supplier CRUD
│   ├── CustomerModel.php   # Customer accounts, loyalty points
│   ├── ReportsModel.php    # Dashboard KPIs, revenue charts, audit log
│   ├── MpesaModel.php      # Daraja STK Push + callback handling
│   └── AlertModel.php      # Alert CRUD, low-stock auto-creation
│
├── api/
│   └── mpesa/
│       └── callback.php    # Daraja webhook endpoint
│
├── admin/
│   ├── login.php           # Staff login page
│   └── dashboard.php       # Admin workspace (redirect target)
│
├── public/
│   └── uploads/            # Product images (5 MB max)
│
├── sql/
│   └── schema.sql          # Full DB schema + seed data
│
├── web/
│   └── index.html          # Landing page + storefront SPA
│
├── index.php               # App entry point
└── README.md
```

---

## 4. Database schema

### Core tables

| Table | Purpose |
|---|---|
| `roles` | Four roles — Admin, Store Manager, Customer Support, Cashier |
| `staff` | Staff accounts with bcrypt hashed passwords and role FK |
| `customers` | Walk-in and online customer accounts (optional auth) |
| `branches` | CBD, Westlands, Karen + any new branches |
| `categories` | 15 product categories with emoji icons |
| `suppliers` | 9 seeded suppliers with status, lead time, contact |
| `products` | Master product catalogue — pricing, stock, discounts, deal timer |
| `branch_stock` | Per-branch inventory (unique constraint on branch+product) |
| `purchase_orders` | PO workflow with approval tracking |
| `po_items` | Line items with generated `line_total` column |
| `sales` | POS transactions with payment method and M-Pesa code |
| `sale_items` | Line items with computed `line_total` and discount |
| `online_orders` | Customer-facing online orders |
| `order_items` | Online order line items |
| `stock_adjustments` | Audit trail for all manual stock changes |
| `mpesa_transactions` | STK Push + callback records |
| `alerts` | Critical / Warning / Info alerts with resolution tracking |
| `branch_transfers` | Inter-branch stock movement |
| `expenses` | Branch expense tracking |
| `audit_log` | JSON diff log for every significant data change |

### Views

| View | Returns |
|---|---|
| `vw_products` | Full product details with computed `stock_status`, `margin_pct`, `stock_value`, `discounted_price` |
| `vw_sales_daily` | Daily revenue breakdown by branch and payment method |
| `vw_top_selling` | Revenue, units sold, profit per product |
| `vw_customer_stats` | Lifetime value, loyalty points, walk-in + online order counts |

### Triggers (7 total)

- `trg_deduct_stock_on_sale` — auto-deducts stock on `sale_items` insert
- `trg_restore_stock_on_void` — restores stock when sale is voided/refunded
- `trg_deduct_stock_online` — deducts stock on online `order_items` insert
- `trg_update_sale_totals` — recalculates subtotal, VAT, total on each item insert
- `trg_add_stock_on_po_receive` — adds stock and updates `buying_price` when PO status → Received
- `trg_apply_adj` — applies stock adjustments (In/Return add, everything else deducts)
- `trg_update_customer_spend` — updates `total_spent` and `loyalty_pts` on completed sale

### Stored procedures

| Procedure | Purpose |
|---|---|
| `sp_process_sale` | Atomic sale creation with receipt number generation |
| `sp_dashboard_kpis` | Single call returning all dashboard metrics |
| `sp_generate_monthly_report` | Monthly revenue summary for a given branch |

---

## 5. Installation & setup

### Prerequisites

- PHP 8.1 or higher with extensions: `pdo_mysql`, `curl`, `mbstring`, `json`
- MySQL 8.0+ or MariaDB 10.6+
- Apache or Nginx (XAMPP / WAMP works locally)

### Steps

```bash
# 1. Clone / place project in web root
cp -r bidhaabora/ /var/www/html/bidhaabora
# or for XAMPP: C:\xampp\htdocs\bidhaabora

# 2. Import the database
mysql -u root -p < sql/schema.sql
# or import via phpMyAdmin → Import → select schema.sql

# 3. Set folder permissions
chmod 755 public/uploads/

# 4. Edit config (see section 6)
nano config/config.php

# 5. Visit the app
http://localhost/bidhaabora
```

### Regenerate staff passwords

The seeded `password_hash` values are placeholders. Regenerate real hashes:

```php
// Run once in PHP CLI or a temporary script
echo password_hash('Admin123!',   PASSWORD_BCRYPT, ['cost' => 12]); // Mitchell Wanga
echo password_hash('Manager123!', PASSWORD_BCRYPT, ['cost' => 12]); // Willington Kutar
// etc.
```

Then update the `staff` table:

```sql
UPDATE staff SET password_hash = '<output>' WHERE username = 'mitchellwanga';
```

---

## 6. Configuration

All configuration lives in `config/config.php`. Edit these before deployment:

```php
// ── App ──────────────────────────────────
define('APP_URL', 'https://yourdomain.co.ke');   // No trailing slash
define('APP_ENV', 'production');                  // 'development' | 'production'

// ── Database ─────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'bidhaabora');
define('DB_USER', 'bidhaabora_user');             // Never use root in production
define('DB_PASS', 'strongpasswordhere');

// ── M-Pesa Daraja ─────────────────────────
define('DARAJA_ENV',             'production');   // 'sandbox' | 'production'
define('DARAJA_CONSUMER_KEY',    'your_key');
define('DARAJA_CONSUMER_SECRET', 'your_secret');
define('DARAJA_SHORTCODE',       '123456');
define('DARAJA_PASSKEY',         'your_passkey');
define('DARAJA_CALLBACK_URL',    APP_URL . '/api/mpesa/callback.php');
```

> ⚠️ **Never commit real credentials to version control.** Use `.env` files or server environment variables in production.

---

## 7. Default credentials

These are seeded in `schema.sql`. **Change all passwords immediately after first login.**

| Name | Username | Email | Role | Password |
|---|---|---|---|---|
| Mitchell Wanga | mitchellwanga | mitchell@bidhaabora.co.ke | Admin | *(regenerate — see section 5)* |
| Willington Kutar | willingtonkutar | willington@bidhaabora.co.ke | Store Manager | *(regenerate)* |
| Wills George | willsgeorge | wills@bidhaabora.co.ke | Customer Support | *(regenerate)* |
| Mike Clarence | mikeclarence | mike@bidhaabora.co.ke | Cashier | *(regenerate)* |
| Ramsley Odhiambo | ramsleyodhiambo | ramsley@bidhaabora.co.ke | Cashier | *(regenerate)* |

---

## 8. Staff roles & permissions

| Permission | Admin | Store Manager | Customer Support | Cashier |
|---|---|---|---|---|
| Dashboard | ✓ All branches | ✓ Own branch | ✓ | ✓ |
| Products — view & edit | ✓ | ✓ | ✗ | ✗ |
| POS / Sales | ✓ | ✓ | ✓ | ✓ |
| Customers | ✓ | ✓ | ✓ | ✓ |
| Purchase orders | ✓ | ✓ (create/submit) | ✗ | ✗ |
| Approve POs | ✓ | ✗ | ✗ | ✗ |
| Suppliers | ✓ | ✓ | ✗ | ✗ |
| Inventory adjustments | ✓ | ✓ | ✗ | ✗ |
| Online orders | ✓ | ✓ | ✓ | ✗ |
| Alerts | ✓ | ✓ | ✓ | ✗ |
| Reports | ✓ All | ✓ Own branch | ✗ | ✗ |
| Branches | ✓ | ✓ | ✗ | ✗ |
| Audit log | ✓ | ✗ | ✗ | ✗ |
| M-Pesa settings | ✓ | ✗ | ✗ | ✗ |
| Staff management | ✓ | ✗ | ✗ | ✗ |

Permissions are stored as a JSON object in `roles.permissions`. Use `hasPermission('key')` from `helpers.php` to check in any page.

---

## 9. Key modules

### ProductModel
- `getAll(array $filters)` — supports search, category, supplier, low-stock, top-sell, discounted filters
- `create(array $d)` — auto-generates SKU if not supplied, uses `$_SESSION['staff_id']` as creator
- `getLowStock()` — queries `vw_products` for `stock_status IN ('Out of Stock','Reorder Now')`

### SalesModel
- `startSale(...)` — inserts into `sales` table, returns `sale_id` and `receipt_no`
- `addItem(...)` — validates stock before insert; trigger handles deduction and total recalculation
- `getDailyKPIs()` — returns today's revenue, transaction count, average sale value
- `getMonthlyRevenue(int $months)` — 7-month trend for dashboard chart

### PurchaseModel
- Full workflow: `create()` → `submit()` → `approve()` → `receive()`
- On `receive()`, trigger `trg_add_stock_on_po_receive` auto-updates product stock and buying price

### MpesaModel
- `stkPush(phone, amount, poNumber)` — authenticates, builds payload, calls Daraja, logs to `mpesa_transactions`
- `handleCallback(data)` — processes Safaricom webhook, updates transaction status and receipt number

### AlertModel
- `checkAndCreateLowStockAlerts()` — call on cron or after any stock-affecting operation
- `resolve(id, staffId)` — marks resolved, logs to `audit_log`

### ReportsModel
- `dashboard(?branchId)` — 9-metric associative array for KPI cards
- `topProducts(limit)` — queries `vw_top_selling`
- `stockSummary()` — grouped by category with buy/sell values
- `supplierKPIs()` — order count and completion rate per supplier

---

## 10. M-Pesa Daraja integration

### Setup steps

1. Register at [https://daraja.safaricom.co.ke](https://daraja.safaricom.co.ke)
2. Create an app → copy **Consumer Key** and **Consumer Secret**
3. Get your **Business Shortcode** (Paybill or Till number)
4. Get your **Passkey** from the Daraja dashboard
5. Set all four in `config/config.php`
6. Point your **Callback URL** to `https://yourdomain.co.ke/api/mpesa/callback.php` (must be HTTPS, publicly accessible)

### STK Push flow

```
Staff triggers payment → MpesaModel::stkPush() → Daraja API
→ STK prompt on supplier/customer phone → PIN entry
→ Safaricom sends callback to /api/mpesa/callback.php
→ MpesaModel::handleCallback() updates mpesa_transactions
→ Receipt number stored, status = 'Success'
```

### Sandbox test phone numbers

Safaricom provides test numbers at `https://developer.safaricom.co.ke/test-credentials`. Use these during development.

### Cost note

Daraja charges approximately KSh 0.50 per STK Push request (successful or not). Safaricom takes 2–3% commission on successful transactions.

---

## 11. API endpoints

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| POST | `/api/mpesa/callback.php` | None (Safaricom IP only) | Daraja payment callback |
| GET | `/api/products.php` | Session | JSON product list for storefront |
| POST | `/api/orders.php` | None | Create online order |
| GET | `/api/dashboard.php` | Session + Admin | Dashboard KPI JSON |

> More endpoints should be added as `api/` PHP files returning JSON via `jsonResponse()` from `helpers.php`.

---

## 12. Security checklist

- [x] Passwords hashed with `password_hash()` bcrypt cost 12
- [x] All DB queries use PDO prepared statements (no raw interpolation)
- [x] CSRF token generated per session, verified on all POST requests via `verifyCsrf()`
- [x] `requireLogin()` / `requirePermission()` guards on all admin pages
- [x] `sanitize()` strips HTML tags and encodes special characters
- [x] Session regenerated on login (`session_regenerate_id(true)`)
- [x] File uploads restricted to 5 MB, stored outside web root ideally
- [ ] **TODO:** Move credentials to `.env` or server environment variables
- [ ] **TODO:** Enable HTTPS (Let's Encrypt) in production
- [ ] **TODO:** Add rate limiting to login endpoint
- [ ] **TODO:** Restrict Daraja callback to Safaricom IP ranges
- [ ] **TODO:** Set `APP_ENV = 'production'` and disable `display_errors`

---

## 13. Known issues & fixes

### Issue: `APP_ENV` used before `define()`

In `config/config.php`, `APP_ENV` is referenced in the `if` block before it is defined. **Fix:** move the `define('APP_ENV', ...)` line above the error-handling block.

```php
// BEFORE (broken)
if (APP_ENV === 'development') { ... }   // ← APP_ENV not yet defined
define('APP_ENV', 'development');

// AFTER (fixed)
define('APP_ENV', 'development');        // ← define first
if (APP_ENV === 'development') { ... }
```

### Issue: Seeded `password_hash` values are placeholders

The values in `schema.sql` like `$2y$12$dummyHashAdmin001ForMitchell` are not valid bcrypt hashes. PHP's `password_verify()` will always return `false` against them. **Fix:** regenerate real hashes (see section 5).

### Issue: `PurchaseModel::create()` has wrong argument order

```php
// BEFORE (broken — 'Draft' passed as $notes, status missing)
[$ono, $d['supplier_id'], ..., $d['notes']??null, 'Draft']

// AFTER (fixed)
[$ono, $d['supplier_id'], ..., 'Draft', $d['notes']??null]
```

Match the INSERT column order: `(po_number, supplier_id, branch_id, created_by, po_date, expected_date, status, notes)`.

### Issue: `DB` class not available in `helpers.php`

`helpers.php` calls `DB::query()` in `logAudit()` but `DB` is defined in `db.php`. **Fix:** ensure `db.php` is always required before `helpers.php`.

```php
// In every entry point (index.php, admin pages, api files):
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';        // ← must come before helpers
require_once __DIR__ . '/config/helpers.php';
```

### Issue: `helpers.php` `isLoggedIn()` checks `$_SESSION['staff_id']` but login sets `staff_id`

Verify your login handler sets `$_SESSION['staff_id']` (not `admin_id` or `user_id`). The guard function and session key must match exactly.

### Issue: `OrderModel::create()` applies VAT on top of product prices

Products already store a `selling_price` that may or may not include VAT. Confirm your pricing policy and adjust `$tax = round($sub * VAT_RATE, 2)` accordingly — or set `VAT_RATE = 0` if prices are inclusive.

---

## 14. Deployment guide

### Shared hosting (cPanel)

1. Upload files via FTP/FileManager to `public_html/bidhaabora/`
2. Create MySQL database and user in cPanel → MySQL Databases
3. Import `schema.sql` via phpMyAdmin
4. Edit `config/config.php` with production credentials
5. Set `APP_ENV = 'production'`
6. Point domain/subdomain to the folder

### VPS (Ubuntu + Apache)

```bash
# Install LAMP
sudo apt update && sudo apt install -y apache2 php8.2 php8.2-mysql php8.2-curl php8.2-mbstring mysql-server

# Enable mod_rewrite
sudo a2enmod rewrite && sudo systemctl restart apache2

# Deploy app
sudo cp -r bidhaabora /var/www/html/
sudo chown -R www-data:www-data /var/www/html/bidhaabora
sudo chmod 755 /var/www/html/bidhaabora/public/uploads

# Import schema
mysql -u root -p < /var/www/html/bidhaabora/sql/schema.sql

# Set up SSL (Certbot)
sudo certbot --apache -d yourdomain.co.ke
```

### Environment variables (recommended over `config.php` edits)

```bash
# /etc/apache2/sites-available/bidhaabora.conf
SetEnv DB_PASS "strongpasswordhere"
SetEnv DARAJA_CONSUMER_KEY "yourkey"
SetEnv DARAJA_CONSUMER_SECRET "yoursecret"
```

Then in `config.php`:

```php
define('DB_PASS', getenv('DB_PASS'));
```

---

## 15. Changelog

### v2.0.0 — 2026
- Complete rewrite with modular PHP class architecture
- Multi-branch inventory support with `branch_stock` table
- Full M-Pesa Daraja STK Push integration with callback handling
- Online storefront with cart, checkout, and order tracking
- 76 seeded products across 15 categories
- 9 seeded suppliers
- 7 database triggers for automatic stock management
- 3 stored procedures for POS and reporting
- 4 database views for efficient querying
- Dark/light theme support in single-page frontend
- Audit log for all significant data mutations
- Role-based access control with JSON permissions

---

*BidhaaBora · Built for Kenyan grocery retailers · MySQL 8.0+ / MariaDB 10.6+*
