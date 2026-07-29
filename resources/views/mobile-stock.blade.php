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

        /* ---- [F4] Search ---- */
        .search-wrap { position: relative; margin: 6px 4px 2px; }
        .search-input {
            width: 100%; min-height: 46px; border-radius: 12px; border: 1px solid var(--line);
            background: var(--card); padding: 10px 40px 10px 14px; font-size: 16px; color: var(--dark);
            -webkit-appearance: none; appearance: none;
        }
        .search-input:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 3px rgba(244,80,30,.15); }
        .search-clear {
            position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
            width: 32px; height: 32px; border: none; border-radius: 50%; background: #EDEDED;
            color: var(--muted); font-size: 15px; cursor: pointer; line-height: 1;
        }
        .no-results { color: var(--muted); font-size: 15px; padding: 20px 6px; text-align: center; }

        /* ---- [F4] Quantity badge ---- */
        .qty { display: inline-block; margin-left: 8px; font-size: 12px; font-weight: 700; color: var(--muted); background: #F0F0EF; border-radius: 999px; padding: 2px 8px; white-space: nowrap; vertical-align: middle; }
        .qty.low { color: #92400E; background: #FEF3C7; }

        /* ---- [F4] Collapsible category head ---- */
        .cat-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; cursor: pointer; user-select: none; }
        .cat-head .chev { font-size: 13px; color: var(--muted); transition: transform .15s; }
        .cat-head.collapsed .chev { transform: rotate(-90deg); }
        .cat-head .cat-count { font-size: 12px; font-weight: 600; color: var(--muted); }

        /* ---- [F5] 2-tap rupture confirm ---- */
        .row-actions { display: flex; align-items: center; gap: 8px; }
        .toggle.confirming { background: var(--accent); color: var(--dark); }
        .cancel-btn {
            min-height: 44px; padding: 0 12px; border-radius: 12px; border: 1px solid var(--line);
            background: var(--card); color: var(--muted); font-size: 14px; font-weight: 700; cursor: pointer;
        }
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
            <div class="search-wrap">
                <input id="stock-search" class="search-input" type="search" inputmode="search"
                    autocomplete="off" autocorrect="off" autocapitalize="none" spellcheck="false"
                    placeholder="🔍 Rechercher un produit ou ingrédient…" aria-label="Rechercher un produit ou ingrédient">
                <button id="search-clear" class="search-clear hidden" type="button" aria-label="Effacer la recherche">✕</button>
            </div>
            <div class="section-title">🛒 À acheter</div>
            <div class="buy-card" id="buy-card"><div class="buy-empty">Chargement…</div></div>
            <div class="section-title">🍔 Produits</div>
            <div id="catalog"><div class="loading">Chargement du catalogue…</div></div>
            <div class="section-title">🧂 Ingrédients / sauces</div>
            <div id="ingredients"><div class="loading">Chargement des ingrédients…</div></div>
            <div id="no-results" class="no-results hidden">Aucun résultat pour « <span id="no-results-q"></span> ».</div>
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

        // ---- Stock catalog (state-driven : recherche + repli + quantités + 2-taps rupture) ----
        // [F4/F5 2026-07-24] Le catalogue chargé est gardé en mémoire (state) : la
        // recherche filtre EN DIRECT sans appel réseau, les sections se plient/déplient
        // (état local), les quantités (on_hand) s'affichent NULL-safe, et couper un
        // produit (RUPTURE) demande une confirmation 2-taps (anti mis-tap tactile).
        var state = { categories: [], ingredients: [], query: '', collapsed: {} };

        function loadCatalog() {
            var cat = document.getElementById('catalog');
            var ing = document.getElementById('ingredients');
            cat.innerHTML = '<div class="loading">Chargement du catalogue…</div>';
            ing.innerHTML = '<div class="loading">Chargement des ingrédients…</div>';
            fetch('{{ url('/m/api/catalog') }}', {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin'
            }).then(function (r) {
                if (r.status === 401) { showPin(); return null; }
                return r.json();
            }).then(function (data) {
                if (!data) return;
                state.categories = data.categories || [];
                state.ingredients = data.ingredients || [];
                renderShopping(data.shopping || []);
                renderAll();
            }).catch(function () {
                cat.innerHTML = '<div class="loading">Erreur de chargement.</div>';
                ing.innerHTML = '';
            });
        }
        function normalizeSearch(s) {
            return String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
        }
        function matchesQuery(name) {
            if (!state.query) return true;
            return normalizeSearch(name).indexOf(state.query) !== -1;
        }
        function renderShopping(list) {
            var box = document.getElementById('buy-card');
            box.textContent = '';
            if (!list.length) {
                var e = document.createElement('div');
                e.className = 'buy-empty';
                e.textContent = 'Aucune rupture signalée (produits & ingrédients) ✅';
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
        function setBtn(btn, available) {
            btn.className = 'toggle ' + (available ? 'on' : 'off');
            btn.textContent = available ? 'EN STOCK' : 'RUPTURE';
            delete btn.dataset.arm;
        }
        // [F4] Badge quantité — NULL-safe : rien si on_hand inconnu (null/undefined).
        function qtyBadge(onHand) {
            if (onHand === null || typeof onHand === 'undefined') return null;
            var q = document.createElement('span');
            q.className = 'qty' + (Number(onHand) <= 0 ? ' low' : '');
            q.textContent = onHand + ' en stock';
            return q;
        }
        // [F5] Arme / désarme la confirmation 2-taps de rupture sur un bouton.
        function disarm(btn, actions, it) {
            if (btn._armTimer) { clearTimeout(btn._armTimer); btn._armTimer = null; }
            var cancel = actions.querySelector('.cancel-btn');
            if (cancel) cancel.remove();
            setBtn(btn, it.is_available);
        }
        function arm(btn, actions, it) {
            btn.dataset.arm = '1';
            btn.className = 'toggle confirming';
            btn.textContent = '⚠️ Confirmer la rupture ?';
            if (!actions.querySelector('.cancel-btn')) {
                var cancel = document.createElement('button');
                cancel.type = 'button'; cancel.className = 'cancel-btn'; cancel.textContent = 'Annuler';
                cancel.addEventListener('click', function (ev) { ev.stopPropagation(); disarm(btn, actions, it); });
                actions.insertBefore(cancel, btn);
            }
            btn._armTimer = setTimeout(function () { disarm(btn, actions, it); }, 4000);
        }
        // Construit une ligne produit/ingrédient (nom + quantité + bouton 2-taps).
        function buildRow(it, doToggle) {
            var row = document.createElement('div'); row.className = 'row';
            var nm = document.createElement('div'); nm.className = 'name'; nm.textContent = it.name;
            var badge = qtyBadge(it.on_hand);
            if (badge) nm.appendChild(badge);
            var actions = document.createElement('div'); actions.className = 'row-actions';
            var btn = document.createElement('button'); btn.type = 'button';
            setBtn(btn, it.is_available);
            actions.appendChild(btn);
            btn.addEventListener('click', function () {
                if (btn.disabled) return;
                // Remise EN STOCK (réversible) = 1 tap direct.
                if (!it.is_available) { doToggle(true, btn); return; }
                // Passage en RUPTURE = 2-taps (confirmation anti mis-tap).
                if (btn.dataset.arm === '1') { disarm(btn, actions, it); doToggle(false, btn); }
                else { arm(btn, actions, it); }
            });
            row.appendChild(nm); row.appendChild(actions);
            return row;
        }
        // Bloc catégorie repliable générique (produits ou ingrédients).
        function renderGroups(wrapId, groups, keyPrefix, nameOf, emptyMsg, doToggleFor) {
            var wrap = document.getElementById(wrapId);
            wrap.textContent = '';
            if (!groups.length) {
                var e0 = document.createElement('div'); e0.className = 'loading';
                e0.textContent = emptyMsg; wrap.appendChild(e0); return;
            }
            var searching = !!state.query;
            groups.forEach(function (g) {
                var gname = nameOf(g);
                var items = (g.items || []).filter(function (it) { return matchesQuery(it.name); });
                if (!items.length) return;
                var key = keyPrefix + gname;
                var collapsed = !searching && !!state.collapsed[key];
                var block = document.createElement('div'); block.className = 'cat-block';
                var head = document.createElement('div'); head.className = 'cat-head' + (collapsed ? ' collapsed' : '');
                var title = document.createElement('span'); title.textContent = gname;
                var right = document.createElement('span'); right.className = 'cat-count';
                right.textContent = items.length;
                var chev = document.createElement('span'); chev.className = 'chev'; chev.textContent = ' ▾';
                right.appendChild(chev);
                head.appendChild(title); head.appendChild(right);
                head.addEventListener('click', function () {
                    state.collapsed[key] = !state.collapsed[key];
                    renderAll();
                });
                block.appendChild(head);
                var body = document.createElement('div');
                if (collapsed) body.className = 'hidden';
                items.forEach(function (it) { body.appendChild(buildRow(it, doToggleFor(g, it))); });
                block.appendChild(body);
                wrap.appendChild(block);
            });
        }
        function renderCatalog() {
            renderGroups('catalog', state.categories, 'c:',
                function (c) { return c.name; }, 'Aucun produit actif.',
                function (c, it) { return function (next, btn) { toggleItem(it.id, next, btn, it); }; });
        }
        function renderIngredients() {
            renderGroups('ingredients', state.ingredients, 'i:',
                function (g) { return g.group; }, 'Aucun ingrédient.',
                function (g, it) { return function (next, btn) { toggleIngredient(g.kind, it, btn, next); }; });
        }
        function renderAll() {
            renderCatalog();
            renderIngredients();
            // Bandeau « aucun résultat » quand la recherche ne matche rien (produits + ingrédients).
            var anyMatch = false;
            state.categories.forEach(function (c) { (c.items || []).forEach(function (it) { if (matchesQuery(it.name)) anyMatch = true; }); });
            state.ingredients.forEach(function (g) { (g.items || []).forEach(function (it) { if (matchesQuery(it.name)) anyMatch = true; }); });
            var nr = document.getElementById('no-results');
            if (state.query && !anyMatch) {
                document.getElementById('no-results-q').textContent = state.query;
                nr.classList.remove('hidden');
            } else {
                nr.classList.add('hidden');
            }
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
        function toggleIngredient(kind, it, btn, next) {
            if (btn.disabled) return;
            btn.disabled = true;
            if (typeof next === 'undefined') next = !it.is_available;
            fetch('{{ url('/m/api/toggle-extra') }}', {
                method: 'POST', headers: jsonHeaders(), credentials: 'same-origin',
                body: JSON.stringify({ kind: kind, ids: it.ids || [], is_available: next })
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
                    toast((res.data && res.data.message) || 'Échec de la mise à jour');
                }
            }).catch(function () {
                btn.disabled = false; toast('Erreur réseau');
            });
        }
        // ---- [F4] Recherche live (filtre le catalogue déjà chargé, aucun appel réseau) ----
        (function () {
            var input = document.getElementById('stock-search');
            var clear = document.getElementById('search-clear');
            if (!input) return;
            input.addEventListener('input', function () {
                state.query = normalizeSearch(input.value);
                clear.classList.toggle('hidden', input.value === '');
                renderAll();
            });
            clear.addEventListener('click', function () {
                input.value = ''; state.query = ''; clear.classList.add('hidden');
                renderAll(); input.focus();
            });
        })();

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
