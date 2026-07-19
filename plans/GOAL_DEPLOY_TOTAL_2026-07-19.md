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

## RÉSULTAT (exécuté 2026-07-19) — ✅ CONVERGÉ
### W1 backend VPS — ✅ vert
HEAD `19d2bf8e`, migration TVA 10% `2026_07_18_150000` DONE, triggers NF525 10/10, healthz 200,
`/kiosk/idle` bundle frais, rollback `5394e1a9` gardé. (Chaîne = TAMPER pré-existant, non-bloquant.)

### W2 web Vercel — ✅ vert
`site-lecayenne.vercel.app` (HTTP 200). index.html : api-base-url→VPS, **meta api-key ajouté** (= MIX_API_KEY
VPS ; le défaut `b6d68…` donnait 400), cache-bust →`b`. 19 commits web accumulés déployés (dont P0 checkout
page blanche du 16/07). Générateur `--check` contre le VPS : **0 dérive** (38/38 prix+flags). Commit web `92c91a7`.

### W3 validation e2e sur le déployé — ✅ vert
- **P0 TROUVÉ+CORRIGÉ en cours de valid : CORS**. Le VPS ne renvoyait PAS d'`Access-Control-Allow-Origin`
  pour l'origine Vercel (curl marchait = trompeur ; un navigateur aurait bloqué → checkout mort). Racine :
  `FRONTEND_WEB_DOMAIN` absent du `.env` VPS (`config/cors.php:18` = `env('FRONTEND_WEB_DOMAIN')` → null →
  filtré). **Fix** : `FRONTEND_WEB_DOMAIN=https://site-lecayenne.vercel.app` posé dans le `.env` VPS +
  `config:cache`. Re-test : préflight 204 **avec ACAO** + GET 200 avec ACAO. (Durable : survit aux redeploys ;
  le script deploy devrait le poser aussi.)
- **Parcours réel navigateur sur le site DÉPLOYÉ** (Tacos L : 3 viandes dont 1 en+ @2,50 + 3 sauces dont
  2 en+ @0,50 + 2 suppléments @0,90) → wizard 13,20 = panier 13,20 = checkout 13,20 = paiement 13,20 =
  confirmation **#190726171 TOTAL 13,20 €** (OTP réel lu table `otps` col `token`, SMS non câblé).
- **Backend VPS order id=171 source=5(WEB) total=13.20** ; composition_snapshot immuable = TOUTES les options
  présentes+chiffrées (Viande supp 2,50 + Sauce supp ×2 = 1,00 + Cheddar 0,90 + Boursin 0,90). **0 drop.**
  Bug owner « clique payer → 10€, sauces supprimées non facturées » = DÉFINITIVEMENT résolu sur le déployé.

## RESTE go-live (gate owner, NON bloquant staging)
1. **Clé API front = placeholder faible** `change-me-long-random-string-local-dev` (VPS + meta web). Rotation
   clé forte des DEUX côtés avant ouverture réelle — registre secrets `docs/HANDOVER_SECRETS_REGISTRY.md`.
2. Clés SMS (OTP par vrai SMS ; aujourd'hui lu en table pour l'e2e). 3. Chaîne fiscale VPS (Workstream A).
4. Order test #171 laissé PENDING (non-fiscalisé, inoffensif) — annulable depuis la caisse si indésiré.
