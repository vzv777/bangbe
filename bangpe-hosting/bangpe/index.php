<?php
require_once __DIR__ . '/includes/functions.php';
$user = require_login();

$today = $pdo->query("SELECT COUNT(*) AS n, COALESCE(SUM(total),0) AS total
                      FROM orders WHERE status = 'paid' AND DATE(created_at) = CURDATE()")->fetch();
$month = $pdo->query("SELECT COALESCE(SUM(total),0) AS total FROM orders
                      WHERE status = 'paid' AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetchColumn();
$notify = notifications_data($pdo);

$recent = $pdo->query("SELECT o.*, u.full_name FROM orders o JOIN users u ON u.id = o.user_id
                       ORDER BY o.id DESC LIMIT 8")->fetchAll();

$topToday = $pdo->query("SELECT oi.menu_name, SUM(oi.qty) AS q FROM order_items oi
                         JOIN orders o ON o.id = oi.order_id
                         WHERE o.status = 'paid' AND DATE(o.created_at) = CURDATE()
                         GROUP BY oi.menu_name ORDER BY q DESC LIMIT 5")->fetchAll();

// ---------- ยอดขายรายสัปดาห์ (แสดงเป็นการ์ดเล็ก) ----------
$todayDate = date('Y-m-d');
$monday = week_start($todayDate);
$thisWeek = week_daily_sales($pdo, $monday);
$lastWeek = week_daily_sales($pdo, date('Y-m-d', strtotime($monday . ' -7 days')));
$daysPassed = (int)date('N');                       // ผ่านมากี่วันแล้วในสัปดาห์นี้ (จันทร์ = 1)
$weekTotal = array_sum(array_column($thisWeek, 'total'));
$weekBills = array_sum(array_column($thisWeek, 'n'));
// เทียบกับ "ช่วงเดียวกัน" ของสัปดาห์ก่อน เช่น วันนี้วันพุธ ก็เทียบ จันทร์–พุธ ของสัปดาห์ก่อน
$lastSame = array_sum(array_column(array_slice($lastWeek, 0, $daysPassed), 'total'));
$weekChange = $lastSame > 0 ? ($weekTotal - $lastSame) / $lastSame * 100 : null;

// ---------- ยอดขายวันนี้ (แสดงเป็นแถบใหญ่) แยกรายชั่วโมง เทียบกับเมื่อวาน ----------
$yesterday = date('Y-m-d', strtotime('-1 day'));
$st = $pdo->prepare("SELECT DATE(created_at) AS d, HOUR(created_at) AS h, COUNT(*) AS n, SUM(total) AS total
                     FROM orders WHERE status = 'paid' AND DATE(created_at) IN (?, ?)
                     GROUP BY DATE(created_at), HOUR(created_at)");
$st->execute([$todayDate, $yesterday]);
$hourly = [$todayDate => [], $yesterday => []];
foreach ($st as $r) $hourly[$r['d']][(int)$r['h']] = (float)$r['total'];

// ช่วงเวลาบนกราฟ: ตั้งต้น 9:00–21:00 และขยายเองถ้ามีบิลนอกช่วงนี้
$hoursUsed = array_merge(array_keys($hourly[$todayDate]), array_keys($hourly[$yesterday]));
$hFrom = min(array_merge([9], $hoursUsed));
$hTo = max(array_merge([21], $hoursUsed));
$nowHour = (int)date('G');

// เทียบกับ "เมื่อวาน ณ เวลาเดียวกัน" เช่น ตอนนี้ 14:20 ก็เทียบยอดเมื่อวานถึง 14:20 จะได้ยุติธรรม
$st = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM orders WHERE status = 'paid'
                     AND created_at >= ? AND created_at <= ?");
$st->execute([$yesterday, $yesterday . ' ' . date('H:i:s')]);
$yesterdaySame = (float)$st->fetchColumn();
$dayChange = $yesterdaySame > 0 ? ($today['total'] - $yesterdaySame) / $yesterdaySame * 100 : null;
$yesterdayHadSales = !empty($hourly[$yesterday]);

$peakHour = null;
foreach ($hourly[$todayDate] as $h => $v) {
    if ($peakHour === null || $v > $hourly[$todayDate][$peakHour]) $peakHour = $h;
}
$dayNames = ['จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์', 'อาทิตย์'];

$hourLabels = $todayValues = $yesterdayValues = [];
for ($h = $hFrom; $h <= $hTo; $h++) {
    $hourLabels[] = sprintf('%02d:00', $h);
    $todayValues[] = $h > $nowHour ? null : ($hourly[$todayDate][$h] ?? 0);
    $yesterdayValues[] = $hourly[$yesterday][$h] ?? 0;
}
$hourLabels = json_encode($hourLabels);
$todayValues = json_encode($todayValues);
$yesterdayValues = json_encode($yesterdayValues);
$pageScript = <<<JS
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    const spice = ['#F5A524', '#E5484D', '#D6409F', '#6B46C1', '#1D8FD8', '#1F9D63'];
    const baht = v => '฿' + Number(v).toLocaleString('th-TH', {maximumFractionDigits: 0});
    const labels = {$hourLabels};
    const canvas = document.getElementById('dayChart');
    new Chart(canvas, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                { label: 'วันนี้', data: {$todayValues}, backgroundColor: labels.map((_, i) => spice[i % spice.length]), borderRadius: 6, maxBarThickness: 28 },
                { label: 'เมื่อวาน', data: {$yesterdayValues}, backgroundColor: 'rgba(255,255,255,.18)', borderRadius: 6, maxBarThickness: 28 }
            ]
        },
        options: {
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { title: c => 'เวลา ' + c[0].label + ' น.', label: c => c.dataset.label + ' ' + baht(c.raw ?? 0) } }
            },
            scales: {
                x: { ticks: { color: 'rgba(255,255,255,.7)', maxRotation: 0, autoSkip: true, callback(v) { const t = this.getLabelForValue(v); return canvas.clientWidth < 520 ? t.slice(0, 2) : t; } }, grid: { display: false }, border: { display: false } },
                y: { beginAtZero: true, ticks: { color: 'rgba(255,255,255,.5)', callback: baht, maxTicksLimit: 5 }, grid: { color: 'rgba(255,255,255,.08)' }, border: { display: false } }
            }
        }
    });
})();
</script>
JS;

// ปุ่มทางลัด: งานที่ทำบ่อยของแต่ละสิทธิ์ [ลิงก์, ไอคอน, ชื่อ, คำอธิบาย, สี]
$shortcuts = is_admin() ? [
    ['pos.php', 'bi-cart-fill', 'ขายหน้าร้าน', 'เปิดบิลขายใหม่', 'tone-saffron'],
    ['stock_in.php', 'bi-box-seam-fill', 'รับของเข้า', 'ซื้อของมาเติมคลัง', 'tone-pandan'],
    ['stock_out.php', 'bi-trash3-fill', 'บันทึกของเสีย', 'ของเสีย หมดอายุ ทำหก', 'tone-chili'],
    ['stock_adjust.php', 'bi-clipboard2-check-fill', 'นับสต็อก', 'ปรับยอดให้ตรงของจริง', 'tone-sky'],
] : [
    ['pos.php', 'bi-cart-fill', 'ขายหน้าร้าน', 'เปิดบิลขายใหม่', 'tone-saffron'],
    ['orders.php', 'bi-receipt-cutoff', 'บิลขาย', 'ดูบิล พิมพ์ใบเสร็จซ้ำ', 'tone-sky'],
    ['sales_summary.php?period=today', 'bi-fire', 'ขายอะไรไปบ้าง', 'สรุปเมนูที่ขายวันนี้', 'tone-rose'],
    ['ingredients.php', 'bi-basket2-fill', 'วัตถุดิบคงเหลือ', 'ดูของที่เหลือในคลัง', 'tone-pandan'],
];

$pageTitle = 'หน้าหลัก';
$activePage = 'index';
require __DIR__ . '/includes/header.php';
?>

<nav class="shortcuts" aria-label="ทางลัด">
    <?php foreach ($shortcuts as [$href, $icon, $name, $desc, $tone]): ?>
        <a class="shortcut <?= $tone ?>" href="<?= $href ?>">
            <span class="stat-icon"><i class="bi <?= $icon ?>"></i></span>
            <span><strong><?= e($name) ?></strong><small><?= e($desc) ?></small></span>
        </a>
    <?php endforeach; ?>
</nav>

<section class="panel week-hero" aria-label="ยอดขายวันนี้">
    <div>
        <div class="eyebrow">ยอดขายวันนี้ (วัน<?= $dayNames[date('N') - 1] ?>ที่ <?= thai_short_date($todayDate) ?> <?= date('Y') + 543 ?>)</div>
        <div class="big">฿<?= money($today['total']) ?></div>
        <?php if ($dayChange === null): ?>
            <span class="trend flat"><?= $yesterdayHadSales ? 'เมื่อวานช่วงเวลานี้ยังไม่มียอดขาย' : 'ยังไม่มียอดเมื่อวานให้เทียบ' ?></span>
        <?php elseif ($dayChange >= 0): ?>
            <span class="trend up"><i class="bi bi-arrow-up-right"></i> เพิ่มขึ้น <?= number_format($dayChange, 1) ?>%</span>
        <?php else: ?>
            <span class="trend down"><i class="bi bi-arrow-down-right"></i> ลดลง <?= number_format(abs($dayChange), 1) ?>%</span>
        <?php endif; ?>
        <?php if ($dayChange !== null): ?><div class="small mt-2" style="color:rgba(255,255,255,.6)">เทียบกับเมื่อวานถึงเวลา <?= date('H:i') ?> น. (฿<?= money($yesterdaySame) ?>)</div><?php endif; ?>
        <div class="week-facts">
            <div><small>จำนวนบิล</small><strong><?= (int)$today['n'] ?></strong></div>
            <div><small>เฉลี่ยต่อบิล</small><strong>฿<?= number_format($today['n'] ? $today['total'] / $today['n'] : 0) ?></strong></div>
            <div><small>ช่วงขายดี</small><strong><?= $peakHour !== null ? sprintf('%02d:00', $peakHour) . ' น.' : '-' ?></strong></div>
        </div>
    </div>
    <div class="d-flex flex-column">
        <div class="week-legend"><span><i style="background:var(--saffron)"></i>วันนี้</span><span><i style="background:rgba(255,255,255,.25)"></i>เมื่อวาน</span>
            <a class="see-more ms-2" href="sales_summary.php?period=today">ดูรายการอาหารวันนี้ <i class="bi bi-chevron-right"></i></a></div>
        <div class="week-chart flex-grow-1"><canvas id="dayChart" role="img" aria-label="กราฟยอดขายรายชั่วโมงของวันนี้เทียบเมื่อวาน"></canvas></div>
    </div>
</section>

<div class="row g-3 mb-1">
    <div class="col-6 col-xl-3">
        <a class="panel stat tone-saffron mb-0" href="sales_summary.php?period=week">
            <span class="stat-icon"><i class="bi bi-calendar-week-fill"></i></span>
            <div><div class="label">ยอดขายสัปดาห์นี้</div>
            <div class="value">฿<?= money($weekTotal) ?></div>
            <div class="sub"><?= $weekBills ?> บิล
                <?php if ($weekChange !== null): ?>
                    <span class="<?= $weekChange >= 0 ? 'text-success' : 'text-danger' ?> fw-semibold"><?= $weekChange >= 0 ? '▲' : '▼' ?> <?= number_format(abs($weekChange), 1) ?>%</span>
                <?php endif; ?>
            </div></div>
        </a>
    </div>
    <div class="col-6 col-xl-3">
        <a class="panel stat tone-violet mb-0" href="sales_summary.php?period=month">
            <span class="stat-icon"><i class="bi bi-calendar3"></i></span>
            <div><div class="label">ยอดขายเดือนนี้</div>
            <div class="value">฿<?= money($month) ?></div>
            <div class="sub">ตั้งแต่วันที่ 1</div></div>
        </a>
    </div>
    <div class="col-6 col-xl-3">
        <a class="panel stat tone-chili mb-0 <?= $notify['low'] ? 'is-alert' : '' ?>" href="ingredients.php?filter=low">
            <span class="stat-icon"><i class="bi bi-exclamation-triangle-fill"></i></span>
            <div><div class="label">วัตถุดิบใกล้หมด</div>
            <div class="value"><?= count($notify['low']) ?></div>
            <div class="sub">รายการ ต่ำกว่าขั้นต่ำ</div></div>
        </a>
    </div>
    <div class="col-6 col-xl-3">
        <a class="panel stat tone-rose mb-0 <?= $notify['expiry'] ? 'is-alert' : '' ?>" href="<?= is_admin() ? 'expiry.php' : 'ingredients.php' ?>">
            <span class="stat-icon"><i class="bi bi-hourglass-split"></i></span>
            <div><div class="label">ใกล้หมดอายุ</div>
            <div class="value"><?= count($notify['expiry']) ?></div>
            <div class="sub">ล็อต ภายใน <?= EXPIRY_ALERT_DAYS ?> วัน</div></div>
        </a>
    </div>
</div>

<div class="row g-3 mt-0">
    <div class="col-lg-7">
        <div class="panel tone-chili">
            <h2 class="panel-title"><i class="bi bi-cart-plus-fill"></i> วัตถุดิบที่ต้องสั่งเพิ่ม
                <?php if (is_admin()): ?><a class="ms-auto small" href="stock_in.php">บันทึกรับเข้า</a><?php endif; ?>
            </h2>
            <?php if (!$notify['low']): ?>
                <p class="text-secondary mb-0">วัตถุดิบทุกรายการยังมากกว่าปริมาณขั้นต่ำ</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead><tr><th>วัตถุดิบ</th><th class="num">คงเหลือ</th><th class="num">ขั้นต่ำ</th></tr></thead>
                        <tbody>
                        <?php foreach ($notify['low'] as $i): ?>
                            <tr>
                                <td><?= e($i['name']) ?></td>
                                <td class="num text-danger fw-semibold"><?= qty($i['quantity']) ?> <?= e($i['unit']) ?></td>
                                <td class="num"><?= qty($i['min_quantity']) ?> <?= e($i['unit']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($notify['expiry']): ?>
        <div class="panel tone-rose">
            <h2 class="panel-title"><i class="bi bi-calendar2-x-fill"></i> ล็อตที่ใกล้หมดอายุ</h2>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>วัตถุดิบ</th><th>วันหมดอายุ</th><th>สถานะ</th></tr></thead>
                    <tbody>
                    <?php foreach ($notify['expiry'] as $x): ?>
                        <tr>
                            <td><?= e($x['name']) ?></td>
                            <td><?= thai_date($x['expiry_date']) ?></td>
                            <td><span class="status <?= $x['days_left'] < 0 ? 'status-low' : 'status-warn' ?>"><?= expiry_text((int)$x['days_left']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-5">
        <div class="panel tone-saffron">
            <h2 class="panel-title"><i class="bi bi-fire"></i> ขายดีวันนี้ <a class="ms-auto small" href="sales_summary.php?period=today">ดูทั้งหมด</a></h2>
            <?php if (!$topToday): ?>
                <p class="text-secondary mb-0">ยังไม่มีการขายวันนี้ <a href="pos.php">เปิดหน้าขาย</a></p>
            <?php else: ?>
                <table class="table">
                    <tbody>
                    <?php foreach ($topToday as $t): ?>
                        <tr><td><?= e($t['menu_name']) ?></td><td class="num"><?= (int)$t['q'] ?> จาน</td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="panel tone-sky">
            <h2 class="panel-title"><i class="bi bi-receipt-cutoff"></i> บิลล่าสุด <a class="ms-auto small" href="orders.php">ดูทั้งหมด</a></h2>
            <?php if (!$recent): ?>
                <p class="text-secondary mb-0">ยังไม่มีบิลขาย</p>
            <?php else: ?>
                <table class="table">
                    <tbody>
                    <?php foreach ($recent as $o): ?>
                        <tr>
                            <td><a href="orders.php?id=<?= $o['id'] ?>"><?= e($o['order_no']) ?></a>
                                <div class="small text-secondary"><?= thai_date($o['created_at'], true) ?></div></td>
                            <td class="num">
                                <?php if ($o['status'] === 'void'): ?>
                                    <span class="status status-muted">ยกเลิก</span>
                                <?php else: ?>
                                    ฿<?= money($o['total']) ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
