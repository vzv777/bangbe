<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

$st = $pdo->prepare('SELECT o.*, u.full_name FROM orders o JOIN users u ON u.id = o.user_id WHERE o.id = ?');
$st->execute([(int)($_GET['id'] ?? 0)]);
$order = $st->fetch();
if (!$order) exit('ไม่พบบิล');
$st = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ?');
$st->execute([$order['id']]);
$items = $st->fetchAll();
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ใบเสร็จ <?= e($order['order_no']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@400;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/style.css?v=<?= filemtime(__DIR__ . '/assets/style.css') ?>" rel="stylesheet">
    <style>
        /* ขอบกระดาษ 0 = เบราว์เซอร์ไม่มีที่วาง URL/วันที่ ที่หัวและท้ายกระดาษ */
        @page { margin: 0; }
        @media print { .receipt { padding: 6mm 4mm; } }
    </style>
</head>
<body style="background:#fff">
<div class="receipt">
    <h2><?= e(APP_NAME) ?></h2>
    <div class="center">ใบเสร็จรับเงินอย่างย่อ</div>
    <hr>
    <div>เลขที่ <?= e($order['order_no']) ?></div>
    <div>วันที่ <?= thai_date($order['created_at'], true) ?></div>
    <div>พนักงาน <?= e($order['full_name']) ?></div>
    <?php if ($order['status'] === 'void'): ?><div class="center fw-bold">*** บิลนี้ถูกยกเลิก ***</div><?php endif; ?>
    <hr>
    <table>
        <?php foreach ($items as $it): ?>
            <tr><td><?= e($it['menu_name']) ?> x<?= (int)$it['qty'] ?></td><td class="r"><?= money($it['subtotal']) ?></td></tr>
        <?php endforeach; ?>
    </table>
    <hr>
    <table>
        <tr><td><strong>รวมทั้งสิ้น</strong></td><td class="r"><strong><?= money($order['total']) ?></strong></td></tr>
        <?php if ($order['payment_method'] === 'cash'): ?>
            <tr><td>รับเงินสด</td><td class="r"><?= money($order['cash_received']) ?></td></tr>
            <tr><td>เงินทอน</td><td class="r"><?= money($order['change_amount']) ?></td></tr>
        <?php else: ?>
            <tr><td>ชำระโดย</td><td class="r">โอน / พร้อมเพย์</td></tr>
        <?php endif; ?>
    </table>
    <hr>
    <div class="center">ขอบคุณที่อุดหนุนครับ</div>
    <div class="text-center mt-3 no-print">
        <button class="btn btn-primary" onclick="window.print()">พิมพ์</button>
        <button class="btn btn-outline-secondary" onclick="window.close()">ปิด</button>
    </div>
</div>
<?php if (isset($_GET['print'])): ?><script>window.addEventListener('load', () => window.print());</script><?php endif; ?>
</body>
</html>
