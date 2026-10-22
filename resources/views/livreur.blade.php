<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#F4501E">
    <title>Livreur — Le Cayenne</title>
    {{-- [LIVRAISON 2026-10-20] Écran du livreur : page 100 % autonome (CSS inline + JS natif, PAS la SPA
         admin, PAS de build Mix), pensée pour le téléphone. Se connecte avec le compte livreur, appelle
         /api/frontend/delivery-run. La remise passe par la voie officielle (encaissement + fiscal intacts). --}}
    <style>
        :root { --brand:#F4501E; --accent:#FFB800; --dark:#1A1A1A; --ok:#1B9E4B; --bad:#D92D20; --bg:#F5F5F4; --card:#FFFFFF; --line:#E4E4E7; --muted:#6B7280; }
        * { box-sizing:border-box; -webkit-tap-highlight-color:transparent; }
        html,body { margin:0; padding:0; }
        body { font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; background:var(--bg); color:var(--dark); line-height:1.4;
               padding:env(safe-area-inset-top) env(safe-area-inset-right) env(safe-area-inset-bottom) env(safe-area-inset-left); }
        .hidden { display:none !important; }
        header { position:sticky; top:0; z-index:5; background:var(--brand); color:#fff; display:flex; align-items:center; justify-content:space-between; padding:14px 16px; box-shadow:0 2px 6px rgba(0,0,0,.15); }
        header h1 { margin:0; font-size:18px; font-weight:800; }
        .ghost { min-height:40px; padding:8px 14px; border-radius:10px; border:1px solid rgba(255,255,255,.55); background:transparent; color:#fff; font-size:14px; font-weight:600; }
        main { padding:12px 12px 48px; max-width:640px; margin:0 auto; }
        #login { min-height:100dvh; display:flex; flex-direction:column; justify-content:center; padding:24px; gap:14px; max-width:420px; margin:0 auto; }
        #login h2 { color:var(--brand); margin:0 0 6px; font-size:24px; text-align:center; }
        input[type=email],input[type=password] { width:100%; min-height:52px; padding:12px 14px; font-size:17px; border:1px solid var(--line); border-radius:12px; background:#fff; }
        .btn { display:flex; align-items:center; justify-content:center; gap:8px; width:100%; min-height:56px; padding:12px 16px; border:none; border-radius:14px; font-size:17px; font-weight:700; background:var(--brand); color:#fff; text-decoration:none; }
        .btn.sec { background:#fff; color:var(--dark); border:1px solid var(--line); }
        .btn.ok { background:var(--ok); }
        .btn:disabled,.btn.off { opacity:.45; pointer-events:none; }
        .err { color:var(--bad); font-weight:600; min-height:20px; text-align:center; }
        /* [relecture adverse P-4] Le message (« commande(s) pas prête(s) ») était hors cadre quand on touche « Je pars » plus bas : il reste collé sous l'en-tête. */
        #app-err:not(:empty) { position:sticky; top:68px; z-index:4; background:#FDECEC; border:1px solid var(--bad); border-radius:10px; padding:8px 12px; margin-bottom:8px; }
        .wave { margin:16px 0 8px; display:flex; align-items:center; justify-content:space-between; gap:10px; }
        .wave h3 { margin:0; font-size:20px; }
        .chip { display:inline-block; padding:3px 10px; border-radius:999px; font-size:13px; font-weight:700; background:#EEE; color:var(--dark); }
        .chip.go { background:#FFF1D6; color:#8A5A00; } .chip.done { background:#DDF5E5; color:#126B33; } .chip.wait { background:#FDE4E1; color:#9B1C12; }
        .stop { background:var(--card); border:1px solid var(--line); border-radius:16px; padding:14px; margin:10px 0; display:flex; flex-direction:column; gap:10px; }
        .stop.delivered { opacity:.6; }
        .row { display:flex; align-items:center; justify-content:space-between; gap:8px; }
        .seq { width:34px; height:34px; border-radius:50%; background:var(--dark); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:800; flex:0 0 auto; }
        .addr { font-size:18px; font-weight:700; }
        .muted { color:var(--muted); font-size:14px; }
        .collect { border-radius:12px; padding:10px 12px; font-weight:800; font-size:18px; }
        .collect.pay { background:#FFF1D6; border:2px solid var(--accent); } .collect.paid { background:#EAF7EE; color:#126B33; }
        /* [VG-2026-10-21 M4-102] Commande annulée en caisse pendant la tournée : impossible à rater, rien à remettre. */
        .chip.bad { background:var(--bad); color:#fff; } .collect.cancel { background:#FDECEC; border:2px solid var(--bad); color:#9B1C12; }
        .stop.cancelled { border:2px solid var(--bad); }
        .grid2 { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
        a.tel { color:var(--dark); font-weight:700; }
        .empty { text-align:center; color:var(--muted); padding:48px 16px; }
    </style>
</head>
<body>
<section id="login">
    <h2>Le Cayenne · Livreur</h2>
    <input id="email" type="email" placeholder="Adresse e-mail" autocomplete="username" inputmode="email">
    <input id="password" type="password" placeholder="Mot de passe" autocomplete="current-password">
    <button class="btn" id="login-btn">Se connecter</button>
    <div class="err" id="login-err" role="alert"></div>
</section>

<section id="app" class="hidden">
    <header><h1>Mes livraisons</h1><button class="ghost" id="logout">Quitter</button></header>
    <main>
        <div class="err" id="app-err" role="alert"></div>
        <div id="waves"></div>
        <div id="empty" class="empty hidden">Aucune livraison pour le moment.<br>Les tournées apparaissent ici dès qu'une commande vous est attribuée.</div>
    </main>
</section>

<script>
(function () {
    'use strict';
    var API_KEY = @json((string) config('app.api_key'));
    var KEY = 'lc_livreur_token';
    var token = null;
    try { token = localStorage.getItem(KEY); } catch (e) {}

    var $ = function (id) { return document.getElementById(id); };
    function uuid() { return (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : String(Date.now()) + Math.random().toString(16).slice(2); }
    function eur(n) { return Number(n).toFixed(2).replace('.', ',') + ' €'; }
    function hm(s) { return s ? s.slice(11, 16) : ''; }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]; }); }

    function api(method, path, body, isForm) {
        var headers = { 'Accept': 'application/json', 'X-API-KEY': API_KEY };
        if (token) headers['Authorization'] = 'Bearer ' + token;
        if (method !== 'GET') headers['X-Idempotency-Key'] = uuid();
        if (body && !isForm) { headers['Content-Type'] = 'application/json'; body = JSON.stringify(body); }
        return fetch(path, { method: method, headers: headers, body: body }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (j) {
                if (r.status === 401) { logout(); throw new Error('Session expirée, reconnectez-vous.'); }
                if (!r.ok) throw new Error(j.message || (j.errors && Object.values(j.errors)[0]) || 'Erreur ' + r.status);
                return j;
            });
        });
    }

    function show(loggedIn) { $('login').classList.toggle('hidden', loggedIn); $('app').classList.toggle('hidden', !loggedIn); }
    function logout() { token = null; try { localStorage.removeItem(KEY); } catch (e) {} show(false); }

    $('login-btn').addEventListener('click', function () {
        $('login-err').textContent = '';
        api('POST', '/api/auth/login', { email: $('email').value.trim(), password: $('password').value }).then(function (j) {
            token = j.token; try { localStorage.setItem(KEY, token); } catch (e) {}
            show(true); load();
        }).catch(function (e) { $('login-err').textContent = e.message; });
    });
    $('logout').addEventListener('click', logout);

    function stopHtml(s) {
        var done = !!s.delivered_at;
        // [VG-2026-10-21 M4-102] Annulée en caisse alors que le livreur est en route : la carte restait « En route · Déjà payé »
        // avec « Remis au client ». Bandeau rouge, aucune action de remise ni d'encaissement (le serveur refuse aussi : 422).
        if (s.cancelled && !done) {
            return '<div class="stop cancelled" data-cancelled="1">'
                + '<div class="row"><div class="seq">' + s.sequence + '</div><div style="flex:1"><div class="addr">' + esc(s.address || 'Adresse inconnue') + '</div>'
                + '<div class="muted">' + esc(s.postal_code || '') + ' ' + esc(s.city || '') + '</div></div><span class="chip bad">ANNULÉE</span></div>'
                + '<div class="row"><span>#' + esc(s.order_number || s.order_id) + ' · ' + esc(s.customer_name || 'Client') + '</span></div>'
                + '<div class="collect cancel" role="alert">ANNULÉE — ne pas remettre. Rapportez la commande au restaurant, n\'encaissez rien.</div></div>';
        }
        var state = done ? '<span class="chip done">Remise ' + hm(s.delivered_at) + '</span>'
                  : s.arrived_at ? '<span class="chip go">Arrivé ' + hm(s.arrived_at) + '</span>'
                  : s.left_at ? '<span class="chip go">En route · ETA ' + hm(s.eta_at) + '</span>'
                  : s.ready ? '<span class="chip">Prête · ETA ' + hm(s.eta_at) + '</span>'
                  : '<span class="chip wait">En préparation</span>';
        // [Supervision E2E 2026-10-21 · P-5] Une remise terminée n'a plus rien à encaisser : l'arrêt livré affichait encore
        // « Encaisser en espèces : 4,90 € » à côté de « Remise 21:42 ».
        var collect = done
            ? '<div class="collect paid">✓ Remise terminée</div>'
            : s.amount_due_cents > 0
            ? '<div class="collect pay">' + esc(s.collect_label) + ' : ' + eur(s.amount_due) + '</div>'
            : '<div class="collect paid">✓ Déjà payé</div>';
        var actions = '';
        if (!done && s.left_at) {
            actions = '<div class="grid2">'
                + '<label class="btn sec" style="cursor:pointer">📷 ' + (s.has_photo ? 'Photo prise' : 'Arrivé + photo')
                + '<input type="file" accept="image/*" capture="environment" class="hidden" data-arrive="' + s.id + '"></label>'
                + '<button class="btn ok" data-deliver="' + s.id + '" data-due="' + s.amount_due_cents + '">Remis au client</button></div>';
        }
        var nav = s.waze_url ? '<div class="grid2"><a class="btn" href="' + esc(s.waze_url) + '" target="_blank" rel="noopener">Waze</a>'
                              + '<a class="btn sec" href="' + esc(s.maps_url) + '" target="_blank" rel="noopener">Plans</a></div>' : '';
        return '<div class="stop' + (done ? ' delivered' : '') + '">'
            + '<div class="row"><div class="seq">' + s.sequence + '</div><div style="flex:1"><div class="addr">' + esc(s.address || 'Adresse inconnue') + (s.apartment ? ' · ' + esc(s.apartment) : '') + '</div>'
            + '<div class="muted">' + esc(s.postal_code || '') + ' ' + esc(s.city || '') + '</div></div>' + state + '</div>'
            + '<div class="row"><span>#' + esc(s.order_number || s.order_id) + ' · ' + esc(s.customer_name || 'Client') + '</span>'
            + (s.customer_phone ? '<a class="tel" href="tel:' + esc(s.customer_phone) + '">📞 ' + esc(s.customer_phone) + '</a>' : '') + '</div>'
            + collect + (done ? '' : nav) + actions + '</div>';
    }

    function render(waves) {
        $('empty').classList.toggle('hidden', waves.length > 0);
        $('waves').innerHTML = waves.map(function (w) {
            var pending = w.stops.filter(function (s) { return !s.left_at && !s.delivered_at; });
            var ready = pending.filter(function (s) { return s.ready; }).length;
            var head = '<div class="wave"><h3>Départ ' + w.time + ' · ' + w.stops.length + ' livraison' + (w.stops.length > 1 ? 's' : '') + '</h3></div>';
            var go = pending.length
                ? '<button class="btn" data-depart="' + w.wave_at + '"' + (ready ? '' : ' disabled') + '>Je pars (' + ready + '/' + pending.length + ' prête' + (ready > 1 ? 's' : '') + ')</button>' : '';
            return head + go + w.stops.map(stopHtml).join('');
        }).join('');
    }

    function load(keepMessage) {
        return api('GET', '/api/frontend/delivery-run').then(function (j) { if (!keepMessage) $('app-err').textContent = ''; render(j.data || []); })
            .catch(function (e) { $('app-err').textContent = e.message; });
    }

    function position() {
        return new Promise(function (res) {
            if (!navigator.geolocation) return res({});
            navigator.geolocation.getCurrentPosition(function (p) { res({ lat: p.coords.latitude, lng: p.coords.longitude }); }, function () { res({}); }, { timeout: 2500, maximumAge: 60000 });
        });
    }

    document.addEventListener('click', function (e) {
        var t = e.target.closest('[data-depart],[data-deliver]');
        if (!t) return;
        if (t.dataset.depart) {
            api('POST', '/api/frontend/delivery-run/depart', { wave_at: t.dataset.depart }).then(function (j) {
                // [Supervision E2E 2026-10-21 · P-4] load() effaçait ce message dans la foulée : le livreur partait sans savoir
                // qu'une commande restait au restaurant. Le rechargement garde désormais le message qu'on vient d'afficher.
                var notReady = (j.not_ready && j.not_ready.length) ? j.not_ready.length + ' commande(s) pas encore prête(s) : elles restent à prendre.' : '';
                if (notReady) $('app-err').textContent = notReady;
                load(!!notReady);
            }).catch(function (er) { $('app-err').textContent = er.message; });
        } else if (t.dataset.deliver) {
            var due = Number(t.dataset.due || 0);
            if (due > 0 && !window.confirm('Confirmez-vous avoir encaissé ' + eur(due / 100) + ' ?')) return;
            api('POST', '/api/frontend/delivery-run/stops/' + t.dataset.deliver + '/deliver', due > 0 ? { collected_cents: due } : {})
                .then(function () { return load(); }).catch(function (er) { $('app-err').textContent = er.message; });
        }
    });

    document.addEventListener('change', function (e) {
        var inp = e.target.closest('[data-arrive]');
        if (!inp || !inp.files.length) return;
        var lbl = inp.closest('label');
        if (lbl) { lbl.firstChild.textContent = '⏳ Envoi… '; lbl.classList.add('off'); }
        position().then(function (pos) {
            var fd = new FormData();
            fd.append('photo', inp.files[0]);
            if (pos.lat != null) { fd.append('lat', pos.lat); fd.append('lng', pos.lng); }
            return api('POST', '/api/frontend/delivery-run/stops/' + inp.dataset.arrive + '/arrive', fd, true);
        }).then(function () { return load(); }).catch(function (er) { $('app-err').textContent = er.message; if (lbl) { lbl.classList.remove('off'); } load(); });
    });

    show(!!token);
    if (token) load();
    setInterval(function () { if (token && !document.hidden) load(); }, 30000);
})();
</script>
</body>
</html>
