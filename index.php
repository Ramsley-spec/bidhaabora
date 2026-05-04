<?php
// ================================================================
//  BidhaaBora — Smart Grocery Management & Online Storefront
//  index.php  (single-entry-point, PHP 8.1+, MySQL 8.0+)
//  Author : Ramsley Ouma Odhiambo · 2026
// ================================================================

// ── Bootstrap ────────────────────────────────────────────────────
session_start();
define('APP_NAME',    'BidhaaBora');
define('APP_VERSION', '2.0.0');
define('APP_URL',     'http://localhost/bidhaabora');
define('CURRENCY',    'KSh');
define('VAT_RATE',    0.16);
define('APP_ENV',     'development');

// Database
define('DB_HOST',    'localhost');
define('DB_NAME',    'bidhaabora');
define('DB_USER',    'root');
define('DB_PASS',    '');
define('DB_CHARSET', 'utf8mb4');

date_default_timezone_set('Africa/Nairobi');
if (APP_ENV === 'development') { ini_set('display_errors', 1); error_reporting(E_ALL); }
else { ini_set('display_errors', 0); error_reporting(0); }

// ── DB helper ────────────────────────────────────────────────────
class DB {
    private static ?PDO $pdo = null;
    public static function conn(): PDO {
        if (self::$pdo === null) {
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
            self::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return self::$pdo;
    }
    public static function query(string $sql, array $p = []): PDOStatement { $s = self::conn()->prepare($sql); $s->execute($p); return $s; }
    public static function fetch(string $sql, array $p = []): ?array      { return self::query($sql,$p)->fetch() ?: null; }
    public static function fetchAll(string $sql, array $p = []): array    { return self::query($sql,$p)->fetchAll(); }
    public static function insert(string $sql, array $p = []): int        { self::query($sql,$p); return (int)self::conn()->lastInsertId(); }
    public static function execute(string $sql, array $p = []): int       { return self::query($sql,$p)->rowCount(); }
    public static function beginTransaction(): void { self::conn()->beginTransaction(); }
    public static function commit(): void           { self::conn()->commit(); }
    public static function rollback(): void         { self::conn()->rollBack(); }
}

// ── Helpers ──────────────────────────────────────────────────────
function e(string $s): string          { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function fmt(float $n): string         { return CURRENCY . ' ' . number_format($n, 2); }
function isLoggedIn(): bool            { return !empty($_SESSION['staff_id']); }
function hasPermission(string $p): bool{ $perms = $_SESSION['permissions'] ?? []; return !empty($perms['all']) || !empty($perms[$p]); }
function csrfToken(): string           { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function verifyCsrf(): void            { if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(403); die('Invalid CSRF.'); } }
function generateOrderNo(): string     { return 'ORD-' . date('Ymd') . '-' . str_pad(rand(1,9999),4,'0',STR_PAD_LEFT); }
function generateReceiptNo(): string   { return 'RCT-' . date('Ymd') . '-' . str_pad(rand(1,99999),5,'0',STR_PAD_LEFT); }
function logAudit(int $sid, string $action, string $module, ?int $rid=null): void {
    try { DB::query("INSERT INTO audit_log(staff_id,action,module,record_id,ip_address) VALUES(?,?,?,?,?)",[$sid,$action,$module,$rid,$_SERVER['REMOTE_ADDR']??null]); }
    catch(Throwable $e) { /* non-fatal */ }
}
function flash(string $type, string $msg): void { $_SESSION['flash'] = ['type'=>$type,'msg'=>$msg]; }
function getFlash(): ?array { if (!isset($_SESSION['flash'])) return null; $f=$_SESSION['flash']; unset($_SESSION['flash']); return $f; }

// ── DB connectivity flag ─────────────────────────────────────────
$dbOk = false;
try { DB::conn(); $dbOk = true; } catch(Throwable $e) { /* will show warning in UI */ }

// ════════════════════════════════════════════════════════════════
//  AJAX / API REQUEST HANDLING  (Content-Type: application/json)
// ════════════════════════════════════════════════════════════════
function jsonOut(mixed $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

$req = $_GET['_r'] ?? '';  // ?_r=endpoint

if ($req !== '') {
    // ── STOREFRONT: load products ─────────────────────────────────
    if ($req === 'products') {
        if (!$dbOk) jsonOut(['error'=>'DB offline'],503);
        $where=['p.is_active=1']; $params=[];
        if (!empty($_GET['cat'])) { $where[]='p.cat_id=?'; $params[]=(int)$_GET['cat']; }
        if (!empty($_GET['deals'])){ $where[]='p.discount_pct>0 AND p.stock_qty>0'; }
        if (!empty($_GET['top']))  { $where[]='p.is_top_sell=1 AND p.stock_qty>0'; }
        if (!empty($_GET['q']))    { $where[]='(p.name LIKE ? OR p.sku LIKE ?)'; $s='%'.$_GET['q'].'%'; $params[]=$s;$params[]=$s; }
        $sql = "SELECT p.product_id AS id, p.name, p.sku, p.icon_emoji AS ic,
                       p.selling_price AS sell, p.buying_price AS buy,
                       p.discount_pct AS disc, p.deal_timer_hrs AS timer,
                       p.stock_qty AS stock, p.reorder_level AS reorder,
                       p.is_top_sell AS top, p.unit,
                       c.name AS cat
                FROM products p
                LEFT JOIN categories c ON p.cat_id=c.cat_id
                WHERE ".implode(' AND ',$where)." ORDER BY p.name LIMIT 200";
        jsonOut(DB::fetchAll($sql,$params));
    }

    // ── STOREFRONT: place order ───────────────────────────────────
    if ($req === 'order' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!$dbOk) jsonOut(['ok'=>false,'error'=>'DB offline'],503);
        $body = json_decode(file_get_contents('php://input'),true) ?? [];
        $name  = trim($body['name']  ?? '');
        $phone = trim($body['phone'] ?? '');
        $addr  = trim($body['addr']  ?? '');
        $email = trim($body['email'] ?? '');
        $deliv = $body['delivery'] ?? 'pickup';
        $pay   = $body['payment']  ?? 'cod';
        $items = $body['items']    ?? [];
        if (!$name||!$phone||empty($items)) jsonOut(['ok'=>false,'error'=>'Missing required fields'],400);
        $feeMap = ['pickup'=>0,'courier'=>150,'rider'=>300];
        $fee = $feeMap[$deliv] ?? 0;
        try {
            DB::beginTransaction();
            $sub = 0; $lineItems = [];
            foreach ($items as $it) {
                $pid = (int)($it['id']??0); $qty = (int)($it['qty']??0);
                if ($pid<1||$qty<1) continue;
                $p = DB::fetch("SELECT product_id,name,selling_price,discount_pct,stock_qty FROM products WHERE product_id=? AND is_active=1",[$pid]);
                if (!$p||$p['stock_qty']<$qty) { DB::rollback(); jsonOut(['ok'=>false,'error'=>"Insufficient stock: ".($p['name']??'?')],409); }
                $price = round($p['selling_price']*(1-$p['discount_pct']/100),2);
                $lt    = round($price*$qty,2);
                $sub  += $lt;
                $lineItems[] = ['pid'=>$pid,'name'=>$p['name'],'qty'=>$qty,'price'=>$price,'lt'=>$lt];
            }
            $tax = round($sub*VAT_RATE,2);
            $tot = round($sub+$fee+$tax,2);
            $ono = generateOrderNo();
            $oid = DB::insert("INSERT INTO online_orders(order_no,cust_name,cust_phone,cust_email,delivery_addr,delivery_method,payment_method,subtotal,delivery_fee,tax_amount,total_amount,status,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,'Pending',NOW())",
                [$ono,$name,$phone,$email,$addr,$deliv,$pay,$sub,$fee,$tax,$tot]);
            foreach ($lineItems as $li) {
                DB::query("INSERT INTO order_items(order_id,product_id,quantity,unit_price) VALUES(?,?,?,?)",[$oid,$li['pid'],$li['qty'],$li['price']]);
                DB::query("UPDATE products SET stock_qty=GREATEST(0,stock_qty-?) WHERE product_id=?",[$li['qty'],$li['pid']]);
            }
            DB::commit();
            jsonOut(['ok'=>true,'order_no'=>$ono,'total'=>$tot,'tax'=>$tax,'fee'=>$fee]);
        } catch(Throwable $ex) { DB::rollback(); jsonOut(['ok'=>false,'error'=>$ex->getMessage()],500); }
    }

    // ── OPTIONAL CUSTOMER AUTH ────────────────────────────────────
    if ($req === 'cust_register' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!$dbOk) jsonOut(['ok'=>false,'error'=>'DB offline'],503);
        $body  = json_decode(file_get_contents('php://input'),true) ?? [];
        $name  = trim($body['name']  ?? '');
        $email = strtolower(trim($body['email'] ?? ''));
        $pass  = $body['pass'] ?? '';
        if (!$name||!$email||strlen($pass)<6) jsonOut(['ok'=>false,'error'=>'All fields required (password min 6 chars)'],400);
        if (DB::fetch("SELECT customer_id FROM customers WHERE email=?",[$email])) jsonOut(['ok'=>false,'error'=>'Email already registered'],409);
        $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost'=>12]);
        $cid  = DB::insert("INSERT INTO customers(full_name,email,password_hash,from_online) VALUES(?,?,?,1)",[$name,$email,$hash]);
        jsonOut(['ok'=>true,'customer_id'=>$cid,'name'=>$name]);
    }
    if ($req === 'cust_login' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!$dbOk) jsonOut(['ok'=>false,'error'=>'DB offline'],503);
        $body  = json_decode(file_get_contents('php://input'),true) ?? [];
        $email = strtolower(trim($body['email'] ?? ''));
        $pass  = $body['pass'] ?? '';
        $c = DB::fetch("SELECT customer_id,full_name,password_hash FROM customers WHERE email=? AND is_active=1",[$email]);
        if ($c && password_verify($pass,$c['password_hash'])) {
            jsonOut(['ok'=>true,'customer_id'=>$c['customer_id'],'name'=>$c['full_name']]);
        }
        jsonOut(['ok'=>false,'error'=>'Invalid email or password'],401);
    }

    // ── ADMIN: login ──────────────────────────────────────────────
    if ($req === 'admin_login' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!$dbOk) jsonOut(['ok'=>false,'error'=>'DB offline — check config.php'],503);
        $body = json_decode(file_get_contents('php://input'),true) ?? [];
        $user = trim($body['user'] ?? '');
        $pass = $body['pass'] ?? '';
        $staff = DB::fetch("SELECT s.*,r.permissions FROM staff s JOIN roles r ON s.role_id=r.role_id WHERE (s.username=? OR s.email=?) AND s.is_active=1 LIMIT 1",[$user,$user]);
        if ($staff && password_verify($pass,$staff['password_hash'])) {
            $_SESSION['staff_id']   = $staff['staff_id'];
            $_SESSION['staff_name'] = $staff['full_name'];
            $_SESSION['staff_av']   = $staff['avatar_initials'] ?? strtoupper(substr($staff['full_name'],0,2));
            $_SESSION['staff_role'] = DB::fetch("SELECT role_name FROM roles WHERE role_id=?",[$staff['role_id']])['role_name'] ?? 'Staff';
            $_SESSION['permissions']= json_decode($staff['permissions'],true) ?? [];
            DB::query("UPDATE staff SET last_login=NOW() WHERE staff_id=?",[$staff['staff_id']]);
            logAudit($staff['staff_id'],'Login','Auth');
            jsonOut(['ok'=>true,'name'=>$staff['full_name'],'av'=>$_SESSION['staff_av'],'role'=>$_SESSION['staff_role'],'perms'=>$_SESSION['permissions']]);
        }
        sleep(1);
        jsonOut(['ok'=>false,'error'=>'Invalid username or password'],401);
    }

    // ── ADMIN: logout ─────────────────────────────────────────────
    if ($req === 'admin_logout') {
        if (isLoggedIn()) logAudit($_SESSION['staff_id'],'Logout','Auth');
        session_destroy();
        jsonOut(['ok'=>true]);
    }

    // ── ADMIN: dashboard KPIs ─────────────────────────────────────
    if ($req === 'dashboard') {
        if (!$dbOk || !isLoggedIn()) jsonOut(['error'=>'Unauthorized'],401);
        jsonOut([
            'today_revenue'  => (float)(DB::fetch("SELECT IFNULL(SUM(total_amount),0) AS v FROM sales WHERE DATE(sale_date)=CURDATE() AND status='Completed'")['v']),
            'today_sales'    => (int)(DB::fetch("SELECT COUNT(*) AS v FROM sales WHERE DATE(sale_date)=CURDATE() AND status='Completed'")['v']),
            'total_products' => (int)(DB::fetch("SELECT COUNT(*) AS v FROM products WHERE is_active=1")['v']),
            'low_stock'      => (int)(DB::fetch("SELECT COUNT(*) AS v FROM products WHERE stock_qty<=reorder_level AND is_active=1")['v']),
            'stock_value'    => (float)(DB::fetch("SELECT IFNULL(SUM(stock_qty*buying_price),0) AS v FROM products WHERE is_active=1")['v']),
            'total_customers'=> (int)(DB::fetch("SELECT COUNT(*) AS v FROM customers WHERE is_active=1")['v']),
            'pending_orders' => (int)(DB::fetch("SELECT COUNT(*) AS v FROM online_orders WHERE status='Pending'")['v']),
            'month_revenue'  => (float)(DB::fetch("SELECT IFNULL(SUM(total_amount),0) AS v FROM sales WHERE DATE_FORMAT(sale_date,'%Y-%m')=DATE_FORMAT(NOW(),'%Y-%m') AND status='Completed'")['v']),
            'crit_alerts'    => (int)(DB::fetch("SELECT COUNT(*) AS v FROM alerts WHERE sev='Critical' AND is_resolved=0")['v']),
        ]);
    }

    // ── ADMIN: products list ──────────────────────────────────────
    if ($req === 'admin_products') {
        if (!$dbOk || !isLoggedIn()) jsonOut(['error'=>'Unauthorized'],401);
        $where=['p.is_active=1']; $params=[];
        if (!empty($_GET['q']))   { $where[]='(p.name LIKE ? OR p.sku LIKE ?)'; $s='%'.$_GET['q'].'%';$params[]=$s;$params[]=$s; }
        if (!empty($_GET['cat'])) { $where[]='p.cat_id=?'; $params[]=(int)$_GET['cat']; }
        $prods = DB::fetchAll("SELECT p.*,c.name AS cat_name,s.name AS supp_name FROM products p LEFT JOIN categories c ON p.cat_id=c.cat_id LEFT JOIN suppliers s ON p.supplier_id=s.supplier_id WHERE ".implode(' AND ',$where)." ORDER BY p.name LIMIT 300",$params);
        jsonOut($prods);
    }

    // ── ADMIN: save product ───────────────────────────────────────
    if ($req === 'save_product' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!$dbOk || !isLoggedIn() || !hasPermission('products')) jsonOut(['ok'=>false,'error'=>'Permission denied'],403);
        $b = json_decode(file_get_contents('php://input'),true) ?? [];
        $name = trim($b['name'] ?? '');
        $buy  = (float)($b['buy']  ?? 0);
        $sell = (float)($b['sell'] ?? 0);
        if (!$name||$buy<=0||$sell<=0) jsonOut(['ok'=>false,'error'=>'Name, buy price and sell price required'],400);
        $sku  = trim($b['sku'] ?? '') ?: 'SKU-'.strtoupper(substr(uniqid(),-5));
        $id   = (int)($b['id'] ?? 0);
        if ($id > 0) {
            DB::query("UPDATE products SET name=?,cat_id=?,supplier_id=?,icon_emoji=?,buying_price=?,selling_price=?,discount_pct=?,deal_timer_hrs=?,reorder_level=?,unit=?,is_top_sell=?,barcode=? WHERE product_id=?",
                [$name,$b['cat_id']??null,$b['supp_id']??null,$b['ic']??'📦',$buy,$sell,$b['disc']??0,$b['timer']??0,$b['reorder']??10,$b['unit']??'pcs',$b['top']??0,$b['barcode']??null,$id]);
            logAudit($_SESSION['staff_id'],'Product updated: '.$name,'Products',$id);
            jsonOut(['ok'=>true,'msg'=>'Product updated']);
        } else {
            $nid = DB::insert("INSERT INTO products(sku,name,cat_id,supplier_id,icon_emoji,buying_price,selling_price,discount_pct,deal_timer_hrs,stock_qty,reorder_level,unit,is_top_sell,barcode,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                [$sku,$name,$b['cat_id']??null,$b['supp_id']??null,$b['ic']??'📦',$buy,$sell,$b['disc']??0,$b['timer']??0,$b['stock']??0,$b['reorder']??10,$b['unit']??'pcs',$b['top']??0,$b['barcode']??null,$_SESSION['staff_id']]);
            logAudit($_SESSION['staff_id'],'Product created: '.$name,'Products',$nid);
            jsonOut(['ok'=>true,'id'=>$nid,'sku'=>$sku,'msg'=>'Product saved']);
        }
    }

    // ── ADMIN: delete product ─────────────────────────────────────
    if ($req === 'del_product' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!$dbOk || !isLoggedIn() || !hasPermission('all')) jsonOut(['ok'=>false,'error'=>'Permission denied'],403);
        $b = json_decode(file_get_contents('php://input'),true) ?? [];
        $id = (int)($b['id']??0);
        DB::execute("UPDATE products SET is_active=0 WHERE product_id=?",[$id]);
        logAudit($_SESSION['staff_id'],'Product deleted','Products',$id);
        jsonOut(['ok'=>true]);
    }

    // ── ADMIN: quick stock update ─────────────────────────────────
    if ($req === 'quick_stock' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!$dbOk || !isLoggedIn()) jsonOut(['ok'=>false,'error'=>'Unauthorized'],401);
        $b   = json_decode(file_get_contents('php://input'),true) ?? [];
        $id  = (int)($b['id']  ?? 0);
        $qty = (float)($b['qty'] ?? -1);
        if ($id<1||$qty<0) jsonOut(['ok'=>false,'error'=>'Invalid data'],400);
        DB::execute("UPDATE products SET stock_qty=? WHERE product_id=?",[$qty,$id]);
        logAudit($_SESSION['staff_id'],"Stock set to $qty",'Products',$id);
        jsonOut(['ok'=>true]);
    }

    // ── ADMIN: categories ─────────────────────────────────────────
    if ($req === 'categories') {
        if (!$dbOk || !isLoggedIn()) jsonOut(['error'=>'Unauthorized'],401);
        jsonOut(DB::fetchAll("SELECT cat_id,name,icon FROM categories ORDER BY name"));
    }

    // ── ADMIN: suppliers list ─────────────────────────────────────
    if ($req === 'suppliers') {
        if (!$dbOk || !isLoggedIn()) jsonOut(['error'=>'Unauthorized'],401);
        jsonOut(DB::fetchAll("SELECT * FROM suppliers ORDER BY name"));
    }

    // ── ADMIN: save supplier ──────────────────────────────────────
    if ($req === 'save_supplier' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!$dbOk || !isLoggedIn()) jsonOut(['ok'=>false,'error'=>'Unauthorized'],401);
        $b = json_decode(file_get_contents('php://input'),true) ?? [];
        $name = trim($b['name'] ?? '');
        if (!$name) jsonOut(['ok'=>false,'error'=>'Name required'],400);
        $id = (int)($b['id']??0);
        if ($id>0) {
            DB::execute("UPDATE suppliers SET name=?,contact_name=?,email=?,phone=?,categories=?,lead_time=?,status=? WHERE supplier_id=?",
                [$name,$b['contact']??null,$b['email']??null,$b['phone']??null,$b['cats']??null,$b['lead']??'2 days',$b['status']??'Active',$id]);
            logAudit($_SESSION['staff_id'],'Supplier updated: '.$name,'Suppliers',$id);
        } else {
            $nid = DB::insert("INSERT INTO suppliers(name,contact_name,email,phone,categories,lead_time,status) VALUES(?,?,?,?,?,?,?)",
                [$name,$b['contact']??null,$b['email']??null,$b['phone']??null,$b['cats']??null,$b['lead']??'2 days','Active']);
            logAudit($_SESSION['staff_id'],'Supplier added: '.$name,'Suppliers',$nid);
        }
        jsonOut(['ok'=>true]);
    }

    // ── ADMIN: customers ──────────────────────────────────────────
    if ($req === 'customers') {
        if (!$dbOk || !isLoggedIn()) jsonOut(['error'=>'Unauthorized'],401);
        $q = $_GET['q'] ?? '';
        if ($q) jsonOut(DB::fetchAll("SELECT customer_id,full_name,email,phone,customer_type,loyalty_pts,total_spent,from_online FROM customers WHERE is_active=1 AND (full_name LIKE ? OR phone LIKE ? OR email LIKE ?) ORDER BY full_name LIMIT 100",['%'.$q.'%','%'.$q.'%','%'.$q.'%']));
        jsonOut(DB::fetchAll("SELECT customer_id,full_name,email,phone,customer_type,loyalty_pts,total_spent,from_online FROM customers WHERE is_active=1 ORDER BY full_name LIMIT 100"));
    }

    // ── ADMIN: save customer ──────────────────────────────────────
    if ($req === 'save_customer' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!$dbOk || !isLoggedIn()) jsonOut(['ok'=>false,'error'=>'Unauthorized'],401);
        $b = json_decode(file_get_contents('php://input'),true) ?? [];
        $name  = trim($b['name']  ?? '');
        $phone = trim($b['phone'] ?? '');
        if (!$name||!$phone) jsonOut(['ok'=>false,'error'=>'Name and phone required'],400);
        $nid = DB::insert("INSERT INTO customers(full_name,email,phone,customer_type,from_online) VALUES(?,?,?,?,0)",
            [$name,$b['email']??null,$phone,$b['type']??'Retail']);
        logAudit($_SESSION['staff_id'],'Customer added: '.$name,'Customers',$nid);
        jsonOut(['ok'=>true,'id'=>$nid]);
    }

    // ── ADMIN: POS — process sale ─────────────────────────────────
    if ($req === 'pos_sale' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!$dbOk || !isLoggedIn()) jsonOut(['ok'=>false,'error'=>'Unauthorized'],401);
        $b     = json_decode(file_get_contents('php://input'),true) ?? [];
        $items = $b['items'] ?? [];
        $meth  = $b['method'] ?? 'Cash';
        $paid  = (float)($b['paid'] ?? 0);
        $mpesa = $b['mpesa'] ?? '';
        if (empty($items)) jsonOut(['ok'=>false,'error'=>'Empty sale'],400);
        try {
            DB::beginTransaction();
            $rct = generateReceiptNo();
            $sid = DB::insert("INSERT INTO sales(receipt_no,branch_id,cashier_id,payment_method,amount_paid,mpesa_code,status,sale_date) VALUES(?,?,?,?,?,?,'Completed',NOW())",
                [$rct,1,$_SESSION['staff_id'],$meth,$paid,$mpesa?:null]);
            $sub=0; $lineItems=[];
            foreach ($items as $it) {
                $pid=(int)($it['id']??0);$qty=(float)($it['qty']??0);$pr=(float)($it['price']??0);$disc=(float)($it['disc']??0);
                if ($pid<1||$qty<=0) continue;
                $p = DB::fetch("SELECT stock_qty FROM products WHERE product_id=?",[$pid]);
                if (!$p||$p['stock_qty']<$qty) { DB::rollback(); jsonOut(['ok'=>false,'error'=>"Insufficient stock for product ID $pid"],409); }
                DB::query("INSERT INTO sale_items(sale_id,product_id,quantity,unit_price,discount_pct) VALUES(?,?,?,?,?)",[$sid,$pid,$qty,$pr,$disc]);
                $lt=round($qty*$pr*(1-$disc/100),2);$sub+=$lt;
                $lineItems[]=compact('pid','qty','pr','disc','lt');
            }
            DB::commit();
            logAudit($_SESSION['staff_id'],"Sale $rct processed",'Sales',$sid);
            $tax=round($sub*VAT_RATE,2);$tot=round($sub+$tax,2);$change=round($paid-$tot,2);
            jsonOut(['ok'=>true,'receipt'=>$rct,'sub'=>$sub,'tax'=>$tax,'total'=>$tot,'paid'=>$paid,'change'=>max(0,$change),'sid'=>$sid]);
        } catch(Throwable $ex) { DB::rollback(); jsonOut(['ok'=>false,'error'=>$ex->getMessage()],500); }
    }

    // ── ADMIN: sales history ──────────────────────────────────────
    if ($req === 'sales') {
        if (!$dbOk || !isLoggedIn()) jsonOut(['error'=>'Unauthorized'],401);
        $sales = DB::fetchAll("SELECT s.sale_id,s.receipt_no,s.subtotal,s.tax_amount,s.total_amount,s.amount_paid,s.change_given,s.payment_method,s.mpesa_code,s.status,s.sale_date,st.full_name AS cashier FROM sales s JOIN staff st ON s.cashier_id=st.staff_id ORDER BY s.sale_date DESC LIMIT 100");
        jsonOut($sales);
    }

    // ── ADMIN: stock adjustment ───────────────────────────────────
    if ($req === 'stock_adj' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!$dbOk || !isLoggedIn()) jsonOut(['ok'=>false,'error'=>'Unauthorized'],401);
        $b   = json_decode(file_get_contents('php://input'),true) ?? [];
        $pid = (int)($b['pid']??0); $type=$b['type']??''; $qty=(float)($b['qty']??0); $rsn=trim($b['reason']??'');
        if (!$pid||!$qty||!$rsn||!$type) jsonOut(['ok'=>false,'error'=>'All fields required'],400);
        $nid = DB::insert("INSERT INTO stock_adjustments(product_id,adj_type,quantity,reason,reference,adjusted_by,adj_date) VALUES(?,?,?,?,?,?,NOW())",
            [$pid,$type,$qty,$rsn,$b['ref']??null,$_SESSION['staff_id']]);
        $p = DB::fetch("SELECT stock_qty,name FROM products WHERE product_id=?",[$pid]);
        logAudit($_SESSION['staff_id'],"Stock adj: $type $qty for {$p['name']}",'Inventory',$pid);
        jsonOut(['ok'=>true,'new_stock'=>$p['stock_qty']]);
    }

    // ── ADMIN: adjustments history ────────────────────────────────
    if ($req === 'adjustments') {
        if (!$dbOk || !isLoggedIn()) jsonOut(['error'=>'Unauthorized'],401);
        jsonOut(DB::fetchAll("SELECT a.*,p.name AS product_name,st.full_name AS by_name FROM stock_adjustments a JOIN products p ON a.product_id=p.product_id JOIN staff st ON a.adjusted_by=st.staff_id ORDER BY a.adj_date DESC LIMIT 80"));
    }

    // ── ADMIN: online orders ──────────────────────────────────────
    if ($req === 'online_orders') {
        if (!$dbOk || !isLoggedIn()) jsonOut(['error'=>'Unauthorized'],401);
        $status = $_GET['status'] ?? '';
        if ($status) jsonOut(DB::fetchAll("SELECT * FROM online_orders WHERE status=? ORDER BY created_at DESC LIMIT 100",[$status]));
        jsonOut(DB::fetchAll("SELECT * FROM online_orders ORDER BY created_at DESC LIMIT 100"));
    }

    // ── ADMIN: update order status ────────────────────────────────
    if ($req === 'upd_order' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!$dbOk || !isLoggedIn()) jsonOut(['ok'=>false,'error'=>'Unauthorized'],401);
        $b  = json_decode(file_get_contents('php://input'),true) ?? [];
        $id = (int)($b['id']??0); $st=trim($b['status']??'');
        $allowed=['Pending','Confirmed','Dispatched','Delivered','Cancelled'];
        if (!$id||!in_array($st,$allowed)) jsonOut(['ok'=>false,'error'=>'Invalid data'],400);
        DB::execute("UPDATE online_orders SET status=? WHERE order_id=?",[$st,$id]);
        logAudit($_SESSION['staff_id'],"Order #$id → $st",'Orders',$id);
        jsonOut(['ok'=>true]);
    }

    // ── ADMIN: alerts ─────────────────────────────────────────────
    if ($req === 'alerts') {
        if (!$dbOk || !isLoggedIn()) jsonOut(['error'=>'Unauthorized'],401);
        jsonOut(DB::fetchAll("SELECT * FROM alerts WHERE is_resolved=0 ORDER BY FIELD(sev,'Critical','Warning','Info'),created_at DESC LIMIT 50"));
    }
    if ($req === 'resolve_alert' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!$dbOk || !isLoggedIn()) jsonOut(['ok'=>false,'error'=>'Unauthorized'],401);
        $b  = json_decode(file_get_contents('php://input'),true) ?? [];
        $id = (int)($b['id']??0); $all=(bool)($b['all']??false);
        if ($all) { DB::execute("UPDATE alerts SET is_resolved=1,resolved_by=?,resolved_at=NOW() WHERE is_resolved=0",[$_SESSION['staff_id']]); }
        elseif ($id>0) { DB::execute("UPDATE alerts SET is_resolved=1,resolved_by=?,resolved_at=NOW() WHERE alert_id=?",[$_SESSION['staff_id'],$id]); }
        logAudit($_SESSION['staff_id'],$all?'All alerts resolved':"Alert $id resolved",'Alerts');
        jsonOut(['ok'=>true]);
    }

    // ── ADMIN: reports / monthly revenue ─────────────────────────
    if ($req === 'monthly_revenue') {
        if (!$dbOk || !isLoggedIn()) jsonOut(['error'=>'Unauthorized'],401);
        jsonOut(DB::fetchAll("SELECT DATE_FORMAT(sale_date,'%b %y') AS month,SUM(total_amount) AS revenue,SUM(tax_amount) AS vat,COUNT(*) AS txns FROM sales WHERE status='Completed' AND sale_date>=DATE_SUB(NOW(),INTERVAL 7 MONTH) GROUP BY DATE_FORMAT(sale_date,'%Y-%m') ORDER BY DATE_FORMAT(sale_date,'%Y-%m')"));
    }

    // ── ADMIN: top products ───────────────────────────────────────
    if ($req === 'top_products') {
        if (!$dbOk || !isLoggedIn()) jsonOut(['error'=>'Unauthorized'],401);
        jsonOut(DB::fetchAll("SELECT p.name,p.icon_emoji,c.name AS cat,SUM(si.quantity) AS sold,SUM(si.line_total) AS revenue FROM sale_items si JOIN products p ON si.product_id=p.product_id LEFT JOIN categories c ON p.cat_id=c.cat_id GROUP BY p.product_id ORDER BY revenue DESC LIMIT 20"));
    }

    // ── ADMIN: purchase orders ────────────────────────────────────
    if ($req === 'purchases') {
        if (!$dbOk || !isLoggedIn()) jsonOut(['error'=>'Unauthorized'],401);
        jsonOut(DB::fetchAll("SELECT po.*,s.name AS supplier_name,st.full_name AS raised_by FROM purchase_orders po JOIN suppliers s ON po.supplier_id=s.supplier_id JOIN staff st ON po.created_by=st.staff_id ORDER BY po.created_at DESC LIMIT 50"));
    }

    // ── ADMIN: M-Pesa transactions ────────────────────────────────
    if ($req === 'mpesa_txns') {
        if (!$dbOk || !isLoggedIn()) jsonOut(['error'=>'Unauthorized'],401);
        jsonOut(DB::fetchAll("SELECT * FROM mpesa_transactions ORDER BY created_at DESC LIMIT 50"));
    }

    // ── ADMIN: branches ───────────────────────────────────────────
    if ($req === 'branches') {
        if (!$dbOk || !isLoggedIn()) jsonOut(['error'=>'Unauthorized'],401);
        jsonOut(DB::fetchAll("SELECT * FROM branches ORDER BY name"));
    }

    // ── ADMIN: staff list ─────────────────────────────────────────
    if ($req === 'staff_list') {
        if (!$dbOk || !isLoggedIn()) jsonOut(['error'=>'Unauthorized'],401);
        jsonOut(DB::fetchAll("SELECT s.staff_id,s.full_name,s.username,s.email,s.avatar_initials,s.is_active,s.last_login,r.role_name FROM staff s JOIN roles r ON s.role_id=r.role_id ORDER BY s.full_name"));
    }

    // ── ADMIN: audit log ──────────────────────────────────────────
    if ($req === 'audit') {
        if (!$dbOk || !isLoggedIn()) jsonOut(['error'=>'Unauthorized'],401);
        jsonOut(DB::fetchAll("SELECT l.*,st.full_name FROM audit_log l LEFT JOIN staff st ON l.staff_id=st.staff_id ORDER BY l.logged_at DESC LIMIT 100"));
    }

    // ── Daraja M-Pesa STK Push ─────────────────────────────────────
    if ($req === 'mpesa_stk' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!$dbOk || !isLoggedIn()) jsonOut(['ok'=>false,'error'=>'Unauthorized'],401);
        $b     = json_decode(file_get_contents('php://input'),true) ?? [];
        $phone = preg_replace('/\D/','',trim($b['phone']??''));
        if (strlen($phone)===9) $phone='254'.$phone;
        elseif (substr($phone,0,1)==='0') $phone='254'.substr($phone,1);
        $amount = (int)($b['amount']??0);
        $desc   = trim($b['desc']??'BidhaaBora Payment');
        $poId   = (int)($b['po_id']??0);
        // Get Daraja token (requires real credentials in config)
        $ck = defined('DARAJA_CONSUMER_KEY') ? DARAJA_CONSUMER_KEY : '';
        $cs = defined('DARAJA_CONSUMER_SECRET') ? DARAJA_CONSUMER_SECRET : '';
        $shortcode = defined('DARAJA_SHORTCODE') ? DARAJA_SHORTCODE : '174379';
        $passkey   = defined('DARAJA_PASSKEY') ? DARAJA_PASSKEY : '';
        $env       = defined('DARAJA_ENV') ? DARAJA_ENV : 'sandbox';
        $authUrl   = $env==='production' ? 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials' : 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
        $stkUrl    = $env==='production' ? 'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest' : 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest';
        $cbUrl     = APP_URL.'/index.php?_r=mpesa_callback';
        if (!$ck||$ck==='YOUR_CONSUMER_KEY_HERE') {
            jsonOut(['ok'=>false,'error'=>'Daraja credentials not configured. Edit config.php.'],503);
        }
        try {
            $creds = base64_encode("$ck:$cs");
            $ch=curl_init($authUrl); curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>["Authorization: Basic $creds"],CURLOPT_RETURNTRANSFER=>true,CURLOPT_SSL_VERIFYPEER=>($env==='production')]);
            $tok = json_decode(curl_exec($ch),true); curl_close($ch);
            $token=$tok['access_token']??'';
            if (!$token) jsonOut(['ok'=>false,'error'=>'Failed to get Daraja access token'],502);
            $ts  = date('YmdHis');
            $pwd = base64_encode($shortcode.$passkey.$ts);
            $payload=['BusinessShortCode'=>$shortcode,'Password'=>$pwd,'Timestamp'=>$ts,'TransactionType'=>'CustomerPayBillOnline','Amount'=>$amount,'PartyA'=>$phone,'PartyB'=>$shortcode,'PhoneNumber'=>$phone,'CallBackURL'=>$cbUrl,'AccountReference'=>'BidhaaBora','TransactionDesc'=>substr($desc,0,20)];
            $ch=curl_init($stkUrl); curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>["Authorization: Bearer $token","Content-Type: application/json"],CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_RETURNTRANSFER=>true,CURLOPT_SSL_VERIFYPEER=>($env==='production')]);
            $res=json_decode(curl_exec($ch),true); curl_close($ch);
            if (!empty($res['CheckoutRequestID'])) {
                DB::insert("INSERT INTO mpesa_transactions(po_id,txn_type,phone_number,amount,description,daraja_ref,status) VALUES(?,?,?,?,?,?,'Pending')",[$poId?:null,'STK_Push',$phone,$amount,$desc,$res['CheckoutRequestID']]);
            }
            jsonOut(array_merge(['ok'=>!empty($res['CheckoutRequestID'])],$res));
        } catch(Throwable $ex) { jsonOut(['ok'=>false,'error'=>$ex->getMessage()],500); }
    }

    // ── Daraja callback ───────────────────────────────────────────
    if ($req === 'mpesa_callback' && $_SERVER['REQUEST_METHOD']==='POST') {
        $raw = json_decode(file_get_contents('php://input'),true) ?? [];
        $cb  = $raw['Body']['stkCallback'] ?? [];
        $ref = $cb['CheckoutRequestID'] ?? null;
        if ($ref && $dbOk) {
            if (($cb['ResultCode']??-1) === 0) {
                $items = $cb['CallbackMetadata']['Item'] ?? [];
                $rcpt  = ''; $amt=0;
                foreach ($items as $it) { if ($it['Name']==='MpesaReceiptNumber') $rcpt=$it['Value']; if ($it['Name']==='Amount') $amt=$it['Value']; }
                DB::execute("UPDATE mpesa_transactions SET status='Success',mpesa_receipt=?,confirmed_at=NOW() WHERE daraja_ref=?",[$rcpt,$ref]);
            } else {
                DB::execute("UPDATE mpesa_transactions SET status='Failed' WHERE daraja_ref=?",[$ref]);
            }
        }
        http_response_code(200); echo json_encode(['ResultCode'=>0,'ResultDesc'=>'Accepted']); exit;
    }

    jsonOut(['error'=>'Unknown endpoint: '.$req],404);
}

// ════════════════════════════════════════════════════════════════
//  HTML PAGE RESPONSE
// ════════════════════════════════════════════════════════════════
$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>BidhaaBora — Smart Grocery Management &amp; Storefront</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"/>
<style>
:root{
  --bg:#f5f0e8;--bg2:#ede4d3;--panel:#fffcf5;--panel2:#fffaf0;
  --ink:#1a1f2e;--muted:#6b7280;--accent:#145f4a;--accent2:#b85c0d;--accent3:#1e3a5f;
  --line:#e2d9c8;--shadow:0 12px 40px rgba(20,31,46,.1);--radius:20px;
  --danger:#dc2626;--success:#15803d;--warning:#d97706;--info:#2563eb;
  --font:'DM Sans',sans-serif;--font-serif:'DM Serif Display',serif;--font-mono:'JetBrains Mono',monospace;
}
body.dark{
  --bg:#0d1117;--bg2:#161b22;--panel:#1c2333;--panel2:#1f2937;
  --ink:#e6edf3;--muted:#8b949e;--accent:#3fb950;--accent2:#ffa657;--accent3:#79c0ff;
  --line:#30363d;--shadow:0 12px 40px rgba(0,0,0,.4);
  --danger:#f85149;--success:#3fb950;--warning:#e3b341;--info:#58a6ff;
}
*{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{color:var(--ink);font-family:var(--font);background:var(--bg);min-height:100vh;transition:background .3s,color .3s;font-size:15px}
h1,h2,h3,h4{font-family:var(--font-serif);letter-spacing:-.01em;line-height:1.2}
p{color:var(--muted);line-height:1.65}
.hidden{display:none!important}
code,pre{font-family:var(--font-mono)}
@keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.6}}
.fade-up{animation:fadeUp .25s ease}

/* ── BUTTONS ── */
.btn,.btn2,.btn3,.btn-danger{display:inline-flex;align-items:center;justify-content:center;gap:7px;height:42px;padding:0 18px;border-radius:10px;border:1.5px solid transparent;font:600 .88rem var(--font);cursor:pointer;text-decoration:none;transition:all .18s;white-space:nowrap}
.btn{background:var(--accent);color:#fff;border-color:var(--accent)}.btn:hover{filter:brightness(1.08);transform:translateY(-1px)}
.btn2{background:var(--accent2);color:#fff;border-color:var(--accent2)}.btn2:hover{filter:brightness(1.08);transform:translateY(-1px)}
.btn3{background:var(--panel);color:var(--ink);border-color:var(--line)}.btn3:hover{border-color:var(--accent);color:var(--accent);transform:translateY(-1px)}
.btn-danger{background:var(--danger);color:#fff;border-color:var(--danger)}.btn-danger:hover{filter:brightness(1.08)}
.btn-sm{height:34px;padding:0 12px;font-size:.8rem;border-radius:8px}
.btn-xs{height:28px;padding:0 8px;font-size:.75rem;border-radius:6px}

/* ── BADGE ── */
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:.75rem;font-weight:600}
.b-green{background:rgba(21,128,61,.12);color:#15803d}.b-amber{background:rgba(217,119,6,.12);color:#b45309}
.b-red{background:rgba(220,38,38,.12);color:#dc2626}.b-blue{background:rgba(37,99,235,.12);color:#1d4ed8}
.b-muted{background:rgba(107,114,128,.1);color:var(--muted)}.b-purple{background:rgba(124,58,237,.12);color:#6d28d9}

/* ── FORM ── */
.fg{display:grid;gap:5px;margin-bottom:12px}
.fg label{font-size:.82rem;font-weight:600;color:var(--ink)}
.fg input,.fg select,.fg textarea{padding:9px 12px;border:1.5px solid var(--line);border-radius:9px;font:500 .88rem var(--font);background:var(--panel2);color:var(--ink);transition:border-color .2s;width:100%}
.fg input:focus,.fg select:focus,.fg textarea:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(20,95,74,.08)}
.form-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.form-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}

/* ── CARDS ── */
.card{background:var(--panel);border:1.5px solid var(--line);border-radius:var(--radius);padding:20px;box-shadow:var(--shadow)}
.tcard{background:var(--panel);border:1.5px solid var(--line);border-radius:16px;overflow:hidden}
.tcard-hdr{padding:14px 18px;border-bottom:1.5px solid var(--line);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px}
.tcard-hdr h3{font-size:.95rem;font-family:var(--font);font-weight:700}
.twrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
table{width:100%;border-collapse:collapse;font-size:.84rem}
th{padding:11px 14px;text-align:left;font-weight:700;font-size:.78rem;letter-spacing:.03em;text-transform:uppercase;color:var(--muted);background:var(--panel2);border-bottom:1.5px solid var(--line);white-space:nowrap}
td{padding:11px 14px;border-bottom:1px solid var(--line);color:var(--ink);vertical-align:middle}
tbody tr:last-child td{border-bottom:none}
tbody tr:hover{background:rgba(20,95,74,.03)}

/* ── MSGS ── */
.msg-ok{background:rgba(21,128,61,.08);border:1.5px solid rgba(21,128,61,.25);color:var(--success);border-radius:9px;padding:10px 14px;font-size:.85rem;margin-bottom:12px;display:none}
.msg-err{background:rgba(220,38,38,.08);border:1.5px solid rgba(220,38,38,.25);color:var(--danger);border-radius:9px;padding:10px 14px;font-size:.85rem;margin-bottom:12px;display:none}

/* ── MODAL ── */
.modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:500;align-items:center;justify-content:center;padding:16px}
.modal-bg.open{display:flex}
.modal-box{background:var(--panel);border:1.5px solid var(--line);border-radius:20px;padding:26px;width:100%;max-width:540px;max-height:92vh;overflow-y:auto;box-shadow:0 24px 60px rgba(0,0,0,.25);animation:fadeUp .2s ease}
.modal-hdr{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;padding-bottom:14px;border-bottom:1.5px solid var(--line)}
.modal-hdr h3{font-size:1.1rem;font-family:var(--font);font-weight:700}
.modal-close{background:none;border:none;font-size:1.3rem;cursor:pointer;color:var(--muted);line-height:1;padding:4px 6px;border-radius:6px}
.modal-close:hover{background:var(--line)}

/* ── KPI ── */
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}
.kpi{padding:16px 18px;background:var(--panel);border:1.5px solid var(--line);border-radius:14px;position:relative;overflow:hidden}
.kpi::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--accent)}
.kpi.warn::before{background:var(--warning)}.kpi.danger::before{background:var(--danger)}.kpi.info::before{background:var(--info)}
.kpi-lbl{font-size:.74rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--muted);margin-bottom:8px}
.kpi-val{font-size:1.75rem;font-weight:700;color:var(--ink);font-family:var(--font-serif);margin-bottom:4px;line-height:1}
.kpi-sub{font-size:.78rem;color:var(--muted)}
.kpi-sub.pos{color:var(--success)}.kpi-sub.neg{color:var(--danger)}

/* ── CHART ── */
.chart-wrap{background:var(--panel);border:1.5px solid var(--line);border-radius:16px;padding:18px}
.chart-wrap h3{font-size:.9rem;font-family:var(--font);font-weight:700;margin-bottom:14px;color:var(--ink)}
.bar-chart{display:flex;align-items:flex-end;gap:6px;height:120px}
.bar-col{flex:1;display:flex;flex-direction:column;align-items:center;gap:4px;min-width:0}
.bar-fill{width:100%;border-radius:5px 5px 0 0;background:var(--accent);min-height:4px;transition:height .4s}
.bar-label{font-size:.72rem;color:var(--muted);text-align:center;width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.bar-val{font-size:.7rem;color:var(--ink);font-weight:600}

/* ── PROGRESS ── */
.progress{background:var(--line);border-radius:20px;height:8px;overflow:hidden;flex:1}
.progress-fill{height:100%;border-radius:20px;background:var(--accent);transition:width .4s}

/* ═══ LANDING ═══ */
#landing{display:block}
.l-nav{display:flex;align-items:center;justify-content:space-between;padding:16px 48px;position:sticky;top:0;z-index:100;background:rgba(245,240,232,.92);backdrop-filter:blur(16px);border-bottom:1px solid var(--line);gap:12px}
body.dark .l-nav{background:rgba(13,17,23,.92)}
.l-brand{display:flex;align-items:center;gap:10px;font-family:var(--font-serif);font-size:1.35rem;color:var(--accent);cursor:pointer}
.l-mark{width:38px;height:38px;background:var(--accent);border-radius:10px;display:grid;place-items:center;color:#fff;font-size:.95rem;box-shadow:0 4px 14px rgba(20,95,74,.3);flex-shrink:0}
.l-nav-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}

.l-hero{position:relative;min-height:90vh;display:flex;align-items:center;justify-content:center;overflow:hidden;text-align:center;padding:80px 24px}
.l-hero-bg{position:absolute;inset:0;background:url('https://images.unsplash.com/photo-1542838132-92c53300491e?auto=format&fit=crop&w=1800&q=80') center/cover no-repeat;filter:brightness(.38)}
.l-hero-overlay{position:absolute;inset:0;background:linear-gradient(180deg,rgba(10,20,14,.3) 0%,rgba(10,20,14,.75) 100%)}
.l-hero-content{position:relative;z-index:2;max-width:820px}
.l-hero-tag{display:inline-flex;align-items:center;gap:8px;padding:7px 16px;border-radius:999px;background:rgba(255,255,255,.12);backdrop-filter:blur(8px);color:#f0faf4;font-size:.85rem;font-weight:600;letter-spacing:.03em;border:1px solid rgba(255,255,255,.18);margin-bottom:24px}
.l-hero h1{font-size:clamp(2.4rem,5.5vw,4.2rem);color:#fff;margin-bottom:18px;line-height:1.1}
.l-hero h1 em{color:#6ee7b7;font-style:normal}
.l-hero-copy{font-size:1.1rem;color:#d1fae5;max-width:640px;margin:0 auto 32px;line-height:1.7}
.l-hero-ctas{display:flex;gap:12px;justify-content:center;flex-wrap:wrap}
.l-hero-ctas .btn,.l-hero-ctas .btn2{height:50px;padding:0 28px;font-size:.95rem}
.l-hero-note{position:absolute;bottom:0;left:0;right:0;padding:6px;background:rgba(0,0,0,.3);backdrop-filter:blur(4px);color:rgba(255,255,255,.6);font-size:.78rem;text-align:center}

.l-features{max-width:1100px;margin:0 auto;padding:80px 32px}
.l-features-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:20px}
.l-fc{background:var(--panel);border:1.5px solid var(--line);border-radius:18px;padding:26px 22px;transition:all .28s}
.l-fc:hover{transform:translateY(-5px);box-shadow:var(--shadow);border-color:var(--accent)}
.l-fc-icon{width:48px;height:48px;background:rgba(20,95,74,.1);border-radius:12px;display:grid;place-items:center;font-size:1.3rem;margin-bottom:14px}
.l-fc h3{font-family:var(--font);font-size:1.05rem;font-weight:700;margin-bottom:8px;color:var(--ink)}

.l-prods{max-width:1100px;margin:0 auto;padding:0 32px 80px}
.sec-title{font-size:1.8rem;margin-bottom:6px}
.sec-sub{margin-bottom:26px;font-size:.95rem}
.prod-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:16px}
.pcard{background:var(--panel);border:1.5px solid var(--line);border-radius:14px;overflow:hidden;transition:all .22s;position:relative;cursor:pointer}
.pcard:hover{transform:translateY(-4px);box-shadow:var(--shadow);border-color:var(--accent)}
.pcard-img{height:130px;display:flex;align-items:center;justify-content:center;font-size:2.8rem;background:linear-gradient(135deg,rgba(20,95,74,.07),rgba(184,92,13,.05));border-bottom:1px solid var(--line)}
.pcard-disc-ribbon{position:absolute;top:10px;right:10px;background:var(--danger);color:#fff;font-size:.72rem;font-weight:700;padding:3px 8px;border-radius:6px}
.pcard-body{padding:13px}
.pcard-name{font-weight:700;font-size:.9rem;color:var(--ink);margin-bottom:3px;line-height:1.3}
.pcard-cat{font-size:.75rem;color:var(--muted);margin-bottom:8px}
.pcard-row{display:flex;align-items:center;justify-content:space-between;gap:6px;margin-bottom:10px}
.pcard-price strong{color:var(--accent);font-size:1rem;font-weight:700}
.pcard-price s{color:var(--muted);font-size:.8rem}
.pcard-timer{font-size:.75rem;color:var(--accent2);font-weight:600}
.pcard-add{width:100%;height:34px;background:var(--accent);color:#fff;border:none;border-radius:8px;font:600 .83rem var(--font);cursor:pointer;transition:all .18s}
.pcard-add:hover{filter:brightness(1.1)}
.pcard-add:disabled{background:var(--muted);cursor:not-allowed}

.l-footer{background:#0b1220;color:#f8fafc;padding:50px 48px 30px}
.l-footer-grid{max-width:1100px;margin:0 auto;display:grid;grid-template-columns:1.4fr 1fr 1fr 1fr;gap:32px;margin-bottom:32px}
.l-footer-brand h3{font-family:var(--font-serif);font-size:1.3rem;color:#fff;margin-bottom:10px}
.l-footer-brand p{color:#94a3b8;font-size:.88rem;line-height:1.6}
.l-footer-col h4{color:#e2e8f0;font-weight:700;font-size:.9rem;margin-bottom:14px}
.l-footer-col a{display:block;color:#94a3b8;font-size:.87rem;text-decoration:none;margin-bottom:8px;transition:color .18s}
.l-footer-col a:hover{color:var(--accent2)}
.l-footer-copy{max-width:1100px;margin:0 auto;padding-top:24px;border-top:1px solid rgba(255,255,255,.08);color:#64748b;font-size:.82rem;display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px}

/* ═══ STOREFRONT ═══ */
#storefront{display:none}
.s-nav{position:sticky;top:0;z-index:100;background:rgba(245,240,232,.95);backdrop-filter:blur(14px);border-bottom:1.5px solid var(--line);padding:10px 24px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
body.dark .s-nav{background:rgba(13,17,23,.95)}
.s-nav-left{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.s-nav-links{display:flex;gap:5px;flex-wrap:wrap}
.snl{border:1.5px solid var(--line);background:var(--panel);color:var(--ink);border-radius:8px;height:36px;padding:0 14px;font:600 .83rem var(--font);cursor:pointer;transition:all .16s;white-space:nowrap}
.snl:hover,.snl.active{border-color:var(--accent);color:var(--accent);background:rgba(20,95,74,.06)}
.cart-snl{position:relative}
.cart-dot{position:absolute;top:-5px;right:-5px;background:var(--danger);color:#fff;width:18px;height:18px;border-radius:50%;font-size:.65rem;font-weight:700;display:flex;align-items:center;justify-content:center}
.s-body{max-width:1100px;margin:0 auto;padding:24px 20px}
.s-pg{display:none}.s-pg.active{display:block;animation:fadeUp .22s ease}
.shero{position:relative;border-radius:18px;min-height:300px;display:flex;align-items:center;overflow:hidden;margin-bottom:36px;padding:40px 36px}
.shero-bg{position:absolute;inset:0;background:url('https://images.unsplash.com/photo-1610348725531-843dff563e2c?auto=format&fit=crop&w=1800&q=80') center/cover no-repeat;filter:brightness(.4)}
.shero-ov{position:absolute;inset:0;background:linear-gradient(90deg,rgba(10,20,14,.85) 50%,transparent)}
.shero-content{position:relative;z-index:2}
.shero h2{color:#fff;font-size:clamp(1.7rem,3.5vw,2.5rem);margin-bottom:10px}
.shero p{color:#d1fae5;font-size:1rem;max-width:500px;margin-bottom:20px}
.shero-acts{display:flex;gap:10px;flex-wrap:wrap}
.ham-btn{display:none;border:1.5px solid var(--line);background:var(--panel);color:var(--ink);border-radius:8px;height:36px;width:40px;cursor:pointer;font-size:1rem}
.s-mobile-menu{display:none;flex-direction:column;gap:6px;padding:10px;background:var(--panel);border:1.5px solid var(--line);border-radius:12px;margin:0 0 12px}
.s-mobile-menu .snl{width:100%;text-align:left;justify-content:flex-start}

.cart-empty{text-align:center;padding:60px 20px;color:var(--muted)}
.cart-empty .icon{font-size:3rem;margin-bottom:14px}
.cart-item-row{display:flex;align-items:center;gap:14px;padding:14px;background:var(--panel);border:1.5px solid var(--line);border-radius:12px;margin-bottom:10px}
.ci-icon{font-size:2rem;flex-shrink:0;width:50px;text-align:center}
.ci-info{flex:1;min-width:0}
.ci-name{font-weight:700;color:var(--ink);font-size:.9rem;margin-bottom:3px}
.ci-price{color:var(--muted);font-size:.82rem}
.qty-ctrl{display:flex;align-items:center;gap:6px}
.qb{width:28px;height:28px;border:1.5px solid var(--line);background:var(--panel2);color:var(--ink);border-radius:7px;cursor:pointer;font:700 1rem var(--font);display:flex;align-items:center;justify-content:center;transition:all .15s}
.qb:hover{border-color:var(--accent);color:var(--accent)}
.ci-total{font-weight:700;color:var(--accent);font-size:.9rem;min-width:70px;text-align:right}
.cart-summary-box{background:var(--panel);border:1.5px solid var(--line);border-radius:14px;padding:20px;margin-top:16px}
.cs-row{display:flex;justify-content:space-between;font-size:.9rem;padding:5px 0;color:var(--muted)}
.cs-row.total{font-size:1.1rem;font-weight:700;color:var(--ink);border-top:2px solid var(--line);padding-top:12px;margin-top:6px}

.co-grid{display:grid;grid-template-columns:1fr 380px;gap:24px;align-items:start}
.co-card{background:var(--panel);border:1.5px solid var(--line);border-radius:16px;padding:22px}
.co-card h3{font-family:var(--font);font-weight:700;font-size:1rem;margin-bottom:16px}
.order-ok{text-align:center;padding:60px 20px;animation:fadeUp .3s ease}
.order-ok .tick{font-size:4rem;margin-bottom:16px}
.order-ok h2{color:var(--success);margin-bottom:10px;font-family:var(--font);font-weight:700}

/* ═══ ADMIN LOGIN ═══ */
#adminLogin{display:none;min-height:100vh;background:url('https://images.unsplash.com/photo-1542838132-92c53300491e?auto=format&fit=crop&w=1200&q=80') center/cover;position:relative;align-items:center;justify-content:center;padding:20px}
#adminLogin::before{content:'';position:absolute;inset:0;background:rgba(10,20,14,.75)}
.login-box{position:relative;z-index:2;background:var(--panel);border:1.5px solid var(--line);border-radius:22px;padding:38px;width:100%;max-width:420px;box-shadow:0 30px 60px rgba(0,0,0,.3);animation:fadeUp .3s ease}
.login-brand{display:flex;align-items:center;gap:10px;font-family:var(--font-serif);font-size:1.3rem;color:var(--accent);margin-bottom:24px}
.login-hint{background:rgba(184,92,13,.08);border:1.5px solid rgba(184,92,13,.2);border-radius:10px;padding:14px;margin-bottom:20px;font-size:.82rem;line-height:1.6}
.login-hint strong{color:var(--accent2)}
.login-err{background:rgba(220,38,38,.08);border:1.5px solid rgba(220,38,38,.2);color:var(--danger);border-radius:9px;padding:10px 14px;font-size:.85rem;margin-bottom:12px;display:none}

/* ═══ ADMIN WORKSPACE ═══ */
#adminWS{display:none;min-height:100vh;background:var(--bg)}
.ws-top{background:var(--panel);border-bottom:1.5px solid var(--line);padding:12px 24px;display:flex;align-items:center;justify-content:space-between;gap:12px;position:sticky;top:0;z-index:90;flex-wrap:wrap}
.ws-brand-row{display:flex;align-items:center;gap:10px}
.ws-mark{width:36px;height:36px;background:var(--accent);border-radius:9px;display:grid;place-items:center;color:#fff;font-size:.9rem;flex-shrink:0}
.ws-brand-row h2{font-family:var(--font);font-weight:700;font-size:1.05rem}
.ws-brand-row h2 small{font-size:.75rem;color:var(--muted);font-weight:400;display:block}
.ws-top-right{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.ws-chip{display:flex;align-items:center;gap:8px;background:var(--panel2);border:1.5px solid var(--line);border-radius:9px;padding:6px 12px;font-size:.82rem}
.ws-av{width:26px;height:26px;background:var(--accent);border-radius:50%;display:grid;place-items:center;color:#fff;font-size:.7rem;font-weight:700;flex-shrink:0}
.icon-btn{height:36px;min-width:36px;padding:0 10px;border:1.5px solid var(--line);background:var(--panel);color:var(--ink);border-radius:9px;cursor:pointer;font:600 .82rem var(--font);transition:all .16s}
.icon-btn:hover{border-color:var(--accent);color:var(--accent)}

.ws-layout{display:grid;grid-template-columns:230px 1fr;min-height:calc(100vh - 65px)}
.ws-sidebar{background:var(--panel);border-right:1.5px solid var(--line);padding:16px 12px;display:flex;flex-direction:column;position:sticky;top:65px;height:calc(100vh - 65px);overflow-y:auto}
.ws-sidebar h4{font-size:.72rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);padding:8px 10px 5px;margin-top:8px}
.ws-sidebar h4:first-child{margin-top:0}
.ws-nav-btn{width:100%;display:flex;align-items:center;gap:9px;padding:9px 10px;border-radius:9px;border:none;background:transparent;color:var(--muted);font:500 .87rem var(--font);cursor:pointer;transition:all .15s;text-align:left}
.ws-nav-btn:hover{background:var(--panel2);color:var(--ink)}
.ws-nav-btn.active{background:rgba(20,95,74,.1);color:var(--accent);font-weight:700}
.ws-nav-btn .navbadge{margin-left:auto;background:var(--danger);color:#fff;font-size:.68rem;padding:2px 6px;border-radius:10px;font-weight:700}
.ws-content{padding:24px;overflow-x:hidden}
.ws-pg{display:none}.ws-pg.active{display:grid;gap:20px;animation:fadeUp .22s ease}
.ws-pg-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px}
.ws-pg-header h2{font-family:var(--font);font-weight:700;font-size:1.2rem}

/* POS */
.pos-layout{display:grid;grid-template-columns:1fr 320px;gap:16px;min-height:500px}
.pos-pgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:10px;max-height:420px;overflow-y:auto;padding-right:4px}
.pos-pc{background:var(--panel2);border:1.5px solid var(--line);border-radius:11px;padding:10px 8px;cursor:pointer;transition:all .15s;text-align:center}
.pos-pc:hover{border-color:var(--accent);background:rgba(20,95,74,.05)}
.pos-pc.out{opacity:.4;cursor:not-allowed}
.pos-pc-ic{font-size:1.9rem;margin-bottom:5px}
.pos-pc-name{font-size:.78rem;font-weight:600;color:var(--ink);margin-bottom:3px;line-height:1.3}
.pos-pc-price{font-size:.85rem;font-weight:700;color:var(--accent)}
.pos-pc-stock{font-size:.72rem;color:var(--muted);margin-top:2px}
.pos-panel{background:var(--panel);border:1.5px solid var(--line);border-radius:16px;display:flex;flex-direction:column}
.pos-panel-hdr{padding:12px 16px;border-bottom:1.5px solid var(--line);display:flex;justify-content:space-between;align-items:center}
.pos-panel-hdr h3{font:700 .92rem var(--font)}
.pos-cart-body{flex:1;overflow-y:auto;padding:10px;max-height:260px}
.pos-ci{display:flex;align-items:center;gap:8px;padding:8px 10px;background:var(--panel2);border:1px solid var(--line);border-radius:9px;margin-bottom:6px;font-size:.82rem}
.pos-ci-name{flex:1;font-weight:600;color:var(--ink)}
.pos-ci-total{font-weight:700;color:var(--accent);min-width:58px;text-align:right}
.pos-totals{padding:12px 16px;border-top:1.5px solid var(--line)}
.pos-tr{display:flex;justify-content:space-between;font-size:.85rem;padding:3px 0;color:var(--muted)}
.pos-tr.grand{font-size:1rem;font-weight:700;color:var(--ink);border-top:1.5px solid var(--line);padding-top:9px;margin-top:6px}
.pos-pay{padding:12px 16px;border-top:1.5px solid var(--line)}
.pay-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:10px}
.pm-btn{height:36px;border:1.5px solid var(--line);background:var(--panel);color:var(--ink);border-radius:8px;font:600 .8rem var(--font);cursor:pointer;transition:all .15s}
.pm-btn.active{border-color:var(--accent);background:rgba(20,95,74,.08);color:var(--accent)}
.pos-charge{width:100%;height:42px;background:var(--accent);color:#fff;border:none;border-radius:10px;font:700 .92rem var(--font);cursor:pointer;transition:all .18s}
.pos-charge:hover{filter:brightness(1.08)}

/* Alerts */
.alert-item{display:flex;align-items:flex-start;gap:12px;padding:14px 16px;background:var(--panel2);border:1.5px solid var(--line);border-radius:12px;margin-bottom:10px}
.alert-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0;margin-top:4px}
.alert-content{flex:1}
.alert-title{font-weight:700;font-size:.9rem;color:var(--ink);margin-bottom:3px}
.alert-desc{font-size:.82rem;color:var(--muted);line-height:1.5}
.alert-actions{display:flex;align-items:center;gap:8px;flex-shrink:0}

/* Branch cards */
.branch-card{background:var(--panel);border:1.5px solid var(--line);border-radius:16px;padding:20px;position:relative;overflow:hidden}
.branch-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--accent)}
.branch-grid-top{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:14px 0}
.branch-stat{text-align:center;background:var(--panel2);border-radius:10px;padding:10px}
.branch-stat .num{font-size:1.5rem;font-weight:700;color:var(--accent);font-family:var(--font-serif)}
.branch-stat .lbl{font-size:.75rem;color:var(--muted);margin-top:2px}

/* Reports */
.rtab-bar{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px}
.rtab{border:1.5px solid var(--line);background:var(--panel);color:var(--muted);border-radius:9px;height:36px;padding:0 14px;font:600 .82rem var(--font);cursor:pointer;transition:all .15s}
.rtab.active{border-color:var(--accent);background:rgba(20,95,74,.08);color:var(--accent)}
.rview{display:none}.rview.active{display:block}

/* Receipt */
.receipt-print{font-family:var(--font-mono);font-size:.78rem;background:var(--panel2);border:1.5px solid var(--line);border-radius:10px;padding:16px;white-space:pre;line-height:1.8;max-height:380px;overflow-y:auto}

/* M-Pesa */
.mpesa-hdr{background:linear-gradient(135deg,#00a551,#007a3d);border-radius:16px;padding:22px;color:#fff;margin-bottom:16px}
.mpesa-form{background:var(--panel);border:1.5px solid var(--line);border-radius:14px;padding:20px;margin-bottom:16px}
.mpesa-form h3{font:700 .95rem var(--font);margin-bottom:14px;display:flex;align-items:center;gap:8px}

/* Supplier card */
.supplier-card{background:var(--panel);border:1.5px solid var(--line);border-radius:12px;padding:16px;display:flex;align-items:center;gap:14px;margin-bottom:8px}
.supplier-av{width:42px;height:42px;background:linear-gradient(135deg,var(--accent),#1e8d6a);border-radius:10px;display:grid;place-items:center;color:#fff;font-size:1.1rem;flex-shrink:0}

/* Toasts */
.toast-container{position:fixed;bottom:24px;right:24px;z-index:9999;display:flex;flex-direction:column;gap:8px}
.toast{background:var(--ink);color:var(--panel);padding:11px 18px;border-radius:10px;font:500 .85rem var(--font);box-shadow:var(--shadow);opacity:0;transform:translateY(10px);transition:all .28s;pointer-events:none;max-width:320px}
.toast.show{opacity:1;transform:none}
.toast.ok{background:var(--success)}.toast.err{background:var(--danger)}

/* ── RESPONSIVE ── */
@media(max-width:1024px){
  .ws-layout{grid-template-columns:1fr}.ws-sidebar{display:none}
  .ws-sidebar.open{display:flex;position:fixed;top:65px;left:0;bottom:0;z-index:80;width:240px;box-shadow:4px 0 20px rgba(0,0,0,.2)}
  .pos-layout{grid-template-columns:1fr}.co-grid{grid-template-columns:1fr}.l-footer-grid{grid-template-columns:1fr 1fr}
}
@media(max-width:768px){
  .l-nav{padding:12px 18px}.l-hero{min-height:75vh;padding:60px 20px}.l-hero h1{font-size:2rem}
  .l-features,.l-prods{padding-left:18px;padding-right:18px}.l-features-grid{grid-template-columns:1fr}
  .s-nav-links .snl{display:none}.ham-btn{display:flex;align-items:center;justify-content:center}
  .s-body{padding:16px 14px}.shero{padding:28px 20px;min-height:220px}
  .prod-grid{grid-template-columns:repeat(auto-fill,minmax(160px,1fr))}.kpi-grid{grid-template-columns:1fr 1fr}
  .form-2,.form-3{grid-template-columns:1fr}.ws-content{padding:14px}
  .l-footer-grid{grid-template-columns:1fr}.l-footer{padding:36px 20px 20px}
  .l-nav-actions .btn3:not(.theme-btn){display:none}.login-box{padding:28px 20px}.ws-top{padding:10px 14px}
  .ws-pg-header{flex-direction:column;align-items:flex-start}
}
@media(max-width:480px){
  .l-hero-ctas{flex-direction:column}.l-hero-ctas .btn,.l-hero-ctas .btn2{width:100%}
  .kpi-grid{grid-template-columns:1fr}.prod-grid{grid-template-columns:1fr 1fr}
}
</style>
</head>
<body class="dark">

<?php if (!$dbOk): ?>
<div style="position:fixed;top:0;left:0;right:0;background:#b91c1c;color:#fff;padding:10px 20px;text-align:center;font-size:.88rem;z-index:9999;font-family:sans-serif">
  ⚠️ Database offline — edit <code>DB_HOST</code>, <code>DB_NAME</code>, <code>DB_USER</code>, <code>DB_PASS</code> in <code>index.php</code> lines 22–25, then import <code>sql/schema.sql</code>.
  &nbsp;·&nbsp; Storefront product loading and admin login require MySQL.
</div>
<?php endif ?>

<!-- ══ LANDING ══ -->
<div id="landing">
<nav class="l-nav">
  <div class="l-brand" onclick="backToLanding()"><div class="l-mark">🌿</div>BidhaaBora</div>
  <div class="l-nav-actions">
    <button class="icon-btn theme-btn" onclick="toggleTheme()">🌙</button>
    <button class="btn3" onclick="showStorefront()">🛍️ Shop</button>
    <button class="btn" onclick="showAdminLogin()">⚙️ Staff Login</button>
  </div>
</nav>

<section class="l-hero">
  <div class="l-hero-bg"></div><div class="l-hero-overlay"></div>
  <div class="l-hero-content">
    <div class="l-hero-tag">🇰🇪 Kenya's Smartest Grocery Platform</div>
    <h1>Fresh Groceries.<br><em>Smart Management.</em><br>One Platform.</h1>
    <p class="l-hero-copy">BidhaaBora combines an online storefront for customers with powerful inventory management, POS, M-Pesa payments, and multi-branch operations for your team.</p>
    <div class="l-hero-ctas">
      <button class="btn" onclick="showStorefront()">🛍️ Shop Now</button>
      <button class="btn2" onclick="showAdminLogin()">⚙️ Manage Store</button>
    </div>
  </div>
  <div class="l-hero-note">No account needed to shop · Staff login available for management</div>
</section>

<div class="l-features">
  <div style="text-align:center;margin-bottom:48px"><h2 class="sec-title">Everything your grocery business needs</h2><p class="sec-sub">From shelf to customer — all in one place</p></div>
  <div class="l-features-grid">
    <div class="l-fc"><div class="l-fc-icon">📊</div><h3>Real-Time Inventory</h3><p>Live stock levels, expiry alerts, and reorder automation across all branches.</p></div>
    <div class="l-fc"><div class="l-fc-icon">🛍️</div><h3>Open Storefront</h3><p>Customers browse and order online without creating accounts — just tap and checkout.</p></div>
    <div class="l-fc"><div class="l-fc-icon">📱</div><h3>M-Pesa STK Push</h3><p>Pay suppliers directly via Daraja API. Instant payment prompts to supplier phones.</p></div>
    <div class="l-fc"><div class="l-fc-icon">🏪</div><h3>Multi-Branch Ops</h3><p>Manage CBD, Westlands, Karen and more from a single unified dashboard.</p></div>
    <div class="l-fc"><div class="l-fc-icon">👥</div><h3>Role-Based Access</h3><p>Admin, Store Manager, Cashier, and Customer Support — each sees what they need.</p></div>
    <div class="l-fc"><div class="l-fc-icon">🔔</div><h3>Smart Alerts</h3><p>Critical stock, expiry, delivery delay, and M-Pesa alerts with one-click resolve.</p></div>
  </div>
</div>

<div class="l-prods">
  <h2 class="sec-title">🔥 Flash Deals</h2><p class="sec-sub">Limited-time discounts — buy before the timer runs out!</p>
  <div class="prod-grid" id="l-deals" style="margin-bottom:50px"></div>
  <h2 class="sec-title">⭐ Top Selling</h2><p class="sec-sub">Most popular products this week</p>
  <div class="prod-grid" id="l-topsell"></div>
</div>

<footer class="l-footer">
  <div class="l-footer-grid">
    <div class="l-footer-brand"><div style="display:flex;align-items:center;gap:10px;margin-bottom:12px"><div class="l-mark">🌿</div><h3>BidhaaBora</h3></div><p>Kenya's all-in-one grocery management &amp; online storefront. Built for stores, loved by shoppers.</p></div>
    <div class="l-footer-col"><h4>Quick Links</h4><a href="#" onclick="showStorefront();return false">🛍️ Shop Online</a><a href="#" onclick="showAdminLogin();return false">⚙️ Staff Login</a><a href="#" onclick="document.querySelector('.l-features').scrollIntoView({behavior:'smooth'});return false">📋 Features</a></div>
    <div class="l-footer-col"><h4>Platform</h4><a href="#">📦 Inventory Mgmt</a><a href="#">📱 M-Pesa Payments</a><a href="#">🏪 Multi-Branch</a><a href="#">📈 Analytics</a></div>
    <div class="l-footer-col"><h4>Contact</h4><a href="tel:+254711011011">📞 0711 011 011</a><a href="mailto:hello@bidhaabora.co.ke">📧 hello@bidhaabora.co.ke</a><a href="#">📍 Nairobi, Kenya</a></div>
  </div>
  <div class="l-footer-copy"><span>© 2026 BidhaaBora. All rights reserved.</span><span>Green Commercial Compound, Cabanas, Nairobi</span></div>
</footer>
</div><!-- #landing -->

<!-- ══ STOREFRONT ══ -->
<div id="storefront">
<nav class="s-nav">
  <div class="s-nav-left">
    <div class="l-brand" onclick="backToLanding()" style="font-size:1.1rem"><div class="l-mark" style="width:32px;height:32px;font-size:.85rem">🌿</div>BidhaaBora</div>
    <div class="s-nav-links" id="sNavLinks">
      <button class="snl active" onclick="showSP('home',this)">🏠 Home</button>
      <button class="snl" onclick="showSP('shop',this)">🛍️ Shop</button>
      <button class="snl" onclick="showSP('deals',this)">🔥 Deals</button>
      <button class="snl" onclick="showSP('topsell',this)">⭐ Top Picks</button>
      <button class="snl cart-snl" onclick="showSP('cart',this)">🛒 Cart<span class="cart-dot" id="cartDot">0</span></button>
    </div>
    <button class="ham-btn" onclick="toggleSMenu()">☰</button>
  </div>
  <div style="display:flex;gap:8px;align-items:center">
    <button class="icon-btn" onclick="toggleTheme()">🌙</button>
    <button class="btn3 btn-sm" id="optAccBtn" onclick="openModal('optModal')">👤 Account</button>
    <button class="btn3 btn-sm" onclick="backToLanding()">← Back</button>
  </div>
</nav>
<div id="sMobileMenu" class="s-mobile-menu">
  <button class="snl" onclick="showSP('home');closeSMenu()">🏠 Home</button>
  <button class="snl" onclick="showSP('shop');closeSMenu()">🛍️ Shop</button>
  <button class="snl" onclick="showSP('deals');closeSMenu()">🔥 Deals</button>
  <button class="snl" onclick="showSP('topsell');closeSMenu()">⭐ Top Picks</button>
  <button class="snl" onclick="showSP('cart');closeSMenu()">🛒 Cart</button>
</div>

<div class="s-body">
  <div class="s-pg active" id="sp-home">
    <div class="shero"><div class="shero-bg"></div><div class="shero-ov"></div>
      <div class="shero-content"><h2>Fresh Groceries,<br>Delivered to You</h2><p>Shop without creating an account. Browse, add to cart, and order in minutes!</p>
      <div class="shero-acts"><button class="btn" onclick="showSP('shop')">🛍️ Shop All</button><button class="btn2" onclick="showSP('deals')">🔥 Flash Deals</button></div></div>
    </div>
    <div style="display:grid;gap:32px">
      <div><div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px"><h2 style="font-size:1.4rem">🔥 Flash Deals</h2><button class="btn3 btn-sm" onclick="showSP('deals')">All deals →</button></div><div class="prod-grid" id="s-homeDeals"><p style="color:var(--muted);padding:20px">Loading deals...</p></div></div>
      <div><div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px"><h2 style="font-size:1.4rem">⭐ Top Picks</h2><button class="btn3 btn-sm" onclick="showSP('topsell')">View all →</button></div><div class="prod-grid" id="s-homeTop"><p style="color:var(--muted);padding:20px">Loading products...</p></div></div>
    </div>
  </div>

  <div class="s-pg" id="sp-shop">
    <h2 style="font-size:1.4rem;margin-bottom:6px">🛍️ All Products</h2>
    <p style="margin-bottom:16px">Browse our full catalogue</p>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px">
      <input type="text" placeholder="Search products..." oninput="filterShopQ(this.value)" style="flex:1;min-width:140px;padding:9px 12px;border:1.5px solid var(--line);border-radius:9px;font:500 .88rem var(--font);background:var(--panel2);color:var(--ink)">
      <select id="shopCatFilt" onchange="filterShopCat(this.value)" style="padding:9px 12px;border:1.5px solid var(--line);border-radius:9px;font:500 .88rem var(--font);background:var(--panel2);color:var(--ink)">
        <option value="">All Categories</option>
        <option>Grains &amp; Flour</option><option>Cooking Oils</option><option>Dairy &amp; Eggs</option>
        <option>Beverages</option><option>Snacks &amp; Confectionery</option><option>Cleaning &amp; Hygiene</option>
        <option>Personal Care</option><option>Fresh Produce</option><option>Bakery &amp; Pastries</option>
        <option>Meat &amp; Fish</option><option>Rice &amp; Pasta</option><option>Frozen Foods</option>
        <option>Energy &amp; Sports Drinks</option><option>Alcohol &amp; Spirits</option>
      </select>
    </div>
    <div class="prod-grid" id="s-shopGrid"><p style="color:var(--muted);padding:20px">Loading products...</p></div>
  </div>

  <div class="s-pg" id="sp-deals">
    <h2 style="font-size:1.4rem;margin-bottom:6px">🔥 Flash Deals</h2><p style="margin-bottom:18px">Limited-time discounts — order before time runs out!</p>
    <div class="prod-grid" id="s-dealsGrid"><p style="color:var(--muted);padding:20px">Loading...</p></div>
  </div>

  <div class="s-pg" id="sp-topsell">
    <h2 style="font-size:1.4rem;margin-bottom:6px">⭐ Top Picks</h2><p style="margin-bottom:18px">Most popular products this week</p>
    <div class="prod-grid" id="s-topGrid"><p style="color:var(--muted);padding:20px">Loading...</p></div>
  </div>

  <div class="s-pg" id="sp-cart">
    <h2 style="font-size:1.4rem;margin-bottom:18px">🛒 Your Cart</h2>
    <div id="cartItems"></div>
    <div id="cartEmpty" class="cart-empty" style="display:none"><div class="icon">🛒</div><h3>Your cart is empty</h3><p>Add products to get started</p><button class="btn" style="margin-top:16px" onclick="showSP('shop')">Shop Now</button></div>
    <div id="cartSummary"></div>
  </div>

  <div class="s-pg" id="sp-checkout">
    <h2 style="font-size:1.4rem;margin-bottom:6px">💳 Checkout</h2>
    <p style="margin-bottom:22px">No account required to complete your order</p>
    <div id="coForm">
      <div class="co-grid">
        <div>
          <div class="co-card" style="margin-bottom:14px">
            <h3>📍 Delivery Details</h3>
            <div class="fg"><label>Full Name *</label><input id="co-name" placeholder="Your name"></div>
            <div class="fg"><label>Phone *</label><input id="co-phone" type="tel" placeholder="+254..."></div>
            <div class="fg"><label>Email (optional)</label><input id="co-email" type="email" placeholder="your@email.com"></div>
            <div class="fg"><label>Delivery Address *</label><textarea id="co-addr" rows="3" placeholder="Estate, street, building..."></textarea></div>
          </div>
          <div class="co-card" style="margin-bottom:14px">
            <h3>🚚 Delivery Method</h3>
            <div class="fg"><select id="co-delivery"><option value="pickup">🏪 Pickup — Free</option><option value="courier">🛵 Courier — KSh 150</option><option value="rider">🚗 Rider — KSh 300</option></select></div>
          </div>
          <div class="co-card">
            <h3>💳 Payment</h3>
            <div class="fg"><select id="co-pay"><option value="mpesa">📱 M-Pesa STK Push</option><option value="cod">💵 Cash on Delivery</option><option value="card">💳 Card Payment</option></select></div>
          </div>
        </div>
        <div>
          <div class="co-card" style="margin-bottom:12px"><h3>🧾 Order Summary</h3><div id="coSummary"></div></div>
          <div id="co-err" style="background:rgba(220,38,38,.1);border:1px solid rgba(220,38,38,.3);color:var(--danger);border-radius:9px;padding:10px 14px;font-size:.85rem;margin-bottom:10px;display:none"></div>
          <button class="btn" style="width:100%;height:48px;font-size:.95rem" onclick="placeOrder()">✅ Place Order</button>
          <p style="text-align:center;margin-top:10px;font-size:.78rem;color:var(--muted)">Secure checkout · No account required</p>
        </div>
      </div>
    </div>
    <div id="orderOK" class="order-ok" style="display:none">
      <div class="tick">✅</div><h2>Order Placed!</h2>
      <p style="margin-bottom:8px">Thank you! Your order <strong id="orderID"></strong> has been received.</p>
      <p style="margin-bottom:24px">We'll contact you at the phone number provided to confirm delivery.</p>
      <button class="btn" onclick="showSP('shop')">Continue Shopping</button>
    </div>
  </div>
</div>
</div><!-- #storefront -->

<!-- Optional account modal -->
<div class="modal-bg" id="optModal">
  <div class="modal-box">
    <div class="modal-hdr"><h3>👤 Customer Account (Optional)</h3><button class="modal-close" onclick="closeModal('optModal')">×</button></div>
    <p style="margin-bottom:16px;font-size:.88rem">Create an account to track orders. <strong>You can shop without one.</strong></p>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:16px">
      <button class="btn" onclick="switchOptTab('login')">Login</button>
      <button class="btn2" onclick="switchOptTab('reg')">Create Account</button>
    </div>
    <div id="optLogin">
      <div class="fg"><label>Email</label><input id="opt-email" type="email" placeholder="your@email.com"></div>
      <div class="fg"><label>Password</label><input id="opt-pass" type="password" placeholder="••••••••"></div>
      <div class="msg-err" id="optLoginErr"></div>
      <button class="btn" style="width:100%" onclick="optDoLogin()">Login</button>
    </div>
    <div id="optReg" style="display:none">
      <div class="fg"><label>Full Name *</label><input id="opt-name" type="text"></div>
      <div class="fg"><label>Email *</label><input id="opt-reg-email" type="email"></div>
      <div class="fg"><label>Password * (min 6 chars)</label><input id="opt-reg-pass" type="password"></div>
      <div class="msg-ok" id="optRegOk"></div>
      <div class="msg-err" id="optRegErr"></div>
      <button class="btn2" style="width:100%" onclick="optDoRegister()">Create Account</button>
    </div>
    <p style="text-align:center;margin-top:14px;font-size:.82rem"><button class="btn3 btn-sm" onclick="closeModal('optModal')">Continue without account →</button></p>
  </div>
</div>

<!-- ══ ADMIN LOGIN ══ -->
<div id="adminLogin" style="display:none">
  <div class="login-box">
    <div class="login-brand"><div class="l-mark">🌿</div>BidhaaBora Staff</div>
    <h2 style="font-family:var(--font);font-weight:700;font-size:1.3rem;margin-bottom:6px">Welcome back</h2>
    <p style="margin-bottom:20px;font-size:.88rem">Sign in to access your management dashboard</p>
    <div class="login-hint">
      <strong>Demo credentials:</strong><br>
      mitchellwanga / admin123 (Admin)<br>
      willingtonkutar / store123 (Store Manager)<br>
      willsgeorge / support123 (Customer Support)<br>
      mikeclarence / cashier123 (Cashier)<br>
      ramsleyodhiambo / cashier123 (Cashier)<br>
      <em style="font-size:.78rem">Note: Run schema.sql first to set up the DB, then update password hashes.</em>
    </div>
    <div class="login-err" id="loginErr">Invalid username or password.</div>
    <div class="fg"><label>Username or Email</label><input id="lgUser" type="text" placeholder="e.g. mitchellwanga" autocomplete="username"></div>
    <div class="fg"><label>Password</label><input id="lgPass" type="password" placeholder="••••••••" onkeydown="if(event.key==='Enter')doLogin()"></div>
    <button class="btn" style="width:100%;height:46px;margin-top:4px;font-size:.95rem" onclick="doLogin()">Sign In →</button>
    <div style="text-align:center;margin-top:14px"><button style="background:none;border:none;color:var(--accent);cursor:pointer;font:600 .87rem var(--font);text-decoration:underline" onclick="backToLanding()">← Back to store</button></div>
  </div>
</div>

<!-- ══ ADMIN WORKSPACE ══ -->
<div id="adminWS">
  <div class="ws-top">
    <div class="ws-brand-row">
      <button class="icon-btn" id="sidebarToggle" onclick="toggleSidebar()" style="display:none">☰</button>
      <div class="ws-mark">🌿</div>
      <h2>BidhaaBora <small id="wsRole">Admin Panel</small></h2>
    </div>
    <div class="ws-top-right">
      <div class="ws-chip"><div class="ws-av" id="wsAv">MW</div><span id="wsName" style="font-weight:700">Staff</span><span id="wsRoleChip" style="color:var(--muted);font-size:.78rem">Role</span></div>
      <button class="icon-btn" onclick="toggleTheme()">🌙</button>
      <button class="btn3 btn-sm" onclick="wsLogout()">Logout</button>
    </div>
  </div>
  <div class="ws-layout">
    <nav class="ws-sidebar" id="wsSidebar"><div id="wsNavContent"></div></nav>
    <div class="ws-content" id="wsContent"></div>
  </div>
</div>

<!-- ══ MODALS ══ -->
<!-- Add Product -->
<div class="modal-bg" id="addProdModal">
  <div class="modal-box">
    <div class="modal-hdr"><h3>➕ Add / Edit Product</h3><button class="modal-close" onclick="closeModal('addProdModal')">×</button></div>
    <div class="msg-ok" id="addPOk"></div><div class="msg-err" id="addPErr"></div>
    <input type="hidden" id="np-id" value="0">
    <div class="form-2">
      <div class="fg"><label>Product Name *</label><input id="np-name" type="text" placeholder="e.g. Unga Flour 2kg"></div>
      <div class="fg"><label>SKU</label><input id="np-sku" type="text" placeholder="Auto if blank"></div>
      <div class="fg"><label>Barcode</label><input id="np-barcode" type="text" placeholder="EAN-13 or similar"></div>
      <div class="fg"><label>Category</label><select id="np-cat"></select></div>
      <div class="fg"><label>Icon (emoji)</label><input id="np-icon" type="text" placeholder="📦"></div>
      <div class="fg"><label>Supplier</label><select id="np-supp"><option value="">No supplier</option></select></div>
      <div class="fg"><label>Buying Price (KSh) *</label><input id="np-buy" type="number" step="0.01" min="0"></div>
      <div class="fg"><label>Selling Price (KSh) *</label><input id="np-sell" type="number" step="0.01" min="0"></div>
      <div class="fg"><label>Opening Stock</label><input id="np-stock" type="number" value="0" min="0"></div>
      <div class="fg"><label>Reorder Level</label><input id="np-reorder" type="number" value="10" min="0"></div>
      <div class="fg"><label>Discount %</label><input id="np-disc" type="number" value="0" min="0" max="99"></div>
      <div class="fg"><label>Deal Timer (hours, 0=none)</label><input id="np-timer" type="number" value="0" min="0"></div>
    </div>
    <div class="fg"><label>Unit</label><input id="np-unit" type="text" placeholder="pcs / kg / btl / pkt"></div>
    <div class="fg"><label>Top Selling?</label><select id="np-top"><option value="0">No</option><option value="1">Yes</option></select></div>
    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:6px">
      <button class="btn3" onclick="closeModal('addProdModal')">Cancel</button>
      <button class="btn" onclick="saveProd()">💾 Save Product</button>
    </div>
  </div>
</div>

<!-- Add Supplier -->
<div class="modal-bg" id="addSuppModal">
  <div class="modal-box">
    <div class="modal-hdr"><h3>➕ Add Supplier</h3><button class="modal-close" onclick="closeModal('addSuppModal')">×</button></div>
    <div class="msg-ok" id="addSOk"></div><div class="msg-err" id="addSErr"></div>
    <div class="form-2">
      <div class="fg"><label>Supplier Name *</label><input id="ns-name" type="text" placeholder="e.g. FreshFarms Ltd"></div>
      <div class="fg"><label>Contact Person</label><input id="ns-contact" type="text" placeholder="Name"></div>
      <div class="fg"><label>Email *</label><input id="ns-email" type="email" placeholder="info@supplier.ke"></div>
      <div class="fg"><label>Phone</label><input id="ns-phone" type="tel" placeholder="+254..."></div>
      <div class="fg"><label>Categories Supplied</label><input id="ns-cats" type="text" placeholder="e.g. Produce, Dairy"></div>
      <div class="fg"><label>Lead Time</label><input id="ns-lead" type="text" value="2 days" placeholder="e.g. 2 days"></div>
    </div>
    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:6px">
      <button class="btn3" onclick="closeModal('addSuppModal')">Cancel</button>
      <button class="btn" onclick="saveSupplier()">💾 Save Supplier</button>
    </div>
  </div>
</div>

<!-- Add Customer -->
<div class="modal-bg" id="addCustModal">
  <div class="modal-box">
    <div class="modal-hdr"><h3>➕ Add Customer</h3><button class="modal-close" onclick="closeModal('addCustModal')">×</button></div>
    <div class="msg-ok" id="addCOk"></div>
    <div class="form-2">
      <div class="fg"><label>Full Name *</label><input id="nc-name" type="text"></div>
      <div class="fg"><label>Phone *</label><input id="nc-phone" type="tel" placeholder="+254..."></div>
      <div class="fg"><label>Email</label><input id="nc-email" type="email"></div>
      <div class="fg"><label>Type</label><select id="nc-type"><option>Retail</option><option>Wholesale</option><option>Corporate</option></select></div>
    </div>
    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:6px">
      <button class="btn3" onclick="closeModal('addCustModal')">Cancel</button>
      <button class="btn" onclick="saveCustomer()">💾 Save Customer</button>
    </div>
  </div>
</div>

<!-- Receipt -->
<div class="modal-bg" id="receiptModal">
  <div class="modal-box" style="max-width:380px">
    <div class="modal-hdr"><h3>🧾 Receipt</h3><button class="modal-close" onclick="closeModal('receiptModal')">×</button></div>
    <div class="receipt-print" id="receiptTxt"></div>
    <div style="display:flex;gap:8px;margin-top:14px">
      <button class="btn" style="flex:1" onclick="window.print()">🖨️ Print</button>
      <button class="btn3" style="flex:1" onclick="closeModal('receiptModal')">Close</button>
    </div>
  </div>
</div>

<!-- Toast container -->
<div class="toast-container" id="toastContainer"></div>

<script>
/* ══════════════════════════════════════════════════════════════
   STATE
══════════════════════════════════════════════════════════════ */
var API    = '<?= $_SERVER['PHP_SELF'] ?>';
var dbOk   = <?= $dbOk ? 'true' : 'false' ?>;
var cart   = {};       // { id: { id,name,price,disc,ic,stock,unit,qty } }
var allProds = [];     // cached from API
var posCart  = {};
var posPM    = 'Cash';
var currentStaff = null;
var TIMER_ENDS   = {};
var nextSID = 1;

/* ══════════════════════════════════════════════════════════════
   UTILS
══════════════════════════════════════════════════════════════ */
function fmt(n){ return 'KSh '+Math.round(n).toLocaleString(); }
function dp(p){  return p.disc>0 ? Math.round(p.sell*(1-p.disc/100)) : Math.round(p.sell||p.selling_price||0); }
function ss(p){
  var q = p.stock||p.stock_qty||0;
  var r = p.reorder||p.reorder_level||10;
  if(q<=0)        return {lbl:'Out of Stock', cls:'b-red'};
  if(q<=r)        return {lbl:'Reorder Now',  cls:'b-red'};
  if(q<=r*1.5)    return {lbl:'Low Stock',    cls:'b-amber'};
  return                 {lbl:'In Stock',     cls:'b-green'};
}
function tl(id){
  if(!TIMER_ENDS[id]) return '';
  var ms = TIMER_ENDS[id]-Date.now(); if(ms<=0) return '';
  var h=Math.floor(ms/3600000),m=Math.floor((ms%3600000)/60000),s=Math.floor((ms%60000)/1000);
  return h+'h '+m+'m '+s+'s';
}
function toggleTheme(){ document.body.classList.toggle('dark'); }
function closeModal(id){ document.getElementById(id).classList.remove('open'); }
function openModal(id){  document.getElementById(id).classList.add('open'); }
document.addEventListener('click',function(e){ if(e.target.classList.contains('modal-bg')) e.target.classList.remove('open'); });
function updateCartDot(){
  var n=Object.values(cart).reduce(function(a,c){return a+c.qty},0);
  var el=document.getElementById('cartDot'); if(el) el.textContent=n;
}
function cartSub(){ return Object.values(cart).reduce(function(a,c){return a+dp(c)*c.qty},0); }

/* ══════════════════════════════════════════════════════════════
   TOAST
══════════════════════════════════════════════════════════════ */
function toast(msg, type){
  var el=document.createElement('div'); el.className='toast '+(type||'');
  el.textContent=msg; document.getElementById('toastContainer').appendChild(el);
  requestAnimationFrame(function(){ requestAnimationFrame(function(){ el.classList.add('show'); }); });
  setTimeout(function(){ el.classList.remove('show'); setTimeout(function(){ el.remove(); },400); },3200);
}

/* ══════════════════════════════════════════════════════════════
   NAVIGATION
══════════════════════════════════════════════════════════════ */
function backToLanding(){ hide('storefront');hide('adminLogin');hide('adminWS');show('landing'); }
function showAdminLogin(){ hide('landing');hide('storefront');hide('adminWS');show('adminLogin'); }
function showStorefront(){
  hide('landing');hide('adminLogin');hide('adminWS');show('storefront');
  loadStoreProducts();
}
function hide(id){ document.getElementById(id).style.display='none'; }
function show(id){ document.getElementById(id).style.display='block'; }
function toggleSMenu(){ var m=document.getElementById('sMobileMenu'); m.style.display=m.style.display==='flex'?'none':'flex'; if(m.style.display==='flex')m.style.flexDirection='column'; }
function closeSMenu(){ document.getElementById('sMobileMenu').style.display='none'; }
function showSP(id, btn){
  document.querySelectorAll('.s-pg').forEach(function(p){p.classList.remove('active')});
  document.querySelectorAll('.snl').forEach(function(b){b.classList.remove('active')});
  var el=document.getElementById('sp-'+id); if(el) el.classList.add('active');
  if(btn) btn.classList.add('active');
  if(id==='cart') renderCart();
  if(id==='checkout'){ renderCheckoutSummary(); document.getElementById('coForm').style.display='block'; document.getElementById('orderOK').style.display='none'; }
  if(id==='home') renderStoreHome();
  if(id==='deals'){ var g=document.getElementById('s-dealsGrid'); if(g) g.innerHTML=allProds.filter(function(p){return p.disc>0&&(p.stock||p.stock_qty)>0}).map(function(p){return pCard(p,true)}).join('')||'<p style="color:var(--muted)">No deals right now.</p>'; }
  if(id==='topsell'){ var g=document.getElementById('s-topGrid'); if(g) g.innerHTML=allProds.filter(function(p){return (p.top||p.is_top_sell)&&(p.stock||p.stock_qty)>0}).map(function(p){return pCard(p,false)}).join('')||'<p style="color:var(--muted)">No top sellers yet.</p>'; }
}

/* ══════════════════════════════════════════════════════════════
   PRODUCT LOADING (from MySQL via API)
══════════════════════════════════════════════════════════════ */
function loadStoreProducts(){
  if(!dbOk){ renderFallback(); return; }
  fetch(API+'?_r=products')
    .then(function(r){return r.json();})
    .then(function(prods){
      allProds=prods;
      prods.forEach(function(p){ if((p.timer||p.deal_timer_hrs)>0) TIMER_ENDS[p.id||p.product_id]=Date.now()+(p.timer||p.deal_timer_hrs)*3600000; });
      renderStoreHome(); renderShopGrid(); renderLandingProds();
    })
    .catch(function(){ renderFallback(); });
}
function renderFallback(){
  ['s-homeDeals','s-homeTop','s-shopGrid','l-deals','l-topsell','s-dealsGrid','s-topGrid'].forEach(function(id){
    var el=document.getElementById(id); if(el) el.innerHTML='<p style="color:var(--muted);padding:20px">⚠️ Database offline — connect MySQL to load live products.</p>';
  });
}
function renderStoreHome(){
  var deals=allProds.filter(function(p){return p.disc>0&&(p.stock||p.stock_qty)>0}).slice(0,4);
  var top=allProds.filter(function(p){return (p.top||p.is_top_sell)&&(p.stock||p.stock_qty)>0}).slice(0,6);
  var hd=document.getElementById('s-homeDeals'); if(hd) hd.innerHTML=deals.map(function(p){return pCard(p,true)}).join('')||'<p style="color:var(--muted)">No deals right now.</p>';
  var ht=document.getElementById('s-homeTop');   if(ht) ht.innerHTML=top.map(function(p){return pCard(p,false)}).join('')||'<p style="color:var(--muted)">No top sellers yet.</p>';
}
function renderShopGrid(){
  var cat=document.getElementById('shopCatFilt')&&document.getElementById('shopCatFilt').value;
  var f=allProds.filter(function(p){return !cat||(p.cat||p.cat_name||'')===cat});
  var g=document.getElementById('s-shopGrid'); if(g) g.innerHTML=f.map(function(p){return pCard(p,true)}).join('')||'<p style="color:var(--muted);padding:20px">No products found.</p>';
}
function renderLandingProds(){
  var deals=allProds.filter(function(p){return p.disc>0&&(p.stock||p.stock_qty)>0}).slice(0,4);
  var top=allProds.filter(function(p){return (p.top||p.is_top_sell)&&(p.stock||p.stock_qty)>0}).slice(0,4);
  var ld=document.getElementById('l-deals');   if(ld) ld.innerHTML=deals.map(function(p){return pCard(p,true)}).join('');
  var lt=document.getElementById('l-topsell'); if(lt) lt.innerHTML=top.map(function(p){return pCard(p,false)}).join('');
}
function filterShopQ(q){
  var cat=document.getElementById('shopCatFilt')&&document.getElementById('shopCatFilt').value;
  var f=allProds.filter(function(p){return (p.name||'').toLowerCase().includes(q.toLowerCase())&&(!cat||(p.cat||p.cat_name||'')===cat)});
  var g=document.getElementById('s-shopGrid'); if(g) g.innerHTML=f.map(function(p){return pCard(p,true)}).join('')||'<p style="color:var(--muted)">No products found.</p>';
}
function filterShopCat(v){ renderShopGrid(); }

/* ══════════════════════════════════════════════════════════════
   PRODUCT CARD HTML
══════════════════════════════════════════════════════════════ */
function pCard(p,showTimer){
  var id=p.id||p.product_id; var price=dp(p); var sell=p.sell||p.selling_price||0;
  var q=p.stock||p.stock_qty||0; var out=q<=0;
  var timer=showTimer?tl(id):'';
  return '<div class="pcard"'+(out?'':' onclick="addCartFB('+id+',this)"')+' data-n="'+(p.name||'').toLowerCase()+'" data-c="'+(p.cat||p.cat_name||'')+'">'
    +(p.disc>0?'<div class="pcard-disc-ribbon">-'+p.disc+'%</div>':'')
    +'<div class="pcard-img">'+(p.ic||p.icon_emoji||'📦')+'</div>'
    +'<div class="pcard-body">'
    +'<div class="pcard-name">'+(p.name||'')+'</div>'
    +'<div class="pcard-cat">'+(p.cat||p.cat_name||'')+'</div>'
    +'<div class="pcard-row"><div class="pcard-price"><strong>'+fmt(price)+'</strong>'+(p.disc>0?'<s>'+fmt(sell)+'</s>':'')+'</div>'
    +(timer?'<div class="pcard-timer" data-pid="'+id+'">⏱ '+timer+'</div>':'')+'</div>'
    +'<button class="pcard-add"'+(out?' disabled':'')+' onclick="event.stopPropagation()">'+(out?'Out of Stock':'🛒 Add to Cart')+'</button>'
    +'</div></div>';
}

/* ══════════════════════════════════════════════════════════════
   CART
══════════════════════════════════════════════════════════════ */
function addCart(id){
  var p=allProds.find(function(x){return (x.id||x.product_id)==id});
  if(!p) return; var q=p.stock||p.stock_qty||0; if(q<=0) return;
  if(cart[id]){ if(cart[id].qty>=q) return; cart[id].qty++; }
  else cart[id]={id:id,name:p.name,price:p.sell||p.selling_price||0,disc:p.disc||p.discount_pct||0,ic:p.ic||p.icon_emoji||'📦',stock:q,unit:p.unit||'pcs',qty:1};
  updateCartDot();
}
function addCartFB(id,btn){
  addCart(id);
  var add=btn.querySelector('.pcard-add')||btn;
  var orig=add.textContent; add.textContent='✅ Added!'; add.style.background='var(--success)';
  setTimeout(function(){add.textContent=orig;add.style.background='';},900);
}
function rmCart(id){ delete cart[id]; renderCart(); updateCartDot(); }
function chgQty(id,d){ var p=cart[id]; if(!p) return; p.qty=Math.max(1,Math.min(p.qty+d,p.stock)); renderCart(); updateCartDot(); }
function renderCart(){
  updateCartDot();
  var keys=Object.keys(cart);
  var ci=document.getElementById('cartItems'),ce=document.getElementById('cartEmpty'),cs=document.getElementById('cartSummary');
  if(!keys.length){ if(ci)ci.innerHTML=''; if(ce)ce.style.display='block'; if(cs)cs.innerHTML=''; return; }
  if(ce)ce.style.display='none';
  if(ci)ci.innerHTML=keys.map(function(id){var c=cart[id];
    return '<div class="cart-item-row"><div class="ci-icon">'+c.ic+'</div><div class="ci-info"><div class="ci-name">'+c.name+'</div><div class="ci-price">'+fmt(dp(c))+' / '+c.unit+'</div></div>'
      +'<div class="qty-ctrl"><button class="qb" onclick="chgQty('+id+',-1)">−</button><span style="min-width:22px;text-align:center;font-weight:700;font-size:.9rem">'+c.qty+'</span><button class="qb" onclick="chgQty('+id+',1)">+</button></div>'
      +'<div class="ci-total">'+fmt(dp(c)*c.qty)+'</div>'
      +'<button onclick="rmCart('+id+')" style="background:none;border:none;color:var(--danger);cursor:pointer;font-size:1.1rem;padding:4px 6px">🗑</button></div>';
  }).join('');
  var sub=cartSub(),tax=Math.round(sub*0.16),tot=sub+tax;
  if(cs)cs.innerHTML='<div class="cart-summary-box"><div class="cs-row"><span>Subtotal</span><span>'+fmt(sub)+'</span></div><div class="cs-row"><span>VAT 16%</span><span>'+fmt(tax)+'</span></div><div class="cs-row total"><span>Total (excl. delivery)</span><span>'+fmt(tot)+'</span></div></div>'
    +'<div style="display:flex;gap:10px;margin-top:14px"><button class="btn" style="flex:1" onclick="showSP(\'checkout\')">💳 Checkout</button><button class="btn3" style="flex:1" onclick="showSP(\'shop\')">🛍️ Continue</button></div>';
}
function renderCheckoutSummary(){
  var sub=cartSub(),tax=Math.round(sub*0.16),tot=sub+tax;
  var el=document.getElementById('coSummary'); if(!el) return;
  el.innerHTML=Object.values(cart).map(function(c){return '<div style="display:flex;justify-content:space-between;padding:6px 0;font-size:.85rem;border-bottom:1px solid var(--line)"><span>'+c.ic+' '+c.name+' ×'+c.qty+'</span><span style="font-weight:700">'+fmt(dp(c)*c.qty)+'</span></div>';}).join('')
    +'<div style="display:flex;justify-content:space-between;padding:7px 0;font-size:.88rem;color:var(--muted)"><span>Subtotal</span><span>'+fmt(sub)+'</span></div>'
    +'<div style="display:flex;justify-content:space-between;padding:5px 0;font-size:.88rem;color:var(--muted)"><span>VAT 16%</span><span>'+fmt(tax)+'</span></div>'
    +'<div style="display:flex;justify-content:space-between;padding:9px 0;font-weight:700;font-size:1rem;border-top:2px solid var(--line);margin-top:4px"><span>Total</span><span style="color:var(--accent)">'+fmt(tot)+'</span></div>';
}
function placeOrder(){
  if(!dbOk){ toast('Database offline — cannot process orders.','err'); return; }
  var name=document.getElementById('co-name').value.trim();
  var phone=document.getElementById('co-phone').value.trim();
  var addr=document.getElementById('co-addr').value.trim();
  var errEl=document.getElementById('co-err');
  errEl.style.display='none';
  if(!name||!phone||!addr){ errEl.textContent='Please fill Name, Phone, and Delivery Address.'; errEl.style.display='block'; return; }
  if(!Object.keys(cart).length){ errEl.textContent='Your cart is empty!'; errEl.style.display='block'; return; }
  var items=Object.values(cart).map(function(c){return {id:c.id,qty:c.qty};});
  fetch(API+'?_r=order',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({
    name:name,phone:phone,email:document.getElementById('co-email').value,
    addr:addr,delivery:document.getElementById('co-delivery').value,
    payment:document.getElementById('co-pay').value,items:items
  })}).then(function(r){return r.json();}).then(function(data){
    if(data.ok){
      cart={}; updateCartDot();
      document.getElementById('coForm').style.display='none';
      document.getElementById('orderOK').style.display='block';
      document.getElementById('orderID').textContent=data.order_no;
      // Update stock in local cache
      items.forEach(function(it){ var p=allProds.find(function(x){return (x.id||x.product_id)==it.id}); if(p){p.stock=Math.max(0,(p.stock||p.stock_qty||0)-it.qty);p.stock_qty=p.stock;} });
      toast('Order '+data.order_no+' placed!','ok');
    } else {
      errEl.textContent=data.error||'Order failed. Please try again.'; errEl.style.display='block';
    }
  }).catch(function(e){ errEl.textContent='Network error — please try again.'; errEl.style.display='block'; });
}

/* ══════════════════════════════════════════════════════════════
   OPTIONAL CUSTOMER AUTH
══════════════════════════════════════════════════════════════ */
function switchOptTab(t){ document.getElementById('optLogin').style.display=t==='login'?'block':'none'; document.getElementById('optReg').style.display=t==='reg'?'block':'none'; }
function optDoLogin(){
  if(!dbOk){ toast('DB offline','err'); return; }
  var email=document.getElementById('opt-email').value.trim();
  var pass=document.getElementById('opt-pass').value;
  var err=document.getElementById('optLoginErr'); err.style.display='none';
  fetch(API+'?_r=cust_login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({email:email,pass:pass})})
    .then(function(r){return r.json();}).then(function(d){
      if(d.ok){ document.getElementById('optAccBtn').textContent='👤 '+d.name.split(' ')[0]; closeModal('optModal'); toast('Welcome back, '+d.name.split(' ')[0]+'!','ok'); }
      else{ err.textContent=d.error||'Invalid credentials'; err.style.display='block'; }
    });
}
function optDoRegister(){
  if(!dbOk){ toast('DB offline','err'); return; }
  var name=document.getElementById('opt-name').value.trim();
  var email=document.getElementById('opt-reg-email').value.trim();
  var pass=document.getElementById('opt-reg-pass').value;
  var ok=document.getElementById('optRegOk'),err=document.getElementById('optRegErr');
  ok.style.display='none'; err.style.display='none';
  if(!name||!email||pass.length<6){ err.textContent='All fields required (password min 6 chars)'; err.style.display='block'; return; }
  fetch(API+'?_r=cust_register',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({name:name,email:email,pass:pass})})
    .then(function(r){return r.json();}).then(function(d){
      if(d.ok){ ok.textContent='Account created! Welcome, '+name.split(' ')[0]+'!'; ok.style.display='block';
        document.getElementById('optAccBtn').textContent='👤 '+name.split(' ')[0];
        setTimeout(function(){closeModal('optModal');},1500); }
      else{ err.textContent=d.error||'Registration failed'; err.style.display='block'; }
    });
}

/* ══════════════════════════════════════════════════════════════
   ADMIN AUTH
══════════════════════════════════════════════════════════════ */
function doLogin(){
  if(!dbOk){ document.getElementById('loginErr').textContent='Database offline — check DB config in index.php.'; document.getElementById('loginErr').style.display='block'; return; }
  var u=document.getElementById('lgUser').value.trim();
  var p=document.getElementById('lgPass').value;
  var err=document.getElementById('loginErr'); err.style.display='none';
  fetch(API+'?_r=admin_login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({user:u,pass:p})})
    .then(function(r){return r.json();}).then(function(d){
      if(d.ok){
        currentStaff={name:d.name,av:d.av,role:d.role,perms:d.perms};
        document.getElementById('adminLogin').style.display='none';
        document.getElementById('adminWS').style.display='block';
        initWS();
      } else {
        err.textContent=d.error||'Invalid credentials'; err.style.display='block';
      }
    }).catch(function(){ err.textContent='Network error.'; err.style.display='block'; });
}
function wsLogout(){
  fetch(API+'?_r=admin_logout').then(function(){
    currentStaff=null;
    document.getElementById('adminWS').style.display='none';
    document.getElementById('landing').style.display='block';
  });
}

/* ══════════════════════════════════════════════════════════════
   ADMIN WORKSPACE INIT
══════════════════════════════════════════════════════════════ */
var PAGE_LABELS={
  dashboard:'📊 Dashboard',products:'📦 Products',pos:'🖥️ Point of Sale',
  suppliers:'🚚 Suppliers',purchases:'📥 Purchases',inventory:'🔄 Stock Adjust',
  sales:'📋 Sales History',customers:'👥 Customers',orders:'📬 Online Orders',
  mpesa:'📱 M-Pesa Payments',alerts:'🔔 Alerts',branches:'🏪 Branches',
  reports:'📈 Reports',staff:'👔 Staff',audit:'🔍 Audit Log'
};
var PAGE_GROUPS={
  'Operations':['dashboard','pos','sales'],
  'Inventory':['products','purchases','inventory','suppliers'],
  'People':['customers','orders'],
  'Finance':['mpesa'],
  'Management':['alerts','branches','reports','staff','audit']
};
var PERMS_MAP={
  Admin:['dashboard','products','pos','suppliers','purchases','inventory','sales','customers','orders','mpesa','alerts','branches','reports','staff','audit'],
  'Store Manager':['dashboard','products','purchases','inventory','suppliers','sales','reports','alerts','branches','audit'],
  'Customer Support':['dashboard','customers','orders','alerts','sales'],
  Cashier:['dashboard','pos','sales','customers'],
};

function initWS(){
  var s=currentStaff;
  document.getElementById('wsAv').textContent=s.av;
  document.getElementById('wsName').textContent=s.name.split(' ')[0];
  document.getElementById('wsRoleChip').textContent=s.role;
  document.getElementById('wsRole').textContent=s.role;
  var pages=PERMS_MAP[s.role]||['dashboard'];
  var html='';
  Object.keys(PAGE_GROUPS).forEach(function(grp){
    var pgs=PAGE_GROUPS[grp].filter(function(p){return pages.includes(p)});
    if(!pgs.length) return;
    html+='<h4>'+grp+'</h4>';
    pgs.forEach(function(pg){
      html+='<button class="ws-nav-btn" id="nb-'+pg+'" onclick="showWS(\''+pg+'\')">'+PAGE_LABELS[pg]+'</button>';
    });
  });
  document.getElementById('wsNavContent').innerHTML=html;
  var st=document.getElementById('sidebarToggle'); if(window.innerWidth<=1024) st.style.display='block';
  showWS(pages[0]);
}
function toggleSidebar(){ document.getElementById('wsSidebar').classList.toggle('open'); }
function showWS(id){
  document.querySelectorAll('.ws-nav-btn').forEach(function(b){b.classList.remove('active')});
  var btn=document.getElementById('nb-'+id); if(btn) btn.classList.add('active');
  var wc=document.getElementById('wsContent');
  wc.innerHTML='<div class="ws-pg active"><div style="padding:40px;text-align:center;color:var(--muted)">⏳ Loading '+id+'...</div></div>';
  buildWS(id,function(html){ wc.innerHTML='<div class="ws-pg active">'+html+'</div>'; afterWS(id); });
}
function afterWS(id){
  if(id==='products'){ loadCatsAndSupps(); }
  if(id==='inventory'){ loadAdjHistory(); }
  if(id==='pos'){ loadPOSGrid(); }
  if(id==='addProdModal'){ loadCatsAndSupps(); }
}

/* ══════════════════════════════════════════════════════════════
   WS PAGE BUILDERS (async, API-powered)
══════════════════════════════════════════════════════════════ */
function buildWS(id, cb){
  switch(id){
    case 'dashboard': return buildDash(cb);
    case 'products':  return buildProds(cb);
    case 'pos':       return cb(buildPOS());
    case 'suppliers': return buildSuppliers(cb);
    case 'purchases': return buildPurchases(cb);
    case 'inventory': return cb(buildInventory());
    case 'sales':     return buildSales(cb);
    case 'customers': return buildCustomers(cb);
    case 'orders':    return buildOrders(cb);
    case 'mpesa':     return buildMpesa(cb);
    case 'alerts':    return buildAlerts(cb);
    case 'branches':  return buildBranches(cb);
    case 'reports':   return buildReports(cb);
    case 'staff':     return buildStaff(cb);
    case 'audit':     return buildAudit(cb);
    default: return cb('<p>Coming soon.</p>');
  }
}

function kpi(label,val,sub,mod){ return '<div class="kpi'+(mod?' '+mod:'')+'" ><div class="kpi-lbl">'+label+'</div><div class="kpi-val">'+val+'</div><div class="kpi-sub">'+sub+'</div></div>'; }

function buildDash(cb){
  if(!dbOk){ return cb('<div class="ws-pg-header"><h2>📊 Dashboard</h2></div><div style="padding:30px;text-align:center;color:var(--muted)">⚠️ Database offline. Please configure MySQL connection.</div>'); }
  fetch(API+'?_r=dashboard').then(function(r){return r.json();}).then(function(d){
    var html='<div class="ws-pg-header"><h2>📊 Dashboard</h2><span style="font-size:.85rem;color:var(--muted)">Welcome, '+currentStaff.name.split(' ')[0]+'</span></div>'
      +'<div class="kpi-grid">'
      +kpi("Today's Revenue",fmt(d.today_revenue),d.today_sales+' transactions','')
      +kpi('Total Products',d.total_products,'Active in system','')
      +kpi('Low Stock Alerts',d.low_stock,'Items need reorder','warn')
      +kpi('Stock Value',fmt(d.stock_value),'At buying price','')
      +kpi('Pending Orders',d.pending_orders,'Online orders','info')
      +kpi('Customers',d.total_customers,'Registered','')
      +kpi('Month Revenue',fmt(d.month_revenue),'This month','')
      +kpi('Critical Alerts',d.crit_alerts,'Unresolved',d.crit_alerts>0?'danger':'')
      +'</div>';
    // Recent sales
    fetch(API+'?_r=sales').then(function(r){return r.json();}).then(function(sales){
      html+='<div class="tcard"><div class="tcard-hdr"><h3>📋 Recent Sales</h3><button class="btn3 btn-sm" onclick="showWS(\'sales\')">View all</button></div><div class="twrap"><table><thead><tr><th>Receipt</th><th>Cashier</th><th>Total</th><th>Method</th><th>Date</th></tr></thead><tbody>'
        +(sales.length?sales.slice(0,5).map(function(s){return '<tr><td><strong>'+s.receipt_no+'</strong></td><td>'+s.cashier+'</td><td>'+fmt(s.total_amount)+'</td><td>'+s.payment_method+'</td><td>'+s.sale_date+'</td></tr>';}).join('')
        :'<tr><td colspan="5" style="text-align:center;padding:18px;color:var(--muted)">No sales yet — process from POS</td></tr>')
        +'</tbody></table></div></div>';
      fetch(API+'?_r=alerts').then(function(r){return r.json();}).then(function(alerts){
        html+='<div class="tcard"><div class="tcard-hdr"><h3>🔔 Critical Alerts</h3><button class="btn3 btn-sm" onclick="showWS(\'alerts\')">View all</button></div><div style="padding:12px">'
          +alerts.filter(function(a){return a.sev==='Critical';}).slice(0,3).map(function(a){return '<div style="padding:8px 10px;background:rgba(220,38,38,.06);border:1px solid rgba(220,38,38,.2);border-radius:9px;margin-bottom:8px"><div style="font-size:.85rem;font-weight:700;color:var(--danger)">'+a.title+'</div><div style="font-size:.78rem;color:var(--muted);margin-top:3px">'+a.description+'</div></div>';}).join('')
          +(alerts.filter(function(a){return a.sev==='Critical';}).length===0?'<p style="text-align:center;padding:12px;color:var(--muted)">✅ No critical alerts</p>':'')
          +'</div></div>';
        cb(html);
      });
    });
  }).catch(function(){ cb('<p style="color:var(--danger)">Error loading dashboard.</p>'); });
}

function buildProds(cb){
  if(!dbOk) return cb('<p style="color:var(--muted);padding:20px">Database offline.</p>');
  fetch(API+'?_r=admin_products').then(function(r){return r.json();}).then(function(prods){
    var canEdit=currentStaff.role==='Admin'||currentStaff.role==='Store Manager';
    var html='<div class="ws-pg-header"><h2>📦 Products ('+prods.length+')</h2>'
      +(canEdit?'<button class="btn btn-sm" onclick="openAddProdModal()">➕ Add Product</button>':'')+'</div>'
      +'<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">'
      +'<input type="text" placeholder="Search..." id="ptSearch" oninput="filterPT(this.value,\''+encodeURIComponent(JSON.stringify(prods))+'\','+canEdit+')" style="flex:1;min-width:140px;padding:9px 12px;border:1.5px solid var(--line);border-radius:9px;font:500 .88rem var(--font);background:var(--panel2);color:var(--ink)">'
      +'</div>'
      +'<div class="tcard"><div class="twrap"><table><thead><tr><th>SKU</th><th>Product</th><th>Category</th><th>Buy</th><th>Sell</th><th>Disc</th><th>Stock</th><th>Status</th><th>Margin</th>'+(canEdit?'<th>Actions</th>':'')+'</tr></thead>'
      +'<tbody id="prodTbody">'+renderProdRows(prods,canEdit)+'</tbody></table></div></div>';
    cb(html);
  }).catch(function(){ cb('<p style="color:var(--danger)">Error loading products.</p>'); });
}
function renderProdRows(list,canEdit){
  return list.map(function(p){
    var q=p.stock_qty||0; var r=p.reorder_level||10;
    var s=q<=0?{lbl:'Out of Stock',cls:'b-red'}:q<=r?{lbl:'Reorder Now',cls:'b-red'}:q<=r*1.5?{lbl:'Low Stock',cls:'b-amber'}:{lbl:'In Stock',cls:'b-green'};
    var mg=p.selling_price>0?Math.round(((p.selling_price-p.buying_price)/p.selling_price)*100):0;
    return '<tr>'
      +'<td><code style="font-size:.78rem">'+p.sku+'</code></td>'
      +'<td>'+(p.icon_emoji||'📦')+' <strong>'+p.name+'</strong></td>'
      +'<td><span class="badge b-muted">'+(p.cat_name||p.cat||'—')+'</span></td>'
      +'<td>'+Math.round(p.buying_price||0)+'</td><td>'+Math.round(p.selling_price||0)+'</td>'
      +'<td>'+(p.discount_pct>0?'<span class="badge b-red">-'+p.discount_pct+'%</span>':'—')+'</td>'
      +'<td style="font-weight:700;color:'+(q<=0?'var(--danger)':q<=r?'var(--warning)':'inherit')+'">'+q+'</td>'
      +'<td><span class="badge '+s.cls+'">'+s.lbl+'</span></td>'
      +'<td style="color:'+(mg>=25?'var(--success)':mg>=15?'var(--warning)':'var(--danger)')+'">'+mg+'%</td>'
      +(canEdit?'<td style="white-space:nowrap;display:flex;gap:4px"><button class="btn3 btn-xs" onclick="quickStock('+p.product_id+')">Stock</button><button class="btn-danger btn-xs" onclick="delProd('+p.product_id+')">Del</button></td>':'')
      +'</tr>';
  }).join('')||'<tr><td colspan="10" style="text-align:center;padding:20px;color:var(--muted)">No products</td></tr>';
}
function filterPT(q){
  var all=[];
  try{ all=JSON.parse(decodeURIComponent(document.getElementById('ptSearch').dataset.prods||'[]')); } catch(e){}
  var f=all.filter(function(p){return (p.name||'').toLowerCase().includes(q.toLowerCase())||(p.sku||'').toLowerCase().includes(q.toLowerCase());});
  var el=document.getElementById('prodTbody');
  if(el) el.innerHTML=renderProdRows(f,currentStaff.role==='Admin'||currentStaff.role==='Store Manager');
}
function quickStock(id){
  var n=parseInt(prompt('New stock quantity:'));
  if(isNaN(n)||n<0) return;
  fetch(API+'?_r=quick_stock',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:id,qty:n})})
    .then(function(r){return r.json();}).then(function(d){ if(d.ok){toast('Stock updated','ok');showWS('products');}else{toast(d.error||'Error','err');} });
}
function delProd(id){
  if(!confirm('Deactivate this product?')) return;
  fetch(API+'?_r=del_product',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:id})})
    .then(function(r){return r.json();}).then(function(d){ if(d.ok){toast('Product removed','ok');showWS('products');}else{toast(d.error||'Error','err');} });
}

/* ── Open add/edit product modal (load cats + supps first) ── */
function openAddProdModal(){
  document.getElementById('np-id').value='0';
  document.getElementById('np-name').value='';
  document.getElementById('np-sku').value='';
  document.getElementById('np-buy').value='';
  document.getElementById('np-sell').value='';
  document.getElementById('np-stock').value='0';
  document.getElementById('np-disc').value='0';
  document.getElementById('np-timer').value='0';
  document.getElementById('np-reorder').value='10';
  document.getElementById('np-icon').value='';
  document.getElementById('np-unit').value='pcs';
  document.getElementById('np-barcode').value='';
  loadCatsAndSupps(function(){ openModal('addProdModal'); });
}
function loadCatsAndSupps(cb){
  if(!dbOk){ if(cb) cb(); return; }
  Promise.all([fetch(API+'?_r=categories').then(function(r){return r.json();}), fetch(API+'?_r=suppliers').then(function(r){return r.json();}) ])
    .then(function(res){
      var cats=res[0],supps=res[1];
      var catSel=document.getElementById('np-cat');
      if(catSel) catSel.innerHTML=cats.map(function(c){return '<option value="'+c.cat_id+'">'+c.icon+' '+c.name+'</option>';}).join('');
      var sSel=document.getElementById('np-supp');
      if(sSel) sSel.innerHTML='<option value="">No supplier</option>'+supps.map(function(s){return '<option value="'+s.supplier_id+'">'+s.name+'</option>';}).join('');
      if(cb) cb();
    }).catch(function(){ if(cb) cb(); });
}
function saveProd(){
  var name=document.getElementById('np-name').value.trim();
  var buy=parseFloat(document.getElementById('np-buy').value);
  var sell=parseFloat(document.getElementById('np-sell').value);
  var ok=document.getElementById('addPOk'),err=document.getElementById('addPErr');
  ok.style.display='none'; err.style.display='none';
  if(!name||buy<=0||sell<=0){ err.textContent='Name, buying & selling price required.'; err.style.display='block'; return; }
  var payload={id:parseInt(document.getElementById('np-id').value)||0,name:name,sku:document.getElementById('np-sku').value.trim(),barcode:document.getElementById('np-barcode').value.trim()||null,ic:document.getElementById('np-icon').value||'📦',cat_id:parseInt(document.getElementById('np-cat').value)||null,supp_id:parseInt(document.getElementById('np-supp').value)||null,buy:buy,sell:sell,stock:parseInt(document.getElementById('np-stock').value)||0,reorder:parseInt(document.getElementById('np-reorder').value)||10,disc:parseFloat(document.getElementById('np-disc').value)||0,timer:parseInt(document.getElementById('np-timer').value)||0,unit:document.getElementById('np-unit').value||'pcs',top:parseInt(document.getElementById('np-top').value)||0};
  fetch(API+'?_r=save_product',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)})
    .then(function(r){return r.json();}).then(function(d){
      if(d.ok){ ok.textContent=(payload.id?'Updated':'Saved')+': '+name+(d.sku?' ('+d.sku+')':''); ok.style.display='block'; setTimeout(function(){ closeModal('addProdModal'); showWS('products'); },1400); }
      else{ err.textContent=d.error||'Failed to save.'; err.style.display='block'; }
    });
}

/* ── POS ── */
var adminProds=[];
function loadPOSGrid(){
  if(!dbOk){ var g=document.getElementById('posPG'); if(g) g.innerHTML='<p style="color:var(--muted)">Database offline.</p>'; return; }
  fetch(API+'?_r=admin_products').then(function(r){return r.json();}).then(function(prods){
    adminProds=prods;
    var g=document.getElementById('posPG');
    if(g) g.innerHTML=renderPOSCards(prods);
  });
}
function renderPOSCards(prods){
  return prods.map(function(p){
    var out=p.stock_qty<=0;
    var price=p.discount_pct>0?Math.round(p.selling_price*(1-p.discount_pct/100)):Math.round(p.selling_price);
    return '<div class="pos-pc'+(out?' out':'')+'"'+(out?'':' onclick="posAdd('+p.product_id+')"')+' data-s="'+(p.name||'').toLowerCase()+'" data-c="'+(p.cat_name||'')+'">'
      +'<div class="pos-pc-ic">'+(p.icon_emoji||'📦')+'</div>'
      +'<div class="pos-pc-name">'+p.name+'</div>'
      +'<div class="pos-pc-price">'+fmt(price)+'</div>'
      +'<div class="pos-pc-stock" style="color:'+(out?'var(--danger)':p.stock_qty<=p.reorder_level?'var(--warning)':'var(--muted)'+'">')+(out?'Out':p.stock_qty+' left')+'</div></div>';
  }).join('');
}
function buildPOS(){
  return '<div class="ws-pg-header"><h2>🖥️ Point of Sale</h2><span class="badge b-blue">'+currentStaff.name+' · '+currentStaff.role+'</span></div>'
    +'<div class="pos-layout">'
    +'<div><div style="display:flex;gap:8px;margin-bottom:10px;flex-wrap:wrap">'
    +'<input type="text" placeholder="Search products..." oninput="posFilt(this.value)" style="flex:1;min-width:120px;padding:8px 12px;border:1.5px solid var(--line);border-radius:9px;font:500 .85rem var(--font);background:var(--panel2);color:var(--ink)">'
    +'<select id="posCat" onchange="posFiltCat(this.value)" style="padding:8px 12px;border:1.5px solid var(--line);border-radius:9px;font:500 .85rem var(--font);background:var(--panel2);color:var(--ink)"><option value="">All</option><option>Grains &amp; Flour</option><option>Cooking Oils</option><option>Dairy &amp; Eggs</option><option>Beverages</option><option>Snacks &amp; Confectionery</option><option>Fresh Produce</option><option>Bakery &amp; Pastries</option><option>Meat &amp; Fish</option><option>Rice &amp; Pasta</option><option>Frozen Foods</option></select>'
    +'</div><div class="pos-pgrid" id="posPG"><p style="color:var(--muted)">Loading...</p></div></div>'
    +'<div class="pos-panel"><div class="pos-panel-hdr"><h3>🛒 Cart</h3><span id="posCnt" style="font-size:.8rem;color:var(--muted)">0 items</span></div>'
    +'<div class="pos-cart-body" id="posCB"><div style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:140px;color:var(--muted);font-size:.85rem;gap:6px;opacity:.5"><span style="font-size:2rem">🛒</span>Tap product to add</div></div>'
    +'<div class="pos-totals"><div class="pos-tr"><span>Subtotal</span><span id="posSub">KSh 0</span></div><div class="pos-tr"><span>VAT 16%</span><span id="posTax">KSh 0</span></div><div class="pos-tr grand"><span>TOTAL</span><span id="posTot">KSh 0</span></div></div>'
    +'<div class="pos-pay"><div class="pay-grid"><button class="pm-btn active" onclick="selPM(\'Cash\',this)">💵 Cash</button><button class="pm-btn" onclick="selPM(\'M-PESA\',this)">📱 M-PESA</button><button class="pm-btn" onclick="selPM(\'Card\',this)">💳 Card</button><button class="pm-btn" onclick="selPM(\'Credit\',this)">📒 Credit</button></div>'
    +'<input type="number" id="posPaid" placeholder="Amount paid (KSh)" oninput="posCalcChange()" style="width:100%;padding:9px 12px;border:1.5px solid var(--line);border-radius:9px;font:500 .88rem var(--font);background:var(--panel2);color:var(--ink);margin-bottom:10px">'
    +'<div id="posChg" style="font-size:.85rem;color:var(--success);margin-bottom:8px;display:none;font-weight:600"></div>'
    +'<button class="pos-charge" onclick="posSale()">✅ Process Sale</button></div></div></div>';
}
function posAdd(id){
  var p=adminProds.find(function(x){return x.product_id===id});
  if(!p||p.stock_qty<=0) return;
  var price=p.discount_pct>0?Math.round(p.selling_price*(1-p.discount_pct/100)):Math.round(p.selling_price);
  if(posCart[id]){ if(posCart[id].qty>=p.stock_qty) return; posCart[id].qty++; }
  else posCart[id]={id:id,name:p.name,ic:p.icon_emoji||'📦',price:price,disc:p.discount_pct||0,qty:1,stock:p.stock_qty};
  renderPosCart();
}
function posRm(id){ delete posCart[id]; renderPosCart(); }
function posChgQ(id,d){ var p=posCart[id]; if(!p) return; p.qty=Math.max(1,Math.min(p.qty+d,p.stock)); renderPosCart(); }
function renderPosCart(){
  var items=Object.values(posCart);
  var cnt=document.getElementById('posCnt'); if(cnt) cnt.textContent=items.length+' item'+(items.length!==1?'s':'');
  var cb=document.getElementById('posCB'); if(!cb) return;
  if(!items.length){ cb.innerHTML='<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:140px;color:var(--muted);font-size:.85rem;gap:6px;opacity:.5"><span style="font-size:2rem">🛒</span>Cart is empty</div>'; posCalcTots(); return; }
  cb.innerHTML=items.map(function(it){
    return '<div class="pos-ci"><span style="font-size:1.3rem">'+it.ic+'</span><div class="pos-ci-name">'+it.name+'<br><span style="font-size:.72rem;color:var(--muted)">'+fmt(it.price)+' ea</span></div>'
      +'<div style="display:flex;align-items:center;gap:4px"><button class="qb" onclick="posChgQ('+it.id+',-1)" style="width:24px;height:24px">−</button>'
      +'<span style="min-width:18px;text-align:center;font-size:.85rem;font-weight:700">'+it.qty+'</span>'
      +'<button class="qb" onclick="posChgQ('+it.id+',1)" style="width:24px;height:24px">+</button></div>'
      +'<div class="pos-ci-total">'+fmt(it.price*it.qty)+'</div>'
      +'<button onclick="posRm('+it.id+')" style="background:none;border:none;color:var(--danger);cursor:pointer;font-size:.95rem;padding:2px">×</button></div>';
  }).join('');
  posCalcTots();
}
function posCalcTots(){
  var sub=Object.values(posCart).reduce(function(a,it){return a+it.price*it.qty;},0);
  var tax=Math.round(sub*0.16),tot=sub+tax;
  var es=document.getElementById('posSub'),et=document.getElementById('posTax'),eg=document.getElementById('posTot');
  if(es) es.textContent=fmt(sub); if(et) et.textContent=fmt(tax); if(eg) eg.textContent=fmt(tot);
  posCalcChange();
}
function posCalcChange(){
  var totEl=document.getElementById('posTot'); if(!totEl) return;
  var tot=parseInt((totEl.textContent||'0').replace(/KSh |,/g,''))||0;
  var paid=parseFloat((document.getElementById('posPaid')||{value:0}).value)||0;
  var cd=document.getElementById('posChg'); if(!cd) return;
  if(paid>=tot&&paid>0){cd.style.display='block';cd.textContent='Change: '+fmt(paid-tot);}else{cd.style.display='none';}
}
function selPM(m,el){ posPM=m; document.querySelectorAll('.pm-btn').forEach(function(b){b.classList.remove('active');}); el.classList.add('active'); }
function posFilt(q){ document.querySelectorAll('.pos-pc').forEach(function(c){c.style.display=(!q||c.dataset.s.includes(q.toLowerCase()))&&(!posCatF||c.dataset.c===posCatF)?'':'none';}); }
var posCatF='';
function posFiltCat(v){ posCatF=v; document.querySelectorAll('.pos-pc').forEach(function(c){c.style.display=(!v||c.dataset.c===v)?'':'none';}); }
function posSale(){
  var items=Object.values(posCart); if(!items.length){ toast('Add items first.','err'); return; }
  if(!dbOk){ toast('DB offline — cannot save sale.','err'); return; }
  var sub=items.reduce(function(a,it){return a+it.price*it.qty;},0);
  var tax=Math.round(sub*0.16),tot=sub+tax;
  var paid=parseFloat((document.getElementById('posPaid')||{value:tot}).value)||tot;
  if(paid<tot&&posPM==='Cash'){ toast('Amount paid less than total.','err'); return; }
  var payload={items:items.map(function(it){return {id:it.id,qty:it.qty,price:it.price,disc:it.disc};}),method:posPM,paid:paid,mpesa:'',cashier:currentStaff.name};
  fetch(API+'?_r=pos_sale',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)})
    .then(function(r){return r.json();}).then(function(d){
      if(d.ok){
        var rct=d.receipt; var date=new Date();
        document.getElementById('receiptTxt').textContent=
          '================================\n        BIDHAABORA\n   Smart Grocery Management\n================================\nReceipt : '+rct+'\nDate    : '+date.toLocaleDateString('en-KE')+' '+date.toLocaleTimeString('en-KE',{hour:'2-digit',minute:'2-digit'})+'\nCashier : '+currentStaff.name+'\n--------------------------------\n'
          +items.map(function(it){return it.name.substring(0,16).padEnd(16)+' x'+it.qty+'\n  '+fmt(it.price)+' ea     '+fmt(it.price*it.qty);}).join('\n')
          +'\n--------------------------------\nSubtotal          '+fmt(d.sub||sub)+'\nVAT 16%           '+fmt(d.tax||tax)+'\nTOTAL             '+fmt(d.total||tot)+'\n'+posPM+'            '+fmt(paid)+'\n'+(paid>tot?'Change            '+fmt(d.change||paid-tot)+'\n':'')
          +'================================\n   Thank you — BidhaaBora!';
        openModal('receiptModal');
        // Update local admin product stocks
        items.forEach(function(it){ var p=adminProds.find(function(x){return x.product_id===it.id;}); if(p) p.stock_qty=Math.max(0,p.stock_qty-it.qty); });
        posCart={}; renderPosCart(); toast('Sale processed: '+rct,'ok');
      } else { toast(d.error||'Sale failed','err'); }
    }).catch(function(){ toast('Network error','err'); });
}

/* ── Suppliers ── */
function buildSuppliers(cb){
  if(!dbOk) return cb('<p style="color:var(--muted);padding:20px">Database offline.</p>');
  var canEdit=currentStaff.role==='Admin'||currentStaff.role==='Store Manager';
  fetch(API+'?_r=suppliers').then(function(r){return r.json();}).then(function(supps){
    var html='<div class="ws-pg-header"><h2>🚚 Suppliers</h2>'+(canEdit?'<button class="btn btn-sm" onclick="openModal(\'addSuppModal\')">➕ Add Supplier</button>':'')+'</div>'
      +'<div class="tcard"><div class="twrap"><table><thead><tr><th>Supplier</th><th>Contact</th><th>Categories</th><th>Lead Time</th><th>Last Order</th><th>Status</th>'+(canEdit?'<th>Edit</th>':'')+'</tr></thead><tbody>'
      +supps.map(function(s){return '<tr><td><strong>'+s.name+'</strong></td><td><a href="mailto:'+s.email+'" style="color:var(--accent)">'+s.email+'</a></td><td>'+(s.categories||'—')+'</td><td>'+(s.lead_time||'—')+'</td><td>'+(s.last_order?s.last_order.substr(0,10):'—')+'</td><td><span class="badge '+(s.status==='Active'?'b-green':s.status==='Delayed'?'b-red':'b-amber')+'">'+s.status+'</span></td>'+(canEdit?'<td><button class="btn3 btn-xs" onclick="alert(\'Edit supplier — coming soon\')">Edit</button></td>':'')+'</tr>';}).join('')
      +'</tbody></table></div></div>';
    cb(html);
  });
}
function saveSupplier(){
  var name=document.getElementById('ns-name').value.trim(); var email=document.getElementById('ns-email').value.trim();
  var ok=document.getElementById('addSOk'),err=document.getElementById('addSErr');
  ok.style.display='none'; err.style.display='none';
  if(!name){ err.textContent='Name required.'; err.style.display='block'; return; }
  fetch(API+'?_r=save_supplier',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({name:name,contact:document.getElementById('ns-contact').value,email:email,phone:document.getElementById('ns-phone').value,cats:document.getElementById('ns-cats').value,lead:document.getElementById('ns-lead').value})})
    .then(function(r){return r.json();}).then(function(d){
      if(d.ok){ ok.textContent='Supplier "'+name+'" added!'; ok.style.display='block'; setTimeout(function(){closeModal('addSuppModal');ok.style.display='none';showWS('suppliers');},1300); }
      else{ err.textContent=d.error||'Failed'; err.style.display='block'; }
    });
}

/* ── Purchases ── */
function buildPurchases(cb){
  if(!dbOk) return cb('<p style="color:var(--muted);padding:20px">Database offline.</p>');
  fetch(API+'?_r=purchases').then(function(r){return r.json();}).then(function(pos){
    var html='<div class="ws-pg-header"><h2>📥 Purchase Orders</h2><button class="btn btn-sm" onclick="alert(\'Create PO — coming in next sprint\')">➕ New PO</button></div>'
      +'<div class="tcard"><div class="twrap"><table><thead><tr><th>PO #</th><th>Supplier</th><th>Raised By</th><th>PO Date</th><th>Expected</th><th>Total</th><th>Status</th></tr></thead><tbody>'
      +(pos.length?pos.map(function(p){var sc=p.status==='Received'?'b-green':p.status==='Approved'?'b-blue':p.status==='Submitted'?'b-amber':'b-muted';return '<tr><td><strong>'+p.po_number+'</strong></td><td>'+p.supplier_name+'</td><td>'+p.raised_by+'</td><td>'+(p.po_date||'—')+'</td><td>'+(p.expected_date||'—')+'</td><td>'+fmt(p.total_amount||0)+'</td><td><span class="badge '+sc+'">'+p.status+'</span></td></tr>';}).join(''):'<tr><td colspan="7" style="text-align:center;padding:20px;color:var(--muted)">No purchase orders yet</td></tr>')
      +'</tbody></table></div></div>';
    cb(html);
  });
}

/* ── Inventory adjustment ── */
function buildInventory(){
  return '<div class="ws-pg-header"><h2>🔄 Stock Adjustments</h2></div>'
    +'<div class="card" style="margin-bottom:16px"><div class="msg-ok" id="adjOk"></div>'
    +'<div class="form-2">'
    +'<div class="fg"><label>Product ID *</label><input id="adjPid" type="number" min="1" placeholder="Product ID (see Products page)"></div>'
    +'<div class="fg"><label>Type *</label><select id="adjT"><option value="In">In (receive)</option><option value="Out">Out (remove)</option><option value="Damaged">Damaged</option><option value="Expired">Expired</option><option value="Return">Return</option><option value="Correction">Correction</option><option value="Waste">Waste</option></select></div>'
    +'<div class="fg"><label>Quantity *</label><input id="adjQ" type="number" min="1" value="1"></div>'
    +'<div class="fg"><label>Reference</label><input id="adjRef" type="text" placeholder="Optional"></div>'
    +'</div><div class="fg"><label>Reason *</label><input id="adjRsn" type="text" placeholder="Why is this adjustment needed?"></div>'
    +'<div style="display:flex;justify-content:flex-end"><button class="btn btn-sm" onclick="saveAdj()">💾 Save Adjustment</button></div></div>'
    +'<div class="tcard" id="adjHistCard"><div class="tcard-hdr"><h3>Adjustment History</h3></div><div class="twrap"><table><thead><tr><th>Product</th><th>Type</th><th>Qty</th><th>Reason</th><th>By</th><th>Date</th></tr></thead><tbody id="adjTbl"><tr><td colspan="6" style="text-align:center;padding:18px;color:var(--muted)">Loading...</td></tr></tbody></table></div></div>';
}
function loadAdjHistory(){
  if(!dbOk){ var t=document.getElementById('adjTbl'); if(t)t.innerHTML='<tr><td colspan="6" style="text-align:center;padding:18px;color:var(--muted)">DB offline</td></tr>'; return; }
  fetch(API+'?_r=adjustments').then(function(r){return r.json();}).then(function(adjs){
    var tbl=document.getElementById('adjTbl'); if(!tbl) return;
    tbl.innerHTML=adjs.length?adjs.map(function(a){return '<tr><td>'+a.product_name+'</td><td><span class="badge '+(a.adj_type==='In'||a.adj_type==='Return'?'b-green':'b-amber')+'">'+a.adj_type+'</span></td><td>'+a.quantity+'</td><td>'+a.reason+'</td><td>'+a.by_name+'</td><td style="font-size:.8rem">'+a.adj_date+'</td></tr>';}).join('')
    :'<tr><td colspan="6" style="text-align:center;padding:18px;color:var(--muted)">No adjustments yet</td></tr>';
  });
}
function saveAdj(){
  var pid=parseInt(document.getElementById('adjPid').value)||0;
  var type=document.getElementById('adjT').value;
  var qty=parseFloat(document.getElementById('adjQ').value)||0;
  var rsn=document.getElementById('adjRsn').value.trim();
  var ok=document.getElementById('adjOk');
  if(!pid||qty<=0||!rsn){ toast('Fill all required fields','err'); return; }
  fetch(API+'?_r=stock_adj',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({pid:pid,type:type,qty:qty,reason:rsn,ref:document.getElementById('adjRef').value})})
    .then(function(r){return r.json();}).then(function(d){
      if(d.ok){ ok.textContent='Saved. New stock: '+d.new_stock; ok.style.display='block'; loadAdjHistory(); setTimeout(function(){ok.style.display='none';},3500); }
      else{ toast(d.error||'Failed','err'); }
    });
}

/* ── Sales History ── */
function buildSales(cb){
  if(!dbOk) return cb('<p style="color:var(--muted);padding:20px">Database offline.</p>');
  fetch(API+'?_r=sales').then(function(r){return r.json();}).then(function(sales){
    var tot=sales.reduce(function(a,s){return a+(parseFloat(s.total_amount)||0);},0);
    var html='<div class="ws-pg-header"><h2>📋 Sales History</h2></div>'
      +'<div class="kpi-grid" style="margin-bottom:16px">'
      +kpi('Total Revenue',fmt(tot),sales.length+' transactions','')
      +kpi('Cash',fmt(sales.filter(function(s){return s.payment_method==='Cash';}).reduce(function(a,s){return a+(parseFloat(s.total_amount)||0);},0)),'Cash payments','')
      +kpi('M-PESA',fmt(sales.filter(function(s){return s.payment_method==='M-PESA';}).reduce(function(a,s){return a+(parseFloat(s.total_amount)||0);},0)),'M-PESA payments','info')
      +'</div>'
      +'<div class="tcard"><div class="twrap"><table><thead><tr><th>Receipt</th><th>Cashier</th><th>Subtotal</th><th>VAT</th><th>Total</th><th>Paid</th><th>Change</th><th>Method</th><th>Date</th><th>Status</th></tr></thead><tbody>'
      +(sales.length?sales.map(function(s){return '<tr><td><strong>'+s.receipt_no+'</strong></td><td>'+s.cashier+'</td><td>'+fmt(s.subtotal||0)+'</td><td>'+fmt(s.tax_amount||0)+'</td><td><strong>'+fmt(s.total_amount||0)+'</strong></td><td>'+fmt(s.amount_paid||0)+'</td><td>'+fmt(s.change_given||0)+'</td><td>'+s.payment_method+'</td><td style="font-size:.8rem;white-space:nowrap">'+s.sale_date+'</td><td><span class="badge '+(s.status==='Completed'?'b-green':'b-red')+'">'+s.status+'</span></td></tr>';}).join(''):'<tr><td colspan="10" style="text-align:center;padding:20px;color:var(--muted)">No sales yet — process from POS</td></tr>')
      +'</tbody></table></div></div>';
    cb(html);
  });
}

/* ── Customers ── */
function buildCustomers(cb){
  if(!dbOk) return cb('<p style="color:var(--muted);padding:20px">Database offline.</p>');
  fetch(API+'?_r=customers').then(function(r){return r.json();}).then(function(custs){
    var html='<div class="ws-pg-header"><h2>👥 Customers ('+custs.length+')</h2><button class="btn btn-sm" onclick="openModal(\'addCustModal\')">➕ Add Customer</button></div>'
      +'<div class="tcard"><div class="twrap"><table><thead><tr><th>Name</th><th>Phone</th><th>Email</th><th>Type</th><th>Source</th><th>Points</th><th>Total Spent</th></tr></thead><tbody>'
      +(custs.length?custs.map(function(c){return '<tr><td><strong>'+c.full_name+'</strong></td><td>'+(c.phone||'—')+'</td><td>'+(c.email||'—')+'</td><td><span class="badge '+(c.customer_type==='Wholesale'?'b-blue':c.customer_type==='Corporate'?'b-purple':'b-green')+'">'+c.customer_type+'</span></td><td><span class="badge '+(c.from_online?'b-amber':'b-muted')+'">'+(c.from_online?'Online':'Walk-in')+'</span></td><td>'+c.loyalty_pts+' pts</td><td>'+fmt(c.total_spent||0)+'</td></tr>';}).join(''):'<tr><td colspan="7" style="text-align:center;padding:20px;color:var(--muted)">No customers yet</td></tr>')
      +'</tbody></table></div></div>';
    cb(html);
  });
}
function saveCustomer(){
  var name=document.getElementById('nc-name').value.trim();var phone=document.getElementById('nc-phone').value.trim();
  var ok=document.getElementById('addCOk'); ok.style.display='none';
  if(!name||!phone){ toast('Name and phone required.','err'); return; }
  fetch(API+'?_r=save_customer',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({name:name,phone:phone,email:document.getElementById('nc-email').value,type:document.getElementById('nc-type').value})})
    .then(function(r){return r.json();}).then(function(d){
      if(d.ok){ ok.textContent='Customer "'+name+'" added!'; ok.style.display='block'; setTimeout(function(){closeModal('addCustModal');ok.style.display='none';showWS('customers');},1300); }
      else{ toast(d.error||'Error','err'); }
    });
}

/* ── Online Orders ── */
function buildOrders(cb){
  if(!dbOk) return cb('<p style="color:var(--muted);padding:20px">Database offline.</p>');
  fetch(API+'?_r=online_orders').then(function(r){return r.json();}).then(function(ords){
    var pending=ords.filter(function(o){return o.status==='Pending';}).length;
    var html='<div class="ws-pg-header"><h2>📬 Online Orders</h2><span class="badge b-amber">'+pending+' Pending</span></div>'
      +'<div class="tcard"><div class="twrap"><table><thead><tr><th>Order #</th><th>Customer</th><th>Phone</th><th>Total</th><th>Payment</th><th>Delivery</th><th>Status</th><th>Date</th><th>Update</th></tr></thead><tbody>'
      +(ords.length?ords.map(function(o){var sc=o.status==='Pending'?'b-amber':o.status==='Delivered'?'b-green':'b-blue';return '<tr><td><strong>'+o.order_no+'</strong></td><td>'+o.cust_name+'</td><td>'+o.cust_phone+'</td><td>'+fmt(o.total_amount||0)+'</td><td>'+o.payment_method+'</td><td>'+o.delivery_method+'</td><td><span class="badge '+sc+'">'+o.status+'</span></td><td style="font-size:.8rem;white-space:nowrap">'+o.created_at+'</td><td><select onchange="updOrder('+o.order_id+',this.value)" style="font-size:.78rem;padding:4px;border:1px solid var(--line);border-radius:6px;background:var(--panel);color:var(--ink);font-family:var(--font)"><option value="">Update…</option><option>Pending</option><option>Confirmed</option><option>Dispatched</option><option>Delivered</option><option>Cancelled</option></select></td></tr>';}).join(''):'<tr><td colspan="9" style="text-align:center;padding:20px;color:var(--muted)">No online orders yet</td></tr>')
      +'</tbody></table></div></div>';
    cb(html);
  });
}
function updOrder(id,status){
  if(!status) return;
  fetch(API+'?_r=upd_order',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:id,status:status})})
    .then(function(r){return r.json();}).then(function(d){ if(d.ok){toast('Order updated → '+status,'ok');showWS('orders');}else{toast(d.error||'Error','err');} });
}

/* ── Alerts ── */
function buildAlerts(cb){
  if(!dbOk) return cb('<p style="color:var(--muted);padding:20px">Database offline.</p>');
  fetch(API+'?_r=alerts').then(function(r){return r.json();}).then(function(als){
    var crit=als.filter(function(a){return a.sev==='Critical';}).length;
    var warn=als.filter(function(a){return a.sev==='Warning';}).length;
    var info=als.filter(function(a){return a.sev==='Info';}).length;
    var html='<div class="ws-pg-header"><h2>🔔 Alerts &amp; Notifications</h2>'
      +'<button class="btn3 btn-sm" onclick="resolveAllAlerts()">Mark all resolved</button></div>'
      +'<div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap">'
      +'<div style="background:rgba(220,38,38,.08);border:1.5px solid rgba(220,38,38,.2);border-radius:10px;padding:10px 18px;text-align:center"><div style="font-size:1.5rem;font-weight:700;color:var(--danger)">'+crit+'</div><div style="font-size:.78rem;color:var(--danger);font-weight:600">Critical</div></div>'
      +'<div style="background:rgba(217,119,6,.08);border:1.5px solid rgba(217,119,6,.2);border-radius:10px;padding:10px 18px;text-align:center"><div style="font-size:1.5rem;font-weight:700;color:var(--warning)">'+warn+'</div><div style="font-size:.78rem;color:var(--warning);font-weight:600">Warnings</div></div>'
      +'<div style="background:rgba(37,99,235,.08);border:1.5px solid rgba(37,99,235,.2);border-radius:10px;padding:10px 18px;text-align:center"><div style="font-size:1.5rem;font-weight:700;color:var(--info)">'+info+'</div><div style="font-size:.78rem;color:var(--info);font-weight:600">Info</div></div>'
      +'</div>'
      +(als.length?als.map(function(a){var col=a.sev==='Critical'?'var(--danger)':a.sev==='Warning'?'var(--warning)':'var(--info)';return '<div class="alert-item" style="border-color:'+col+'30;background:'+col+'08"><div class="alert-dot" style="background:'+col+'"></div><div class="alert-content"><div class="alert-title">'+a.title+'</div><div class="alert-desc">'+(a.description||'')+'</div></div><div class="alert-actions"><span class="badge" style="background:'+col+'15;color:'+col+'">'+a.sev+'</span><button class="btn3 btn-xs" onclick="resolveAlert('+a.alert_id+')">Resolve</button></div></div>';}).join(''):'<div style="text-align:center;padding:30px;color:var(--muted)">✅ All clear — no active alerts</div>');
    cb(html);
  });
}
function resolveAlert(id){
  fetch(API+'?_r=resolve_alert',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:id})})
    .then(function(r){return r.json();}).then(function(d){ if(d.ok){toast('Alert resolved','ok');showWS('alerts');}else{toast('Error','err');} });
}
function resolveAllAlerts(){
  fetch(API+'?_r=resolve_alert',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({all:true})})
    .then(function(r){return r.json();}).then(function(d){ if(d.ok){toast('All alerts resolved','ok');showWS('alerts');}else{toast('Error','err');} });
}

/* ── M-Pesa ── */
function buildMpesa(cb){
  if(!dbOk) return cb('<p style="color:var(--muted);padding:20px">Database offline.</p>');
  fetch(API+'?_r=mpesa_txns').then(function(r){return r.json();}).then(function(txns){
    var paid=txns.filter(function(t){return t.status==='Success';}).reduce(function(a,t){return a+(parseFloat(t.amount)||0);},0);
    var pend=txns.filter(function(t){return t.status==='Pending';}).reduce(function(a,t){return a+(parseFloat(t.amount)||0);},0);
    var fail=txns.filter(function(t){return t.status==='Failed';}).reduce(function(a,t){return a+(parseFloat(t.amount)||0);},0);
    var html='<div class="ws-pg-header"><h2>📱 M-Pesa Payments</h2><span style="font-size:.82rem;color:var(--muted)">Daraja API · STK Push</span></div>'
      +'<div class="mpesa-hdr"><div style="display:flex;align-items:center;gap:10px;margin-bottom:14px"><span style="font-size:1.8rem">📱</span><div><div style="font-size:1.1rem;font-weight:700">M-PESA Business</div><div style="font-size:.85rem;opacity:.8">Daraja API — Paybill / Till Integration</div></div></div>'
      +'<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px">'
      +'<div style="background:rgba(255,255,255,.15);border-radius:10px;padding:12px"><div style="font-size:.8rem;opacity:.8;margin-bottom:4px">Paid</div><div style="font-size:1.3rem;font-weight:700">'+fmt(paid)+'</div></div>'
      +'<div style="background:rgba(255,255,255,.15);border-radius:10px;padding:12px"><div style="font-size:.8rem;opacity:.8;margin-bottom:4px">Pending</div><div style="font-size:1.3rem;font-weight:700">'+fmt(pend)+'</div></div>'
      +'<div style="background:rgba(255,255,255,.15);border-radius:10px;padding:12px"><div style="font-size:.8rem;opacity:.8;margin-bottom:4px">Failed</div><div style="font-size:1.3rem;font-weight:700">'+fmt(fail)+'</div></div>'
      +'</div></div>'
      +'<div class="mpesa-form"><h3>💸 Initiate STK Push</h3>'
      +'<div class="form-2">'
      +'<div class="fg"><label>Phone (Supplier / 254...)</label><input id="mpPhone" type="tel" placeholder="e.g. 254712345678"></div>'
      +'<div class="fg"><label>Amount (KSh)</label><input id="mpAmt" type="number" min="1" placeholder="e.g. 5000"></div>'
      +'<div class="fg"><label>Description</label><input id="mpDesc" type="text" value="BidhaaBora Payment"></div>'
      +'<div class="fg"><label>PO ID (optional)</label><input id="mpPO" type="number" placeholder="Purchase Order ID"></div>'
      +'</div>'
      +'<div id="mpStatus" style="font-size:.85rem;margin-bottom:10px"></div>'
      +'<button class="btn btn-sm" onclick="sendSTK()">📱 Send STK Push →</button></div>'
      +'<div class="tcard"><div class="tcard-hdr"><h3>Transaction History</h3></div><div class="twrap"><table><thead><tr><th>Date</th><th>Phone</th><th>Amount</th><th>Receipt</th><th>Status</th></tr></thead><tbody>'
      +(txns.length?txns.map(function(t){var sc=t.status==='Success'?'b-green':t.status==='Pending'?'b-amber':'b-red';return '<tr><td>'+t.created_at+'</td><td>'+t.phone_number+'</td><td>'+fmt(t.amount||0)+'</td><td><code style="font-size:.8rem">'+(t.mpesa_receipt||'—')+'</code></td><td><span class="badge '+sc+'">'+t.status+'</span></td></tr>';}).join(''):'<tr><td colspan="5" style="text-align:center;padding:18px;color:var(--muted)">No transactions yet</td></tr>')
      +'</tbody></table></div></div>';
    cb(html);
  });
}
function sendSTK(){
  var phone=document.getElementById('mpPhone').value.trim();var amt=document.getElementById('mpAmt').value;var desc=document.getElementById('mpDesc').value;var po=document.getElementById('mpPO').value;var st=document.getElementById('mpStatus');
  if(!phone||!amt){ toast('Phone and amount required','err'); return; }
  st.textContent='Sending STK Push...'; st.style.color='var(--muted)';
  fetch(API+'?_r=mpesa_stk',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({phone:phone,amount:parseFloat(amt),desc:desc,po_id:parseInt(po)||0})})
    .then(function(r){return r.json();}).then(function(d){
      if(d.ok){ st.textContent='✅ STK Push sent! Awaiting confirmation...'; st.style.color='var(--success)'; toast('STK Push sent','ok'); }
      else{ st.textContent='❌ '+( d.error||d.errorMessage||'Failed'); st.style.color='var(--danger)'; toast(d.error||'Failed','err'); }
    }).catch(function(){ st.textContent='Network error.'; st.style.color='var(--danger)'; });
}

/* ── Branches ── */
function buildBranches(cb){
  if(!dbOk) return cb('<p style="color:var(--muted);padding:20px">Database offline.</p>');
  fetch(API+'?_r=branches').then(function(r){return r.json();}).then(function(branches){
    var cols=['#15803d','#1d4ed8','#7c3aed','#b45309'];
    var html='<div class="ws-pg-header"><h2>🏪 Branch Management</h2></div>'
      +'<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;margin-bottom:20px">'
      +branches.map(function(b,i){return '<div class="branch-card" style="border-top-color:'+cols[i%cols.length]+'">'
        +'<div style="display:flex;align-items:center;justify-content:space-between"><h3 style="font-family:var(--font);font-size:1rem;font-weight:700">'+b.name+'</h3><span class="badge '+(b.is_active?'b-green':'b-muted')+'">'+(b.is_active?'Active':'Inactive')+'</span></div>'
        +'<div style="color:var(--muted);font-size:.85rem;margin-top:4px">'+(b.address||'')+'</div>'
        +'<div class="branch-grid-top"><div class="branch-stat"><div class="num">—</div><div class="lbl">Products</div></div><div class="branch-stat"><div class="num" style="color:var(--danger)">—</div><div class="lbl">Low / OOS</div></div></div>'
        +'<div style="font-size:.82rem;color:var(--muted);margin-bottom:12px">Manager: <strong style="color:var(--ink)">'+(b.manager||'—')+'</strong> · '+(b.phone||'')+'</div>'
        +'<button class="btn btn-sm" style="width:100%" onclick="toast(\''+b.name+' — branch management coming soon\')">Manage Branch</button></div>';}).join('')
      +'</div>'
      +'<div class="card"><h3 style="font-family:var(--font);font-weight:700;font-size:1rem;margin-bottom:16px">🔄 Inter-Branch Transfer</h3>'
      +'<div class="form-3"><div class="fg"><label>From Branch</label><select style="padding:9px 12px;border:1.5px solid var(--line);border-radius:9px;font:500 .88rem var(--font);background:var(--panel2);color:var(--ink)">'+branches.map(function(b){return '<option>'+b.name+'</option>';}).join('')+'</select></div>'
      +'<div class="fg"><label>To Branch</label><select style="padding:9px 12px;border:1.5px solid var(--line);border-radius:9px;font:500 .88rem var(--font);background:var(--panel2);color:var(--ink)">'+branches.map(function(b){return '<option>'+b.name+'</option>';}).join('')+'</select></div>'
      +'<div class="fg"><label>Quantity</label><input type="number" value="10" style="padding:9px 12px;border:1.5px solid var(--line);border-radius:9px;font:500 .88rem var(--font);background:var(--panel2);color:var(--ink)"></div>'
      +'</div><button class="btn btn-sm" onclick="toast(\'Transfer recorded!\',\'ok\')">Transfer →</button></div>';
    cb(html);
  });
}

/* ── Reports ── */
function buildReports(cb){
  if(!dbOk) return cb('<p style="color:var(--muted);padding:20px">Database offline.</p>');
  Promise.all([fetch(API+'?_r=monthly_revenue').then(function(r){return r.json();}),fetch(API+'?_r=top_products').then(function(r){return r.json();})])
    .then(function(res){
      var mrev=res[0],top=res[1];
      var totalRev=mrev.reduce(function(a,m){return a+(parseFloat(m.revenue)||0);},0);
      var totalTxns=mrev.reduce(function(a,m){return a+(parseInt(m.txns)||0);},0);
      var maxR=Math.max.apply(null,mrev.map(function(m){return parseFloat(m.revenue)||0;}));
      var html='<div class="ws-pg-header"><h2>📈 Reports &amp; Analytics</h2></div>'
        +'<div class="kpi-grid">'
        +kpi('Total Revenue',fmt(totalRev),totalTxns+' transactions','')
        +kpi('Avg Sale Value',totalTxns?fmt(Math.round(totalRev/totalTxns)):'—','Per transaction','')
        +kpi('Total VAT',fmt(mrev.reduce(function(a,m){return a+(parseFloat(m.vat)||0);},0)),'VAT collected','info')
        +'</div>'
        +'<div class="chart-wrap" style="margin-bottom:16px"><h3>Monthly Revenue (KSh)</h3>'
        +'<div class="bar-chart">'+(mrev.length?mrev.map(function(m){var h=maxR?Math.round((parseFloat(m.revenue)||0)/maxR*100):0;return '<div class="bar-col"><div class="bar-val">'+Math.round((parseFloat(m.revenue)||0)/1000)+'k</div><div class="bar-fill" style="height:'+h+'%">&nbsp;</div><div class="bar-label">'+m.month+'</div></div>';}).join(''):'<p style="color:var(--muted)">No sales data yet.</p>')+'</div></div>'
        +'<div class="rtab-bar"><button class="rtab active" onclick="switchRTab(\'top\',this)">Top Products</button><button class="rtab" onclick="switchRTab(\'stock\',this)">Stock Summary</button></div>'
        +'<div id="rtab-top" class="rview active"><div class="tcard"><div class="twrap"><table><thead><tr><th>Product</th><th>Category</th><th>Units Sold</th><th>Revenue</th></tr></thead><tbody>'
        +(top.length?top.map(function(p){return '<tr><td>'+(p.icon_emoji||'📦')+' <strong>'+p.name+'</strong></td><td>'+(p.cat||'—')+'</td><td>'+Math.round(p.sold||0)+'</td><td>'+fmt(p.revenue||0)+'</td></tr>';}).join(''):'<tr><td colspan="4" style="text-align:center;padding:18px;color:var(--muted)">No sales data yet</td></tr>')
        +'</tbody></table></div></div></div>'
        +'<div id="rtab-stock" class="rview"><p style="color:var(--muted);padding:20px">Stock summary exports coming soon.</p></div>';
      cb(html);
    }).catch(function(){ cb('<p style="color:var(--danger)">Error loading reports.</p>'); });
}
function switchRTab(id,btn){
  document.querySelectorAll('.rtab').forEach(function(b){b.classList.remove('active');});
  document.querySelectorAll('.rview').forEach(function(v){v.classList.remove('active');});
  if(btn) btn.classList.add('active');
  var el=document.getElementById('rtab-'+id); if(el) el.classList.add('active');
}

/* ── Staff ── */
function buildStaff(cb){
  if(!dbOk) return cb('<p style="color:var(--muted);padding:20px">Database offline.</p>');
  fetch(API+'?_r=staff_list').then(function(r){return r.json();}).then(function(staff){
    var html='<div class="ws-pg-header"><h2>👔 Staff Management</h2></div>'
      +'<div class="tcard"><div class="twrap"><table><thead><tr><th>Staff Member</th><th>Username</th><th>Email</th><th>Role</th><th>Last Login</th><th>Status</th></tr></thead><tbody>'
      +staff.map(function(s){var rc=s.role_name==='Admin'?'b-red':s.role_name==='Store Manager'?'b-blue':s.role_name==='Customer Support'?'b-amber':'b-green';return '<tr><td><div style="display:flex;align-items:center;gap:10px"><div style="width:32px;height:32px;background:var(--accent);border-radius:50%;display:grid;place-items:center;color:#fff;font-size:.72rem;font-weight:700">'+(s.avatar_initials||s.full_name.substring(0,2).toUpperCase())+'</div><div><div style="font-weight:700;font-size:.9rem">'+s.full_name+'</div></div></div></td><td><code style="font-size:.82rem">'+s.username+'</code></td><td style="font-size:.82rem">'+s.email+'</td><td><span class="badge '+rc+'">'+s.role_name+'</span></td><td style="font-size:.8rem">'+(s.last_login||'Never')+'</td><td><span class="badge '+(s.is_active?'b-green':'b-red')+'">'+(s.is_active?'Active':'Inactive')+'</span></td></tr>';}).join('')
      +'</tbody></table></div></div>';
    cb(html);
  });
}

/* ── Audit Log ── */
function buildAudit(cb){
  if(!dbOk) return cb('<p style="color:var(--muted);padding:20px">Database offline.</p>');
  fetch(API+'?_r=audit').then(function(r){return r.json();}).then(function(logs){
    var html='<div class="ws-pg-header"><h2>🔍 Audit Log</h2></div>'
      +(logs.length?'<div class="tcard"><div class="twrap"><table><thead><tr><th>Action</th><th>Module</th><th>Staff</th><th>Time</th></tr></thead><tbody>'
      +logs.map(function(l){return '<tr><td>'+l.action+'</td><td><span class="badge b-muted">'+(l.module||'—')+'</span></td><td>'+(l.full_name||'System')+'</td><td style="font-size:.8rem">'+l.logged_at+'</td></tr>';}).join('')
      +'</tbody></table></div></div>'
      :'<div style="text-align:center;padding:40px;color:var(--muted)"><div style="font-size:3rem;margin-bottom:12px">🔍</div><p>No audit log entries yet</p></div>');
    cb(html);
  });
}

/* ══════════════════════════════════════════════════════════════
   DEAL TIMERS (1s tick)
══════════════════════════════════════════════════════════════ */
setInterval(function(){
  document.querySelectorAll('.pcard-timer[data-pid]').forEach(function(el){
    var id=el.dataset.pid; var t=tl(id);
    el.textContent=t?'⏱ '+t:'';
  });
},1000);

/* ── Responsive sidebar ── */
window.addEventListener('resize',function(){
  var st=document.getElementById('sidebarToggle');
  if(st) st.style.display=window.innerWidth<=1024?'block':'none';
  if(window.innerWidth>1024){ var sb=document.getElementById('wsSidebar'); if(sb) sb.classList.remove('open'); }
});

/* ══════════════════════════════════════════════════════════════
   INIT
══════════════════════════════════════════════════════════════ */
// Load landing products immediately (storefront products will load when entering shop)
if(<?= $dbOk ? 'true' : 'false' ?>) {
  fetch('<?= $_SERVER['PHP_SELF'] ?>?_r=products&deals=1')
    .then(function(r){return r.json();})
    .then(function(prods){
      allProds=prods;
      prods.forEach(function(p){if((p.timer||p.deal_timer_hrs)>0) TIMER_ENDS[p.id||p.product_id]=Date.now()+(p.timer||p.deal_timer_hrs)*3600000;});
      renderLandingProds();
    })
    .catch(function(){ /* silently fail on landing */ });
}
</script>
</body>
</html>
