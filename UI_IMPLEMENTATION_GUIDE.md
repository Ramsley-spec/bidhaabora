# 🎨 BidhaaBora — UI Implementation Guide

> Complete reference for the frontend architecture, design system, page structure, and JavaScript patterns used in `web/index.html`.  
> **Version 2.0.0** · Single-file SPA · No build step required

---

## Table of contents

1. [Architecture overview](#1-architecture-overview)
2. [Design system](#2-design-system)
3. [CSS utility classes](#3-css-utility-classes)
4. [Page / view structure](#4-page--view-structure)
5. [Landing page](#5-landing-page)
6. [Storefront (customer)](#6-storefront-customer)
7. [Admin login](#7-admin-login)
8. [Admin workspace](#8-admin-workspace)
9. [JavaScript modules](#9-javascript-modules)
10. [Component patterns](#10-component-patterns)
11. [State management](#11-state-management)
12. [Responsive behaviour](#12-responsive-behaviour)
13. [Dark / light theme](#13-dark--light-theme)
14. [Connecting to the PHP backend](#14-connecting-to-the-php-backend)
15. [Known UI issues & fixes](#15-known-ui-issues--fixes)
16. [Next enhancements](#16-next-enhancements)

---

## 1. Architecture overview

The entire frontend lives in a **single HTML file** (`web/index.html`). There is no build step, no bundler, and no framework dependency — just CSS custom properties, vanilla JavaScript, and Google Fonts loaded from CDN.

```
web/index.html
│
├── <style> block          — All CSS (≈ 700 lines, CSS custom properties)
│
├── #landing               — Public landing page
│   ├── .l-nav             — Sticky navigation bar
│   ├── .l-hero            — Full-viewport hero with Unsplash background
│   ├── .l-features        — 3-column feature grid
│   ├── .l-prods           — Product showcase grid
│   └── .l-footer          — 4-column footer
│
├── #storefront            — Customer-facing shop (no login required)
│   ├── .s-nav             — Sticky storefront nav with cart badge
│   └── .s-body            — Page container
│       ├── [data-page=home]       — Hero + featured products
│       ├── [data-page=shop]       — Full product grid + filters
│       ├── [data-page=cart]       — Cart items + summary
│       ├── [data-page=checkout]   — 2-column checkout form
│       └── [data-page=orders]     — Customer order history
│
├── #adminLogin            — Full-viewport staff login
│
├── #adminWS               — Admin workspace (session-gated)
│   ├── .ws-top            — Top bar: brand, staff chip, controls
│   ├── .ws-sidebar        — Left navigation (sticky, scrollable)
│   └── .ws-content        — Page area
│       ├── .ws-pg[data-admin=dashboard]
│       ├── .ws-pg[data-admin=pos]
│       ├── .ws-pg[data-admin=products]
│       ├── .ws-pg[data-admin=inventory]
│       ├── .ws-pg[data-admin=purchases]
│       ├── .ws-pg[data-admin=suppliers]
│       ├── .ws-pg[data-admin=customers]
│       ├── .ws-pg[data-admin=orders]
│       ├── .ws-pg[data-admin=alerts]
│       ├── .ws-pg[data-admin=reports]
│       ├── .ws-pg[data-admin=branches]
│       ├── .ws-pg[data-admin=mpesa]
│       └── .ws-pg[data-admin=audit]
│
└── <script> block         — All JS (≈ 600 lines, vanilla ES2022)
```

**View switching** is achieved by toggling `display` on the four top-level containers (`#landing`, `#storefront`, `#adminLogin`, `#adminWS`) and by adding/removing the `.active` class on `.s-pg` / `.ws-pg` children.

---

## 2. Design system

### Colour tokens (CSS custom properties)

All colours are defined as CSS variables on `:root` and overridden on `body.dark`. This gives instant theme switching without any JavaScript DOM manipulation beyond toggling `body.dark`.

#### Light mode

| Token | Value | Usage |
|---|---|---|
| `--bg` | `#f5f0e8` | Page background |
| `--bg2` | `#ede4d3` | Secondary background |
| `--panel` | `#fffcf5` | Cards, modals, sidebar |
| `--panel2` | `#fffaf0` | Input backgrounds, table headers |
| `--ink` | `#1a1f2e` | Primary text |
| `--muted` | `#6b7280` | Secondary text, labels |
| `--accent` | `#145f4a` | Primary green — buttons, links, active states |
| `--accent2` | `#b85c0d` | Amber — deal prices, warnings |
| `--accent3` | `#1e3a5f` | Deep blue — info states |
| `--line` | `#e2d9c8` | Borders, dividers |
| `--shadow` | `0 12px 40px rgba(20,31,46,.1)` | Card elevation |
| `--danger` | `#dc2626` | Errors, out-of-stock |
| `--success` | `#15803d` | Positive states |
| `--warning` | `#d97706` | Low stock, pending |
| `--info` | `#2563eb` | Info badges |

#### Dark mode overrides (`body.dark`)

| Token | Value |
|---|---|
| `--bg` | `#0d1117` |
| `--panel` | `#1c2333` |
| `--ink` | `#e6edf3` |
| `--accent` | `#3fb950` (brighter green) |
| `--accent2` | `#ffa657` |
| `--line` | `#30363d` |

### Typography

| Variable | Font | Usage |
|---|---|---|
| `--font` | DM Sans (300–700) | All body text, buttons, labels |
| `--font-serif` | DM Serif Display | Headings (`h1`–`h4`), KPI values, brand name |
| `--font-mono` | JetBrains Mono | Receipt output, barcodes, code references |

### Spacing & radius

| Token | Value |
|---|---|
| `--radius` | `20px` — large cards, modals, hero sections |
| Border radius (inline) | `16px` — table cards; `14px` — product cards; `10px` — tags; `8px` — buttons |

### Animation

```css
@keyframes fadeUp {
  from { opacity: 0; transform: translateY(12px); }
  to   { opacity: 1; transform: translateY(0); }
}
@keyframes pulse {
  0%, 100% { opacity: 1; }
  50%       { opacity: .6; }
}
```

Apply `fadeUp` to new views: `.ws-pg.active { animation: fadeUp .22s ease }`.  
Apply `pulse` to loading indicators or live alert dots.

---

## 3. CSS utility classes

### Buttons

| Class | Description |
|---|---|
| `.btn` | Primary — solid green (`--accent`) |
| `.btn2` | Secondary — solid amber (`--accent2`) |
| `.btn3` | Ghost — white/panel with border |
| `.btn-danger` | Destructive — solid red |
| `.btn-sm` | 34 px height, smaller padding |
| `.btn-xs` | 28 px height, minimal padding |

All buttons share `inline-flex`, `gap: 7px`, `transition: all .18s`, and a `translateY(-1px)` hover lift.

### Badges

| Class | Colour |
|---|---|
| `.b-green` | Translucent green — In Stock, Active |
| `.b-amber` | Translucent amber — Low Stock, Pending |
| `.b-red` | Translucent red — Out of Stock, Critical |
| `.b-blue` | Translucent blue — Info, Processing |
| `.b-muted` | Translucent grey — Inactive, Unknown |
| `.b-purple` | Translucent purple — Wholesale, Special |

### Form inputs

Wrap each field in `.fg` (grid, 5 px gap) with a `<label>` and an `<input>` / `<select>` / `<textarea>`. Focus state adds `border-color: var(--accent)` + a soft box shadow.

Multi-column form layouts:

```html
<div class="form-2"> <!-- two equal columns -->
<div class="form-3"> <!-- three equal columns -->
```

### Cards

| Class | Usage |
|---|---|
| `.card` | Padded panel with border + shadow |
| `.tcard` | Table container (no padding, overflow hidden) |
| `.tcard-hdr` | Header row for `.tcard` with flex space-between |
| `.twrap` | Inner scrollable wrapper for `<table>` |
| `.kpi` | Metric card — accent top-border strip, serif value |
| `.chart-wrap` | Wraps a bar chart with label and bars |
| `.branch-card` | Branch summary panel with green top strip |

### KPI modifiers

```html
<div class="kpi">           <!-- default — green accent strip -->
<div class="kpi warn">      <!-- amber strip -->
<div class="kpi danger">    <!-- red strip -->
<div class="kpi info">      <!-- blue strip -->
```

---

## 4. Page / view structure

### Top-level view IDs

| ID | Shown when |
|---|---|
| `#landing` | Default — public visitor |
| `#storefront` | Customer taps "Shop" |
| `#adminLogin` | Staff taps "Staff Login" |
| `#adminWS` | After successful admin authentication |

Switch with:

```javascript
function showView(id) {
  ['landing','storefront','adminLogin','adminWS'].forEach(v => {
    document.getElementById(v).style.display = 'none';
  });
  document.getElementById(id).style.display = v === 'adminWS' ? 'block' : 'flex'; // or 'block'
}
```

### Storefront pages (`data-page` attribute)

```javascript
function storefrontPage(name) {
  document.querySelectorAll('.s-pg').forEach(p => p.classList.remove('active'));
  document.querySelector(`.s-pg[data-page="${name}"]`).classList.add('active');
  document.querySelectorAll('.snl').forEach(b => b.classList.remove('active'));
  document.querySelector(`.snl[data-goto="${name}"]`)?.classList.add('active');
}
```

### Admin workspace pages (`data-admin` attribute)

```javascript
function adminPage(name) {
  document.querySelectorAll('.ws-pg').forEach(p => p.classList.remove('active'));
  document.querySelector(`.ws-pg[data-admin="${name}"]`).classList.add('active');
  document.querySelectorAll('.ws-nav-btn').forEach(b => b.classList.remove('active'));
  document.querySelector(`.ws-nav-btn[data-admin="${name}"]`)?.classList.add('active');
}
```

---

## 5. Landing page

### Sections

#### `.l-nav` — sticky navigation
- Brand mark (`🌿` in a 38 px green tile) + "BidhaaBora" serif wordmark
- Right: theme toggle, "🛍️ Shop" button, "⚙️ Staff Login" button
- `backdrop-filter: blur(16px)` + semi-transparent background for scroll effect

#### `.l-hero` — full-viewport hero
- Unsplash grocery image (`photo-1542838132-92c53300491e`) at 38% brightness
- Linear gradient overlay darkens bottom half
- Animated tag pill + large serif headline with `<em>` in `#6ee7b7`
- Two CTA buttons side by side

#### `.l-features` — feature grid
- `repeat(auto-fit, minmax(300px, 1fr))`
- Six `.l-fc` cards: icon tile + heading + description
- Hover: `translateY(-5px)` + shadow + accent border

#### `.l-prods` — product showcase
- Loads top-selling products via `fetch('/api/products.php?top=1')`
- Same `.pcard` grid as the storefront shop page
- "Shop All" CTA links to storefront

#### `.l-footer` — 4-column dark footer
- `#0b1220` background, `#94a3b8` link text
- Columns: brand + tagline, Quick Links, Contact, Social
- Copyright bar with `justify-content: space-between`

---

## 6. Storefront (customer)

No login required. Cart is stored in `localStorage` as a JSON array.

### `.s-nav` — sticky storefront bar
- Left: brand + hamburger (mobile) + nav pills (hidden on mobile)
- Right: cart button with `.cart-dot` badge showing item count

### Pages

#### `[data-page=home]` — Storefront home
- `.shero` — mini hero with grocery photo background, search bar
- `.sec-title` + `.prod-grid` — featured products (tagged `is_top_sell = 1`)
- Deal timer countdown using `setInterval` on `.pcard-timer` elements

#### `[data-page=shop]` — Full product catalogue
- Category filter pills (All, Grains, Dairy, etc.)
- Search input filters `.pcard` elements client-side
- `.prod-grid` — `auto-fill` grid, minimum 210 px columns
- Each `.pcard`:
  - `.pcard-disc-ribbon` — shown when `discount_pct > 0`
  - `.pcard-timer` — countdown if `deal_timer_hrs > 0`
  - Add-to-cart button disabled when `stock_qty ≤ 0`

#### `[data-page=cart]` — Shopping cart
- Empty state: large cart emoji + "Your cart is empty" + Shop CTA
- Populated: `.cart-item-row` list with `.qty-ctrl` (− qty + buttons)
- `.cart-summary-box` — subtotal, delivery, VAT, total
- "Proceed to Checkout" → `storefrontPage('checkout')`

#### `[data-page=checkout]` — Order form
- `.co-grid` — 2-column on desktop, stacked on mobile
- Left: delivery details form + delivery method radios + payment method radios
- Right: sticky `.co-card` order summary
- On submit: `POST /api/orders.php` with cart JSON
- Success: `.order-ok` confirmation panel with order number

#### `[data-page=orders]` — Customer order history
- Filtered by `localStorage.customerId` (if registered) or `localStorage.phone`
- Table: order number, date, total, payment method, status badge

### Cart state

```javascript
// localStorage key: 'bh_cart'
// Shape: [{ id, name, price, qty, icon, discount_pct }, ...]

function addToCart(product) { ... }
function removeFromCart(id) { ... }
function updateQty(id, delta) { ... }
function getCartTotal() { ... }  // returns { subtotal, discount, vat, total, delivery }
function renderCart() { ... }
function updateCartBadge() {
  document.querySelector('.cart-dot').textContent = getTotalQty();
}
```

---

## 7. Admin login

### `#adminLogin` layout
- Full-viewport background: Unsplash grocery image + 75% dark overlay
- `.login-box` — centered white card (max-width 420 px, `fadeUp` animation)
- Brand row, credential hint box, error message div, form fields
- `POST /admin/login.php` — PHP session authentication

### Credential hint box (`.login-hint`)
```html
<div class="login-hint">
  <strong>Demo credentials</strong><br>
  Username: mitchellwanga | Password: Admin123!
</div>
```

### Authentication flow

```javascript
async function adminLogin(e) {
  e.preventDefault();
  const res = await fetch('/admin/login.php', {
    method: 'POST',
    body: new FormData(e.target),
  });
  const data = await res.json();
  if (data.ok) {
    sessionStorage.setItem('staff', JSON.stringify(data.staff));
    showView('adminWS');
    renderAdminWorkspace(data.staff);
  } else {
    document.querySelector('.login-err').textContent = data.error;
    document.querySelector('.login-err').style.display = 'block';
  }
}
```

The PHP login endpoint should return `{ ok: true, staff: { id, name, role, permissions } }` on success.

---

## 8. Admin workspace

### `.ws-top` — Top bar
- Left: brand mark + store name + "Grocery Manager" subtitle
- Right: `.ws-chip` (avatar initials + staff name + role) + icon buttons (alerts, theme, mobile menu, logout)

### `.ws-sidebar` — Left navigation
Four nav sections with `.ws-nav-btn` items:

| Section | Pages |
|---|---|
| Overview | Dashboard |
| Sales | POS · Sales History |
| Inventory | Products · Stock Adjustments · Purchase Orders |
| Customers & Orders | Customers · Online Orders |
| Finance & Analytics | Alerts · Reports · M-Pesa |
| Store Operations | Branches · Transfers · Expenses · Audit Log |

Active state: `.ws-nav-btn.active` gets `background: rgba(20,95,74,.1); color: var(--accent)`.

Alert badge (`.navbadge`) on the Alerts nav button shows unread count.

### Admin pages

#### `[data-admin=dashboard]`
- `.kpi-grid` — `auto-fit` grid of metric cards (Today's Revenue, Sales, Products, Low Stock, Pending Orders, Stock Value, Month Revenue, Critical Alerts)
- `.chart-wrap` — CSS bar chart for 7-month revenue trend
- `.tcard` — Recent sales table (last 10)
- `.tcard` — Low stock table (products below reorder level)
- `.tcard` — Recent online orders (last 10)

#### `[data-admin=pos]` — Point of Sale
```
.pos-layout (2 columns)
├── Left: .pos-pgrid  — clickable product tiles
│         category filter tabs above
└── Right: .pos-panel — running sale cart
              .pos-cart-body  — sale items
              .pos-totals     — subtotal, VAT, grand total
              .pos-pay        — payment method grid + charge button
```

Add to cart by clicking `.pos-pc` tile. Disabled state (`.pos-pc.out`) for zero-stock products. Payment method `.pm-btn` toggles `.active`. "Charge" button calls `sp_process_sale` via `POST /api/pos/charge.php`.

#### `[data-admin=products]`
- Toolbar: search input + category filter + supplier filter + "Add Product" button
- `.tcard` + `.twrap` — sortable product table
- Columns: SKU, Emoji, Name, Category, Buying Price, Selling Price, Margin %, Stock, Reorder, Status, Actions
- Inline edit via modal (`.modal-bg` + `.modal-box`)
- Stock status badge uses `stockStatus()` from `helpers.php`

#### `[data-admin=purchases]` — Purchase orders
- PO status stepper component per row (Draft → Submitted → Approved → Received)
- `.tcard` list of POs with supplier, branch, total, status, actions
- "New PO" modal: supplier select, branch select, expected date, line items

#### `[data-admin=alerts]`
- Three metric counts: Critical, Warning, Info
- List of `.alert-item` rows with `.alert-dot` (coloured circle), title, description, "Resolve" button
- "Resolve all" button calls `AlertModel::resolveAll()`

#### `[data-admin=mpesa]` — M-Pesa
- `.mpesa-hdr` — branded green header with total paid, pending, failed metrics
- `.mpesa-stat-row` — 3-column stat grid
- `.mpesa-form` — STK Push initiation form (PO dropdown, phone, amount, description)
- `.tcard` — transaction history table

#### `[data-admin=reports]`
`.rtab-bar` tabs:

| Tab | `rview` | Content |
|---|---|---|
| Stock summary | `rview-stock` | Category fill rates + progress bars |
| Sales | `rview-sales` | Date-range picker + CSV export + bar chart |
| Wastage log | `rview-waste` | Adjustment table filtered to Damaged/Expired/Waste |
| Supplier KPIs | `rview-supplier` | On-time rate progress bars |
| Movements | `rview-movement` | Full stock adjustment log |
| Audit log | `rview-audit` | JSON diff viewer from `audit_log` table |

---

## 9. JavaScript modules

All JS is in a single `<script>` block. Logical groups:

### Theme

```javascript
function toggleTheme() {
  document.body.classList.toggle('dark');
  localStorage.setItem('bh_theme', document.body.classList.contains('dark') ? 'dark' : 'light');
}
// On load:
if (localStorage.getItem('bh_theme') === 'dark') document.body.classList.add('dark');
```

### View navigation

```javascript
function backToLanding()   { showView('landing'); }
function showStorefront()  { showView('storefront'); loadStorefrontProducts(); }
function showAdminLogin()  { showView('adminLogin'); }
function showAdminWS()     { showView('adminWS'); loadDashboard(); }
```

### Product loading

```javascript
async function loadStorefrontProducts(filters = {}) {
  const params = new URLSearchParams(filters);
  const res = await fetch('/api/products.php?' + params);
  const products = await res.json();
  renderProductGrid(products);
}

function renderProductGrid(products) {
  const grid = document.querySelector('.prod-grid');
  grid.innerHTML = products.map(p => productCardHTML(p)).join('');
}

function productCardHTML(p) {
  const discountedPrice = p.selling_price * (1 - p.discount_pct / 100);
  return `<div class="pcard" onclick="viewProduct(${p.product_id})">
    ${p.discount_pct > 0 ? `<div class="pcard-disc-ribbon">-${p.discount_pct}%</div>` : ''}
    <div class="pcard-img">${p.icon_emoji}</div>
    <div class="pcard-body">
      <div class="pcard-name">${p.name}</div>
      <div class="pcard-cat">${p.category}</div>
      <div class="pcard-row">
        <div class="pcard-price">
          <strong>KSh ${discountedPrice.toFixed(2)}</strong>
          ${p.discount_pct > 0 ? `<s>KSh ${p.selling_price}</s>` : ''}
        </div>
      </div>
      <button class="pcard-add" ${p.stock_qty <= 0 ? 'disabled' : ''}
        onclick="event.stopPropagation(); addToCart(${JSON.stringify(p)})">
        ${p.stock_qty <= 0 ? 'Out of Stock' : '+ Add to Cart'}
      </button>
    </div>
  </div>`;
}
```

### Deal timers

```javascript
function startDealTimers() {
  document.querySelectorAll('[data-expires]').forEach(el => {
    const end = new Date(el.dataset.expires);
    const tick = () => {
      const diff = end - Date.now();
      if (diff <= 0) { el.textContent = 'Deal ended'; return; }
      const h = Math.floor(diff / 3600000);
      const m = Math.floor((diff % 3600000) / 60000);
      const s = Math.floor((diff % 60000) / 1000);
      el.textContent = `${h}h ${m}m ${s}s left`;
    };
    tick();
    setInterval(tick, 1000);
  });
}
```

### POS

```javascript
let posCart = []; // [{ id, name, price, qty, icon }]

function posAddProduct(product) {
  const existing = posCart.find(i => i.id === product.product_id);
  if (existing) { existing.qty++; }
  else posCart.push({ id: product.product_id, name: product.name, price: product.selling_price, qty: 1, icon: product.icon_emoji });
  renderPosCart();
}

async function posCharge() {
  if (!posCart.length) return;
  const method = document.querySelector('.pm-btn.active')?.dataset.method ?? 'Cash';
  const res = await fetch('/api/pos/charge.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ items: posCart, method, cashier_id: SESSION.staff_id }),
  });
  const data = await res.json();
  if (data.ok) { showReceipt(data.receipt); posCart = []; renderPosCart(); }
}
```

### Dashboard

```javascript
async function loadDashboard() {
  const res = await fetch('/api/dashboard.php');
  const kpis = await res.json();
  renderKPIs(kpis);
  renderRevenueChart(kpis.monthly_revenue);
}

function renderKPIs(kpis) {
  document.getElementById('kpi-revenue').textContent = 'KSh ' + Number(kpis.today_revenue).toLocaleString();
  document.getElementById('kpi-sales').textContent   = kpis.today_sales;
  document.getElementById('kpi-low').textContent     = kpis.low_stock;
  // ... etc.
}
```

### Alerts badge

```javascript
async function refreshAlertBadge() {
  const res = await fetch('/api/alerts.php?count=1');
  const { critical } = await res.json();
  const badge = document.querySelector('.navbadge');
  if (badge) badge.textContent = critical;
  badge.style.display = critical > 0 ? 'inline-flex' : 'none';
}
setInterval(refreshAlertBadge, 60_000); // Poll every minute
```

---

## 10. Component patterns

### Modal

```html
<div class="modal-bg" id="modal-product">
  <div class="modal-box">
    <div class="modal-hdr">
      <h3>Edit product</h3>
      <button class="modal-close" onclick="closeModal('modal-product')">✕</button>
    </div>
    <!-- form content -->
  </div>
</div>
```

```javascript
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
// Close on backdrop click:
document.querySelectorAll('.modal-bg').forEach(m =>
  m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); })
);
```

### Toast notifications

```javascript
function toast(msg, type = 'ok') {
  const el = document.createElement('div');
  el.className = `msg-${type}`;
  el.textContent = msg;
  el.style.display = 'block';
  document.body.appendChild(el);
  setTimeout(() => { el.style.opacity = '0'; setTimeout(() => el.remove(), 400); }, 3000);
}
```

### Progress bar

```html
<div style="display:flex;align-items:center;gap:10px">
  <span style="width:110px;font-size:.82rem">Dairy</span>
  <div class="progress">
    <div class="progress-fill" style="width: 72%"></div>
  </div>
  <span style="font-size:.78rem;color:var(--muted)">72%</span>
</div>
```

### CSS bar chart

```html
<div class="bar-chart">
  <div class="bar-col">
    <div class="bar-val">84K</div>
    <div class="bar-fill" style="height: 80%"></div>
    <div class="bar-label">Jan</div>
  </div>
  <!-- repeat for each month -->
</div>
```

Set `bar-fill` height as a percentage of the max value. Use `var(--accent)` for the current month, `var(--line)` for others.

### Alert item

```html
<div class="alert-item">
  <div class="alert-dot" style="background:var(--danger)"></div>
  <div class="alert-content">
    <div class="alert-title">Whole Milk — Out of stock · CBD Branch</div>
    <div class="alert-desc">0 units remaining. Reorder placed with FreshFarms.</div>
  </div>
  <div class="alert-actions">
    <button class="btn-xs btn3" onclick="resolveAlert(1)">Resolve</button>
  </div>
</div>
```

Dot colour by severity: `--danger` (Critical), `--warning` (Warning), `--info` (Info).

---

## 11. State management

State is held in plain JavaScript variables and `localStorage`. There is no reactive framework.

| State | Storage | Key |
|---|---|---|
| Theme | `localStorage` | `bh_theme` |
| Cart items | `localStorage` | `bh_cart` |
| Logged-in customer | `localStorage` | `bh_customer` |
| Staff session | PHP `$_SESSION` + `sessionStorage` | `staff` |
| POS cart | In-memory `posCart[]` | — |
| Active storefront page | DOM class `.active` | — |
| Active admin page | DOM class `.active` | — |

```javascript
// Load cart on init
let cart = JSON.parse(localStorage.getItem('bh_cart') || '[]');

// Persist on every change
function saveCart() {
  localStorage.setItem('bh_cart', JSON.stringify(cart));
  updateCartBadge();
}
```

---

## 12. Responsive behaviour

| Breakpoint | Changes |
|---|---|
| `≤ 1024px` | Admin sidebar hidden by default; hamburger button shows; POS goes single-column; checkout goes single-column; footer goes 2-column |
| `≤ 768px` | Landing hero min-height 75vh; storefront nav pills hidden (hamburger only); KPI grid goes 2-column; all form grids go 1-column; admin content padding reduced |
| `≤ 480px` | Hero CTAs stack vertically and go full-width; KPI grid goes 1-column; product grid goes 2 columns |

### Mobile sidebar (admin)

The sidebar slides in from the left on mobile using a fixed-position override when `.open` is added:

```css
.ws-sidebar.open {
  display: flex;
  position: fixed;
  top: 65px; left: 0; bottom: 0;
  z-index: 80;
  width: 240px;
  box-shadow: 4px 0 20px rgba(0,0,0,.2);
}
```

Toggle with:

```javascript
document.querySelector('.ws-sidebar').classList.toggle('open');
```

---

## 13. Dark / light theme

Theme is toggled by adding/removing `body.dark`, which activates the CSS override block. Preference is saved to `localStorage`.

```javascript
// On page load
const saved = localStorage.getItem('bh_theme') ?? 'dark';
if (saved === 'dark') document.body.classList.add('dark');

function toggleTheme() {
  const isDark = document.body.classList.toggle('dark');
  localStorage.setItem('bh_theme', isDark ? 'dark' : 'light');
  // Update theme button icon
  document.querySelectorAll('.theme-btn').forEach(b => b.textContent = isDark ? '☀️' : '🌙');
}
```

> **Note:** The default class on `<body>` in `index.html` is `class="dark"`. Change to `class=""` if you prefer light as the default.

---

## 14. Connecting to the PHP backend

The frontend calls PHP API endpoints via `fetch()`. All endpoints should return JSON. Use `jsonResponse()` from `helpers.php`.

### Required API endpoints

| Frontend action | Endpoint | Method | Returns |
|---|---|---|---|
| Load storefront products | `/api/products.php` | GET | `ProductModel::getAll()` with storefront filter |
| Create online order | `/api/orders.php` | POST | `{ ok, order_no }` |
| Admin login | `/admin/login.php` | POST | `{ ok, staff }` or `{ error }` |
| Dashboard KPIs | `/api/dashboard.php` | GET | `ReportsModel::dashboard()` |
| POS charge | `/api/pos/charge.php` | POST | `{ ok, receipt_no }` |
| Alert count | `/api/alerts.php?count=1` | GET | `{ critical, warning, info }` |
| Resolve alert | `/api/alerts.php` | POST | `{ ok }` |
| M-Pesa STK Push | `/api/mpesa/push.php` | POST | `{ ok, checkout_request_id }` |
| Daraja callback | `/api/mpesa/callback.php` | POST | `ResultCode: 0` |

### Example API file pattern

```php
<?php
// api/products.php
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/config/helpers.php';
require_once dirname(__DIR__) . '/modules/ProductModel.php';

$filters = [];
if (!empty($_GET['search']))   $filters['search']     = $_GET['search'];
if (!empty($_GET['cat_id']))   $filters['cat_id']     = (int)$_GET['cat_id'];
if (!empty($_GET['top']))      $filters['top_sell']   = true;
if (!empty($_GET['discount'])) $filters['discounted'] = true;

jsonResponse(ProductModel::getAll($filters));
```

---

## 15. Known UI issues & fixes

### `.s-pg` pages not showing on mobile

The `.s-nav-links .snl` buttons are hidden on mobile (< 768px) via `display: none`. The hamburger menu must exist and be wired to toggle `.s-mobile-menu`. Ensure `.s-mobile-menu .snl` buttons call `storefrontPage()` with the correct `data-goto` value.

### Admin sidebar not accessible after login on mobile

On first load on mobile, `.ws-sidebar` has `display: none`. Add a hamburger icon in `.ws-top` wired to toggle `.ws-sidebar.open`.

### Cart badge not updating after item removal

`updateCartBadge()` must be called inside `removeFromCart()`, `updateQty()`, and `clearCart()`. If the badge stays stale, check that `saveCart()` always calls `updateCartBadge()` at the end.

### `body.dark` default causes flash on light-mode preference

Because `<body class="dark">` is hard-coded, users who saved `bh_theme=light` see a brief dark flash before JS overrides it. **Fix:** use a `<script>` in `<head>` (before CSS loads) to apply the saved theme:

```html
<head>
  <script>
    if (localStorage.getItem('bh_theme') === 'light') document.documentElement.classList.add('light-mode');
  </script>
```

Or remove `dark` from the `<body>` default and rely entirely on the `localStorage` read at the top of your script.

### Deal timer counts to negative on expired deals

Add a guard in the timer tick:

```javascript
if (diff <= 0) {
  el.textContent = 'Deal ended';
  clearInterval(timerId);
  return;
}
```

---

## 16. Next enhancements

### Short-term (within 2 sprints)

- [ ] Paginate storefront product grid (client-side or server-side)
- [ ] Add category pill filter row on storefront shop page (visible on mobile)
- [ ] Product image upload — display `UPLOAD_URL + product_id + '.jpg'` in `.pcard-img`
- [ ] Receipt print view — styled `.receipt-print` div with `window.print()` trigger
- [ ] Toast notification system for all CRUD actions
- [ ] Barcode scanner input field in POS (USB HID scanners auto-fill `<input>` focus)
- [ ] Keyboard shortcut: `F2` → focus POS search, `F8` → open charge modal, `Escape` → close modal

### Medium-term

- [ ] Lazy-load product images with `IntersectionObserver`
- [ ] Charts using Chart.js CDN instead of CSS bar charts (line chart for revenue, doughnut for category split)
- [ ] Swahili / English language toggle (`i18n` object of translated strings)
- [ ] Customer loyalty points display in storefront (after optional login)
- [ ] Offline cart persistence when network drops (ServiceWorker + Cache API)
- [ ] Admin CSV export — generate and download via `data:text/csv` blob URL

### Long-term

- [ ] React or Vue migration for the admin workspace (keep landing + storefront as HTML)
- [ ] PWA manifest + install prompt for mobile staff
- [ ] Real-time stock updates via Server-Sent Events (SSE)
- [ ] Advanced analytics page with Chart.js: revenue vs costs, margin per category, supplier on-time rate
- [ ] Multi-tab / multi-branch dashboard with branch switcher in top bar

---

*BidhaaBora UI Implementation Guide · v2.0.0 · 2026*
