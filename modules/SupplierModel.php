<?php
// ================================================================
//  modules/SupplierModel.php
// ================================================================
class SupplierModel {
    public static function getAll(): array { return DB::fetchAll("SELECT * FROM suppliers ORDER BY name"); }
    public static function create(array $d): int {
        return DB::insert("INSERT INTO suppliers(name,contact_name,email,phone,categories,lead_time,status) VALUES(?,?,?,?,?,?,?)",
            [$d['name'],$d['contact_name']??null,$d['email']??null,$d['phone']??null,$d['categories']??null,$d['lead_time']??'2 days','Active']);
    }
    public static function update(int $id, array $d): void {
        DB::query("UPDATE suppliers SET name=?,contact_name=?,email=?,phone=?,categories=?,lead_time=?,status=? WHERE supplier_id=?",
            [$d['name'],$d['contact_name']??null,$d['email']??null,$d['phone']??null,$d['categories']??null,$d['lead_time']??'2 days',$d['status']??'Active',$id]);
    }
}