<?php
// ================================================================
//  modules/PurchaseModel.php
// ================================================================
class PurchaseModel {
    public static function create(array $d): int {
        return DB::insert("INSERT INTO purchase_orders(po_number,supplier_id,branch_id,created_by,po_date,expected_date,notes,status) VALUES(?,?,?,?,?,?,'Draft')",
            [generatePONumber(),$d['supplier_id'],$d['branch_id']??null,$_SESSION['staff_id'],$d['po_date'],$d['expected_date']??null,$d['notes']??null,'Draft']);
    }
    public static function addItem(int $poId, int $prodId, float $qty, float $cost, string $batch='', ?string $exp=null): void {
        DB::query("INSERT INTO po_items(po_id,product_id,qty_ordered,unit_cost,batch_no,expiry_date) VALUES(?,?,?,?,?,?)",
            [$poId,$prodId,$qty,$cost,$batch?:null,$exp]);
    }
    public static function submit(int $id): void  { DB::query("UPDATE purchase_orders SET status='Submitted' WHERE po_id=? AND status='Draft'",[$id]); }
    public static function approve(int $id, int $staffId): void {
        DB::query("UPDATE purchase_orders SET status='Approved',approved_by=?,approved_at=NOW() WHERE po_id=? AND status='Submitted'",[$staffId,$id]);
        logAudit($staffId,'PO Approved','Procurement',$id);
    }
    public static function receive(int $id, int $staffId): void {
        DB::query("UPDATE purchase_orders SET status='Received' WHERE po_id=? AND status='Approved'",[$id]);
        logAudit($staffId,'Stock Received','Procurement',$id);
    }
    public static function getAll(string $status=''): array {
        $w = $status ? "WHERE po.status='$status'" : '';
        return DB::fetchAll("SELECT po.*,s.name AS supplier_name,st.full_name AS raised_by,b.name AS branch_name FROM purchase_orders po JOIN suppliers s ON po.supplier_id=s.supplier_id JOIN staff st ON po.created_by=st.staff_id LEFT JOIN branches b ON po.branch_id=b.branch_id $w ORDER BY po.created_at DESC");
    }
}
