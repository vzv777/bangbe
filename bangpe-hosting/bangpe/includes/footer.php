        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ===== การแจ้งเตือนในระบบ: ดึงข้อมูลจาก api/notifications.php ทุก ๆ NOTIFY_POLL_SECONDS วินาที =====
(function () {
    const badge = document.getElementById('notifyBadge');
    const menu = document.getElementById('notifyMenu');
    const isAdmin = <?= is_admin() ? 'true' : 'false' ?>;
    const esc = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    function render(data) {
        badge.textContent = data.count > 99 ? '99+' : data.count;
        badge.classList.toggle('d-none', data.count === 0);

        if (data.count === 0) {
            menu.innerHTML = '<div class="notify-empty"><i class="bi bi-check-circle"></i> วัตถุดิบเพียงพอ และไม่มีของใกล้หมดอายุ</div>';
            return;
        }
        let html = '';
        if (data.low.length) {
            html += '<div class="notify-head">ใกล้หมด (' + data.low.length + ')</div>';
            data.low.forEach(i => {
                html += '<a class="notify-item" href="' + (isAdmin ? 'stock_in.php?ingredient=' + i.id : 'ingredients.php') + '">' +
                    '<i class="bi bi-exclamation-triangle text-danger"></i><span><strong>' + esc(i.name) + '</strong>' +
                    '<small>เหลือ ' + esc(i.quantity_text) + ' ' + esc(i.unit) + ' (ขั้นต่ำ ' + esc(i.min_text) + ')</small></span></a>';
            });
        }
        if (data.expiry.length) {
            html += '<div class="notify-head">ใกล้หมดอายุ (' + data.expiry.length + ')</div>';
            data.expiry.forEach(i => {
                html += '<a class="notify-item" href="' + (isAdmin ? 'expiry.php' : 'ingredients.php') + '">' +
                    '<i class="bi bi-calendar-x text-warning"></i><span><strong>' + esc(i.name) + '</strong>' +
                    '<small>' + esc(i.expiry_text) + '</small></span></a>';
            });
        }
        menu.innerHTML = html;
    }

    function load() {
        fetch('api/notifications.php', {credentials: 'same-origin'})
            .then(r => r.ok ? r.json() : Promise.reject())
            .then(render)
            .catch(() => { menu.innerHTML = '<div class="notify-empty">โหลดการแจ้งเตือนไม่สำเร็จ</div>'; });
    }

    load();
    setInterval(load, <?= (int)NOTIFY_POLL_SECONDS ?> * 1000);
    window.refreshNotifications = load;
})();
</script>
<script>
// กล่องวิธีใช้: ซ่อนแล้วจำไว้ในเบราว์เซอร์ (แยกแต่ละหน้า) กดปุ่ม "วิธีใช้" ด้านบนเพื่อเปิดอีกครั้ง
(function () {
    const box = document.getElementById('pageHelp');
    const toggle = document.getElementById('helpToggle');
    if (!box || !toggle) return;
    const key = 'bangpe-help-hidden-' + box.dataset.page;
    const read = () => { try { return localStorage.getItem(key) === '1'; } catch (e) { return false; } };
    const save = v => { try { v ? localStorage.setItem(key, '1') : localStorage.removeItem(key); } catch (e) {} };
    const show = visible => {
        box.hidden = !visible;
        toggle.setAttribute('aria-expanded', visible ? 'true' : 'false');
        toggle.classList.toggle('is-open', visible);
    };
    show(!read());
    document.getElementById('helpClose').addEventListener('click', () => { save(true); show(false); toggle.focus(); });
    toggle.addEventListener('click', () => { const v = box.hidden; save(!v); show(v); });
})();
</script>
<?php if (!empty($pageScript)) echo $pageScript; ?>
</body>
</html>
