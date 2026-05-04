<?php
// ================================================================
//  modules/SalesModel.php
// ================================================================
class SalesModel {
    public static function startSale(int $cashierId, ?int $custId, ?int $branchId,
                                     string $method, float $paid, string $mpesa=''): array {
        $rct = generateReceiptNo();
        $id  = DB::insert("INSERT INTO sales(receipt_no,customer_id,branch_id,cashier_id,payment_method,amount_paid,mpesa_code,status) VALUES(?,?,?,?,?,?,?,'Completed')",
            [$rct,$custId,$branchId,$cashierId,$method,$paid,$mpesa?:null]);
        return ['sale_id'=>$id,'receipt_no'=>$rct];
    }
    public static function addItem(int $saleId, int $productId, float $qty, float $price, float $disc=0): void {
        $p = DB::fetch("SELECT stock_qty, name FROM products WHERE product_id=?",[$productId]);
        if (!$p || $p['stock_qty'] < $qty) throw new RuntimeException("Insufficient stock: {$p['name']}");
        DB::query("INSERT INTO sale_items(sale_id,product_id,quantity,unit_price,discount_pct) VALUES(?,?,?,?,?)",
            [$saleId,$productId,$qty,$price,$disc]);
    }
    public static function voidSale(int $id, string $reason, int $staffId): void {
        DB::query("UPDATE sales SET status='Voided' WHERE sale_id=? AND status='Completed'",[$id]);
        logAudit($staffId,"Sale Voided: $reason",'Sales',$id);
    }
    public static function getReceipt(int $id): array {
        $s = DB::fetch("SELECT s.*,COALESCE(c.full_name,'Walk-in') AS customer,st.full_name AS cashier,b.name AS branch FROM sales s LEFT JOIN customers c ON s.customer_id=c.customer_id JOIN staff st ON s.cashier_id=st.staff_id LEFT JOIN branches b ON s.branch_id=b.branch_id WHERE s.sale_id=?",[$id]);
        $i = DB::fetchAll("SELECT si.*,p.name,p.sku FROM sale_items si JOIN products p ON si.product_id=p.product_id WHERE si.sale_id=?",[$id]);
        return ['sale'=>$s,'items'=>$i];
    }
    public static function getAll(array $f=[]): array {
        $w=['1=1'];$p=[];
        if(!empty($f['date']))   {$w[]="DATE(s.sale_date)=?";$p[]=$f['date'];}
        if(!empty($f['method'])) {$w[]="s.payment_method=?"; $p[]=$f['method'];}
        if(!empty($f['branch'])) {$w[]="s.branch_id=?";      $p[]=$f['branch'];}
        return DB::fetchAll("SELECT s.*,COALESCE(c.full_name,'Walk-in') AS customer,st.full_name AS cashier FROM sales s LEFT JOIN customers c ON s.customer_id=c.customer_id JOIN staff st ON s.cashier_id=st.staff_id WHERE ".implode(' AND ',$w)." ORDER BY s.sale_date DESC",$p);
    }
    public static function getDailyKPIs(?int $branchId=null): array {
        $w=$branchId?"AND branch_id=$branchId":'';
        return DB::fetch("SELECT IFNULL(SUM(total_amount),0) AS revenue,COUNT(*) AS transactions,IFNULL(AVG(total_amount),0) AS avg_sale FROM sales WHERE DATE(sale_date)=CURDATE() AND status='Completed' $w") ?? [];
    }
    public static function getMonthlyRevenue(int $months=7): array {
        return DB::fetchAll("SELECT DATE_FORMAT(sale_date,'%Y-%m') AS month,SUM(total_amount) AS revenue,COUNT(*) AS transactions FROM sales WHERE status='Completed' AND sale_date>=DATE_SUB(NOW(),INTERVAL ? MONTH) GROUP BY DATE_FORMAT(sale_date,'%Y-%m') ORDER BY month",[$months]);
    }
}
