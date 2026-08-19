# GOAL — Supervision, intégration et mise en production des 3 branches du 2026-08-19

> **Mission (verbatim propriétaire)** : « vérifie et act as supervisor et audit ce que les
> autres sessions ont fait et finis par test-e2e et deploy » — puis « tous » (les trois
> branches, pas une seule).
>
> **Rôle** : superviseur. Ce GOAL n'écrit pas de fonctionnalité nouvelle. Il **intègre**,
> **conteste**, **prouve** et **livre** le travail produit par trois sessions parallèles qui
> ne se sont jamais vues.
>
> **Écrit le** : 2026-08-19 · **Branche d'intégration** : `supervision/integration-2026-08-19`
> · **Worktree** : `.claude/worktrees/supervision-2026-08-19` · **Base** : `81e6a6ec5`

---

## §0 — Préambule

### §0.1 Décision sur l'arbre de travail (obligatoire avant toute vague)

Au démarrage, le dépôt principal portait **3 modifications non commitées** :

| Fichier | Nature | Décision |
|---|---|---|
| `tools/deploy-lecayenne.sh` | **Correctif réel non sauvegardé** : retire `php artisan config:cache` du déploiement VPS + corrige 2 messages d'aide qui le conseillaient | **INCLUS DANS LE PÉRIMÈTRE** — commit dédié en Vague 1 |
| `.claude/scheduled_tasks.lock` | Fichier de verrou de planificateur, supprimé | Ignoré (bruit d'outillage, non versionnable utilement) |
| `.claude/worktrees/clever-hypatia-1e4f84` | Pointeur de sous-module de worktree | Ignoré |

**Pourquoi le correctif de déploiement entre dans le périmètre et n'est pas mis de côté.**
Il désarme un piège documenté deux fois (2026-08-12 puis 2026-08-17) : le service fiscal gelé
`AuditLogService::secretFor()` lit l'override par caisse via un appel direct à `env()`. Or
`env()` rend **toujours `null`** une fois la configuration mise en cache — Laravel ne relit
plus le `.env`. Le secret retombe alors sur `config('fiscal.audit_secret')`, différent de celui
qui a signé la ligne de genèse, et `fiscal:verify-chain` crie **TAMPER** à tort. Une alarme
fiscale fausse coûte des heures et détruit la confiance dans le seul signal qui doit rester
crédible. Tant que ce correctif vit dans l'arbre de travail, une bascule de branche l'efface et
la Vague 7 réarme le piège. Il est donc **la toute première chose commitée**.

### §0.2 Ce que ce GOAL considère comme acquis (vérifié, pas supposé)

Constats établis par exécution réelle dans cette session, pas par lecture d'un rapport :

1. **Base commune unique** : `7ae8a9c4c` (17/08 22:01, « kds ») — identique pour les trois
   paires de branches. L'intégration est mécaniquement propre, sans historique enchevêtré.
2. **Surface de collision minuscule** : exactement **2 fichiers** touchés par plus d'une
   branche — `PROJECT_BRAIN.md` (attendu) et `resources/js/components/admin/pos/PosComponent.vue`
   (seul conflit de code réel, entre la branche caisse et la branche fidélité).
3. **Zones gelées touchées : 3 fichiers, tous par la branche caisse, tous sous LOCK** —
   `app/Domain/Order/OrderStateMachine.php` (34+/1−),
   `resources/js/components/admin/pos/PaymentComponent.vue` (59+/13−),
   `tests/Unit/Domain/Order/OrderStateMachineTest.php` (20+/5−).
   Le document `plans/LOCK_CAISSE_ANNULATION_ET_CARTE_2026-08-19.md` (217 lignes) existe, porte
   **« Gate propriétaire : OBTENU — questions posées et répondues explicitement en séance »**,
   et contient les 9 sections attendues (demande verbatim, diagnostic mesuré AVANT modification,
   portée exacte, ce qui n'a PAS été affaibli, preuves en navigateur réel, intégrité fiscale,
   cliquets, retour arrière, reste ouvert). Les empreintes SHA-256 ont été réalignées par le
   commit `b5fd9477c`.
4. **Le « barème à trancher owner » n'est pas un taux d'argent.** Le diff de `config/loyalty.php`
   n'ajoute **que** le drapeau `kiosk_email_capture` (+53 lignes, 0 suppression). Les taux gagner
   (10 pts/€) et dépenser (100 pts/€) ne sont pas touchés. Aucune répétition du crédit ×10 du
   2026-08-14.
5. **Banc de test réellement exécutable ici** : `vendor/` réel (pas un lien symbolique),
   `node_modules/` présent, `.env` en `APP_ENV=local`, `vendor/bin/phpunit`, `vitest run`,
   `playwright.config.js`. Preuve : `tests/Unit/Domain/Order/OrderStateMachineTest.php` →
   **82 tests, 98 assertions, OK** dans le worktree d'intégration.

### §0.3 Ce que ce GOAL refuse de tenir pour acquis

- **Trois branches vertes ne font pas une fusion verte.** Chaque session a validé son travail
  sur SA branche. Personne n'a exécuté un seul test sur la combinaison des trois. C'est
  exactement le trou que la fonction de superviseur existe pour boucher, et c'est la
  justification première de ce GOAL.
- **Un LOCK obtenu ne prouve pas que le cliquet a été réaligné honnêtement.** Réaligner une
  empreinte SHA-256 est indistinguable, dans un `git log`, entre « j'ai documenté un changement
  légitime » et « j'ai fait taire la sentinelle ». La Vague 3 doit lire le diff réel des 3
  fichiers gelés et le confronter à la portée déclarée au §4 du LOCK.
- **Un test écrit par l'auteur du correctif peut être creux.** Le rapport d'usage du projet le
  relève explicitement. La Vague 4 éprouve chaque test neuf par inversion (casser le code,
  vérifier que le test rougit).
- **`git push` ne prouve pas un déploiement.** La Vague 8 juge sur le **contenu servi**.

### §0.4 Pipeline par tâche et compétences déléguées

Ce GOAL ne redécrit aucune compétence amont. Chaque tâche s'exécute via
`~/.claude/skills/ultra-audit-profond/` ; l'audit visuel adversarial de la Vague 5 s'exécute via
`~/.claude/skills/test-e2e/` ; toute dérogation de zone gelée supplémentaire passerait par
`~/.claude/skills/lock-plan/`. Le fan-out parallèle suit
`superpowers:dispatching-parallel-agents`.

### §0.5 Critère de convergence (repris de `test-e2e`, non négociable)

> **Convergence atteinte = deux cycles consécutifs avec P0 + P1 = 0 ET ensembles de constats
> identiques.** Deux cycles qui trouvent des choses différentes ne convergent pas — ils
> clignotent. On reboucle.

---

## §1 — Carte des systèmes principaux (ancres vérifiées)

Ancres obtenues par `git diff --name-only 7ae8a9c4c..<branche>` — sortie réelle, pas supposée.

| # | Système | Maturité | Ancre principale vérifiée | Tests existants |
|---|---|---|---|---|
| S1 | Caisse (POS) | production, sous LOCK actif | `resources/js/components/admin/pos/PosComponent.vue`, `ItemComponent.vue`, `PaymentComponent.vue` (gelé), `resources/js/helpers/pos*.js` (5 aides) | 8 specs `tests/js/pos*.spec.js` + `tests/Unit/Domain/Order/OrderStateMachineTest.php` |
| S2 | Cuisine (KDS + tickets) | production | `app/Services/Hardware/KitchenBundledAddonCollapser.php`, `OrderReceiptEscPosRenderer.php`, `resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue` | `tests/Unit/Hardware/KitchenBundledAddonCollapserTest.php`, `tests/js/kdsBundledAddons.spec.js` |
| S3 | Borne (Kiosk) | production | `resources/js/components/frontend/kiosk/KioskCartComponent.vue`, `KioskLoyaltyComponent.vue`, `resources/js/store/modules/kioskCart.js` | `tests/js/kioskCart*.spec.js` (2), `tests/js/kioskLoyaltyDiscountConsistency.spec.js` |
| S4 | Fidélité (transversal caisse ↔ borne ↔ web) | neuf, chemin ARGENT | `app/Services/Loyalty/PosCartRedemption.php`, `app/Services/Order/OrderQuoteService.php`, `config/loyalty.php` | 6 suites `tests/Feature/Loyalty/*.php` |
| S5 | Comptes & apps magasins | neuf, chemin IDENTITÉ | `app/Services/Auth/SocialIdentityVerifier.php`, `app/Http/Middleware/RequireCustomerPhone.php`, `app/Models/User.php` | 5 suites `tests/Feature/{Auth,Apps}/*.php` |
| S6 | Intégration & déploiement | outillage | `tools/deploy-lecayenne.sh`, `tools/deploy-vps.sh` | (aucun test — vérification par exécution et contenu servi) |

### Répartition mesurée du travail des trois sessions

| Branche | HEAD | Commits | Fichiers | Systèmes couverts |
|---|---|---|---|---|
| `pos/category-first-caisse-2026-06-23` (**A**) | `81e6a6ec5` | 9 | 28 | S1, S2 |
| `apps-stores-auth-2026-08-19` (**B**) | `58cd4fafd` | 6 | 20 | S5 |
| `worktree-goal-fidelite-2026-08-19` (**C**) | `29daf6096` | 7 | 28 | S4, S3, S1 |

---

## §2 — Systèmes séparés (hors périmètre de déploiement)

Conformément au mandat propriétaire (CLAUDE.md §3bis), deux bases restent autonomes et **ne
sont pas câblées** aux API du backend :

- **Mobile RN** (`mobile/`) — aucun commit du 19/08. Hors périmètre.
- **Web autonome déployé** — le vrai dépôt est `lecayenne-web-deploy` (et **non** le miroir cité
  dans CLAUDE.md §3bis, qui est faux : voir `memory/piege_miroir_web_canonique_faux.md`).
  Concerné en Vague 7 **uniquement** si un des trois lots modifie un contrat consommé par le
  site (CORS, forme de réponse de commande). À trancher en T-7.2.1, pas par principe.

**Périmètre de « deploy » retenu** : VPS backend (caisse, borne, cuisine, admin, API) + site web
si un contrat bouge. **La soumission App Store / Google Play est HORS périmètre** — elle exige
une reconstruction native et une validation Apple, circuit séparé. Voir porte **G5**.

---

## §3 — Système S1 : Caisse (POS)

### Contrat
Le personnel encaisse vite : composer une commande, encaisser (espèces, carte, mixte), gérer le
tiroir, suivre les commandes en cours. Aucune régression tolérée sur le chemin argent.

### Zones gelées concernées (CLAUDE.md §7)
- `resources/js/components/admin/pos/PaymentComponent.vue` — **TOUCHÉ (59+/13−), sous LOCK**
- `app/Domain/Order/OrderStateMachine.php` — **TOUCHÉ (34+/1−), sous LOCK**
- `public/js/pos-wizard.js`, `public/css/pos-wizard.css`, `resources/views/admin-pos-v4.blade.php`
  — **non touchés**, doivent le rester (diff attendu = 0 ligne)

### Ancres vérifiées
`resources/js/components/admin/pos/{PosComponent,ItemComponent,PaymentComponent,PosOrdersTrackerComponent,PosLoyaltyIdentifyModal}.vue`,
`resources/js/helpers/{posCartCompactDisplay,posQuickAdd,posServiceDay,posWizardInstruction}.js`,
`resources/css/pos-v5.css`, `app/Http/Controllers/Admin/PosController.php`

### Sub 1.1 — Dérogation de zone gelée : est-elle honnête ?
**Ancres** : `plans/LOCK_CAISSE_ANNULATION_ET_CARTE_2026-08-19.md`,
`app/Domain/Order/OrderStateMachine.php`, `resources/js/components/admin/pos/PaymentComponent.vue`,
`tests/Feature/Sentinels/frozen-zone-sha256-baseline.json`
**Tâches** :
- T-1.1.1 Lire le diff **ligne à ligne** des 2 fichiers gelés et le confronter au §4 du LOCK
  (« portée exacte des modifications »). Tout ajout hors portée déclarée = P0.
- T-1.1.2 Vérifier que `OrderStateMachine::allows()` **élargit** une transition (annuler après
  « Prêt ») sans **affaiblir** une garde existante — en particulier qu'aucune transition vers un
  état encaissé/scellé n'est ouverte.
- T-1.1.3 Vérifier que le réalignement SHA-256 (`b5fd9477c`) correspond aux fichiers réellement
  modifiés et **à eux seuls** — un réalignement plus large serait un bâillon, pas une mise à jour.
- T-1.1.4 Vérifier que le §5 du LOCK (« ce qui n'a PAS été affaibli ») est étayé par un test et
  pas seulement par une affirmation.
**Acceptation** : `tests/Unit/Domain/Order/OrderStateMachineTest.php` PASS (82 tests, référence
mesurée ce jour) + sentinelle empreintes gelées PASS + rapport écrit de concordance LOCK ↔ diff.

### Sub 1.2 — Panier, ajout rapide et lisibilité
**Ancres** : `resources/js/helpers/posQuickAdd.js`, `posCartCompactDisplay.js`,
`resources/css/pos-v5.css`, `resources/js/components/admin/pos/ItemComponent.vue`
**Tâches** :
- T-1.2.1 Ajout en un seul appui pour un produit sans option — vérifier qu'un produit **avec**
  option ouvre toujours son assistant (le raccourci ne doit pas sauter un choix obligatoire).
- T-1.2.2 Panier passé de 40 px à 237 px — vérifier par mesure réelle (`getBoundingClientRect`),
  pas par appréciation, et sur les deux hauteurs d'écran utilisées en salle.
- T-1.2.3 Doublon d'instruction à l'édition (ticket + écran) — vérifier qu'un aller-retour
  d'édition n'empile plus le texte.
**Acceptation** : `tests/js/posQuickAdd.spec.js`, `tests/js/posCartCompactDisplay.spec.js`,
`tests/js/posCartEditInstructionDuplication.spec.js`, `tests/js/posTileInstantOpen.spec.js` PASS
+ capture navigateur analysée de `/admin/pos`.

### Sub 1.3 — Encaissement et suivi
**Ancres** : `resources/js/components/admin/pos/PaymentComponent.vue`, `PosOrdersTrackerComponent.vue`,
`resources/js/helpers/posServiceDay.js`
**Tâches** :
- T-1.3.1 Carte sans code à 4 chiffres — vérifier qu'aucun ticket n'est produit si le terminal
  refuse (leçon du 2026-08-08 : faux ticket après carte REFUSÉE).
- T-1.3.2 Journée de SERVICE — la commande de 23 h 50 survit à minuit ; éprouver le passage de
  jour **et** le changement d'heure.
- T-1.3.3 Bouton d'annulation : affiché **si et seulement si** l'action est réellement permise.
**Acceptation** : `tests/js/posPaymentCardNoFourDigits.spec.js`, `tests/js/posServiceDay.spec.js`,
`tests/js/posAvailabilityLiveGuard.spec.js` PASS + `tests/js/quickwins/discountReasonBindingTest.spec.js` PASS.

---

## §4 — Système S2 : Cuisine (KDS + tickets)

### Contrat
La cuisine voit chaque commande une fois, complète, sans doublon, et le ticket papier dit la
même chose que l'écran.

### Zones gelées concernées
Aucune. `OrderReceiptEscPosRenderer.php` n'est pas dans la liste §7 mais est **générateur de
ticket** : tout changement s'y prouve par un rendu réel, jamais par lecture.

### Ancres vérifiées
`app/Services/Hardware/KitchenBundledAddonCollapser.php`, `app/Services/Hardware/OrderReceiptEscPosRenderer.php`,
`resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue`, `resources/js/helpers/kdsBundledAddons.js`

### Sub 2.1 — La formule n'est plus écrite deux fois
**Tâches** :
- T-2.1.1 Vérifier le repli du menu groupé côté **service** (`KitchenBundledAddonCollapser`) et
  côté **écran** (`kdsBundledAddons.js`) — les deux doivent produire le même regroupement, sinon
  écran et ticket divergeront à nouveau.
- T-2.1.2 Éprouver le cas limite : formule **partiellement** modifiée (un supplément sur un seul
  composant) — le repli ne doit pas masquer la modification.
- T-2.1.3 Vérifier qu'un texte déjà emballé ne repasse pas dans le générateur gelé (cause racine
  du doublon d'origine).
**Acceptation** : `tests/Unit/Hardware/KitchenBundledAddonCollapserTest.php` PASS +
`tests/js/kdsBundledAddons.spec.js` PASS + rendu ESC/POS capturé et lu pour une formule réelle.

### Sub 2.2 — Non-régression de l'écran cuisine
**Tâches** :
- T-2.2.1 Le tableau ne se fige pas à la première commande — piège `<Teleport>` + `v-if` du
  2026-08-17 (le contenu passait de non-monté à monté après le montage initial).
- T-2.2.2 « Remettre en préparation » reste disponible après passage à « Prêt ».
**Acceptation** : `/kds` chargé en navigateur réel, une commande injectée, capture analysée,
console sans erreur.

---

## §5 — Système S3 : Borne (Kiosk)

### Contrat
Le client compose seul, paie (ou est routé vers le comptoir, Plan B), et depuis le 19/08 peut
**dépenser ses points**. Mode clair imposé, palette Cayenne.

### Zones gelées concernées
- `KioskWizardComponent.vue`, `KioskAppComponent.vue`, `KioskUpsellComponent.vue` — **non
  touchés** par la branche C (vérifié). Diff attendu = 0 ligne.
- `KioskCartComponent.vue` et `KioskLoyaltyComponent.vue` **ne sont pas** dans la liste gelée.

### Ancres vérifiées
`resources/js/components/frontend/kiosk/{KioskCartComponent,KioskLoyaltyComponent}.vue`,
`resources/js/store/modules/kioskCart.js`, `config/kiosk.php`

### Sub 3.1 — Dépenser ses points à la borne
**Tâches** :
- T-3.1.1 Vérifier que la remise passe par le **devis scellé** et jamais par un total calculé
  côté client (invariant NF525 : le prix est backend, le frontend n'envoie que des identifiants).
- T-3.1.2 Vérifier la porte promo × fidélité : une remise fidélité ne doit pas se cumuler
  silencieusement avec un code promo si la règle l'interdit.
- T-3.1.3 Vérifier la charge utile envoyée par la borne (`kioskCartSendPayload`) — aucun montant
  décidé par le client.
**Acceptation** : `tests/Feature/Loyalty/KioskRedeemThroughSealedQuoteTest.php` PASS +
`tests/js/kioskCartPromoGate.spec.js`, `tests/js/kioskCartSendPayload.spec.js`,
`tests/js/kioskLoyaltyDiscountConsistency.spec.js` PASS + capture `/kiosk/idle` → panier analysée.

### Sub 3.2 — Inscription à la borne et conservation de l'email
**Tâches** :
- T-3.2.1 Éprouver **les deux** positions du drapeau `kiosk_email_capture` (true et false) — le
  test doit couvrir la position de repli, sinon l'interrupteur est décoratif.
- T-3.2.2 Vérifier qu'un email déjà porté par un autre compte donne 409 **sans création**.
- T-3.2.3 Vérifier que `email_verified_at` reste `NULL` (une adresse déclarée n'est pas une preuve)
  et qu'aucun email n'est posé sur un compte préexistant (correctif détournement 2026-07-02).
**Acceptation** : `tests/Feature/Loyalty/KioskRegisterKeepsEmailTest.php` +
`tests/Feature/Loyalty/LoyaltyRegisterAllowsWebLoginTest.php` PASS **dans les deux positions**.

---

## §6 — Système S4 : Fidélité (transversal — chemin ARGENT)

### Contrat
Le client gagne des points en payant, les dépense en remise. Le solde est un grand-livre, jamais
un `UPDATE` brut. Gagner et dépenser n'ont **pas** le même taux — les confondre a déjà produit un
crédit ×10 en production le 2026-08-14.

### Zones gelées concernées
`app/Services/Pricing/PricingService.php` — **non touché** (vérifié). Doit le rester.
`app/Services/Order/OrderQuoteService.php` et `OrderService.php` sont touchés : non gelés, mais
ce sont les gardiens du devis scellé.

### Ancres vérifiées
`app/Services/Loyalty/PosCartRedemption.php`, `app/Http/Controllers/Frontend/LoyaltyController.php`,
`app/Http/Controllers/Admin/PosController.php`, `app/Http/Requests/{OrderRequest,PosOrderRequest}.php`,
`app/Services/{OrderService,FrontendOrderService}.php`, `config/loyalty.php`

### Sub 4.1 — Ordre des écritures : débiter APRÈS le sceau
**Tâches** :
- T-4.1.1 Vérifier que le débit des points survient **après** le scellement du devis. C'est le
  cœur du correctif `4be4c288c` : quand devis et création doivent s'accorder au centime, l'ordre
  des écritures fait partie du contrat, pas de la mise en forme.
- T-4.1.2 Éprouver l'échec **entre** les deux écritures : si la création de commande échoue après
  le débit, les points doivent revenir. Chercher le chemin de recrédit et le prouver.
- T-4.1.3 Vérifier la moissonneuse d'orphelins (`orphan_redeem_reap_minutes`, 30 min par défaut)
  — un pré-débit sans commande ne doit pas immobiliser les points définitivement.
**Acceptation** : `tests/Feature/Loyalty/PosCartRedeemBeforePaymentTest.php` PASS +
`tests/Feature/Loyalty/PosOrderCarriesLoyaltyCodeTest.php` PASS + une vente réelle rejouée en
Vague 5 avec vérification du solde avant/après en base.

### Sub 4.2 — Ne rien promettre que le panier refuse
**Tâches** :
- T-4.2.1 Vérifier que les paliers affichés sont **atteignables** avec le barème réel (correctif
  `4dfe598fc`) — un palier affiché mais inatteignable est un mensonge au client.
- T-4.2.2 Vérifier que la remise proposée ne dépasse jamais ce que le panier accepte.
- T-4.2.3 **Confronter les taux** : gagner et dépenser doivent rester distincts et non
  intervertis. Le diff de `config/loyalty.php` ne les touche pas — le confirmer sur le code fusionné.
**Acceptation** : `tests/Feature/Loyalty/LoyaltyConfigTiersHonestTest.php` PASS + lecture directe
des deux taux dans le code fusionné, consignée dans le rapport.

### Sub 4.3 — Rattachement du client pendant la vente
**Tâches** :
- T-4.3.1 Vérifier que le rattachement se fait **pendant** la vente et pas seulement après
  (correctif `dacc161df`) — le contexte : 1817 ventes caisse pour 12 rattachées.
- T-4.3.2 Vérifier que `PosOrderRequest` porte bien le code fidélité et que l'autorisation du
  FormRequest n'a pas régressé (cliquet `FormRequestAuthzDriftSentinelTest`, référence 64).
- T-4.3.3 Vérifier qu'un rattachement ne peut pas viser le compte d'un autre client.
**Acceptation** : `tests/js/posLoyaltyAttachCart.spec.js` PASS +
`tests/Feature/Sentinels/FormRequestAuthzDriftSentinelTest.php` PASS (référence inchangée ou
resserrée, **jamais** desserrée).

---

## §7 — Système S5 : Comptes & apps magasins (chemin IDENTITÉ)

### Contrat
Un client s'identifie par Apple, Google ou téléphone. Un numéro **déclaré** ne vaut pas
possession. La suppression de compte efface réellement (exigences Apple 5.1.1(v) et Google Play).

### Zones gelées concernées
Aucune. Mais `app/Models/User.php` est modifié : `BranchScope` fait un **no-op explicite** sur
`User` (vérifié 2026-08-14, contredit CLAUDE.md §9 qui a été corrigé). Sans effet en V1
mono-branche ; à ne pas aggraver.

### Ancres vérifiées
`app/Services/Auth/SocialIdentityVerifier.php`, `app/Http/Controllers/Auth/{SocialAuthController,DeactivateController}.php`,
`app/Http/Middleware/RequireCustomerPhone.php`, `app/Http/Kernel.php`, `app/Models/User.php`,
`config/{cors,services}.php`, `routes/api.php`,
`database/migrations/2026_08_19_100000_add_social_identity_to_users_table.php`,
`database/migrations/2026_08_19_140000_add_contact_phone_to_users_table.php`

### Sub 5.1 — Squattage de numéro : la porte est-elle vraiment fermée ?
**Tâches** :
- T-5.1.1 Vérifier qu'un numéro **déclaré** via Apple/Google ne devient jamais une identité
  vérifiée sans preuve de possession.
- T-5.1.2 **Piège connu** : `users.phone` est `NOT NULL` avec une sentinelle `PENDING_`. Un
  contrôle par `filled()` rend donc le verrou décoratif (toute valeur sentinelle passe). Vérifier
  que le contrôle ne tombe pas dans ce piège.
- T-5.1.3 Vérifier qu'un client **existant** légitime n'est pas enfermé dehors par le nouveau
  middleware `RequireCustomerPhone` — ordre d'enregistrement dans `Http/Kernel.php` inclus.
**Acceptation** : `tests/Feature/Auth/SocialPhoneSquattingTest.php` +
`tests/Feature/Apps/NumeroJoignableCaisseTest.php` PASS + une commande passée par un compte
préexistant sans téléphone en Vague 5.

### Sub 5.2 — Suppression de compte et clés de fournisseur
**Tâches** :
- T-5.2.1 Vérifier que la suppression efface réellement, **sans** casser les commandes passées ni
  la chaîne fiscale (les commandes historiques doivent survivre à l'effacement du client).
- T-5.2.2 Vérifier qu'un identifiant de clé inconnu ne provoque pas de martèlement d'Apple/Google
  (correctif `58cd4fafd`) — pas de boucle de récupération de clés.
- T-5.2.3 Vérifier qu'aucune comparaison d'un `roles.id` à une constante ne bloque toute
  suppression (piège relevé le 19/08).
**Acceptation** : `tests/Feature/Auth/AccountDeletionTest.php` + `tests/Feature/Auth/SocialAuthTest.php` PASS
+ `php artisan fiscal:verify-chain --all` inchangé après une suppression d'essai.

### Sub 5.3 — Origine des applications empaquetées (CORS)
**Tâches** :
- T-5.3.1 Vérifier que `https://localhost` **sans port** est autorisé — c'est l'origine réelle
  d'une application Capacitor, et son absence refusait toute commande alors que la carte
  s'affichait (donc « ça a l'air de marcher »).
- T-5.3.2 Vérifier que l'élargissement CORS n'ouvre pas l'API à une origine quelconque —
  contrôler la liste finale, pas seulement le fait que le test passe.
- T-5.3.3 Croiser avec le garde de production `CorsAppUrlProductionGuardSentinelTest`.
**Acceptation** : `tests/Feature/Apps/AppOriginCorsTest.php` +
`tests/Feature/Sentinels/CorsAppUrlProductionGuardSentinelTest.php` PASS + préflight réel en Vague 8.

---

## §8 — Système S6 : Intégration & déploiement

### Contrat
Ce qui est prouvé en local arrive **entier** en production, et on le juge sur le contenu servi.

### Ancres vérifiées
`tools/deploy-lecayenne.sh` (modifié, non commité au départ), `tools/deploy-vps.sh`,
`resources/views/master.blade.php` (touché par C — la coquille, donc surface partagée)

### Sub 6.1 — Fusion des trois lots
**Tâches** :
- T-6.1.1 Fusionner **B** (apps/auth) : aucun fichier de code en collision attendu, seul
  `PROJECT_BRAIN.md` conflit.
- T-6.1.2 Fusionner **C** (fidélité) : conflit réel attendu sur
  `resources/js/components/admin/pos/PosComponent.vue` — A y a travaillé le panier/ajout rapide,
  C y a greffé l'identification fidélité. **Résolution manuelle, jamais « prendre le nôtre ».**
- T-6.1.3 Réconcilier `PROJECT_BRAIN.md` §2 : conserver les **trois** récits, pas le dernier
  écrivain.
- T-6.1.4 Vérifier que les 2 migrations de B s'appliquent après l'état de A et C.
**Acceptation** : `git diff --stat` de la fusion revu fichier par fichier + `php artisan migrate --pretend`
sans erreur + aucune ligne perdue sur `PosComponent.vue` (comparaison des deux côtés du conflit).

### Sub 6.2 — Déploiement lui-même
**Tâches** :
- T-6.2.1 Commiter le correctif `tools/deploy-lecayenne.sh` **avant tout** (§0.1).
- T-6.2.2 Copier le script hors du dépôt avant exécution — un script de déploiement ne doit
  jamais se modifier en cours de route (incident documenté).
- T-6.2.3 `git status` **sur le serveur** avant tout `reset --hard` — le 14/08, 141 modifications
  non commitées vivaient sur le VPS et ont dû être mises de côté pour ne pas être détruites.
- T-6.2.4 Ne **jamais** rétablir `config:cache` (§0.1).
**Acceptation** : journal de déploiement complet + `fiscal:verify-chain --all` vert après
déploiement + contenu servi comparé au HEAD attendu.

---

## §A — Armée d'agents et matrice de déclenchement

### Rôles mobilisés

| Rôle | Type | Outils | Cible dans ce GOAL |
|---|---|---|---|
| Architecte | `Plan` | lecture seule | cohérence de la fusion, contrats traversants |
| Sécurité | `general-purpose` | lecture seule | S5 (identité), S4 (argent), CORS |
| Fiscal / NF525 | `general-purpose` | lecture seule | S1 zones gelées, chaîne d'audit, Z |
| DBA | `general-purpose` | lecture seule | 2 migrations, `User`, index, N+1 |
| Testeur | `general-purpose` | lecture + Bash | tests creux, couverture réelle des correctifs |
| RED-team | `general-purpose` | lecture seule | contestation de chaque constat |
| QA visuel | `general-purpose` | Playwright | captures des surfaces touchées |
| RED visuel | `general-purpose` | lecture | ré-analyse indépendante des mêmes captures |

### Matrice de déclenchement par type de tâche

| Type de tâche | Arch | Sécu | Fiscal | DBA | Test | RED | QA vis | RED vis |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| Zone gelée sous LOCK (S1.1) | x | x | x | . | x | x | . | . |
| Argent / fidélité (S4) | x | x | x | x | x | x | x | x |
| Identité / auth (S5) | x | x | . | x | x | x | . | . |
| Migration / schéma (S5, S6) | x | x | . | x | x | x | . | . |
| Interface (S1.2, S2, S3) | x | . | . | . | x | x | x | x |
| Traversant borne→caisse→cuisine | x | x | x | x | x | x | x | x |

### Discipline de dispatch
- Les spécialistes en **lecture seule** partent en **un seul message**, en parallèle.
- **Jamais deux implémenteurs en parallèle** (conflit d'écriture).
- La contestation RED intervient **après** le correctif, **avant** toute déclaration de fin.
- Chaque agent écrit son rapport sur disque —
  `reports/test-e2e/supervision-2026-08-19/<vague>/<role>.md` — la synthèse se fait **depuis le
  disque**, jamais depuis le contexte : c'est ce qui survit à une coupure de quota.

### Contrat de restitution (obligatoire, sinon constat rejeté)
```
[P0|P1|P2|P3] <fichier>:<ligne> — <titre en une ligne>
  reproduction : <commande exacte ou chemin de clics>
  preuve       : <capture | erreur console | nom du test>
  correctif    : <proposition de portée minimale>
```
Tout constat sans `file:line` **vérifié** par `grep`/lecture est **rejeté** et n'est pas remonté
au propriétaire. Trois sessions passées ont halluciné des P0 contre des fichiers inexistants.

---

## §X — Vagues de convergence

> Défaut : **séquentiel entre vagues**, **parallèle à l'intérieur** de la phase d'audit d'une vague.

### Vague 1 — Pré-vol (séquentiel)
**Portée** : sauvegarde et lignes de base.
- Branche de secours `backup/pre-supervision-2026-08-19` depuis `81e6a6ec5`.
- Commit du correctif `tools/deploy-lecayenne.sh` (§0.1).
- Lignes de base mesurées : compte PHPUnit, compte Vitest, `SELECT count(*), MAX(current_hash) FROM audit_logs`.
- Dump base de données avant migrations.
**Point de contrôle** : les 3 branches sont intactes, la sauvegarde existe, les lignes de base sont écrites.

### Vague 2 — Intégration (séquentiel, obligatoirement)
**Portée** : S6.1. Fusion B puis C, résolution des 2 conflits.
**Point de contrôle** : `PosComponent.vue` fusionné à la main et relu ; `PROJECT_BRAIN.md` porte
les trois récits ; `migrate --pretend` propre ; **aucun test lancé encore** (c'est la Vague 4).

### Vague 3 — Audit superviseur (fan-out parallèle, lecture seule)
**Portée** : S1.1 (zones gelées), S4 (argent), S5 (identité), S2/S3 (interfaces).
6 spécialistes en un seul message. Chacun écrit sur disque.
**Point de contrôle** : tous les rapports sur disque ; chaque P0/P1 reproduit ou rejeté avec
preuve ; aucun constat sans `file:line` vérifié.

### Vague 4 — Porte technique (séquentiel)
**Portée** : la suite complète sur le **code fusionné** — ce que personne n'a encore fait.
- PHPUnit complet, Vitest complet.
- Sentinelles : empreintes zones gelées, `FormRequestAuthzDrift` (référence 64),
  `BranchScopeCoverageSentinel`, `CommittedSecretsScan`.
- `php artisan fiscal:verify-chain --all`.
- **Épreuve d'inversion** : pour chaque test neuf des trois lots, casser le code couvert et
  vérifier que le test rougit. Un test qui reste vert est creux → supprimé ou réparé.
**Point de contrôle** : 0 échec, ou échec documenté avec raison écrite. Diff zones gelées hors
LOCK = 0 ligne.

### Vague 5 — test-e2e adversarial (compétence `test-e2e`)
**Portée** : navigateur réel sur les surfaces touchées + contrats traversants.
- Surfaces : `/admin/pos`, `/kds`, `/kiosk/idle`, `/admin/order-status-screen`, site web.
- Contrat traversant n°1 : commande → file caisse → écran cuisine → ticket.
- Contrat traversant n°2 : fidélité — gagner à la caisse, dépenser à la borne, solde en base
  avant/après.
- Contrat traversant n°3 : compte sans téléphone → commande possible ou refus **explicite**.
- Équipe QA visuelle et équipe RED visuelle analysent les **mêmes** captures séparément.
**Point de contrôle** : 0 libellé brut, 0 rupture de mise en page, 0 erreur console, les 3
contrats prouvés de bout en bout.

### Vague 6 — Convergence
Reboucler Vagues 3→5 jusqu'à **deux cycles consécutifs avec P0+P1=0 et constats identiques**
(§0.5). Maximum 3 boucles de réparation sur un même amas ; à la 3e, protocole d'échec de
convergence : arrêt, analyse, quatre options présentées au propriétaire, **aucun choix
automatique**.

### Vague 7 — Déploiement (porte propriétaire G4 obligatoire avant)
**Portée** : S6.2. VPS backend ; site web seulement si un contrat bouge (T-7.2.1 tranche).
`git status` sur le serveur → sauvegarde → déploiement → `fiscal:verify-chain`.
**Point de contrôle** : journal complet, chaîne fiscale verte, aucune fausse alarme TAMPER.

### Vague 8 — Vérification post-déploiement
- Comparer le **contenu servi** au HEAD attendu (jamais conclure depuis un `push`).
- Fumée en production : caisse charge et montre les commandes espèces **et** carte ; une commande
  web arrive en caisse et à la cuisine ; le ticket s'imprime ou le pont accuse réception ;
  les points se créditent au bon taux ; admin sans erreur console ni 500.
- Si un seul contrôle échoue → **retour arrière immédiat**, puis diagnostic écrit.
**Point de contrôle** : `PROJECT_BRAIN.md` §2/§3 à jour, étiquette de version posée, épisode
Graphiti poussé.

### Protocole d'interruption (coupure de quota / fin de session)
1. Commiter le partiel (`wip(vague-N): partiel jusqu'à T-x.y.z`).
2. Écrire `reports/test-e2e/supervision-2026-08-19/INTERRUPT_<vague>_<horodatage>.md` :
   dernier SHA vert, dernière tâche tentée + statut, prochaine tâche, rapports déjà sur disque.
3. Mettre à jour `PROJECT_BRAIN.md` §2.
4. À la reprise : lire le manifeste, `git status`, rejouer la dernière tâche en fumée, continuer.

---

## §G — Portes propriétaire

| Porte | Description | QUI | QUOI | OÙ | Statut |
|---|---|---|---|---|---|
| **G1** | Paliers fidélité : confirmer que les paliers affichés correspondent au barème voulu commercialement | Propriétaire | Confirmation orale ou écrite des paliers | `config/loyalty.php` + `LoyaltyConfigTiersHonestTest` | **EN ATTENTE** |
| **G2** | `kiosk_email_capture = true` : risque résiduel de squattage à la borne, assumé et chiffré (plancher 1000 pts = 10 €) | Propriétaire | Re-confirmation avant production | `config/loyalty.php` (les 2 positions y sont écrites) | **EN ATTENTE** (arbitré le 19/08, à reconfirmer) |
| **G3** | LOCK caisse — dérogation zones gelées | Propriétaire | **DÉJÀ OBTENU** en séance | `plans/LOCK_CAISSE_ANNULATION_ET_CARTE_2026-08-19.md` en-tête | **OBTENU** |
| **G4** | Poussée vers `origin` + déploiement production (CLAUDE.md §10) | Propriétaire | « GO » explicite | message de session + journal de déploiement | **EN ATTENTE** |
| **G5** | Soumission App Store / Google Play | Propriétaire | Décision de périmètre | ce GOAL §2 | **HORS PÉRIMÈTRE sauf avis contraire** |

**Protocole d'attente** : G1, G2 et G5 **ne bloquent pas** les Vagues 1 à 6 — elles bloquent
uniquement la Vague 7. Les Vagues 1 à 6 s'exécutent pendant que le propriétaire tranche.
G4 bloque strictement les Vagues 7 et 8.

---

## §R — Références

- `CLAUDE.md` §§4–13 · `CONSTITUTION.md` · `PROJECT_BRAIN.md` §2 · `SYSTEM_MAP.md`
- `plans/LOCK_CAISSE_ANNULATION_ET_CARTE_2026-08-19.md` (dérogation active)
- `memory/piege_config_cache_secret_fiscal_2026-08-12.md` (piège TAMPER, §0.1)
- `memory/piege_miroir_web_canonique_faux.md` (le vrai dépôt web est `lecayenne-web-deploy`)
- `memory/index_git_partage_commit_non_atomique_2026-08-12.md` (index git partagé entre sessions)
- `memory/fidelite_credit_manuel_bug_bareme_facteur10_2026-08-14.md` (incident ×10)
- `memory/goal_fidelite_portes_fermees_2026-08-19.md` · `memory/goal_terrain_caisse_cuisine_2026-08-19.md`
  · `memory/apps_stores_capacitor_pieges_2026-08-19.md` (les 3 lots à intégrer)
- Compétences : `ultra-audit-profond`, `test-e2e`, `lock-plan`, `superpowers:dispatching-parallel-agents`

---

## §F — Règle finale : ce que « fini » veut dire

Ce GOAL est terminé quand, et seulement quand :

1. Les **trois** lots du 19/08 vivent sur une seule ligne d'historique, sans qu'une seule ligne
   de code d'une session ait été perdue dans la fusion.
2. La suite complète passe sur le **code fusionné** — pas sur trois branches séparées.
3. Chaque test neuf a été **éprouvé par inversion** ; aucun test creux ne subsiste.
4. Le diff des zones gelées hors LOCK vaut **0 ligne**, et le LOCK actif concorde avec le diff réel.
5. Les trois contrats traversants sont prouvés en navigateur réel, captures analysées.
6. Deux cycles consécutifs rendent **P0 + P1 = 0 avec des constats identiques**.
7. Le déploiement est jugé sur le **contenu servi**, la chaîne fiscale est verte, et aucune
   fausse alarme TAMPER n'a été produite.

**Partiel vaut mieux que faux. Bloqué vaut mieux que dangereux en silence.** Si un lot ne peut
pas être livré proprement, il est isolé et signalé — les deux autres partent quand même, et le
propriétaire décide. Ce qu'on ne fait pas : annoncer « déployé » sans avoir regardé ce que le
serveur sert réellement.
