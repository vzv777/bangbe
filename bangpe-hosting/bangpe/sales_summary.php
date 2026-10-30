<?php
// สรุปยอดขาย วันนี้ / สัปดาห์นี้ / เดือนนี้ พร้อมรายการอาหารที่ขายได้และรายการบิล
// เปิดได้ทั้งผู้ดูแลและแคชเชียร์ (กดมาจากการ์ดในหน้าหลัก)
require_once __DIR__ . '/includes/functions.php';
require_login();

$today = date('Y-m-d');
$periods = [
    'today' => ['วันนี้', $today, $today, 'bi-sun-fill', 'tone-saffron'],
    'week'  => ['สัปดาห์นี้', week_start($today), $today, 'bi-calendar-week-fill', 'tone-pandan'],
    'month' => ['เดือนนี้', date('Y-m-01'), $today, 'bi-calendar3', 'tone-violet'],
];
$period = isset($periods[$_GET['period'] ?? '']) ? $_GET['period'] : 'today';
[$label, $from, $to, $icon, $tone] = $periods[$period];
$rangeText = $from === $to ? thai_date($from) : thai_date($from) . ' ถึง ' . thai_date($to);

// ยอดรวม
$st = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(total),0) AS total FROM orders
                     WHERE status = 'paid' AND DATE(created_at) BETWEEN ? AND ?");
$st->execute([$from, $to]);
$sum = $st->fetch();

// รายการอาหารที่ขายได้ เรียงจากขายมากไปน้อย
$st = $pdo->prepare("SELECT oi.menu_name, COALESCE(m.menu_group, '-') AS menu_group,
                            SUM(oi.qty) AS q, SUM(oi.subtotal) AS total
                     FROM order_items oi JOIN orders o ON o.id = oi.order_id
                     LEFT JOIN menus m ON m.id = oi.menu_id
                     WHERE o.status = 'paid' AND DATE(o.created_at) BETWEEN ? AND ?
                     GROUP BY oi.menu_name, m.menu_group ORDER BY q DESC, total DESC");
$st->execute([$from, $to]);
$items = $st->fetchAll();
$dishes = array_sum(array_column($items, 'q'));
$maxQty = $items ? max(array_column($items, 'q')) : 0;

// รายการบิล (รวมบิลที่ยกเลิก เพื่อให้ตรวจย้อนได้) แสดงล่าสุดก่อน
$billLimit = 300;
$st = $pdo->prepare("SELECT o.id, o.order_no, o.total, o.status, o.payment_method, o.created_at, u.full_name,
                            GROUP_CONCAT(CONCAT(oi.menu_name, ' x', oi.qty) ORDER BY oi.id SEPARATOR ', ') AS item_text
                     FROM orders o JOIN users u ON u.id = o.user_id
                     LEFT JOIN order_items oi ON oi.order_id = o.id
                     WHERE DATE(o.created_at) BETWEEN ? AND ?
                     GROUP BY o.id ORDER BY o.created_at DESC, o.id DESC LIMIT $billLimit");
$st->execute([$from, $to]);
$bills = $st->fetchAll();

$pageTitle = 'ยอดขาย' . $label;
$activePage = 'index';
require __DIR__ . '/includes/header.php';
?>
<ul class="nav nav-tabs mb-3 no-print">
    <?php foreach ($periods as $k => $p): ?>
        <li class="nav-item"><a class="nav-link <?= $period === $k ? 'active' : '' ?>" href="?period=<?= $k ?>">ยอดขาย<?= e($p[0]) ?></a></li>
    <?php endforeach; ?>
</ul>

<div class="row g-3 mb-1">
    <div class="col-6 col-xl-3">
        <div class="panel stat <?= $tone ?> mb-0">
            <span class="stat-icon"><i class="bi <?= $icon ?>"></i></span>
            <div><div class="label">ยอดขาย<?= e($label) ?></div>
            <div class="value">฿<?= money($sum['total']) ?></div>
            <div class="sub"><?= e($rangeText) ?></div></div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="panel stat tone-sky mb-0">
            <span class="stat-icon"><i class="bi bi-receipt-cutoff"></i></span>
            <div><div class="label">จำนวนบิล</div>
            <div class="value"><?= number_format($sum['n']) ?></div>
            <div class="sub">ไม่รวมบิลที่ยกเลิก</div></div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="panel stat tone-rose mb-0">
            <span class="stat-icon"><i class="bi bi-egg-fried"></i></span>
            <div><div class="label">ขายไปทั้งหมด</div>
            <div class="value"><?= number_format($dishes) ?></div>
            <div class="sub">จาน/แก้ว จาก <?= count($items) ?> เมนู</div></div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="panel stat tone-chili mb-0">
            <span class="stat-icon"><i class="bi bi-calculator-fill"></i></span>
            <div><div class="label">เฉลี่ยต่อบิล</div>
            <div class="value">฿<?= money($sum['n'] ? $sum['total'] / $sum['n'] : 0) ?></div>
            <div class="sub">ยอดขาย ÷ จำนวนบิล</div></div>
        </div>
    </div>
</div>

<div class="row g-3 mt-0">
    <div class="col-lg-5">
        <div class="panel tone-saffron">
            <h2 class="panel-title"><i class="bi bi-fire"></i> รายการอาหารที่ขายได้</h2>
            <?php if (!$items): ?>
                <p class="text-secondary mb-0">ยังไม่มีการขาย<?= e($label) ?></p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead><tr><th>เมนู</th><th class="num">จำนวน</th><th class="num">ยอดขาย</th></tr></thead>
                        <tbody>
                        <?php foreach ($items as $i => $it): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="rank <?= $i < 3 ? 'rank-top' : '' ?>"><?= $i + 1 ?></span>
                                        <div class="flex-grow-1">
                                            <div class="fw-semibold"><?= e($it['menu_name']) ?></div>
                                            <div class="qty-bar <?= tone_for($it['menu_group']) ?>"><span style="width: <?= round($it['q'] / $maxQty * 100) ?>%"></span></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="num"><?= (int)$it['q'] ?></td>
                                <td class="num"><?= money($it['total']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot><tr><th>รวม</th><th class="num"><?= number_format($dishes) ?></th><th class="num">฿<?= money(array_sum(array_column($items, 'total'))) ?></th></tr></tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="panel tone-sky">
            <h2 class="panel-title"><i class="bi bi-receipt-cutoff"></i> รายการบิล<?= e($label) ?>
                <span class="ms-auto small text-secondary"><?= count($bills) ?> บิล</span></h2>
            <?php if (!$bills): ?>
                <p class="text-secondary mb-0">ยังไม่มีบิล<?= e($label) ?></p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead><tr><th>บิล</th><th>รายการอาหาร</th><th class="num">ยอด</th></tr></thead>
                        <tbody>
                        <?php foreach ($bills as $b): ?>
                            <tr class="<?= $b['status'] === 'void' ? 'text-secondary' : '' ?>">
                                <td class="text-nowrap">
                                    <a href="orders.php?id=<?= $b['id'] ?>"><?= e($b['order_no']) ?></a>
                                    <div class="small text-secondary"><?= $period === 'today' ? date('H:i', strtotime($b['created_at'])) . ' น.' : thai_date($b['created_at'], true) ?> · <?= e($b['full_name']) ?></div>
                                </td>
                                <td class="small"><?= e($b['item_text'] ?? '-') ?></td>
                                <td class="num">
                                    <?php if ($b['status'] === 'void'): ?>
                                        <span class="status status-muted">ยกเลิก</span>
                                    <?php else: ?>
                                        ฿<?= money($b['total']) ?>
                                        <div class="small text-secondary"><?= $b['payment_method'] === 'cash' ? 'เงินสด' : 'โอน' ?></div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (count($bills) === $billLimit): ?>
                    <p class="small text-secondary mt-2 mb-0">แสดง <?= $billLimit ?> บิลล่าสุด ดูทั้งหมดได้ที่เมนูบิลขาย</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
