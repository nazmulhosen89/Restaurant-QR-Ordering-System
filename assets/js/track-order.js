/* QRRS order tracking page */
(function () {
    'use strict';
    var CFG = window.QRRS_TRACK || {};
    var $ = function (id) { return document.getElementById(id); };
    var timer = null;

    function esc(t) { return String(t == null ? '' : t).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

    function render(d) {
        var box = $('qtr-result');
        var steps = d.steps.map(function (label, i) {
            var state = d.cancelled ? 'off' : (i < d.current ? 'done' : (i === d.current ? 'now' : 'todo'));
            var dot = state === 'done' ? '✓' : (state === 'now' ? '●' : '');
            var color = state === 'done' ? '#16a34a' : (state === 'now' ? '#f97316' : '#475569');
            return '<div style="display:flex; gap:14px; align-items:flex-start; padding:10px 0;">'
                + '<div style="width:28px; height:28px; border-radius:50%; flex:0 0 28px; display:flex; align-items:center; justify-content:center; background:' + (state === 'todo' || state === 'off' ? '#2c2c2c' : color) + '; color:#fff; font-weight:700; font-size:13px;">' + (dot || (i + 1)) + '</div>'
                + '<div style="font-weight:' + (state === 'now' ? '800' : '600') + '; color:' + (state === 'todo' ? '#64748b' : '#f5f5f5') + ';">' + esc(label) + (state === 'now' ? ' <span style="color:#f97316; font-size:12px;">now</span>' : '') + '</div>'
                + '</div>';
        }).join('');
        var head = d.cancelled
            ? '<div style="background:rgba(239,68,68,.15); color:#f87171; padding:12px 14px; border-radius:12px; font-weight:700;">This order was cancelled.</div>'
            : (d.done
                ? '<div style="background:rgba(22,163,74,.15); color:#4ade80; padding:12px 14px; border-radius:12px; font-weight:700;">Thank you! Your order is complete.</div>'
                : '<div style="color:#9ca3af; font-size:13px;">Order ' + esc(d.code) + ' · placed ' + esc(d.placed) + '</div>');
        box.innerHTML = '<div style="background:#1f1f1f; border:1px solid #2c2c2c; border-radius:18px; padding:20px;">' + head + '<div style="margin-top:12px;">' + steps + '</div>'
            + '<div style="color:#6b7280; font-size:12px; margin-top:8px;">This page refreshes automatically.</div></div>';
    }

    function lookup(code, phone) {
        var body = new URLSearchParams({ action: 'qrrs_track_order', code: code, phone: phone });
        return fetch(CFG.ajax, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.text(); })
            .then(function (t) {
                var j; try { j = JSON.parse(t); } catch (e) { j = { success: false, data: 'Server error. Please try again.' }; }
                if (!j.success) { $('qtr-err').textContent = j.data; $('qtr-result').innerHTML = ''; return; }
                $('qtr-err').textContent = '';
                render(j.data);
                clearTimeout(timer);
                if (!j.data.done && !j.data.cancelled) {
                    timer = setTimeout(function () { lookup(code, phone); }, 30000);
                }
            })
            .catch(function () { $('qtr-err').textContent = 'Network error. Please try again.'; });
    }

    function start() {
        if (!$('qtr')) return;
        if (CFG.code) { $('qtr-code').value = CFG.code; }
        $('qtr-go').addEventListener('click', function () {
            var code = $('qtr-code').value.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
            var phone = $('qtr-phone').value.replace(/[\s-]/g, '');
            if (code.length !== 6) { $('qtr-err').textContent = 'Enter the 6-character order code.'; return; }
            if (!/^01[3-9]\d{8}$/.test(phone)) { $('qtr-err').textContent = 'Enter the mobile number used for the order.'; return; }
            lookup(code, phone);
        });
    }
    start();
})();