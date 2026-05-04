<?php
// ================================================================
//  modules/AlertModel.php
// ================================================================
class AlertModel {
    public static function getActive(): array {
        return DB::fetchAll("SELECT * FROM alerts WHERE is_resolved=0 ORDER BY FIELD(sev,'Critical','Warning','Info'),created_at DESC");
    }
    public static function resolve(int $id, int $staffId): void {
        DB::query("UPDATE alerts SET is_resolved=1,resolved_by=?,resolved_at=NOW() WHERE alert_id=?",[$staffId,$id]);
        logAudit($staffId,'Alert resolved','Alerts',$id);
    }
    public static function resolveAll(int $staffId): void {
        DB::query("UPDATE alerts SET is_resolved=1,resolved_by=?,resolved_at=NOW() WHERE is_resolved=0",[$staffId]);
        logAudit($staffId,'All alerts resolved','Alerts');
    }
    public static function create(string $sev, string $title, string $desc, ?int $branchId=null): void {
        DB::query("INSERT INTO alerts(sev,title,description,branch_id) VALUES(?,?,?,?)",[$sev,$title,$desc,$branchId]);
    }
    public static function checkAndCreateLowStockAlerts(): void {
        $low = ProductModel::getLowStock();
        foreach ($low as $p) {
            $exists = DB::fetch("SELECT alert_id FROM alerts WHERE title LIKE ? AND is_resolved=0",
                ["%{$p['name']}%"]);
            if (!$exists) {
                self::create('Critical',"Low Stock: {$p['name']}",
                    "{$p['stock_qty']} units remaining. Reorder level: {$p['reorder_level']}");
            }
        }
    }
}