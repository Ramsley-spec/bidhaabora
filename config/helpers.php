<?php
// ================================================================
//  config/helpers.php — Utility Functions
// ================================================================

function formatCurrency(float $n): string {
    return CURRENCY . ' ' . number_format($n, 2);
}

function sanitize(string $s): string {
    return htmlspecialchars(strip_tags(trim($s)), ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void {
    header("Location: $url"); exit;
}

function isLoggedIn(): bool {
    return isset($_SESSION['staff_id']);
}

function requireLogin(): void {
    if (!isLoggedIn()) redirect(APP_URL . '/admin/login.php');
}

function hasPermission(string $perm): bool {
    $perms = $_SESSION['permissions'] ?? [];
    return !empty($perms['all']) || !empty($perms[$perm]);
}

function requirePermission(string $perm): void {
    if (!hasPermission($perm)) { http_response_code(403); die('<h2>403 Forbidden</h2>'); }
}

function generateSKU(string $prefix = 'SKU'): string {
    return $prefix . '-' . strtoupper(substr(uniqid(), -6));
}

function generateReceiptNo(): string {
    return 'RCT-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
}

function generatePONumber(): string {
    return 'FT-PO-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
}

function generateOrderNo(): string {
    return 'ORD-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
}

function logAudit(int $staffId, string $action, string $module,
                   ?int $recordId = null, mixed $old = null, mixed $new = null): void {
    DB::query(
        "INSERT INTO audit_log (staff_id,action,module,record_id,old_data,new_data,ip_address) VALUES (?,?,?,?,?,?,?)",
        [$staffId, $action, $module, $recordId,
         $old ? json_encode($old) : null,
         $new  ? json_encode($new)  : null,
         $_SERVER['REMOTE_ADDR'] ?? null]
    );
}

function flash(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function getFlash(): ?array {
    if (!isset($_SESSION['flash'])) return null;
    $f = $_SESSION['flash']; unset($_SESSION['flash']); return $f;
}

function discountedPrice(float $price, float $disc): float {
    return round($price * (1 - $disc / 100), 2);
}

function stockStatus(float $qty, float $reorder): array {
    if ($qty <= 0)           return ['label' => 'Out of Stock', 'class' => 'b-red',   'color' => '#dc2626'];
    if ($qty <= $reorder)    return ['label' => 'Reorder Now',  'class' => 'b-red',   'color' => '#dc2626'];
    if ($qty <= $reorder*1.5)return ['label' => 'Low Stock',    'class' => 'b-amber', 'color' => '#d97706'];
    return                          ['label' => 'In Stock',     'class' => 'b-green', 'color' => '#15803d'];
}

function marginPct(float $buy, float $sell): float {
    if ($sell <= 0) return 0;
    return round(($sell - $buy) / $sell * 100, 1);
}

function jsonResponse(mixed $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403); die('Invalid CSRF token.');
    }
}