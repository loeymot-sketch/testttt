# QA Loop — prochaines actions bornées

Date : 2026-09-27  
Source : `reports/planning/QA_CROSS_SURFACE_FOLLOWUP_2026-09-25.md` et
`reports/antigravity/playwright-latest.json`

## État confirmé

- Smoke Playwright critique : 22/22, reproductible sur deux runs.
- Pricing/status lint et budget des bundles actifs : verts.
- Parcours fidélité, suppléments, deux sauces, KDS et libellés cuisine : verts.
- Production HTTP/healthz : verte.
- Borne distante : provisioning auto-login maintenant actif; le parcours
  interactif catégories/produits passe 5/5 en distant.
- Sentinel frozen : drift limité à trois commentaires, mais lock propriétaire
  `LOCK_KIOSK_FRITES_SAUCE_BILLING_2026-07-29.md` non signé.
- Site public : copie livraison encore « Uber Eats », contrairement aux clés
  checkout FoodKing versionnées.
- Auth POS distante/F5 : les deux scénarios reçoivent HTTP 400
  « Identifiants invalides ou compte bloqué » avec le compte E2E configuré;
  aucun test F5 distant ne peut être déclaré vert sans compte de test actif.
  La fixture locale correspondante est active (`status=5`, branche 1) et
  accepte le mot de passe de test; l’action est donc strictement distante.
  Le dernier re-run a déclenché le rate-limit distant (HTTP 429, fenêtre 600 s);
  ne pas relancer avant expiration ou changement de compte E2E.
- **Revalidation web distante (27/09/2026, 18:49 CEST)** : le wizard borne
  repasse **5/5 en 19,7 s** sur le VPS et `/api/healthz` reste 200; aucun
  incident borne n’est reproduit. Le texte public Uber Eats reste le blocage
  contenu distinct.
- **Panier public contrôlé dans Chrome (27/09/2026)** : Tacos L 17,30 € +
  Tacos M 6,90 € donnent un total affiché de 24,20 €; vérification arrêtée
  avant paiement.

## Prochaine action A — borne (revalidation après provisioning)

1. Conserver les secrets `KIOSK_MACHINE_*` hors dépôt et surveiller le
   provisioning lors des prochains redéploiements.
2. Rejouer périodiquement `/kiosk/login` → auto-login → `/kiosk/idle`.
3. Preuves actuelles : test interactif catégories/produits **5/5**, healthz
   toujours 200; aucun secret n’est stocké dans le rapport.

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
