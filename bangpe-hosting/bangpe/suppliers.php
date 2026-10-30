<?php
require_once __DIR__ . '/includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'save') {
        $f = [
            trim($_POST['name'] ?? ''),
            trim($_POST['contact_name'] ?? '') ?: null,
            trim($_POST['phone'] ?? '') ?: null,
            trim($_POST['address'] ?? '') ?: null,
        ];
        if ($f[0] === '') {
            flash('danger', 'กรุณากรอกชื่อผู้จำหน่าย');
        } elseif ($id) {
            $pdo->prepare('UPDATE suppliers SET name = ?, contact_name = ?, phone = ?, address = ? WHERE id = ?')->execute([...$f, $id]);
            flash('success', 'บันทึกการแก้ไขผู้จำหน่ายแล้ว');
        } else {
            $pdo->prepare('INSERT INTO suppliers (name, contact_name, phone, address) VALUES (?, ?, ?, ?)')->execute($f);
            flash('success', 'เพิ่มผู้จำหน่าย "' . $f[0] . '" แล้ว');
        }
    } elseif ($action === 'delete') {
        // ประวัติรับเข้าจะยังอยู่ แต่ช่องผู้จำหน่ายจะว่าง (ON DELETE SET NULL)
        $pdo->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$id]);
        flash('success', 'ลบผู้จำหน่ายแล้ว');
    }
    redirect('suppliers.php');
}

$edit = null;
if (isset($_GET['edit'])) {
    $st = $pdo->prepare('SELECT * FROM suppliers WHERE id = ?');
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch() ?: null;
}

$q = trim($_GET['q'] ?? '');
$st = $pdo->prepare('SELECT s.*, COUNT(si.id) AS lots, MAX(si.received_date) AS last_date
                     FROM suppliers s LEFT JOIN stock_in si ON si.supplier_id = s.id
                     WHERE s.name LIKE ? OR s.phone LIKE ?
                     GROUP BY s.id ORDER BY s.name');
$st->execute(["%$q%", "%$q%"]);
$rows = $st->fetchAll();

$pageTitle = 'ผู้จำหน่าย';
$activePage = 'suppliers';
require __DIR__ . '/includes/header.php';
?>
<div class="row g-3">
    <div class="col-lg-4">
        <form class="panel" method="post">
            <h2 class="panel-title"><?= $edit ? 'แก้ไขผู้จำหน่าย' : 'เพิ่มผู้จำหน่าย' ?></h2>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
            <div class="mb-3"><label class="form-label" for="name">ชื่อร้าน / บริษัท</label>
                <input class="form-control" id="name" name="name" value="<?= e($edit['name'] ?? '') ?>" required></div>
            <div class="mb-3"><label class="form-label" for="contact_name">ชื่อผู้ติดต่อ</label>
                <input class="form-control" id="contact_name" name="contact_name" value="<?= e($edit['contact_name'] ?? '') ?>"></div>
            <div class="mb-3"><label class="form-label" for="phone">เบอร์โทร</label>
                <input class="form-control" id="phone" name="phone" type="tel" value="<?= e($edit['phone'] ?? '') ?>"></div>
            <div class="mb-3"><label class="form-label" for="address">ที่อยู่</label>
                <textarea class="form-control" id="address" name="address" rows="2"><?= e($edit['address'] ?? '') ?></textarea></div>
            <button class="btn btn-primary"><?= $edit ? 'บันทึกการแก้ไข' : 'เพิ่มผู้จำหน่าย' ?></button>
            <?php if ($edit): ?><a class="btn btn-link" href="suppliers.php">ยกเลิก</a><?php endif; ?>
        </form>
    </div>
    <div class="col-lg-8">
        <div class="panel">
            <h2 class="panel-title">ผู้จำหน่ายทั้งหมด (<?= count($rows) ?>)
                <form class="ms-auto filter-form" method="get"><input class="form-control form-control-sm" name="q" value="<?= e($q) ?>" placeholder="ค้นหาชื่อ / เบอร์โทร"></form>
            </h2>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>ชื่อ</th><th>ผู้ติดต่อ</th><th>เบอร์โทร</th><th>รับเข้าล่าสุด</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td class="fw-semibold"><?= e($r['name']) ?><div class="small text-secondary fw-normal"><?= e($r['address']) ?></div></td>
                            <td><?= e($r['contact_name']) ?></td>
                            <td><?php if ($r['phone']): ?><a href="tel:<?= e($r['phone']) ?>"><?= e($r['phone']) ?></a><?php endif; ?></td>
                            <td><?= $r['last_date'] ? thai_date($r['last_date']) . ' (' . (int)$r['lots'] . ' ครั้ง)' : '-' ?></td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-secondary" href="?edit=<?= $r['id'] ?>">แก้ไข</a>
                                <form method="post" class="d-inline" onsubmit="return confirm('ลบผู้จำหน่ายนี้? ประวัติรับเข้าเดิมจะยังอยู่')">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger">ลบ</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?><tr><td colspan="5" class="text-secondary">ไม่พบผู้จำหน่าย</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
