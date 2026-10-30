<?php
require_once __DIR__ . '/includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'save') {
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        if ($name === '') {
            flash('danger', 'กรุณากรอกชื่อประเภท');
        } else {
            try {
                if ($id) {
                    $pdo->prepare('UPDATE categories SET name = ?, description = ? WHERE id = ?')->execute([$name, $desc ?: null, $id]);
                    flash('success', 'บันทึกการแก้ไขประเภท "' . $name . '" แล้ว');
                } else {
                    $pdo->prepare('INSERT INTO categories (name, description) VALUES (?, ?)')->execute([$name, $desc ?: null]);
                    flash('success', 'เพิ่มประเภท "' . $name . '" แล้ว');
                }
            } catch (PDOException $ex) {
                flash('danger', 'มีประเภทชื่อ "' . $name . '" อยู่แล้ว');
            }
        }
    } elseif ($action === 'delete') {
        try {
            $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
            flash('success', 'ลบประเภทแล้ว');
        } catch (PDOException $ex) {
            flash('danger', 'ลบไม่ได้ เพราะยังมีวัตถุดิบอยู่ในประเภทนี้ ให้ย้ายวัตถุดิบไปประเภทอื่นก่อน');
        }
    }
    redirect('categories.php');
}

$edit = null;
if (isset($_GET['edit'])) {
    $st = $pdo->prepare('SELECT * FROM categories WHERE id = ?');
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch() ?: null;
}

$rows = $pdo->query('SELECT c.*, COUNT(i.id) AS item_count FROM categories c
                     LEFT JOIN ingredients i ON i.category_id = c.id
                     GROUP BY c.id ORDER BY c.name')->fetchAll();

$pageTitle = 'ประเภทวัตถุดิบ';
$activePage = 'categories';
require __DIR__ . '/includes/header.php';
?>
<div class="row g-3">
    <div class="col-lg-4">
        <form class="panel" method="post">
            <h2 class="panel-title"><?= $edit ? 'แก้ไขประเภท' : 'เพิ่มประเภท' ?></h2>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
            <div class="mb-3">
                <label class="form-label" for="name">ชื่อประเภท</label>
                <input class="form-control" id="name" name="name" value="<?= e($edit['name'] ?? '') ?>" placeholder="เช่น เนื้อสัตว์" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="description">คำอธิบาย</label>
                <input class="form-control" id="description" name="description" value="<?= e($edit['description'] ?? '') ?>">
            </div>
            <button class="btn btn-primary"><?= $edit ? 'บันทึกการแก้ไข' : 'เพิ่มประเภท' ?></button>
            <?php if ($edit): ?><a class="btn btn-link" href="categories.php">ยกเลิก</a><?php endif; ?>
        </form>
    </div>
    <div class="col-lg-8">
        <div class="panel">
            <h2 class="panel-title">ประเภททั้งหมด (<?= count($rows) ?>)</h2>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>ชื่อประเภท</th><th>คำอธิบาย</th><th class="num">วัตถุดิบ</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td class="fw-semibold"><?= e($r['name']) ?></td>
                            <td class="text-secondary"><?= e($r['description']) ?></td>
                            <td class="num"><a href="ingredients.php?category=<?= $r['id'] ?>"><?= (int)$r['item_count'] ?> รายการ</a></td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-secondary" href="?edit=<?= $r['id'] ?>">แก้ไข</a>
                                <form method="post" class="d-inline" onsubmit="return confirm('ลบประเภท <?= e($r['name']) ?>?')">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger">ลบ</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
