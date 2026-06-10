# W1 — DIMENSION A11Y — Audit axe-core des 9 pages CAISSE
**Date** : 2026-06-11 · **App** : http://127.0.0.1:8768 (release-v1 worktree) · **Outil** : axe-core 4.x local (`node_modules/axe-core/axe.min.js`), Playwright Chromium, 1440×900, fr-FR · **Tags** : wcag2a, wcag2aa, wcag21aa, wcag22aa · **READ-ONLY** (aucun encaissement confirmé — modal ouvert puis Escape).

**Méthodo / fiabilité** : la session admin sur :8768 est fortement contendue (Sanctum révoque les anciens tokens à chaque relogin d'un autre agent) → 5 scans du 1er passage ont en réalité capturé `/login` ou `/admin/dashboard`. **Tous re-scannés avec assertion d'URL avant ET après `axe.run`** — les 12 états ci-dessous sont vérifiés on-target. Données brutes : `/tmp/w1-a11y-caisse-{results,rescan,pos-states,frozen-scoped}.json`. Scripts : `tests/e2e/_w1-a11y-caisse-*.mjs`.

## Totaux par impact (12 états scannés)

| Impact | Node-hits | Règles |
|---|---|---|
| **critical** | **13** | aria-required-children (×12 états — 1 seule root cause globale), aria-allowed-attr (×1) |
| **serious** | **115** | color-contrast (×113), target-size (×2, même node sur 2 états) |
| moderate / minor | 0 | — |

## Tableau violations (règle × page)

| Règle | Impact | Pages | Nodes | Exemples sélecteurs |
|---|---|---|---|---|
| color-contrast | serious | /admin/encaissement | **49** | `.enc-count-chip` (blanc/#F4501E 3.49), `.enc-collect-btn` « Encaisser » (blanc/#F4501E 3.49), `.db-btn > span` « Actualiser » |
| color-contrast | serious | /admin/pos (4 états : base 10 / wizard 15 / commande 12 / modal 13) | 10–15 | `button[data-testid="pos-shortcut-encaisser-*"]` « 💳 Encaisser » (blanc/#F4501E 3.49, 12px bold), `p[data-testid="pos-shortcuts-ready-refresh"]` (#9a9a9a/blanc 2.81, 11px) |
| color-contrast [FROZEN-GATE] | serious | /admin/pos — popup wizard (scopé `#item-variation-modal`, **pos-wizard.js/css**) | **10** | `.wizard-item-price` « €1.50 » (#f5a6a3 **1.87**), `.ticket-label` « Aperçu ticket » (#dcb58d **1.86**), `.wizard-item-info > h2` (2.76), `.wizard-comment-field` placeholder (2.86) |
| color-contrast [FROZEN-GATE] | serious | /admin/pos — modal `#orderpayment` (scopé, **PaymentComponent.vue**) | **1** | `button[data-tab="#cash"] > .pos-v5-payment-method-label` « Espèces » (#f4501e/blanc 3.49) |
| color-contrast | serious | /admin/pos-orders/show/4511 | 4 | `.flex-1.text-start` « Non payé » / « Accepter » (#f4501e/blanc 3.49 bold), « Imprimer la facture » (blanc/#f4501e) |
| color-contrast | serious | /admin/cash-sessions-report | 6 | « Rechercher » (blanc/#f4501e), `h4.text-primary` dates jour (#f4501e/#f9fafb 3.34) |
| color-contrast | serious | /admin/pos/floorplan | 2 | `.pos-v5-floorplan__eyebrow` (#f4501e/#fffbf5 3.38, 11px), `.pos-v5-floorplan-btn--primary` « Rechercher » |
| color-contrast | serious | /admin/pos-orders-tracker | 1 | `.is-active > span` « Toutes » (blanc/#f4501e, 12px) |
| color-contrast | serious | /admin/cash-overview | 1 | `.bg-primary > span` « Rechercher » |
| aria-required-children | **critical** | **TOUTES les pages** (12/12 états) | 1/page | `#profile-menu-*[role="menu"]` — enfants interdits `figure[tabindex], h3[tabindex], input[aria-label]` — composant global `BackendNavbarComponent.vue` |
| aria-allowed-attr | **critical** | /admin/historique | 1 | `#vs60-combobox` (vue-select) — `aria-expanded` + `aria-activedescendant` non autorisés sur ce rôle |
| target-size (WCAG 2.2) | serious | /admin/pos (base + commande) | 1 | `input[placeholder="Client passage"]` (vue-select client) — 283.6×**19.5px** < 24px |

Pages sans violation propre au-delà du menu profil global : `/admin/pos-orders` (1 critical global seulement), `/admin/historique` (2 critical, 0 contrast).

## Findings classés

### P1 — critical/serious sur flux principal

**P1-1 · color-contrast systémique blanc↔#F4501E (3.49 < 4.5) — ~95 des 113 nodes, 8/9 pages.**
La palette Cayenne primary `#F4501E` avec texte blanc (ou texte #F4501E sur blanc) donne 3.49:1 — insuffisant pour du texte < 18.66px bold. Touche les CTA du flux d'encaissement lui-même : « Encaisser » ×47 tickets sur `/admin/encaissement` (`EncaissementComponent.vue`), raccourcis « 💳 Encaisser » du POS (`PosComponent.vue` zone pos-shortcuts), « Accepter »/« Imprimer la facture » sur le détail commande, « Rechercher » (cash-overview, cash-sessions, floorplan).
**Reco scope-minimal** : NE PAS changer la marque — introduire un token texte `--primary-text-safe: #C2410C` (≈4.6:1 sur blanc) pour les labels #F4501E-sur-blanc, et pour les boutons blanc-sur-primary soit passer le label à ≥18.7px/bold ≥14px+bold→ toujours 3:1 requis seulement si « large text » (≥14pt bold = 18.66px… 12–14px actuels ne qualifient PAS) soit foncer le fond des CTA texte-petit vers `#C2410C`. Un seul endroit : variables CSS du thème admin (pas de refonte composant par composant).

**P1-2 · aria-required-children sur le menu profil global — critical, 100% des pages caisse.**
`BackendNavbarComponent.vue` (`resources/js/components/layouts/backend/BackendNavbarComponent.vue`) rend `role="menu"` avec enfants `figure`/`h3`/`input` → arbre ARIA invalide, lecteurs d'écran annoncent un menu vide/cassé sur chaque écran.
**Reco scope-minimal** : retirer `role="menu"`/`aria-labelledby` du dropdown (le laisser en simple région disclosure avec `aria-expanded` sur le trigger), OU envelopper chaque entrée actionnable dans `role="menuitem"` et sortir le bloc identité (figure/h3) du conteneur `role="menu"`. 1 fichier, ~5 lignes.

**P1-3 [FROZEN-GATE] · contrastes très faibles dans le popup wizard caisse (pos-wizard.js / pos-wizard.css — frozen §7).**
Scan scopé : 10 nodes, dont prix `€1.50` à **1.87:1** (`.wizard-item-price`, #f5a6a3) et « Aperçu ticket » à **1.86:1** (`.ticket-label`, #dcb58d) — quasi illisibles ; titre item 2.76, textarea 2.86. Bouton « Ajouter au panier » blanc/teal `#43C6AC` (~2.1:1, `pos-wizard.css:745`).
**Reco** : fix CSS-only dans `public/css/pos-wizard.css` (foncer #f5a6a3→#dc2626-like, #dcb58d→#b45309, #999*→#6b7280, teal→#0d9488) — zéro changement JS/markup, mais fichier **frozen** → LOCK + gate owner requis avant tout patch.

**P1-4 [FROZEN-GATE] · PaymentComponent.vue (modal `#orderpayment`, frozen §7) — 1 node.**
Label d'onglet « Espèces » `.pos-v5-payment-method-label` #f4501e/blanc 3.49 (état non-sélectionné). Scan scopé : c'est l'**unique** violation interne au modal d'encaissement — le reste du modal est propre.
**Reco** : couvert par le token P1-1 si la variable CSS est globale (le composant n'a pas besoin d'être édité si le token est redéfini en amont) ; sinon gate owner.

### P2 — serious secondaire / critical hors flux principal

**P2-1 · target-size (WCAG 2.2) — champ « Client passage »** (vue-select customer, `PosComponent.vue` ~l.535) : 19.5px de haut < 24px. Reco : `min-height: 24px` sur l'input du `.db-field-control` POS.
**P2-2 · aria-allowed-attr critical sur vue-select** (`/admin/historique`, `#vs60-combobox`) : attributs `aria-expanded`/`aria-activedescendant` posés par la lib `vue-next-select` sur un rôle qui ne les autorise pas. Vendor — reco : upgrade lib ou patch wrapper ; documenter si accepté (présent partout où vue-select est utilisé, axe ne le remonte que là où le DOM l'expose).
**P2-3 · textes gris d'état** : `pos-shortcuts-ready-refresh` « Mis à jour à l'instant » #9a9a9a 2.81 (11px) ; reco #6b7280.

### P3 — minor
Aucune violation minor/moderate détectée sur ces tags WCAG. (Le 1er passage avait listé des items dashboard — « PDF Clôture du jour », `code.audit_logs` 4.39 — scan off-target, exclu du périmètre caisse mais même root cause P1-1.)

## Synthèse demandée
- **Total violations (node-hits, 12 états)** : **critical 13 · serious 115 · moderate 0 · minor 0**. En dédupliqué root-cause : **2 critical** (menu profil global, vue-select) + **~80 serious uniques** (dont 78 color-contrast, 1 target-size).
- **Top 3 règles** : 1) **color-contrast** (serious, 113 hits, 8/9 pages — root cause palette #F4501E + couleurs wizard frozen) ; 2) **aria-required-children** (critical, toutes pages — 1 composant navbar) ; 3) **target-size** (serious, POS) ex æquo **aria-allowed-attr** (critical, vendor vue-select).
- **FROZEN-GATE** : 2 findings (P1-3 pos-wizard.css 10 nodes ; P1-4 PaymentComponent 1 node) — patches CSS-only possibles mais gate owner §7 obligatoire.
