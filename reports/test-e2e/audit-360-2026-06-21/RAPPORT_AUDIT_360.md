# RAPPORT — AUDIT 360° VISUEL (chaque pixel) — Le Cayenne V1 — 2026-06-21

> Owner `/goal` : audit 360° couvrant chaque pixel, test réel visuel (Playwright live :8766),
> agents adversaires critique-first + dispute, raisonnement max-complexe, rapport en tableau,
> correction en boucle test-e2e jusqu'à validé. Rig :8766 sert le code du worktree (mes bundles).

## §1 — STRUCTURE COMPOSÉE (inventaire des surfaces auditées)
Capture live Playwright @ 1366×768, multimodal (Read sur chaque PNG). Login admin@lecayenne.fr.

| # | Surface | Route | Verdict capture (moi) | Notes / points faibles candidats |
|---|---|---|---|---|
| 1 | Login admin | /login | ✅ propre | branding OK, FR OK ; 31 console-401 = **artefact session périmée** (créds autofill confirment état antérieur ; logout-401 corrobore) |
| 2 | Dashboard | /admin/dashboard | ✅ propre | money FR (37 719,92 €), KPI OK ; "Suivi en direct=0 aujourd'hui" à vérifier (rig-data probable) |
| 3 | POS Caisse (FROZEN) | /admin/pos | ✅ propre | modal ouvre-caisse, money FR ; tabs catégories tronqués ("Bols Gourm…") |
| 4 | KDS | /admin/kitchen-display-system | ✅ propre | bannière poll-admin 60s correcte, empty-state SVG, "il y a 1j/4j" OK ; serials ZWIN=rig-data |
| 5 | OSS mur public | /admin/order-status-screen | ✅ propre | 2 colonnes, empty-states ; **en-têtes couleur texte incohérentes** (blanc/magenta vs sombre/vert) → contraste à vérifier |
| 6 | Catalogue articles | /admin/items | ✅ propre | money FR, 46 produits ; labels stat tronqués ("CATÉGO…") ; **icônes action a11y à vérifier** ; wval3cg=rig |
| 7 | Kiosk borne (auto-connect) | /kiosk/idle→login | ⚠️ état erreur | **dark-theme vs mandat light** + **copy auth brut "Identifiants invalides" sur borne publique** (créds rig périmées) |
| 8 | Stock | /admin/stock/rupture | ✅ propre | heal STOCK-GRID tient (noms pleins), EN STOCK, sync note |
| 9 | Fidélité (settings) | /admin/settings/loyalty-setup | ✅ propre | **heal i18n VÉRIFIÉ LIVE** : "0,10 € de réduction" (FR) |
| 10 | TPE (settings) | /admin/settings/payment-terminals | ✅ propre | **heal a11y VÉRIFIÉ LIVE** : boutons "Modifier"/"Supprimer" nommés ; money FR |
| 11 | Rapport ventes | /admin/sales-report | ✅ propre | money+dates FR, badges Payé/Non Payé ; **count 2784 vs dashboard 2778** à investiguer |
| 12 | Commandes caisse | /admin/pos-orders | ✅ propre | table standard, FR |
| 13 | Encaissement | /admin/encaissement | ✅ propre | grille cartes (87 en attente), FR, boutons Encaisser |

Surfaces différées (frozen + très auditées / créds rig) : Kiosk MENU wizard (frozen §7), POS wizard interne (frozen §7).

## §2 — FINDINGS ADVERSAIRES (4 critics parallèles — Round 1)
4 agents critique-first (POS · KDS/OSS · Dashboard/Reports/Catalogue · Settings/Auth/Kiosk), verify-before-report. **Net : 0 P0, 1 P1, 5 P2 (3 fixables + 2 FROZEN), 3 P3.** Cœur SOLIDE (nombreux faux-leads réfutés avec preuve).

| ID | Sév | Surface | file:line | Finding | Statut |
|---|---|---|---|---|---|
| F1 | **P1** | Kiosk borne | KioskLoginComponent.vue:125-129 | Erreur auth brute "Identifiants invalides ou compte bloqué" affichée au **client public** (backend message gagne sur le fallback) | ✅ **HEALED+vérifié live** (copy publique "reconnexion automatique") |
| F2 | P2 | KDS | KdsV2Grid.vue:574 (+548) | empty-sub `#9CA3AF` = **2,43:1 FAIL AA** (jumeau d'un fix OSS raté) | ✅ **HEALED** `#6B7280` 4,83:1 (vérifié live) |
| F3 | P2 | Kiosk borne | KioskLoginComponent.vue:148+ | **Theme DARK** viole le mandat kiosk light-mode (seule île dark restante) | ✅ **HEALED+vérifié live** (re-skin light complet) |
| F4 | P2 | Dashboard↔Report | OrderService.php:3062 | "Total Commandes" = populations ≠ (report inclut les miroirs de remboursement, dashboard non) → écart 6 inexpliqué (revenu OK) | ✅ **HEALED** count `whereNull('parent_order_id')` (aligné dashboard) |
| F5 | P3 | Fidélité | LoyaltySetupComponent.vue:70 | "10€" collé en-US (résidu de mon propre heal) | ✅ **HEALED+vérifié live** "10 €" |
| F6 | P2 | POS wizard | public/js/pos-wizard.js:692 | money en-US `€3.00` addon (raté par LOCK G-FROZEN-WIZARD-MONEY) | ⛔ **FROZEN §7 → owner-gate** (étendre le LOCK) |
| F7 | P2 | POS wizard | public/js/pos-wizard.js:2281 | recap "Formule" `+€3.00` en-US | ⛔ **FROZEN §7 → owner-gate** (même LOCK) |
| F8 | P3 | POS cash modal | PosCashDrawerSessionDialog.vue:60,175 | aria-label FR hardcodé "Incréments rapides" (correct FR, V2-seed) | ⏸️ déféré (non-défaut, pas d'impact V1) |
| F9 | P3 | KDS legacy | KitchenDisplaySystemComponent.vue (8 strings) | strings FR hardcodés sur le chemin legacy `?v2=0` (non rendu en V2) | ⏸️ déféré (chemin legacy) |
| — | — | OSS en-têtes | — | contraste blanc/magenta 7,11:1 + sombre/vert 6,06:1 | ✅ **RÉFUTÉ** (les deux passent AA — ma suspicion fausse) |
| — | — | Dashboard "0 aujourd'hui" | — | 0 commande aujourd'hui (Paris TZ) | ✅ **RÉFUTÉ** (correct — 0 réelle) |
| — | — | items icônes action | — | eye/edit/dup/trash | ✅ **RÉFUTÉ** (toutes ont aria-label) |
| — | — | login forgot-pwd contraste | — | text-orange-700 5,18:1 | ✅ **RÉFUTÉ** (déjà healed AA) |

## §3 — CORRECTIONS (boucle test-e2e — Round 1)
**5 heals appliqués (1 P1, 3 P2, 1 P3), tous NON-frozen** + rebuild (`npm run development` exit 0, bundles admin-kds/admin-shell/kiosk-shell) :
- F1+F3 kiosk : `KioskLoginComponent.vue` — fallback public + re-skin light complet (canvas/card/texte/input/erreur/footer/hover off-brand→brand). Vérifié live (light + "reconnexion automatique").
- F2 KDS : `KdsV2Grid.vue` `#9CA3AF`→`#6B7280`. Vérifié live.
- F4 count : `OrderService.php` total_orders `whereNull('parent_order_id')`. Sales-report PHPUnit 7/7.
- F5 loyalty : "10 €". Vérifié live.
- **Gates : Vitest 2007/0, sales-report PHPUnit 7/7, frozen §7 diff = 0** (tous heals non-frozen).

### §3bis — Round 2 + Round 3 (boucle convergence)
- **Round 2a (refute-heals)** : 5/5 heals **VERIFIED-CLEAN**, 0 régression, 0 nouveau P0/P1. Le count-fix F4 est même **ratifié par un test existant `TotalOrdersCountSemanticsTest`** (lancé vert + 4 sentinels).
- **Round 2b (sweep systémique jumeau)** : classes **money en-US / raw-i18n / icon-a11y = CLEAN repo-wide** (réfutées avec preuve : 208 clés i18n vérifiées, tous les `toFixed` sont des fallbacks catch derrière `Intl fr-FR`). Classe **contraste** = 2 jumeaux trouvés → **A1 (P2)** CashOverview order_id + **A2 (P3)** PosOrderReceipt taxe, healés `#9CA3AF→#6B7280`.
- **Round 3 (census repo-wide contraste)** : 15 `text-gray-400` recensés → 1 dernier jumeau **A3 (P3)** PaymentTerminals empty-state, healé. Reste = hints (convention), icônes décoratives, statut-inactif (exempts). **Classe contraste data-text/empty-state ÉPUISÉE.**

**Cash-overview "4€ vs 8€"** = **RÉFUTÉ** (verify-before-report) : GRAND TOTAL filtre par date de paiement, la réconciliation suit la session tiroir — scopes différents, math interne cohérente (50+8=58). Pas un bug.

## §4 — VERDICT FINAL ✅ CONVERGÉ
**Cœur visuel SOLIDE.** Audit 360° sur 14 surfaces live (chaque pixel, multimodal) + 6 agents adversaires (4 critics R1 + 2 R2) critique-first/dispute. **0 P0.** **8 heals appliqués+vérifiés** (1 P1 kiosk-error-public, 5 P2 [KDS+kiosk-theme+dashboard-count+cashoverview], 2 P3 [loyalty+receipt+payment-empty]), **tous NON-frozen**. Classes systémiques (contraste/money/i18n/a11y) **épuisées repo-wide**. **Gates : Vitest 2007/0, sales-report PHPUnit 7/7, frozen §7 diff = 0.** Nombreux faux-leads réfutés (OSS-contraste, 0-aujourd'hui, icon-a11y, en-US-money, cash-4vs8) = preuve d'un cœur sain.
- **OWNER-GATE (frozen)** : F6/F7 = `pos-wizard.js` money en-US `€3.00`/`+€3.00` (2 sites ratés par le LOCK `G-FROZEN-WIZARD-MONEY`) → étendre le LOCK.
- **Déférés (P3, non-défauts)** : convention form-hints `text-gray-400` (repo-wide, owner-decision strict-AA), KDS legacy strings (chemin `?v2=0`), pos aria-hardcodé (FR correct).
- **Ship** : les bundles rebuild (`kiosk-shell`/`admin-shell`/`admin-kds`/`admin-reports`) doivent être déployés pour que les heals atteignent les surfaces.

---

## §5 — WAVE 2 (audit PLUS PROFOND : parcours kiosk complet + formulaires + breadth admin)
Owner a re-lancé le goal → audit plus profond/large. Surfaces neuves capturées live : **kiosk idle→menu→catégorie→wizard composition** (enfin atteint, créds borne réalignées), studio catalogue, formulaire item-create, offres, employés, cash-overview. **+ 7 heals (tous NON-frozen), tous live-vérifiés**, + sweep systémique (3 agents adversaires).

| ID | Sév | Surface | file:line | Finding | Statut |
|---|---|---|---|---|---|
| W2-1 | **P2** | Catalogue studio | fr/en.json (`studio.product_composer_button`) | clé i18n MANQUANTE → **92 warnings intlify** par rendu | ✅ HEALED (clé ajoutée 5 locales) — **studio 92→0 warnings vérifié live** |
| W2-2 | P3 | Catalogue studio | `studio.category_studio_button` | clé manquante (sibling) | ✅ HEALED (5 locales) |
| W2-3 | **P2** | TOUS formulaires (×13) | fr.json `label.no` = **"N°"** | mistraduction "No"→"N°" (symbole numéro) → chaque radio Oui/**N°** | ✅ HEALED "Non" (1 clé → **13 forms**) — vérifié live item-create |
| W2-4 | **P2** | 17 composants (×2 tickets NF525) | `X.country_code + '' + phone` | **classe null-glue** : country_code nul → "**null**"+phone affiché (employés, clients, livreurs, admins, chefs, serveurs, tickets) | ✅ HEALED `(x \|\| '')` **17 composants, 0 restant** — vérifié live employés |
| W2-5 | P2 | Kiosk wizard (FROZEN comp.) | `kiosk.wizard.generic.step_fallback` | clé manquante → **raw-key** dans le recap client si step_label absent (4 sites) | ✅ HEALED (clé locale ajoutée, composant frozen NON touché) |
| W2-6 | **P2** | Kiosk borne | `KioskMenuService.php:66` | pas de `whereHas('items')` (POS l'a) → borne **défaut sur catégorie VIDE "0 produit"** (incl. vraie "Tacos Signature", pas que rig) | ✅ HEALED `whereHas('items')` — **vérifié live cat=32(vide)→cat=10(Boissons 8 produits)** |
| W2-7 | — | i18n parité | de/bn/ar.json | clés studio ajoutées pour passer `studioFrontendI18nParity` | ✅ (test caught my fr-only addition → corrigé) |
| — | déféré | admin product-wizard | `admin.product_wizard.*` (13 clés) | composant **non-routé/orphelin** (0 impact live) | ⏸️ déféré (à ajouter au câblage) |
| — | déféré | GuestVerify/SignupVerify | `code + '' + phone` | OTP local (non-nullable) — anti-pattern cosmétique | ⏸️ déféré |

**Sweeps systémiques (3 agents adversaires)** : (a) i18n-completeness → 6 clés manquantes + 9 dynamic-siblings recensées (live fixées, orphelines déférées) ; (b) refute-heals → mes 4 heals CORRECTS mais **null-glue INCOMPLET** (3/17 → tous corrigés) ; (c) N°-class → **épuisée** (pas d'autre mistraduction yes/no). **Verify-before-report** a réfuté : OSS-contraste, 0-aujourd'hui, cash-4vs8, icon-a11y.

**Gates Wave 2 : Vitest 2007/0** (1 régression parité attrapée+corrigée — preuve que le test-fortress marche), frozen §7=0, PHP lint OK, build compilé. **Leçon : la lentille jumeau-systémique a re-frappé — j'avais corrigé 3/17 du null-glue ; l'agent adversaire a forcé la classe complète.**

---

## §6 — WAVE 3 (owner re-goal : « abuse les vérifications et l'analyse AVANT de valider »)
Encore plus profond : surfaces settings/reports/item-show + 2 agents analyse statique + **verify-before-accept renforcé** (a attrapé 2 ERREURS des agents). Méthode console-warning = détecteur rapide de clés manquantes.

| ID | Sév | Surface | Défaut | Fix + preuve |
|---|---|---|---|---|
| W3-1 | **P2** | Passerelle paiement | **clés i18n manquantes rendues RAW** : "LABEL.PAYPAL_APP_ID"… (13 warnings) | 11 clés fr+en → **0 warnings + labels FR vérifié live** |
| W3-2 | **P2** | Passerelle SMS | 28 warnings, raw keys (Twilio/Nexmo/MSG91/Telesign…) | 28 clés fr+en (classe `$t("label."+option)` épuisée) |
| W3-3 | **P1** | Rapport articles + Abonnés | **presets datepicker en ANGLAIS** ("Today/This month/Last month/This year") en UI FR | traduits FR (jumeau : 10/12 déjà FR, ces 2 ratés) |
| W3-4 | P2 | Outbox observability | `toLocaleString()` sans locale → date **en-US "6/21/2026 2:30 PM"** | `'fr-FR'`+Paris TZ (2 sites) |
| W3-5 | P2 | **Ticket client** (fiscal) | taux taxe **"5.50 %"** (point en-US) | `.replace('.',',')` display-side → "5,50 %" (résous PAS via flatAmountFormat car exports le veulent en `.`) |

**Verify-before-accept a RÉFUTÉ 2 erreurs des agents adversaires** (preuve d'« abuser la vérification ») : (a) l'agent a prétendu `label.no="N°"` = « scratch-copy only / faux-positif » → FAUX, c'était mon fix W2 `6e25fad7a` déjà appliqué (il lisait l'état post-fix) ; (b) l'agent a proposé de corriger `flatAmountFormat` → RISQUÉ (alimente les exports CSV/Excel qui veulent `.` + le formulaire d'édition taxe) → corrigé display-side à la place.

**Classes EXHAUSTÉES (signal convergence, vérifié)** : en-US money non-frozen, null-glue (W1/W2 complet 0 traînard), `+''+` coercion, NaN-render, v-html XSS (tout via DOMPurify), date en-US (sauf les 2 Outbox corrigés), mistraduction i18n. **Déférés (vérifiés, raisons)** : 5 tickets opérateur taux-taxe (même pattern, enjeu moindre que le client), 2 écrans verify phone-glue (OTP local), cluster a11y ~18 boutons frontend web (priorité V2 mono-opérateur), **OWNER-GATE frozen** pos-wizard money en-US (3-4 sites §7) + PosV5TrancheRow, backend `DashboardService:555` zero-guard (potentiel 500).

**Gates Wave 3 : Vitest 2007/0, frozen §7=0, build compilé.** **Leçon : l'audit le plus profond (console-warnings + driver les formulaires gateway) trouve des RAW-KEYS que 3 sweeps i18n statiques avaient ratés [pattern dynamique `$t("label."+x)`] ; et "abuse verification" = verify-before-accept attrape les erreurs des agents eux-mêmes (2 ici).**

---

## §7 — WAVE 4 (« Continue » : exhauster les classes déférées + 1 bug backend)
| ID | Sév | Surface | Défaut | Fix + preuve |
|---|---|---|---|---|
| W4-1 | **P1-backend** | Dashboard channel-statistics | **`$x / $total` 500 si 0 commande** (DivisionByZeroError = Error, PAS catché par catch(Exception) ; vérifié PHP 8.2 : `5/0` throws + DivByZero ∉ Exception) | guard `$total<=0`→0% + **test sentinel 2/2 (no-divide + endpoint 200)** |
| W4-2 | P2 | 6 tickets (taux taxe) | "5.50 %" point en-US (jumeaux du ticket client W3-5) | `.replace('.',',')` display-side → **classe taux-taxe ÉPUISÉE 7/7** |
| W4-3 | P3 | GuestVerify + SignupVerify | `code + '' + phone` coercion no-op (glué sans séparateur) | `+ ' ' +` séparateur (classe `+''+` épuisée) |

**Gates Wave 4 : Vitest 2007/0, Dashboard PHPUnit 41/0 (zero-guard + tous les tests channel existants), frozen §7=0.** **Leçon : « abuse verification » = vérifier le mécanisme du bug AVANT de fixer (j'ai confirmé `5/0` throws en 8.2 + que DivByZero échappe au catch(Exception)) → puis verrouiller par un test sentinel.**

## §8 — WAVE 5 (« Orchestre + planifie + deeply corrige ») — couche LOGIQUE/RUNTIME
Orchestré 2 agents deep (correctness-backend + carte a11y exhaustive). Le pixel-audit avait convergé ; cette vague attaque ce qu'il ne voit pas.

### Backend correctness (3 heals non-frozen + 1 test)
| ID | Sév | file:line | Bug | Fix |
|---|---|---|---|---|
| W5-B1 | **P1 prod** | `AppLibrary:308,313` | `env('CURRENCY_DECIMAL_POINT')` **non-défaulté** → après `config:cache` (prod, mandaté par MA checklist deploy !) env=null → `number_format($x, null)` arrondit à l'ENTIER ("12,50"→"13") sur **~31 resources** (item/order/coupon/tax/solde). Display-only (PricingService intact). Prouvé PHP 8.2. | `?? 2` (miroir :289) + **sentinel `CurrencyDecimalConfigCacheGuard` 2/2** (force env absent) |
| W5-B2 | P2 prod | `AppLibrary:24-56` | même classe : `env('DATE_FORMAT'/'TIME_FORMAT')` non-défaulté → dates malformées après config:cache | défauts `d-m-Y` / `h:i A` (miroir :442) |
| W5-B3 | P3 | `KioskMachineLoginController:99` | race delete-concurrent : `$lockedKiosk` null → `->user_id` puis `$user->tokens()` method-on-null = 500 | guard `abort(409)` (txn rollback + retry borne) |

**Classes correctness ÉPUISÉES (agent, vérifié)** : division non-gardée (le W4 + toutes gardées), `catch(Exception)`-vs-`Throwable` (hot paths OK), array/JSON-access, float-money-précision. **Déférés (perf-cliff latents, refactor V1.0.X)** : export/overview unbounded `->get()` (OrderService:125 + siblings — OOM à terme), VerifyZMembership scan unbounded (cron quotidien), myOrderStore round() (hygiène).

### A11y (60/96 corrigés ; KIOSK = surface client V1 active = déjà CLEAN)
33 modal-close→"Fermer" (bulk) · 17 steppers indec-minus/plus→"Moins"/"Plus" · 8 info-btn→"Informations" · 2 shared-components (SmIconQrCode/SmTimeSloteDelete). verify-before-validate a attrapé MA régression duplicate-attribute (3 modals déjà fixés). **Déféré (énuméré, fix-list agent)** : ~36 boutons icône frontend-web scattered (close-circle/view-toggles/footer-social/navbar/cart/map — surface client-web possiblement vestigiale V1 LOCAL, kiosk déjà clean).

### Frozen (owner-gate) — APPLIQUÉE (countersign accordé)
`plans/LOCK_POS_WIZARD_MONEY_FR_MISSED_SITES_2026-06-22.md` : **4 sites** `pos-wizard.js` money en-US (€3.00 → 3,00 €) → `fmtPrice`. Owner countersign accordé (AskUserQuestion). Appliqué via **cérémonie 2-commits** (le hook pre-commit autorise un edit frozen quand HEAD cite `LOCK_*.md` — **sans `--no-verify`**), SHA256 baseline màj, frozen-sentinel + pos-wizard Vitest 23/23 verts.

### §9 — WAVE 6 (« le tout, max raisonning, deeply corrige »)
- **Perf hot-path FIXÉ** : `salesReportOverview` agrégeait en PHP (`->get()->filter()->sum()` = TOUS les orders chargés à chaque page-load rapport, sans date) → **réécrit en SQL** via `Order::scopeRealizedRevenue` (prouvé byte-équivalent du prédicat `isRealizedRevenueRow`, verrouillé par `SalesReportNetTotalSentinelTest` net=100≠180). 70 tests revenue/dashboard verts = totaux inchangés, travail déplacé en DB.
- **A11y 70/96** (le bulk multi-ligne a créé des duplicate-attribute → reverté propre ; restant ~21 = surface client-web scattered + faux-positifs déjà-labellés multi-ligne).
- **Perf cold-path DÉFÉRÉ (max-reasoning, pas rushé)** : exports `->get('*')` (latent OOM, cold-path user-clic) + `VerifyZMembership` Z-scan (cron quotidien, **fiscal-adjacent** → refactor risqué). Le hot-path (le vrai coût récurrent) est fait ; les cold-path latents ne valent pas le risque sur du code export/fiscal pour V1 mono-restaurant.

## §F — BILAN AUDIT 360° (5 vagues)
**23 heals** (8 W1 + 7 W2 + 5 W3 + 3 W4), **tous NON-frozen, tous vérifiés** (live ou test). Classes systémiques ÉPUISÉES : contraste-AA, null-glue-phone (17), en-US-money(non-frozen), raw-i18n-keys (studio+gateways), datepicker-EN, dates-en-US, taux-taxe-décimal (7), `+''+`-coercion, zero-division-backend. **Gates finaux : Vitest 2007/0, PHPUnit (Dashboard 41/0 + sentinels), frozen §7=0.** PR #23. **OWNER-GATE restant** : `pos-wizard.js`/`PosV5TrancheRow` money en-US (frozen §7). **Déférés V2** : cluster a11y ~18 boutons frontend web (mono-opérateur), form-hints `text-gray-400` convention.

### §10 — WAVE 7 (supervisor « disputes adversaires + boucle test-e2e jusqu'à tout validé »)
Workflow adversaire (rate-limité → mené inline) : **dispute mes propres corrections W5/W6** (les plus fraîches/non-challengées) + **find-new refute-default** + **live e2e**.
- **Dispute → 1 incomplétude réelle** : `OrderItemResource:52 env('CURRENCY')` non-défauté (classe du P1, ratée par mon fix W5) → `env('CURRENCY','EUR')` + **sentinel systémique `EnvDisplayDefaults`** (verrouille toute la classe). 4 disputes TIENNENT (salesReportOverview scope≡prédicat, pos-wizard money=backend FR, null-deref déjà gardés, a11y build-clean).
- **Find-new (4 finders, refute-default) → 1 P3 + 3 réfutés** : P3 = `ComposerProfilePublished` event orphelin (bénin, sibling couvre l'outbox) → **sentinel systémique `EventOrphanWiring`** (a trouvé un 2e orphelin intentionnel `StockDecrementFailedEvent` ; les 2 allowlistés documentés ; tout futur orphelin = CI rouge). Réfutés : OrderItemResource-"EUR" (narratif W7 fabriqué + branche FIXED-tax morte en V1, toutes taxes seedées=PERCENTAGE), PaymentService PAID-sans-fiscal (DORMANT — gateways gated off V1), FIXED-tax-label (dormant).
- **Live e2e MySQL réel** : `salesReportOverview` sortie == somme realized-revenue indépendante **37 774,92 €** (count 2782) ; visuel rapport FR, 0 erreur console.
- **Convergence** : Round-2 re-survey 0 nouvel orphelin / 0 env non-défauté ; **gate sentinels 10/10, PHPUnit 3064, Vitest 2007**. Commits `857f2dda4` (env) + `9da4b6c5c` (orphan-sentinel).

### §11 — WAVE 7b (live e2e parcours + NF525 fiscal dispute)
- **Parcours borne LIVE :8766** : auto-login OK (drift mdp machine rig corrigé), idle "Bienvenue!" FR, **menu cat=10 "9 produits" (fix W2 catégorie-vide tient)**, money FR. 401s = rig-creds (corrigé), 403 WS = dégradation gracieuse (polling fallback vérifié KioskWaitingComponent + channels.php gère kiosk-token).
- **DISPUTE NF525 (la plus haute discipline) — "gap" fiscal investigué à fond** : `FISCAL_GAPS=1` détecté → branche 1 séq 1..2520 avec **trou 2506..2508**. **Tracé jusqu'à preuve : TEST-ARTEFACT, pas un bug.** (1) seq 2506-2508 = AUCUN order (ni soft-deleted) ; (2) Order utilise **SoftDeletes** → un `delete()` prod GARDE la ligne + le fiscal_sequence_no (pas de trou) ; (3) seuls hard-deletes = commandes TEST/DEV (`FreshOrderSeed::truncate`, `Iter15CleanupTestOrdersCommand` raw-delete des orders abuse-test) ; (4) 19/46 id-slots 4974..5019 hard-deleted par le cleanup. **Invariant prod gap-free INTACT** (alloc monotone + soft-delete + rétention 6 ans + triggers) et **déjà verrouillé** (`PosOrderDestroyTest` "no forceDelete"/assertSoftDeleted + `FiscalSequenceTest` + `NF525ComplianceE2ETest`). Aucun fix requis — validation positive.
