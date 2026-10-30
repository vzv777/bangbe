<?php
require_once __DIR__ . '/includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'save') {
        $name = trim($_POST['name'] ?? '');
        $group = trim($_POST['menu_group'] ?? '') ?: 'อาหาร';
        $price = (float)($_POST['price'] ?? 0);
        $active = isset($_POST['is_active']) ? 1 : 0;
        if ($name === '' || $price < 0) {
            flash('danger', 'กรุณากรอกชื่อเมนูและราคาให้ถูกต้อง');
        } elseif ($id) {
            $pdo->prepare('UPDATE menus SET name = ?, menu_group = ?, price = ?, is_active = ? WHERE id = ?')->execute([$name, $group, $price, $active, $id]);
            flash('success', 'บันทึกการแก้ไขเมนู "' . $name . '" แล้ว');
        } else {
            $pdo->prepare('INSERT INTO menus (name, menu_group, price, is_active) VALUES (?, ?, ?, ?)')->execute([$name, $group, $price, $active]);
            $newId = (int)$pdo->lastInsertId();
            flash('success', 'เพิ่มเมนู "' . $name . '" แล้ว ต่อไปกำหนดสูตรว่าใช้วัตถุดิบอะไรบ้าง');
            redirect('recipe.php?menu=' . $newId);
        }
    } elseif ($action === 'delete') {
        // ประวัติบิลยังเก็บชื่อเมนูไว้ (order_items.menu_name)
        $pdo->prepare('DELETE FROM menus WHERE id = ?')->execute([$id]);
        flash('success', 'ลบเมนูแล้ว');
    }
    redirect('menus.php');
}

$edit = null;
if (isset($_GET['edit'])) {
    $st = $pdo->prepare('SELECT * FROM menus WHERE id = ?');
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch() ?: null;
}

$rows = $pdo->query('SELECT m.*, COUNT(r.id) AS line_count, COALESCE(SUM(r.quantity * i.cost_per_unit), 0) AS cost
                     FROM menus m LEFT JOIN recipes r ON r.menu_id = m.id LEFT JOIN ingredients i ON i.id = r.ingredient_id
                     GROUP BY m.id ORDER BY m.menu_group, m.name')->fetchAll();
$groups = array_unique(array_merge(['อาหาร', 'เครื่องดื่ม'], array_column($rows, 'menu_group')));
$servings = menu_servings($pdo);

$pageTitle = 'เมนูและสูตรอาหาร';
$activePage = 'menus';
require __DIR__ . '/includes/header.php';
?>
<div class="row g-3">
    <div class="col-xl-4">
        <form class="panel" method="post">
            <h2 class="panel-title"><?= $edit ? 'แก้ไขเมนู' : 'เพิ่มเมนู' ?></h2>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
            <div class="mb-3"><label class="form-label" for="name">ชื่อเมนู</label>
                <input class="form-control" id="name" name="name" value="<?= e($edit['name'] ?? '') ?>" required></div>
            <div class="row g-2 mb-3">
                <div class="col-7"><label class="form-label" for="menu_group">หมวด</label>
                    <input class="form-control" id="menu_group" name="menu_group" list="groupList" value="<?= e($edit['menu_group'] ?? 'อาหาร') ?>">
                    <datalist id="groupList"><?php foreach ($groups as $g): ?><option value="<?= e($g) ?>"><?php endforeach; ?></datalist></div>
                <div class="col-5"><label class="form-label" for="price">ราคา (บาท)</label>
                    <input class="form-control" id="price" name="price" type="number" step="0.01" min="0" value="<?= e($edit['price'] ?? '') ?>" required></div>
            </div>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="is_active" name="is_active" <?= ($edit['is_active'] ?? 1) ? 'checked' : '' ?>>
                <label class="form-check-label" for="is_active">แสดงในหน้าขาย</label>
            </div>
            <button class="btn btn-primary"><?= $edit ? 'บันทึกการแก้ไข' : 'เพิ่มเมนู' ?></button>
            <?php if ($edit): ?><a class="btn btn-link" href="menus.php">ยกเลิก</a><?php endif; ?>
        </form>
    </div>
    <div class="col-xl-8">
        <div class="panel">
            <h2 class="panel-title">เมนูทั้งหมด (<?= count($rows) ?>)</h2>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>เมนู</th><th class="num">ราคา</th><th class="num">ต้นทุน/จาน</th><th class="num">กำไร</th><th class="num">ทำได้อีก</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r):
                        $profit = $r['price'] - $r['cost'];
                        $pct = $r['price'] > 0 ? $profit / $r['price'] * 100 : 0;
                        $sv = $servings[(int)$r['id']] ?? null; ?>
                        <tr class="<?= $r['is_active'] ? '' : 'text-secondary' ?>">
                            <td class="fw-semibold"><?= e($r['name']) ?>
                                <div class="small fw-normal text-secondary"><?= e($r['menu_group']) ?><?= $r['is_active'] ? '' : ' (ปิดขาย)' ?></div></td>
                            <td class="num"><?= money($r['price']) ?></td>
                            <td class="num"><?= $r['line_count'] ? money($r['cost']) : '<span class="status status-warn">ยังไม่มีสูตร</span>' ?></td>
                            <td class="num"><?= $r['line_count'] ? money($profit) . '<div class="small text-secondary">' . number_format($pct, 0) . '%</div>' : '-' ?></td>
                            <td class="num"><?= $sv === null ? '-' : ($sv === 0 ? '<span class="status status-low">หมด</span>' : $sv . ' จาน') ?></td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-primary" href="recipe.php?menu=<?= $r['id'] ?>">สูตร (<?= (int)$r['line_count'] ?>)</a>
                                <a class="btn btn-sm btn-outline-secondary" href="?edit=<?= $r['id'] ?>">แก้ไข</a>
                                <form method="post" class="d-inline" onsubmit="return confirm('ลบเมนู <?= e($r['name']) ?>? ถ้าแค่ไม่อยากขายชั่วคราว ให้แก้ไขแล้วปิดการแสดงแทน')">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger">ลบ</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="small text-secondary mt-3 mb-0">ต้นทุนคำนวณจากราคารับเข้าล่าสุดของวัตถุดิบ "ทำได้อีก" คำนวณจากวัตถุดิบในคลังตอนนี้ เมนูที่ไม่มีสูตร (เช่น น้ำเปล่า) จะไม่ตัดสต็อก</p>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
