# PLAN_MEGA_FUNCTIONAL_CORRECTNESS_2026-04-20

**Cycle**: P-MEGA (post auto-remediation)  
**Date**: 2026-04-20  
**Mode**: prêt à orchestrer en RUNNER_MODE single-session + auto-remediation  
**Esprit du plan**:

> La phase précédente a livré la convergence d'infrastructure (auth config, observability, CSP, K-2 invariant test, etc.). Cette phase change de registre : on attaque la **correctness fonctionnelle réelle du produit kiosque**. Chaque tâche cible une fonctionnalité métier visible par l'utilisateur final, audit + fix + test garde-fou + acceptance critère, ancrée dans le code réel (pas générique).

**Trigger** : bug rapporté par l'utilisateur — sur un Tacos 2/3/4 viandes, on ne peut sélectionner qu'**une** viande (alors qu'on devrait pouvoir choisir 2 fois la même, 3 fois la même, ou mixer 1+1 / 2+1, etc.). Cause confirmée : `detectViandeCount()` dans `KioskWizardComponent.vue:465-480` retombe à `1` quand l'heuristique regex sur le nom (`\b2 viandes?\b`, `\bL\b`...) ne capte pas et que `_tailleMeta.viandeCount` n'a pas été seedé par le step Taille. **Cette classe de bug — "fallback silencieux à 1 quand la métadonnée business n'est pas seedée" — se reproduit dans 6 à 8 autres endroits du wizard** (sauces, garnitures, suppléments, menu boissons, pain, etc.).

---

## Conventions plan

- **20 tâches longues**, séquencées en 7 vagues.
- Chaque tâche = 2 à 6 heures (+ tests). Pas de mini-tâches.
- Chaque tâche a : *Contexte* / *Audit* / *Fix* / *Tests* / *Acceptance* / *Risque* / *Zone* / *LOC estimées*.
- **Zones critiques** : DB schema, auth, frozen zone, OrderService, branch_id, OrderStatus, dispatch-after-commit, pricing SSOT → human gate avant exec.
- **Hors zones critiques** : auto-execute en single-session.
- Test garde-fou Vitest **ou** PHPUnit obligatoire pour chaque fix (pas de fix sans test).
- Output unique par tâche : `reports/execution/RUN_P_MEGA_<NN>_<slug>_2026-04-20.md`.

---

## Wave 1 — Wizard logic & business rules (le cœur du bug)

### P-MEGA-01 — Audit + fix `detectViandeCount` et fallback meta business (le bug rapporté + ses cousins)

**Contexte** : `detectViandeCount()` retombe silencieusement à 1 si :
1. `_tailleMeta.viandeCount` non seedé (step Taille jamais atteint, ou skipé)
2. Le nom de l'item ne matche pas la regex `\b[1-4]\s*viandes?\b|\bxxl\b|\bxl\b|\bl\b`

→ pour un item nommé `Tacos M`, `Tacos Mega`, `Tacos Famille`, `Burger 3 steaks`, etc., le fallback à 1 désactive le bouton `+` après 1 sélection.

**Audit (avant fix)**:
- Lister tous les items en BD via `Item::where('item_category_id', tacosCategoryId)` et matcher contre la regex actuelle. Compter les hits/miss → quantifier l'impact réel.
- Inventorier dans `KioskWizardComponent.vue` toutes les méthodes `detect<X>Count()` similaires (sauce, garniture, supplements, drinks). Vérifier si elles ont le même pattern fallback.
- Lister les autres composants Step* qui utilisent `selections._<X>Meta?.<Y>Count || 1`.

**Fix proposé**:
1. `detectViandeCount()` : ajouter une **3e source de vérité** = lookup côté serveur via `item.viande_count` (à exposer dans `SimpleItemResource` si absent).
2. Si toujours `null`, retourner `null` (pas `1`) et **forcer l'affichage du step Taille** comme prérequis (UI : bandeau "veuillez choisir une taille").
3. Loguer côté analytics `wizard.fallback_count_used` quand l'heuristique sert (pour observer en prod).

**Tests garde-fou** :
- Vitest : `tests/js/kioskDetectViandeCount.spec.js` — 12 cases (Tacos M sans step Taille → null forcé Taille ; Tacos L avec step Taille → 2 ; Tacos 4 viandes XXL → 4 ; nom imprévu sans step Taille → null + UI guard ; etc.).
- Vitest existant à étendre : `kioskWizardSelections.spec.js` (si présent).

**Acceptance** :
- Pour un Tacos `viande_count=3` côté BD, le step viande affiche `0/3`, `+` reste actif jusqu'à `3/3`, et l'utilisateur peut faire `2 + 1 mix` ou `3 d'une même viande`.
- Pour un item ambigu sans `viande_count`, le wizard refuse de skip le step Taille (pas de fallback silencieux).
- 0 hit `wizard.fallback_count_used` sur un menu correctement configuré.

**Risque** : moyen (touche logic wizard, mais pas de zone critique back). LOC ~150.  
**Zone** : front kiosk uniquement (sauf si on ajoute `viande_count` à la resource → alors back additif non-critique).

---

### P-MEGA-02 — Audit complet "fallback à 1 silencieux" sur tout le wizard (chasse aux cousins)

**Contexte** : généralisation de P-MEGA-01. Le pattern `(_<X>Meta?.<Y>Count) || 1` est anti-pattern car masque une mauvaise configuration BD ou un mauvais flux wizard.

**Audit** :
- `rg "Meta\?\.\w+Count" resources/js/components/frontend/kiosk/` → lister toutes les occurrences.
- Pour chaque, vérifier si le fallback est légitime (vraiment "single by default") ou silencieusement bogué.
- Cibles probables : sauces (combien de sauces autorisées), garnitures (combien d'extras gratuits inclus), suppléments (cap), boissons (combien de boissons dans le menu).

**Fix** : remplacer chaque fallback bogué par un guard explicite + fallback à `null` + UI message + analytics event.

**Tests** : 1 spec Vitest par fallback nettoyé.

**Acceptance** : grep `|| 1` sur les meta business returns 0 hit illegitimes.

**Risque** : moyen. LOC ~100-200 selon nombre de cousins.  
**Zone** : front kiosk.

---

### P-MEGA-03 — Audit + fix : symétrie cardinality groupe variations (min/max enforcement)

**Contexte** : l'admin BD permet de définir un groupe de variations (e.g. `viande`) avec `min=1, max=3`. Le wizard doit :
- Bloquer "Suivant" si `selected < min`
- Bloquer le `+` si `selected >= max`
- Distinguer `min=max` (qty fixée, ex: 2 viandes obligatoires) de `min<max` (range, ex: 1 à 3 viandes)
- Permettre `select_same_variation_multiple_times: true|false` (param BD)

**Audit** :
- Lire `KioskWizardComponent.vue` `canValidateStep()` et chaque step's `emitUpdate`.
- Vérifier comment min/max remontent depuis `ItemAttribute` BD vers le front.
- Tester en BD : créer un attribute avec `min=2, max=4, allow_repeat=true` ; voir si le front respecte.

**Fix** : standardiser une struct `{ min, max, allowRepeat }` propagée de `SimpleItemResource` jusqu'à chaque step ; renforcer les guards ; UI hint clair ("Choisissez de 2 à 4 viandes — une même viande peut être répétée").

**Tests** : Vitest 8 cas + PHPUnit pour la resource.

**Acceptance** : 100% cas BD (1-1, 1-3, 2-2, 2-4, allow_repeat true/false) respectés sans bricolage front.

**Risque** : moyen. LOC ~250.  
**Zone** : front + back (resource additif).

---

### P-MEGA-04 — Audit + fix : navigation back/skip wizard préserve les sélections

**Contexte** : si l'utilisateur va step viande → step sauce → revient en arrière → repart, ses sélections viande doivent persister. Bug fréquent : reset partiel.

**Audit** :
- Tracer `selections` dans `KioskWizardComponent` à chaque mount/unmount des Step*.
- Vérifier que `goBack()` ne réinitialise pas `localSelections` côté Step.
- Tester scénarios : back-forward 3x ; back puis change taille (le viandeCount bouge) ; skip optional step puis revient.

**Fix** : extraire un store local Vuex `kioskWizard` ou Pinia, source de vérité unique ; les Step* ne maintiennent que de l'UI state, pas de business state.

**Tests** : Vitest e2e wizard navigation (mount-back-forward-validate).

**Acceptance** : 0 perte de sélection sur 20 scénarios de navigation.

**Risque** : moyen. LOC ~300 (refactor).  
**Zone** : front kiosk.

---

### P-MEGA-05 — Cart edit : ré-entrer dans le wizard avec sélections pré-remplies

**Contexte** : utilisateur ajoute Tacos 3 viandes au panier, puis clique "modifier". Aujourd'hui ouvre une page neuve sans pré-sélection.

**Audit** :
- Vérifier si `KioskCartComponent` a un bouton "modifier" et ce qu'il fait.
- Tracer le payload cart : contient-il `item_variations` + `item_extras` exploitables pour reconstituer `selections` ?

**Fix** : ajouter `editCartLine(idx)` qui hydrate `selections` depuis le cart line + ouvre `KioskWizard` en mode édition ; à la validation, `replace` la cart line au lieu d'ajouter.

**Tests** : Vitest cart→edit→wizard→re-validate (3 cas).

**Acceptance** : édition d'un Tacos 3 viandes (2 boeuf + 1 poulet) ré-ouvre le wizard avec le compteur exact.

**Risque** : moyen. LOC ~200.  
**Zone** : front kiosk.

---

## Wave 2 — Pricing & SSOT integrity

### P-MEGA-06 — Audit pricing client-side vs server-side cohérence sur 50 panneaux

**Contexte** : l'invariant SSOT pricing (T06) est garanti par `OrderService`. Mais le **runningTotal** affiché côté kiosk (helper `calculateKioskRunningTotal`) doit matcher au centime près, sinon le client voit X et paie Y.

**Audit** :
- Construire 50 paniers synthétiques (variations seules / extras seuls / mix / promo / coupon / loyalty).
- Pour chaque, calculer côté kiosk + envoyer dry-run au backend `/pricing/preview` + comparer.
- Lister les écarts > 0.01 €.

**Fix** : aligner `calculateKioskRunningTotal` sur la formule canonique back ; ajouter une assertion en dev qui flash une toast si écart détecté.

**Tests** : Vitest paramétré 50 cas + 1 PHPUnit qui exécute pricing/preview avec ces 50 cas et fait l'assertion.

**Acceptance** : 50/50 écarts ≤ 1 cent.

**Risque** : haut (pricing critical). **HUMAN GATE pour la formule, pas pour les tests.**  
**Zone** : pricing SSOT — gate.

---

### P-MEGA-07 — Audit + fix arrondis VAT par taux (5.5% / 10% / 20%)

**Contexte** : France a 3 taux TVA (alimentaire à emporter 5.5%, restauration 10%, alcool 20%). Le total TVA = Σ par taux, pas total*taux moyen. Risque d'erreur 1-2 cents par item, qui s'accumule.

**Audit** :
- Vérifier `OrderService::computeTaxes()` et resources qui exposent `total_tax`.
- Tester un panier mixte : burger (10%) + bière (20%) + glace à emporter (5.5%).

**Fix** : si manquant, factoriser un `TaxBreakdownService` qui retourne `{ '5.5': X, '10': Y, '20': Z, total: X+Y+Z }`. Exposer dans la resource. Afficher sur le ticket.

**Tests** : PHPUnit `TaxBreakdownTest` 12 cas + Vitest receipt rendering.

**Acceptance** : ticket ventile correctement par taux ; total TVA = somme par taux.

**Risque** : haut (pricing + NF525). **HUMAN GATE.**  
**Zone** : pricing SSOT + NF525.

---

## Wave 3 — Allergens & dietary

### P-MEGA-08 — Audit propagation allergènes à travers variations + extras

**Contexte** : un item "Tacos boeuf" peut être OK gluten ; mais l'extra "fromage" contient lait. Le badge allergène final doit refléter la **somme** allergènes(item) ∪ allergènes(variations sélectionnées) ∪ allergènes(extras sélectionnés). Bug fréquent : badge figé à `item.allergens` au mount.

**Audit** :
- Lire `KsAllergenBadge.vue` : prend-il `item` ou `cartLine` ?
- Vérifier `kioskFilters.js` helper `mergeAllergens(item, selections)`.
- Tester scénario : item sans allergènes + extra fromage → cart line doit montrer "lait".

**Fix** : si manquant, créer `mergeAllergens(item, selections)` ; brancher sur le récap wizard + cart + receipt ; mirror back `OrderItem::computeAllergensSnapshot()`.

**Tests** : Vitest 8 cas + PHPUnit snapshot.

**Acceptance** : panier affiche les bons allergènes pour 8 scénarios mix.

**Risque** : moyen (légal — info allergène = obligation). LOC ~150.  
**Zone** : front + back additif.

---

### P-MEGA-09 — Filtre allergène persistant + bandeau visible + interaction item indisponible

**Contexte** : l'utilisateur active "sans gluten" sur l'écran d'accueil → le menu cache ou grise les items contenant gluten. À chaque navigation, le filtre doit persister. Si l'utilisateur ouvre un item compatible mais avec une variation gluten, le wizard doit masquer cette variation.

**Audit** :
- Vérifier `kioskFilters.js` état + persistance (localStorage ? Vuex ?).
- Ouvrir un Tacos sans gluten (galette) puis aller au step pain : la variation "pain blanc" doit être grisée/cachée.

**Fix** : helper `isVariationAllowedByFilters(variation, activeFilters)` réutilisable ; bandeau permanent en haut ; bouton "désactiver le filtre" visible.

**Tests** : Vitest 6 cas + Playwright spec dédiée.

**Acceptance** : 0 plat gluten visible dans le menu quand filtre actif ; 0 variation gluten cliquable dans le wizard.

**Risque** : faible. LOC ~120.  
**Zone** : front kiosk.

---

## Wave 4 — i18n / RTL

### P-MEGA-10 — Audit RTL Arabe : layout cart, wizard, payment, receipt

**Contexte** : `lang/ar/*` est présent et `kioskAllergens` a des clés `ar`. Mais l'UX RTL nécessite mirror du layout : flèche back à droite, prix à gauche, bouton primaire à gauche, etc.

**Audit** :
- Build kiosk en `?lang=ar`. Capturer screenshots (cart, wizard step, payment, receipt).
- Vérifier `dir="rtl"` propagé sur `<html>` ; revoir CSS `direction: rtl` sur composants kiosk.
- Tester clavier virtuel (latin pour téléphone, arabe pour nom ?).

**Fix** : audit + corrections CSS ciblées (logical properties `padding-inline-start`, `margin-inline-end`, etc.) ; fontes (Tajawal recommandée) ; mirror SVG flèches.

**Tests** : Playwright spec RTL avec assertion `dir=rtl` + visual regression key elements.

**Acceptance** : 5 screens captured RTL avec layout cohérent (vu humain en revue).

**Risque** : faible. LOC ~200 CSS.  
**Zone** : front kiosk.

---

### P-MEGA-11 — Audit complétude des traductions par surface (auto-detect missing keys)

**Contexte** : 5 langues (`ar`, `bn`, `de`, `en`, `fr`). Risque de drift : on ajoute une clé en `fr.json`, on oublie les autres → `$t('foo.bar')` renvoie la clé brute.

**Audit** :
- Script Node : parse `fr.json` (référence) → liste toutes les clés ; pour chaque autre langue, lister les manquantes ou identiques à `fr` (potentiellement non traduit).
- Sortie : `reports/i18n/missing_keys_per_locale_2026-04-20.csv`.

**Fix** : script CI qui fail si une clé existe en `fr` et manque en `en|de|ar|bn` (warning, pas error). Compléter au moins `en` (production internationale).

**Tests** : CI check + 1 PHPUnit qui boucle sur tous les locales et vérifie absence de clé brute exposée à l'UI dans 10 routes critiques.

**Acceptance** : 0 clé manquante en `en` ; rapport drift `de|ar|bn` documenté.

**Risque** : faible. LOC ~100 (script + tests).  
**Zone** : front kiosk + tooling.

---

## Wave 5 — Order types, payment, receipt

### P-MEGA-12 — Eat-in vs takeaway : flow + impact TVA + impact ticket

**Contexte** : sur place vs à emporter change la TVA (10% vs 5.5% sur certains items), le statut, le routing KDS, le ticket.

**Audit** :
- Tester chaque combinaison : (sur-place, drink) → 20% ; (emporter, glace) → 5.5% ; (sur-place, sandwich) → 10%.
- Vérifier que le toggle order_type dans le wizard recalcule la TVA en live.

**Fix** : si manquant, brancher `pricing/preview` à recalculer sur changement order_type ; afficher TVA breakdown.

**Tests** : PHPUnit `EatInVsTakeawayTaxTest` (12 cas) + Vitest UI toggle.

**Acceptance** : 12/12 cas TVA correctes ; toggle live sans rechargement.

**Risque** : haut (TVA + NF525). **HUMAN GATE.**  
**Zone** : pricing SSOT + NF525.

---

### P-MEGA-13 — TPE handshake reliability + multi-tender split + idempotence retry

**Contexte** : ticket-restaurant + carte (split), retry après timeout TPE, et pas de double-paiement.

**Audit** :
- Lire `KioskPaymentComponent.vue` + `PaymentService` côté back.
- Vérifier `X-Idempotency-Key` envoyé sur chaque tentative ; vérifier le côté back ne crée pas un 2e order si la même key arrive.
- Tester scénario : TPE timeout → retry → double order ?
- Tester split TR 5€ + carte 8€ → server doit recevoir 2 lignes payment, total = 13€.

**Fix** : si manquant, idempotence côté `/payment` ; UI clear sur "ne touchez pas pendant retry"; serveur valide somme = total.

**Tests** : PHPUnit `PaymentRetryIdempotencyTest` + `MultiTenderTotalsTest`.

**Acceptance** : 100 simulations retry → 100 orders uniques ; split TR+CB → 1 order avec 2 payments cohérents.

**Risque** : haut (payment SSOT + NF525). **HUMAN GATE.**  
**Zone** : payment + NF525 + idempotence.

---

### P-MEGA-14 — Receipt rendering : variations + extras + TVA breakdown + duplicata + QR

**Contexte** : ticket NF525 doit montrer l'item, ses variations sélectionnées, ses extras avec qty, la TVA ventilée par taux, le total, l'horodatage signé HMAC, le marqueur DUPLICATA si réimpression.

**Audit** :
- Imprimer un ticket complexe (Tacos 3 viandes mix + 2 sauces + boisson + dessert + coupon) → relire ligne par ligne.
- Vérifier que la signature HMAC est dans le QR du ticket.
- Tester réimpression → marqueur DUPLICATA ?

**Fix** : ajustements composant `ReceiptComponent.vue` (admin/POS) + mirror kiosk si besoin ; ajouter marqueur DUPLICATA si manquant.

**Tests** : Vitest snapshot ticket + Playwright pas de regression visuelle.

**Acceptance** : 5 scénarios complexes ticketés correctement.

**Risque** : haut (NF525). **HUMAN GATE.**  
**Zone** : NF525.

---

## Wave 6 — Accessibility & performance

### P-MEGA-15 — A11y kiosk : touch target ≥ 44px, contraste WCAG AA, focus order, screen reader, motion

**Contexte** : un kiosk est utilisé par tout public. WCAG AA est minimum.

**Audit** :
- Lighthouse a11y score sur kiosk → cible 95+.
- Vérifier touch target sur boutons critiques (validation, +/-).
- Contraste : Tacos red `#E8001C` sur blanc OK, mais sur fond `rgba(232,0,28,0.04)` ?
- Focus order : tabbable ? clavier seulement complete une commande ?
- `prefers-reduced-motion` : animations désactivées ?

**Fix** : corrections ciblées (CSS contrast adjust, `min-height: 48px`, `tabindex`, `aria-*` labels, `@media (prefers-reduced-motion: reduce)`).

**Tests** : `axe-core` integration test + manual review checklist.

**Acceptance** : Lighthouse a11y ≥ 95 ; 0 issue critique axe-core.

**Risque** : faible. LOC ~150 CSS + ARIA.  
**Zone** : front kiosk.

---

### P-MEGA-16 — Perf cold start kiosk < 1.5s + bundle audit + image lazy load

**Contexte** : un kiosk reboot fréquemment ; le menu doit être interactif < 1.5s sur Chromebox / Pi.

**Audit** :
- Lighthouse Performance + WebPageTest sur le device cible.
- Vérifier bundle splitting : `KioskApp` ≠ `AdminApp` ≠ `PosApp` ?
- Toutes les images items en lazy load ?
- Service worker pré-cache du menu ?

**Fix** : code splitting si manquant ; `loading="lazy"` sur images ; `preload` polices critiques ; gzip/brotli assets.

**Tests** : Lighthouse CI seuil + bundle-size budget.

**Acceptance** : LCP ≤ 1.5s ; bundle kiosk gzip ≤ 250 KB.

**Risque** : moyen. LOC ~80 + config.  
**Zone** : front + tooling build.

---

## Wave 7 — Resilience, hardware, branch ops

### P-MEGA-17 — Offline queue K-3 v2 (T14c reporté) : IDB + jitter + ItemAvailabilityChanged listener + UI conflict resolution

**Contexte** : T14b a livré la V7 (analytics events) ; T14c contient le vrai durcissement K-3 (IndexedDB, jitter sur le replay, listener `ItemAvailabilityChanged` pour invalider snapshot, modal conflit "cet item n'est plus dispo, voulez-vous remplacer ?").

**Audit** :
- Lire `kioskOfflineQueue.js` (testttt) et `p93` reference.
- Mapper le delta : storage layer, backoff strategy, conflict UX.

**Fix** : implémenter selon p93 reference + adapter à testttt simpler model.

**Tests** : Vitest IDB mock + scénario simulé offline-replay.

**Acceptance** : 3 scénarios e2e offline → online → replay → conflict modal.

**Risque** : moyen. LOC ~400. Cycle dédié.  
**Zone** : front kiosk (offline).

---

### P-MEGA-18 — Hardware fallback : printer offline, TPE timeout, camera scanner KO, buzzer absent

**Contexte** : le kiosk ne doit JAMAIS bloquer une commande à cause d'un périphérique HS. Fallback dégradé requis (e.g. ticket QR si printer KO).

**Audit** :
- Lister les flux dépendant d'un périphérique : printer, TPE, camera (QR coupon scan), buzzer.
- Tester chaque scénario en simulant l'absence.

**Fix** : pour chaque, ajouter une voie dégradée (e.g. "QR à scanner avec votre téléphone" si printer KO).

**Tests** : Vitest mock périphériques + Playwright E2E simulé.

**Acceptance** : 4 périphériques × 4 scénarios = 16 cas dégradés OK.

**Risque** : faible. LOC ~250.  
**Zone** : front kiosk + intégration matériel.

---

### P-MEGA-19 — Branch theming : logo, couleurs, idle video par branche end-to-end

**Contexte** : les colonnes `branches.theme_primary/accent/logo_path/idle_video_path` ont été identifiées en C9 comme p93-uniques (zéro consommateur testttt). Cette tâche les **active end-to-end** côté testttt si la décision business confirme le besoin.

**Audit** :
- Confirmer business need (multi-branch white label).
- Décider stratégie : porter migration p93 + créer `KioskContextResource` consommateur + brancher kiosk sur tokens dynamiques.

**Fix** : si OK business → migration backfill p93 + `KioskContextResource` + composables `useBranchTheme()` côté Vue + fallback `tokens.css` si valeurs vides.

**Tests** : PHPUnit `KioskContextResourceTest` + Vitest theming consume.

**Acceptance** : 2 branches avec logos différents rendent 2 kiosks visuellement distincts.

**Risque** : moyen (touche schema BD + resource publique). **HUMAN GATE confirmation business.**  
**Zone** : DB schema + front.

---

## Wave 8 — Security, NF525, observability avancée

### P-MEGA-20 — K-6 enforcement complet : `branch_mismatch` (`KioskEventController`) + lockdown anti-tamper + tests sentinelles

**Contexte** : C7 backlog (~93 LOC port `KioskEventController` + 2 tests p93-uniques `KioskMultiBranchPentest` + `KioskEventBranchSpoofing`).

**Audit** :
- Lire `KioskEventController` testttt vs p93.
- Mapper diff exacte (ce qui manque côté enforcement branch_id).

**Fix** : porter le bloc enforcement + alias middleware si requis + 2 tests garde-fou.

**Tests** : 2 tests p93 portés + 1 test maison "spoofing scenario" sur testttt.

**Acceptance** : un kiosk authentifié sur branch A qui POST avec un payload mentionnant branch B est rejeté 403 + log `security.branch_mismatch_claimed`.

**Risque** : moyen (zone branch_id critique). **HUMAN GATE.**  
**Zone** : branch_id + auth.

---

### P-MEGA-21 — C11 backport K-6.3 + K-6.4 dans `RouteServiceProvider` + port `KioskThrottleKeysTest`

**Contexte** : déjà identifié et scopé en C11 ; ~10 LOC + 5 tests garde-fou.

**Audit** : déjà fait (cf. `RUN_C9_C10_AUDIT_CONVERGENCE_2026-04-20.md`).

**Fix** : merge testttt configurabilité + p93 K-6.3 (`kiosk:{user_id}|{ip}` keying) + K-6.4 (`anon` fallback).

**Tests** : `KioskThrottleKeysTest` (5 tests) ported atomically.

**Acceptance** : test rouge → vert ; full Auth/RateLimit suite vert.

**Risque** : bas (zone auth, mais bénéfice haut). **HUMAN GATE.**  
**Zone** : auth + rate limiting.

---

### P-MEGA-22 — NF525 readiness end-to-end : Z report verifyChain câblé, schedule fiscal:archive, marqueur DUPLICATA, export JET/PIAF

**Contexte** : T12 PARTIAL (WARN) — 4 piliers MVP OK mais gaps : `verifyChain`/`verifySignature` non câblés à `Z::open()`, pas de schedule `fiscal:archive`, pas d'export JET/PIAF, pas de marqueur DUPLICATA.

**Audit** :
- Lire `ZReportService::open()` ; vérifier appel `verifyChain` avant clôture.
- Lire `Console/Kernel.php` : ajouter `$schedule->command('fiscal:archive')->dailyAt('02:00')`.
- Lire `ReceiptComponent.vue` : ajouter marqueur DUPLICATA si réimpression.
- Audit format JET (XML standard contrôle fiscal FR) + PIAF.

**Fix** : 4 patches ciblés, chacun avec test PHPUnit dédié.

**Tests** : `ZOpenChainVerifiedTest`, `FiscalArchiveScheduledTest`, `DuplicataMarkerTest`, `JetExportFormatTest`.

**Acceptance** : 4/4 piliers complétés ; T12 verdict `PASS` (vs WARN).

**Risque** : haut (NF525 régul). **HUMAN GATE.**  
**Zone** : NF525.

---

## Wave 9 (bonus) — Cohérence menu admin ↔ kiosk

### P-MEGA-23 — Audit drift admin menu CRUD ↔ exposition kiosk (la racine du bug viandes)

**Contexte** : pourquoi `viande_count` peut-il être absent ou mal seedé ? Souvent parce que l'admin BD n'expose pas ce champ → le wizard kiosk doit deviner via heuristique nom. **C'est la cause profonde de P-MEGA-01.**

**Audit** :
- Inventaire des champs `Item` requis par le kiosk pour piloter le wizard sans heuristique : `viande_count`, `min_sauces`, `max_sauces`, `min_garnitures`, `max_garnitures`, `allow_repeat_variations`, `viande_count_per_size`.
- Cross-check : sont-ils tous éditables dans l'admin Vue ?

**Fix** : pour chaque champ manquant, ajouter le formulaire admin + migration colonne + seed des items existants ; déprécier l'heuristique nom.

**Tests** : PHPUnit ItemAdminCrudTest + Vitest admin form.

**Acceptance** : 100% items menu ont les meta business explicites ; heuristique nom devient un assert dev seulement.

**Risque** : moyen-haut (touche admin + DB schema additif). **HUMAN GATE pour migrations.**  
**Zone** : admin + DB schema.

---

## Récap & métriques cible

| Vague | Tâches | Type | LOC ~ | Gates humains |
|-------|--------|------|------:|:-------------:|
| 1 — Wizard logic | 5 | front + back additif | ~1000 | 0 |
| 2 — Pricing SSOT | 2 | back critical | ~300 | **2** |
| 3 — Allergens / dietary | 2 | front + back | ~270 | 0 |
| 4 — i18n RTL | 2 | front + tooling | ~300 | 0 |
| 5 — Order/payment/receipt | 3 | back critical | ~400 | **3** |
| 6 — A11y / Perf | 2 | front + tooling | ~230 | 0 |
| 7 — Resilience / branch ops | 3 | front + back | ~850 | **1** (P-MEGA-19) |
| 8 — Security / NF525 / observability | 3 | back critical | ~600 | **3** |
| 9 — Admin drift | 1 | admin + back | ~400 | **1** |
| **TOTAL** | **23** | mixte | **~4350** | **10** |

### Discipline d'exécution

- **Sans gate** (wave 1, 3, 4, 6, partiel 7) : auto-execute en single-session avec auto-remediation.
- **Avec gate** (wave 2, 5, 8, 9) : présenter audit + fix proposé, attendre `oui P-MEGA-XX` avant exec.
- Toutes les exec créent des commits atomiques avec ID `P-MEGA-NN`.
- 1 report `RUN_P_MEGA_NN_<slug>_2026-04-20.md` par tâche.
- Verdict global : viser **+30 tests automatisés** (Vitest + PHPUnit confondus), **0 régression**, **23 features auditées et fiabilisées**.

### Ordre de lancement recommandé

1. **P-MEGA-01** (le bug rapporté — quick win visible)
2. **P-MEGA-23** (la cause profonde — cycle admin)
3. **P-MEGA-02** + **03** + **04** + **05** (suite wizard logic)
4. **P-MEGA-08** + **09** (allergens — légal)
5. **P-MEGA-10** + **11** (i18n)
6. **P-MEGA-15** + **16** (a11y + perf)
7. **P-MEGA-17** (offline T14c)
8. **P-MEGA-18** (hardware)
9. **P-MEGA-21** (C11 — quick gate)
10. **P-MEGA-19** (branch theming si business OK)
11. **P-MEGA-20** (K-6 enforcement gate)
12. **P-MEGA-06** + **07** (pricing SSOT gates)
13. **P-MEGA-12** + **13** + **14** (order/payment/receipt gates)
14. **P-MEGA-22** (NF525 readiness gate)

### Risques anticipés

- **Collision P11/P12/P13** (composer batch parallèle actif) sur `RouteServiceProvider`, `Availability*`, `posCart`, `i18n` → coordonner via `git status` check avant chaque tâche.
- **Charge BD pour audit** (P-MEGA-01 query items, P-MEGA-06 50 paniers) → exécuter en dev/CI seulement.
- **Refactor wizard (P-MEGA-04)** = risque régression visuelle → snapshot Playwright recommandé avant.

---

## Manifeste

> Cette phase change le contrat : ce n'est plus "porter du code de p93", c'est **garantir que le kiosk fait ce que l'utilisateur attend**. Chaque tâche du plan est ancrée dans un comportement utilisateur observable (pas un audit générique). Les gates sont là parce qu'ils touchent des invariants (NF525, pricing, branch_id, auth) — pas pour ralentir, pour protéger. Les 13 tâches sans gate (wave 1+3+4+6+17+18+21) peuvent être exécutées en autonomie en RUNNER_MODE single-session, livrer ~2500 LOC + ~25 tests, et corriger 13 surfaces fonctionnelles concrètes en quelques cycles.

**Prochaine action proposée** : `oui P-MEGA-01` → je lance le fix viandes + le test garde-fou en single-session immédiatement.
