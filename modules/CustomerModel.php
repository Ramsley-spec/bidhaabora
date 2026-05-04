<?php
// ================================================================
//  modules/CustomerModel.php
// ================================================================
class CustomerModel {
    public static function getAll(string $search=''): array {
        $w='1=1';$p=[];
        if($search){$w="(full_name LIKE ? OR phone LIKE ? OR email LIKE ?)";$s="%$search%";$p=[$s,$s,$s];}
        return DB::fetchAll("SELECT * FROM customers WHERE $w AND is_active=1 ORDER BY full_name",$p);
    }
    public static function create(array $d, bool $fromOnline=false): int {
        $hash = !empty($d['password']) ? password_hash($d['password'],PASSWORD_BCRYPT) : null;
        return DB::insert("INSERT INTO customers(full_name,email,phone,address,password_hash,customer_type,from_online) VALUES(?,?,?,?,?,?,?)",
            [$d['full_name'],$d['email']??null,$d['phone']??null,$d['address']??null,$hash,$d['customer_type']??'Retail',(int)$fromOnline]);
    }
    public static function findByEmail(string $email): ?array {
        return DB::fetch("SELECT * FROM customers WHERE email=? AND is_active=1",[$email]);
    }
    public static function login(string $email, string $pass): ?array {
        $c = self::findByEmail($email);
        if ($c && $c['password_hash'] && password_verify($pass,$c['password_hash'])) return $c;
        return null;
    }
    public static function getStats(): array {
        return DB::fetchAll("SELECT * FROM vw_customer_stats ORDER BY lifetime_value DESC LIMIT 50");
    }
}