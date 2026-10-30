<?php
// ใช้งาน: กำหนด $pageTitle และ $activePage ก่อน include ไฟล์นี้
$user = current_user();
$pageTitle = $pageTitle ?? APP_NAME;
$activePage = $activePage ?? '';

// คำแนะนำวิธีใช้ของหน้านี้ (ดู includes/help.php)
$helpKey = basename($_SERVER['SCRIPT_NAME'], '.php');
$helpFile = __DIR__ . '/help.php';
$help = is_file($helpFile) ? ((require $helpFile)[$helpKey] ?? null) : null;  // ไม่มีไฟล์ก็แค่ไม่แสดงกล่องวิธีใช้

// แต่ละกลุ่มเมนูมีสีประจำตัว (ดู .tone-* ใน assets/style.css)
$navTones = ['ขาย' => 'saffron', 'คลังวัตถุดิบ' => 'pandan', 'ตั้งค่าข้อมูล' => 'sky', 'สรุปผล' => 'rose'];
$navGroups = [
    'ขาย' => [
        ['index', 'index.php', 'bi-grid-1x2-fill', 'หน้าหลัก', false],
        ['pos', 'pos.php', 'bi-cart-fill', 'ขายหน้าร้าน', false],
        ['orders', 'orders.php', 'bi-receipt-cutoff', 'บิลขาย', false],
    ],
    'คลังวัตถุดิบ' => [
        ['ingredients', 'ingredients.php', 'bi-basket2-fill', 'วัตถุดิบคงเหลือ', false],
        ['stock_in', 'stock_in.php', 'bi-box-seam-fill', 'รับเข้า', true],
        ['stock_out', 'stock_out.php', 'bi-trash3-fill', 'เบิกใช้ / ของเสีย', true],
        ['stock_adjust', 'stock_adjust.php', 'bi-clipboard2-check-fill', 'นับสต็อก', true],
        ['expiry', 'expiry.php', 'bi-calendar2-x-fill', 'วันหมดอายุ', true],
    ],
    'ตั้งค่าข้อมูล' => [
        ['menus', 'menus.php', 'bi-journal-bookmark-fill', 'เมนูและสูตร', true],
        ['categories', 'categories.php', 'bi-tags-fill', 'ประเภทวัตถุดิบ', true],
        ['suppliers', 'suppliers.php', 'bi-truck-front-fill', 'ผู้จำหน่าย', true],
        ['users', 'users.php', 'bi-people-fill', 'ผู้ใช้งาน', true],
    ],
    'สรุปผล' => [
        ['reports', 'reports.php', 'bi-bar-chart-line-fill', 'รายงาน', true],
    ],
];

function render_nav(array $groups, array $tones, string $active): void
{
    foreach ($groups as $label => $items) {
        $visible = array_filter($items, fn($i) => !$i[4] || is_admin());
        if (!$visible) continue;
        $tone = ' tone-' . ($tones[$label] ?? 'violet');
        echo '<div class="nav-group' . $tone . '">' . e($label) . '</div>';
        foreach ($visible as [$key, $href, $icon, $text]) {
            $cls = $tone . ($key === $active ? ' active' : '');
            $aria = $key === $active ? ' aria-current="page"' : '';
            echo '<a class="side-link' . $cls . '" href="' . $href . '"' . $aria . '><i class="bi ' . $icon . '"></i><span>' . e($text) . '</span></a>';
        }
    }
}
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | <?= e(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Anuphan:wght@500;600;700&family=IBM+Plex+Sans+Thai:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/style.css?v=<?= filemtime(__DIR__ . '/../assets/style.css') ?>" rel="stylesheet">
</head>
<body>
<div class="app">
    <aside class="sidebar d-none d-lg-flex">
        <a class="brand" href="index.php">
            <span class="brand-mark"><span>บป</span></span>
            <span><strong><?= e(APP_NAME) ?></strong><small>คลังวัตถุดิบ + ขายหน้าร้าน</small></span>
        </a>
        <nav class="side-nav"><?php render_nav($navGroups, $navTones, $activePage); ?></nav>
    </aside>

    <div class="offcanvas offcanvas-start sidebar-mobile" tabindex="-1" id="mobileNav" aria-labelledby="mobileNavLabel">
        <div class="offcanvas-header">
            <span class="brand" id="mobileNavLabel"><span class="brand-mark"><span>บป</span></span><strong><?= e(APP_NAME) ?></strong></span>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="ปิดเมนู"></button>
        </div>
        <div class="offcanvas-body"><nav class="side-nav"><?php render_nav($navGroups, $navTones, $activePage); ?></nav></div>
    </div>

    <div class="main">
        <header class="topbar">
            <button class="btn btn-icon d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileNav" aria-label="เปิดเมนู">
                <i class="bi bi-list"></i>
            </button>
            <h1 class="page-title"><?= e($pageTitle) ?></h1>
            <div class="topbar-right">
                <?php if ($help): ?>
                <button class="btn btn-icon help-toggle" type="button" id="helpToggle" aria-controls="pageHelp" aria-expanded="true" title="วิธีใช้หน้านี้">
                    <i class="bi bi-question-circle-fill"></i><span class="d-none d-md-inline">วิธีใช้</span>
                </button>
                <?php endif; ?>
                <div class="dropdown">
                    <button class="btn btn-icon position-relative" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-label="การแจ้งเตือน">
                        <i class="bi bi-bell-fill"></i>
                        <span id="notifyBadge" class="notify-badge d-none">0</span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end notify-menu" id="notifyMenu">
                        <div class="notify-empty">กำลังโหลด…</div>
                    </div>
                </div>
                <div class="dropdown">
                    <button class="btn user-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="avatar" aria-hidden="true"><i class="bi bi-person-fill"></i></span>
                        <span class="d-none d-sm-inline"><?= e($user['full_name']) ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><span class="dropdown-item-text small text-secondary">
                            <?= e($user['username']) ?> · <?= $user['role'] === 'admin' ? 'ผู้ดูแลระบบ' : 'แคชเชียร์' ?>
                        </span></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>ออกจากระบบ</a></li>
                    </ul>
                </div>
            </div>
        </header>

        <main class="content">
            <?php foreach (get_flashes() as $f): ?>
                <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show" role="alert">
                    <?= e($f['msg']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="ปิด"></button>
                </div>
            <?php endforeach; ?>
            <?php if ($help): ?>
            <section class="help-strip" id="pageHelp" data-page="<?= e($helpKey) ?>" aria-label="วิธีใช้หน้านี้">
                <div class="help-icon"><i class="bi bi-lightbulb-fill"></i></div>
                <div class="help-body">
                    <div class="help-what"><?= e($help['what']) ?></div>
                    <ol class="help-steps">
                        <?php foreach ($help['steps'] as $step): ?><li><?= e($step) ?></li><?php endforeach; ?>
                    </ol>
                    <?php if (!empty($help['tip'])): ?><div class="help-tip"><i class="bi bi-info-circle-fill"></i> <?= e($help['tip']) ?></div><?php endif; ?>
                </div>
                <button type="button" class="btn btn-sm help-close" id="helpClose">เข้าใจแล้ว ซ่อน</button>
            </section>
            <?php endif; ?>
