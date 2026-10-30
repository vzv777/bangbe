<?php
require_once __DIR__ . '/includes/functions.php';
require_admin();

$reasons = ['ใช้ในครัว (ไม่ผ่านการขาย)', 'ของเสีย / เน่า', 'หมดอายุ', 'อาหารพนักงาน', 'อื่น ๆ'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $ingId = (int)($_POST['ingredient_id'] ?? 0);
    $amount = (float)($_POST['quantity'] ?? 0);
    $reason = in_array($_POST['reason'] ?? '', $reasons, true) ? $_POST['reason'] : 'อื่น ๆ';
    $note = trim($_POST['note'] ?? '') ?: null;
    $lotId = (int)($_POST['lot_id'] ?? 0);

    $st = $pdo->prepare('SELECT * FROM ingredients WHERE id = ?');
    $st->execute([$ingId]);
    $ing = $st->fetch();

    if (!$ing || $amount <= 0) {
        flash('danger', 'กรุณาเลือกวัตถุดิบและกรอกจำนวนที่มากกว่า 0');
    } elseif ($amount > (float)$ing['quantity']) {
        flash('danger', 'เบิกได้ไม่เกินจำนวนคงเหลือ (' . qty($ing['quantity']) . ' ' . $ing['unit'] . ') ถ้าของจริงมีมากกว่านี้ ให้นับสต็อกก่อน');
        redirect('stock_out.php?ingredient=' . $ingId);
    } else {
        $pdo->beginTransaction();
        $newQty = stock_move($pdo, $ingId, -$amount, 'withdraw', $lotId ? 'stock_in' : null, $lotId ?: null, $reason, $note);
        if ($lotId) {
            $pdo->prepare('UPDATE stock_in SET expiry_cleared = 1 WHERE id = ?')->execute([$lotId]);
        }
        $pdo->commit();
        flash('success', 'เบิก ' . $ing['name'] . ' ' . qty($amount) . ' ' . $ing['unit'] . ' (' . $reason . ') แล้ว คงเหลือ ' . qty($newQty) . ' ' . $ing['unit']);
    }
    redirect('stock_out.php');
}

$ingredients = $pdo->query('SELECT * FROM ingredients ORDER BY name')->fetchAll();
$selected = (int)($_GET['ingredient'] ?? 0);
$preReason = $_GET['reason'] ?? '';
$lot = (int)($_GET['lot'] ?? 0);

$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');
$st = $pdo->prepare("SELECT m.*, i.name, i.unit, i.cost_per_unit, u.full_name FROM stock_movements m
                     JOIN ingredients i ON i.id = m.ingredient_id JOIN users u ON u.id = m.user_id
                     WHERE m.type = 'withdraw' AND DATE(m.created_at) BETWEEN ? AND ?
                     ORDER BY m.id DESC");
$st->execute([$from, $to]);
$rows = $st->fetchAll();

$pageTitle = 'เบิกใช้ / ของเสีย';
$activePage = 'stock_out';
require __DIR__ . '/includes/header.php';
?>
<div class="row g-3">
    <div class="col-xl-4">
        <form class="panel" method="post">
            <h2 class="panel-title">บันทึกการเบิกใช้</h2>
            <p class="small text-secondary">วัตถุดิบที่ใช้ทำเมนูที่ขาย ระบบตัดสต็อกให้อัตโนมัติแล้ว หน้านี้ใช้สำหรับของที่ออกจากคลังโดยไม่ผ่านการขาย</p>
            <?= csrf_field() ?>
            <input type="hidden" name="lot_id" value="<?= $lot ?>">
            <div class="mb-3"><label class="form-label" for="ingredient_id">วัตถุดิบ</label>
                <select class="form-select" id="ingredient_id" name="ingredient_id" required>
                    <option value="">เลือกวัตถุดิบ</option>
                    <?php foreach ($ingredients as $i): ?>
                        <option value="<?= $i['id'] ?>" data-unit="<?= e($i['unit']) ?>" data-qty="<?= e(qty($i['quantity'])) ?>" data-raw="<?= (float)$i['quantity'] ?>"
                            <?= $selected === (int)$i['id'] ? 'selected' : '' ?>><?= e($i['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text" id="ingInfo"></div></div>
            <div class="mb-3"><label class="form-label" for="quantity">จำนวน <span id="unitLabel"></span></label>
                <div class="input-group">
                    <input class="form-control" id="quantity" name="quantity" type="number" step="any" min="0.01" required>
                    <button class="btn btn-outline-primary" type="button" id="qtyHalf" disabled>ครึ่งหนึ่ง</button>
                    <button class="btn btn-accent" type="button" id="qtyAll" disabled><i class="bi bi-lightning-charge-fill"></i> ทั้งหมด</button>
                </div>
                <div class="form-text">กด "ทั้งหมด" เพื่อเบิกที่เหลืออยู่ทั้งหมด เช่น ทิ้งของเสียทั้งล็อต</div></div>
            <div class="mb-3"><label class="form-label" for="reason">เหตุผล</label>
                <select class="form-select" id="reason" name="reason">
                    <?php foreach ($reasons as $r): ?><option <?= $preReason === $r ? 'selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?>
                </select></div>
            <div class="mb-3"><label class="form-label" for="note">หมายเหตุ</label>
                <input class="form-control" id="note" name="note"></div>
            <button class="btn btn-primary w-100">บันทึกการเบิก</button>
        </form>
    </div>
    <div class="col-xl-8">
        <div class="panel">
            <h2 class="panel-title">ประวัติการเบิก</h2>
            <form class="row g-2 mb-3 filter-form" method="get">
                <div class="col-sm-4"><input class="form-control" type="date" name="from" value="<?= e($from) ?>" aria-label="ตั้งแต่วันที่"></div>
                <div class="col-sm-4"><input class="form-control" type="date" name="to" value="<?= e($to) ?>" aria-label="ถึงวันที่"></div>
                <div class="col-sm-4"><button class="btn btn-outline-primary w-100">แสดง</button></div>
            </form>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>วันเวลา</th><th>วัตถุดิบ</th><th class="num">จำนวน</th><th>เหตุผล</th><th class="num">มูลค่าโดยประมาณ</th></tr></thead>
                    <tbody>
                    <?php $sum = 0; foreach ($rows as $r): $val = abs($r['qty_change']) * $r['cost_per_unit']; $sum += $val; ?>
                        <tr>
                            <td><?= thai_date($r['created_at'], true) ?><div class="small text-secondary"><?= e($r['full_name']) ?></div></td>
                            <td class="fw-semibold"><?= e($r['name']) ?><?php if ($r['note']): ?><div class="small text-secondary fw-normal"><?= e($r['note']) ?></div><?php endif; ?></td>
                            <td class="num"><?= qty(abs($r['qty_change'])) ?> <?= e($r['unit']) ?></td>
                            <td><?= e($r['reason']) ?></td>
                            <td class="num"><?= money($val) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?><tr><td colspan="5" class="text-secondary">ไม่มีการเบิกในช่วงวันที่นี้</td></tr><?php endif; ?>
                    </tbody>
                    <?php if ($rows): ?><tfoot><tr><th colspan="4">รวม</th><th class="num">฿<?= money($sum) ?></th></tr></tfoot><?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>
<?php
$pageScript = <<<'JS'
<script>
(function () {
    const sel = document.getElementById('ingredient_id');
    const info = document.getElementById('ingInfo');
    const label = document.getElementById('unitLabel');
    const input = document.getElementById('quantity');
    const btnAll = document.getElementById('qtyAll');
    const btnHalf = document.getElementById('qtyHalf');
    const stock = () => { const o = sel.options[sel.selectedIndex]; return o.value ? parseFloat(o.dataset.raw) || 0 : 0; };
    function update() {
        const o = sel.options[sel.selectedIndex];
        btnAll.disabled = btnHalf.disabled = stock() <= 0;   // ยังไม่เลือก หรือของหมดแล้ว กดไม่ได้
        if (!o.value) { info.textContent = ''; label.textContent = ''; return; }
        info.textContent = 'คงเหลือตอนนี้ ' + o.dataset.qty + ' ' + o.dataset.unit;
        label.textContent = '(' + o.dataset.unit + ')';
    }
    // ใส่จำนวนให้อัตโนมัติ ทั้งหมด = ยอดคงเหลือ, ครึ่งหนึ่ง = ปัดลงทศนิยม 2 ตำแหน่ง
    btnAll.addEventListener('click', () => { input.value = stock(); input.focus(); });
    btnHalf.addEventListener('click', () => { input.value = Math.floor(stock() / 2 * 100) / 100; input.focus(); });
    sel.addEventListener('change', () => { input.value = ''; update(); });
    update();
})();
</script>
JS;
require __DIR__ . '/includes/footer.php';
?>
