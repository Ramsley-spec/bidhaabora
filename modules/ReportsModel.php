<?php
// ================================================================
//  modules/ReportsModel.php
// ================================================================
class ReportsModel {
    public static function dashboard(?int $branchId=null): array {
        $b = $branchId ? "AND branch_id=$branchId" : '';
        return [
            'today_revenue'   => DB::fetch("SELECT IFNULL(SUM(total_amount),0) AS v FROM sales WHERE DATE(sale_date)=CURDATE() AND status='Completed' $b")['v'],
            'today_sales'     => DB::fetch("SELECT COUNT(*) AS v FROM sales WHERE DATE(sale_date)=CURDATE() AND status='Completed' $b")['v'],
            'total_products'  => DB::fetch("SELECT COUNT(*) AS v FROM products WHERE is_active=1")['v'],
            'low_stock'       => DB::fetch("SELECT COUNT(*) AS v FROM products WHERE stock_qty<=reorder_level AND is_active=1")['v'],
            'total_customers' => DB::fetch("SELECT COUNT(*) AS v FROM customers WHERE is_active=1")['v'],
            'pending_orders'  => DB::fetch("SELECT COUNT(*) AS v FROM online_orders WHERE status='Pending'")['v'],
            'stock_value'     => DB::fetch("SELECT IFNULL(SUM(stock_qty*buying_price),0) AS v FROM products WHERE is_active=1")['v'],
            'month_revenue'   => DB::fetch("SELECT IFNULL(SUM(total_amount),0) AS v FROM sales WHERE DATE_FORMAT(sale_date,'%Y-%m')=DATE_FORMAT(NOW(),'%Y-%m') AND status='Completed' $b")['v'],
            'critical_alerts' => DB::fetch("SELECT COUNT(*) AS v FROM alerts WHERE sev='Critical' AND is_resolved=0")['v'],
        ];
    }
    public static function monthlyRevenue(int $months=7): array {
        return DB::fetchAll("SELECT DATE_FORMAT(sale_date,'%Y-%m') AS month,SUM(total_amount) AS revenue,SUM(tax_amount) AS vat,COUNT(*) AS transactions FROM sales WHERE status='Completed' AND sale_date>=DATE_SUB(NOW(),INTERVAL ? MONTH) GROUP BY DATE_FORMAT(sale_date,'%Y-%m') ORDER BY month",[$months]);
    }
    public static function topProducts(int $limit=20): array {
        return DB::fetchAll("SELECT * FROM vw_top_selling LIMIT ?",[$limit]);
    }
    public static function stockSummary(): array {
        return DB::fetchAll("SELECT category,COUNT(*) AS products,SUM(stock_qty) AS units,SUM(stock_value) AS buy_value,SUM(stock_qty*selling_price) AS sell_value FROM vw_products GROUP BY category ORDER BY buy_value DESC");
    }
    public static function supplierKPIs(): array {
        return DB::fetchAll("SELECT s.name,COUNT(po.po_id) AS total_orders,SUM(CASE WHEN po.status='Received' THEN 1 ELSE 0 END) AS completed FROM suppliers s LEFT JOIN purchase_orders po ON s.supplier_id=po.supplier_id GROUP BY s.supplier_id,s.name ORDER BY total_orders DESC");
    }
    public static function auditLog(int $limit=100): array {
        return DB::fetchAll("SELECT l.*,st.full_name FROM audit_log l LEFT JOIN staff st ON l.staff_id=st.staff_id ORDER BY l.logged_at DESC LIMIT ?",[$limit]);
    }
}