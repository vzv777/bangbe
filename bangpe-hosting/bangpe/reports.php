<?php
require_once __DIR__ . '/includes/functions.php';
require_admin();

$tabs = [
    'sales' => 'ยอดขาย',
    'weekly' => 'ยอดขายรายสัปดาห์',
    'stock_in' => 'การรับเข้า',
    'usage' => 'การใช้วัตถุดิบ',
    'balance' => 'ยอดคงเหลือ',
    'movements' => 'ประวัติเคลื่อนไหว',
];
$tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'sales';
// รายงานรายสัปดาห์ตั้งต้นที่ 8 สัปดาห์ล่าสุด ส่วนรายงานอื่นตั้งต้นที่ต้นเดือน
$defaultFrom = $tab === 'weekly' ? date('Y-m-d', strtotime(week_start(date('Y-m-d')) . ' -7 weeks')) : date('Y-m-01');
$from = $_GET['from'] ?? $defaultFrom;
$to = $_GET['to'] ?? date('Y-m-d');
if ($from > $to) [$from, $to] = [$to, $from];
$ingFilter = (int)($_GET['ingredient'] ?? 0);

$pageTitle = 'รายงาน';
$activePage = 'reports';
$pageScript = '';
require __DIR__ . '/includes/header.php';
?>
<ul class="nav nav-tabs mb-3 no-print">
    <?php foreach ($tabs as $k => $label): ?>
        <li class="nav-item"><a class="nav-link <?= $tab === $k ? 'active' : '' ?>" href="?tab=<?= $k ?><?= ($k === 'weekly') === ($tab === 'weekly') ? '&from=' . e($from) . '&to=' . e($to) : '' ?>"><?= e($label) ?></a></li>
    <?php endforeach; ?>
</ul>

<div class="panel">
    <h2 class="panel-title">รายงาน<?= e($tabs[$tab]) ?>
        <?php if ($tab !== 'balance'): ?><span class="small text-secondary fw-normal">ช่วง <?= thai_date($from) ?> ถึง <?= thai_date($to) ?></span><?php endif; ?>
        <button class="btn btn-sm btn-outline-secondary ms-auto" onclick="window.print()"><i class="bi bi-printer"></i> พิมพ์</button>
    </h2>
    <?php if ($tab !== 'balance'): ?>
    <form class="row g-2 mb-3 filter-form" method="get">
        <input type="hidden" name="tab" value="<?= e($tab) ?>">
        <div class="col-sm-3"><input class="form-control" type="date" name="from" value="<?= e($from) ?>" aria-label="ตั้งแต่วันที่"></div>
        <div class="col-sm-3"><input class="form-control" type="date" name="to" value="<?= e($to) ?>" aria-label="ถึงวันที่"></div>
        <?php if ($tab === 'movements'): ?>
        <div class="col-sm-3"><select class="form-select" name="ingredient" aria-label="วัตถุดิบ">
            <option value="">ทุกวัตถุดิบ</option>
            <?php foreach ($pdo->query('SELECT id, name FROM ingredients ORDER BY name') as $i): ?>
                <option value="<?= $i['id'] ?>" <?= $ingFilter === (int)$i['id'] ? 'selected' : '' ?>><?= e($i['name']) ?></option>
            <?php endforeach; ?>
        </select></div>
        <?php endif; ?>
        <div class="col-sm-3"><button class="btn btn-outline-primary w-100">แสดงรายงาน</button></div>
    </form>
    <?php endif; ?>

<?php if ($tab === 'sales'):
    $st = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(total),0) AS total,
                                COALESCE(SUM(CASE WHEN payment_method='cash' THEN total END),0) AS cash,
                                COALESCE(SUM(CASE WHEN payment_method='transfer' THEN total END),0) AS transfer
                         FROM orders WHERE status = 'paid' AND DATE(created_at) BETWEEN ? AND ?");
    $st->execute([$from, $to]);
    $s = $st->fetch();
    $st = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE status = 'void' AND DATE(created_at) BETWEEN ? AND ?");
    $st->execute([$from, $to]);
    $voids = (int)$st->fetchColumn();

    $st = $pdo->prepare("SELECT DATE(created_at) AS d, COUNT(*) AS n, SUM(total) AS total FROM orders
                         WHERE status = 'paid' AND DATE(created_at) BETWEEN ? AND ? GROUP BY DATE(created_at) ORDER BY d");
    $st->execute([$from, $to]);
    $daily = $st->fetchAll();

    // ต้นทุนวัตถุดิบที่ใช้ไปกับการขาย (ประมาณจากต้นทุนต่อหน่วยปัจจุบัน)
    $st = $pdo->prepare("SELECT COALESCE(SUM(-m.qty_change * i.cost_per_unit),0) FROM stock_movements m
                         JOIN ingredients i ON i.id = m.ingredient_id JOIN orders o ON o.id = m.ref_id AND m.ref_type = 'order'
                         WHERE m.type = 'sale' AND o.status = 'paid' AND DATE(o.created_at) BETWEEN ? AND ?");
    $st->execute([$from, $to]);
    $cost = (float)$st->fetchColumn();

    $st = $pdo->prepare("SELECT oi.menu_name, SUM(oi.qty) AS q, SUM(oi.subtotal) AS total FROM order_items oi
                         JOIN orders o ON o.id = oi.order_id
                         WHERE o.status = 'paid' AND DATE(o.created_at) BETWEEN ? AND ?
                         GROUP BY oi.menu_name ORDER BY q DESC");
    $st->execute([$from, $to]);
    $top = $st->fetchAll();
    ?>
    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3"><div class="panel stat tone-saffron mb-0"><span class="stat-icon"><i class="bi bi-cash-stack"></i></span><div><div class="label">ยอดขายรวม</div><div class="value">฿<?= money($s['total']) ?></div><div class="sub"><?= (int)$s['n'] ?> บิล ยกเลิก <?= $voids ?> บิล</div></div></div></div>
        <div class="col-6 col-lg-3"><div class="panel stat tone-violet mb-0"><span class="stat-icon"><i class="bi bi-receipt-cutoff"></i></span><div><div class="label">เฉลี่ยต่อบิล</div><div class="value">฿<?= money($s['n'] ? $s['total'] / $s['n'] : 0) ?></div></div></div></div>
        <div class="col-6 col-lg-3"><div class="panel stat tone-sky mb-0"><span class="stat-icon"><i class="bi bi-wallet-fill"></i></span><div><div class="label">เงินสด</div><div class="value">฿<?= money($s['cash']) ?></div><div class="sub">โอน ฿<?= money($s['transfer']) ?></div></div></div></div>
        <div class="col-6 col-lg-3"><div class="panel stat tone-pandan mb-0"><span class="stat-icon"><i class="bi bi-graph-up-arrow"></i></span><div><div class="label">กำไรขั้นต้นโดยประมาณ</div><div class="value">฿<?= money($s['total'] - $cost) ?></div><div class="sub">ต้นทุนวัตถุดิบ ฿<?= money($cost) ?></div></div></div></div>
    </div>
    <?php if ($daily): ?><div class="mb-4" style="height:260px"><canvas id="dailyChart" aria-label="กราฟยอดขายรายวัน" role="img"></canvas></div><?php endif; ?>
    <div class="row g-4">
        <div class="col-lg-6">
            <h3 class="fs-6 fw-semibold">ยอดขายรายวัน</h3>
            <table class="table">
                <thead><tr><th>วันที่</th><th class="num">บิล</th><th class="num">ยอดขาย</th></tr></thead>
                <tbody>
                <?php foreach ($daily as $d): ?><tr><td><?= thai_date($d['d']) ?></td><td class="num"><?= (int)$d['n'] ?></td><td class="num"><?= money($d['total']) ?></td></tr><?php endforeach; ?>
                <?php if (!$daily): ?><tr><td colspan="3" class="text-secondary">ไม่มีการขายในช่วงนี้</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="col-lg-6">
            <h3 class="fs-6 fw-semibold">เมนูขายดี</h3>
            <table class="table">
                <thead><tr><th>เมนู</th><th class="num">จำนวน</th><th class="num">ยอดขาย</th></tr></thead>
                <tbody>
                <?php foreach ($top as $t): ?><tr><td><?= e($t['menu_name']) ?></td><td class="num"><?= (int)$t['q'] ?></td><td class="num"><?= money($t['total']) ?></td></tr><?php endforeach; ?>
                <?php if (!$top): ?><tr><td colspan="3" class="text-secondary">ไม่มีข้อมูล</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    if ($daily):
        $labels = json_encode(array_map(fn($d) => thai_date($d['d']), $daily), JSON_UNESCAPED_UNICODE);
        $values = json_encode(array_map(fn($d) => (float)$d['total'], $daily));
        $pageScript = <<<JS
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('dailyChart'), {
    type: 'bar',
    data: { labels: {$labels}, datasets: [{ label: 'ยอดขาย (บาท)', data: {$values}, backgroundColor: '#6B46C1', hoverBackgroundColor: '#F5A524', borderRadius: 6, maxBarThickness: 48 }] },
    options: { maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
});
</script>
JS;
    endif;

elseif ($tab === 'weekly'):
    // จัดกลุ่มบิลตามสัปดาห์ (เริ่มวันจันทร์) WEEKDAY() คืน 0 = จันทร์
    $st = $pdo->prepare("SELECT DATE_SUB(DATE(created_at), INTERVAL WEEKDAY(created_at) DAY) AS wk,
                                COUNT(*) AS n, SUM(total) AS total, COUNT(DISTINCT DATE(created_at)) AS days
                         FROM orders WHERE status = 'paid' AND DATE(created_at) BETWEEN ? AND ?
                         GROUP BY wk ORDER BY wk");
    $st->execute([$from, $to]);
    $found = [];
    foreach ($st as $r) $found[$r['wk']] = $r;

    // เมนูขายดีที่สุดของแต่ละสัปดาห์
    $st = $pdo->prepare("SELECT DATE_SUB(DATE(o.created_at), INTERVAL WEEKDAY(o.created_at) DAY) AS wk, oi.menu_name, SUM(oi.qty) AS q
                         FROM order_items oi JOIN orders o ON o.id = oi.order_id
                         WHERE o.status = 'paid' AND DATE(o.created_at) BETWEEN ? AND ?
                         GROUP BY wk, oi.menu_name ORDER BY wk, q DESC");
    $st->execute([$from, $to]);
    $topMenu = [];
    foreach ($st as $r) $topMenu[$r['wk']] ??= $r;

    // ใส่สัปดาห์ที่ไม่มีการขายเป็น 0 ด้วย กราฟจะได้ไม่ขาดช่วง
    $weeks = [];
    $prev = null;
    for ($wk = week_start($from); $wk <= $to; $wk = date('Y-m-d', strtotime("$wk +7 days"))) {
        $total = (float)($found[$wk]['total'] ?? 0);
        $weeks[$wk] = [
            'n' => (int)($found[$wk]['n'] ?? 0),
            'total' => $total,
            'days' => (int)($found[$wk]['days'] ?? 0),
            'change' => $prev !== null && $prev > 0 ? ($total - $prev) / $prev * 100 : null,
            'partial' => false,
            'top' => $topMenu[$wk] ?? null,
        ];
        $prev = $total;
    }
    // สัปดาห์ปัจจุบันยังไม่ครบ 7 วัน จึงเทียบกับ "ช่วงวันเดียวกัน" ของสัปดาห์ก่อนแทน (แบบเดียวกับหน้าหลัก)
    $thisWk = week_start(date('Y-m-d'));
    if (isset($weeks[$thisWk])) {
        $prevWk = week_daily_sales($pdo, date('Y-m-d', strtotime("$thisWk -7 days")));
        $prevSame = array_sum(array_column(array_slice($prevWk, 0, (int)date('N')), 'total'));
        $weeks[$thisWk]['change'] = $prevSame > 0 ? ($weeks[$thisWk]['total'] - $prevSame) / $prevSame * 100 : null;
        $weeks[$thisWk]['partial'] = true;
    }
    $sumAll = array_sum(array_column($weeks, 'total'));
    $bestWk = null;
    foreach ($weeks as $wk => $w) if ($w['total'] > 0 && (!$bestWk || $w['total'] > $weeks[$bestWk]['total'])) $bestWk = $wk;
    ?>
    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3"><div class="panel stat tone-saffron mb-0"><span class="stat-icon"><i class="bi bi-cash-stack"></i></span><div><div class="label">ยอดขายรวม</div><div class="value">฿<?= money($sumAll) ?></div><div class="sub"><?= count($weeks) ?> สัปดาห์</div></div></div></div>
        <div class="col-6 col-lg-3"><div class="panel stat tone-violet mb-0"><span class="stat-icon"><i class="bi bi-calendar-week-fill"></i></span><div><div class="label">เฉลี่ยต่อสัปดาห์</div><div class="value">฿<?= money($weeks ? $sumAll / count($weeks) : 0) ?></div></div></div></div>
        <div class="col-6 col-lg-3"><div class="panel stat tone-pandan mb-0"><span class="stat-icon"><i class="bi bi-trophy-fill"></i></span><div><div class="label">สัปดาห์ที่ขายดีที่สุด</div><div class="value">฿<?= money($bestWk ? $weeks[$bestWk]['total'] : 0) ?></div><div class="sub"><?= $bestWk ? thai_week_range($bestWk) : 'ยังไม่มีการขาย' ?></div></div></div></div>
        <div class="col-6 col-lg-3"><div class="panel stat tone-rose mb-0"><span class="stat-icon"><i class="bi bi-receipt-cutoff"></i></span><div><div class="label">จำนวนบิลรวม</div><div class="value"><?= number_format(array_sum(array_column($weeks, 'n'))) ?></div><div class="sub">บิล</div></div></div></div>
    </div>
    <div class="mb-4" style="height:280px"><canvas id="weeklyChart" aria-label="กราฟยอดขายรายสัปดาห์" role="img"></canvas></div>
    <div class="table-responsive">
    <table class="table">
        <thead><tr><th>สัปดาห์</th><th class="num">บิล</th><th class="num">ยอดขาย</th><th class="num">เฉลี่ยต่อวันที่เปิดขาย</th><th class="num">เทียบสัปดาห์ก่อน</th><th>เมนูขายดี</th></tr></thead>
        <tbody>
        <?php foreach (array_reverse($weeks, true) as $wk => $w): ?>
            <tr>
                <td class="text-nowrap"><?= thai_week_range($wk) ?><?= $wk === $thisWk ? ' <span class="status status-warn">สัปดาห์นี้</span>' : '' ?></td>
                <td class="num"><?= $w['n'] ?></td>
                <td class="num fw-semibold"><?= money($w['total']) ?></td>
                <td class="num"><?= $w['days'] ? money($w['total'] / $w['days']) : '-' ?></td>
                <td class="num">
                    <?php if ($w['change'] === null): ?><span class="text-secondary">-</span>
                    <?php elseif ($w['change'] >= 0): ?><span class="status status-ok"><i class="bi bi-arrow-up"></i> <?= number_format($w['change'], 1) ?>%</span>
                    <?php else: ?><span class="status status-low"><i class="bi bi-arrow-down"></i> <?= number_format(abs($w['change']), 1) ?>%</span><?php endif; ?>
                    <?php if ($w['partial'] && $w['change'] !== null): ?><div class="small text-secondary"><?= date('N') ?> วันแรก</div><?php endif; ?>
                </td>
                <td><?= $w['top'] ? e($w['top']['menu_name']) . ' <span class="text-secondary small">(' . (int)$w['top']['q'] . ' จาน)</span>' : '<span class="text-secondary">-</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><th colspan="2">รวม</th><th class="num">฿<?= money($sumAll) ?></th><th colspan="3"></th></tr></tfoot>
    </table>
    </div>
    <p class="small text-secondary mt-2">สัปดาห์เริ่มวันจันทร์ถึงวันอาทิตย์ สัปดาห์ปัจจุบันยังไม่ครบ 7 วัน จึงเทียบกับวันเดียวกันของสัปดาห์ก่อน (เช่น วันนี้วันพฤหัสฯ ก็เทียบ จันทร์–พฤหัสฯ)</p>
    <?php
    $labels = json_encode(array_map(fn($wk) => thai_short_date($wk), array_keys($weeks)), JSON_UNESCAPED_UNICODE);
    $values = json_encode(array_column($weeks, 'total'));
    $pageScript = <<<JS
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    const spice = ['#F5A524', '#E5484D', '#D6409F', '#6B46C1', '#1D8FD8', '#1F9D63'];
    const values = {$values};
    const baht = v => '฿' + Number(v).toLocaleString('th-TH', {maximumFractionDigits: 0});
    new Chart(document.getElementById('weeklyChart'), {
        type: 'bar',
        data: { labels: {$labels}, datasets: [{ label: 'ยอดขาย', data: values,
            backgroundColor: values.map((_, i) => spice[i % spice.length]), borderRadius: 8, maxBarThickness: 56 }] },
        options: {
            maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { callbacks: { title: c => 'สัปดาห์ที่เริ่ม ' + c[0].label, label: c => baht(c.raw) } } },
            scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { callback: baht } } }
        }
    });
})();
</script>
JS;

elseif ($tab === 'stock_in'):
    $st = $pdo->prepare('SELECT i.name, i.unit, COUNT(*) AS lots, SUM(s.base_qty) AS qty, SUM(s.total_cost) AS cost
                         FROM stock_in s JOIN ingredients i ON i.id = s.ingredient_id
                         WHERE s.received_date BETWEEN ? AND ? GROUP BY i.id ORDER BY cost DESC');
    $st->execute([$from, $to]);
    $rows = $st->fetchAll();
    $st = $pdo->prepare('SELECT sp.name, COUNT(*) AS lots, SUM(s.total_cost) AS cost
                         FROM stock_in s LEFT JOIN suppliers sp ON sp.id = s.supplier_id
                         WHERE s.received_date BETWEEN ? AND ? GROUP BY sp.id, sp.name ORDER BY cost DESC');
    $st->execute([$from, $to]);
    $bySupplier = $st->fetchAll();
    ?>
    <div class="row g-4">
        <div class="col-lg-7">
            <h3 class="fs-6 fw-semibold">แยกตามวัตถุดิบ</h3>
            <table class="table">
                <thead><tr><th>วัตถุดิบ</th><th class="num">ครั้ง</th><th class="num">ปริมาณรวม</th><th class="num">ค่าใช้จ่าย</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?><tr><td><?= e($r['name']) ?></td><td class="num"><?= (int)$r['lots'] ?></td><td class="num"><?= qty($r['qty']) ?> <?= e($r['unit']) ?></td><td class="num"><?= money($r['cost']) ?></td></tr><?php endforeach; ?>
                <?php if (!$rows): ?><tr><td colspan="4" class="text-secondary">ไม่มีการรับเข้าในช่วงนี้</td></tr><?php endif; ?>
                </tbody>
                <?php if ($rows): ?><tfoot><tr><th colspan="3">รวม</th><th class="num">฿<?= money(array_sum(array_column($rows, 'cost'))) ?></th></tr></tfoot><?php endif; ?>
            </table>
        </div>
        <div class="col-lg-5">
            <h3 class="fs-6 fw-semibold">แยกตามผู้จำหน่าย</h3>
            <table class="table">
                <thead><tr><th>ผู้จำหน่าย</th><th class="num">ครั้ง</th><th class="num">ค่าใช้จ่าย</th></tr></thead>
                <tbody>
                <?php foreach ($bySupplier as $r): ?><tr><td><?= e($r['name'] ?? 'ไม่ระบุ') ?></td><td class="num"><?= (int)$r['lots'] ?></td><td class="num"><?= money($r['cost']) ?></td></tr><?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php elseif ($tab === 'usage'):
    $st = $pdo->prepare("SELECT i.name, i.unit, i.cost_per_unit,
                                SUM(CASE WHEN m.type = 'sale' THEN -m.qty_change ELSE 0 END)
                              - SUM(CASE WHEN m.type = 'void' THEN m.qty_change ELSE 0 END) AS sold,
                                SUM(CASE WHEN m.type = 'withdraw' THEN -m.qty_change ELSE 0 END) AS withdrawn,
                                SUM(CASE WHEN m.type = 'adjust' AND m.qty_change < 0 AND (m.reason IS NULL OR m.reason <> 'ยอดยกมา') THEN -m.qty_change ELSE 0 END) AS lost
                         FROM stock_movements m JOIN ingredients i ON i.id = m.ingredient_id
                         WHERE DATE(m.created_at) BETWEEN ? AND ?
                         GROUP BY i.id HAVING sold <> 0 OR withdrawn <> 0 OR lost <> 0 ORDER BY i.name");
    $st->execute([$from, $to]);
    $rows = $st->fetchAll();
    ?>
    <p class="small text-secondary">"ขาย" คือที่ระบบตัดอัตโนมัติจากบิล (หักบิลที่ยกเลิกแล้ว) "เบิก" คือที่บันทึกเอง เช่น ของเสีย "หายจากการนับ" คือส่วนที่นับได้น้อยกว่าในระบบ</p>
    <div class="table-responsive">
    <table class="table">
        <thead><tr><th>วัตถุดิบ</th><th class="num">ขาย</th><th class="num">เบิก / ของเสีย</th><th class="num">หายจากการนับ</th><th class="num">มูลค่ารวม</th></tr></thead>
        <tbody>
        <?php $sum = 0; foreach ($rows as $r): $val = ($r['sold'] + $r['withdrawn'] + $r['lost']) * $r['cost_per_unit']; $sum += $val; ?>
            <tr><td><?= e($r['name']) ?></td>
                <td class="num"><?= qty($r['sold']) ?> <?= e($r['unit']) ?></td>
                <td class="num"><?= qty($r['withdrawn']) ?></td>
                <td class="num <?= $r['lost'] > 0 ? 'text-danger' : '' ?>"><?= qty($r['lost']) ?></td>
                <td class="num"><?= money($val) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="5" class="text-secondary">ไม่มีการใช้วัตถุดิบในช่วงนี้</td></tr><?php endif; ?>
        </tbody>
        <?php if ($rows): ?><tfoot><tr><th colspan="4">รวม</th><th class="num">฿<?= money($sum) ?></th></tr></tfoot><?php endif; ?>
    </table>
    </div>

<?php elseif ($tab === 'balance'):
    $rows = $pdo->query('SELECT i.*, c.name AS category_name FROM ingredients i JOIN categories c ON c.id = i.category_id ORDER BY c.name, i.name')->fetchAll();
    $total = 0;
    ?>
    <p class="small text-secondary">ข้อมูล ณ <?= thai_date(date('Y-m-d H:i:s'), true) ?></p>
    <div class="table-responsive">
    <table class="table">
        <thead><tr><th>ประเภท</th><th>วัตถุดิบ</th><th class="num">คงเหลือ</th><th class="num">ขั้นต่ำ</th><th class="num">มูลค่า</th><th>สถานะ</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $val = $r['quantity'] * $r['cost_per_unit']; $total += $val;
            $low = $r['min_quantity'] > 0 && $r['quantity'] <= $r['min_quantity']; ?>
            <tr><td><?= e($r['category_name']) ?></td><td><?= e($r['name']) ?></td>
                <td class="num"><?= qty($r['quantity']) ?> <?= e($r['unit']) ?></td><td class="num"><?= qty($r['min_quantity']) ?></td>
                <td class="num"><?= money($val) ?></td>
                <td><?= $low ? '<span class="status status-low">ใกล้หมด</span>' : '<span class="status status-ok">พอใช้</span>' ?></td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><th colspan="4">มูลค่าวัตถุดิบในคลังทั้งหมด</th><th class="num">฿<?= money($total) ?></th><th></th></tr></tfoot>
    </table>
    </div>

<?php else:
    $sql = 'SELECT m.*, i.name, i.unit, u.full_name FROM stock_movements m
            JOIN ingredients i ON i.id = m.ingredient_id JOIN users u ON u.id = m.user_id
            WHERE DATE(m.created_at) BETWEEN ? AND ?';
    $params = [$from, $to];
    if ($ingFilter) { $sql .= ' AND m.ingredient_id = ?'; $params[] = $ingFilter; }
    $st = $pdo->prepare($sql . ' ORDER BY m.id DESC LIMIT 500');
    $st->execute($params);
    $rows = $st->fetchAll();
    ?>
    <div class="table-responsive">
    <table class="table table-sm">
        <thead><tr><th>วันเวลา</th><th>วัตถุดิบ</th><th>ประเภท</th><th class="num">เปลี่ยนแปลง</th><th class="num">คงเหลือหลังทำ</th><th>อ้างอิง</th><th>ผู้ทำ</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr><td class="text-nowrap"><?= thai_date($r['created_at'], true) ?></td><td><?= e($r['name']) ?></td>
                <td><?= movement_label($r['type']) ?><?= $r['reason'] ? '<div class="small text-secondary">' . e($r['reason']) . '</div>' : '' ?></td>
                <td class="num <?= $r['qty_change'] < 0 ? 'text-danger' : 'text-success' ?>"><?= $r['qty_change'] > 0 ? '+' : '' ?><?= qty($r['qty_change']) ?> <?= e($r['unit']) ?></td>
                <td class="num"><?= qty($r['balance_after']) ?></td>
                <td><?php if ($r['ref_type'] === 'order'): ?><a href="orders.php?id=<?= $r['ref_id'] ?>"><?= e($r['note']) ?></a><?php else: ?><?= e($r['note']) ?><?php endif; ?></td>
                <td><?= e($r['full_name']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="text-secondary">ไม่มีการเคลื่อนไหวในช่วงนี้</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
    <?php if (count($rows) === 500): ?><p class="small text-secondary">แสดง 500 รายการล่าสุด เลือกช่วงวันที่ให้แคบลงเพื่อดูทั้งหมด</p><?php endif; ?>
<?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
