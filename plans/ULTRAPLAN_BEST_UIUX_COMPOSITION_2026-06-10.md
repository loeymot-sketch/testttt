# ULTRAPLAN SUPERVISEUR — Meilleur UI/UX + technique Composition & Modification (2026-06-10)

> PLAN-ONLY (aucune exécution sans GO owner — discipline §5/§10). Ancré file:line (workflow 14 agents `wf_69d1f787-e53`, références modernes 2024-2026). Branche release/v1-2026-06-10. Frozen §7 + NF525 respectés ; toute amélioration d'un wizard frozen passe par la DONNÉE (projection) ou un owner-gate, jamais par édition directe.

## NORTH STAR
Élever FoodKing au niveau des meilleures plateformes QSR 2024-2026 (Toast 2.0, Olo Rails, Square for Restaurants, McDonald's GDM / Kiosk v2, BK Reclaim) sur 2 axes : (1) **le système de COMPOSITION & MODIFICATION** (builder composer → projection → wizards borne/caisse) — robustesse technique + parité ; (2) **l'UI/UX** de chaque surface + un **design-system unique de classe pro**. Le tout sans toucher les zones frozen ni l'invariant NF525 (jamais de prix sur une étape, prix 100% backend).

## VERDICT D'ENTRÉE (état actuel — grounded)
L'architecture composition est **solide et data-driven** (builder/projection/wizard séparés, versionnement+publish+diff, NF525-clean prouvé par sentinels, parité borne complète via KioskStepGenericChoices). MAIS 4 gaps techniques confirmés + des UI/UX en-deçà du meilleur sur chaque surface + un design-system fragmenté (cv1/kiosk/pos-v5 redéfinissent au lieu d'aliaser) + apps client sans vrai build prod.

---

## AXE 1 — COMPOSITION & MODIFICATION (le cœur, technique)

### Gaps confirmés (file:line)
- **GAP#1 Parité borne↔caisse CASSÉE** : `pos-wizard.js:515` produit `generic_choices` mais le dispatch `renderStepContent:1131-1152` n'a NI branche `generic_choices` NI `default` → un step composer custom rend VIDE à la caisse alors qu'il est sélectionnable à la borne (KioskWizardComponent.vue:806-810). Casse la promesse « ~10 wizards réels Le Cayenne » dès qu'un step sort du vocabulaire heuristique POS. (pos-wizard.js FROZEN)
- **GAP#2 Provenance résolue au mauvais endroit** : la projection (`ComposerProfileProjection.php:186-198`) matche `item_attribute` par **nom flou** (`source_ref` mb_strtolower), JAMAIS par le FK stable `source_item_attribute_id` (pourtant validé + dans le diff). → 2 attributs homonymes = match ambigu ; renommer un attribut casse le step silencieusement. Bombe à retardement quand le builder grandit. (non-frozen)
- **GAP#3 Publish non-idempotent** : route `routes/api.php:802-803` POST `/profiles/{profile}/publish` sans middleware `idempotency` (toutes les autres mutations l'ont). Double-POST → double snapshot version + double event sync borne+caisse. (non-frozen)
- **GAP#4 Dette de modélisation** : 3 source_type à 3 résolutions divergentes ; pas de `menu_component` natif (box/formule forcée via addon_role→KioskStepMenu frozen = RISK#1) ; pas de validation à l'édition que source_ref pointe une source réelle. (backlog V1.0.x)

### Tâches (priorisées)
| Tâche | Impact/Effort | Contrainte | Détail |
|---|---|---|---|
| **T-COMPO-3** résoudre `item_attribute` par FK d'abord, source_ref en repli | high/S | none | ComposerProfileProjection.php — corrige GAP#2, élimine l'ambiguïté homonyme + le rename-break. **Quick win haute valeur.** |
| **T-COMPO-4** idempotency sur route publish + dédup events | high/S | none | routes/api.php:802 + config — corrige GAP#3, aligne sur le reste. |
| **T-COMPO-1** projection POS pré-mappe les steps custom vers un type rendable | high/M | frozen-safe (DONNÉE, ne touche pas pos-wizard.js) | corrige GAP#1 sans gate : la projection POS garantit que renderStepContent ait toujours une branche. **Option V1 préférée.** |
| **T-COMPO-6** spec de RENDU POS composer (comble le trou du sentinel string-match) | medium/S | none | preuve que generic_choices rend réellement à la caisse. |
| **T-COMPO-5** validation backend à l'édition : source_ref/FK pointe une source réelle de l'item | medium/M | none | empêche les steps orphelins dès la création. |
| **T-COMPO-2** renderer `generic_choices` natif dans pos-wizard.js | medium/M | **OWNER-GATE + LOCK §7** | alternative à T-COMPO-1 (composant borne existe déjà, risque faible) — parité « vraie » plutôt que contournée. |
| **T-COMPO-7** source_type `modifier_group` unifié réutilisable (modèle Toast Modifier Groups partagés) | low/L | plan-only V1.0.x | dette de modélisation, refonte structurelle future. |

---

## AXE 2 — UI/UX PAR SURFACE

### Borne (kiosk) — steps NON-frozen actionnables
- **BU-01 (high/M, none)** densifier la grille des steps (canvas portrait ~60% vide vs McDonald's Kiosk v2) — fichiers `steps/` non-frozen.
- **BU-02 (high/M, none)** affordance de sélection (check/radio contraste, mono vs multi).
- **BU-05 (med/M, NF525-dérivé)** delta prix par étape DÉRIVÉ du backend.
- **BU-03/BU-04 (med, FROZEN owner-gate)** simplifier double-nav + indicateur d'étape = complétion réelle (KioskWizardComponent).
- **BU-07 (med/S, DONNÉE owner)** re-catégoriser les upsells pollueurs (CMS).

### Caisse (POS) — PosComponent NON-frozen
- **CX-1 (high/M, none)** carte panier entièrement cliquable pour l'édition (tap-anywhere, modèle Toast New POS).
- **CX-2 (high/M, none)** hotkeys workflow caisse (encaisser/park/annuler ligne/remise).
- **CX-5 (high/S, DONNÉE owner)** seed ItemExtras Grande/Cheddar → active la facturation CAISSE-01 (cohérence affichage/facturé).
- **CX-3 (med/S)** presets motifs de remise. **CX-6 (low/L)** code-split bundle POS.
- **CX-4/CX-7 (FROZEN owner-gate)** récap rendu-monnaie + parité visuelle borne↔caisse (dossier W6 différé).

### Admin/CMS gestion — non-frozen
- **CMS-UX-1 (high/M)** garde brouillon-non-sauvegardé + indicateur dirty dans le composer.
- **CMS-UX-2 (high/M, NF525 0 prix)** diff de publication valeur-par-valeur.
- **CMS-UX-3 (high/L)** remplacer l'iframe du drawer composer par un mount natif (latence + état partagé).
- **CMS-UX-8 (med/S)** garde serveur profondeur catégorie (interdire niveau 3).
- **CMS-UX-4/5/6 (med)** undo/redo structure wizard · mode tablette gérant · actions de masse catalogue.

### KDS/OSS — non-frozen
- **KDS-UX-01 (high/M)** exposer le filtre station (bar/chaud/froid) sur le board V2 (backend déjà complet, Square KDS 2024).
- **KDS-UX-03 (high/M)** drill-down allergène tap-to-expand sur la carte V2.
- **KDS-UX-02 (high/L, NF525 0 prix)** vue articles-agrégés all-day togglable.
- **KDS-UX-05/04/06 (med)** repère temporel client OSS · horloge dernier-refresh-OK mode dégradé · cue PRÊT renforcé.

---

## AXE 3 — DESIGN-SYSTEM TRANSVERSAL + APPS CLIENT
- **UX-5 (high/S, doc)** `docs/design/DESIGN_SYSTEM.md` — SSOT tokens + a11y/contrast policy.
- **UX-1 (high/M)** primitif IconButton/ModalCloseButton + migrer les ~32 close-buttons admin sans aria-label (11 drawers déjà healés cette session ; reste les modales).
- **UX-2 (high/M)** étendre AxeCoreCriticalGateSentinel aux modales admin + run axe live (gate a11y CI).
- **UX-6 (high/S, owner-gate léger)** token `--fk-brand-text` AA additif (résout l'orange #F4501E 3.49:1 SANS toucher la surface de marque).
- **UX-7 (high/XL, owner-gate)** couche racine `--fk-*` + alias additifs cv1/kiosk/pos-v5 (consolidation non-cassante, modèle Olo Rails).
- **UX-3 (med/S)** `prefers-reduced-motion` global admin. **UX-4 (med/M)** sentinel parité i18n bloquant fr/en/ar.
- **Apps client** : **CA-1/CA-2 (high/L)** vrai build prod Vite (web + mobile, sortie minifiée hashée vs React-dev+Babel-CDN actuel ~4MB) ; **CA-3/CA-4 (high/M)** self-host React + fonts (mandat LOCAL no-cloud) ; **CA-6 (high/L)** PWA offline ; **CA-8 (med)** gate CI Lighthouse+axe.

---

## SÉQUENÇAGE PROPOSÉ (réaliste V1 LOCAL)
- **Vague 0 — Quick wins haute valeur non-frozen** : T-COMPO-3, T-COMPO-4, T-COMPO-6, CX-1, BU-01/02, KDS-UX-01/03, CMS-UX-1/2, UX-1, UX-5. (impact fort, effort S/M, 0 gate)
- **Vague 1 — Robustesse composition** : T-COMPO-1 (parité POS par données), T-COMPO-5, CMS-UX-3, CX-2. + seeds DONNÉE (CX-5, BU-07) = owner.
- **Vague 2 — UI/UX profondeur** : BU-05, KDS-UX-02/05, CMS-UX-4/5/6, UX-2/3/6.
- **Vague 3 — Structurel (gates owner)** : T-COMPO-2 (renderer POS frozen+LOCK), UX-7 (design-system racine), CA-1..6 (build prod apps), W6 parité caisse, T-COMPO-7 (modélisation).

## GATES OWNER (à trancher)
1. Orange marque #F4501E vs AA → UX-6 token additif (recommandé) vs garder tel quel.
2. T-COMPO-1 (données, V1) vs T-COMPO-2 (renderer frozen + LOCK) pour la parité caisse.
3. Seeds catalogue (CX-5 frites, BU-07 upsells) = données owner.
4. UX-7 refonte tokens racine + W6 parité caisse + CA build apps = chantiers structurels.
5. Sort des langues orphelines bn/de (wire vs retrait) — UX-4.

## NON-NÉGOCIABLE (rappel)
Aucune tâche ne touche un fichier frozen sans owner-gate+LOCK ; aucun prix sur une étape de composition ; prix 100% backend ; palette respectée par surface ; SSOT 45 items. Détail complet des 52 tâches : sortie workflow `wf_69d1f787-e53`.
