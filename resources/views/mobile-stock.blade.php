<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#F4501E">
    <title>Stock — Le Cayenne</title>
    {{-- [GOAL MEGA W-MOBILE 2026-07-22] Page stock mobile PIN-gated, 100% autonome
         (inline CSS + vanilla JS, PAS la SPA admin, PAS de Mix build). HORS NF525 :
         stock uniquement, aucune donnée fiscale/CA. --}}
    <style>
        :root {
            --brand: #F4501E; --accent: #FFB800; --dark: #1A1A1A;
            --ok: #1B9E4B; --bad: #D92D20; --bg: #F5F5F4; --card: #FFFFFF;
            --line: #E4E4E7; --muted: #6B7280;
        }
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: var(--bg); color: var(--dark); line-height: 1.4;
            padding: env(safe-area-inset-top) env(safe-area-inset-right) env(safe-area-inset-bottom) env(safe-area-inset-left);
        }
        .hidden { display: none !important; }

        /* ---- PIN screen ---- */
        #pin-screen {
            min-height: 100dvh; display: flex; flex-direction: column;
            align-items: center; justify-content: center; padding: 24px; gap: 24px;
        }
        .pin-logo { font-size: 22px; font-weight: 800; color: var(--brand); letter-spacing: .5px; text-align: center; }
        .pin-sub { color: var(--muted); font-size: 15px; margin-top: -14px; }
        .pin-dots { display: flex; gap: 16px; }
        .pin-dot { width: 16px; height: 16px; border-radius: 50%; border: 2px solid var(--brand); background: transparent; transition: background .1s; }
        .pin-dot.on { background: var(--brand); }
        .pin-err { color: var(--bad); font-size: 14px; min-height: 18px; font-weight: 600; }
        .keypad { display: grid; grid-template-columns: repeat(3, 84px); gap: 14px; }
        .key {
            height: 76px; border-radius: 18px; border: none; background: var(--card);
            font-size: 30px; font-weight: 600; color: var(--dark);
            box-shadow: 0 1px 2px rgba(0,0,0,.08); cursor: pointer; user-select: none;
        }
        .key:active { background: #EDEDED; }
        .key.blank { background: transparent; box-shadow: none; cursor: default; }
        .key.wide { font-size: 20px; }

        /* ---- Stock screen ---- */
        header {
            position: sticky; top: 0; z-index: 5; background: var(--brand); color: #fff;
            display: flex; align-items: center; justify-content: space-between;
            padding: 14px 16px; box-shadow: 0 2px 6px rgba(0,0,0,.15);
        }
        header .h-title { font-size: 18px; font-weight: 800; }
        .lock-btn {
            min-height: 40px; padding: 8px 14px; border-radius: 10px; border: 1px solid rgba(255,255,255,.5);
            background: transparent; color: #fff; font-size: 14px; font-weight: 600; cursor: pointer;
        }
        main { padding: 12px 12px 40px; max-width: 640px; margin: 0 auto; }
        .section-title { font-size: 14px; font-weight: 800; text-transform: uppercase; letter-spacing: .6px; color: var(--muted); margin: 18px 4px 8px; }
        .buy-card { background: #FFF7ED; border: 1px solid var(--accent); border-radius: 14px; padding: 12px; }
        .buy-empty { color: var(--muted); font-size: 15px; padding: 6px 4px; }
        .buy-item { display: flex; align-items: center; gap: 8px; padding: 6px 2px; font-size: 16px; font-weight: 600; }
        .buy-item .cat { color: var(--muted); font-weight: 500; font-size: 13px; }
        .cat-block { background: var(--card); border: 1px solid var(--line); border-radius: 14px; margin-top: 12px; overflow: hidden; }
        .cat-head { padding: 12px 14px; font-weight: 800; font-size: 16px; background: #FAFAFA; border-bottom: 1px solid var(--line); }
        .row { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 10px 14px; border-bottom: 1px solid var(--line); }
        .row:last-child { border-bottom: none; }
        .row .name { font-size: 16px; font-weight: 500; flex: 1; }
        .toggle {
            min-width: 118px; min-height: 44px; border-radius: 12px; border: none;
            font-size: 14px; font-weight: 800; letter-spacing: .3px; color: #fff; cursor: pointer;
            display: inline-flex; align-items: center; justify-content: center; gap: 6px;
        }
        .toggle.on { background: var(--ok); }
        .toggle.off { background: var(--bad); }
        .toggle[disabled] { opacity: .5; }
        .loading { text-align: center; color: var(--muted); padding: 40px 0; font-size: 15px; }
        .toast {
            position: fixed; left: 50%; bottom: 24px; transform: translateX(-50%);
            background: var(--dark); color: #fff; padding: 10px 18px; border-radius: 10px;
            font-size: 14px; font-weight: 600; opacity: 0; transition: opacity .2s; pointer-events: none; z-index: 20;
        }
        .toast.show { opacity: .95; }
    </style>
</head>
<body>
    <!-- PIN screen -->
    <section id="pin-screen">
        <div>
            <div class="pin-logo">🌶️ LE CAYENNE — STOCK</div>
            <div class="pin-sub" style="text-align:center">Entrez le code</div>
        </div>
        <div class="pin-dots" id="pin-dots"></div>
        <div class="pin-err" id="pin-err"></div>
        <div class="keypad" id="keypad"></div>
    </section>

    <!-- Stock screen -->
    <section id="stock-screen" class="hidden">
        <header>
            <div class="h-title">🌶️ Stock</div>
            <button class="lock-btn" id="lock-btn" type="button">Verrouiller</button>
        </header>
        <main>
            <div class="section-title">🛒 À acheter</div>
            <div class="buy-card" id="buy-card"><div class="buy-empty">Chargement…</div></div>
            <div id="catalog"><div class="loading">Chargement du catalogue…</div></div>
        </main>
    </section>

    <div class="toast" id="toast"></div>

    <script>
    (function () {
        'use strict';
        var PIN_LEN = 4;
        var csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        var pinScreen = document.getElementById('pin-screen');
        var stockScreen = document.getElementById('stock-screen');
        var dotsEl = document.getElementById('pin-dots');
        var errEl = document.getElementById('pin-err');
        var buf = '';
        var busy = false;

        // ---- helpers ----
        function toast(msg) {
            var t = document.getElementById('toast');
            t.textContent = msg; t.classList.add('show');
            setTimeout(function () { t.classList.remove('show'); }, 1800);
        }
        function jsonHeaders() {
            return { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' };
        }
        function showPin() {
            stockScreen.classList.add('hidden');
            pinScreen.classList.remove('hidden');
            buf = ''; renderDots();
        }
        function showStock() {
            pinScreen.classList.add('hidden');
            stockScreen.classList.remove('hidden');
            loadCatalog();
        }

        // ---- PIN keypad ----
        function renderDots() {
            dotsEl.textContent = '';
            for (var i = 0; i < PIN_LEN; i++) {
                var d = document.createElement('div');
                d.className = 'pin-dot' + (i < buf.length ? ' on' : '');
                dotsEl.appendChild(d);
            }
        }
        function buildKeypad() {
            var kp = document.getElementById('keypad');
            var keys = ['1','2','3','4','5','6','7','8','9','','0','⌫'];
            keys.forEach(function (k) {
                var b = document.createElement('button');
                b.type = 'button';
                if (k === '') { b.className = 'key blank'; b.disabled = true; }
                else { b.className = 'key' + (k === '⌫' ? ' wide' : ''); b.textContent = k; }
                b.addEventListener('click', function () { onKey(k); });
                kp.appendChild(b);
            });
        }
        function onKey(k) {
            if (busy || k === '') return;
            errEl.textContent = '';
            if (k === '⌫') { buf = buf.slice(0, -1); renderDots(); return; }
            if (buf.length >= PIN_LEN) return;
            buf += k; renderDots();
            if (buf.length === PIN_LEN) submitPin();
        }
        function submitPin() {
            busy = true;
            fetch('{{ url('/m/api/pin') }}', {
                method: 'POST', headers: jsonHeaders(), credentials: 'same-origin',
                body: JSON.stringify({ pin: buf })
            }).then(function (r) {
                return r.json().catch(function () { return {}; }).then(function (data) { return { status: r.status, data: data }; });
            }).then(function (res) {
                busy = false;
                if (res.status === 200 && res.data.unlocked) {
                    if (res.data.csrf) { csrf = res.data.csrf; }
                    showStock();
                } else if (res.status === 429) {
                    errEl.textContent = 'Trop d\'essais. Patientez une minute.';
                    buf = ''; renderDots();
                } else {
                    errEl.textContent = res.data.message || 'Code incorrect.';
                    buf = ''; renderDots();
                }
            }).catch(function () {
                busy = false; errEl.textContent = 'Erreur réseau.'; buf = ''; renderDots();
            });
        }

        // ---- Stock catalog ----
        function loadCatalog() {
            var cat = document.getElementById('catalog');
            cat.innerHTML = '<div class="loading">Chargement du catalogue…</div>';
            fetch('{{ url('/m/api/catalog') }}', {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin'
            }).then(function (r) {
                if (r.status === 401) { showPin(); return null; }
                return r.json();
            }).then(function (data) {
                if (!data) return;
                renderShopping(data.shopping || []);
                renderCatalog(data.categories || []);
            }).catch(function () {
                cat.innerHTML = '<div class="loading">Erreur de chargement.</div>';
            });
        }
        function renderShopping(list) {
            var box = document.getElementById('buy-card');
            box.textContent = '';
            if (!list.length) {
                var e = document.createElement('div');
                e.className = 'buy-empty';
                e.textContent = 'Aucune rupture — tout est en stock ✅';
                box.appendChild(e);
                return;
            }
            list.forEach(function (it) {
                var row = document.createElement('div');
                row.className = 'buy-item';
                var dot = document.createElement('span'); dot.textContent = '🔴';
                var nm = document.createElement('span'); nm.textContent = it.name;
                var cat = document.createElement('span'); cat.className = 'cat'; cat.textContent = it.category || '';
                row.appendChild(dot); row.appendChild(nm); row.appendChild(cat);
                box.appendChild(row);
            });
        }
        function renderCatalog(cats) {
            var wrap = document.getElementById('catalog');
            wrap.textContent = '';
            if (!cats.length) {
                var e = document.createElement('div'); e.className = 'loading';
                e.textContent = 'Aucun produit actif.'; wrap.appendChild(e); return;
            }
            cats.forEach(function (c) {
                if (!c.items || !c.items.length) return;
                var block = document.createElement('div'); block.className = 'cat-block';
                var head = document.createElement('div'); head.className = 'cat-head'; head.textContent = c.name;
                block.appendChild(head);
                c.items.forEach(function (it) {
                    var row = document.createElement('div'); row.className = 'row';
                    var nm = document.createElement('div'); nm.className = 'name'; nm.textContent = it.name;
                    var btn = document.createElement('button'); btn.type = 'button';
                    setBtn(btn, it.is_available);
                    btn.addEventListener('click', function () { toggleItem(it.id, !it.is_available, btn, it); });
                    row.appendChild(nm); row.appendChild(btn);
                    block.appendChild(row);
                });
                wrap.appendChild(block);
            });
        }
        function setBtn(btn, available) {
            btn.className = 'toggle ' + (available ? 'on' : 'off');
            btn.textContent = available ? 'EN STOCK' : 'RUPTURE';
        }
        function toggleItem(id, next, btn, it) {
            if (btn.disabled) return;
            btn.disabled = true;
            fetch('{{ url('/m/api/toggle') }}', {
                method: 'POST', headers: jsonHeaders(), credentials: 'same-origin',
                body: JSON.stringify({ item_id: id, is_available: next })
            }).then(function (r) {
                if (r.status === 401) { showPin(); return null; }
                return r.json().catch(function () { return {}; }).then(function (d) { return { status: r.status, data: d }; });
            }).then(function (res) {
                btn.disabled = false;
                if (!res) return;
                if (res.status === 200 && res.data.ok) {
                    it.is_available = res.data.is_available;
                    setBtn(btn, it.is_available);
                    toast(it.is_available ? 'Remis en stock' : 'Marqué en rupture');
                    loadCatalog(); // refresh « À acheter »
                } else {
                    toast(res.data.message || 'Échec de la mise à jour');
                }
            }).catch(function () {
                btn.disabled = false; toast('Erreur réseau');
            });
        }

        // ---- lock ----
        document.getElementById('lock-btn').addEventListener('click', function () {
            fetch('{{ url('/m/api/lock') }}', {
                method: 'POST', headers: jsonHeaders(), credentials: 'same-origin'
            }).then(function () { showPin(); }).catch(function () { showPin(); });
        });

        // ---- boot ----
        buildKeypad();
        renderDots();
        fetch('{{ url('/m/api/status') }}', {
            headers: { 'Accept': 'application/json' }, credentials: 'same-origin'
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (data && data.unlocked) { showStock(); } else { showPin(); }
        }).catch(function () { showPin(); });
    })();
    </script>
</body>
</html>
