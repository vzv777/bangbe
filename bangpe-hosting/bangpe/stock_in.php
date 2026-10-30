<?php
require_once __DIR__ . '/includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $ingId = (int)($_POST['ingredient_id'] ?? 0);
    $supplierId = (int)($_POST['supplier_id'] ?? 0) ?: null;
    $date = $_POST['received_date'] ?? date('Y-m-d');
    $qtyInput = (float)($_POST['qty_input'] ?? 0);
    $mode = $_POST['unit_mode'] ?? 'base';
    $totalCost = max(0, (float)($_POST['total_cost'] ?? 0));
    $expiry = ($_POST['expiry_date'] ?? '') ?: null;
    $note = trim($_POST['note'] ?? '') ?: null;

    $st = $pdo->prepare('SELECT * FROM ingredients WHERE id = ?');
    $st->execute([$ingId]);
    $ing = $st->fetch();

    if (!$ing || $qtyInput <= 0) {
        flash('danger', 'กรุณาเลือกวัตถุดิบและกรอกจำนวนที่มากกว่า 0');
        redirect('stock_in.php');
    }
    if ($expiry && $expiry < $date) {
        flash('danger', 'วันหมดอายุต้องไม่ก่อนวันที่รับเข้า');
        redirect('stock_in.php?ingredient=' . $ingId);
    }

    $usePurchase = $mode === 'purchase' && $ing['purchase_unit'];
    $factor = $usePurchase ? (float)$ing['purchase_factor'] : 1;
    $baseQty = round($qtyInput * $factor, 2);
    $inputUnit = $usePurchase ? $ing['purchase_unit'] : $ing['unit'];

    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO stock_in (ingredient_id, supplier_id, received_date, qty_input, input_unit, base_qty, total_cost, expiry_date, note, user_id)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$ingId, $supplierId, $date, $qtyInput, $inputUnit, $baseQty, $totalCost, $expiry, $note, current_user()['id']]);
    $lotId = (int)$pdo->lastInsertId();
    $newQty = stock_move($pdo, $ingId, $baseQty, 'in', 'stock_in', $lotId, null, $note);
    if ($totalCost > 0) {
        // ต้นทุนต่อหน่วยล่าสุด ใช้คำนวณต้นทุนต่อจาน
        $pdo->prepare('UPDATE ingredients SET cost_per_unit = ? WHERE id = ?')->execute([round($totalCost / $baseQty, 4), $ingId]);
    }
    $pdo->commit();

    flash('success', 'รับเข้า ' . $ing['name'] . ' ' . qty($qtyInput) . ' ' . $inputUnit . ' แล้ว คงเหลือ ' . qty($newQty) . ' ' . $ing['unit']);
    redirect('stock_in.php');
}

$ingredients = $pdo->query('SELECT * FROM ingredients ORDER BY name')->fetchAll();
$suppliers = $pdo->query('SELECT id, name FROM suppliers ORDER BY name')->fetchAll();
$selected = (int)($_GET['ingredient'] ?? 0);

$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');
$st = $pdo->prepare('SELECT s.*, i.name, i.unit, sp.name AS supplier_name, u.full_name
                     FROM stock_in s JOIN ingredients i ON i.id = s.ingredient_id
                     LEFT JOIN suppliers sp ON sp.id = s.supplier_id
                     JOIN users u ON u.id = s.user_id
                     WHERE s.received_date BETWEEN ? AND ?
                     ORDER BY s.received_date DESC, s.id DESC');
$st->execute([$from, $to]);
$rows = $st->fetchAll();
$sumCost = array_sum(array_column($rows, 'total_cost'));

$pageTitle = 'บันทึกรับเข้าวัตถุดิบ';
$activePage = 'stock_in';
require __DIR__ . '/includes/header.php';
?>
<div class="row g-3">
    <div class="col-xl-4">
        <form class="panel" method="post" id="inForm">
            <h2 class="panel-title">รับเข้าวัตถุดิบ</h2>
            <?= csrf_field() ?>
            <div class="mb-3"><label class="form-label" for="ingredient_id">วัตถุดิบ</label>
                <select class="form-select" id="ingredient_id" name="ingredient_id" required>
                    <option value="">เลือกวัตถุดิบ</option>
                    <?php foreach ($ingredients as $i): ?>
                        <option value="<?= $i['id'] ?>" data-unit="<?= e($i['unit']) ?>" data-punit="<?= e($i['purchase_unit']) ?>"
                                data-factor="<?= e($i['purchase_factor']) ?>" data-qty="<?= e(qty($i['quantity'])) ?>"
                                <?= $selected === (int)$i['id'] ? 'selected' : '' ?>><?= e($i['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text" id="ingInfo"></div></div>
            <div class="row g-2 mb-3">
                <div class="col-6"><label class="form-label" for="qty_input">จำนวน</label>
                    <input class="form-control" id="qty_input" name="qty_input" type="number" step="0.01" min="0.01" required></div>
                <div class="col-6"><label class="form-label" for="unit_mode">หน่วย</label>
                    <select class="form-select" id="unit_mode" name="unit_mode"></select></div>
                <div class="form-text mt-1" id="convertInfo"></div>
            </div>
            <div class="mb-3"><label class="form-label" for="total_cost">ราคารวม (บาท)</label>
                <input class="form-control" id="total_cost" name="total_cost" type="number" step="0.01" min="0" placeholder="0.00"></div>
            <div class="mb-3"><label class="form-label" for="supplier_id">ผู้จำหน่าย</label>
                <select class="form-select" id="supplier_id" name="supplier_id">
                    <option value="">ไม่ระบุ</option>
                    <?php foreach ($suppliers as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
                </select>
                <div class="form-text"><a href="suppliers.php">เพิ่มผู้จำหน่ายใหม่</a></div></div>
            <div class="row g-2 mb-3">
                <div class="col-6"><label class="form-label" for="received_date">วันที่รับเข้า</label>
                    <input class="form-control" id="received_date" name="received_date" type="date" value="<?= date('Y-m-d') ?>" required></div>
                <div class="col-6"><label class="form-label" for="expiry_date">วันหมดอายุ</label>
                    <input class="form-control" id="expiry_date" name="expiry_date" type="date"></div>
                <div class="form-text mt-1">ไม่ใส่วันหมดอายุได้ ถ้าเป็นของที่เก็บได้นาน</div>
            </div>
            <div class="mb-3"><label class="form-label" for="note">หมายเหตุ</label>
                <input class="form-control" id="note" name="note" placeholder="เช่น เลขที่บิลร้านค้า"></div>
            <button class="btn btn-primary w-100">บันทึกรับเข้า</button>
        </form>
    </div>

    <div class="col-xl-8">
        <div class="panel">
            <h2 class="panel-title">ประวัติการรับเข้า</h2>
            <form class="row g-2 mb-3 filter-form" method="get">
                <div class="col-sm-4"><input class="form-control" type="date" name="from" value="<?= e($from) ?>" aria-label="ตั้งแต่วันที่"></div>
                <div class="col-sm-4"><input class="form-control" type="date" name="to" value="<?= e($to) ?>" aria-label="ถึงวันที่"></div>
                <div class="col-sm-4"><button class="btn btn-outline-primary w-100">แสดง</button></div>
            </form>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>วันที่</th><th>วัตถุดิบ</th><th class="num">จำนวน</th><th class="num">ราคารวม</th><th>ผู้จำหน่าย</th><th>หมดอายุ</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= thai_date($r['received_date']) ?><div class="small text-secondary"><?= e($r['full_name']) ?></div></td>
                            <td class="fw-semibold"><?= e($r['name']) ?><?php if ($r['note']): ?><div class="small text-secondary fw-normal"><?= e($r['note']) ?></div><?php endif; ?></td>
                            <td class="num"><?= qty($r['qty_input']) ?> <?= e($r['input_unit']) ?>
                                <?php if ($r['input_unit'] !== $r['unit']): ?><div class="small text-secondary">= <?= qty($r['base_qty']) ?> <?= e($r['unit']) ?></div><?php endif; ?></td>
                            <td class="num"><?= money($r['total_cost']) ?></td>
                            <td><?= e($r['supplier_name'] ?? '-') ?></td>
                            <td><?= $r['expiry_date'] ? thai_date($r['expiry_date']) : '-' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?><tr><td colspan="6" class="text-secondary">ไม่มีการรับเข้าในช่วงวันที่นี้</td></tr><?php endif; ?>
                    </tbody>
                    <?php if ($rows): ?>
                    <tfoot><tr><th colspan="3">รวม <?= count($rows) ?> รายการ</th><th class="num">฿<?= money($sumCost) ?></th><th colspan="2"></th></tr></tfoot>
                    <?php endif; ?>
                </table>
            </div>
            <p class="small text-secondary mt-3 mb-0">บันทึกผิด? ใช้หน้า <a href="stock_adjust.php">นับสต็อก</a> ปรับยอดให้ตรงกับของจริง ระบบจะเก็บประวัติการปรับไว้</p>
        </div>
    </div>
</div>
<?php
$pageScript = <<<'JS'
<script>
(function () {
    const sel = document.getElementById('ingredient_id');
    const mode = document.getElementById('unit_mode');
    const qtyIn = document.getElementById('qty_input');
    const info = document.getElementById('ingInfo');
    const conv = document.getElementById('convertInfo');

    function opt() { return sel.options[sel.selectedIndex]; }

    function fillUnits() {
        const o = opt();
        mode.innerHTML = '';
        if (!o || !o.value) { info.textContent = ''; conv.textContent = ''; return; }
        if (o.dataset.punit) mode.add(new Option(o.dataset.punit, 'purchase'));
        mode.add(new Option(o.dataset.unit, 'base'));
        info.textContent = 'คงเหลือตอนนี้ ' + o.dataset.qty + ' ' + o.dataset.unit;
        showConvert();
    }

    function showConvert() {
        const o = opt();
        const q = parseFloat(qtyIn.value);
        if (!o || !o.value || mode.value !== 'purchase' || !q) { conv.textContent = ''; return; }
        const base = q * parseFloat(o.dataset.factor);
        conv.textContent = 'จะเพิ่มเข้าสต็อก ' + base.toLocaleString('th-TH', {maximumFractionDigits: 2}) + ' ' + o.dataset.unit;
    }

    sel.addEventListener('change', fillUnits);
    mode.addEventListener('change', showConvert);
    qtyIn.addEventListener('input', showConvert);
    fillUnits();
})();
</script>
JS;
require __DIR__ . '/includes/footer.php';
?>
