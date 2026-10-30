<?php
require_once __DIR__ . '/includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $counts = $_POST['counted'] ?? [];
    $note = trim($_POST['note'] ?? '') ?: null;
    $changed = 0;

    $pdo->beginTransaction();
    foreach ($counts as $id => $val) {
        if ($val === '' || !is_numeric($val)) continue;  // ช่องว่าง = ไม่ได้นับรายการนี้
        $counted = max(0, round((float)$val, 2));
        $st = $pdo->prepare('SELECT quantity FROM ingredients WHERE id = ? FOR UPDATE');
        $st->execute([(int)$id]);
        $current = $st->fetchColumn();
        if ($current === false) continue;
        $diff = round($counted - (float)$current, 2);
        if ($diff != 0) {
            stock_move($pdo, (int)$id, $diff, 'adjust', null, null, 'นับสต็อก', $note);
            $changed++;
        }
    }
    $pdo->commit();

    flash('success', $changed ? "ปรับยอดแล้ว $changed รายการ" : 'ยอดที่นับตรงกับในระบบทุกรายการ ไม่มีการปรับ');
    redirect('stock_adjust.php');
}

$rows = $pdo->query('SELECT i.*, c.name AS category_name FROM ingredients i JOIN categories c ON c.id = i.category_id ORDER BY c.name, i.name')->fetchAll();
$history = $pdo->query("SELECT m.*, i.name, i.unit, u.full_name FROM stock_movements m
                        JOIN ingredients i ON i.id = m.ingredient_id JOIN users u ON u.id = m.user_id
                        WHERE m.type = 'adjust' ORDER BY m.id DESC LIMIT 30")->fetchAll();

$pageTitle = 'นับสต็อก';
$activePage = 'stock_adjust';
require __DIR__ . '/includes/header.php';
?>
<form class="panel" method="post">
    <h2 class="panel-title">กรอกจำนวนที่นับได้จริง</h2>
    <p class="small text-secondary">กรอกเฉพาะรายการที่นับ เว้นว่างไว้ถ้าไม่ได้นับ ระบบจะปรับยอดให้ตรงกับที่กรอกและบันทึกส่วนต่างเป็นประวัติ</p>
    <?= csrf_field() ?>
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>วัตถุดิบ</th><th>ประเภท</th><th class="num">ในระบบ</th><th style="min-width:150px">นับได้จริง</th><th class="num">ส่วนต่าง</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="fw-semibold"><?= e($r['name']) ?></td>
                    <td><?= e($r['category_name']) ?></td>
                    <td class="num"><?= qty($r['quantity']) ?> <?= e($r['unit']) ?></td>
                    <td><div class="input-group input-group-sm">
                        <input class="form-control count-input" type="number" step="0.01" min="0" name="counted[<?= $r['id'] ?>]" data-current="<?= e($r['quantity']) ?>" aria-label="จำนวนที่นับได้ของ <?= e($r['name']) ?>">
                        <span class="input-group-text"><?= e($r['unit']) ?></span></div></td>
                    <td class="num diff-cell">-</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="row g-2 mt-3 align-items-end">
        <div class="col-md-6"><label class="form-label" for="note">หมายเหตุการนับ</label>
            <input class="form-control" id="note" name="note" placeholder="เช่น นับสิ้นเดือน ต.ค."></div>
        <div class="col-md-3"><button class="btn btn-primary w-100" onclick="return confirm('ยืนยันปรับยอดสต็อกตามที่นับ?')">บันทึกผลการนับ</button></div>
    </div>
</form>

<div class="panel">
    <h2 class="panel-title">ประวัติการปรับยอดล่าสุด</h2>
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>วันเวลา</th><th>วัตถุดิบ</th><th class="num">ปรับ</th><th class="num">ยอดหลังปรับ</th><th>เหตุผล</th></tr></thead>
            <tbody>
            <?php foreach ($history as $h): ?>
                <tr>
                    <td><?= thai_date($h['created_at'], true) ?><div class="small text-secondary"><?= e($h['full_name']) ?></div></td>
                    <td><?= e($h['name']) ?></td>
                    <td class="num <?= $h['qty_change'] < 0 ? 'text-danger' : 'text-success' ?>"><?= $h['qty_change'] > 0 ? '+' : '' ?><?= qty($h['qty_change']) ?> <?= e($h['unit']) ?></td>
                    <td class="num"><?= qty($h['balance_after']) ?></td>
                    <td><?= e($h['reason']) ?><?php if ($h['note']): ?><div class="small text-secondary"><?= e($h['note']) ?></div><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
$pageScript = <<<'JS'
<script>
document.querySelectorAll('.count-input').forEach(inp => {
    inp.addEventListener('input', () => {
        const cell = inp.closest('tr').querySelector('.diff-cell');
        if (inp.value === '') { cell.textContent = '-'; cell.className = 'num diff-cell'; return; }
        const d = Math.round((parseFloat(inp.value) - parseFloat(inp.dataset.current)) * 100) / 100;
        cell.textContent = (d > 0 ? '+' : '') + d.toLocaleString('th-TH');
        cell.className = 'num diff-cell fw-semibold ' + (d < 0 ? 'text-danger' : d > 0 ? 'text-success' : '');
    });
});
</script>
JS;
require __DIR__ . '/includes/footer.php';
?>
