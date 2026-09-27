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
- **Quantité panier contrôlée (27/09/2026)** : Tacos M ×2 affiche 13,80 € et
  total 31,10 €, puis le retour à ×1 restaure 24,20 €.
- **Checkout public contrôlé (27/09/2026)** : `#payment` confirme le mode
  « À emporter », le retrait et le paiement au comptoir; la livraison pointe
  toujours vers Uber Eats et reste à remplacer par le texte validé.
- **Smoke E2E locale complète (27/09/2026, 18:54 CEST)** : **22/22 tests
  passés en 1,5 min**, incluant POS/F5, caisse, borne, KDS et isolation de
  rupture de stock multi-branche.
- **Fidélité/inscription locale (27/09/2026, 18:54 CEST)** : **4/4 tests en
  7,3 s**, sans page blanche sur solde réel, code inconnu ou inscription.
- **Backend Kiosk/Loyalty/Pricing (27/09/2026)** : **62/62 + 93/93 + 31/31
  tests passés**; prix au centime, suppléments, fidélité et auto-login borne
  restent validés.
- **Inscription post-register (27/09/2026, 18:56 CEST)** : **1/1 test en
  4,0 s**; le solde revient après `register`, sans page blanche.
- **Sécurité/isolation (27/09/2026)** : **221/221 + 6/6 + 8/8 tests passés**
  pour sécurité générale, borne et multi-branche.
- **Commande/outbox (27/09/2026)** : **109/109 + 81/81 tests passés**;
  snapshots de prix, statuts, idempotence et dispatch post-commit validés.
- **Reprobe VPS/public (27/09/2026, 18:59 CEST)** : healthz toujours 200 et
  services critiques OK; le contenu livraison reste inchangé (« Uber Eats »).
- **Frontend/hardware (27/09/2026)** : **58/58 + 164/164 tests passés**;
  tickets, sauces, viandes, frites et total serveur restent validés.
- **Fiscalité/paiement/caisse (27/09/2026)** : **307 tests fiscaux passés
  (8 skips MySQL), 86/86 paiement et 386/386 POS**; NF525, split tender,
  tiroir, fidélité caisse et totaux serveur restent verts.
- **Release guards (27/09/2026)** : pricing/status lint verts (**86/38 fichiers**),
  bundles **15/15**; i18n parse les **80 fichiers Laravel** sans erreur, avec
  dette de clés connue et sign-off pricing encore en attente jusqu’au 27/10.
- **Backend global (27/09/2026)** : **6 080 passés, 36 skipped, 6 incomplets,
  1 échec** en 1 301,71 s; le seul échec reste le sentinel frozen-zone
  `KioskWizardComponent.vue`. Ne pas toucher à la baseline sans gate propriétaire.
- **Forensic frozen recheck (27/09/2026)** : hash réel `f8ecb111…06465` contre
  baseline `fcbe3755…256ac`; l’écart est limité au commit `c21628767`, qui ne
  fait que normaliser trois commentaires de sign-off pricing. Le lock owner
  reste non signé : verdict **NEEDS_OWNER_ACTION**, aucune baseline modifiée.
- **Reprobe runtime (27/09/2026, 19:28 CEST)** : VPS HTTP 200 et sous-systèmes
  critiques `ok`; `queue_pending=1` sur trois mesures consécutives. Surveiller
  le worker/outbox, sans relancer ni purger manuellement une file de production.
- **Reprobe contenu public (27/09/2026, 19:28 CEST)** : Uber Eats est toujours
  affiché dans le HTML; la copie « livraison par nos livreurs bientôt » reste
  un blocage de déploiement distinct.
- **Healthz local (27/09/2026)** : `HealthzEndpointTest` **7/7 passés**.
- **Borne distante (27/09/2026, 19:29 CEST)** : avec Node **20.20.2** requis
  par Playwright, `03-kiosk-wizard.spec.js` passe **5/5 en 19,9 s**.
- **Queue distante (27/09/2026, 19:30 CEST)** : `queue_pending=2` persiste
  après quatre mesures et le test borne; surveiller le worker/outbox sans
  purge manuelle.
- **Readiness/sondes (27/09/2026, 19:31 CEST)** : `/api/health/live` et
  `/api/health/ready` sont HTTP 200; `restore_drill` reste `degraded` faute de
  restauration mesurée. `/api/health` voit 0 job tandis que `/api/healthz` en
  voit 2 : corrélation de configuration/déploiement à faire avant toute action.
- **Fidélité borne (27/09/2026, 19:31 CEST)** : **4/4 en 6,8 s** sur les
  scénarios réels solde/erreur/inscription/auto-check, plus **1/1** register.
- **Frontend/tickets (27/09/2026)** : `Feature/Frontend` **58/58 en 11,48 s**
  et `Feature/Hardware` **164/164 en 12,24 s**; sauces HH/X, suppléments,
  tacos, frites, viandes, tickets et tiroir restent verts.
- **Restore-drill (27/09/2026)** : contrats locaux verts (**17/17 + 5/5 +
  5/5**), mais la prod reste `degraded` tant qu’un drill de restauration
  mesuré et attesté n’est pas fourni. Ne pas lancer d’opération destructive
  depuis l’agent.
- **Release guards (27/09/2026, 19:34 CEST)** : pricing/status lint verts
  (**86/38 fichiers**), bundles **15/15**; i18n parse **80 fichiers Laravel
  sans erreur**. Les clés manquantes restent une dette connue et le sign-off
  pricing est averti jusqu’au 27/10, sans échec actuel.

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
