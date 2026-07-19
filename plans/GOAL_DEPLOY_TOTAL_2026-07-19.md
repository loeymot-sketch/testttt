# GOAL — Déploiement total (backend VPS + site web Vercel) + validation e2e (2026-07-19)

Owner : « déploie tout et valide en test-e2e, ultra plan deep, exécute ». Mode simu matériel CONSERVÉ (POS_SIMULATION_HARDWARE reste true, pas de go-live prod complet — c'est un deploy staging validé).

## W0 — Pré-flight ✅ (fait)
- Backend +12 commits ahead origin (garde expected_total, TVA 10%, remises OFF, fidélité, multi-sauces, blocs C/D, canal public 86). VPS `5394e1a9`, APP_URL `https://vps-418872ac.vps.ovh.net`, APP_ENV=staging.
- Web +19 commits (drop-fix, P0 checkout, parité options, générateur data, 86 temps réel). Remote GitHub `loeymot-sketch/Site-lecayenne` → Vercel. api-base-url = `:8766` (à corriger).
- **VPS HTTPS externe : healthz 200, cert VALIDE (0), CORS preflight 204** → le web Vercel peut appeler le backend prod. (item 400 = clé API locale≠VPS, résolue dans la config web.)

## W1 — Deploy BACKEND VPS
- Push branche → origin. `bash tools/deploy-lecayenne.sh <HEAD>` (reviewed SHA) : snapshot DB + build + migrations (TVA 10% `2026_07_18_150000` + backfill loyalty déjà passé) + triggers NF525 + smoke contenu + rollback auto. Le trigger orders_no_delete est déjà en place (hier).
- Post-deploy : vérifier TVA 10% appliquée (boissons NULL→10%), remises coupées, HEAD.

## W2 — Deploy WEB Vercel
- `index.html` : api-base-url `:8766` → `https://vps-418872ac.vps.ovh.net` + menu-image-base idem + cache-bust `?v=`. (fichier de conf, pas le code.)
- Lancer `node tools/generate-menu-from-api.mjs --check` contre le VPS prod (dispo/prix alignés) ; `--write` si dérive.
- Vérifier la clé API web == VPS (le 400 local). 
- Push GitHub → Vercel auto-deploy. **C'est du public — owner a dit « déploie tout ».**

## W3 — Validation e2e sur le DÉPLOYÉ
- Backend VPS : smoke (healthz, /kiosk/idle contenu frais, chaîne verify-chain informative), preflight interpreté.
- Web Vercel (une fois en ligne) : e2e navigateur du parcours réel contre le VPS — commander un produit + options → panier=payé=backend, 86 temps réel, checkout OK. Boucle jusqu'à vert.
- Cross-surface : commande web déployée → visible caisse VPS → KDS → OSS → notif client.

## Gates / risques
- Deploy = hard-to-reverse : owner a explicitement demandé. Rollback auto intégré au script backend ; web = revert commit Vercel.
- Chaîne fiscale VPS = TAMPER pré-existant connu (Workstream A, non-bloquant deploy).
- Le bonus WS 86 instantané (soketi public) n'est pas activé — le polling web assure.
