# QA Loop — prochaines actions bornées

Date : 2026-09-27  
Source : `reports/planning/QA_CROSS_SURFACE_FOLLOWUP_2026-09-25.md` et
`reports/antigravity/playwright-latest.json`

## État confirmé

- Smoke Playwright critique : 22/22, reproductible sur deux runs.
- Pricing/status lint et budget des bundles actifs : verts.
- Parcours fidélité, suppléments, deux sauces, KDS et libellés cuisine : verts.
- Production HTTP/healthz : verte.
- Borne distante : auto-login machine absent; le bouton Réessayer reste proprement
  sur l’état indisponible.
- Sentinel frozen : drift limité à trois commentaires, mais lock propriétaire
  `LOCK_KIOSK_FRITES_SAUCE_BILLING_2026-07-29.md` non signé.
- Site public : copie livraison encore « Uber Eats », contrairement aux clés
  checkout FoodKing versionnées.
- Auth POS distante/F5 : les deux scénarios reçoivent HTTP 400
  « Identifiants invalides ou compte bloqué » avec le compte E2E configuré;
  aucun test F5 distant ne peut être déclaré vert sans compte de test actif.

## Prochaine action A — provisioning borne (déploiement, hors code)

1. Propriétaire renseigne les `KIOSK_MACHINE_*` et la confiance IP/secret du VPS
   selon la borne physique; ne jamais transmettre ces secrets dans le dépôt.
2. Exécuter côté serveur `php artisan foodking:ensure-kiosk-machine`, puis
   `php artisan config:clear`.
3. Rejouer en navigateur `/kiosk/login` → auto-login → `/kiosk/idle`.
4. Preuves de clôture : DOM idle, console sans warning `Auto-login indisponible`,
   test interactif catégories/produit non skipped, et healthz toujours 200.

## Prochaine action B — contenu public

1. Identifier le dépôt/surface qui sert `lecayenne.fr` (hors checkout FoodKing
   versionné) et faire valider le texte cible par le propriétaire.
2. Déployer uniquement la copie validée : « Commander à emporter »,
   « Confirmer à emporter », « Livraison par nos livreurs bientôt ».
3. Vérifier en Chrome le footer/checkout public et conserver une capture ou un
   DOM snapshot; aucun panier/compte réel ne doit être modifié durant le test.

## Prochaine action C — compte E2E POS distant

1. Le propriétaire déploie/active un compte POS de test dédié (ou fournit les
   variables `E2E_POS_USER`/`E2E_POS_PASS` valides hors dépôt).
2. Rejouer `PLAYWRIGHT_BASE_URL=https://vps-418872ac.vps.ovh.net npx playwright
   test --config=playwright.config.js tests/e2e/01-auth-refresh.spec.js`.
3. Clôturer uniquement avec 2/2 scénarios verts et URL `/admin/pos` conservée
   après F5; ne pas modifier le code auth sur la seule base d’un compte rejeté.

## Gate frozen

Ne pas mettre à jour `frozen-zone-sha256-baseline.json` tant que le sign-off
propriétaire du lock n’est pas explicite. Après gate uniquement : mise à jour
atomique baseline + fichier frozen, puis sentinel ciblé et suite globale.

## Routage QA

- Provisioning et gate : validation propriétaire / déploiement, pas d’édition
  automatique par l’agent.
- Contenu public : cycle séparé, surface à identifier avant toute modification.
- Validation finale : `playwright-critical-flow` puis relecture du rapport principal.

**VERDICT QA LOOP : NEEDS_OWNER_ACTION**
