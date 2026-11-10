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
        .ghost { min-height:44px; padding:8px 14px; border-radius:10px; border:1px solid rgba(255,255,255,.55); background:transparent; color:#fff; font-size:14px; font-weight:600; }
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
        /* [2026-10-23] Durée réelle de la tournée ALLER-RETOUR (retour au restaurant compris). */
        .tour { margin:0 0 8px; padding:8px 12px; border-radius:12px; background:#FFF1D6; color:#5C3B00; font-size:15px; font-weight:600; }
        .tour small { display:block; font-weight:500; color:#8A5A00; margin-top:2px; }
        .chip { display:inline-block; padding:3px 10px; border-radius:999px; font-size:13px; font-weight:700; background:#EEE; color:var(--dark); }
        .chip.go { background:#FFF1D6; color:#8A5A00; } .chip.done { background:#DDF5E5; color:#126B33; } .chip.wait { background:#FDE4E1; color:#9B1C12; }
        .stop { background:var(--card); border:1px solid var(--line); border-radius:16px; padding:14px; margin:10px 0; display:flex; flex-direction:column; gap:10px; }
        /* [E2E 2026-10-23 C2-011] Plus d'opacité sur une carte remise : le texte tombait à ≈ 2,7:1. Fond atténué à la place. */
        .stop.delivered { background:#F4F6F5; border-style:dashed; }
        /* [E2E 2026-10-23 C2-013] Le champ photo reste focalisable au clavier (visuellement masqué, pas display:none). */
        .file-cta { position:relative; }
        .file-cta input[type=file] { position:absolute; width:1px; height:1px; opacity:0; overflow:hidden; }
        .file-cta:focus-within { outline:3px solid var(--accent); outline-offset:2px; }
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
        input:focus-visible,button:focus-visible,a:focus-visible,[role=tab]:focus-visible { outline:3px solid var(--accent); outline-offset:2px; }
        /* [LIVREUR CAISSE 2026-11-02] Onglets « Mes livraisons » / « Ma caisse » */
        .tabs { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin:0 0 10px; }
        .tab { min-height:52px; border-radius:14px; border:2px solid var(--line); background:#fff; color:var(--dark); font-size:17px; font-weight:800; cursor:pointer; }
        .tab[aria-selected=true] { background:var(--dark); border-color:var(--dark); color:#fff; }
        /* Règlement à la porte : feuille plein écran sur téléphone */
        .sheet { position:fixed; inset:0; z-index:20; background:rgba(0,0,0,.55); display:flex; align-items:flex-end; justify-content:center; }
        .sheet-card { background:#fff; width:100%; max-width:560px; max-height:94dvh; overflow-y:auto; border-radius:20px 20px 0 0; padding:18px 16px calc(18px + env(safe-area-inset-bottom)); display:flex; flex-direction:column; gap:12px; }
        .sheet-card h2 { margin:0; font-size:21px; } .sheet-card h2:focus { outline:none; }
        .due-big { border-radius:12px; padding:12px 14px; font-weight:800; font-size:22px; background:#FFF1D6; border:2px solid var(--accent); display:flex; justify-content:space-between; gap:8px; }
        .modes4 { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
        .mode { min-height:64px; border-radius:14px; border:3px solid var(--line); background:#fff; font-size:18px; font-weight:800; color:var(--dark); cursor:pointer; padding:8px; }
        .mode[aria-pressed=true] { border-color:var(--ok); background:#EAF7EE; color:#126B33; }
        .amt-row { display:grid; grid-template-columns:1fr 128px 64px; gap:8px; align-items:center; }
        .amt-row label { font-weight:700; font-size:16px; }
        .amt-row input { width:100%; min-height:52px; padding:8px 12px; font-size:18px; text-align:right; border:2px solid var(--line); border-radius:12px; background:#fff; }
        .amt-row .fill { min-height:52px; border-radius:12px; border:2px solid var(--line); background:#fff; font-size:14px; font-weight:700; cursor:pointer; }
        .rest { border-radius:12px; padding:10px 12px; font-weight:800; font-size:19px; background:#F4F4F5; }
        .rest.ok { background:#EAF7EE; color:#126B33; } .rest.bad { background:#FDECEC; color:#9B1C12; }
        /* Ma caisse */
        .card { background:var(--card); border:1px solid var(--line); border-radius:16px; padding:14px; margin:10px 0; display:flex; flex-direction:column; gap:10px; }
        .card h3 { margin:0; font-size:18px; }
        .big-n { font-size:26px; font-weight:900; line-height:1.1; white-space:nowrap; }
        .tiles { display:grid; grid-template-columns:1fr 1fr 1fr; gap:8px; }
        .tile { border:1px solid var(--line); border-radius:12px; padding:10px 8px; background:#fff; text-align:center; }
        .tile b { display:block; font-size:19px; } .tile span { font-size:13px; color:var(--muted); }
        .kv { display:flex; justify-content:space-between; gap:8px; align-items:baseline; }
        .kv b { font-size:18px; }
        .expect { background:#FFF1D6; border:2px solid var(--accent); border-radius:14px; padding:12px; }
        .var-pos { color:#126B33; } .var-neg { color:#9B1C12; }
        .ord { border-top:1px solid var(--line); padding-top:8px; font-size:15px; }
        .ord:first-child { border-top:none; padding-top:0; }
        .warn { border-radius:12px; padding:10px 12px; font-weight:700; background:#FFF1D6; border:2px solid var(--accent); color:#5C3B00; }
        input.field { width:100%; min-height:56px; padding:10px 14px; font-size:20px; border:2px solid var(--line); border-radius:12px; background:#fff; text-align:right; }
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
        <nav class="tabs" role="tablist" aria-label="Sections">
            <button class="tab" role="tab" id="tab-liv" aria-selected="true" aria-controls="view-liv" data-tab="liv">Mes livraisons</button>
            <button class="tab" role="tab" id="tab-cash" aria-selected="false" aria-controls="view-cash" data-tab="cash">Ma caisse</button>
        </nav>
        <div class="err" id="app-err" role="alert"></div>
        <section id="view-liv" role="tabpanel" aria-labelledby="tab-liv">
            <div id="waves"></div>
            <div id="empty" class="empty hidden">Aucune livraison pour le moment.<br>Les tournées apparaissent ici dès qu'une commande vous est attribuée.</div>
        </section>
        <section id="view-cash" role="tabpanel" aria-labelledby="tab-cash" class="hidden">
            <div class="err" id="cash-err" role="alert"></div>
            <div id="cash-body"><div class="empty">Chargement de votre caisse…</div></div>
        </section>
    </main>
</section>

{{-- [LIVREUR CAISSE 2026-11-02] Règlement à la porte : espèces, carte, titre-restaurant ou plusieurs moyens. Le total est celui de la commande (jamais modifiable ici). --}}
<div id="pay-sheet" class="sheet hidden" role="dialog" aria-modal="true" aria-labelledby="pay-title">
    <div class="sheet-card" data-testid="pay-sheet">
        <h2 id="pay-title" tabindex="-1">Régler la commande</h2>
        <div class="due-big"><span>Total à encaisser</span><b id="pay-total" data-testid="pay-total"></b></div>
        <div class="muted" id="pay-choice"></div>
        <div class="modes4" role="group" aria-label="Moyen de paiement">
            <button type="button" class="mode" data-pay-mode="cash" aria-pressed="false">Espèces</button>
            <button type="button" class="mode" data-pay-mode="card" aria-pressed="false">Carte</button>
            <button type="button" class="mode" data-pay-mode="meal_voucher" aria-pressed="false">Titre-restaurant</button>
            <button type="button" class="mode" data-pay-mode="split" aria-pressed="false">En plusieurs fois</button>
        </div>
        <div id="pay-split" class="hidden" data-testid="pay-split">
            <div class="amt-row"><label for="amt-cash">Espèces</label><input id="amt-cash" data-amt="cash" inputmode="decimal" autocomplete="off" placeholder="0,00"><button type="button" class="fill" data-fill="cash" aria-label="Mettre tout le reste en espèces">Reste</button></div>
            <div class="amt-row" style="margin-top:8px"><label for="amt-card">Carte</label><input id="amt-card" data-amt="card" inputmode="decimal" autocomplete="off" placeholder="0,00"><button type="button" class="fill" data-fill="card" aria-label="Mettre tout le reste sur la carte">Reste</button></div>
            <div class="amt-row" style="margin:8px 0"><label for="amt-meal">Titre-restaurant</label><input id="amt-meal" data-amt="meal_voucher" inputmode="decimal" autocomplete="off" placeholder="0,00"><button type="button" class="fill" data-fill="meal_voucher" aria-label="Mettre tout le reste en titre-restaurant">Reste</button></div>
            <div class="rest" id="pay-rest" data-testid="pay-rest" role="status" aria-live="polite"></div>
        </div>
        <div class="err" id="pay-err" role="alert"></div>
        <button class="btn ok" id="pay-ok" data-testid="pay-ok" disabled>Valider la remise</button>
        <button class="btn sec" id="pay-cancel">Annuler</button>
    </div>
</div>

{{-- Clôture de la caisse : on compte les espèces de la sacoche, l'écart s'affiche AVANT de confirmer. --}}
<div id="close-sheet" class="sheet hidden" role="dialog" aria-modal="true" aria-labelledby="close-title">
    <div class="sheet-card" data-testid="close-sheet">
        <h2 id="close-title" tabindex="-1">Clôturer ma caisse</h2>
        <div id="close-sum"></div>
        <div class="err" id="close-err" role="alert"></div>
        <button class="btn ok" id="close-ok" data-testid="close-ok">Confirmer la clôture</button>
        <button class="btn sec" id="close-cancel">Annuler</button>
    </div>
</div>

<script>
(function () {
    'use strict';
    var API_KEY = @json((string) config('app.api_key'));
    var KEY = 'lc_livreur_token';
    var token = null;
    try { token = localStorage.getItem(KEY); } catch (e) {}

    var cash = null, justClosed = false, closing = null; // « Ma caisse » (déclarées ici : logout() les remet à zéro)
    var $ = function (id) { return document.getElementById(id); };
    function uuid() { return (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : String(Date.now()) + Math.random().toString(16).slice(2); }
    function eur(n) { return Number(n).toFixed(2).replace('.', ',') + ' €'; }
    function hm(s) { return s ? s.slice(11, 16) : ''; }
    // [E2E 2026-10-23 C2-021] Code postal + ville seulement si le libellé d'adresse ne les contient pas déjà (libellé BAN).
    function villeSiAbsente(s) {
        // [SUPERVISION 2026-11-10] Le serveur envoie la ligne déjà dédoublonnée par la source unique (CoordonneesClientTicket) :
        // on l'affiche telle quelle ; l'ancienne règle locale ne sert que si un vieux serveur ne l'envoie pas.
        if (typeof s.locality === 'string') return s.locality ? '<div class="muted">' + esc(s.locality) + '</div>' : '';
        var cp = String(s.postal_code || ''), ville = String(s.city || ''), adr = String(s.address || '');
        if ((!cp || adr.indexOf(cp) !== -1) && (!ville || adr.toLowerCase().indexOf(ville.toLowerCase()) !== -1)) return '';
        return '<div class="muted">' + esc(cp) + ' ' + esc(ville) + '</div>';
    }
    // [2026-10-23] 47 → « 47 min », 65 → « 1 h 05 ».
    function dureeLabel(m) { m = Math.round(m); return m >= 60 ? Math.floor(m / 60) + ' h ' + ('0' + (m % 60)).slice(-2) : m + ' min'; }
    // La tournée ALLER-RETOUR (restaurant → arrêts → restaurant) : ce que le livreur met vraiment. Jamais affichée sans point GPS.
    function tourHtml(t) {
        if (!t) return '';
        return '<div class="tour" data-testid="livreur-tour">⏱ Tournée aller-retour ≈ ' + dureeLabel(t.tour_minutes) + ' · retour au restaurant vers ' + hm(t.back_at)
            + '<small>' + (t.source === 'road' ? 'Itinéraire routier réel' : 'Estimation (itinéraire indisponible)') + ' · dont ' + dureeLabel(t.return_minutes) + ' de retour</small></div>';
    }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]; }); }

    // [LIVREUR CAISSE 2026-11-02] `idem` : la même clé sert à rejouer une demande dont le réseau a coupé la réponse (aucun doublon possible).
    // Un réseau perdu donne un message français (jamais « Failed to fetch »), marqué `network` pour garder la clé.
    function api(method, path, body, isForm, idem) {
        var headers = { 'Accept': 'application/json', 'X-API-KEY': API_KEY };
        if (token) headers['Authorization'] = 'Bearer ' + token;
        if (method !== 'GET') headers['X-Idempotency-Key'] = idem || uuid();
        if (body && !isForm) { headers['Content-Type'] = 'application/json'; body = JSON.stringify(body); }
        return fetch(path, { method: method, headers: headers, body: body }).catch(function () {
            var er = new Error('Pas de réseau. Vérifiez votre connexion puis réessayez : rien ne sera encaissé deux fois.'); er.network = true; throw er;
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (j) {
                if (r.status === 401) { logout(); throw new Error('Session expirée, reconnectez-vous.'); }
                if (r.status === 429) { var e429 = new Error('Trop de demandes à la suite. Patientez quelques secondes puis réessayez.'); e429.retryable = true; throw e429; }
                if (!r.ok) {
                    var er2 = new Error(j.message || (j.errors && Object.values(j.errors)[0]) || (r.status >= 500 ? 'Le service ne répond pas correctement. Réessayez dans un instant.' : 'Demande refusée (code ' + r.status + ').'));
                    er2.status = r.status; throw er2;
                }
                return j;
            });
        });
    }

    function show(loggedIn) { $('login').classList.toggle('hidden', loggedIn); $('app').classList.toggle('hidden', !loggedIn); }
    function logout() {
        // [SUPERVISION 2026-11-10] « Quitter » révoque aussi le jeton côté serveur (au mieux, sans attendre) : sur un téléphone
        // partagé, la connexion ne survit plus dans l'autre magasin (SPA) une fois le livreur parti.
        if (token) { try { api('POST', '/api/auth/logout', {}).catch(function () {}); } catch (e) {} }
        token = null; try { localStorage.removeItem(KEY); } catch (e) {} show(false);
        cash = null; justClosed = false; // la caisse d'un livreur ne reste jamais à l'écran pour le suivant
        ['pay-sheet', 'close-sheet'].forEach(function (id) { var el = $(id); if (el) el.classList.add('hidden'); });
        document.body.style.overflow = '';
    }

    $('login-btn').addEventListener('click', function () {
        $('login-err').textContent = '';
        api('POST', '/api/auth/login', { email: $('email').value.trim(), password: $('password').value }).then(function (j) {
            token = j.token; try { localStorage.setItem(KEY, token); } catch (e) {}
            show(true); load();
        }).catch(function (e) { $('login-err').textContent = e.message; });
    });
    // [E2E 2026-10-23 C2-012] Un appui raté en pleine tournée ne déconnecte plus sans prévenir.
    $('logout').addEventListener('click', function () {
        if (enTournee && !window.confirm('Une tournée est en cours. Se déconnecter quand même ?')) return;
        logout();
    });

    var stopsById = {};
    // Ce qui a réellement été encaissé à la porte, moyen par moyen (arrêt remis).
    function doorLines(s) {
        if (!s.door_payments || !s.door_payments.length) return '';
        return '<div class="muted" style="font-weight:600">' + s.door_payments.map(function (p) { return esc(p.label) + ' ' + eur(p.amount); }).join(' · ') + '</div>';
    }

    function stopHtml(s) {
        stopsById[s.id] = s;
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
            ? '<div class="collect paid">✓ Remise terminée' + doorLines(s) + '</div>'
            : s.amount_due_cents > 0
            ? '<div class="collect pay">' + esc(s.collect_label) + ' : ' + eur(s.amount_due) + '</div>'
            : '<div class="collect paid">✓ Déjà payé</div>';
        var actions = '';
        if (!done && s.left_at) {
            actions = '<div class="grid2">'
                + '<label class="btn sec file-cta" style="cursor:pointer">📷 ' + (s.has_photo ? 'Photo prise' : 'Arrivé + photo')
                + '<input type="file" accept="image/*" capture="environment" aria-label="Arrivé : prendre la photo de preuve" data-arrive="' + s.id + '"></label>'
                + '<button class="btn ok" data-deliver="' + s.id + '" data-due="' + s.amount_due_cents + '" data-mode="' + esc(s.collect_label || '') + '">Remis au client</button></div>';
            // [LIVREUR CAISSE 2026-11-02] Autre moyen que celui choisi par le client, ou règlement en plusieurs fois.
            if (s.door_settleable) {
                actions += '<button class="btn sec" data-pay-open="' + s.id + '" data-testid="pay-open">Autre moyen · en plusieurs fois</button>';
            }
        }
        var nav = s.waze_url ? '<div class="grid2"><a class="btn" href="' + esc(s.waze_url) + '" target="_blank" rel="noopener">Waze</a>'
                              + '<a class="btn sec" href="' + esc(s.maps_url) + '" target="_blank" rel="noopener">Plans</a></div>' : '';
        return '<div class="stop' + (done ? ' delivered' : '') + '">'
            + '<div class="row"><div class="seq">' + s.sequence + '</div><div style="flex:1"><div class="addr">' + esc(s.address || 'Adresse inconnue') + (s.apartment ? ' · ' + esc(s.apartment) : '') + '</div>'
            + villeSiAbsente(s) + '</div>' + state + '</div>'
            + '<div class="row"><span>#' + esc(s.order_number || s.order_id) + ' · ' + esc(s.customer_name || 'Client') + '</span>'
            + (s.customer_phone ? '<a class="tel" href="tel:' + esc(s.customer_phone) + '">📞 ' + esc(s.customer_phone) + '</a>' : '') + '</div>'
            + collect + (done ? '' : nav) + actions + '</div>';
    }

    var enTournee = false;
    function render(waves) {
        // [E2E 2026-10-23 C2-010] Tournées encore à faire d'abord ; celles entièrement remises après.
        var fini = function (w) { return w.stops.length > 0 && w.stops.every(function (s) { return !!s.delivered_at; }); };
        waves = waves.filter(function (w) { return !fini(w); }).concat(waves.filter(fini));
        enTournee = waves.some(function (w) { return w.stops.some(function (s) { return s.left_at && !s.delivered_at; }); });
        $('empty').classList.toggle('hidden', waves.length > 0);
        $('waves').innerHTML = waves.map(function (w) {
            var pending = w.stops.filter(function (s) { return !s.left_at && !s.delivered_at; });
            var ready = pending.filter(function (s) { return s.ready; }).length;
            // [VG-2026-10-21 M4-005] Le jour quand ce n'est pas aujourd'hui : « Départ 00:30 » se lisait comme ce soir.
            var jour = w.is_today === false && w.day_label ? esc(w.day_label) + ' ' : '';
            var head = '<div class="wave"><h3>Départ ' + jour + w.time + ' · ' + w.stops.length + ' livraison' + (w.stops.length > 1 ? 's' : '') + '</h3></div>';
            var go = pending.length
                ? '<button class="btn" data-depart="' + w.wave_at + '"' + (ready ? '' : ' disabled') + '>Je pars (' + ready + '/' + pending.length + ' prête' + (ready > 1 ? 's' : '') + ')</button>' : '';
            return head + tourHtml(w.tour) + go + w.stops.map(stopHtml).join('');
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
            // [E2E PAIEMENT 2026-10-24] La confirmation dit le MODE encaissé (carte au terminal, espèces, titre-restaurant), pas seulement le montant.
            var mode = (t.dataset.mode || '').toLowerCase();
            if (due > 0 && !window.confirm('Confirmez-vous avoir encaissé ' + eur(due / 100) + (mode ? ' (' + mode + ')' : '') + ' ?')) return;
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

    // ── [LIVREUR CAISSE 2026-11-02] Onglets ────────────────────────────────────────────────────────
    var tab = 'liv';
    function setTab(t) {
        if (t !== 'cash') justClosed = false;
        tab = t;
        $('view-liv').classList.toggle('hidden', t !== 'liv');
        $('view-cash').classList.toggle('hidden', t !== 'cash');
        $('tab-liv').setAttribute('aria-selected', t === 'liv' ? 'true' : 'false');
        $('tab-cash').setAttribute('aria-selected', t === 'cash' ? 'true' : 'false');
        if (t === 'cash') loadCash();
    }
    document.addEventListener('click', function (e) {
        var t = e.target.closest('[data-tab]');
        if (t) setTab(t.dataset.tab);
    });

    // ── Règlement à la porte : espèces / carte / titre-restaurant / plusieurs fois ────────────────────
    var pay = null;
    var MODE_LABEL = { cash: 'Espèces', card: 'Carte', meal_voucher: 'Titre-restaurant' };
    // « 12,5 » / « 12.50 » → 1250 ; vide → 0 ; illisible → NaN
    function parseCents(v) {
        v = String(v == null ? '' : v).replace(/\s/g, '').replace(',', '.');
        if (v === '') return 0;
        if (!/^\d+(\.\d{1,2})?$/.test(v)) return NaN;
        return Math.round(parseFloat(v) * 100);
    }
    function sheetOpen(id, focusId) { $(id).classList.remove('hidden'); document.body.style.overflow = 'hidden'; var h = $(focusId); if (h) h.focus({ preventScroll: true }); }
    function sheetClose(id) { $(id).classList.add('hidden'); document.body.style.overflow = ''; }

    function openPay(stop) {
        var cm = stop.collect_method;
        pay = { stop: stop, mode: MODE_LABEL[cm] ? cm : 'cash', idem: uuid(), busy: false, from: document.activeElement };
        $('pay-title').textContent = 'Régler la commande #' + (stop.order_number || stop.order_id);
        $('pay-total').textContent = eur(stop.amount_due_cents / 100);
        $('pay-choice').textContent = 'Choix du client : ' + String(stop.collect_label || '').replace(/^Encaisser (en |par )?/, '') + '. Indiquez ce que vous avez réellement encaissé.';
        ['cash', 'card', 'meal'].forEach(function (k) { $('amt-' + k).value = ''; });
        $('pay-err').textContent = '';
        applyPay();
        sheetOpen('pay-sheet', 'pay-title');
    }
    function payAmounts() {
        return { cash: parseCents($('amt-cash').value), card: parseCents($('amt-card').value), meal_voucher: parseCents($('amt-meal').value) };
    }
    function applyPay() {
        if (!pay) return;
        var total = pay.stop.amount_due_cents, split = pay.mode === 'split', ok = !pay.busy;
        Array.prototype.forEach.call(document.querySelectorAll('[data-pay-mode]'), function (b) { b.setAttribute('aria-pressed', b.dataset.payMode === pay.mode ? 'true' : 'false'); });
        $('pay-split').classList.toggle('hidden', !split);
        if (split) {
            var a = payAmounts(), bad = false, sum = 0;
            Object.keys(a).forEach(function (k) { if (isNaN(a[k])) bad = true; else sum += a[k]; });
            var rest = total - sum, el = $('pay-rest');
            if (bad) { el.textContent = 'Un montant est illisible : saisissez par exemple 12,50.'; el.className = 'rest bad'; ok = false; }
            else if (rest > 0) { el.textContent = 'Reste à encaisser : ' + eur(rest / 100); el.className = 'rest'; ok = false; }
            else if (rest < 0) { el.textContent = 'Trop perçu : ' + eur(-rest / 100) + ' — corrigez les montants'; el.className = 'rest bad'; ok = false; }
            else { el.textContent = 'Reste à encaisser : ' + eur(0) + ' — c\'est le compte'; el.className = 'rest ok'; }
        }
        $('pay-ok').disabled = !ok;
    }
    function closePay() { sheetClose('pay-sheet'); var f = pay && pay.from; pay = null; if (f && f.focus) { try { f.focus(); } catch (e) {} } }

    function submitPay() {
        if (!pay || pay.busy) return;
        var total = pay.stop.amount_due_cents, payments = [];
        if (pay.mode === 'split') {
            var a = payAmounts();
            Object.keys(a).forEach(function (k) { if (a[k] > 0) payments.push({ mode: k, amount: a[k] / 100 }); });
        } else {
            payments.push({ mode: pay.mode, amount: total / 100 });
        }
        pay.busy = true; applyPay(); $('pay-err').textContent = '';
        var cur = pay;
        api('POST', '/api/frontend/delivery-run/stops/' + cur.stop.id + '/deliver', { collected_cents: total, payments: payments }, false, cur.idem).then(function () {
            closePay(); return load();
        }).catch(function (er) {
            cur.busy = false;
            if (!er.network) cur.idem = uuid(); // refus définitif : la prochaine tentative est une nouvelle demande ; réseau coupé : MÊME clé
            if (er.status === 409) { closePay(); $('app-err').textContent = er.message; return load(true); }
            $('pay-err').textContent = er.message; applyPay();
        });
    }

    document.addEventListener('click', function (e) {
        var o = e.target.closest('[data-pay-open]');
        if (o) { var st = stopsById[o.dataset.payOpen]; if (st) openPay(st); return; }
        var m = e.target.closest('[data-pay-mode]');
        if (m && pay) { pay.mode = m.dataset.payMode; $('pay-err').textContent = ''; applyPay(); if (pay.mode === 'split') $('amt-cash').focus(); return; }
        var f = e.target.closest('[data-fill]');
        if (f && pay) {
            var a = payAmounts(), k = f.dataset.fill, others = 0;
            Object.keys(a).forEach(function (x) { if (x !== k && !isNaN(a[x])) others += a[x]; });
            var left = Math.max(0, pay.stop.amount_due_cents - others);
            $(k === 'meal_voucher' ? 'amt-meal' : 'amt-' + k).value = (left / 100).toFixed(2).replace('.', ',');
            applyPay(); return;
        }
        if (e.target.closest('#pay-ok')) return submitPay();
        if (e.target.closest('#pay-cancel')) return closePay();
        if (e.target === $('pay-sheet') && !(pay && pay.busy)) return closePay();
    });
    document.addEventListener('input', function (e) { if (e.target.closest && e.target.closest('[data-amt]')) applyPay(); });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (!$('pay-sheet').classList.contains('hidden') && !(pay && pay.busy)) closePay();
        else if (!$('close-sheet').classList.contains('hidden')) closeCloseSheet();
    });

    // ── Ma caisse ─────────────────────────────────────────────────────────────────────────────────────
    function signed(n) { var v = Number(n); return (v > 0 ? '+' : v < 0 ? '−' : '') + eur(Math.abs(v)); }
    function varClass(n) { return Number(n) > 0.004 ? 'var-pos' : Number(n) < -0.004 ? 'var-neg' : ''; }
    function varWords(n) {
        var v = Number(n);
        return v > 0.004 ? 'Il y a ' + eur(v) + ' de trop dans la sacoche.' : v < -0.004 ? 'Il manque ' + eur(-v) + ' dans la sacoche.' : 'Le compte est bon.';
    }

    function recapHtml(d) {
        var closed = d.status && d.status !== 'open', bm = d.by_mode || {};
        var h = '<div class="card"><div class="kv"><span>Fond de début</span><b data-testid="caisse-fond">' + eur(d.opening_amount) + '</b></div>'
            + '<div class="muted">Remis à l\'ouverture du service' + (d.opened_at ? ' (' + hm(d.opened_at) + ')' : '') + '</div></div>';
        h += '<div class="card"><h3>Encaissé à la porte</h3><div class="tiles">'
            + '<div class="tile" data-testid="caisse-especes"><span>Espèces</span><b>' + eur((bm.cash || {}).amount || 0) + '</b><span>' + ((bm.cash || {}).count || 0) + ' paiement(s)</span></div>'
            + '<div class="tile" data-testid="caisse-carte"><span>Carte</span><b>' + eur((bm.card || {}).amount || 0) + '</b><span>' + ((bm.card || {}).count || 0) + ' paiement(s)</span></div>'
            + '<div class="tile" data-testid="caisse-titres"><span>Titres-resto</span><b>' + eur((bm.meal_voucher || {}).amount || 0) + '</b><span>' + ((bm.meal_voucher || {}).count || 0) + ' paiement(s)</span></div></div>'
            + '<div class="kv"><span>Total encaissé</span><b data-testid="caisse-total">' + eur(d.total_collected) + '</b></div>'
            + '<div class="kv"><span>Livraisons réglées</span><b data-testid="caisse-nb">' + d.deliveries_settled + '</b></div></div>';
        h += '<div class="expect"><div class="kv"><span>Espèces attendues dans la sacoche</span><b class="big-n" data-testid="caisse-attendu">' + eur(d.expected_cash) + '</b></div>'
            + '<div class="muted">Fond ' + eur(d.opening_amount) + ' + espèces encaissées ' + eur((bm.cash || {}).amount || 0) + '</div></div>';
        if (Math.abs(Number(d.ledger_gap || 0)) >= 0.01) {
            h += '<div class="warn" role="alert">Attention : les espèces des commandes et le livre de caisse ne concordent pas (écart de ' + eur(Math.abs(d.ledger_gap)) + '). Prévenez le restaurant.</div>';
        }
        if (closed) {
            h += '<div class="card" data-testid="caisse-cloture"><h3>Caisse clôturée' + (d.closed_at ? ' à ' + hm(d.closed_at) : '') + '</h3>'
                + '<div class="kv"><span>Espèces comptées</span><b data-testid="caisse-comptees">' + eur(d.counted_cash) + '</b></div>'
                + '<div class="kv"><span>Écart</span><b class="' + varClass(d.variance) + '" data-testid="caisse-ecart">' + signed(d.variance) + '</b></div>'
                + '<div class="muted">' + esc(varWords(d.variance)) + '</div></div>';
        }
        var ords = d.orders || [];
        h += '<div class="card"><h3>Mes commandes (' + ords.length + ')</h3>' + (ords.length ? ords.map(function (o) {
            var lines = o.paid_online ? 'Payée en ligne' : o.payments.map(function (p) { return esc(p.label) + ' ' + eur(p.amount); }).join(' · ');
            return '<div class="ord"><div class="kv"><span>#' + esc(o.order_number || o.order_id) + (o.delivered_at ? ' · ' + hm(o.delivered_at) : '') + '</span><b>' + eur(o.total) + '</b></div><div class="muted">' + lines + '</div></div>';
        }).join('') : '<div class="muted">Aucune livraison remise pour le moment.</div>') + '</div>';
        return h;
    }

    function renderCash() {
        var body = $('cash-body'), d = cash, counted = document.getElementById('cash-counted');
        var keep = counted ? counted.value : '', hadFocus = counted && document.activeElement === counted;
        if (!d) { body.innerHTML = '<div class="empty">Chargement de votre caisse…</div>'; return; }
        var h;
        if (!d.open) {
            h = '<div class="card" data-testid="caisse-aucun"><h3>Aucun service ouvert</h3>'
                + '<div class="muted">Votre caisse s\'ouvre toute seule à votre première livraison encaissée, ou à la demande du restaurant (avec votre fond de début).</div></div>';
            if (d.last) h += '<h3 style="margin:16px 4px 0">' + (justClosed ? 'Votre caisse vient d\'être clôturée' : 'Dernier service') + '</h3>' + recapHtml(d.last);
        } else {
            h = recapHtml(d);
            var pend = d.pending || { count: 0 };
            h += '<div class="card"><h3>Clôturer ma caisse</h3>'
                + (pend.count > 0 ? '<div class="warn" role="alert">Il reste ' + pend.count + ' livraison' + (pend.count > 1 ? 's' : '') + ' en route à encaisser (' + esc((pend.orders || []).join(', ')) + ' — ' + eur(pend.amount) + '). Remettez-' + (pend.count > 1 ? 'les' : 'la') + ' ou rapportez-' + (pend.count > 1 ? 'les' : 'la') + ' avant de clôturer.</div>' : '')
                + '<label for="cash-counted" style="font-weight:700">Espèces comptées dans la sacoche</label>'
                + '<input id="cash-counted" class="field" inputmode="decimal" autocomplete="off" placeholder="0,00" data-testid="cash-counted">'
                + '<div class="muted">Comptez tout l\'argent liquide que vous avez sur vous, fond de début compris.</div>'
                + '<button class="btn" id="cash-close-open" data-testid="cash-close-open" disabled>Clôturer ma caisse</button></div>';
        }
        body.innerHTML = h;
        var c2 = document.getElementById('cash-counted');
        if (c2) { c2.value = keep; if (hadFocus) c2.focus(); syncCloseBtn(); }
    }
    function syncCloseBtn() {
        var c = document.getElementById('cash-counted'), b = document.getElementById('cash-close-open');
        if (!c || !b) return;
        var v = parseCents(c.value);
        b.disabled = c.value.trim() === '' || isNaN(v) || !(cash && cash.open && (!cash.pending || cash.pending.count === 0));
    }
    function loadCash(keepMsg) {
        return api('GET', '/api/frontend/delivery-run/cash').then(function (j) { if (!keepMsg) $('cash-err').textContent = ''; cash = j.data; renderCash(); })
            .catch(function (e) { $('cash-err').textContent = e.message; });
    }

    document.addEventListener('input', function (e) { if (e.target && e.target.id === 'cash-counted') syncCloseBtn(); });
    document.addEventListener('click', function (e) {
        if (e.target.closest('#cash-close-open')) {
            var v = parseCents(document.getElementById('cash-counted').value);
            if (isNaN(v) || !cash || !cash.open) return;
            closing = { counted: v, idem: uuid(), busy: false };
            var diff = v / 100 - Number(cash.expected_cash);
            $('close-sum').innerHTML = '<div class="kv"><span>Fond de début</span><b>' + eur(cash.opening_amount) + '</b></div>'
                + '<div class="kv"><span>Espèces attendues</span><b>' + eur(cash.expected_cash) + '</b></div>'
                + '<div class="kv"><span>Espèces comptées</span><b data-testid="close-comptees">' + eur(v / 100) + '</b></div>'
                + '<div class="kv"><span>Écart</span><b class="' + varClass(diff) + '" data-testid="close-ecart">' + signed(Math.round(diff * 100) / 100) + '</b></div>'
                + '<div class="muted">' + esc(varWords(diff)) + ' La clôture est définitive.</div>';
            $('close-err').textContent = '';
            $('close-ok').disabled = false;
            sheetOpen('close-sheet', 'close-title');
        } else if (e.target.closest('#close-ok')) { submitClose(); }
        else if (e.target.closest('#close-cancel')) { closeCloseSheet(); }
        else if (e.target === $('close-sheet') && !(closing && closing.busy)) { closeCloseSheet(); }
    });
    function closeCloseSheet() { sheetClose('close-sheet'); closing = null; }
    function submitClose() {
        if (!closing || closing.busy || !cash || !cash.open) return;
        closing.busy = true; $('close-ok').disabled = true; $('close-err').textContent = '';
        var cur = closing;
        api('POST', '/api/frontend/delivery-run/cash/close', { counted_cash: cur.counted / 100, session_id: cash.session_id }, false, cur.idem).then(function (j) {
            closeCloseSheet(); justClosed = true;
            cash = { open: false, message: 'Aucun service ouvert', last: j.data };
            renderCash(); $('cash-err').textContent = '';
        }).catch(function (er) {
            cur.busy = false; $('close-ok').disabled = false;
            if (!er.network) cur.idem = uuid();
            $('close-err').textContent = er.message;
        });
    }

    show(!!token);
    if (token) load();
    setInterval(function () {
        if (!token || document.hidden) return;
        load();
        if (tab === 'cash' && $('close-sheet').classList.contains('hidden')) loadCash(true);
    }, 30000);
})();
</script>
</body>
</html>
