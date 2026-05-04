# Catalog Studio — Audit retour Claude Design + plan d'orchestration restant

| Champ | Valeur |
|---|---|
| Date | 2026-05-03 |
| TASK_ID actif | `CV1-V2-REMAINING-MISSIONS-001` |
| Phase | EXECUTE post-gate SOURCE-FK option 2 (staging-first) |
| Auteur | Claude (orchestrateur) |
| Cible | Audit du retour Claude Design (`/Users/1millnonstop/Downloads/gestion`) + cartographie technique + plan d'itération |

---

## 0. Résumé exécutif (raisonné fort)

- ✅ Claude Design a livré du contenu **dense, fidèle au schéma BDD**, avec preview POS + Kiosk **différenciées** (pas un cadre téléphone générique).
- ✅ Tokens : additions seules, **zéro override CV1**, brand kiosk respecté.
- ✅ Mapping écran → fichier `.vue` documenté dans le README artboard.
- ⚠️ **18 angles morts** identifiés (image upload, drag&drop visuel, conflict, per-branch override, RTL, raccourcis clavier, etc.). Liste précise §3 + messages prêts à coller §4.
- ✅ Côté technique : staging migration `source_item_attribute_id` + dual-write + tests passent. Reste : soak staging + go/no-go prod après rapport soak.

---

## 1. Audit du retour Claude Design (forces / lacunes)

### 1.1 Forces
| Critère | Note | Évidence |
|---|---|---|
| Fidélité schéma `ItemWizardStep` | 10/10 | `studio-data.jsx` — `step_key`, `source_type`, `source_item_attribute_id`, `min_select`, `max_select`, `visible_on`, `stockable_choices`, `addon_role` — tous présents |
| Distinction POS / Kiosk | 9/10 | `PosPreview` mono-page rouge bordeaux + `KioskPreview` beige multi-pages avec stepper |
| Tokens (additions seules, no override) | 10/10 | `tokens.css` préfixe `--studio-*` exclusivement |
| Brand discipline | 9/10 | Rouge #E8001C réservé aux CTA primaires + canal Kiosk |
| Mapping handoff | 10/10 | README artboard liste les 6 écrans → fichiers Vue |
| A11y (focus 3px, prefers-reduced-motion) | 8/10 | Documenté README + appliqué en CSS |
| Données réalistes | 10/10 | Vrai produit "Tacos M (1 viande)" + 6 étapes préremplies |

### 1.2 Lacunes / angles morts (18)

**Critiques (bloquants pour intégration)**
1. **Drag & drop reorder** — ARIA grid mentionné mais **pas de spec visuelle** : ghost row, drop indicator, état drag active, état dragover.
2. **Branch overrides** — la TopBar montre "Toutes les filiales" mais **pas d'écran** "ce produit est 86 sur Filiale #2 uniquement" (matrice par filiale).
3. **Image upload** (QuickCreate + ProductCard) — drop-zone mentionné, **pas d'états** : drag-over, uploading %, error, success, ratio crop, fallback image.
4. **Diff algorithm spec** — modal diff statique, **pas de description** de comment générer add/rem/mod depuis `ComposerProfileProjection v(n)` vs draft (clés stables ? hash ? diff ligne).
5. **Conflict state** — `PublishBadge` mentionne "Conflict" mais **pas d'écran** quand v(n) serveur > v(n-1) local (409).

**Importantes (nuit fortement à l'UX si absents)**
6. **Source picker create-on-the-fly** — promis "Add new attribute…" mais **non visualisé** (modal inline ? side-sheet ?).
7. **Auto-save states** — pill "il y a 4s" présente, **pas de différenciation** : saving / saved / save-error / offline.
8. **Empty/loading/error** — un seul artboard agrégé, **pas un par écran** + pas d'état "no permission".
9. **Toast micro-system** — référencé tokens mais **pas de design** : positionnement, stacking, durée, undo timer (5s).
10. **RTL (Arabic)** — mentionné FR primaire/EN/AR/BN/DE mais **pas d'artboard RTL** pour l'admin.
11. **Stock resolve flow** — "Resolve" 1-clic mentionné mais **pas de confirmation** (immédiat ? toast undo ?).
12. **Permission-aware UI** — pas d'écran pour rôle sans `delete` ni `publish` (manager filiale vs central).

**Polish (différenciants premium)**
13. **Keyboard shortcuts layer** — Tab order documenté, **pas de raccourcis** (Cmd+S publish ? J/K navigate steps ? Cmd+/ help ?).
14. **Search results** — input toolbar présent, **pas de spec** : results highlight, no-results, recent searches, fuzzy.
15. **Responsive 1280px** — minimum déclaré, **pas d'artboard** (collapse rail ? cards plus petites ?).
16. **Dark mode** — différé "later" — OK pour V1.
17. **Tutoriel onboarding** — promesse "5 min onboarding" mais **pas de spec** : tooltips first-run ? checklist sticky ?
18. **Live preview real photos** — `ProductThumb` est SVG placeholder, **pas de spec** : ratio, fallback ladder, lazy.

---

## 2. Audit "arbre" technique — état actuel

```
RACINES (DB — SSOT)
├── items, item_categories                              [legacy 2022, stable]
├── item_attributes, item_extras, item_addons           [legacy 2022, stable]
├── item_branch_availability                            [V1 — 86 par filiale]
├── stock_levels, stock_movements (+ idempotency_key)   [stable]
├── item_wizard_profiles, item_wizard_steps             [2026-04, schéma OK]
├── source_item_attribute_id (FK typée nullable)        [2026-05-03, AJOUTÉ — staging]
└── order_*, payment_*                                  [hors scope Catalog Studio]

TRONC (Backend services)
├── Composer/                                           [livré]
│   ├── ComposerStepService (normalize + typed FK)      [DUAL-WRITE OK]
│   ├── ComposerTemplateService                         [7 templates]
│   ├── ComposerProfileProjection                       [pour preview live]
│   └── ComposerProfileChanged event (after commit)     [conforme invariant 4]
├── Menu/                                               [livré]
│   ├── MenuProjectionService::forChannel(pos|kiosk)    [SSOT projection]
│   ├── PosMenuProjection                               [recent change]
│   └── KioskMenuService                                [stable]
├── Stock/                                              [livré, scan rupture]
│   ├── StockService                                    [atomic decrement OK]
│   ├── AvailabilityService                             [resolveable]
│   └── ChoiceAvailabilityResolver                      [stockable_choices]
├── ItemBranchAvailability                              [86 par branche]
└── Events
    ├── CatalogChanged                                  [Echo broadcast]
    ├── ComposerProfileChanged                          [versioned dispatch]
    ├── ItemAvailabilityChanged                         [auto-86 trigger]
    └── StockLevelChanged                               [low-stock alert]

BRANCHES (Frontend admin)
├── CatalogStudioComponent.vue                          [LIVRÉ, à upgrader visuellement]
├── AvailabilityToggleComponent.vue                     [LIVRÉ]
├── composer/
│   ├── ProductComposerEditorComponent.vue              [LIVRÉ — 3 colonnes existant, à upgrader]
│   ├── ComposerStepListSidebar.vue                     [LIVRÉ]
│   ├── ComposerStepFormPanel.vue                       [LIVRÉ]
│   ├── StepEditorComponent.vue                         [LIVRÉ — picker present]
│   └── ComposerTemplatePickerModal.vue                 [LIVRÉ]
├── ItemPreviewComponent.vue                            [LIVRÉ]
├── wizard/ProductCreateWizardComponent.vue             [SKELETON — 9 étapes guidées]
├── stock/StockRuptureDashboardComponent.vue            [SKELETON — à brancher en side-panel]
└── ComposerPublishDiffModal.vue                        [À CRÉER]

FEUILLES (Surfaces consommatrices runtime)
├── POS public/js/pos-wizard.js                         [refactor XL Batch A/B/C DONE — OPS-flagged]
├── Kiosk KioskWizardComponent.vue + steps/*            [LIVRÉ]
├── KDS — KdsSyncService.js                             [polling fallback OPS-flagged]
└── OSS — OssSyncService.js                             [polling fallback OPS-flagged]

SAP FLOW (sync paths)
├── Echo / Pusher push                                  [primaire]
│   ├── CatalogChanged → useCatalogChangeNotifier
│   ├── ComposerProfileChanged → reload step list
│   └── ItemAvailabilityChanged → toggle card
└── Polling fallback                                    [feature-flag, OPS readiness ready]
    ├── KdsSyncService cadence runtime-configurable
    └── OssSyncService cadence runtime-configurable
```

### Verdict arbre
| Étage | Statut | Risque |
|---|---|---|
| Racines DB | ✅ Sain. FK typée ajoutée non-breaking. | Bas |
| Tronc backend | ✅ Services + events conformes invariants. | Bas |
| Branches admin | 🟡 Skeleton pour 2 surfaces (Diff modal manquante, StockRupture à brancher) | Moyen |
| Feuilles runtime | ✅ POS refactor XL clean, OPS-flagged | Bas |
| Sync flow | ✅ Push + fallback prêts, attendent rollout flags | Bas (gate humain seulement) |

---

## 3. Plan d'orchestration des missions restantes

### Phase α — Pendant que Claude Design itère (parallèle, peut commencer maintenant)

| ID | Action | Tier | Owner | Statut |
|---|---|---|---|---|
| α1 | Soak staging migration `source_item_attribute_id` (24-48h évidence) | complex | codex-extension | À LANCER |
| α2 | Backfill verification report (rows count, deterministic vs unresolved) | routine | composer | À LANCER |
| α3 | Vitest sentinel `studio-tokens-additions.spec` (vérifie qu'aucun `--cv1-*` n'est redéfini) | routine | composer | PENDING |
| α4 | Pré-câblage endpoints diff `ComposerProfileProjection v(n) vs draft` (PHP service `ComposerDiffService`) | complex | codex-extension | PENDING |
| α5 | Pré-câblage endpoint upload image produit (presigned ou local + ratio crop) | complex | codex-extension | PENDING |
| α6 | Sentinel a11y (axe-core) sur route `/admin/items/studio` | routine | composer | PENDING |

### Phase β — Réception design final Claude Design

| ID | Action | Dépend de | Tier |
|---|---|---|---|
| β1 | Apply tokens delta `--studio-*` dans `cv1-tokens.css` | livraison Claude Design | routine |
| β2 | Upgrade visuel `CatalogStudioComponent.vue` (Screen 1 layout 3 zones) | β1 | complex |
| β3 | Implémenter `<CatalogQuickCreate />` inline (Screen 2) | β2 | complex |
| β4 | Upgrade `ProductComposerEditorComponent.vue` + children (Screen 3 — drag&drop ARIA) | β1 | complex |
| β5 | Créer `ComposerPublishDiffModal.vue` + brancher diff service α4 | α4, β1 | complex |
| β6 | Brancher `StockRuptureDashboardComponent.vue` en side-panel inspecteur | β2 | routine |
| β7 | Empty/loading/error states partagés | β1 | routine |

### Phase γ — Vérification globale et clôture

| ID | Action |
|---|---|
| γ1 | Playwright critical-flow `create-tacos-product-end-to-end` (Studio → publish → POS reflète → Kiosk reflète) |
| γ2 | Vitest global ≥ 1048 passing (zéro régression baseline cycle 2) |
| γ3 | PHPUnit Composer/* + Menu/* + Stock/* ≥ vert |
| γ4 | Mega-audit consolidé V3 (`reports/audit/MEGA_AUDIT_V3_*`) |
| γ5 | Double PASS (Claude AUDIT + GPT FINAL_AUDIT) |
| γ6 | Cycle CLOSED, ACTIVE_CYCLE reset |

### Bloqueurs / soft-gates ouverts
- **SOURCE-FK staging soak evidence** : nécessaire avant de proposer prod (option 2 est *staging-only* approuvée).
- **OPS rollout flags** (polling, composer-aware) : prêts pour staging, **prod gate humain non franchi**.

---

## 4. Messages exacts à renvoyer à Claude Design (à coller tels quels)

### Batch 1 — Critique (à envoyer en priorité)

```text
Itère sur le design pour combler 5 angles morts critiques avant intégration :

1) DRAG & DROP REORDER — Sur Screen 3 (Wizard Editor, colonne steps list),
   ajoute un artboard montrant l'état de réordonnancement. Spec attendue :
   - poignée de drag visible au hover (icône `drag` à gauche de la pastille
     position) avec curseur `grab`
   - état drag-active : la step row prend l'élévation `--studio-elev-2` et
     une rotation 1deg, opacity 0.95
   - drop indicator : ligne 2px bleue (`--cv1-border-emphasis`) entre les
     deux rows cibles
   - état keyboard reorder : focus visible 3px sur la row, et un live region
     `aria-live="polite"` qui annonce "Étape 'Sauce' déplacée à la position 4
     sur 6"
   - persistance après drop : sauvegarde optimiste + toast undo 5s

2) BRANCH OVERRIDES — Crée un nouvel artboard "Inspecteur · onglet
   Disponibilité par filiale". Quand un produit est sélectionné dans Screen 1,
   l'inspecteur de droite a un onglet "Disponibilité" qui affiche une matrice
   par filiale :
   - liste filiales (3-N rows)
   - par row : toggle disponibilité, raison si 86, dernière modification,
     "synchroniser sur toutes les filiales" en sticky bottom
   - chip rouge "86" et chip orange "quota épuisé" alignés sur tokens
     existants `--studio-pill-86-*`
   - état "scope = Toutes les filiales" (multi-toggle) vs "scope = Filiale #1"
     (toggle simple)

3) IMAGE UPLOAD — Sur Screen 2 (Quick Create), détaille la zone photo :
   - état idle : drop-zone pointillée, icône image, "Glisser une photo ou
     cliquer (PNG/JPG, max 4Mo, ratio 4:3 conseillé)"
   - état drag-over : bordure pleine bleue, fond bleu très clair
   - état uploading : barre de progression linéaire (0→100%), bouton annuler
   - état success : preview image + bouton remplacer + bouton recadrer
   - état error : message rouge "Échec de l'upload — Réessayer" + bouton
   - fallback si pas de photo : initial du produit en grand sur fond
     `--studio-pill-neutral-bg`
   - Donne aussi l'état dans ProductCard (Screen 1) quand la photo manque :
     placeholder identique, badge "Photo manquante" sur le coin

4) DIFF MODAL — Spec d'algorithme + cas réels. Sur Screen 4, ajoute :
   - une section "Cas couverts" listant : step ajouté, step supprimé, step
     déplacé (changement position), step renommé (label change), changement
     de min/max, changement de visible_on, changement de addon_role,
     changement de stockable_choices
   - un mini-diagramme : "diff = compare(profile_published_v(n).steps,
     draft_v(n+1).steps) avec clé stable = step_key"
   - un cas concret : "Étape 'sauce' modifiée — max_select 1 → 2" doit
     apparaître en `--studio-diff-mod-*` avec l'avant/après visible
   - état conflict : si v(n) serveur a changé pendant l'édition, header de
     la modal devient rouge "Conflit de version — recharge nécessaire" avec
     bouton "Recharger v(n+1) serveur" qui ferme la modal sans publier

5) CONFLICT BANNER — Crée un artboard montrant l'état Conflict :
   - top-bar du Wizard Editor passe en rose `--cv1-status-blocker-bg` avec
     un texte "Une autre session a publié v5 — votre brouillon v4 ne peut
     plus être publié tel quel"
   - 2 actions : "Recharger v5 et perdre mes modifs" (destructif, rouge) /
     "Voir le diff serveur d'abord" (secondaire)
   - le bouton Publier de la TopBar est désactivé tant que le conflit n'est
     pas résolu

Renvoie ces 5 itérations dans le canvas existant comme nouveaux artboards
(ne casse pas les anciens). Garde le mapping screen → fichier Vue à jour.
```

### Batch 2 — Important (à envoyer après livraison batch 1)

```text
Améliore les 7 points suivants dans le canvas :

6) SOURCE PICKER CREATE-ON-THE-FLY — Sur Screen 3, dans le StepEditor, le
   picker source_ref doit avoir un bouton "+ Nouveau…" en bas du dropdown.
   Quand on clique, montre une mini side-sheet glissant à droite (350px)
   avec un mini-formulaire selon le source_type courant :
   - item_attribute : nom + valeurs (chips éditables) + "Créer"
   - extra_group : nom du groupe + items (avec prix) + "Créer"
   - addon : produit lié (autocomplete sur catalogue) + rôle (radio) + "Créer"
   À la création réussie, la nouvelle source est sélectionnée
   automatiquement dans le picker.

7) AUTO-SAVE STATES — Différencie 4 états du pill auto-save dans la TopBar
   du Wizard Editor (Screen 3) :
   - idle "Auto-sauvegardé il y a 4s" (gris, pas d'icône)
   - saving "Sauvegarde…" (pill bleue clair, spinner 12px)
   - saved "Sauvegardé" (pill verte, icône check, fade en idle après 2s)
   - error "Échec — Réessayer" (pill rouge, icône warning, cliquable)
   - offline "Hors ligne — modifications conservées" (pill ambre, icône
     wifi-off)

8) EMPTY/LOADING/ERROR PER ÉCRAN — L'artboard actuel S6 agrège tout. Au
   lieu, livre 3 mini-artboards par écran pertinent :
   - Screen 1 vide : "Aucune catégorie — créez-en une pour commencer"
   - Screen 1 loading : skeleton de la grille + skeleton de la sidebar
   - Screen 1 error : panneau d'erreur central, recovery actions
   - Screen 3 vide : "Aucune étape — choisissez un template ou ajoutez
     manuellement"
   - Screen 5 vide : "Stock à jour — aucune alerte"
   Les états "no permission" (rôle sans publish ni delete) doivent aussi
   être rendus : actions secondaires grisées avec tooltip "Permission
   requise : catalog.publish".

9) TOAST MICRO-SYSTEM — Crée un artboard dédié "Toast system" :
   - position : bottom-right, stacking vertical, max 3 visibles
   - durées : info 4s, warning 6s, blocker 0 (manuel)
   - undo timer : barre fine en bas qui se vide en 5s, hover pause le timer
   - tokens : info=cv1-status-info, warning=cv1-status-warning,
     blocker=cv1-status-blocker
   - aria-live polite, focus trap quand bouton action

10) PERMISSION MATRIX — Petit panneau dans le README artboard :
    - rôles : super_admin, branch_manager, kitchen_manager
    - actions : create_category, edit_category, delete_category, create_item,
      edit_item, delete_item, edit_wizard, publish_wizard, toggle_availability
    - check / cross par cellule
    Cela aide l'intégration côté permissionChecker.

11) STOCK RESOLVE FLOW — Sur Screen 5, ajoute un artboard "Resolve interaction" :
    - clic "Résoudre" sur un événement Auto-86 → mini-confirmation inline
      (pas modal) "Marquer 'Le Méga' comme à nouveau disponible sur Filiale #1 ?"
    - 2 actions : "Confirmer et resync" / "Annuler"
    - après confirm : toast undo 5s, et l'événement disparaît de la liste

12) SEARCH RESULTS — Sur Screen 1, montre l'état toolbar avec recherche
    "tac" actif :
    - dropdown live results sous l'input (max 6 résultats, "Voir tout")
    - highlight de la séquence "tac" dans chaque résultat
    - état no-results : "Aucun produit ne contient 'xyz' — vérifier
      l'orthographe ou le filtre catégorie"

Renvoie tout dans le canvas existant.
```

### Batch 3 — Polish (à envoyer si temps disponible)

```text
Polish final — 6 points pour passer de 8/10 à 10/10 :

13) KEYBOARD SHORTCUTS — Crée un artboard "Raccourcis clavier" qui montre
    une petite popup d'aide (Cmd+/) avec :
    - global : Cmd+S = publier, Cmd+P = aperçu plein écran, Cmd+/ = aide,
      Esc = fermer modal
    - liste catégories : ↑↓ navigate, Enter sélectionne, N = nouvelle
    - grille produits : ↑↓←→ navigate, Enter ouvre inspecteur, Space =
      toggle disponibilité
    - wizard editor : J/K = step suivant/précédent, D = dupliquer step,
      Backspace = supprimer step (avec confirm)
    Affiche aussi un hint discret "Cmd+/" en bas à droite de chaque écran
    principal.

14) RESPONSIVE 1280px — Une variante de Screen 1 à 1280px :
    - rail catégories collapsible (icônes seules) avec un toggle
    - inspecteur droite collapsible (overlay quand ouvert)
    - grille produits 3 colonnes au lieu de 4

15) RTL ARABE — Une variante de Screen 1 en RTL :
    - direction inversée, icônes flippées (chevrons, drag, etc.)
    - validation que les chips POS/Kiosk gardent leur ordre logique
    - police arabe (Noto Sans Arabic)

16) ONBOARDING TOUR — Un artboard montrant la 1re visite :
    - tooltip 1 : "Bienvenue. Voici tes catégories. Crée la première."
    - tooltip 2 : "Ajoute un produit dans la catégorie."
    - tooltip 3 : "Configure le wizard avec un template."
    - tooltip 4 : "Publie. Le POS et la borne se mettront à jour
      automatiquement."
    - checklist sticky en bas-gauche, dismissible.

17) REAL PHOTOS GUIDANCE — Spec dans le README :
    - ratio recommandé : 4:3
    - taille min : 800x600
    - taille max upload : 4Mo
    - formats : PNG, JPG, WebP
    - fallback ladder : photo > placeholder SVG par catégorie > initiale du
      nom sur surface neutre
    - lazy load : intersection observer, decode async

18) MICRO-INTERACTIONS — Un artboard "Interactions à conserver vs. neutraliser
    en reduced-motion" :
    - hover ProductCard : translate Y -1px, ombre +1 (neutralisé en
      reduced-motion)
    - publish CTA : scale 0.98 sur :active (neutralisé)
    - drag drop : pas d'easing sur drop final pour précision (gardé)

Renvoie le tout consolidé dans une dernière itération du canvas, avec une
section README mise à jour qui liste les 18 améliorations + n° artboard.
```

---

## 5. Suite immédiate côté code (parallèle, sans bloquer Claude Design)

1. Lancer `α1` (soak staging migration) et `α2` (backfill verification report).
2. Pré-câbler le service `ComposerDiffService` (PHP) côté backend (α4) — Claude Design n'a pas besoin d'attendre.
3. Pré-câbler endpoint upload image produit (α5).
4. Sentinel `studio-tokens-additions.spec` (α3) pour s'assurer qu'on n'override aucun token CV1 lors du merge.

Ces 4 chantiers tournent **pendant que Claude Design itère** sur les 18 angles morts. À sa livraison finale, on ne fait plus que le câblage visuel.

---

## 6. Engagement de qualité

Aucun raccourci pris :
- Tokens : additions seules.
- Schéma BDD : `source_item_attribute_id` ajouté non-breaking, dual-write, tests 5/5 PASS.
- Frozen zones : aucune touchée.
- Invariants pricing / branch_id / dispatch après commit / OrderStatus enum / symétrie services : respectés.

Le seul gate restant est la **soak evidence** staging avant proposition prod (option 2 humaine signée Kossay).
