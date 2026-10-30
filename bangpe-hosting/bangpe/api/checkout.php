<?php
// บันทึกการขาย + ตัดสต็อกวัตถุดิบตามสูตรอัตโนมัติ
require_once __DIR__ . '/../includes/functions.php';
$user = require_login(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'message' => 'ต้องส่งแบบ POST'], 405);
}
verify_csrf();

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$method = in_array($in['payment_method'] ?? '', ['cash', 'transfer'], true) ? $in['payment_method'] : null;
$items = is_array($in['items'] ?? null) ? $in['items'] : [];

// รวมจำนวนต่อเมนู (กันส่งเมนูซ้ำ)
$qtyByMenu = [];
foreach ($items as $it) {
    $mid = (int)($it['menu_id'] ?? 0);
    $q = (int)($it['qty'] ?? 0);
    if ($mid > 0 && $q > 0) {
        $qtyByMenu[$mid] = ($qtyByMenu[$mid] ?? 0) + $q;
    }
}
if (!$method || !$qtyByMenu) {
    json_out(['ok' => false, 'message' => 'ข้อมูลการขายไม่ครบ'], 422);
}

try {
    $pdo->beginTransaction();

    // 1) ดึงเมนูและราคาจากฐานข้อมูล (ไม่เชื่อราคาที่ส่งมาจากหน้าเว็บ)
    $ph = implode(',', array_fill(0, count($qtyByMenu), '?'));
    $st = $pdo->prepare("SELECT id, name, price FROM menus WHERE is_active = 1 AND id IN ($ph)");
    $st->execute(array_keys($qtyByMenu));
    $menus = [];
    foreach ($st->fetchAll() as $m) {
        $menus[(int)$m['id']] = $m;
    }
    if (count($menus) !== count($qtyByMenu)) {
        throw new RuntimeException('มีเมนูที่ถูกปิดขายหรือถูกลบไปแล้ว กรุณารีเฟรชหน้าขาย');
    }

    $total = 0;
    foreach ($qtyByMenu as $mid => $q) {
        $total += $menus[$mid]['price'] * $q;
    }
    $total = round($total, 2);

    $cash = null;
    $change = null;
    if ($method === 'cash') {
        $cash = round((float)($in['cash_received'] ?? 0), 2);
        if ($cash < $total) {
            throw new RuntimeException('รับเงินมาน้อยกว่ายอดที่ต้องชำระ');
        }
        $change = round($cash - $total, 2);
    }

    // 2) คำนวณวัตถุดิบที่ต้องใช้ทั้งหมดจากสูตร
    $st = $pdo->prepare("SELECT menu_id, ingredient_id, quantity FROM recipes WHERE menu_id IN ($ph)");
    $st->execute(array_keys($qtyByMenu));
    $need = [];
    foreach ($st->fetchAll() as $r) {
        $iid = (int)$r['ingredient_id'];
        $need[$iid] = ($need[$iid] ?? 0) + $r['quantity'] * $qtyByMenu[(int)$r['menu_id']];
    }

    // 3) ล็อกแถววัตถุดิบ แล้วตรวจว่าพอ
    if ($need) {
        ksort($need);
        $ph2 = implode(',', array_fill(0, count($need), '?'));
        $st = $pdo->prepare("SELECT id, name, unit, quantity FROM ingredients WHERE id IN ($ph2) ORDER BY id FOR UPDATE");
        $st->execute(array_keys($need));
        $short = [];
        foreach ($st->fetchAll() as $ing) {
            if ((float)$ing['quantity'] + 0.0001 < $need[(int)$ing['id']]) {
                $short[] = $ing['name'] . ' (ต้องใช้ ' . qty($need[(int)$ing['id']]) . ' มีอยู่ ' . qty($ing['quantity']) . ' ' . $ing['unit'] . ')';
            }
        }
        if ($short) {
            throw new RuntimeException('วัตถุดิบไม่พอ: ' . implode(', ', $short));
        }
    }

    // 4) สร้างเลขที่บิล เช่น B20261029-0001
    $prefix = 'B' . date('Ymd') . '-';
    $st = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE order_no LIKE ?');
    $st->execute([$prefix . '%']);
    $orderNo = $prefix . str_pad((string)((int)$st->fetchColumn() + 1), 4, '0', STR_PAD_LEFT);

    $pdo->prepare('INSERT INTO orders (order_no, total, payment_method, cash_received, change_amount, user_id) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$orderNo, $total, $method, $cash, $change, $user['id']]);
    $orderId = (int)$pdo->lastInsertId();

    $insItem = $pdo->prepare('INSERT INTO order_items (order_id, menu_id, menu_name, price, qty, subtotal) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($qtyByMenu as $mid => $q) {
        $m = $menus[$mid];
        $insItem->execute([$orderId, $mid, $m['name'], $m['price'], $q, round($m['price'] * $q, 2)]);
    }

    // 5) ตัดสต็อกและบันทึกประวัติ (อ้างอิงเลขบิล เพื่อคืนสต็อกได้ถ้ายกเลิกบิล)
    foreach ($need as $iid => $amount) {
        stock_move($pdo, $iid, -round($amount, 2), 'sale', 'order', $orderId, null, $orderNo);
    }

    $pdo->commit();
    json_out(['ok' => true, 'order_id' => $orderId, 'order_no' => $orderNo, 'total' => $total, 'change' => $change]);
} catch (RuntimeException $ex) {
    $pdo->rollBack();
    json_out(['ok' => false, 'message' => $ex->getMessage()], 422);
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_out(['ok' => false, 'message' => 'บันทึกการขายไม่สำเร็จ กรุณาลองใหม่'], 500);
}
