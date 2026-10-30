<?php
require_once __DIR__ . '/includes/functions.php';
require_admin();

$menuId = (int)($_GET['menu'] ?? $_POST['menu_id'] ?? 0);
$st = $pdo->prepare('SELECT * FROM menus WHERE id = ?');
$st->execute([$menuId]);
$menu = $st->fetch();
if (!$menu) {
    flash('danger', 'ไม่พบเมนูนี้');
    redirect('menus.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $ingId = (int)($_POST['ingredient_id'] ?? 0);
        $q = (float)($_POST['quantity'] ?? 0);
        if ($ingId && $q > 0) {
            // ถ้ามีวัตถุดิบนี้ในสูตรแล้ว ให้แทนที่ปริมาณ
            $pdo->prepare('INSERT INTO recipes (menu_id, ingredient_id, quantity) VALUES (?, ?, ?)
                           ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)')->execute([$menuId, $ingId, $q]);
            flash('success', 'บันทึกวัตถุดิบในสูตรแล้ว');
        } else {
            flash('danger', 'กรุณาเลือกวัตถุดิบและกรอกปริมาณที่มากกว่า 0');
        }
    } elseif ($action === 'update') {
        foreach ($_POST['qty'] ?? [] as $rid => $q) {
            if ((float)$q > 0) {
                $pdo->prepare('UPDATE recipes SET quantity = ? WHERE id = ? AND menu_id = ?')->execute([(float)$q, (int)$rid, $menuId]);
            }
        }
        flash('success', 'บันทึกปริมาณในสูตรแล้ว');
    } elseif ($action === 'remove') {
        $pdo->prepare('DELETE FROM recipes WHERE id = ? AND menu_id = ?')->execute([(int)($_POST['recipe_id'] ?? 0), $menuId]);
        flash('success', 'นำวัตถุดิบออกจากสูตรแล้ว');
    }
    redirect('recipe.php?menu=' . $menuId);
}

$st = $pdo->prepare('SELECT r.*, i.name, i.unit, i.cost_per_unit, i.quantity AS stock FROM recipes r
                     JOIN ingredients i ON i.id = r.ingredient_id WHERE r.menu_id = ? ORDER BY i.name');
$st->execute([$menuId]);
$lines = $st->fetchAll();
$cost = array_sum(array_map(fn($l) => $l['quantity'] * $l['cost_per_unit'], $lines));
$ingredients = $pdo->query('SELECT id, name, unit FROM ingredients ORDER BY name')->fetchAll();

$pageTitle = 'สูตร: ' . $menu['name'];
$activePage = 'menus';
require __DIR__ . '/includes/header.php';
?>
<p><a href="menus.php"><i class="bi bi-arrow-left"></i> กลับไปหน้าเมนู</a></p>
<div class="row g-3">
    <div class="col-xl-8">
        <form class="panel" method="post">
            <h2 class="panel-title">วัตถุดิบที่ใช้ต่อ 1 จาน
                <span class="ms-auto small text-secondary">ราคาขาย ฿<?= money($menu['price']) ?> ต้นทุน ฿<?= money($cost) ?> กำไร ฿<?= money($menu['price'] - $cost) ?></span>
            </h2>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="menu_id" value="<?= $menuId ?>">
            <?php if (!$lines): ?>
                <p class="text-secondary mb-0">เมนูนี้ยังไม่มีสูตร เมื่อขายจะไม่ตัดสต็อก เพิ่มวัตถุดิบจากฟอร์มด้านขวา</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>วัตถุดิบ</th><th style="min-width:160px">ปริมาณต่อจาน</th><th class="num">ต้นทุน</th><th class="num">ทำได้</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($lines as $l): ?>
                        <tr>
                            <td class="fw-semibold"><?= e($l['name']) ?></td>
                            <td><div class="input-group input-group-sm">
                                <input class="form-control" type="number" step="0.01" min="0.01" name="qty[<?= $l['id'] ?>]" value="<?= e(qty($l['quantity'])) ?>" aria-label="ปริมาณ <?= e($l['name']) ?>">
                                <span class="input-group-text"><?= e($l['unit']) ?></span></div></td>
                            <td class="num"><?= money($l['quantity'] * $l['cost_per_unit']) ?></td>
                            <td class="num"><?= (int)floor($l['stock'] / $l['quantity']) ?> จาน</td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-outline-danger" form="remove<?= $l['id'] ?>">นำออก</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <button class="btn btn-primary mt-3">บันทึกปริมาณ</button>
            <?php endif; ?>
        </form>
        <?php foreach ($lines as $l): ?>
            <form id="remove<?= $l['id'] ?>" method="post" onsubmit="return confirm('นำ <?= e($l['name']) ?> ออกจากสูตร?')">
                <?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="menu_id" value="<?= $menuId ?>"><input type="hidden" name="recipe_id" value="<?= $l['id'] ?>">
            </form>
        <?php endforeach; ?>
    </div>
    <div class="col-xl-4">
        <form class="panel" method="post">
            <h2 class="panel-title">เพิ่มวัตถุดิบในสูตร</h2>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="menu_id" value="<?= $menuId ?>">
            <div class="mb-3"><label class="form-label" for="ingredient_id">วัตถุดิบ</label>
                <select class="form-select" id="ingredient_id" name="ingredient_id" required>
                    <option value="">เลือกวัตถุดิบ</option>
                    <?php foreach ($ingredients as $i): ?><option value="<?= $i['id'] ?>" data-unit="<?= e($i['unit']) ?>"><?= e($i['name']) ?> (<?= e($i['unit']) ?>)</option><?php endforeach; ?>
                </select></div>
            <div class="mb-3"><label class="form-label" for="quantity">ปริมาณต่อ 1 จาน</label>
                <input class="form-control" id="quantity" name="quantity" type="number" step="0.01" min="0.01" required>
                <div class="form-text">ใช้หน่วยเดียวกับหน่วยในสต็อกของวัตถุดิบนั้น</div></div>
            <button class="btn btn-primary w-100">เพิ่มในสูตร</button>
        </form>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
