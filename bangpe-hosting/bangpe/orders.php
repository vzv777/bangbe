<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

// ยกเลิกบิล (เฉพาะ admin) และคืนวัตถุดิบเข้าสต็อก
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    verify_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $reason = trim($_POST['void_reason'] ?? '');
    if ($reason === '') {
        flash('danger', 'กรุณาระบุเหตุผลที่ยกเลิกบิล');
        redirect('orders.php?id=' . $id);
    }

    $pdo->beginTransaction();
    $st = $pdo->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
    $st->execute([$id]);
    $order = $st->fetch();
    if (!$order || $order['status'] !== 'paid') {
        $pdo->rollBack();
        flash('danger', 'บิลนี้ถูกยกเลิกไปแล้ว หรือไม่พบบิล');
        redirect('orders.php');
    }
    // คืนสต็อกตามที่ตัดไปจริงตอนขาย (ไม่ใช้สูตรปัจจุบัน เผื่อสูตรถูกแก้ภายหลัง)
    $st = $pdo->prepare("SELECT ingredient_id, qty_change FROM stock_movements WHERE type = 'sale' AND ref_type = 'order' AND ref_id = ?");
    $st->execute([$id]);
    foreach ($st->fetchAll() as $mv) {
        stock_move($pdo, (int)$mv['ingredient_id'], -(float)$mv['qty_change'], 'void', 'order', $id, 'ยกเลิกบิล', $order['order_no']);
    }
    $pdo->prepare("UPDATE orders SET status = 'void', void_by = ?, void_reason = ?, void_at = NOW() WHERE id = ?")
        ->execute([current_user()['id'], $reason, $id]);
    $pdo->commit();

    flash('success', 'ยกเลิกบิล ' . $order['order_no'] . ' และคืนวัตถุดิบเข้าสต็อกแล้ว');
    redirect('orders.php?id=' . $id);
}

// ----- ดูรายละเอียดบิล -----
if (isset($_GET['id'])) {
    $st = $pdo->prepare('SELECT o.*, u.full_name, v.full_name AS void_name FROM orders o
                         JOIN users u ON u.id = o.user_id LEFT JOIN users v ON v.id = o.void_by WHERE o.id = ?');
    $st->execute([(int)$_GET['id']]);
    $order = $st->fetch();
    if (!$order) {
        flash('danger', 'ไม่พบบิลนี้');
        redirect('orders.php');
    }
    $st = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ?');
    $st->execute([$order['id']]);
    $items = $st->fetchAll();
    $st = $pdo->prepare("SELECT m.*, i.name, i.unit FROM stock_movements m JOIN ingredients i ON i.id = m.ingredient_id
                         WHERE m.ref_type = 'order' AND m.ref_id = ? ORDER BY m.id");
    $st->execute([$order['id']]);
    $moves = $st->fetchAll();

    $pageTitle = 'บิล ' . $order['order_no'];
    $activePage = 'orders';
    require __DIR__ . '/includes/header.php';
    ?>
    <p><a href="orders.php"><i class="bi bi-arrow-left"></i> กลับไปรายการบิล</a></p>
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="panel">
                <h2 class="panel-title"><?= e($order['order_no']) ?>
                    <?= $order['status'] === 'void' ? '<span class="status status-low">ยกเลิกแล้ว</span>' : '<span class="status status-ok">ชำระแล้ว</span>' ?>
                    <a class="btn btn-sm btn-outline-primary ms-auto" target="_blank" href="receipt.php?id=<?= $order['id'] ?>&print=1"><i class="bi bi-printer"></i> พิมพ์ใบเสร็จ</a>
                </h2>
                <p class="small text-secondary"><?= thai_date($order['created_at'], true) ?> ขายโดย <?= e($order['full_name']) ?>
                    ชำระด้วย<?= $order['payment_method'] === 'cash' ? 'เงินสด' : 'การโอน' ?></p>
                <table class="table">
                    <thead><tr><th>เมนู</th><th class="num">ราคา</th><th class="num">จำนวน</th><th class="num">รวม</th></tr></thead>
                    <tbody>
                    <?php foreach ($items as $it): ?>
                        <tr><td><?= e($it['menu_name']) ?></td><td class="num"><?= money($it['price']) ?></td><td class="num"><?= (int)$it['qty'] ?></td><td class="num"><?= money($it['subtotal']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr><th colspan="3">ยอดรวม</th><th class="num">฿<?= money($order['total']) ?></th></tr>
                        <?php if ($order['payment_method'] === 'cash'): ?>
                        <tr><td colspan="3">รับเงิน</td><td class="num"><?= money($order['cash_received']) ?></td></tr>
                        <tr><td colspan="3">เงินทอน</td><td class="num"><?= money($order['change_amount']) ?></td></tr>
                        <?php endif; ?>
                    </tfoot>
                </table>
                <?php if ($order['status'] === 'void'): ?>
                    <div class="alert alert-secondary mt-3 mb-0">ยกเลิกโดย <?= e($order['void_name']) ?> เมื่อ <?= thai_date($order['void_at'], true) ?><br>เหตุผล: <?= e($order['void_reason']) ?></div>
                <?php elseif (is_admin()): ?>
                    <form method="post" class="mt-3 border-top pt-3" onsubmit="return confirm('ยืนยันยกเลิกบิลนี้? วัตถุดิบจะถูกคืนเข้าสต็อก')">
                        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $order['id'] ?>">
                        <label class="form-label" for="void_reason">ยกเลิกบิลนี้</label>
                        <div class="input-group">
                            <input class="form-control" id="void_reason" name="void_reason" placeholder="เหตุผล เช่น กดเมนูผิด" required>
                            <button class="btn btn-outline-danger">ยกเลิกบิล</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="panel">
                <h2 class="panel-title">วัตถุดิบที่ตัด / คืน</h2>
                <?php if (!$moves): ?><p class="text-secondary mb-0">บิลนี้ไม่มีเมนูที่ตัดสต็อก</p><?php else: ?>
                <table class="table">
                    <tbody>
                    <?php foreach ($moves as $mv): ?>
                        <tr><td><?= e($mv['name']) ?><div class="small text-secondary"><?= movement_label($mv['type']) ?></div></td>
                            <td class="num <?= $mv['qty_change'] < 0 ? 'text-danger' : 'text-success' ?>"><?= $mv['qty_change'] > 0 ? '+' : '' ?><?= qty($mv['qty_change']) ?> <?= e($mv['unit']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

// ----- รายการบิล -----
$from = $_GET['from'] ?? date('Y-m-d');
$to = $_GET['to'] ?? date('Y-m-d');
$q = trim($_GET['q'] ?? '');
$st = $pdo->prepare('SELECT o.*, u.full_name, (SELECT SUM(qty) FROM order_items WHERE order_id = o.id) AS item_count
                     FROM orders o JOIN users u ON u.id = o.user_id
                     WHERE DATE(o.created_at) BETWEEN ? AND ? AND o.order_no LIKE ?
                     ORDER BY o.id DESC');
$st->execute([$from, $to, "%$q%"]);
$rows = $st->fetchAll();
$paid = array_filter($rows, fn($r) => $r['status'] === 'paid');
$sum = array_sum(array_column($paid, 'total'));

$pageTitle = 'บิลขาย';
$activePage = 'orders';
require __DIR__ . '/includes/header.php';
?>
<div class="panel">
    <form class="row g-2 mb-3 filter-form" method="get">
        <div class="col-sm-3"><input class="form-control" type="date" name="from" value="<?= e($from) ?>" aria-label="ตั้งแต่วันที่"></div>
        <div class="col-sm-3"><input class="form-control" type="date" name="to" value="<?= e($to) ?>" aria-label="ถึงวันที่"></div>
        <div class="col-sm-3"><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="เลขที่บิล"></div>
        <div class="col-sm-3"><button class="btn btn-outline-primary w-100">ค้นหา</button></div>
    </form>
    <p class="mb-3">ชำระแล้ว <strong><?= count($paid) ?></strong> บิล ยอดรวม <strong>฿<?= money($sum) ?></strong>
        <?php if (count($rows) > count($paid)): ?><span class="text-secondary">(ยกเลิก <?= count($rows) - count($paid) ?> บิล)</span><?php endif; ?></p>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead><tr><th>เลขที่บิล</th><th>วันเวลา</th><th class="num">จำนวน</th><th>ชำระ</th><th>พนักงาน</th><th class="num">ยอด</th><th>สถานะ</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><a href="?id=<?= $r['id'] ?>"><?= e($r['order_no']) ?></a></td>
                    <td><?= thai_date($r['created_at'], true) ?></td>
                    <td class="num"><?= (int)$r['item_count'] ?></td>
                    <td><?= $r['payment_method'] === 'cash' ? 'เงินสด' : 'โอน' ?></td>
                    <td><?= e($r['full_name']) ?></td>
                    <td class="num"><?= money($r['total']) ?></td>
                    <td><?= $r['status'] === 'void' ? '<span class="status status-low">ยกเลิก</span>' : '<span class="status status-ok">ชำระแล้ว</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="7" class="text-secondary">ไม่มีบิลในช่วงวันที่นี้</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
