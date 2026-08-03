# RED TEAM R2 — Kiosk prise de commande

**Date** : 2026-05-07
**Auditeur** : agent adversaire (zéro complaisance)
**Branche** : cycle/PHASE2-TRAIN-A-V1-RELEASE-PREP-2026-04-27
**Objet** : challenger le verdict "FoodKing V1 PRODUCTION-READY" émis par la blue team sur le parcours kiosk (cycles K0-K6 + MEGA-A à F).

---

## 0. Méthodologie

- Spec Playwright dédiée : `tests/e2e/red-team-r2-kiosk-prise-commande-2026-05-07.spec.js`
- 15 tests serial, 1 worker, 1080×1920 (kiosk vertical), `reuseExistingServer=true` sur `http://localhost:8000`
- 15 screenshots full-page durables : `tests/e2e/screenshots/red-team-r2-kiosk-2026-05-07/step-NN-*.png`
- Findings + DOM probes JSON écrits **immédiatement** après chaque assertion (`findings.json`, `dom-snapshots.json`, `synthesis.json`)
- Probes adversaires runtime, pas de hallucination ; les éléments non testables côté browser sont notés "non-validé runtime / limitation honnête".

**Compteurs finaux** : 22 findings — **3 P0**, **4 P1**, **5 P2**, **5 INFO**, **5 OK/RÉFUTÉ**.

---

## 1. Bilan par étape

### Step 01 — Login borne (auto-login silencieux)
- **Observation** : `KIOSK_REQUIRE_MACHINE_LOGIN=false` → SPA redirige sans formulaire vers `/kiosk/idle`. Login API silencieux via `config/kiosk.php` (kiosk-lecayenne / kiosk123 en local).
- **Critique** : le kiosk **expose 3 erreurs console au boot**, dont une CSP report-only **délivrée via `<meta>` (ignorée par les browsers)**, et un Pusher WebSocket qui échoue (broker laravel-websockets non démarré côté harness). Le verdict prod-ready ne peut pas inclure une CSP qui ne s'applique pas.
- **Question** : pourquoi aucun sentinel n'a flag la CSP en `<meta>` ? Le wallet de sécurité de la blue team a un trou.

### Step 02 — Token leak probe (LK1)
- **Observation** : URL `kiosk/idle` propre, **aucun token shape Sanctum** (`\d+\|[A-Za-z0-9]{30,80}`) dans `localStorage`/`sessionStorage`/cookies. Cookies = `XSRF-TOKEN` only (encrypted Laravel).
- **Critique** : RÉFUTÉ partiellement. Mais `localStorage.vuex` contient l'auth state ; un XSS pourrait scraper l'état complet. La vraie attaque-vector serait `script-src 'unsafe-inline' 'unsafe-eval'` dans la CSP report-only — **double trou** combiné avec le finding step 01.

### Step 03 — Surface accueil (DS Bold + tokens)
- **Observation** : `.kiosk-idle.kiosk-idle--bold` rendu, **fontFamily root computed = `Inter, -apple-system, ...`** (cascade body, sans-serif). `document.fonts.check('1em Fraunces') = false`.
- **Critique nuancée** : Inter sur le ROOT body est **par design** (Inter = body font, Fraunces = display font sur headings). Le finding INFO step 3 isolé ne suffit pas comme P2 (sera ré-évalué). La vraie alerte est step 6 où Fraunces est attendu mais absent du runtime fonts API.
- **Action** : ce finding step 3 est **rétrogradé en INFO/note** ; le P1 réel reste sur step 6 (DSK1-FRAUNCES-NOT-LOADED).

### Step 04 — Categories
- **Observation** : 15 catégories sidebar (`kiosk-categories-sidebar-item-*`) + 1 à 4 produits par catégorie selon le seed. testids correctement préfixés.
- **OK** côté structure.

### Step 05 — Wizard kiosk a11y (probe centrale, FAILLES P0 CONFIRMÉES)
- **Observation** : ouverture wizard via overlay inline `.kiosk-wizard-overlay > .kiosk-wizard` (pas de route push). Probe DOM runtime sur le root :

  | attribut | valeur runtime | attendu |
  |---|---|---|
  | `role` | **`null`** | `dialog` |
  | `aria-modal` | **`null`** | `true` |
  | `aria-label` | **`null`** | label item |
  | `aria-labelledby` | **`null`** | `kiosk-wizard-title` ou similaire |
  | `hasH1` | `true` | OK (sr-only h1 présent) |
  | focus après 25 Tabs | **escape vers `.kiosk-sidebar-item`** (background) | rester dans wizard |

- **Critique sévère** : ces 4 attributs manquants sont **exactement** la même classe de défauts que W1/W2/W3/W4 que la R1 a fixés sur le wizard POS. La blue team a corrigé la borne caisse et oublié la borne client. **Régression directe contre EAA 2025**.
- Référence code : `resources/js/components/frontend/kiosk/KioskWizardComponent.vue:2` — `<div class="kiosk-wizard">` n'a aucun attribut a11y. Le focus-trap (`watch.showAbandonConfirm` lignes 2030-2068) est wired UNIQUEMENT au sub-modal d'abandon, pas au wizard root.
- **AK1 (allergens mid-wizard)** : item de test ("Tacos M (1 Viande)") retourne **`allergens=null/[]`** côté API. Le composant `KsAllergenBadge.vue:3` a `v-if="visibleAllergens.length > 0"` → badge invisible. Donc :
  - badge correctement caché vu la donnée vide
  - **mais** le client n'a aucun moyen UX de distinguer "ce produit est sans allergènes" de "données manquantes". **FIC UE 1169 ambiguïté**.

### Step 06 — Wizard typography
- **Observation** : `tokens-bold.css` chargé ; `--kiosk-font-display` = `'Fraunces', 'Recoleta', 'DM Serif Display', Georgia, ui-serif, serif` (correct côté token). Mais `document.fonts.check('1em Fraunces') = false`. `frauncesBold = true` (mais c'est probablement la fallback Georgia qui matche le poids 700, pas la vraie police).
- **Critique** : la chaîne CSS variable est correcte ; **la police n'est pas vraiment chargée**. Soit `<link rel="stylesheet" href="...fonts.googleapis.com/...Fraunces...">` absent du layout kiosk, soit le self-host n'a pas livré le fichier. **DS V1 Bold est un mensonge runtime sur le wizard**.

### Step 07 — Cart
- **Observation** : 16 items après seed (le seed s'accumule à travers les tests serial — Vuex localStorage persistant), grand total = €24,50. testids présents (`kiosk-cart-summary`, `kiosk-cart-total`).
- **Critique** : `kiosk-cart-summary` a `role="region"` + `aria-label="kiosk.subtotal"` mais **pas d'`aria-live`**. Quand le total change après ajout/retrait/promo, le screen-reader ne l'annonce pas. Régression a11y vs cart POS.

### Step 08 — Payment
- **Observation** : 3 méthodes détectées via testid `kiosk-payment-method-card / cash / tr`. Surface card / cash / tickets-resto exposée.
- **Critique** : **TPE direct vs counter-collect = hardware integration, non testable depuis Playwright** (limitation honnête). Le SPA expose les variants UI ; le comportement TPE réel reste hors scope.

### Step 09 — Inactivity (TK1)
- **Observation** : `kioskSettings.idleMs = 180000ms` (3min), `confirmMs = 30000ms`. Configuration cohérente avec un kiosk self-service.
- **OK** — RÉFUTÉ TK1.

### Step 10 — Offline queue (OK1)
- **Observation** : `BroadcastChannel` + `IndexedDB` supportés. Helper `kioskOfflineQueue.js` (653 lignes) a une vraie API : `saveOrder`, `syncQueue`, `startAutoSync`, `getPendingCount`, `getStaleEntries`, `markStaleItems`. Codes solides.
- **Test runtime** : `context.setOffline(true)` → **AUCUN indicateur UI ne s'affiche** : pas de banner, pas de class `body.offline`, pas d'écran erreur réseau visible. Le client reste sur la même surface comme si rien ne s'était passé.
- **Critique** : la queue **technique** est solide, mais **l'UX offline est silencieuse**. Un client qui tape sa commande pendant une coupure réseau ne saura pas que sa commande va être différée. Quand il atteint le paiement, le TPE peut échouer sans contexte. **Recommandation : `KioskErrorNetworkComponent` ou banner global doit s'afficher dès `navigator.onLine === false`**.

### Step 11 — Pusher Echo
- **Observation runtime** : `window.Echo` présent, `state = "connecting"` (broker laravel-websockets non démarré dans le harness E2E local — limitation harness). Aucun banner UI visible.
- **Critique INSPECTION CODE (renforce le P1)** : `KioskAppComponent.vue:11` monte `<ConnectionStatusBanner suppress-transient suppress-session-invalid />`. Le composant `ConnectionStatusBanner.vue:74` retourne `false` quand `suppressTransient=true` :
  ```js
  visible() {
    if (this.isSessionInvalid) return !this.suppressSessionInvalid;  // suppressed
    if (this.suppressTransient) return false;                          // suppressed
    return this.showTransientBanner && !this.dismissed;
  }
  ```
  Les deux flags `suppress-transient` ET `suppress-session-invalid` sont activés **sur le kiosk client** → le banner **ne s'affichera JAMAIS**, ni pendant une déconnexion temporaire, ni même quand la session devient invalide. **Choix volontaire qui cache 100% des problèmes connexion au client final**.
- **Implication** : si le broker tombe pendant que le client compose son panier, il ne le saura jamais. Si sa session expire, idem. La probabilité d'incohérences (menu 86 non synchronisé, prix obsolète) augmente sans aucun signal UX.
- **Reco** : revoir la doctrine — au minimum un banner discret pour `isOffline=true` après 30s de déconnexion (équivalent au seuil de `ConnectionStatusBanner.vue:69`).

### Step 12 — Branch isolation (BK1)
- **Observation** : `?branch_id=2` ignoré côté serveur (le menu retourne sans `branch_id` field exposé, donc impossible à comparer). Le seeder `KioskMachineTableSeeder` ne crée la borne `gulshan1` (branch 2) que si `DEMO=true`.
- **Limitation honnête** : non-validé sans seconde borne réelle. La probe n'a pas pu confirmer ni infirmer. Demande une cycle dédié avec `DEMO=true` env.

### Step 13 — Lockdown
- **Observation** : navigation `/admin/dashboard` depuis kiosk redirige vers `/login` (cookie session pas auth admin).
- **Limitation honnête** : black-screen guard / Chromium `--kiosk` flag = **OS-level**, non testable depuis Playwright. L'URL reste accessible au clavier physique si le kiosk physique en a un branché.

### Step 14 — Confirmation
- **Observation** : navigation directe vers `/kiosk/confirmation?number=A042&total=22.00` redirige vers `/kiosk/idle` (le guard `requireConfirmationContext` veut un `orderRef` qui résiste au stale Vuex state). Body text final = écran idle.
- **Critique mineure** : guard fait son job, mais le testid `kiosk-confirmation-queue` n'existe pas. Soit le composant utilise un autre testid, soit la confirmation n'est pas couverte par sentinels propres.

---

## 2. Top 5 failles (priorité décroissante)

| # | Slug | Sévérité | Description courte | File:Line |
|---|---|---|---|---|
| 1 | **WK1-NO-ROLE-DIALOG** | **P0** | Wizard kiosk root sans `role="dialog"` runtime | `KioskWizardComponent.vue:2` |
| 2 | **WK2-NO-ARIA-MODAL** | **P0** | Wizard kiosk root sans `aria-modal="true"` | `KioskWizardComponent.vue:2` |
| 3 | **WK4-NO-FOCUS-TRAP** | **P0** | Focus échappe vers sidebar arrière-plan après 25 Tabs | `KioskWizardComponent.vue:2030-2068` |
| 4 | **DSK1-FRAUNCES-NOT-LOADED** | **P1** | Police Fraunces du DS V1 Bold non réellement chargée — fallback Georgia | `typography-bold.css:31` + `<link>` manquant probable |
| 5 | **OK1-NO-OFFLINE-INDICATOR** | **P1** | `setOffline(true)` ne déclenche aucun feedback UI | `KioskAppComponent.vue` ou banner global |

Failles bonus :
- **WK3-NO-ARIA-LABEL** P1 (wizard sans label sr-readable)
- **PUSHER-BANNER-SUPPRESSED** P1 (kiosk passe `suppress-transient + suppress-session-invalid` → banner connexion perdue désactivé in code, pas un bug runtime mais doctrine UX dangereuse — voir step 11)
- **CART-NO-ARIA-LIVE** P2 (total ne s'annonce pas)
- **AK1-NO-ALLERGEN-DATA** P2 (ambiguïté UX FIC pour items sans allergens)
- **CSP-meta-ignored** P2 (CSP report-only via `<meta>` ignorée)
- **DSK1-IDLE-INTER** INFO (rétrogradé : Inter sur body est by design ; le P1 reste sur step 6)

---

## 3. Top 10 questions au blue team

1. **WK1/WK2/WK3/WK4** — pourquoi le fix R1 a été appliqué au wizard POS (`9ce2f2e6f`) mais pas au wizard kiosk ? Les deux composants ont le même contrat UX (modal sur catalog) ; pourquoi l'asymétrie ?
2. **Fraunces** — où est le `<link rel="stylesheet" href="...Fraunces...">` ? Sur quel layout ? Pourquoi `document.fonts.check('1em Fraunces') = false` même après chargement complet de la page ?
3. **CSP en `<meta>`** — pourquoi la CSP est délivrée via `<meta>` (ignorée par browsers) et non via header HTTP ? Combien d'autres surfaces ont la même erreur ?
4. **Allergens API** — pourquoi `frontendItem/details` retourne `allergens=[]` pour un Tacos qui contient au minimum gluten + lait + œufs ? L'`AllergensSeeder.php` est exécuté ; le pivot `item_allergens` est-il rempli ?
5. **Offline UX** — `kioskOfflineQueue.js` queue les commandes mais aucun composant UI ne le signale au client. Pourquoi `KioskErrorNetworkComponent` n'est pas auto-route quand `navigator.onLine === false` ?
6. **Pusher banner** — il existe une route `kiosk.error.network` ; existe-t-il un composant équivalent pour "broker WebSocket déconnecté" ? Sinon, quelle est la stratégie de fallback realtime ?
7. **Cart aria-live** — pourquoi `kiosk-cart-summary` a `role="region"` mais pas `aria-live="polite"` sur le total ? Le screen-reader perd les changements après loyalty/promo apply.
8. **Branch isolation seconde borne** — quelle est la procédure pour valider la branch isolation côté kiosk en CI ? Le DEMO seed est-il exécuté quelque part automatiquement ?
9. **Lockdown OS-level** — quelle est la doctrine de mise en kiosk (Chromium `--kiosk` flag, on-screen keyboard désactivé, lockdown agent ?). Est-ce documenté en `docs/KIOSK_HARDWARE_OPS.md` ou similaire ?
10. **Cycle de revue final** — comment 70+ sentinels existants ont-ils raté ces 3 P0 a11y wizard ? Les sentinels ne probent-ils pas le DOM runtime du wizard ouvert ? Ou ne testent-ils que le wizard POS ?

---

## 4. Verdict adversaire final

**NOT PROD-READY — heal mandatory avant release V1.**

Justification :
- **3 findings P0 a11y wizard kiosk** = violation directe EAA 2025 entrée en vigueur. Une borne self-service publique non-conforme expose le restaurant à amende administrative + plainte association handicap.
- **DSK1 P1 Fraunces** = le DS V1 Bold est un mensonge marketing tant que la police n'est pas chargée. Le verdict "DS posé" doit être suspendu.
- **OK1 P1 offline silent** = scénario réaliste (coupure 4G/WiFi en restaurant) où le client tape commande sans feedback. UX inacceptable pour kiosk public.

**Aucune des trois P0 ne peut être healed par sentinel cosmétique** : il faut éditer `KioskWizardComponent.vue` (ajouter role/aria-modal/aria-labelledby/focus-trap au root, identique au wizard POS R1). Estimé : 1 cycle de heal (≤30 LOC), sentinels Playwright dédiés, re-run R3 pour confirmer.

**Recommandation** : ouvrir un cycle `CV1-KIOSK-WIZARD-A11Y-001` symétrique au fix R1 POS, puis ré-itérer cette spec. Le verdict global "FoodKing V1 PRODUCTION-READY" ne tient pas tant que la borne client n'a pas le même niveau a11y que la borne caisse.

---

## 5. Limitations honnêtes (ce que la sonde n'a pas pu valider)

1. **Black-screen guard / Chromium kiosk flag** — OS-level, hors scope Playwright (BK1-OS-LEVEL-GUARD). Doit être validé physiquement sur la borne.
2. **TPE direct hardware** — la spec confirme uniquement la présence des variants UI. Le comportement réel du TPE (pinpad input, success/refusal codes) requiert un appareil branché.
3. **Pusher broker disruption** — le harness E2E n'a pas démarré laravel-websockets, donc state observé = `connecting` permanent. La probe runtime n'a pas pu vérifier le COMPORTEMENT lors d'une transition `connected→disconnected`. **Compensation** : inspection code (`KioskAppComponent.vue:11` + `ConnectionStatusBanner.vue:74`) confirme que `suppress-transient + suppress-session-invalid` désactivent intégralement le banner sur kiosk → le P1 tient même sans validation runtime.
4. **Branch isolation deuxième borne** — non-validé sans `DEMO=true` (BK1-NON-VALIDÉ). Doit être ré-exécuté avec seed étendu.
5. **Allergens API contract** — l'item testé ("Tacos M") retourne `allergens=[]` ; impossible de savoir si c'est correct ou bug seed sans inspecter le pivot DB. Probe step 5 marque ça en P2 ambiguïté UX.
6. **Auto-return inactivity full-cycle** — la valeur idleMs=180s a été lue depuis Vuex mais non testée bout-en-bout (3 min de wait dépasse le budget temps des 15 tests). Prouvée OK par config seulement.

---

## 6. Annexes

### Fichiers durables
- Spec : `tests/e2e/red-team-r2-kiosk-prise-commande-2026-05-07.spec.js` (1390 lignes)
- Findings : `tests/e2e/screenshots/red-team-r2-kiosk-2026-05-07/findings.json` (22 entries)
- DOM probes : `tests/e2e/screenshots/red-team-r2-kiosk-2026-05-07/dom-snapshots.json`
- Synthese : `tests/e2e/screenshots/red-team-r2-kiosk-2026-05-07/synthesis.json`
- 15 screenshots PNG : `tests/e2e/screenshots/red-team-r2-kiosk-2026-05-07/step-*.png`

### Compteurs finaux
```
P0 = 3   (WK1, WK2, WK4 — wizard a11y)
P1 = 4   (WK3, DSK1, OK1, PUSHER)
P2 = 5   (CSP-meta, CART-no-aria-live, AK1-no-data, CONF-no-queue, DSK1-idle)
INFO = 5
OK = 5
TOTAL = 22
```

### Comparaison R1 (POS) vs R2 (kiosk)
| Faille | POS R1 | Kiosk R2 |
|---|---|---|
| Root role="dialog" | FIXÉ commit `9ce2f2e6f` | **MANQUE** P0 |
| aria-modal="true" | FIXÉ | **MANQUE** P0 |
| aria-labelledby | FIXÉ | **MANQUE** P1 |
| Focus-trap root | FIXÉ | **MANQUE** P0 |
| Allergens visible | OK | OK structurel mais data vide |
| DS Bold | (POS = V5 different DS) | **Fraunces non chargé P1** |

La blue team a fixé la moitié du problème. La symétrie kiosk reste à faire.

---

*Auditeur adversaire R2 — 2026-05-07*
