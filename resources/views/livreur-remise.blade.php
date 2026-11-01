<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#F4501E">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="referrer" content="no-referrer">
    <title>Remise livreur — Le Cayenne</title>
    {{-- [LIVRAISON QR 2026-11-01] Page de REMISE par QR : le livreur scanne le QR du ticket avec l'appareil photo de son téléphone,
         arrive ici (hôte de la plateforme), SE CONNECTE TOUJOURS (e-mail + mot de passe), voit l'adresse, déclare comment la
         commande a été payée à la porte, puis touche « Remis ».
         • Le jeton est dans le FRAGMENT de l'URL (`#…`) : jamais envoyé au serveur ni aux journaux. Cette page le lit UNE fois,
           l'efface aussitôt de la barre d'adresse (history.replaceState) et ne le garde qu'en mémoire (pas de stockage).
         • Cette page ignore la session /livreur du navigateur : elle ne lit AUCUN stockage local, ne réutilise aucune connexion.
         • Avant la connexion, la page ne contient AUCUNE donnée : même HTML pour tout le monde (jeton valide ou non). --}}
    <style>
        :root { --brand:#F4501E; --accent:#FFB800; --dark:#1A1A1A; --ok:#1B9E4B; --bad:#D92D20; --bg:#F5F5F4; --card:#FFFFFF; --line:#E4E4E7; --muted:#6B7280; }
        * { box-sizing:border-box; -webkit-tap-highlight-color:transparent; }
        html,body { margin:0; padding:0; }
        body { font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; background:var(--bg); color:var(--dark); line-height:1.4;
               padding:env(safe-area-inset-top) env(safe-area-inset-right) env(safe-area-inset-bottom) env(safe-area-inset-left); }
        .hidden { display:none !important; }
        header { background:var(--brand); color:#fff; padding:14px 16px; box-shadow:0 2px 6px rgba(0,0,0,.15); }
        header h1 { margin:0; font-size:18px; font-weight:800; }
        main { padding:16px 14px 56px; max-width:520px; margin:0 auto; display:flex; flex-direction:column; gap:14px; }
        h2 { margin:0; font-size:22px; }
        h2:focus { outline:none; }
        p { margin:0; }
        label.f { display:flex; flex-direction:column; gap:6px; font-weight:700; font-size:15px; }
        input[type=email],input[type=password] { width:100%; min-height:56px; padding:12px 14px; font-size:18px; border:1px solid var(--line); border-radius:12px; background:#fff; }
        input:focus-visible,button:focus-visible,a:focus-visible { outline:3px solid var(--accent); outline-offset:2px; }
        .btn { display:flex; align-items:center; justify-content:center; gap:8px; width:100%; min-height:60px; padding:12px 16px; border:none; border-radius:14px; font-size:19px; font-weight:800; background:var(--brand); color:#fff; text-decoration:none; cursor:pointer; }
        .btn.sec { background:#fff; color:var(--dark); border:2px solid var(--line); }
        .btn.ok { background:var(--ok); }
        .btn:disabled,.btn.off { opacity:.45; pointer-events:none; }
        .err { color:var(--bad); font-weight:700; min-height:22px; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:16px; padding:16px; display:flex; flex-direction:column; gap:12px; }
        .addr { font-size:21px; font-weight:800; }
        .muted { color:var(--muted); font-size:15px; }
        .grid2 { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
        .modes { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
        .modes.one { grid-template-columns:1fr; }
        .mode { min-height:72px; border-radius:14px; border:3px solid var(--line); background:#fff; font-size:20px; font-weight:800; color:var(--dark); cursor:pointer; padding:8px; }
        .mode[aria-pressed=true] { border-color:var(--ok); background:#EAF7EE; color:#126B33; }
        .due { border-radius:12px; padding:12px 14px; font-weight:800; font-size:20px; background:#FFF1D6; border:2px solid var(--accent); }
        .due.paid { background:#EAF7EE; border-color:#BFE5CB; color:#126B33; }
        .alert { border-radius:12px; padding:12px 14px; font-weight:700; background:#FDECEC; border:2px solid var(--bad); color:#9B1C12; }
        .big { text-align:center; padding:28px 12px; }
        .big .tick { font-size:56px; line-height:1; color:var(--ok); }
        a.tel { color:var(--dark); font-weight:800; }
    </style>
</head>
<body>
<header><h1>Le Cayenne · Remise livreur</h1></header>
<main id="main" aria-live="polite">
    {{-- Écran de départ : identique pour tout le monde (aucune donnée). --}}
    <div class="card" id="boot"><p class="muted">Chargement…</p></div>
</main>

<script>
(function () {
    'use strict';
    var API_KEY = @json((string) config('app.api_key'));
    var NEXT = '/livreur';                       // cible FIXE : jamais une valeur venue du serveur ou de l'URL
    var TOKEN_RE = /^[A-Za-z0-9_-]{43}$/;

    // 1) Le jeton vient du fragment, et SEULEMENT de lui. On l'efface aussitôt de la barre d'adresse (historique compris).
    var qr = (location.hash || '').replace(/^#/, '');
    try { history.replaceState(null, '', location.pathname); } catch (e) { /* ancien navigateur : le fragment reste, sans conséquence serveur */ }
    if (!TOKEN_RE.test(qr)) qr = '';

    var session = null;          // jeton court de connexion : en MÉMOIRE seulement
    var stop = null;             // données de l'arrêt (après connexion)
    var mode = null;             // mode déclaré
    var idem = null;             // clé d'idempotence d'UNE tentative de validation
    var busy = false;

    var main = document.getElementById('main');
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]; }); }
    function eur(cents) { return (Number(cents) / 100).toFixed(2).replace('.', ',') + ' €'; }
    function uuid() { return (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : String(Date.now()) + Math.random().toString(16).slice(2); }
    function hm(s) { return s ? String(s).slice(11, 16) : ''; }
    function safeUrl(u, prefixes) { u = String(u || ''); return prefixes.some(function (p) { return u.indexOf(p) === 0; }) ? u : ''; }
    function render(html) { main.innerHTML = html; var h = main.querySelector('h2'); if (h) { h.setAttribute('tabindex', '-1'); h.focus({ preventScroll: true }); } }

    function api(path, body, withIdem) {
        var headers = { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-API-KEY': API_KEY };
        if (session) headers['Authorization'] = 'Bearer ' + session;
        if (withIdem) headers['X-Idempotency-Key'] = withIdem;
        return fetch('/api/frontend/delivery-handover/' + path, { method: 'POST', headers: headers, body: JSON.stringify(body), credentials: 'omit', referrerPolicy: 'no-referrer' })
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { status: r.status, body: j }; }); });
    }

    // ── écrans ──────────────────────────────────────────────────────────────────────────────────
    function refusal(msg) {
        // Écran de refus : ne dit RIEN de la commande (ni adresse, ni nom, ni contenu).
        render('<div class="card" data-testid="refus"><h2>QR non valable</h2><p>' + esc(msg || 'Ce QR n\'est plus valable.') + '</p>'
            + '<p class="muted">Utilisez l\'écran Livreur ou voyez la caisse.</p><a class="btn sec" href="' + NEXT + '">Mes livraisons</a></div>');
    }

    var lastEmail = '';
    function loginScreen(message) {
        render('<form class="card" id="f" novalidate data-testid="connexion"><h2>Connexion livreur</h2>'
            + '<p class="muted">Pour valider cette remise, connectez-vous avec <b>votre</b> compte livreur.</p>'
            + '<label class="f">Adresse e-mail<input id="email" type="email" autocomplete="username" inputmode="email" autocapitalize="off" value="' + esc(lastEmail) + '" required></label>'
            + '<label class="f">Mot de passe<input id="password" type="password" autocomplete="current-password" required></label>'
            + '<button class="btn" id="go" type="submit">Se connecter</button>'
            + '<div class="err" id="err" role="alert">' + esc(message || '') + '</div></form>');
        document.getElementById('f').addEventListener('submit', function (e) {
            e.preventDefault();
            if (busy) return;
            var email = document.getElementById('email').value.trim(), pw = document.getElementById('password').value;
            lastEmail = email; // gardé EN MÉMOIRE seulement pour ne pas le retaper après une erreur
            var err = document.getElementById('err');
            if (!email || !pw) { err.textContent = 'Saisissez votre e-mail et votre mot de passe.'; return; }
            busy = true; document.getElementById('go').classList.add('off'); err.textContent = '';
            session = null;
            api('login', { email: email, password: pw }).then(function (r) {
                busy = false;
                if (r.status === 201 && r.body.token) { session = r.body.token; return load(); }
                loginScreen(r.body.message || 'Connexion impossible.');
            }).catch(function () { busy = false; loginScreen('Pas de réseau. Réessayez.'); });
        });
    }

    function load() {
        busy = true;
        return api('resolve', { token: qr }).then(function (r) {
            busy = false;
            if (r.status === 401) { session = null; return loginScreen('Session expirée : reconnectez-vous.'); }
            if (r.status === 403) { session = null; return loginScreen(r.body.message || 'Connectez-vous avec votre compte livreur.'); }
            if (r.status !== 200 || !r.body.data) return refusalFor(r);
            stop = r.body.data;
            if (stop.state === 'delivered') return doneScreen(stop.delivered_at, true);
            mode = (stop.allowed_modes || []).indexOf(stop.chosen_mode) !== -1 ? stop.chosen_mode : null;
            idem = uuid();
            stopScreen();
        }).catch(function () { busy = false; loginScreen('Pas de réseau. Réessayez.'); });
    }

    function refusalFor(r) {
        // 409 (annulée, pas encore parti, déjà remise) : le message est adressé au livreur attribué, il n'y a rien d'autre à montrer.
        if (r.status === 409 || r.status === 422) return refusal(r.body.message);
        return refusal(r.body.message);
    }

    var LABELS = { cash: 'Espèces', card: 'Carte', meal_voucher: 'Titre-restaurant' };

    function stopScreen(msg) {
        var s = stop, modes = s.allowed_modes || [], due = Number(s.amount_due_cents || 0);
        var waze = safeUrl(s.waze_url, ['https://waze.com/ul?']), maps = safeUrl(s.maps_url, ['https://www.google.com/maps/dir/?']);
        var tel = String(s.customer_phone || '').replace(/[^0-9+]/g, '');
        var html = '<div class="card" data-testid="arret"><h2>Livraison #' + esc(s.order_number) + '</h2>'
            + '<div class="addr">' + esc(s.address || 'Adresse inconnue') + (s.apartment ? ' · ' + esc(s.apartment) : '') + '</div>'
            + (s.postal_code || s.city ? '<div class="muted">' + esc(s.postal_code || '') + ' ' + esc(s.city || '') + '</div>' : '')
            + (waze ? '<div class="grid2"><a class="btn" href="' + esc(waze) + '" target="_blank" rel="noopener noreferrer">Waze</a>'
                + (maps ? '<a class="btn sec" href="' + esc(maps) + '" target="_blank" rel="noopener noreferrer">Plans</a>' : '<span></span>') + '</div>' : '')
            + '<div>' + esc(s.customer_name || 'Client') + (tel ? ' · <a class="tel" href="tel:' + esc(tel) + '">' + esc(s.customer_phone) + '</a>' : '') + '</div></div>';

        if (due > 0 && modes.length) {
            html += '<div class="card"><div class="due" data-testid="du">À encaisser : ' + eur(due) + '</div>'
                + '<p class="muted">Choix du client : ' + esc(String(s.collect_label || '').replace(/^Encaisser (en |par )?/, '')) + '.</p>'
                + '<p class="muted"><b>Comment le client a-t-il réellement payé à la porte ?</b></p>'
                + '<div class="modes' + (modes.length === 1 ? ' one' : '') + '" role="group" aria-label="Mode de paiement encaissé">'
                + modes.map(function (m) { return '<button type="button" class="mode" data-mode="' + esc(m) + '" aria-pressed="' + (mode === m ? 'true' : 'false') + '">' + esc(LABELS[m] || m) + '</button>'; }).join('')
                + '</div></div>';
        } else if (due > 0) {
            html += '<div class="alert" role="alert">Cette commande ne peut pas être encaissée à la porte. Renvoyez-la à la caisse.</div>';
        } else {
            html += '<div class="due paid" data-testid="du">✓ Déjà payé : rien à encaisser</div>';
        }
        var canGo = (due === 0) || (due > 0 && modes.length && mode);
        html += '<button class="btn ok" id="remis"' + (canGo ? '' : ' disabled') + '>Remis au client</button>'
            + '<div class="err" id="err" role="alert">' + esc(msg || '') + '</div>';
        render(html);
        Array.prototype.forEach.call(main.querySelectorAll('[data-mode]'), function (b) {
            b.addEventListener('click', function () { mode = b.getAttribute('data-mode'); stopScreen(); });
        });
        var go = document.getElementById('remis');
        if (go) go.addEventListener('click', validate);
    }

    function position() {
        // Facultative et jamais bloquante : refus, absence ou lenteur ⇒ on valide sans position.
        return new Promise(function (res) {
            var done = false, t = setTimeout(function () { if (!done) { done = true; res({}); } }, 2500);
            try {
                if (!navigator.geolocation) { clearTimeout(t); return res({}); }
                navigator.geolocation.getCurrentPosition(function (p) { if (done) return; done = true; clearTimeout(t); res({ lat: p.coords.latitude, lng: p.coords.longitude, accuracy: p.coords.accuracy }); },
                    function () { if (done) return; done = true; clearTimeout(t); res({}); }, { timeout: 2500, maximumAge: 60000 });
            } catch (e) { clearTimeout(t); res({}); }
        });
    }

    function validate() {
        if (busy) return;
        var due = Number(stop.amount_due_cents || 0);
        busy = true;
        var go = document.getElementById('remis'); if (go) go.classList.add('off');
        position().then(function (pos) {
            var body = { token: qr };
            if (due > 0) { body.declared_mode = mode; body.collected_cents = due; }
            if (pos.lat != null) { body.lat = pos.lat; body.lng = pos.lng; body.accuracy = pos.accuracy; }
            return api('confirm', body, idem);
        }).then(function (r) {
            busy = false;
            if (r.status === 200 && r.body.status) { return doneScreen(r.body.data && r.body.data.delivered_at, !!r.body.already); }
            if (r.status === 401) { session = null; return loginScreen('Session expirée : reconnectez-vous, puis validez à nouveau.'); }
            if (r.status === 404 || r.status === 409) return refusal(r.body.message);
            if (r.status === 429) return stopScreen(r.body.message || 'Trop de tentatives. Patientez une minute.');
            idem = uuid(); // refus 422/500 : rien n'a été enregistré, la prochaine tentative est une nouvelle demande
            stopScreen(r.body.message || 'La remise n\'a pas pu être enregistrée.');
        }).catch(function () {
            busy = false; // réseau perdu : on GARDE la même clé, un nouvel appui rejoue la même demande sans doublon
            stopScreen('Pas de réseau. Touchez de nouveau « Remis au client » (aucun doublon possible).');
        });
    }

    function doneScreen(at, already) {
        render('<div class="card big" data-testid="remise-ok"><div class="tick" aria-hidden="true">✓</div><h2>' + (already ? 'Déjà remis' : 'Remis') + (at ? ' à ' + esc(hm(at)) : '') + '</h2>'
            + '<p class="muted">Ce QR ne peut plus servir.</p><a class="btn" id="back" href="' + NEXT + '">Mes livraisons</a></div>');
        setTimeout(function () { location.replace(NEXT); }, 2500);
    }

    // ── départ ──────────────────────────────────────────────────────────────────────────────────
    if (!qr) { refusal('Ce QR n\'est pas lisible. Rescannez le ticket.'); } else { loginScreen(''); }
})();
</script>
</body>
</html>
