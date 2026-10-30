<?php
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

// ---------- การแสดงผล ----------
function e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function money($n): string
{
    return number_format((float)$n, 2);
}

// แสดงจำนวนแบบตัดทศนิยมที่เป็น 0 ออก เช่น 1,000.00 -> 1,000 และ 2.50 -> 2.5
function qty($n): string
{
    $s = number_format((float)$n, 2, '.', ',');
    return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
}

// อักษรตัวแรกของชื่อสำหรับรูปโปรไฟล์ (ข้ามสระหน้า เช่น "เอ" ได้ "อ")
function initial(string $name): string
{
    return preg_match('/[ก-ฮA-Za-z0-9]/u', $name, $m) ? strtoupper($m[0]) : '?';
}

// เลือกสีประจำกลุ่มจากชื่อกลุ่ม ชื่อเดิมได้สีเดิมเสมอ (ใช้กับหมวดเมนูในหน้าขาย)
function tone_for(string $key): string
{
    $tones = ['saffron', 'pandan', 'rose', 'sky', 'chili', 'violet'];
    return 'tone-' . $tones[abs(crc32($key)) % count($tones)];
}

// วันที่แบบไทยแบบสั้น เช่น 26 ต.ค.
function thai_short_date(string $date): string
{
    $m = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    $t = strtotime($date);
    return (int)date('j', $t) . ' ' . $m[(int)date('n', $t)];
}

// ช่วงสัปดาห์แบบไทย เช่น 26 ต.ค. – 1 พ.ย. 2569
function thai_week_range(string $monday): string
{
    $sun = date('Y-m-d', strtotime($monday . ' +6 days'));
    return thai_short_date($monday) . ' – ' . thai_short_date($sun) . ' ' . (date('Y', strtotime($sun)) + 543);
}

// ยอดขายรายวันของสัปดาห์ (จันทร์–อาทิตย์) ที่เริ่มวัน $monday คืนค่า [วันที่ => ['n'=>บิล, 'total'=>ยอด]] ครบ 7 วัน
function week_daily_sales(PDO $pdo, string $monday): array
{
    $days = [];
    for ($i = 0; $i < 7; $i++) {
        $days[date('Y-m-d', strtotime("$monday +$i days"))] = ['n' => 0, 'total' => 0.0];
    }
    $st = $pdo->prepare("SELECT DATE(created_at) AS d, COUNT(*) AS n, SUM(total) AS total FROM orders
                         WHERE status = 'paid' AND created_at >= ? AND created_at < ? + INTERVAL 7 DAY
                         GROUP BY DATE(created_at)");
    $st->execute([$monday, $monday]);
    foreach ($st as $r) {
        $days[$r['d']] = ['n' => (int)$r['n'], 'total' => (float)$r['total']];
    }
    return $days;
}

// วันจันทร์ของสัปดาห์ที่วันที่ $date อยู่
function week_start(string $date): string
{
    $t = strtotime($date);
    return date('Y-m-d', strtotime('-' . (date('N', $t) - 1) . ' days', $t));
}

// วันที่แบบไทย (พ.ศ.)
function thai_date($dt, bool $withTime = false): string
{
    if (!$dt) return '-';
    $t = strtotime($dt);
    $s = date('d/m/', $t) . (date('Y', $t) + 543);
    return $withTime ? $s . ' ' . date('H:i', $t) : $s;
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

// ---------- ข้อความแจ้งผล (flash) ----------
function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function get_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

// ---------- ป้องกัน CSRF ----------
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

function verify_csrf(): void
{
    $t = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
        http_response_code(400);
        exit('คำขอหมดอายุหรือไม่ถูกต้อง กรุณารีเฟรชหน้าแล้วลองใหม่');
    }
}

// ---------- ผู้ใช้และสิทธิ์ ----------
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_admin(): bool
{
    return (current_user()['role'] ?? '') === 'admin';
}

// ตรวจว่าล็อกอินแล้ว และบัญชียังใช้งานได้
function require_login(bool $api = false): array
{
    global $pdo;
    $u = current_user();
    if ($u) {
        $st = $pdo->prepare('SELECT id, username, full_name, role FROM users WHERE id = ? AND is_active = 1');
        $st->execute([$u['id']]);
        $fresh = $st->fetch();
        if ($fresh) {
            $_SESSION['user'] = $fresh;
            return $fresh;
        }
        session_destroy();
    }
    if ($api) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode(['ok' => false, 'message' => 'กรุณาเข้าสู่ระบบใหม่']));
    }
    redirect('login.php');
}

function require_admin(bool $api = false): array
{
    $u = require_login($api);
    if ($u['role'] !== 'admin') {
        if ($api) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            exit(json_encode(['ok' => false, 'message' => 'เฉพาะผู้ดูแลระบบเท่านั้น']));
        }
        flash('danger', 'หน้านี้ใช้ได้เฉพาะผู้ดูแลระบบ');
        redirect('index.php');
    }
    return $u;
}

// ---------- สต็อก ----------
/**
 * เปลี่ยนจำนวนวัตถุดิบ และบันทึกประวัติการเคลื่อนไหว
 * ต้องเรียกภายใน transaction เสมอ
 * $change เป็นบวก = เพิ่ม, เป็นลบ = ลด
 */
function stock_move(PDO $pdo, int $ingredientId, float $change, string $type, ?string $refType = null, ?int $refId = null, ?string $reason = null, ?string $note = null): float
{
    $st = $pdo->prepare('SELECT quantity FROM ingredients WHERE id = ? FOR UPDATE');
    $st->execute([$ingredientId]);
    $row = $st->fetch();
    if (!$row) {
        throw new RuntimeException('ไม่พบวัตถุดิบรหัส ' . $ingredientId);
    }
    $newQty = round((float)$row['quantity'] + $change, 2);

    $pdo->prepare('UPDATE ingredients SET quantity = ? WHERE id = ?')->execute([$newQty, $ingredientId]);
    $pdo->prepare('INSERT INTO stock_movements (ingredient_id, type, qty_change, balance_after, ref_type, ref_id, reason, note, user_id)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$ingredientId, $type, $change, $newQty, $refType, $refId, $reason, $note, current_user()['id']]);

    return $newQty;
}

function movement_label(string $type): string
{
    return [
        'in' => 'รับเข้า',
        'sale' => 'ขาย',
        'withdraw' => 'เบิกใช้',
        'adjust' => 'ปรับยอด',
        'void' => 'คืนจากยกเลิกบิล',
    ][$type] ?? $type;
}

// ---------- การแจ้งเตือน ----------
function notifications_data(PDO $pdo): array
{
    $low = $pdo->query(
        'SELECT id, name, unit, quantity, min_quantity FROM ingredients
         WHERE min_quantity > 0 AND quantity <= min_quantity
         ORDER BY (quantity / min_quantity) ASC'
    )->fetchAll();

    $st = $pdo->prepare(
        'SELECT s.id, s.expiry_date, i.name, i.unit, DATEDIFF(s.expiry_date, CURDATE()) AS days_left
         FROM stock_in s JOIN ingredients i ON i.id = s.ingredient_id
         WHERE s.expiry_date IS NOT NULL AND s.expiry_cleared = 0 AND i.quantity > 0
           AND s.expiry_date <= CURDATE() + INTERVAL ? DAY
         ORDER BY s.expiry_date ASC'
    );
    $st->execute([EXPIRY_ALERT_DAYS]);
    $expiry = $st->fetchAll();

    return ['low' => $low, 'expiry' => $expiry, 'count' => count($low) + count($expiry)];
}

function expiry_text(int $days): string
{
    if ($days < 0) return 'หมดอายุแล้ว ' . abs($days) . ' วัน';
    if ($days === 0) return 'หมดอายุวันนี้';
    return 'อีก ' . $days . ' วัน';
}

// จำนวนจานที่ทำได้จากสต็อกปัจจุบัน (null = ไม่มีสูตร ไม่จำกัด)
function menu_servings(PDO $pdo): array
{
    $rows = $pdo->query(
        'SELECT r.menu_id, MIN(FLOOR(i.quantity / r.quantity)) AS servings
         FROM recipes r JOIN ingredients i ON i.id = r.ingredient_id
         WHERE r.quantity > 0
         GROUP BY r.menu_id'
    )->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r['menu_id']] = max(0, (int)$r['servings']);
    }
    return $out;
}

function json_out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
