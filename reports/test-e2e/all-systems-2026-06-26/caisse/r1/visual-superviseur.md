# CAISSE r1 — VISUEL (superviseur, live :8766 foodking_e2e, Admin authed)

Auth visuel débloqué via injection état Vuex (token Sanctum admin force-login, sans mot de passe owner).

## ✅ Confirmés OK (rendu réel)
- Category-first landing OK : catégories = Sandwichs/Galette/Burgers/Tacos/Bols/Frites/Desserts/Boissons/Menu enfant (= menu canonique seeder). FR propre, 0 raw-label.
- Sidebar complète FR (Tableau de bord, POS, Encaissement, Écran cuisine, Suivi client, Rapports...). Données réelles (90 commandes « À encaisser borne »).
- Panier vide cohérent : « Aucun article. Sélectionnez un produit dans la grille. »

## ⚠️ FINDINGS
- [P2] resources/js/services/appService.js:71-76 — `currencyFormat` non-FR. `parseFloat(amount).toFixed(decimal) + currency` → rend « 0.00€ » (point décimal + AUCUN espace avant €) au lieu du canonique FR « 0,00 € ». Visible LIVE sur le panier POS (Sous-total/Total). Utilisé aussi par CheckoutComponent + CouponComponent (systémique, multi-surfaces user-facing). Mandat FR ADR-007. PosOrdersTracker rend déjà « 19,00 € » par un AUTRE chemin → migration incomplète. NON-frozen. repro: ouvrir /admin/pos → panier Sous-total/Total = « 0.00€ ». reco: router le rendu prix POS cart vers le helper FR-aware (humanize/format canonique) ou rendre currencyFormat FR (séparateur ',' + espace insécable). lentille: client+commerçant.
- [P3] APP_URL mismatch — avatar/img `http://localhost:8000/storage/18/clean.jpg` → ERR_CONNECTION_REFUSED sur serveur :8766 (APP_URL=localhost:8000 ≠ host). Config/deploy V1 : APP_URL doit = host servi. repro: console /admin/pos = 1 erreur ERR_CONNECTION_REFUSED. lentille: technique/deploy.
