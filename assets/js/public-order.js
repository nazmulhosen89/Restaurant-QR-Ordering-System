/* QRRS public online order page: card grid, popup option sheet, popup checkout */
(function () {
    'use strict';
    var CFG = window.QRRS_PUB || {};
    var AJAX = CFG.ajax, TOKEN = CFG.token;
    var ROOM = new URLSearchParams(window.location.search).get('rt') || '';
    var S = { data: null, type: '', cat: 0, cart: [], busy: false };
    var $ = function (id) { return document.getElementById(id); };
    var money = function (n) { return '৳' + Number(n || 0).toFixed(2); };
    function esc(t) { return String(t == null ? '' : t).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

    function post(action, data) {
        var b = new URLSearchParams(Object.assign({ action: action, token: TOKEN }, data || {}));
        return fetch(AJAX, { method: 'POST', body: b, credentials: 'same-origin' }).then(function (r) {
            return r.text().then(function (t) {
                try { return JSON.parse(t); }
                catch (e) { return { success: false, data: 'Server error (HTTP ' + r.status + ').' }; }
            });
        }).catch(function () { return { success: false, data: 'Network error. Please try again.' }; });
    }

    /* ---------- header, services, chips ---------- */
    function renderHead() {
        $('qpo-name').textContent = S.data.restaurant;
        if (S.data.room) { $('qpo-name').textContent = S.data.restaurant + ' · Room ' + S.data.room.number; }
        $('qpo-status').innerHTML = S.data.open
            ? '<span class="qpo-status open">● Open now</span>'
            : '<span class="qpo-status closed">● Closed now</span>' + (S.data.next ? ' <small>We open ' + esc(S.data.next) + '</small>' : '');
    }
    function renderServices() {
        var box = $('qpo-services'); box.innerHTML = '';
        if (!S.data.services.length) { box.innerHTML = '<span style="color:#9ca3af;font-size:14px;">Online ordering is not available right now.</span>'; return; }
        if (!S.type) S.type = S.data.services[0];
        var names = { take_out: 'Take-away', delivery: 'Delivery', room_service: 'Room service' };
        S.data.services.forEach(function (s) {
            var b = document.createElement('button');
            b.type = 'button'; b.textContent = names[s] || s;
            b.className = (S.type === s) ? 'on' : '';
            b.onclick = function () { S.type = s; renderServices(); };
            box.appendChild(b);
        });
    }
    function renderChips() {
        var box = $('qpo-chips'); box.innerHTML = '';
        var all = [{ id: 0, category_name: 'All items' }].concat(S.data.categories);
        all.forEach(function (c) {
            var b = document.createElement('button');
            b.type = 'button'; b.className = 'qpo-chip' + (S.cat === c.id ? ' on' : '');
            b.textContent = c.category_name;
            b.onclick = function () { S.cat = c.id; renderChips(); renderGrid(); };
            box.appendChild(b);
        });
    }

    /* ---------- grid ---------- */
    function lineFor(key) { return S.cart.filter(function (l) { return l.key === key; })[0]; }
    function renderGrid() {
        var grid = $('qpo-grid');
        var items = S.data.items.filter(function (i) { return !S.cat || i.cat === S.cat; });
        if (!items.length) { grid.innerHTML = '<div class="qpo-empty">No items in this category.</div>'; return; }
        grid.innerHTML = items.map(function (i) {
            var img = i.image ? '<img src="' + esc(i.image) + '" alt="' + esc(i.name) + '" loading="lazy">' : '🍽';
            var price = (i.custom && !i.price) ? 'Choose size' : money(i.price);
            var action;
            if (i.custom) {
                action = '<button type="button" class="qpo-add alt" data-act="options" data-id="' + i.id + '">Customize</button>';
            } else {
                var l = lineFor(i.id + '||');
                action = l
                    ? '<div class="qpo-stepper"><button type="button" data-act="minus" data-key="' + esc(i.id + '||') + '">−</button><span>' + l.qty + '</span><button type="button" data-act="plus" data-key="' + esc(i.id + '||') + '">+</button></div>'
                    : '<button type="button" class="qpo-add" data-act="add" data-id="' + i.id + '">+ Add</button>';
            }
            return '<article class="qpo-card"><div class="qpo-img">' + img + '</div><div class="qpo-body">'
                + '<div class="qpo-name">' + esc(i.name) + '</div>'
                + '<div class="qpo-desc">' + esc(i.desc) + '</div>'
                + '<div class="qpo-foot"><span class="qpo-price">' + price + '</span>' + action + '</div></div></article>';
        }).join('');
    }
    function itemById(id) { return S.data.items.filter(function (i) { return i.id === Number(id); })[0]; }

    /* ---------- cart ---------- */
    function cartTotal() { return S.cart.reduce(function (a, l) { return a + l.price * l.qty; }, 0); }
    function cartCount() { return S.cart.reduce(function (a, l) { return a + l.qty; }, 0); }
    function updateBar() {
        var bar = $('qpo-cartbar');
        if (!S.cart.length) { bar.hidden = true; return; }
        bar.hidden = false;
        $('qpo-cartcount').textContent = cartCount();
        $('qpo-carttotal').textContent = money(cartTotal());
    }
    function changeLine(key, delta) {
        var l = lineFor(key); if (!l) return;
        l.qty += delta;
        if (l.qty <= 0) S.cart = S.cart.filter(function (x) { return x !== l; });
        renderGrid(); updateBar();
        var lines = document.getElementById('qpo-lines');
        if (lines) lines.innerHTML = cartRows();
        if (!S.cart.length && !document.getElementById('qpo-body')) return;
        if (!S.cart.length) { closeSheet(); return; }
        refreshCheckoutTotals();
    }
    function addSimple(item) {
        var key = item.id + '||';
        var l = lineFor(key);
        if (l) l.qty++; else S.cart.push({ key: key, id: item.id, name: item.name, price: item.price, qty: 1, size_value_ids: '', variants_selected: '', label: '' });
        renderGrid(); updateBar();
    }

    /* ---------- option sheet (size + add-ons) ---------- */
    function openSheet(html) {
        $('qpo-sheet').innerHTML = html;
        $('qpo-modal').hidden = false;
        document.body.style.overflow = 'hidden';
    }
    function closeSheet() {
        if (S.busy) return;
        $('qpo-modal').hidden = true;
        $('qpo-sheet').innerHTML = '';
        document.body.style.overflow = '';
    }
    function openOptions(item) {
        post('qrrs_get_item_options', { restaurant_id: S.data.rid, item_id: item.id }).then(function (j) {
            var groups = (j && j.success && j.data) ? j.data.groups : [];
            if (!groups.length) { addSimple(item); return; }
            var hasReqSize = groups.some(function (g) { return g.type === 'size' && g.required; });
            var h = '<div class="qpo-sheet-head"><h2>' + esc(item.name) + '</h2><button class="qpo-x" data-act="close">×</button></div><div class="qpo-sheet-body qpo-opts">';
            groups.forEach(function (g) {
                h += '<div class="qpo-sec"><h3>' + esc(g.name) + (g.type === 'size' && g.required ? ' · required' : '') + '</h3>';
                if (g.type === 'size') {
                    if (!g.required) h += '<label><input type="radio" name="sz' + g.id + '" value="0" checked data-p="0" data-n=""> None</label>';
                    g.values.forEach(function (v) {
                        h += '<label><input type="radio" class="sz" name="sz' + g.id + '" value="' + v.id + '" data-p="' + v.price + '" data-n="' + esc(v.name) + '"> ' + esc(v.name) + '<span class="pr">' + money(v.price) + '</span></label>';
                    });
                } else {
                    g.values.forEach(function (v) {
                        h += '<label><input type="checkbox" class="ad" data-p="' + v.price + '" data-n="' + esc(v.name) + '"> ' + esc(v.name) + '<span class="pr">+' + money(v.price) + '</span></label>';
                    });
                }
                h += '</div>';
            });
            h += '<div class="qpo-totals"><div class="grand"><span>Total</span><span id="qpo-opt-total"></span></div></div>'
               + '<button type="button" class="qpo-submit" id="qpo-opt-add">Add to cart</button></div>';
            openSheet(h);
            var groupsReq = groups.filter(function (g) { return g.type === 'size' && g.required; });
            function total() {
                var p = hasReqSize ? 0 : Number(item.price);
                document.querySelectorAll('#qpo-sheet input:checked').forEach(function (el) { p += Number(el.dataset.p || 0); });
                $('qpo-opt-total').textContent = money(p);
                return p;
            }
            document.querySelectorAll('#qpo-sheet input').forEach(function (el) { el.onchange = total; });
            total();
            $('qpo-opt-add').onclick = function () {
                for (var i = 0; i < groupsReq.length; i++) {
                    if (!document.querySelector('input[name="sz' + groupsReq[i].id + '"]:checked')) { alert('Please choose: ' + groupsReq[i].name); return; }
                }
                var sizeIds = [], sizeNames = [], addons = [];
                document.querySelectorAll('input.sz:checked').forEach(function (el) { if (el.value !== '0') { sizeIds.push(el.value); sizeNames.push(el.dataset.n); } });
                document.querySelectorAll('input.ad:checked').forEach(function (el) { addons.push(el.dataset.n); });
                var price = total();
                var label = sizeNames.join(' + ');
                var key = item.id + '|' + sizeIds.join(',') + '|' + addons.join(',');
                var ex = lineFor(key);
                if (ex) ex.qty++;
                else S.cart.push({ key: key, id: item.id, name: item.name + (label ? ' (' + label + ')' : ''), price: price, qty: 1, size_value_ids: sizeIds.join(','), variants_selected: addons.join(', '), label: label });
                closeSheet(); renderGrid(); updateBar();
            };
        });
    }

    /* ---------- checkout popup ---------- */
    function areaById(id) { return S.data.areas.filter(function (a) { return a.id === Number(id); })[0]; }
    function deliveryCharge() {
        if (S.type !== 'delivery') return 0;
        var sel = document.getElementById('qpo-area'); var a = sel && areaById(sel.value);
        return a ? a.charge : 0;
    }
    function minOrder() {
        if (S.type !== 'delivery') return 0;
        var sel = document.getElementById('qpo-area'); var a = sel && areaById(sel.value);
        return a ? a.min : 0;
    }
    function cartRows() {
        if (!S.cart.length) return '<div class="qpo-empty" style="padding:14px 0;">Your cart is empty.</div>';
        return S.cart.map(function (l) {
            return '<div class="qpo-line"><div class="nm"><b>' + esc(l.name) + '</b><small>' + money(l.price) + ' each</small></div>'
                + '<div class="qpo-stepper"><button type="button" data-act="minus" data-key="' + esc(l.key) + '">−</button><span>' + l.qty + '</span><button type="button" data-act="plus" data-key="' + esc(l.key) + '">+</button></div>'
                + '<div class="amt">' + money(l.price * l.qty) + '</div></div>';
        }).join('');
    }
    function refreshCheckoutTotals() {
        var box = document.getElementById('qpo-totals'); if (!box) return;
        var sub = cartTotal(), dc = deliveryCharge(), min = minOrder();
        box.innerHTML = '<div><span>Subtotal</span><span>' + money(sub) + '</span></div>'
            + (S.type === 'delivery' ? '<div><span>Delivery charge</span><span>' + money(dc) + '</span></div>' : '')
            + '<div class="grand"><span>Total</span><span>' + money(sub + dc) + '</span></div>'
            + '<div style="font-size:12px;color:#6b7280;margin-top:4px;">VAT applied where applicable (delivery charge is not taxed).</div>';
        var warn = document.getElementById('qpo-minwarn');
        if (warn) warn.textContent = (S.type === 'delivery' && min && sub < min) ? 'Minimum order for this area is ' + money(min) + '.' : '';
        var btn = document.getElementById('qpo-submit');
        if (btn) btn.disabled = !S.data.open || !S.cart.length || (S.type === 'delivery' && min && sub < min);
    }
    function openCheckout() {
        if (!S.cart.length) return;
        var types = S.data.services;
        var seg = types.length > 1 ? '<div class="qpo-seg">' + types.map(function (s) {
            return '<button type="button" data-act="type" data-type="' + s + '" class="' + (S.type === s ? 'on' : '') + '">' + (s === 'delivery' ? 'Delivery' : 'Take-away') + '</button>';
        }).join('') + '</div>' : '';
        var areaSel = '<select id="qpo-area" class="qpo-input"><option value="">Choose your area</option>' + S.data.areas.map(function (a) {
            return '<option value="' + a.id + '">' + esc(a.name) + ' — delivery ' + money(a.charge) + (a.min ? ', min ' + money(a.min) : '') + '</option>';
        }).join('') + '</select>';
        var h = '<div class="qpo-sheet-head"><h2>Checkout</h2><button class="qpo-x" data-act="close">×</button></div><div class="qpo-sheet-body" id="qpo-body">'
            + '<div class="qpo-sec"><h3>Your order</h3><div id="qpo-lines">' + cartRows() + '</div></div>'
            + '<div class="qpo-sec"><h3>How would you like it</h3>' + seg + '</div>'
            + '<div class="qpo-sec"><h3>Your details</h3>'
            + '<input id="qpo-cname" class="qpo-input" placeholder="Full name *" maxlength="100">'
            + '<input id="qpo-phone" class="qpo-input" placeholder="Mobile number * (e.g. 01XXXXXXXXX)" inputmode="tel" maxlength="20">'
            + (S.data.otp === 'test' ? '<div style="margin-bottom:10px;"><div style="display:flex; gap:8px;"><button type="button" class="qpo-add" id="qpo-otp-send" style="white-space:nowrap;">Send code</button><input id="qpo-otp-code" class="qpo-input" style="margin:0;" placeholder="6-digit code" inputmode="numeric" maxlength="6"><button type="button" class="qpo-add" id="qpo-otp-verify">Verify</button></div><div id="qpo-otp-msg" style="font-size:13px; margin-top:6px; color:#64748b;"></div></div>' : '')
            + (S.type === 'delivery' ? areaSel + '<textarea id="qpo-addr" class="qpo-input" rows="2" placeholder="Full delivery address *"></textarea>' : '')
            + '<textarea id="qpo-note" class="qpo-input" rows="2" placeholder="Note (optional)"></textarea></div>'
            + '<div class="qpo-totals" id="qpo-totals"></div>'
            + '<div class="qpo-cod">💵 Pay cash on delivery / at pickup</div>'
            + '<div class="qpo-err" id="qpo-minwarn"></div>'
            + '<div class="qpo-err" id="qpo-err"></div>'
            + '<button type="button" class="qpo-submit" id="qpo-submit">Place order</button>'
            + (S.data.open ? '' : '<div class="qpo-err">We are closed right now. Please come back during opening hours.</div>')
            + '<button type="button" class="qpo-ghost" data-act="close">Continue browsing</button></div>';
        openSheet(h);
        var phoneEl = document.getElementById('qpo-phone');
        var nameEl = document.getElementById('qpo-cname');
        if (phoneEl && nameEl) {
            phoneEl.addEventListener('blur', function () {
                var p = phoneEl.value.replace(/[\s-]/g, '');
                if (!/^01[3-9]\d{8}$/.test(p) || nameEl.value.trim()) return;
                post('qrrs_public_customer_name', { phone: p }).then(function (j) {
                    if (j && j.success && j.data && j.data.name && !nameEl.value.trim()) nameEl.value = j.data.name;
                });
            });
        }
        var sendBtn = document.getElementById('qpo-otp-send');
        var verBtn = document.getElementById('qpo-otp-verify');
        var otpMsg = document.getElementById('qpo-otp-msg');
        if (phoneEl) phoneEl.addEventListener('input', function () { S.verifiedPhone = ''; if (otpMsg) otpMsg.textContent = ''; });
        if (sendBtn && phoneEl) sendBtn.onclick = function () {
            var p = phoneEl.value.replace(/[\s-]/g, '');
            if (!/^01[3-9]\d{8}$/.test(p)) { otpMsg.textContent = 'Enter a valid mobile number first.'; return; }
            otpMsg.textContent = 'Sending code...';
            post('qrrs_otp_send', { phone: p }).then(function (j) {
                if (j && j.success) otpMsg.innerHTML = 'Test code: <strong style="color:#f97316; font-size:16px;">' + esc(j.data.code || '') + '</strong> (valid for 5 minutes)';
                else otpMsg.textContent = (j && j.data) ? String(j.data) : 'Could not send code.';
            });
        };
        if (verBtn && phoneEl) verBtn.onclick = function () {
            var p = phoneEl.value.replace(/[\s-]/g, '');
            var c = (document.getElementById('qpo-otp-code') || { value: '' }).value.trim();
            if (!c) { otpMsg.textContent = 'Enter the code first.'; return; }
            post('qrrs_otp_verify', { phone: p, code: c }).then(function (j) {
                if (j && j.success) { S.verifiedPhone = p; otpMsg.innerHTML = '<span style="color:#16a34a; font-weight:700;">✓ Mobile number verified</span>'; }
                else otpMsg.textContent = (j && j.data) ? String(j.data) : 'Verification failed.';
            });
        };
        var areaEl = document.getElementById('qpo-area');
        if (areaEl) areaEl.onchange = refreshCheckoutTotals;
        refreshCheckoutTotals();
    }
    function showSuccess(info) {
        S.cart = []; updateBar(); renderGrid();
        var code = info && info.track_code ? info.track_code : '';
        var link = info && info.track_url ? info.track_url : '';
        openSheet('<div class="qpo-sheet-body qpo-success"><div class="tick">✓</div><h3>Thank you!</h3>'
            + '<p>Your order has been received. Our staff will confirm it shortly.</p>'
            + (code ? '<div style="margin:16px 0 8px; font-size:13px; color:#9ca3af;">Your order code</div>'
                    + '<div style="font-size:30px; font-weight:800; letter-spacing:.2em; color:#f97316;">' + esc(code) + '</div>'
                    + '<p style="font-size:13px;">Keep this code. Use it with your mobile number to track your order.</p>'
                    + (link ? '<a href="' + esc(link) + '" class="qpo-submit" style="display:block; text-decoration:none; margin-top:14px;">Track my order</a>' : '') : '')
            + '<button type="button" class="qpo-ghost" data-act="close">Done</button></div>');
    }
    function submitOrder() {
        var err = document.getElementById('qpo-err'); err.textContent = '';
        var name = $('qpo-cname').value.trim();
        var phone = $('qpo-phone').value.replace(/[\s-]/g, '');
        var note = $('qpo-note').value;
        if (name.length < 2) { err.textContent = 'Please enter your name.'; return; }
        if (!/^(?:\+?880|0)?1[3-9]\d{8}$/.test(phone)) { err.textContent = 'Please enter a valid mobile number.'; return; }
        if (S.data.otp === 'test' && S.verifiedPhone !== phone) { err.textContent = 'Please verify your mobile number with the code first.'; return; }
        var data = { order_type: S.type, rt: ROOM, customer_name: name, customer_phone: phone, delivery_note: note,
            items: JSON.stringify(S.cart.map(function (l) { return { id: l.id, qty: l.qty, size_value_ids: l.size_value_ids, variants_selected: l.variants_selected }; })) };
        if (S.type === 'delivery') {
            var area = document.getElementById('qpo-area').value;
            var addr = document.getElementById('qpo-addr').value.trim();
            if (!area) { err.textContent = 'Please choose your delivery area.'; return; }
            if (addr.length < 5) { err.textContent = 'Please enter your full delivery address.'; return; }
            data.area_id = area; data.delivery_address = addr;
        }
        var btn = $('qpo-submit'); btn.disabled = true; btn.textContent = 'Placing order...'; S.busy = true;
        post('qrrs_public_order', data).then(function (j) {
            S.busy = false;
            if (j && j.success) { showSuccess(j.data && typeof j.data === 'object' ? j.data : null); return; }
            btn.disabled = false; btn.textContent = 'Place order';
            err.textContent = (j && j.data) ? String(j.data) : 'Could not place order. Please try again.';
        });
    }

    /* ---------- events ---------- */
    document.addEventListener('click', function (e) {
        var t = e.target.closest('[data-act]'); if (!t) return;
        var act = t.dataset.act;
        if (act === 'add') addSimple(itemById(t.dataset.id));
        else if (act === 'options') openOptions(itemById(t.dataset.id));
        else if (act === 'plus') changeLine(t.dataset.key, 1);
        else if (act === 'minus') changeLine(t.dataset.key, -1);
        else if (act === 'close') closeSheet();
        else if (act === 'type') { S.type = t.dataset.type; openCheckout(); }
    });
    document.addEventListener('click', function (e) {
        if (e.target.id === 'qpo-view-cart') openCheckout();
        if (e.target.id === 'qpo-submit' && document.getElementById('qpo-body')) submitOrder();
        if (e.target.id === 'qpo-modal') closeSheet();
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeSheet(); });

    /* ---------- start ---------- */
    if (!$('qpo')) return;
    post('qrrs_public_menu', { rt: ROOM }).then(function (j) {
        if (!j || !j.success) { $('qpo-name').textContent = 'Online order'; $('qpo-status').innerHTML = '<span class="qpo-status closed">' + esc(j && j.data ? j.data : 'Could not load the menu.') + '</span>'; return; }
        S.data = j.data;
        // Database ids arrive as strings; items use numbers. Normalise so the category filter matches.
        S.data.categories = (S.data.categories || []).map(function (c) {
            return { id: Number(c.id), category_name: c.category_name };
        });
        S.data.items = (S.data.items || []).map(function (i) {
            i.id = Number(i.id); i.cat = Number(i.cat); i.price = Number(i.price); i.tax_free = Number(i.tax_free);
            return i;
        });
        S.data.rid = Number(S.data.rid);
        renderHead(); renderServices(); renderChips(); renderGrid(); updateBar();
    });
})();