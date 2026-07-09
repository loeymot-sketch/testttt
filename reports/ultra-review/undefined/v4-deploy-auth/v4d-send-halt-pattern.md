# V4-DEPLOY Axe 2 — Surface: pattern ->send()/redirect-sans-halt (slug v4d-send-halt-pattern)

HEAD 61e9ea7b7 + working tree. Serveur LIVE 127.0.0.1:8766 (foodking_e2e). storage/installed présent.

## Verdict: SAFE (0 nouveau finding)

## Méthode
grep `->send()|->sendContent()|->sendHeaders()|Redirect::to` sur app/Http/Controllers + app/Http/Middleware + tout app/ + routes/.

## Résultats
- `->send()` dans TOUT app/ : 1 seul hit = un COMMENTAIRE dans InstallerController.php:29 documentant l'ancien bug. Le code réel est déjà healé (middleware de contrôleur closure qui `return redirect(...)` → court-circuite le pipeline). Aucun `->send()`/`->sendContent()` exécutable nulle part.
- `abort()` (HealthController, Frontend/Address, Frontend/Order, Frontend/Payment, OrderHistory, ZReport/XReport, PosLoyalty, PosOrder, PosReceiptPrint, 2 middlewares) : `abort()` LÈVE une HttpException → HALTE toujours, `return` non requis. Sûr.
- Middlewares avec redirect : Installed.php:27 `return redirect('/install')`, RedirectIfAuthenticated:26 `return redirect(HOME)` — tous correctement `return`és. Sûr.
- Constructeurs de contrôleurs : aucun `redirect()->send()`/`exit`/`die` non-halté. PosCategory/Item __construct utilisent `abort_unless` (throw). Uber webhook = toutes réponses `return`ées.

## Repro LIVE (garde installeur = efficace)
- GET /install → 302 → http://127.0.0.1:8766/ (bloqué)
- GET /install/database → 302 → home (bloqué)
- GET /install/final-store → 302 → home (bloqué)
Le middleware court-circuite AVANT toute mutation .env/DB. Pattern P0 cloné = ABSENT ailleurs.
