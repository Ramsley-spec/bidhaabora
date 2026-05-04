<?php
// ================================================================
//  modules/OrderModel.php — Online Orders
// ================================================================
class OrderModel {
    public static function create(array $d, array $items): string {
        $ono = generateOrderNo();
        $sub = array_sum(array_map(fn($i)=>$i['qty']*$i['price'], $items));
        $fee = match($d['delivery_method']) { 'courier'=>150, 'rider'=>300, default=>0 };
        $tax = round($sub*VAT_RATE,2); $tot = $sub+$fee+$tax;
        $oid = DB::insert("INSERT INTO online_orders(order_no,customer_id,cust_name,cust_phone,cust_email,delivery_addr,delivery_method,payment_method,subtotal,delivery_fee,tax_amount,total_amount,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,'Pending')",
            [$ono,$d['customer_id']??null,$d['name'],$d['phone'],$d['email']??null,
             $d['address'],$d['delivery_method'],$d['payment'],$sub,$fee,$tax,$tot]);
        foreach ($items as $item) {
            DB::query("INSERT INTO order_items(order_id,product_id,quantity,unit_price) VALUES(?,?,?,?)",
                [$oid,$item['product_id'],$item['qty'],$item['price']]);
        }
        return $ono;
    }
    public static function getAll(string $status=''): array {
        $w = $status ? "WHERE o.status='$status'" : '';
        return DB::fetchAll("SELECT o.*,c.full_name AS registered_customer FROM online_orders o LEFT JOIN customers c ON o.customer_id=c.customer_id $w ORDER BY o.created_at DESC");
    }
    public static function updateStatus(int $id, string $status, int $staffId): void {
        DB::query("UPDATE online_orders SET status=? WHERE order_id=?",[$status,$id]);
        logAudit($staffId,"Order status→$status",'Orders',$id);
    }
}

