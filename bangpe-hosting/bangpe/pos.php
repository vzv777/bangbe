<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

$menus = $pdo->query('SELECT id, name, menu_group, price FROM menus WHERE is_active = 1 ORDER BY menu_group, name')->fetchAll();
$servings = menu_servings($pdo);
$groups = array_values(array_unique(array_column($menus, 'menu_group')));

$pageTitle = 'ขายหน้าร้าน';
$activePage = 'pos';
require __DIR__ . '/includes/header.php';
?>
<div class="pos-page">
<div class="pos">
    <section aria-label="เมนู">
        <div class="pos-filters">
            <input class="form-control" id="menuSearch" type="search" placeholder="ค้นหาเมนู" aria-label="ค้นหาเมนู">
            <button class="group-btn active" data-group="">ทั้งหมด</button>
            <?php foreach ($groups as $g): ?><button class="group-btn <?= tone_for($g) ?>" data-group="<?= e($g) ?>"><?= e($g) ?></button><?php endforeach; ?>
        </div>
        <?php if (!$menus): ?>
            <div class="panel text-secondary">ยังไม่มีเมนูที่เปิดขาย <?php if (is_admin()): ?><a href="menus.php">เพิ่มเมนู</a><?php endif; ?></div>
        <?php endif; ?>
        <div class="menu-grid" id="menuGrid">
            <?php foreach ($menus as $m):
                $sv = $servings[(int)$m['id']] ?? null; ?>
                <button type="button" class="menu-tile <?= tone_for($m['menu_group'] ?? '') ?>" data-id="<?= $m['id'] ?>" data-name="<?= e($m['name']) ?>"
                        data-price="<?= e($m['price']) ?>" data-group="<?= e($m['menu_group']) ?>"
                        data-servings="<?= $sv === null ? '' : $sv ?>" <?= $sv === 0 ? 'disabled' : '' ?>>
                    <span class="name"><?= e($m['name']) ?></span>
                    <span>
                        <span class="price">฿<?= qty($m['price']) ?></span>
                        <span class="left d-block <?= $sv !== null && $sv <= 5 ? 'is-low' : '' ?>">
                            <?= $sv === null ? '&nbsp;' : ($sv === 0 ? 'วัตถุดิบไม่พอ' : 'ทำได้อีก ' . $sv . ' จาน') ?>
                        </span>
                    </span>
                </button>
            <?php endforeach; ?>
        </div>
    </section>

    <aside class="panel cart" id="cart" aria-label="รายการสั่ง">
        <div class="cart-head"><strong>รายการสั่ง</strong>
            <button class="btn btn-sm btn-link text-danger ms-auto" id="clearCart" type="button">ล้างทั้งหมด</button></div>
        <div class="cart-items" id="cartItems"></div>
        <div class="cart-foot">
            <div class="cart-total"><span>ยอดรวม</span><strong id="cartTotal">฿0.00</strong></div>
            <button class="btn btn-accent btn-lg w-100" id="payBtn" type="button" disabled>ชำระเงิน</button>
        </div>
    </aside>
</div>
</div>

<div class="mobile-cart-bar" id="mobileBar">
    <span><span id="mobileCount">0</span> รายการ</span>
    <strong class="ms-auto" id="mobileTotal">฿0.00</strong>
    <a class="btn btn-accent" href="#cart">ดูรายการ</a>
</div>

<div class="modal fade" id="payModal" tabindex="-1" aria-labelledby="payTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="payTitle">ชำระเงิน</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body" id="payForm">
                <div class="cart-total"><span>ยอดที่ต้องชำระ</span><strong id="payTotal">฿0.00</strong></div>
                <div class="d-flex gap-2 pay-methods mb-3" role="group" aria-label="วิธีชำระเงิน">
                    <input type="radio" class="btn-check" name="method" id="mCash" value="cash" checked>
                    <label class="btn btn-outline-primary" for="mCash"><i class="bi bi-cash-coin"></i> เงินสด</label>
                    <input type="radio" class="btn-check" name="method" id="mTransfer" value="transfer">
                    <label class="btn btn-outline-primary" for="mTransfer"><i class="bi bi-qr-code"></i> โอน / พร้อมเพย์</label>
                </div>
                <div id="cashSection">
                    <label class="form-label" for="cashIn">รับเงินมา (บาท)</label>
                    <input class="form-control form-control-lg mb-2" id="cashIn" type="number" min="0" step="1" inputmode="decimal">
                    <div class="cash-quick mb-3">
                        <button type="button" class="btn btn-outline-secondary" data-cash="exact">พอดี</button>
                        <button type="button" class="btn btn-outline-secondary" data-cash="100">100</button>
                        <button type="button" class="btn btn-outline-secondary" data-cash="500">500</button>
                        <button type="button" class="btn btn-outline-secondary" data-cash="1000">1,000</button>
                    </div>
                    <div class="change-box"><span>เงินทอน</span><strong id="changeOut">฿0.00</strong></div>
                </div>
                <div id="payError" class="alert alert-danger mt-3 mb-0 d-none"></div>
            </div>
            <div class="modal-body d-none text-center py-4" id="payDone">
                <i class="bi bi-check-circle-fill" style="font-size:3rem;color:var(--leaf)"></i>
                <h3 class="fs-4 mt-2 mb-1">บันทึกการขายแล้ว</h3>
                <p class="text-secondary mb-2" id="doneNo"></p>
                <p class="fs-5 mb-0" id="doneChange"></p>
            </div>
            <div class="modal-footer" id="payFooter">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">กลับไปแก้ไข</button>
                <button type="button" class="btn btn-accent btn-lg" id="confirmPay">ยืนยันการขาย</button>
            </div>
            <div class="modal-footer d-none" id="doneFooter">
                <a class="btn btn-outline-primary" id="printBtn" target="_blank" href="#"><i class="bi bi-printer"></i> พิมพ์ใบเสร็จ</a>
                <button type="button" class="btn btn-accent btn-lg" onclick="location.reload()">ขายบิลถัดไป</button>
            </div>
        </div>
    </div>
</div>

<?php
$csrf = csrf_token();
$pageScript = <<<JS
<script>
const CSRF = '{$csrf}';
JS;
$pageScript .= <<<'JS'

(function () {
    const cart = new Map(); // menu_id => {id, name, price, qty, max}
    const fmt = n => '฿' + n.toLocaleString('th-TH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    const esc = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const $ = id => document.getElementById(id);
    const payModal = new bootstrap.Modal($('payModal'));

    function total() { let t = 0; cart.forEach(i => t += i.price * i.qty); return t; }
    function count() { let c = 0; cart.forEach(i => c += i.qty); return c; }

    function render() {
        const box = $('cartItems');
        if (!cart.size) {
            box.innerHTML = '<div class="cart-empty">แตะเมนูทางซ้ายเพื่อเพิ่มรายการ</div>';
        } else {
            box.innerHTML = '';
            cart.forEach(i => {
                const row = document.createElement('div');
                row.className = 'cart-row';
                row.innerHTML = '<div><div class="n">' + esc(i.name) + '</div><div class="p">' + fmt(i.price) + '</div></div>' +
                    '<div class="qty-ctrl"><button type="button" aria-label="ลด">−</button><span>' + i.qty + '</span><button type="button" aria-label="เพิ่ม">+</button></div>' +
                    '<div class="num fw-semibold">' + fmt(i.price * i.qty) + '</div>';
                const [minus, plus] = row.querySelectorAll('button');
                minus.onclick = () => { i.qty--; if (i.qty <= 0) cart.delete(i.id); render(); };
                plus.onclick = () => add(i.id);
                box.appendChild(row);
            });
        }
        $('cartTotal').textContent = fmt(total());
        $('mobileTotal').textContent = fmt(total());
        $('mobileCount').textContent = count();
        $('payBtn').disabled = !cart.size;
    }

    function add(id) {
        const tile = document.querySelector('.menu-tile[data-id="' + id + '"]');
        const max = tile.dataset.servings === '' ? null : parseInt(tile.dataset.servings, 10);
        const item = cart.get(id) || {id, name: tile.dataset.name, price: parseFloat(tile.dataset.price), qty: 0, max};
        if (max !== null && item.qty >= max) {
            alert('วัตถุดิบในคลังพอสำหรับ ' + tile.dataset.name + ' อีก ' + max + ' จานเท่านั้น');
            return;
        }
        item.qty++;
        cart.set(id, item);
        render();
    }

    document.querySelectorAll('.menu-tile').forEach(t => t.addEventListener('click', () => add(parseInt(t.dataset.id, 10))));
    $('clearCart').onclick = () => { if (cart.size && confirm('ล้างรายการทั้งหมด?')) { cart.clear(); render(); } };

    // กรองเมนูตามหมวดและคำค้นหา
    let group = '';
    function filter() {
        const q = $('menuSearch').value.trim().toLowerCase();
        document.querySelectorAll('.menu-tile').forEach(t => {
            const show = (!group || t.dataset.group === group) && t.dataset.name.toLowerCase().includes(q);
            t.style.display = show ? '' : 'none';
        });
    }
    document.querySelectorAll('.group-btn').forEach(b => b.addEventListener('click', () => {
        document.querySelectorAll('.group-btn').forEach(x => x.classList.remove('active'));
        b.classList.add('active');
        group = b.dataset.group;
        filter();
    }));
    $('menuSearch').addEventListener('input', filter);

    // ชำระเงิน
    function method() { return document.querySelector('input[name="method"]:checked').value; }
    function updateChange() {
        const cash = parseFloat($('cashIn').value) || 0;
        const ch = cash - total();
        $('changeOut').textContent = ch >= 0 ? fmt(ch) : 'รับเงินยังไม่พอ';
    }
    document.querySelectorAll('input[name="method"]').forEach(r => r.addEventListener('change', () => {
        $('cashSection').classList.toggle('d-none', method() !== 'cash');
    }));
    $('cashIn').addEventListener('input', updateChange);
    document.querySelectorAll('[data-cash]').forEach(b => b.addEventListener('click', () => {
        $('cashIn').value = b.dataset.cash === 'exact' ? total() : b.dataset.cash;
        updateChange();
    }));

    $('payBtn').onclick = () => {
        $('payTotal').textContent = fmt(total());
        $('cashIn').value = '';
        $('payError').classList.add('d-none');
        updateChange();
        payModal.show();
        setTimeout(() => $('cashIn').focus(), 300);
    };

    $('confirmPay').onclick = async () => {
        const m = method();
        const cash = parseFloat($('cashIn').value) || 0;
        if (m === 'cash' && cash < total()) {
            showError('รับเงินมาน้อยกว่ายอดที่ต้องชำระ');
            return;
        }
        const btn = $('confirmPay');
        btn.disabled = true;
        btn.textContent = 'กำลังบันทึก…';
        try {
            const res = await fetch('api/checkout.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': CSRF},
                body: JSON.stringify({
                    payment_method: m,
                    cash_received: m === 'cash' ? cash : null,
                    items: [...cart.values()].map(i => ({menu_id: i.id, qty: i.qty}))
                })
            });
            const data = await res.json();
            if (!data.ok) throw new Error(data.message || 'บันทึกการขายไม่สำเร็จ');
            $('payForm').classList.add('d-none');
            $('payFooter').classList.add('d-none');
            $('payDone').classList.remove('d-none');
            $('doneFooter').classList.remove('d-none');
            $('doneNo').textContent = 'เลขที่บิล ' + data.order_no;
            $('doneChange').textContent = m === 'cash' ? 'เงินทอน ' + fmt(data.change) : 'ชำระด้วยการโอน';
            $('printBtn').href = 'receipt.php?id=' + data.order_id + '&print=1';
            $('payModal').addEventListener('hidden.bs.modal', () => location.reload(), {once: true});
            if (window.refreshNotifications) window.refreshNotifications();
        } catch (err) {
            showError(err.message);
        } finally {
            btn.disabled = false;
            btn.textContent = 'ยืนยันการขาย';
        }
    };

    function showError(msg) {
        $('payError').textContent = msg;
        $('payError').classList.remove('d-none');
    }

    render();
})();
</script>
JS;
require __DIR__ . '/includes/footer.php';
?>
