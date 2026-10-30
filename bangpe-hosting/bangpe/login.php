<?php
require_once __DIR__ . '/includes/functions.php';

if (current_user()) {
    redirect(is_admin() ? 'index.php' : 'pos.php');
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $st = $pdo->prepare('SELECT * FROM users WHERE username = ?');
    $st->execute([$username]);
    $u = $st->fetch();

    if ($u && password_verify($password, $u['password'])) {
        if (!$u['is_active']) {
            $error = 'บัญชีนี้ถูกปิดใช้งาน ติดต่อผู้ดูแลระบบ';
        } else {
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id' => (int)$u['id'],
                'username' => $u['username'],
                'full_name' => $u['full_name'],
                'role' => $u['role'],
            ];
            redirect($u['role'] === 'admin' ? 'index.php' : 'pos.php');
        }
    } else {
        $error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
    }
}
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>เข้าสู่ระบบ | <?= e(APP_NAME) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Anuphan:wght@500;600;700&family=IBM+Plex+Sans+Thai:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/style.css?v=<?= filemtime(__DIR__ . '/assets/style.css') ?>" rel="stylesheet">
</head>
<body>
<div class="login-wrap">
    <form class="login-card" method="post" autocomplete="off">
        <div class="brand">
            <span class="brand-mark"><span>บป</span></span>
            <span><strong><?= e(APP_NAME) ?></strong><small>คลังวัตถุดิบ + ขายหน้าร้าน</small></span>
        </div>
        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= e($error) ?></div>
        <?php endif; ?>
        <?= csrf_field() ?>
        <div class="mb-3">
            <label class="form-label" for="username">ชื่อผู้ใช้</label>
            <input class="form-control form-control-lg" id="username" name="username" value="<?= e($username) ?>" required autofocus>
        </div>
        <div class="mb-4">
            <label class="form-label" for="password">รหัสผ่าน</label>
            <input class="form-control form-control-lg" id="password" type="password" name="password" required>
        </div>
        <button class="btn btn-primary btn-lg w-100" type="submit">เข้าสู่ระบบ</button>
    </form>
</div>
</body>
</html>
