# PLAN_KDS_IMPROVEMENT_2026-05-11.md

> Audit + plan d'amélioration du Kitchen Display System (KDS)
> Date : 2026-05-11
> Branche : `feature/mobile-app-le-cayenne-2026-05-10`
> Owner-gate : **NON validé** (lecture obligatoire avant exécution)
> Baseline : `tests/e2e/__screenshots__/kds/kds-kds-grid-iter-5-3840x2160.png`

---

## 1. Méthodologie

3 sub-agents YC GStack lancés en parallèle read-only (CLAUDE.md §4) :
- **Architecte Vue** — structure + anti-patterns + refacto candidate
- **UX/Design cuisine** — workflow chef + palette + lecture distance + benchmark Toast/Square/Otter
- **A11y + i18n** — WCAG 2.1 AA + RTL + clés manquantes + raw labels

Pas de modification de fichier pendant audit. Cross-validation des findings :
3 P0 confirmés indépendamment par 2+ agents.

---

## 2. Verdict consolidé

| Axe | Score | Verdict |
|---|---|---|
| **UX cuisine** | 3.2/10 | Catalogue admin, pas un display kitchen. Échoue en rush. |
| **Architecture Vue** | Acceptable | God-component 2353 lignes + 4× duplication, refacto recommandée non bloquante |
| **A11y WCAG 2.1 AA** | Partiel | Contrast fail texte secondaire + reduced-motion absent + aria-expanded statique |
| **i18n 3 langues** | Partiel | 47 clés FR/EN/AR OK ✓, mais 18 raw labels FR mêlés au template + JS |
| **Sync (KdsSyncService)** | 🟢 Solide | Ne pas toucher. Backoff + version-gate + cleanup OK. |

**Problèmes les plus impactants (cross-validés)** :

1. **[CRITIQUE UX]** Items + quantités cachés derrière accordion fermé par défaut.
   Carte fermée = `#serial illisible` + `Token No` + `09:53 AM` + chevron. Aucune
   nourriture visible. Un chef doit cliquer 16 chevrons pour voir 16 commandes.
   *Industrie standard (Toast/Square/Otter) : items toujours visibles*.

2. **[CRITIQUE UX]** 5 banners empilés en haut consomment ~10% écran
   (ConnectionStatusBanner + ws-reconnect + kds-error + admin-polling-hint
   + cap-warning + bump-local-only-notice). Bruit permanent.

3. **[CRITIQUE UX]** Bouton bump = 32×32 px (sous WCAG 44 px + sous standard
   kitchen 60 px). Et bump nécessite 2 actions (open accordion → click flèche).

4. **[CRITIQUE UX]** Couleur d'âge appliquée seulement à `border-color` 1px
   opacité 55-85% → invisible à 2m. `kds-wait-red.animate-pulse` neutralisé
   explicitement (`animation: none`). L'urgence ne se voit plus.

5. **[CRITIQUE UX]** Layout 4 colonnes (Dine-in / Online / Takeaway / Borne).
   Dine-in et Online toujours vides en V1 (feature flag `pos.dine_in_enabled=false`).
   50% de l'écran 4K affiche "Aucune commande" en permanence.

6. **[BUG REAL]** Ligne 1290 : `allergenModal` ≠ `allergensModal` →
   fermeture modal allergènes silencieusement cassée.

7. **[A11y FAIL]** Texte gris `#6E7191` sur blanc = 3.2:1 (~12 emplacements)
   et bouton vert `#1AB759` "Mark Done" = 4.1:1 — fail WCAG AA 4.5:1.

8. **[A11y FAIL]** Pas de `@media (prefers-reduced-motion: reduce)` guard sur
   Swiper + accordéons → fail WCAG 2.3.3.

9. **[i18n FAIL]** 13 raw labels FR dans template (search placeholder,
   empty states, "Print ticket" ×4, payment pending ×2, queue number ×2)
   + 5 raw labels FR dans `printKitchenTicket()` JS ("Sur place", "Livraison",
   "À emporter", "Caisse", "Borne") → tickets cuisine en français même en EN/AR.

10. **[ARCHI]** 4 colonnes dinein/online/takeaway/kiosk = ~550 lignes
    template dupliquées 4× quasi-identiques. Maintenance 4× plus coûteuse.

11. **[PERF]** Watcher `orders` O(n) sans throttle → peut fire 3-5×/s en peak
    (polling 5s + Echo + manual refresh).

---

## 3. Roadmap priorisée

Architecture en 3 sprints, chacun livrant une amélioration tangible et
testable indépendamment. Owner-gate entre chaque sprint.

### Sprint 1 — Quick Wins (4-5h, risque ~zéro)

Aucun changement de palette. Aucune refonte. Juste corrections
chirurgicales qui débloquent l'usage cuisine.

| ID | Action | Fichier | Effort |
|---|---|---|---|
| QW-1 | **Bug fix** : `allergenModal` → `allergensModal` (ligne 1290) | KitchenDisplaySystemComponent.vue | 5 min |
| QW-2 | **Accordéon items ouvert par défaut** (supprimer `style="height: 0px"` ~lignes 320, 480, 622, 762) — items + quantités visibles direct | idem | 30 min |
| QW-3 | **Bump button 32→60 px** (`w-8 h-8` → `w-14 h-14` + icone 28px) sur les 4 colonnes | idem | 30 min |
| QW-4 | **Wait class : fond carte au lieu de border** : `kds-wait-orange/red` → `bg-[#FFEDD5]` / `bg-[#FEE2E2]` + animation pulse réactivée | `<style scoped>` lignes 2168-2178 | 30 min |
| QW-5 | **Cacher 2 colonnes vides V1** (`Dine-in`, `Online`) via feature flag → grid auto `1col / 2col / 2col` selon nb sources actives | ligne 228 + computed `activeOrderSources` | 1h |
| QW-6 | **Banners stack → 1 seul `KdsStatusBanner`** affichant le plus prioritaire (priorité : error > cap-full > cap-warning > admin-polling > bump-notice > sync-mode) | nouveau composant + lignes 9-84 | 1h |
| QW-7 | **aria-expanded dynamique** sur les 4 toggles + suppression ligne 71-84 banner `bump_local_only_notice` (dette démo) | idem | 15 min |
| QW-8 | **Foncer texte gris secondaire** : `#6E7191` → `#4B5563` (déjà utilisé ailleurs) — 12 emplacements | idem + style scoped | 15 min |
| QW-9 | **prefers-reduced-motion guard** sur Swiper/accordéons/pulse | `<style scoped>` | 15 min |
| QW-10 | **i18n raw labels** : extraire les 18 raw labels FR (template + JS printKitchenTicket) → keys FR/EN/AR | i18n files + composant | 1.5h |

**Livrable Sprint 1** : KDS *utilisable en service réel*, A11y partiellement
remontée (contrast fix + reduced-motion), i18n complétée, bug allergens fix.
Aucun changement esthétique ni breaking change.

**Tests** :
- E2E specs existants doivent rester verts (`04-kds-status`, `audit-kds-cycle1..4`,
  `red-team-r4`, `test-e2e-pos-kds-sync-D/E/F`, `audit-kiosk-multiproduct-kds-journey`,
  `audit-pos-multiproduct-kds-journey`)
- Vitest unit specs verts (`kdsAllergens`, `kdsDisplay`, `kdsLineSemantics`,
  `kdsSyncCadence`, `kdsStationFilter`, `kdsBumpRecall`, `kdsVersionGate`,
  `kdsTimerEscalation`, `kdsBackoffOn5xx`, `kdsReactsToReconnectStorm`)
- Visual capture Playwright `/admin/kitchen-display-system` post-Sprint 1
  comparée baseline iter-5

---

### Sprint 2 — Refresh palette + card v2 (1 jour, risque modéré)

Migration vers palette mobile-app + redesign cohérent des cartes.
**Owner-gate obligatoire AVANT** : valider les hex de palette.

| ID | Action | Effort |
|---|---|---|
| RM-1 | **Migrer palette admin → palette mobile-app** : `#4C1A96` → `#0F0F10` (noir), `#F4501E` → `#E11D2A` (rouge), ajouter `#F5C518` (jaune). Centraliser dans CSS variables `--kds-*` | 2h |
| RM-2 | **Card v2** : header 64px avec `N°queue` 48px + source pill icone, items en 22px regular + qty 28px bold, CTA hoisté h-14 full-width | 3h |
| RM-3 | **Status pill clarification** : `Confirmer` (verbe) → `À démarrer` (état) ; `Done` → `Prêt` ; harmoniser i18n FR/EN/AR | 1h |
| RM-4 | **Header `/kds` minimaliste** : retirer logo FoodKing + Dashboard btn + avatar en mode plein-écran ; conserver branch_name + clock + son toggle | 1h |
| RM-5 | **Allergens v2** : badge 24px jaune sur noir + auto-popup à création commande avec allergène | 1h |

**Livrable Sprint 2** : KDS *aligné palette mobile-app* + clarté workflow chef.

---

### Sprint 3 — Refonte majeure (3-5 jours, risque haut)

Transformation cuisine pro. Owner-gate obligatoire AVANT.

| ID | Action | Effort |
|---|---|---|
| RF-1 | **Mode sombre cuisine** (toggle ou auto par heure) : moins éblouissant en cuisine chaude/hotte, fond `#0F0F10` | 1j |
| RF-2 | **Colonne "Priorité" auto-feed** des cartes ≥8min toutes sources, pulse rouge | 0.5j |
| RF-3 | **Tap-to-bump pleine ligne item** + recall toast (3s undo window) | 1j |
| RF-4 | **Vue "Production list"** : items aggregés cross-cartes (ex `5 × Tacos XXL — 2 N°23 + 3 N°24`) — boost throughput grosse cuisine | 1j |
| RF-5 | **Bottom-bar contrôles** (filter station, sound) au lieu de filter bar du haut | 0.5j |
| RF-6 | **Refacto architecture** : extraction `KdsHeaderBanners` / `KdsFilterBar` / `KdsItemsBoard` / `KdsOrderLane` / `KdsOrderCard` (header/metadata/items/actions/badges) / `KdsAllergensModal` / `KdsAriaLiveRegion` / `KdsSyncFooter`. Réduit god-component 2353 → ~600 lignes orchestrateur | 3j |

**Livrable Sprint 3** : KDS *grade pro fast-food* + maintenabilité long-terme.

---

## 4. Risques

- **R1 — Specs E2E sélecteurs** : `data-testid="kds-card-cta"` + `data-kds-order-card="..."`
  doivent être préservés ou batch-update. Inventaire avant Sprint 2.
- **R2 — Frozen zone proximité** : `OrderStateMachine.php` frozen, mais le KDS
  ne touche que l'UI/store frontend → pas de risque si on ne modifie pas
  les contrats API.
- **R3 — Palette validation owner** : avant RM-1, l'owner doit confirmer les
  hex `#0F0F10 / #E11D2A / #F5C518 / #FFFFFF`. Sinon dériver de l'app mobile
  Le Cayenne directement.
- **R4 — Mode sombre RF-1** : tester sur le hardware réel cuisine avant
  rollout (TFT bas de gamme parfois mauvais rendu noir).
- **R5 — Workflow chef RF-3** : tap-to-bump pleine ligne change le geste
  appris. Prévoir mode "legacy bump" toggleable les 2 premières semaines.

---

## 5. Anti-drift

- **Aucun fichier KDS dans frozen-zone** (CLAUDE.md §7) ✓ modifications autorisées
- **`KdsSyncService.js` solide** → ne pas toucher
- **NF525 invariants** non impactés (le KDS n'écrit pas les prix ni la chain HMAC)
- **Branch isolation** non impactée (KDS hérite déjà de `BranchScope`)
- **i18n 3 langues** : toute clé ajoutée doit exister en FR/EN/AR (pas de fallback silencieux)
- **Tests obligatoires** entre chaque QW/RM/RF (pas de batch sans test)

---

## 6. Décisions à prendre par l'owner

1. **Quel sprint démarrer ?** Sprint 1 (quick wins, 4-5h, risque ~0) /
   Sprint 1+2 (refresh complet, 1.5j) / Sprint 1+2+3 (refonte totale, ~7j) /
   Custom subset.
2. **Palette validée ?** `#0F0F10 noir / #E11D2A rouge / #F5C518 jaune /
   #FFFFFF blanc` (à dériver de la mobile-app Le Cayenne).
3. **Mode sombre par défaut ?** Si Sprint 3 → mode sombre prod ou opt-in toggle.
4. **Cacher complètement Dine-in/Online ?** Ou les garder en stub avec
   message "désactivé V1" ?
5. **Branche** : continuer sur `feature/mobile-app-le-cayenne-2026-05-10` ou
   ouvrir une branche dédiée `feature/kds-design-refresh-2026-05-11` ?

---

## 7. Status

- [ ] Owner-gate sur scope (questions section 6)
- [ ] Sprint 1 démarre une fois owner-gate cleared
- [ ] Tests + visual capture après chaque QW
- [ ] Update PROJECT_BRAIN.md §3 LAST DONE en fin de chaque sprint
- [ ] Push épisode Graphiti foodking group à clôture sprint significative
