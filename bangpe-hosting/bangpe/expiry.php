<?php
require_once __DIR__ . '/includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $pdo->prepare('UPDATE stock_in SET expiry_cleared = 1 WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
    flash('success', 'ซ่อนล็อตนี้จากการแจ้งเตือนแล้ว');
    redirect('expiry.php');
}

$days = max(1, (int)($_GET['days'] ?? 30));
$st = $pdo->prepare('SELECT s.*, i.name, i.unit, i.quantity, sp.name AS supplier_name,
                            DATEDIFF(s.expiry_date, CURDATE()) AS days_left
                     FROM stock_in s JOIN ingredients i ON i.id = s.ingredient_id
                     LEFT JOIN suppliers sp ON sp.id = s.supplier_id
                     WHERE s.expiry_date IS NOT NULL AND s.expiry_cleared = 0
                       AND s.expiry_date <= CURDATE() + INTERVAL ? DAY
                     ORDER BY s.expiry_date ASC');
$st->execute([$days]);
$rows = $st->fetchAll();

$pageTitle = 'ตรวจสอบวันหมดอายุ';
$activePage = 'expiry';
require __DIR__ . '/includes/header.php';
?>
<div class="panel">
    <h2 class="panel-title">ล็อตที่จะหมดอายุ
        <form class="ms-auto d-flex gap-2 align-items-center filter-form" method="get">
            <label class="small text-secondary text-nowrap" for="days">ภายใน</label>
            <select class="form-select form-select-sm" id="days" name="days" onchange="this.form.submit()">
                <?php foreach ([3, 7, 14, 30, 60] as $d): ?><option value="<?= $d ?>" <?= $days === $d ? 'selected' : '' ?>><?= $d ?> วัน</option><?php endforeach; ?>
            </select>
        </form>
    </h2>
    <p class="small text-secondary">การแจ้งเตือนบนกระดิ่งจะแสดงล็อตที่หมดอายุภายใน <?= EXPIRY_ALERT_DAYS ?> วัน เมื่อทิ้งของแล้วให้กด "บันทึกทิ้ง" เพื่อตัดสต็อก ถ้าใช้หมดไปแล้วให้กด "ใช้หมดแล้ว" เพื่อซ่อนการแจ้งเตือน</p>
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>วัตถุดิบ</th><th>รับเข้า</th><th class="num">จำนวนล็อต</th><th>วันหมดอายุ</th><th>สถานะ</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $d = (int)$r['days_left']; ?>
                <tr>
                    <td class="fw-semibold"><?= e($r['name']) ?><div class="small text-secondary fw-normal">คงเหลือในคลัง <?= qty($r['quantity']) ?> <?= e($r['unit']) ?></div></td>
                    <td><?= thai_date($r['received_date']) ?><div class="small text-secondary"><?= e($r['supplier_name'] ?? '') ?></div></td>
                    <td class="num"><?= qty($r['qty_input']) ?> <?= e($r['input_unit']) ?></td>
                    <td><?= thai_date($r['expiry_date']) ?></td>
                    <td><span class="status <?= $d < 0 ? 'status-low' : ($d <= EXPIRY_ALERT_DAYS ? 'status-warn' : 'status-muted') ?>"><?= expiry_text($d) ?></span></td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-outline-danger" href="stock_out.php?ingredient=<?= $r['ingredient_id'] ?>&reason=<?= urlencode('หมดอายุ') ?>&lot=<?= $r['id'] ?>">บันทึกทิ้ง</a>
                        <form method="post" class="d-inline">
                            <?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>">
                            <button class="btn btn-sm btn-outline-secondary">ใช้หมดแล้ว</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="6" class="text-secondary">ไม่มีล็อตที่จะหมดอายุภายใน <?= $days ?> วัน</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
