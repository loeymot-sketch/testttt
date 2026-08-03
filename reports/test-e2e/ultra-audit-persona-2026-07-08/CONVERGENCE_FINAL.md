# 🎯 ULTRA-AUDIT + TEST PERSONA RÉEL — 4 systèmes — CONVERGENCE (2026-07-08)

**Mandat owner** : « ultra audit de chaque fonctionnalité de chaque système (caisse, borne,
site web, mobile) + test d'utilisation RÉEL avec raisonnement persona, tester TOUS les
scénarios de commande, corriger jusqu'à validé. D'abord audit archi → plan → exécution
disciplinée → validation profonde → correction + test-e2e. »

**Env** : local `:8766`, HEAD `6b0a0ac1e` + fixes de session (bundle rebuild `40570cb8`).
**Méthode** : Phase A (4 agents audit archi, file:line vérifiés, anti-hallucination) → Phase B
(mappe + matrice persona) → Phase C (tests réels borne/caisse/KDS/web/mobile) → Phase D (fix + e2e).

---

## ✅ CE QUI EST PROUVÉ VERT (evidence réelle)

### 1. Architecture des 4 systèmes — cartographiée (fiches file:line vérifiées)
- **Borne** : 16 fonctionnalités (idle→type→catégories→wizard 8 templates→panier→upsell→devis signé→paiement Plan B→n° A00xx→impression→inactivité→offline queue→erreurs→fidélité→i18n→menu unifié). Défensive (guards routes, quotes HMAC, offline queue).
- **Caisse** : 10 sections (grille catégories-first→wizard frozen→panier→types commande→téléphone différé→parked→remise+motif→paiement espèces/carte/multi→file à-encaisser 📞→session tiroir/Z/n°/impression/sync).
- **Site web** : le **STANDALONE React** (`/Users/1millnonstop/Downloads/web/`) est le vrai site prod (câblé caisse `source=5`) ; la vitrine Vue Laravel est **verrouillée OFF** (`staff_only_mode=true`).
- **Mobile** : prototype React (pas RN), 38 produits **alignés nom+prix au canon DB**, palette conforme, tous assets présents.

### 1bis. Boucle ARGENT complète + scellement NF525 — **PROUVÉE bout-en-bout (cash ET carte)**
Le maillon le plus critique, désormais testé e2e :
- **Espèces** : borne order #5574 (A0045) PENDING_COUNTER (fiscal_seq NULL) → `POST counter-collect/confirm` mode=1 reçu 10,00 € → **200 OK** → `payment_status=5` (PAYÉ), **`fiscal_sequence_no=2640` alloué au close** (NF525 : alloc à l'encaissement pour le différé comptoir), **rendu 3,10 €** correct.
- **Carte (TPE simulé)** : borne order #5575 (A0046) → confirm mode=2 → **200 OK** → PAYÉ, **`fiscal_sequence_no=2641`**.
- **Intégrité NF525** : `audit_logs` **append-only** (+2 par encaissement, chaîne signée) · séquences **2637→2641 monotone gap-free** · **`fiscal:verify-chain --all` = CHAIN OK sur les 4 branches AVANT ET APRÈS**. Aucune altération, aucun trou.

### 2. Synchro cross-surface BORNE → CAISSE → KDS — **PROUVÉE bout-en-bout**
Commande borne réelle créée via le vrai flux **quote→order** (Tacos M + Poulet + Algérienne + **Oignons cuits**, comptoir espèces) :
- Order **#5561**, queue **A0033** (démarrage-32 respecté), `payment_status=15` (PENDING_COUNTER — fiscal NON alloué = **NF525-correct** pour le différé comptoir), `order_type=10`, `source=5`.
- Prix **6,90 €** cohérent : devis == item order == attendu.
- `composition_snapshot` figé (NF525) : Viande=Poulet mariné, Sauce=Algérienne, extra Oignons cuits. ✓
- **Présent dans la caisse « à encaisser »** (badge « À encaisser 33 », boutons 🖨️ Cuisine / 🖨️ Client / Encaisser visibles). ✓ (capture `c2-caisse-pos.png`)
- **Présent au KDS** : carte `N° A0033`, badges NOUVELLE + BORNE, ligne symbolique `1× G | TAC | M | P | O | ALG`, note « Test sync C-7e2ff », bandeau « EN ATTENTE ENCAISSEMENT ». ✓ (capture `c3-kds.png`)

### 3. Visuel réel (captures analysées)
- **Borne idle** (portrait 1080×1920) : attract Grill Burger, 100% Halal, « Touchez l'écran », on-brand. (`c1-borne-idle.png`)
- **Borne catégories/Boissons** : 15 produits, prix exacts (Eau 1,00 / Capri-Sun 1,50 / canettes 1,90), badges Halal/Végé. (`c1-borne-categories.png`)
- **Borne wizard Tacos M** : étapes Viande→Sauce→**Crudité**→Suppléments→Menu→Récap, base 6,90 €, 7 viandes. (`c1-borne-tacos-wizard-step1.png`)
- **Caisse** : landing catégories-first (9 cats), file à-encaisser fonctionnelle. (`c2-caisse-pos.png`)
- **KDS** : rendu plein écran, symbolique correcte. (`c3-kds.png`)

### 4. Régressions techniques VERTES
- `QueueNumberConcurrencyTest` : **6/6** (dont « daily queue starts at configured start number both surfaces » = A0032).
- `FrozenZoneSha256BaselineSentinelTest` : **frozen zones intactes** (SHA baseline OK).
- `posDrawerBridgeFallback.spec.js` : **3/3** (tiroir pont fallback).
- `mobile/tests/menu.spec.js` : **ALL PASSED** (mobile + web standalone alignés canon).

---

## 🔧 CORRECTIONS APPLIQUÉES CETTE SESSION (non-frozen, sûres)

| # | Fix | Fichier | Preuve |
|---|---|---|---|
| F1 | **Test menu mobile/web réparé** (8 échecs → 0). Data correcte (crudités 4, suppléments 10, Boule gratinée 1,00€, Menu Enfant Chicken Burger), assertions périmées post-sync 2026-07-08 mises à jour. | `mobile/tests/menu.spec.js` | `node tests/menu.spec.js` = ALL PASSED |
| F2 | **194 warnings console idle → 0.** `text(key, fallback)` consultait `$t` même clé absente (flood `idle_screen.badge_*/eyebrow` à chaque rendu). Guard `$te()` : même repli FR, zéro warning. | `KioskIdleScreenComponent.vue` (non-frozen) | idle rebuild : **0 warnings** (était 194), UI identique |

**Frozen zones** : **0 fichier frozen touché cette session** (vérifié `git diff` + sentinel SHA vert).

---

## ⚠️ FINDINGS À ARBITRER (owner gate / décision)

### G1 — [P2, FROZEN GATE] Aperçu tarif : 1× `POST /pricing/preview` → **422** à l'ouverture du wizard
- **Cause racine** (vérifiée) : le watcher `resolvedItem` (`KioskWizardComponent.vue:2396`) déclenche `refreshServerPreviewTotal()` sur la **résolution initiale** de l'item (null→id), donc un preview part avec une **compo vide** → 422 « Sélectionnez 1 Sauce/Viande ». Le fix documenté du 2026-05-10 (« PAS d'appel preview à l'entrée du wizard ») a **partiellement régressé** via ce watcher.
- **Impact réel** : **NUL côté client** (le total local 6,90 € s'affiche correctement via `max(serverPreview, local)`). Symptôme = **bruit console** (1 erreur 422 par ouverture wizard).
- **Correctif proposé (surgical, 1 ligne)** : guarder `resolvedItem` sur `oldItem &&` (ne fire que sur un VRAI swap d'item en mode édition, pas la 1ʳᵉ résolution), OU guarder `refreshServerPreviewTotal` sur compo-complète.
- **Blocage** : `KioskWizardComponent.vue` = **FROZEN §7** → nécessite **LOCK + sign-off owner** (§10). **NON appliqué** (gate humaine respectée).

### G2 — [Go-live hygiene] Commandes test accumulées polluent la file « à encaisser » — **PARTIELLEMENT PURGÉ (dev)**
- **FAIT (dev :8766)** : `php artisan foodking:cleanup-web-test-orders --confirm` → **63 commandes test source=5 (borne/web) soft-delete** (0 scellée, **réversible**, NF525-safe). File à-encaisser **33 → 19**.
- **RESTE (dev + PROD)** :
  - Les **19 commandes restantes** en file sont **POS-originées** (source≠5) — non couvertes par ce command (scoping sécurité). Purge séparée à prévoir si test-data POS (à confirmer owner : test vs réel).
  - **PROD VPS** : rejouer `foodking:cleanup-web-test-orders --confirm` sur le VPS avant go-live (via `ssh lecayenne` → `cd /var/www/lecayenne`).
- Soft-delete = **restaurable** (deleted_at, pas de hard-delete) ; scoping `source=5` protège les commandes POS/caisse réelles.

### G3 — [P3, latent] Couplages logique↔nom-de-produit (fragile au renommage admin)
- Exclusivité oignon cru/cuit (`KioskStepGarnituresComponent:182`) + quota viandes tacos (`kioskTacosSize.js:46`) = **matching textuel** sur le nom. **Fonctionnent avec les données ACTUELLES** (vérifié : « Oignon » vs « Oignons cuits » → exclusivité correcte ; Tacos M/L → 1/2 viandes). Risque = un renommage admin (« Oignons rouges ») casserait la règle **silencieusement**. Backlog durcissement (flag DB explicite au lieu du nom).

---

## Verdict
**Les 4 systèmes sont architecturalement sains, la synchro borne↔caisse↔KDS est prouvée
bout-en-bout (prix + compo + n° cohérents, NF525-correct), le rendu visuel est propre.**
2 corrections sûres appliquées + testées (menu test, console idle). 3 findings arbitrables
(1 fix frozen en attente de gate, 1 purge test-data go-live, 1 durcissement latent).
Aucune régression, frozen zones intactes.
