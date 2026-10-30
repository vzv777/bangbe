<?php
require_once __DIR__ . '/includes/functions.php';
$me = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'save') {
        $username = trim($_POST['username'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'cashier';
        $active = isset($_POST['is_active']) ? 1 : 0;
        $password = $_POST['password'] ?? '';

        if ($id === $me['id']) {
            // ป้องกันผู้ดูแลล็อกตัวเองออกจากระบบ
            $role = 'admin';
            $active = 1;
        }

        if ($username === '' || $fullName === '' || !preg_match('/^[a-zA-Z0-9_.]{3,50}$/', $username)) {
            flash('danger', 'ชื่อผู้ใช้ต้องเป็นภาษาอังกฤษหรือตัวเลข 3–50 ตัว และต้องกรอกชื่อ-นามสกุล');
        } elseif (!$id && strlen($password) < 6) {
            flash('danger', 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร');
        } elseif ($id && $password !== '' && strlen($password) < 6) {
            flash('danger', 'รหัสผ่านใหม่ต้องมีอย่างน้อย 6 ตัวอักษร');
        } else {
            try {
                if ($id) {
                    $pdo->prepare('UPDATE users SET username = ?, full_name = ?, role = ?, is_active = ? WHERE id = ?')
                        ->execute([$username, $fullName, $role, $active, $id]);
                    if ($password !== '') {
                        $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
                    }
                    flash('success', 'บันทึกข้อมูลผู้ใช้ ' . $username . ' แล้ว');
                } else {
                    $pdo->prepare('INSERT INTO users (username, password, full_name, role, is_active) VALUES (?, ?, ?, ?, ?)')
                        ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $fullName, $role, $active]);
                    flash('success', 'เพิ่มผู้ใช้ ' . $username . ' แล้ว');
                }
                redirect('users.php');
            } catch (PDOException $ex) {
                flash('danger', 'ชื่อผู้ใช้ "' . $username . '" ถูกใช้แล้ว');
            }
        }
        redirect('users.php' . ($id ? '?edit=' . $id : ''));
    } elseif ($action === 'delete') {
        if ($id === $me['id']) {
            flash('danger', 'ลบบัญชีของตัวเองไม่ได้');
        } else {
            try {
                $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
                flash('success', 'ลบผู้ใช้แล้ว');
            } catch (PDOException $ex) {
                flash('danger', 'ผู้ใช้นี้มีประวัติการขายหรือบันทึกสต็อกแล้ว ลบไม่ได้ ให้ปิดใช้งานบัญชีแทน');
            }
        }
        redirect('users.php');
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch() ?: null;
}
$rows = $pdo->query('SELECT * FROM users ORDER BY role, username')->fetchAll();
$isSelf = $edit && (int)$edit['id'] === $me['id'];

$pageTitle = 'ผู้ใช้งาน';
$activePage = 'users';
require __DIR__ . '/includes/header.php';
?>
<div class="row g-3">
    <div class="col-lg-4">
        <form class="panel" method="post" autocomplete="off">
            <h2 class="panel-title"><?= $edit ? 'แก้ไขผู้ใช้' : 'เพิ่มผู้ใช้' ?></h2>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
            <div class="mb-3"><label class="form-label" for="username">ชื่อผู้ใช้ (สำหรับเข้าสู่ระบบ)</label>
                <input class="form-control" id="username" name="username" value="<?= e($edit['username'] ?? '') ?>" required pattern="[a-zA-Z0-9_.]{3,50}"></div>
            <div class="mb-3"><label class="form-label" for="full_name">ชื่อ-นามสกุล</label>
                <input class="form-control" id="full_name" name="full_name" value="<?= e($edit['full_name'] ?? '') ?>" required></div>
            <div class="mb-3"><label class="form-label" for="password"><?= $edit ? 'รหัสผ่านใหม่' : 'รหัสผ่าน' ?></label>
                <input class="form-control" id="password" name="password" type="password" minlength="6" <?= $edit ? '' : 'required' ?> autocomplete="new-password">
                <?php if ($edit): ?><div class="form-text">เว้นว่างไว้ถ้าไม่ต้องการเปลี่ยน</div><?php endif; ?></div>
            <div class="mb-3"><label class="form-label" for="role">สิทธิ์</label>
                <select class="form-select" id="role" name="role" <?= $isSelf ? 'disabled' : '' ?>>
                    <option value="cashier" <?= ($edit['role'] ?? '') === 'cashier' ? 'selected' : '' ?>>แคชเชียร์: ขายหน้าร้าน ดูบิลและสต็อก</option>
                    <option value="admin" <?= ($edit['role'] ?? '') === 'admin' ? 'selected' : '' ?>>ผู้ดูแลระบบ: ใช้ได้ทุกเมนู</option>
                </select></div>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="is_active" name="is_active" <?= ($edit['is_active'] ?? 1) ? 'checked' : '' ?> <?= $isSelf ? 'disabled' : '' ?>>
                <label class="form-check-label" for="is_active">เปิดใช้งานบัญชี</label>
            </div>
            <button class="btn btn-primary"><?= $edit ? 'บันทึกการแก้ไข' : 'เพิ่มผู้ใช้' ?></button>
            <?php if ($edit): ?><a class="btn btn-link" href="users.php">ยกเลิก</a><?php endif; ?>
        </form>
    </div>
    <div class="col-lg-8">
        <div class="panel">
            <h2 class="panel-title">ผู้ใช้ทั้งหมด (<?= count($rows) ?>)</h2>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>ชื่อผู้ใช้</th><th>ชื่อ-นามสกุล</th><th>สิทธิ์</th><th>สถานะ</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td class="fw-semibold"><?= e($r['username']) ?><?= (int)$r['id'] === $me['id'] ? ' <span class="small text-secondary fw-normal">(คุณ)</span>' : '' ?></td>
                            <td><?= e($r['full_name']) ?></td>
                            <td><?= $r['role'] === 'admin' ? 'ผู้ดูแลระบบ' : 'แคชเชียร์' ?></td>
                            <td><?= $r['is_active'] ? '<span class="status status-ok">ใช้งาน</span>' : '<span class="status status-muted">ปิดใช้งาน</span>' ?></td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-secondary" href="?edit=<?= $r['id'] ?>">แก้ไข</a>
                                <?php if ((int)$r['id'] !== $me['id']): ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('ลบผู้ใช้ <?= e($r['username']) ?>?')">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger">ลบ</button>
                                </form>
                                <?php endif; ?>
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
