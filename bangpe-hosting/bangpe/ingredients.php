<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'save') {
        $name = trim($_POST['name'] ?? '');
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $unit = trim($_POST['unit'] ?? '');
        $pUnit = trim($_POST['purchase_unit'] ?? '');
        $pFactor = (float)($_POST['purchase_factor'] ?? 1);
        $min = max(0, (float)($_POST['min_quantity'] ?? 0));
        $cost = max(0, (float)($_POST['cost_per_unit'] ?? 0));

        if ($name === '' || !$categoryId || $unit === '') {
            flash('danger', 'กรุณากรอกชื่อ ประเภท และหน่วยนับให้ครบ');
            redirect('ingredients.php' . ($id ? '?edit=' . $id : ''));
        }
        if ($pUnit === '' || $pFactor <= 0) {
            $pUnit = null;
            $pFactor = 1;
        }

        if ($id) {
            $pdo->prepare('UPDATE ingredients SET name = ?, category_id = ?, unit = ?, purchase_unit = ?, purchase_factor = ?, min_quantity = ?, cost_per_unit = ? WHERE id = ?')
                ->execute([$name, $categoryId, $unit, $pUnit, $pFactor, $min, $cost, $id]);
            flash('success', 'บันทึกการแก้ไข "' . $name . '" แล้ว');
        } else {
            $start = max(0, (float)($_POST['start_quantity'] ?? 0));
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO ingredients (name, category_id, unit, purchase_unit, purchase_factor, quantity, min_quantity, cost_per_unit) VALUES (?, ?, ?, ?, ?, 0, ?, ?)')
                ->execute([$name, $categoryId, $unit, $pUnit, $pFactor, $min, $cost]);
            $newId = (int)$pdo->lastInsertId();
            if ($start > 0) {
                stock_move($pdo, $newId, $start, 'adjust', null, null, 'ยอดยกมา', 'ยอดเริ่มต้นตอนเพิ่มวัตถุดิบ');
            }
            $pdo->commit();
            flash('success', 'เพิ่มวัตถุดิบ "' . $name . '" แล้ว');
        }
    } elseif ($action === 'delete') {
        // ลบได้เฉพาะวัตถุดิบที่ยังไม่เคยใช้งาน เพื่อให้รายงานย้อนหลังถูกต้อง
        $st = $pdo->prepare("SELECT
            (SELECT COUNT(*) FROM stock_movements WHERE ingredient_id = ? AND (reason IS NULL OR reason <> 'ยอดยกมา'))
          + (SELECT COUNT(*) FROM stock_in WHERE ingredient_id = ?)
          + (SELECT COUNT(*) FROM recipes WHERE ingredient_id = ?)");
        $st->execute([$id, $id, $id]);
        if ((int)$st->fetchColumn() > 0) {
            flash('danger', 'ลบไม่ได้ เพราะวัตถุดิบนี้มีประวัติรับเข้า เบิกใช้ ขาย หรืออยู่ในสูตรอาหารแล้ว (เก็บไว้เพื่อให้รายงานย้อนหลังถูกต้อง)');
        } else {
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM stock_movements WHERE ingredient_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM ingredients WHERE id = ?')->execute([$id]);
            $pdo->commit();
            flash('success', 'ลบวัตถุดิบแล้ว');
        }
    }
    redirect('ingredients.php');
}

$categories = $pdo->query('SELECT * FROM categories ORDER BY name')->fetchAll();

$edit = null;
if (isset($_GET['edit']) && is_admin()) {
    $st = $pdo->prepare('SELECT * FROM ingredients WHERE id = ?');
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch() ?: null;
}

// ค้นหา / กรอง
$q = trim($_GET['q'] ?? '');
$cat = (int)($_GET['category'] ?? 0);
$filter = $_GET['filter'] ?? '';
$where = ['i.name LIKE ?'];
$params = ["%$q%"];
if ($cat) { $where[] = 'i.category_id = ?'; $params[] = $cat; }
if ($filter === 'low') { $where[] = 'i.min_quantity > 0 AND i.quantity <= i.min_quantity'; }

$st = $pdo->prepare('SELECT i.*, c.name AS category_name FROM ingredients i
                     JOIN categories c ON c.id = i.category_id
                     WHERE ' . implode(' AND ', $where) . ' ORDER BY c.name, i.name');
$st->execute($params);
$rows = $st->fetchAll();

$pageTitle = 'วัตถุดิบคงเหลือ';
$activePage = 'ingredients';
require __DIR__ . '/includes/header.php';
?>
<div class="row g-3">
    <?php if (is_admin()): ?>
    <div class="col-xl-4">
        <form class="panel" method="post">
            <h2 class="panel-title"><?= $edit ? 'แก้ไขวัตถุดิบ' : 'เพิ่มวัตถุดิบ' ?></h2>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
            <div class="mb-3"><label class="form-label" for="name">ชื่อวัตถุดิบ</label>
                <input class="form-control" id="name" name="name" value="<?= e($edit['name'] ?? '') ?>" required></div>
            <div class="mb-3"><label class="form-label" for="category_id">ประเภท</label>
                <select class="form-select" id="category_id" name="category_id" required>
                    <option value="">เลือกประเภท</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= ($edit['category_id'] ?? 0) == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="mb-3"><label class="form-label" for="unit">หน่วยนับในสต็อก</label>
                <input class="form-control" id="unit" name="unit" value="<?= e($edit['unit'] ?? '') ?>" placeholder="เช่น กรัม, มล., ฟอง" required>
                <div class="form-text">ใช้หน่วยเล็กที่สุดที่ใช้ในสูตรอาหาร ระบบตัดสต็อกด้วยหน่วยนี้</div></div>
            <div class="row g-2 mb-3">
                <div class="col-7"><label class="form-label" for="purchase_unit">หน่วยตอนซื้อ</label>
                    <input class="form-control" id="purchase_unit" name="purchase_unit" value="<?= e($edit['purchase_unit'] ?? '') ?>" placeholder="เช่น กิโลกรัม"></div>
                <div class="col-5"><label class="form-label" for="purchase_factor">เท่ากับ</label>
                    <input class="form-control" id="purchase_factor" name="purchase_factor" type="number" step="0.0001" min="0" value="<?= e(qty($edit['purchase_factor'] ?? 1)) ?>"></div>
                <div class="form-text mt-1">เช่น ซื้อเป็น กิโลกรัม = 1000 กรัม (ไม่บังคับ)</div>
            </div>
            <div class="row g-2 mb-3">
                <div class="col-6"><label class="form-label" for="min_quantity">ปริมาณขั้นต่ำ</label>
                    <input class="form-control" id="min_quantity" name="min_quantity" type="number" step="0.01" min="0" value="<?= e($edit['min_quantity'] ?? 0) ?>">
                </div>
                <div class="col-6"><label class="form-label" for="cost_per_unit">ต้นทุน/หน่วย (บาท)</label>
                    <input class="form-control" id="cost_per_unit" name="cost_per_unit" type="number" step="0.0001" min="0" value="<?= e($edit['cost_per_unit'] ?? 0) ?>">
                </div>
                <div class="form-text mt-1">ต่ำกว่าขั้นต่ำจะแจ้งเตือน ต้นทุนจะอัปเดตเองเมื่อบันทึกรับเข้า</div>
            </div>
            <?php if (!$edit): ?>
            <div class="mb-3"><label class="form-label" for="start_quantity">จำนวนคงเหลือเริ่มต้น</label>
                <input class="form-control" id="start_quantity" name="start_quantity" type="number" step="0.01" min="0" value="0">
                <div class="form-text">หลังจากนี้ จำนวนจะเปลี่ยนผ่านการรับเข้า เบิกใช้ ขาย และนับสต็อกเท่านั้น</div></div>
            <?php else: ?>
            <p class="small text-secondary">คงเหลือปัจจุบัน <?= qty($edit['quantity']) ?> <?= e($edit['unit']) ?> ถ้าต้องการแก้จำนวน ให้ใช้หน้า <a href="stock_adjust.php">นับสต็อก</a></p>
            <?php endif; ?>
            <button class="btn btn-primary"><?= $edit ? 'บันทึกการแก้ไข' : 'เพิ่มวัตถุดิบ' ?></button>
            <?php if ($edit): ?><a class="btn btn-link" href="ingredients.php">ยกเลิก</a><?php endif; ?>
        </form>
    </div>
    <?php endif; ?>

    <div class="<?= is_admin() ? 'col-xl-8' : 'col-12' ?>">
        <div class="panel">
            <form class="row g-2 mb-3 filter-form" method="get">
                <div class="col-sm-5"><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="ค้นหาชื่อวัตถุดิบ"></div>
                <div class="col-sm-4"><select class="form-select" name="category">
                    <option value="">ทุกประเภท</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $cat == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                    <?php endforeach; ?>
                </select></div>
                <div class="col-sm-3 d-flex gap-2">
                    <button class="btn btn-primary flex-fill">ค้นหา</button>
                    <?php if ($q || $cat || $filter): ?><a class="btn btn-outline-secondary" href="ingredients.php">ล้าง</a><?php endif; ?>
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="lowOnly" name="filter" value="low" <?= $filter === 'low' ? 'checked' : '' ?> onchange="this.form.submit()">
                        <label class="form-check-label" for="lowOnly">แสดงเฉพาะที่ใกล้หมด</label>
                    </div>
                </div>
            </form>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>วัตถุดิบ</th><th>ประเภท</th><th class="num">คงเหลือ</th><th class="num">ขั้นต่ำ</th><th>สถานะ</th><?php if (is_admin()): ?><th></th><?php endif; ?></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r):
                        $low = $r['min_quantity'] > 0 && $r['quantity'] <= $r['min_quantity'];
                        $out = $r['quantity'] <= 0; ?>
                        <tr>
                            <td class="fw-semibold"><?= e($r['name']) ?>
                                <?php if ($r['purchase_unit']): ?><div class="small text-secondary fw-normal">ซื้อเป็น<?= e($r['purchase_unit']) ?> (<?= qty($r['purchase_factor']) ?> <?= e($r['unit']) ?>)</div><?php endif; ?></td>
                            <td><?= e($r['category_name']) ?></td>
                            <td class="num <?= $low ? 'text-danger fw-semibold' : '' ?>"><?= qty($r['quantity']) ?> <?= e($r['unit']) ?>
                                <?php if ($r['purchase_unit'] && $r['purchase_factor'] > 1): ?><div class="small text-secondary fw-normal">≈ <?= qty($r['quantity'] / $r['purchase_factor']) ?> <?= e($r['purchase_unit']) ?></div><?php endif; ?></td>
                            <td class="num"><?= qty($r['min_quantity']) ?></td>
                            <td><?php if ($out): ?><span class="status status-low">หมด</span>
                                <?php elseif ($low): ?><span class="status status-low">ใกล้หมด</span>
                                <?php else: ?><span class="status status-ok">พอใช้</span><?php endif; ?></td>
                            <?php if (is_admin()): ?>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-primary" href="stock_in.php?ingredient=<?= $r['id'] ?>" title="รับเข้า"><i class="bi bi-plus-lg"></i></a>
                                <a class="btn btn-sm btn-outline-secondary" href="?edit=<?= $r['id'] ?>">แก้ไข</a>
                                <form method="post" class="d-inline" onsubmit="return confirm('ลบวัตถุดิบ <?= e($r['name']) ?>?')">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger">ลบ</button>
                                </form>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?><tr><td colspan="6" class="text-secondary">ไม่พบวัตถุดิบที่ตรงกับการค้นหา</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
