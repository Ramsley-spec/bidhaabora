<?php
// ================================================================
//  modules/ProductModel.php
// ================================================================
class ProductModel {
    public static function getAll(array $f = []): array {
        $where = ['p.is_active=1']; $params = [];
        if (!empty($f['search']))  { $where[] = '(p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)'; $s="%{$f['search']}%"; array_push($params,$s,$s,$s); }
        if (!empty($f['cat_id']))  { $where[] = 'p.cat_id=?';      $params[] = $f['cat_id']; }
        if (!empty($f['supp_id'])) { $where[] = 'p.supplier_id=?'; $params[] = $f['supp_id']; }
        if (!empty($f['low_stock']))  { $where[] = 'p.stock_qty<=p.reorder_level'; }
        if (!empty($f['top_sell']))   { $where[] = 'p.is_top_sell=1'; }
        if (!empty($f['discounted'])) { $where[] = 'p.discount_pct>0 AND p.stock_qty>0'; }
        return DB::fetchAll("SELECT * FROM vw_products p WHERE ".implode(' AND ',$where)." ORDER BY name", $params);
    }
    public static function getById(int $id): ?array {
        return DB::fetch("SELECT * FROM vw_products WHERE product_id=?", [$id]);
    }
    public static function create(array $d): int {
        $sku = $d['sku'] ?: generateSKU();
        return DB::insert("INSERT INTO products (sku,name,cat_id,supplier_id,icon_emoji,buying_price,selling_price,wholesale_price,discount_pct,deal_timer_hrs,stock_qty,reorder_level,unit,is_top_sell,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [$sku,$d['name'],$d['cat_id']??null,$d['supplier_id']??null,$d['icon_emoji']??'📦',
             $d['buying_price'],$d['selling_price'],$d['wholesale_price']??0,
             $d['discount_pct']??0,$d['deal_timer_hrs']??0,$d['stock_qty']??0,
             $d['reorder_level']??10,$d['unit']??'pcs',$d['is_top_sell']??0,$_SESSION['staff_id']]);
    }
    public static function update(int $id, array $d): void {
        DB::query("UPDATE products SET name=?,cat_id=?,supplier_id=?,icon_emoji=?,buying_price=?,selling_price=?,wholesale_price=?,discount_pct=?,deal_timer_hrs=?,reorder_level=?,unit=?,is_top_sell=?,barcode=? WHERE product_id=?",
            [$d['name'],$d['cat_id']??null,$d['supplier_id']??null,$d['icon_emoji']??'📦',
             $d['buying_price'],$d['selling_price'],$d['wholesale_price']??0,
             $d['discount_pct']??0,$d['deal_timer_hrs']??0,$d['reorder_level']??10,
             $d['unit']??'pcs',$d['is_top_sell']??0,$d['barcode']??null,$id]);
    }
    public static function updateStock(int $id, float $qty): void {
        DB::query("UPDATE products SET stock_qty=? WHERE product_id=?",[$qty,$id]);
    }
    public static function delete(int $id): void {
        DB::query("UPDATE products SET is_active=0 WHERE product_id=?",[$id]);
    }
    public static function getCategories(): array { return DB::fetchAll("SELECT * FROM categories ORDER BY name"); }
    public static function getSuppliers(): array  { return DB::fetchAll("SELECT * FROM suppliers WHERE status='Active' ORDER BY name"); }
    public static function getLowStock(): array   { return DB::fetchAll("SELECT * FROM vw_products WHERE stock_status IN ('Out of Stock','Reorder Now') ORDER BY stock_qty"); }
}


