# RED TEAM R5 — SYNTHÈSE FINALE ADVERSAIRE FoodKing V1

**Date** : 2026-05-07
**Persona** : Auditeur senior + Quality Director — synthèse adversaire des 4 cycles RED/BLUE (POS, Kiosk, Rupture stock, KDS)
**Mission** : challenger le verdict BLUE final "PROD-READY après les 4 fixes" et trancher PROD-READY / NOT / HEAL pour la release V1.
**Méthodologie** : lecture intégrale des 8 rapports R1-R4 RED + R1-R4 BLUE + recoupement avec les commits BLUE déjà mergés (`9ce2f2e6f`, `e309083b7`, `7114cec56`, `8ec2d3a0e`).

> Document de synthèse — pas de nouvelle spec Playwright, pas de fix code. Pas de complaisance non plus : si un risque résiduel pèse sur la V1, il est listé.

---

## 1. Recap par cycle

### R1 POS Prise de commande (16 étapes + 1 retry)
RED a livré 27 findings durables (0 P0, 5 P1 a11y/UX/ops confirmés runtime, 11 P2, 2 réfutations honnêtes). Les vraies découvertes : wizard popup POS sans `role="dialog"` / `aria-modal` / focus-trap (W1/W2/W3 — RGAA bloquant), `autocomplete="off"` sur password login (anti-pattern OWASP), absence de banner offline côté POS admin, et un thread méthodologique sur la sentinel `paymentComponentPropMutation` tolérantisée. BLUE a admis 2 vrais P1 (W1/W2/W3 + L1) et fixé en cycle (commit `9ce2f2e6f`), réfuté 3 findings sourcés (D1/D2 backend cap discount RBAC documenté `OrderService.php:2135-2182`, L5 remember-me ligne 33-42 que RED n'avait pas vue, Q-04-2 leak modal déjà rétracté par RED post-RETRY), et différé 3 plans P2. **Bilan R1 : 2 vrais P1 capturés, fixes scope-minimal validés runtime 2/2 PASS.**

### R2 Kiosk Prise de commande (15 étapes)
RED a livré 22 findings (3 P0, 4 P1, 5 P2, 5 INFO, 5 OK). Découverte centrale : **asymétrie a11y POS/kiosk** — le wizard kiosk (`KioskWizardComponent.vue`) avait exactement les mêmes défauts a11y que le POS R1 mais ils n'avaient pas été fixés. C'est une régression directe contre EAA 2025 (entrée en vigueur). BLUE a admis WK1/WK2/WK3/WK4 et appliqué le mirror du fix R1 sur le wizard kiosk (commit `e309083b7`, validation 1/1 PASS), réfuté DSK1 Fraunces (faux positif harness — `document.fonts.check()` retourne false avant utilisation, link tag présent `master.blade.php:52-57`), et différé 6 plans P2 (PUSHER-banner doctrine, OK1 banner offline, CART aria-live, CSP-meta, AK1 data-quality allergens, DSK1 ré-instrumenter). **Bilan R2 : 4 vrais P0 a11y EAA capturés et fixés. Pattern symétrique au R1.**

### R3 Rupture stock live propagation (11 scénarios)
RED a livré 10 findings (3 P1 confirmés runtime, 4 fondations OK validées). Découverte centrale : F2 — **POS UI ne reflète pas `is_available=false` après reload alors que l'API backend retourne correctement la donnée** (vrai bug SPA Vuex/projection, vérifié curl post-mortem). F3 — KDS sans marker "ITEM RECENTLY 86" pour tickets in-flight. F1 — pipeline outbox claim "0 events ever dispatched". BLUE a admis F2 et F3 comme P1/P2 V1.x avec plans dédiés (`CV1-POS-AVAILABILITY-LIVE-001`, `CV1-KDS-INFLIGHT-OOS-MARKER-001`), et **réfuté F1 avec evidence runtime indépendante** (commit `7114cec56`) : reproduction avec `BROADCAST_DRIVER=log` montre `dispatched_at` set, attempts=1 → faux positif harness (websockets:serve port 6001 non démarré). Mitigation V1 acceptée : R3-05 confirme que **le backend re-valide au submit** (HTTP 422 explicite) → pas de risque de commande encaissée pour item OOS, juste friction UX caissier. **Bilan R3 : 0 fix code, F1 réfuté avec rigueur, F2/F3 plans différés acceptables vu la mitigation backend.**

### R4 KDS Réception + status transitions (17 scénarios)
RED a livré 17 findings (0 P0, 1 P1 statique, 6 P2, 10 OK). KDS = **surface la mieux verrouillée des 4** : 0 violations axe-core WCAG 2.0/2.1 A+AA, forward-only state machine 3-verrous (KitchenReleaseRule + OrderStateMachine + KdsOrderStatusRequest), branch isolation double-couche (BranchScope + abort 403, runtime confirmé HTTP 404), audit log `recordTransition()` avec correlation_id. Découverte centrale : **KD5 — chime new-order silencieux quand churn ±1** (`KitchenDisplaySystemComponent.vue:921-929`, watcher length-based vs ID-based). Heure de pointe : 1 ACCEPT entre + 1 PREPARED sort → length stable → silence → commande oubliée. BLUE a admis et fixé en ~10 lignes ID-based diff (commit `8ec2d3a0e`) + sentinel JS `kdsNewOrderChimeIdBased.spec.js` (10/10 PASS). 5 P2 différés en plans dédiés (KD7 édition post-envoi, KD11/F3 inflight 86, KD1/KD2 a11y rich, KD3 focus clavier, KD16 accordéon). **Bilan R4 : 1 vrai P1 fixé + sentinel garde + 5 P2 plans V1.x.**

---

## 2. Scoreboard final R1-R4

| Cycle | RED Total | P0 vrais | P1 vrais | P0/P1 faux positifs | BLUE fixés | Plans différés V1.x | Score réfutations BLUE |
|-------|----------:|---------:|---------:|--------------------:|-----------:|--------------------:|:-----------------------|
| R1 POS | 27 | 0 | 2 (W1+W2+W3 groupé, L1) | 2 (L3 boutons, W5 21 modals) | 2 P1 | 3 (banner offline POS, UX 409, JSDoc) | 3/3 sourcées (D1/D2 RBAC `OrderService.php:2135-2182`, L5 ligne 33-42, Q-04-2 auto-rétracté) |
| R2 Kiosk | 22 | 3 (WK1+WK2+WK4 groupé) | 1 (WK3) | 1 (DSK1 Fraunces — harness artifact) | 4 P0/P1 (mirror R1) | 6 (PUSHER doctrine, OK1, CART aria-live, CSP, AK1, DSK1 reinstrument) | 1/1 sourcée (DSK1 link tag présent `master.blade.php:52-57`) |
| R3 Rupture | 10 | 0 | 1 réel (F2 SPA) + 1 P2 (F3 KDS marker) | 1 (F1 outbox — harness `BROADCAST_DRIVER` artifact) | 0 fix immédiat | 4 (`CV1-POS-AVAILABILITY-LIVE-001`, `CV1-KDS-INFLIGHT-OOS-MARKER-001`, `CV1-OBSERVABILITY-OUTBOX-001`, `CV1-CI-WEBSOCKETS-HARNESS-001`) | 1/1 sourcée (F1 reproduction runtime BROADCAST_DRIVER=log → dispatched_at set) |
| R4 KDS | 17 | 0 | 1 (KD5 sound silence) | 0 | 1 P1 + sentinel JS | 3 (`CV1-POS-EDIT-AFTER-SEND-001`, `CV1-KDS-A11Y-RICH-001`, KD3 focus V2) | N/A (RED a documenté 10 OK confirmés sains) |
| **TOTAL** | **76** | **3** | **5** | **4** | **7 P0/P1 fixés** | **16 plans V1.x** | **5/5 réfutations sourcées** |

### Lecture du scoreboard
- **3 P0 vrais** : tous wizard kiosk a11y EAA bloquant (R2 WK1/WK2/WK4) — fixés.
- **5 P1 vrais** : 2 POS (W1/W2/W3 + L1) + 1 kiosk (WK3) + 1 SPA POS projection (R3-F2, plan dédié) + 1 KDS sound (R4 KD5, fixé).
- **4 faux positifs P0/P1** : honnêtement réfutés par BLUE source-by-source — L3 boutons submit (probe Playwright erronée), W5 21 modals (auto-rétracté RED post-RETRY), DSK1 Fraunces (harness artifact), F1 outbox (harness `BROADCAST_DRIVER` non configuré). Ratio faux positifs / vrais : **4/8 = 50%** — RED a été utile mais aussi bruyant par moments. À noter : **RED a lui-même rétracté W5 et documenté en §7 R3 la limitation harness sur F1** — discipline d'auditeur correcte.
- **5/5 réfutations BLUE sourcées et valides** : toutes citent `file:line` ou reproduction runtime indépendante (curl, tinker, BROADCAST_DRIVER=log). Aucune réfutation paresseuse. **Rigueur méthodologique BLUE = 100%**.
- **7 P0/P1 capturés que 1573 phpunit + 70+ sentinels JS + 125+ Playwright avaient ratés** : **ROI adversaire démontré**.

---

## 3. Findings encore OPEN (P2 plans différés V1.x)

Les 16 plans V1.x ne sont pas tous au même niveau de criticité pour la V1 release. Triage critique adversaire :

### Bloquants ou quasi-bloquants V1 (à exécuter avant release ou avoir mitigation explicite)

| Plan ID | Description | Source | Pourquoi quasi-bloquant |
|---|---|---|---|
| `CV1-POS-AVAILABILITY-LIVE-001` | Fix POS UI projection après toggle (Vuex stale) | R3-F2 | **UX caissier dégradée** : caissier voit la tuile, clique, reçoit 422 au submit. Pas de risque DATA (backend re-valide R3-05) mais friction inacceptable en production restaurant. **À fixer V1 ou avoir un plan documenté de comm interne caissiers**. |
| `CV1-OBSERVABILITY-OUTBOX-001` | Dashboard `/admin/observability/outbox` | R3 §5 reco BLUE | Sans dashboard, opérations ne sait pas si Pusher est UP. F1 a été réfuté en local mais en prod, si laravel-websockets crashe, **silence total** sur la propagation rupture. Observabilité indispensable pour ops. |
| `CV1-CI-WEBSOCKETS-HARNESS-001` | Démarrer websockets:serve + queue:work --queue=high en CI | R3 reco | **Sans ce harness, 1573 phpunit + 125+ Playwright continuent à valider en mode "broadcast log"** — ne testent jamais le push réel. Toute future spec sync rupture aurait le même angle mort. **Trou méthodologique CI**. |
| BANNER OFFLINE POS (plan P2 R1-O1) | `window.addEventListener('online'/'offline')` dans `ConnectionStatusBanner.vue` | R1-O1 | RED-R1 a documenté `navigator.onLine === false` sans aucun banner POS. Soir de coupure WiFi = caissier compose 1h de commandes silencieusement perdues si offline queue POS n'existe pas (kiosk a sa queue, **POS n'en a pas vérifié explicitement**). |

### Polishing V1.x (non-bloquants release)

| Plan ID | Description |
|---|---|
| `CV1-KDS-INFLIGHT-OOS-MARKER-001` | Badge OOS sur tickets in-flight (R3-F3 + R4-KD11). Mitigation V1 : process verbal OSS-cuisine standard restaurant. Acceptable. |
| `CV1-KDS-A11Y-RICH-001` | `role="article"` + `aria-labelledby` + `aria-live` transitions. **0 violations axe-core déjà**, donc polishing pur. |
| `CV1-POS-EDIT-AFTER-SEND-001` | Endpoint pos-order/edit-items + broadcast OrderItemsChanged. **Clarifier produit** : V1 supporte-t-il l'édition post-envoi ? Si non, ce plan est inutile V1. |
| PUSHER-banner doctrine kiosk | Retirer `suppress-session-invalid` (banner session terminée justifié), garder `suppress-transient`. Trade-off doctrine UX kiosk volontaire. |
| OK1 banner offline kiosk | Auto-route `KioskErrorNetworkComponent` quand `navigator.onLine === false`. Queue technique solide, juste UX silencieuse. |
| CART aria-live kiosk | 1 attribut. |
| CSP `<meta>` → header HTTP | Sécurité défense-en-profondeur, pas un risque immédiat. |
| AK1 allergens data-quality | Peupler pivot `item_allergens` Tacos M. Cycle data dédié. |
| DSK1 Fraunces ré-instrument | Tester avec `document.fonts.ready`. |
| KD7 édition POS post-envoi | Idem `CV1-POS-EDIT-AFTER-SEND-001`, dépend du scope produit V1. |
| KD3 focus clavier KDS | Reporté V2 par BLUE. |
| KD16 accordéon collapsed | Investigation produit. |
| Q-13-2 UX 409 | Toast spécifique sur Idempotency-Key-Conflict. Edge case rare (2 onglets POS même ticket). |
| Q-X-1 JSDoc PaymentComponent emits | Documentation. |
| Q-15-2 Idempotency dédup KDS | À investiguer si race possible. |
| AK1 cycle data | Hors-code. |

### Cap discount manager-PIN (Q-09-3 R1)
**Réfuté par BLUE comme RBAC permission-based** (`OrderService.php:2135-2182`, 4 paliers `pos-discount-up-to-10` / `over-10-requires-manager` / `unlimited`). RBAC > PIN modal partageable. **Acceptable V1** mais à documenter clairement dans `docs/AUTHZ_MATRIX.md` si pas déjà fait.

---

## 4. Trous méthodologiques exposés par cette campagne

Cette campagne adversaire a démontré que **1573 phpunit + 125+ Playwright + 70+ sentinels JS** n'avaient pas vu 7 vrais P0/P1. Pourquoi ? Patterns de bugs systématiquement ratés :

### 4.1 — Sentinels structurels (source-grep) ratent les bugs runtime DOM
- W1/W2/W3 a11y wizard POS, WK1/WK2/WK4 wizard kiosk : les attributs DOM `role`, `aria-modal`, focus-trap **n'existaient pas** dans le source. Aucun grep "manque-de" ne pouvait les détecter.
- KD5 watcher length-based : pattern `newVal.length > oldVal.length` **valide syntaxiquement**, sentinels structurels ne distinguent pas length-based de ID-based.
- R3-F2 SPA Vuex projection : le code consommer event Pusher (`_onItemAvailabilityChanged`) **est correct par grep** ; le bug est dans le timing/cache `localStorage.vuex` rehydrate avant fetch. Source-grep aveugle.

**Pattern** : sentinels structurels valident *présence* d'un pattern, pas *absence d'un anti-pattern*. Pour combler, il faut soit des sentinels DOM-runtime (probe attributes runtime) soit des sentinels comportementaux (E2E avec assertion sur l'effet, pas sur le code).

### 4.2 — Tests E2E ratent les bugs hors du happy-path principal
- 125+ Playwright passent parce qu'ils suivent le flow nominal (login → produit → wizard → cart → pay → ticket). Les tests **ne probent pas systématiquement** : a11y attributes runtime du wizard ouvert (R1 W1-W3), `navigator.onLine === false` UX (R1-O1), CSP `<meta>` ignored (R2), `document.fonts.check` (R2 DSK1 — qui s'est retourné contre RED), focus management clavier (R4 KD3), accordéon collapsed (R4 KD16).
- **Pattern** : tests E2E mesurent "ça marche" pas "c'est accessible / observable / robuste sur les edges".

### 4.3 — Sentinels CI ne reflètent pas l'environnement prod
- F1 outbox : 0 events dispatched **car le harness local n'a pas `websockets:serve`**. Les sentinels MEGA-D 10/10 PASS ont validé la *création* DB, pas la *propagation* Pusher. En prod, websockets DOIT être UP pour que la chaîne marche.
- DSK1 Fraunces : `document.fonts.check()` retourne false avant utilisation → faux positif si l'on ne fait pas `document.fonts.ready`.
- **Pattern** : harness CI != harness prod, et les sentinels qui se basent sur l'absence d'effet en harness sont fragiles. **`CV1-CI-WEBSOCKETS-HARNESS-001` est critique** pour fermer ce trou.

### 4.4 — Asymétries entre surfaces sœurs
- Le wizard POS a été fixé R1 (commit `9ce2f2e6f`) mais le wizard kiosk a été oublié → R2 WK1-WK4 ont retrouvé exactement les mêmes 4 défauts. Symétrie pas vérifiée.
- Banner offline kiosk existe (`kioskOfflineQueue.js`), POS n'en a pas → asymétrie OPS.
- Cart aria-live POS OK, kiosk pas vérifié → R2 CART-NO-ARIA-LIVE.
- **Pattern** : aucune sentinel ne vérifie que **POS ↔ kiosk** présentent les mêmes invariants UX/a11y/ops. À ajouter.

### 4.5 — Trade-offs UX volontaires non-documentés
- `suppress-transient` + `suppress-session-invalid` sur kiosk = doctrine UX volontaire (caché en prod), mais aucun sentinel ne lock cette décision pour empêcher qu'un agent retire silencieusement un suppress en pensant "réparer" un bug.
- Sentinel `paymentComponentPropMutation` tolérantisé R1 : trade-off explicite, mais sentinel n'est plus une signature stricte. Aucun mécanisme de review humaine forcée pour l'ajout de nouveaux emits.
- **Pattern** : doctrines UX/architecture nécessitent des sentinels-ancre qui FORCENT la review humaine sur leur modification, pas des sentinels-tolérants.

---

## 5. Recommandations procédurales — Cycle adversaire dans le pipeline

### 5.1 — Garder la procédure RED/BLUE adversaire dans le pipeline V1.x → V2

**Argument fort** : 7 vrais P0/P1 capturés en 4 cycles + 5 réfutations sourcées + 16 plans V1.x documentés. La méthodologie a démontré son ROI sur des bugs runtime/UX/a11y que les sentinels structurels existants ratent par construction.

**Fréquence recommandée** :
- **Pré-release majeure** (V1, V1.x stable) : 1 cycle adversaire complet (4-5 surfaces × 1 RED + 1 BLUE) **obligatoire**.
- **Post-cycle features critiques** (nouveau flow paiement, nouveau composant kiosk, nouveau type d'order) : 1 cycle adversaire ciblé.
- **Trimestriel** sur les surfaces sensibles (POS, kiosk, KDS, paiement) : 1 cycle express type R4 (focus 1 surface, ~17 scénarios).

### 5.2 — Discipline RED à maintenir
- **Limitations honnêtes obligatoires** en tête : RED-R3 §7 sur F1 harness, RED-R1 §0 cascade, RED-R4 §6 audio autoplay. **Discipline anti-hallucination = condition non-négociable**.
- **Evidence durable** : screenshots PNG + findings JSON + dom-snapshots JSON + INDEX.md. R1 / R2 / R3 / R4 ont tous suivi cette discipline.
- **Faux positifs auto-rétractés** : RED-R1 a rétracté W5 (21 modals) post-RETRY, RED-R3 a documenté la limitation harness F1 en §7. **Rigueur d'auditeur = continuer à rétracter quand nouvelle evidence contredit le claim initial**.

### 5.3 — Discipline BLUE à maintenir
- **Réfutation source-by-source obligatoire** : BLUE a réfuté 5 findings RED en citant `file:line` ou en reproduisant runtime indépendamment. **0 réfutation paresseuse** sur les 4 cycles. Standard à préserver.
- **Fix scope-minimal** : R1 W1/W2/W3 ~25 lignes, R2 WK1-WK4 ~30 lignes, R4 KD5 ~10 lignes. Discipline INLINE-EDIT-EXCEPTION (memory `feedback_orchestrator_inline_edit_exception.md`) respectée.
- **Sentinels anti-régression sur chaque fix** : R4 KD5 a une sentinel JS dédiée `kdsNewOrderChimeIdBased.spec.js`. **À généraliser systématiquement** : tout fix RED-validated doit avoir un sentinel JS ou Playwright qui empêche le retour en arrière.

### 5.4 — Nouveaux sentinels à ajouter (issues de la campagne R1-R4)
1. **DOM-runtime probe a11y** : sentinel Playwright qui ouvre wizard POS + wizard kiosk + modal allergens et vérifie `role="dialog"` / `aria-modal="true"` / focus-trap actif. **Anti-régression W1-W3 + WK1-WK4**.
2. **Symétrie POS↔kiosk** : sentinel structurel qui compare la liste des attributes a11y / data-testid entre `ItemComponent.vue` (POS) et `KioskWizardComponent.vue` (kiosk). Détecte les divergences silencieuses.
3. **Outbox health check** : sentinel ops qui vérifie en CI que `websockets:serve` est UP + `queue:work --queue=high` est drainé + `domain_events.dispatched_at` est correctement bumpé pour un event fraîchement créé.
4. **Watcher Vue ID-based vs length-based** : sentinel structurel grep qui empêche `newVal.length > oldVal.length` dans tout watcher orders/items/etc. **Anti-régression KD5**.
5. **Banner offline UX** : sentinel Playwright `context.setOffline(true)` → assert banner visible POS + kiosk + KDS. **Couvre R1-O1 + R2-OK1**.
6. **i18n drift** : sentinel structurel qui détecte `aria-label="Add..."` / `placeholder="Sélectionner..."` mixed dans le même composant. **Couvre R1-CS1 + R1-W6 + R1-S2**.

### 5.5 — Décision-cadre pour les RED/BLUE futurs
- **RED ≠ blocker automatique** : un finding RED P1 peut être réfuté par BLUE si la réfutation est sourcée. R3-F1 est l'exemple parfait.
- **BLUE ≠ approbation automatique** : un finding BLUE-admis avec plan différé doit être triée pour la release courante (cf. §3 ci-dessus, 4 plans quasi-bloquants V1).
- **R5 = arbitre indépendant** : la synthèse adversaire finale tranche. Garder ce rôle pour les futures campagnes.

---

## 6. Verdict global FINAL

### **PROD-READY pour V1 release CONDITIONNÉ — HEAL léger requis sur 2 plans avant tag final.**

#### Ce qui est PROD-READY
- **3 P0 wizard kiosk a11y EAA fixés** (commit `e309083b7`) + 4 P1 POS / KDS fixés (`9ce2f2e6f`, `8ec2d3a0e`).
- **Backend invariants critiques tous verrouillés et runtime confirmés** : forward-only state machine KDS (3 verrous convergents), branch isolation double-couche (404 cross-branch), discount RBAC 4 paliers, `assertItemsOrderableForBranch` au submit (pas de commande encaissée pour item OOS), no double-sell (cascade auto-86), audit log `OrderStatusTransition` avec correlation_id, idempotency-key middleware UNIQUE.
- **0 violations axe-core WCAG 2.0/2.1 A+AA sur KDS**, surface la mieux verrouillée.
- **Outbox pipeline contractuel correct** (F1 réfuté avec evidence runtime, payload V1 contract complet) — la consommation prod fonctionne avec laravel-websockets UP.
- **Sentinels anti-régression** créées sur les fixes (R4 KD5).

#### Ce qui exige HEAL avant V1 release final
- **`CV1-POS-AVAILABILITY-LIVE-001`** (P1 V1.x) — friction UX caissier OOS. Le backend protège la donnée mais l'UX caissier est dégradée. **À fixer V1 ou avoir un plan documenté de fallback procédural**. Estimation : 2-4h investigation Vuex devtools + fix.
- **`CV1-CI-WEBSOCKETS-HARNESS-001`** (P2 ops, mais critique méthodologique) — sans ce harness CI, **toute future régression sur le pipeline Pusher passera silencieusement**. F1 réfuté en local prouve juste que le code marche, pas que CI le valide. Estimation : 1h CI script.

#### Ce qui peut être différé V1.x sans risque
- **`CV1-OBSERVABILITY-OUTBOX-001`** : dashboard ops, recommandé V1.x rapide après release.
- **`CV1-KDS-INFLIGHT-OOS-MARKER-001`** + 5 autres P2 (KDS a11y rich, KDS focus, accordéon, allergens data, CSP, etc.) : polishing.
- **`CV1-POS-EDIT-AFTER-SEND-001`** : conditionnel à décision produit.

#### Risque résiduel non-couvert par les 4 cycles RED-R1-R4
- **Hardware** : TPE physique, impression NF525, on-screen keyboard kiosk physique, `--kiosk` Chromium flag, black-screen guard OS. Hors-Playwright. **À valider par cycle ops dédié sur borne physique avant déploiement client**.
- **Multi-branch réel** : R3 testé branch=1 seul, R4 cross-branch testé via order forgé. **Validation finale avec 2+ branches réelles + 2+ users avec branch_id différents requise**.
- **Pusher cloud / laravel-websockets prod** : aucun cycle ne l'a testé en environnement prod-like. Recommandation forte : staging pré-release avec broker UP + `queue:work --queue=high` daemon.
- **Charge / heure de pointe** : rush 12h/19h non simulé. KD5 fix règle le bug théorique mais le scénario "+1/-1 simultané" reste à mesurer en charge réelle.

#### Verdict détaillé
- **Si les 2 plans HEAL ci-dessus sont exécutés avant tag V1** : **PROD-READY**.
- **Si V1 release malgré les 2 plans non-exécutés** : **PROD-READY avec risque OPS documenté** (UX caissier dégradée + angle mort CI broadcast). Acceptable seulement si la mitigation backend (R3-05) est explicitement communiquée aux caissiers dans le runbook de mise en service, et si l'observabilité Pusher est monitorée en prod par alerting Sentry / Pusher dashboard.
- **NOT PROD-READY si** : le `CV1-POS-AVAILABILITY-LIVE-001` n'est pas tracké comme P1 V1.x (priorité absolue patch dans les 7 jours post-release).

#### Dissonance avec le verdict BLUE final
Le verdict BLUE final "PROD-READY après les 4 fixes" est **techniquement exact sur les fixes appliqués** mais **incomplet sur le triage des plans différés**. Les 4 fixes (commits `9ce2f2e6f` / `e309083b7` / `7114cec56` / `8ec2d3a0e`) sont solides, validés runtime, avec sentinels. Mais traiter `CV1-POS-AVAILABILITY-LIVE-001` comme un V1.x simple est **trop optimiste** : c'est de la friction caissier en production, pas du polishing.

**R5 ajuste BLUE de "PROD-READY" à "PROD-READY conditionné HEAL léger sur 2 plans"** — pas un downgrade fondamental, juste un triage plus strict des plans V1.x à exécuter avant tag final.

---

## 7. Annexes

### 7.1 — Commits BLUE déjà mergés (à conserver)
- `9ce2f2e6f` — BLUE-R1 : a11y wizard POS (W1/W2/W3) + autocomplete login (L1). Spec validation 2/2 PASS.
- `e309083b7` — BLUE-R2 : a11y wizard kiosk symétrie (WK1/WK2/WK3/WK4). Spec validation 1/1 PASS.
- `7114cec56` — BLUE-R3 : F1 réfuté avec evidence runtime + plans dédiés F2/F3.
- `8ec2d3a0e` — BLUE-R4 : KD5 fix watcher ID-based + sentinel JS `kdsNewOrderChimeIdBased.spec.js` (10/10 PASS).

### 7.2 — Plans V1.x à exécuter (priorité décroissante)

**Critique pré-V1 release final (HEAL)**
1. `CV1-POS-AVAILABILITY-LIVE-001` — Fix POS UI projection après toggle (Vuex stale) — **P1**, 2-4h.
2. `CV1-CI-WEBSOCKETS-HARNESS-001` — Démarrer websockets:serve + queue:work --queue=high en CI — **P2 ops critique**, 1h.

**Important V1.x post-release rapide**
3. `CV1-OBSERVABILITY-OUTBOX-001` — Dashboard `/admin/observability/outbox` — P2, 2-3h.
4. Banner offline POS — `window.addEventListener('online'/'offline')` dans `ConnectionStatusBanner.vue` — P2, 5 lignes.
5. PUSHER-banner doctrine kiosk — retirer `suppress-session-invalid`, garder `suppress-transient` — P2, 1 ligne.

**Polishing V1.x**
6. `CV1-KDS-INFLIGHT-OOS-MARKER-001` — Badge OOS tickets in-flight — P2.
7. `CV1-KDS-A11Y-RICH-001` — `role="article"` + `aria-labelledby` + `aria-live` transitions — P2.
8. `CV1-POS-EDIT-AFTER-SEND-001` — Endpoint pos-order/edit-items + listener KDS — P2 (clarifier produit).
9. OK1 banner offline kiosk — auto-route `KioskErrorNetworkComponent` — P2.
10. CART aria-live kiosk — 1 attribut — P2.
11. CSP `<meta>` → header HTTP — P2.
12. AK1 allergens data-quality (cycle data dédié) — P2.
13. DSK1 Fraunces ré-instrumenter avec `document.fonts.ready` — P2.
14. Q-13-2 UX 409 toast — P2.
15. JSDoc PaymentComponent emits — P2.
16. KD3 focus clavier KDS — V2.

### 7.3 — Nouveaux sentinels recommandés
1. DOM-runtime a11y wizard (POS + kiosk).
2. Symétrie POS↔kiosk (a11y attributes + testids).
3. Outbox health check CI (websockets:serve UP + queue drained + dispatched_at bumpé).
4. Watcher Vue ID-based (anti `newVal.length > oldVal.length`).
5. Banner offline UX (POS + kiosk + KDS).
6. i18n drift (mixing FR/EN dans même composant).

### 7.4 — Évidence durable des 4 cycles RED
- 76 findings durables JSON (R1: 27, R2: 22, R3: 10, R4: 17).
- 64 screenshots PNG (R1: 20, R2: 15, R3: 16, R4: 13).
- 4 specs Playwright (`tests/e2e/red-team-r{1,2,3,4}-*.spec.js`).
- 4 spec validation BLUE post-fix (R1: 2/2 PASS, R2: 1/1 PASS, R3: N/A, R4: 1/1 sentinel JS).
- 4 INDEX.md de campagne dans `tests/e2e/screenshots/red-team-r{1,2,3,4}-*/`.

---

*R5 — synthèse adversaire FoodKing V1, 2026-05-07. Verdict : PROD-READY conditionné HEAL léger sur `CV1-POS-AVAILABILITY-LIVE-001` + `CV1-CI-WEBSOCKETS-HARNESS-001`. Méthodologie RED/BLUE adversaire validée pour intégration permanente au pipeline V1.x → V2.*
