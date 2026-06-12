# GOAL DESIGN ELEVATION — caisse + borne (2026-06-12)

> Plan design exécutable issu du dispute superviseur (round-1 `DESIGN_GAP_ANALYSIS.md` 15 gaps,
> round-2 `DESIGN_GAP_ANALYSIS_V2.md` : 4 fermés / 3 partiels / 8 ouverts, contresigné
> `ADVERSARIAL_VERDICT.md` round-2 : 0 P0 / 0 P1 / 14 P2 / 6 P3). Chaque proposition est ancrée
> sur un gap constaté (id) — zéro redesign gratuit. Tous les file:line ci-dessous re-greppés
> ce jour sur le worktree `release-v1-2026-06-10`. Normatif : `docs/design/DESIGN_SYSTEM_POLICY_2026-06-10.md`
> (POLICY) + `docs/design/DESIGN_REFERENCES_2026-06-11.md` (REF). Contraintes dures respectées :
> palette `#F4501E/#FFB800/#1A1A1A`, light 100% borne, FR, NF525 0-prix-front, frozen §7 → §F.

---

## §A — AMBITION

1. **Où on est** : produit fonctionnellement dense et numériquement juste (0 incohérence de montant sur 13 états R2), mais qui rend « gestion générique » : la borne affiche un caddie emoji 🛒 là où McDonald's kiosk v2 met une photo produit dans la zone chaude centrale du portrait ; 40-60 % du 1080×1920 est un dégradé nu (F2-03/F2-03b) quand BK Sizzle en fait une vitrine d'appétence.
2. Côté caisse, Toast 2.0 ancre total + actions dans un check latéral permanent ; notre POS a déjà cette topologie bipanneau mais crie « template » : Title Case EN sur libellés FR, « Filiale #1 », emojis en guise d'icônes.
3. **Où on veut être** : une borne qui vend (idle attract avec vraie photo Le Cayenne, CTA labellisé ≥80 px ancré bas, composition portrait pleine) et une caisse qu'un gérant lit sans glossaire (N° métier A00xx en titre, périodes datées sur chaque KPI, zéro fuite interne).
4. **Comment** : pas de refonte — le langage existe déjà (kit `ds/Ks*` 17 atoms, tokens kiosk 209 lignes + pos-v5 complets, baseline positive `kiosk-error-payment-refused` citée R1). On **propage** ce langage par sweeps normés (emoji, casse FR, terminologie, cibles, fuites) puis une passe de composition.
5. **Mesure de sortie** : re-capture des 13 états R2 → 8 gaps ouverts fermés, 3 partiels soldés, et le doc POLICY enrichi pour que l'adversaire ait une règle citable par sweep (ADV-F-P2-15).

---

## §B — SYSTÈME : évolutions du kit DS

Constat re-greppé : les fondations sont **riches** (`resources/css/kiosk/tokens.css` : spacing 0→128 px, radius 8→32+pill, 5 shadows, type scale 16→64 px ; `resources/css/foundations/pos-v5-tokens.css` : palette sémantique AA complète, type 11→48 px). Le gap = (a) 6 tokens manquants, (b) 3 composants manquants, (c) l'adoption par les écrans (constat R1 : « les gaps viennent des écrans qui ne le consomment pas »).

### B.1 Tokens à AJOUTER (additifs, aucun changement de valeur existante)

| Token | Valeur | Justification (gap) |
|---|---|---|
| `--kiosk-tap-min` | `48px` | GAP-14 — plancher WCAG/EN 301 549, déjà respecté côté pavé caisse (heal P0), absent côté borne |
| `--kiosk-tap-secondary` | `56px` | GAP-14 — crayon/poubelle panier (34/36 px constatés) |
| `--kiosk-tap-primary` | `80px` | REF §1 patterns 1080p : ~20 mm ≈ 80-82 px pour CTA borne |
| `--kiosk-pressed-scale` | `0.97` | §D — feedback tap <100 ms (REF §3 #22), gated reduced-motion |
| `--pos-action-collect` | `var(--pos-v5-brand-red)` | GAP-04 (fermé par heal `9c93920c0`) — **normaliser** le précédent en token sémantique pour empêcher la régression « une action = deux couleurs » |
| `--fk-brand-text` | `#C2410C` | **[GATE-OWNER G1]** — texte orange petit sur blanc (3.49:1 < AA). Additif : assombrit le texte, jamais les surfaces de marque |

### B.2 Utilitaires CSS à ajouter (kiosk + admin)

- `.ks-num { font-variant-numeric: tabular-nums; text-align: right; }` — GAP-15, REF §3 #12 (colonnes montants).
- `.ks-clamp-2 { display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }` — GAP-15, ellipse à frontière de mot (remplace les troncatures mi-mot « + crud… », F2-02).
- **Suppression de `capitalize`** des utilitaires partagés `resources/css/app.css` — racine du GAP-10 (vérifié : `fr.json:341` contient déjà « Tableau de bord » correct ; c'est la CSS qui le mutile). Lignes re-greppées : `.db-sidebar-nav-menu:170`, `.db-sidebar-nav-dropdown-menu:177`, `.db-breadcrumb-title:376`, `.db-breadcrumb-item:378`, `.db-btn*:328-331`, `.db-header-profile-*:156-159`, `.db-tab-btn:382`, badges produit `:238-257`. Le heal `00cc81a16` prouve que le pattern de fix est trivial.

### B.3 Composants à créer / durcir

| Composant | Spec | Gap |
|---|---|---|
| **`KsIcon` (NOUVEAU)** | Atom mappant un nom logique → FA/SVG existant (FA déjà chargé), props `name/size(20/24/32)/tone`. Table de correspondance des ~19 emojis UI relevés (🛒→`lab-bag`, 💶→`fa-coins`, 💸→`fa-rotate-left`, 🥡→`fa-bag-shopping`, 💳→`fa-credit-card`, 🖥→`fa-display`, 📋→`fa-list-check`…). Interdiction emoji-icône écrite POLICY §3 | GAP-05 |
| **`KsEmptyState` (NOUVEAU)** | Illustration marque (logo mascotte Le Cayenne existant) + titre + CTA, remplace caddie emoji du panier vide (F2-03d) et tout état vide nu | GAP-05, REF §3 #18 |
| **`KsInfoStat` (NOUVEAU)** | Bloc info non-cliquable : fond `#FFF4EE` (brand-faint), bordure 0, label eyebrow `#5A5A5A`, valeur `--kiosk-font-size-display` encre `#1A1A1A`. Ne peut PAS être confondu avec un bouton (jamais de fond `#F4501E` plein) | GAP-03 reste (ADV-F-P2-6) |
| **`KsButton` (durcir)** | `min-height: var(--kiosk-tap-min)` enforced sur toutes variantes ; état `:active` scale `--kiosk-pressed-scale` ; variante `hero-xl` déjà livrée (V1.5+) à consommer par l'idle | GAP-14, GAP-01 |
| **`KsPriceLine` (consommer)** | Existant (size hero) — à utiliser par le paiement borne au lieu du rectangle orange plein | GAP-03 |

### B.4 Sentinel à durcir (correction adversariale R2 #2)

Le light de l'idle est servi par `tokens-bold.css:259` (`--kiosk-idle-bg !important`), pas par le composant — `kioskIdleLightMode.spec.js` ne couvre que le composant. **Ajouter une assertion sur la valeur computed de `--kiosk-idle-bg`** pour qu'un refactor tokens-bold ne puisse pas ré-assombrir l'idle silencieusement.

---

## §C — PLAN PAR SURFACE

> Légende : F? = frozen ; effort S/M/L ; impact C=client G=gérant. Fichiers tous re-greppés.

### BORNE (1080×1920 portrait)

**C-1. Idle / attract-screen** — GAP-01 résiduel + GAP-05 part
- Constat (F2-01) : light conforme mais peu vendeur — pas de photo héro, CTA rond icône-seule sans label, micro-texte « CHOISISSEZ UNE OPTION… », 3 emojis flottants.
- Maquette :
```
┌──────────────────────────┐
│  [logo LE CAYENNE]       │  haut : marque
│  PHOTO PRODUIT HÉRO      │  vraie photo catalogue (jamais inventée, §3bis)
│  pleine largeur ~55%     │  rotation 2-3 visuels (KioskPromoCarouselComponent existe)
│  « Bienvenue ! »         │
│  Commandez en quelques   │
│  touches                 │
│ ┌──────────────────────┐ │
│ │  COMMANDER  →        │ │  CTA plein #F4501E, h ≥ 96px, pleine largeur,
│ └──────────────────────┘ │  ancré tiers bas (KsButton hero-xl)
│  À emporter · retrait    │
└──────────────────────────┘
```
- Fichiers : `KioskIdleScreenComponent.vue` (CTA icône-seule `:103/:541`, NON frozen), `KioskPromoCarouselComponent.vue`. Emojis flottants supprimés (sweep C-G1). F? **NON**. Effort **M**. Impact **C MAX** (écran qui décide si on touche la borne — BK Sizzle).

**C-2. Catalogue / grille** — GAP-14 (rail), GAP-15 (troncatures, eyebrow), GAP-02 part UI
- Constat (F2-02) : libellés rail `clamp(10px, 0.92vw, 12px)` (`KioskCategoriesComponent.vue:1064`) illisibles debout ; « Choix de viande + crud… » coupé mi-mot ; eyebrow « NOS Sandwich Cayenne » (accord cassé).
- Fixes : rail ≥14 px sur 2 lignes (`:1064`) ; `.ks-clamp-2` sur descriptions tuiles ; eyebrow dynamique sans « NOS » hardcodé ; fallback image = `KsEmptyState` compact marque pour toute tuile sans photo (la part images/desc = gate DATA connu, non re-compté). F? **NON**. Effort **S-M**. Impact **C FORT**.

**C-3. Wizard — steps non-frozen** — GAP-06 part
- Constat (R1 f02/f04) : étapes ~55 % vides, cartes dimensionnées écran court plaquées en haut.
- Fix : passe composition portrait sur les 9 steps `resources/js/components/frontend/kiosk/steps/*.vue` (POLICY §6 : steps **non-frozen**) — grilles d'options 2 rangées pleine hauteur, cartes ≥ `--kiosk-tap-primary`, compteur de sélection ancré bas. **Aucun prix sur step** (G2, POLICY §5 — les deltas affichés relèvent de l'arbitrage G2, pas de ce plan). Le shell `KioskWizardComponent` est frozen → sa part (chrome, 14 occurrences emoji source) part en §F. F? **NON** (steps). Effort **L**. Impact **C FORT**.

**C-4. Panier** — GAP-14 + GAP-05 + GAP-06 part + GAP-15
- Constat (F2-03) : crayon 34×34 (`KioskCartComponent.vue:991`), poubelle 36×36 (`:1051`), zone morte centrale ~45 %, placeholder « SAISIR UN CODE PROMO... » ALL-CAPS, 🥡 bandeau + 🏷️ ligne promo (`:261`).
- Maquette (zone médiane) :
```
│ [ligne article] [ligne article]   │ haut inchangé
│ ┌─ Vous aimerez aussi ──────────┐ │ NOUVELLE zone suggestions
│ │ [tuile][tuile][tuile]         │ │ (réutilise data upsell existante,
│ └───────────────────────────────┘ │  ≤1 rangée, REF §1 ≤3 prompts)
│ [totaux + promo + CTA collés bas] │
```
- Fixes : crayon/poubelle → `--kiosk-tap-secondary` 56 px (zone d'extension invisible OK) ; placeholder en casse normale ; emojis → `KsIcon` ; bloc totaux collé sous la dernière ligne quand <3 articles, zone suggestions au milieu. F? **NON**. Effort **M**. Impact **C FORT** (suppressions ratées au doigt = EAA).

**C-5. Paiement Plan B (« Paiement à la caisse »)** — GAP-03 reste (ADV-F-P2-6)
- Constat (F2-03b) : bloc info « TOTAL À RÉGLER : 3,00 € » = rectangle orange plein identique au CTA empilé dessous ; libellé CTA calé à gauche ; cluster flottant à ~55 % ; ~60 % d'écran nu.
- Maquette :
```
│   [icône carte cerclée]           │
│   PAIEMENT À LA CAISSE            │
│   Réglez à la caisse — espèces,   │
│   carte ou ticket restaurant.     │
│ ┌───────────────────────────────┐ │
│ │ Total à régler        3,00 €  │ │ ← KsInfoStat fond #FFF4EE, PAS un bouton
│ └───────────────────────────────┘ │
│            (espace)               │
│ ┌───────────────────────────────┐ │
│ │    Confirmer ma commande      │ │ ← CTA unique centré, ancré bas,
│ └───────────────────────────────┘ │   pleine largeur, h ≥ 80px
│ │      Retour au panier         │ │ ← existant (heal 43c5f2d76), conservé
└───────────────────────────────────┘
```
- Fichier : `KioskPaymentComponent.vue` (`:57` bloc total, `:76` CTA, `:89` retour — NON frozen, ≠ `admin/pos/PaymentComponent` frozen). F? **NON**. Effort **S**. Impact **C MAX** (écran de bascule de la transaction).

**C-6. Confirmations / cash-instruction** — GAP-05 part
- Constat (F2-03c/F2-03d) : structure déjà exemplaire (n° énorme, CTA ancré, countdown — baseline positive R1) ; reste 💶 pastille et 🛒 panier vide.
- Fix : `KsIcon` + `KsEmptyState` (panier vide). Fichiers : `KioskCashInstructionComponent.vue`, `KioskCartComponent.vue`, `KioskConfirmationComponent.vue`, `KioskWaitingComponent.vue`. F? **NON**. Effort **S**. Impact **C MOYEN**.

### CAISSE (1440×900)

**C-7. POS — header & panneaux** — GAP-05 + GAP-12
- Constat (F2-04) : 🖥️/📋 pills header (`PosComponent.vue:129/:149/:161`), 💰 panneau (`:348`), 🖥️ titre drawer (`:1164`), 🍔 « PRÊT À LIVRER » ; « Filiale #1 » sous le titre.
- Fix : emojis → `KsIcon`/FA ; « Filiale #1 » supprimé du header (V1 LOCAL mono-site, CONSTITUTION — le sélecteur branche reste fonctionnel mais la mention redondante disparaît). F? **NON** (PosComponent hors liste §7). Effort **S-M**. Impact **G FORT**.

**C-8. Modal encaissement** — GAP-05 + GAP-12
- Constat (F2-05) : tuiles modes avec emojis ; « Ouvre le tiroir (simulation) » visible à 900 px (`fr.json:593` `encaisser_mode_cash_sub`).
- Fix : icônes vectorielles sur tuiles (`PosCounterCollectModal.vue`, 5 occurrences source) ; « (simulation) » rendu conditionnel au flag `POS_SIMULATION_HARDWARE` (visible dev only — en prod le boot guard l'interdit déjà). Le pavé lui-même est sain (P0 fermé, 48 px plancher respecté — le standard à propager). F? **NON**. Effort **S**. Impact **G MOYEN**.

**C-9. « Ouvrir la caisse »** — GAP-08
- Constat (F2-04a) : afficheur « 50,00 € » + chips ET input brut « 50 » dessous, v-model commun sans libellé différenciant (`PosCashDrawerSessionDialog.vue:56` display, `:81-91` input).
- Maquette :
```
│ Fond de caisse initial            │
│ ┌───────────────────────────────┐ │
│ │          50,00 €           ✎  │ │ ← UN champ formaté éditable
│ └───────────────────────────────┘ │   (tap = édite, pattern PosV5Numpad)
│ [+5 €][+10 €][+20 €][+50 €][Effacer]
│           [Annuler] [Ouvrir la caisse]
```
- Fix : fusionner — l'afficheur devient le champ (input masqué stylé ou contenteditable formaté au blur) ; chips conservées. Premier geste de la journée du gérant. F? **NON**. Effort **S**. Impact **G MOYEN-FORT**.

**C-10. Show commande** — GAP-09 reste + GAP-11 + GAP-12 + GAP-05
- Constat (F2-08) : ID interne 10 chiffres en titre orange, N°A0013 métier en petit (`PosOrderShowComponent.vue:9-17`) ; « Référence interne: 2 » ; « Instruction: TIRAMISU » (miroir du nom produit) ; « Passager » ; 💸 « Rembourser ».
- Maquette header :
```
│ Commande N°A0013   [Borne] [Payé] [En préparation]   │ ← titre = n° métier + badge canal
│ 12/06/2026 à 13:25 · Espèces · À emporter            │
│ ID interne #1206264526 ⧉                             │ ← métadonnée discrète copiable
```
- Fixes : inversion hiérarchie titre ; « Référence interne: 2 » retiré (doublon interne) ; instruction masquée quand `instruction === nom produit` ; « Passager » → « Client passage » (glossaire C-13) ; 💸 → `KsIcon`. F? **NON**. Effort **S**. Impact **G FORT** (rapprochement client⇄commande par A00xx).

**C-11. File d'encaissement** — observation R2 #3
- Constat (F2-06) : zombies badgés/triés (heal OK) ; chiffre de tête « 68 / 286,90 € » gonflé tant que la purge PENDING_COUNTER n'est pas tranchée. **Décision owner** (documentée H3) — ce plan n'ajoute que le sous-titre de périmètre « dont X anciennes commandes (10/06) » sous le KPI de tête. F? NON. Effort **S**. Impact **G MOYEN**.

**C-12. Vue Caisse Unifiée / dashboards** — GAP-13 (read-side)
- Constat (F2-09) : KPI « GRAND TOTAL 27,94 € / 6 tx » sans sous-titre de période au-dessus d'une réconciliation de session ; correction adversariale R2 #1 : la cohérence constatée est **de façade** (mouvements espèces du jour rattachés à la session zombie 20 du 10/06 — write-side corrompu).
- Fix DESIGN (read-side seulement) : sous-titre de période sur chaque carte KPI (« Aujourd'hui 00:00 → maintenant », `CashOverviewComponent.vue:99` zone label) + bandeau réconciliation daté (« Session ouverte le 12/06 à 13:20 ») + retrait « (à venir) » (`fr.json:1207`, GAP-12). Le **write-side** (zombies OPEN absorbant les mouvements, résolution writer/reader unifiée) = **gate E-ADV-7 enrichi**, hors périmètre design — le plan REFUSE de maquiller : tant que E-ADV-7 n'est pas tranché, le bandeau daté rend l'incohérence VISIBLE au lieu de la cacher. F? **NON**. Effort **M**. Impact **G FORT** (écran de confiance argent).

**C-13. Global admin — sweeps transverses** — GAP-10 + GAP-11
- **Casse FR** : suppression `capitalize` des utilitaires `app.css` (§B.2, ~15 classes) + sweep des 54 fichiers `.vue` admin à classe inline (`grep -rl capitalize resources/js/components/admin` = 54) ; `fr.json` est déjà correct — aucune retraduction nécessaire. Exclusion : `PaymentComponent.vue` frozen (4 occurrences → §F).
- **Terminologie** (glossaire POLICY §4 d'abord, puis sweep i18n) : « ticket » = artefact NF525 imprimé à l'encaissement, « facture » réservé au document fiscal sur demande → « Imprimer la facture » (show) vs « Confirmer & Imprimer ticket » (modal) unifiés sur « ticket » sauf vraie facture ; walk-in unifié « Client passage » (`fr.json:446` `client_borne`, `:450` `guest`) ; badges = états (« En préparation »), boutons = verbes.
- F? **NON** (sauf îlot frozen). Effort **M** (mécanique, large). Impact **G MOYEN mais OMNIPRÉSENT**.

---

## §D — MICRO-INTERACTIONS & MOTION

Infrastructure déjà câblée (re-greppé) : `prefers-reduced-motion`/`data-kiosk-reduced-motion` dans `tokens.css`, `tokens-bold.css`, `kiosk-wizard.css`, `pos-v5-tokens.css`, `useKioskA11y.js`. Toute animation ci-dessous est **gated** par cette cascade. Budget : aucune animation >400 ms, la borne doit sentir rapide.

| Interaction | Spec | Ancrage |
|---|---|---|
| Tap card/bouton | scale 1→0.97→1, 150 ms ease-out (`--kiosk-pressed-scale`) | REF §3 #22 feedback <100 ms ; GAP-14 (l'affordance compense des cibles denses) |
| Ajout panier | badge compteur bump (scale 1→1.2→1, 200 ms) + pulse icône panier | brief §11 ; zone suggestions C-4 |
| Transition step wizard | slide horizontal 240 ms ease-out (steps non-frozen) | brief §11 ; C-3 |
| Confirmation | checkmark stroke-draw 400 ms + scale bounce ; n° d'appel fade+scale | brief §11 ; C-6 |
| KPI refresh caisse | transition opacité 150 ms sur valeur changée (pas de flash) | C-12 |
| Hover POS | déjà normé (`--pos-v5-brand-red-dark`), inchangé | GAP-04 fermé |

Reduced-motion actif → tout tombe à `transition: none` (cascade existante, rien à recâbler).

---

## §E — VAGUES D'EXÉCUTION

> Ordonnées quick-wins → structurel. Chaque vague a un critère de sortie VISUEL (capture → assertion).
> Total : **28 propositions** (5+8+3+2+4+1 exécutables + 5 gatées §F).

### VAGUE 0 — Normatif + tokens (S, 5 propositions, prérequis des sweeps)
Combler les 4 trous normatifs POLICY (ADV-F-P2-15) AVANT les sweeps (ordre validé par l'adversaire R2) :
1. POLICY §3 : interdiction emoji-icône (le set vectoriel est le seul langage d'icônes).
2. POLICY §4 : règle « sentence case FR » (jamais de `capitalize` CSS sur libellé FR) + glossaire UI court (ticket/facture, Client passage, badges=états/boutons=verbes).
3. POLICY §3 : planchers tactiles borne 48/56/80 px (tokens B.1).
4. POLICY §2 : token sémantique `--pos-action-collect` (norme « une action = un traitement »).
5. Livraison tokens B.1 + utilitaires B.2 + atoms B.3 squelettes (`KsIcon`, `KsEmptyState`, `KsInfoStat`) + sentinel B.4.
**Sortie** : POLICY diffé + tokens présents dans le bundle (`grep --kiosk-tap-min public/css`) ; sentinel `--kiosk-idle-bg` vert.

### VAGUE 1 — Quick-wins écrans (S, 8 propositions)
C-5 (paiement Plan B), C-9 (ouvrir caisse), C-10 (show hiérarchie + fuites), C-8 part « (simulation) », C-12 part « (à venir) », C-2 part eyebrow/placeholder, C-11 sous-titre file, ADV2-F-P3-NEW-1 (catch promesse 401 logout — hygiène console, voie non-frozen).
**Sortie** : re-capture F2-03b → un seul rectangle orange plein (le CTA), bloc total fond pâle, cluster ancré bas ; F2-04a → un seul champ montant ; F2-08 → titre « Commande N°A0013 », zéro « Référence interne », zéro instruction-miroir ; console show sans pageerror.

### VAGUE 2 — Sweeps mécaniques (M, 3 propositions)
1. **Emoji** (GAP-05, marqueur n°1) : ~19 occurrences UI / 10 fichiers non-frozen → `KsIcon` (C-1/C-4/C-6/C-7/C-8/C-10). Périmètre re-greppé : `KioskIdleScreenComponent`, `KioskCartComponent`, `KioskCashInstructionComponent`, `PosComponent`, `PosCounterCollectModal`, `PosOrderShowComponent`, `PosOrdersTrackerComponent` (+ fallbacks `KioskCategoriesComponent`). Occurrences frozen (KioskWizard ×14 source, KioskUpsell ×9, `PosV5TrancheRow.vue:131-135` map modes, `PaymentComponent` ×4) → §F.
2. **Casse FR** (GAP-10) : §B.2 app.css + 54 fichiers inline.
3. **Terminologie** (GAP-11) : sweep i18n sur glossaire V0.
**Sortie** : re-captures F2-01/03/03c/03d/04/04b/05/08 → 0 emoji hors frozen ; F2-08/F2-06 sidebar+breadcrumb « Tableau de bord » sentence case ; libellés ticket/facture conformes glossaire.

### VAGUE 3 — Tactile & légal EAA (M, 2 propositions)
1. C-4 cibles panier (34/36→56 px) + stepper qté ≥48 px.
2. C-2 rail catégories ≥14 px / 2 lignes + espacement ≥8 px entre cibles.
**Sortie** : probe DOM (même méthode que R2) → toute cible interactive borne ≥48 px ; capture F2-03 crayon/poubelle visiblement plus grands, F2-02 rail lisible.

### VAGUE 4 — Composition portrait (L, 4 propositions)
1. C-1 idle attract (photo héro + CTA labellisé hero-xl).
2. C-4 zone suggestions panier + totaux collés.
3. C-5 ancrage bas définitif du cluster paiement (si pas soldé en V1).
4. C-3 steps non-frozen 2 rangées pleine hauteur.
**Sortie** : re-captures F2-01/F2-03/F2-03b → zone morte centrale < 30 % (mesure par ratio de pixels du fond nu, méthode R2) ; idle montre une vraie photo produit Le Cayenne ; aucun prix sur step (probe G2).

### VAGUE 5 — Dashboards confiance (M, 1 proposition)
C-12 read-side : sous-titres de période KPI + bandeau session daté.
**Sortie** : re-capture F2-09 → chaque carte KPI porte sa période ; bandeau « Session ouverte le … à … » visible. (Write-side = E-ADV-7, hors vague.)

Chaque vague se termine par : Vitest filter + frozen diff zéro ligne + capture analysée (LOOP §5-6). Healing max 3 cycles puis escalade (§10).

---

## §F — GATES DESIGN CONSOLIDÉES

| Gate | Contenu | Statut |
|---|---|---|
| **G1 — Contraste marque** | Token additif `--fk-brand-text: #C2410C` pour textes orange PETITS sur blanc (3.49:1 → ≥4.5:1). Surfaces de marque et grands titres inchangés. Périmètre : prix tuiles, liens, eyebrows orange | **OWNER** — proposé, non appliqué |
| **G2 — Prix-étapes wizard** | Policy 0-prix-sur-étape (POLICY §5, NF525). Le plan C-3 N'AFFICHE aucun prix de step ; le sort des deltas (+1 €…) actuellement visibles côté templates = arbitrage owner en cours. Aucune vague n'y touche avant verdict | **OWNER — en arbitrage** |
| **LOCK-FROZEN-DESIGN (unique, groupé)** | 5 fixes frozen à contresigner EN UN SEUL LOCK : (1) `KioskUpsellComponent` — tuiles agrandies + symétrie Ajouter/Non merci (ADV-F-P2-16, GAP-06 part) ; (2) `KioskWizardComponent` shell — emojis chrome ×14 → `KsIcon` ; (3) `PosV5TrancheRow.vue:131-135` — map emoji modes → icônes ; (4) `PaymentComponent.vue` — 4 occurrences capitalize/emoji ; (5) `pos-wizard.js` — format prix `€1.50` → `1,50 €` (POS-ERG-07). Chacun = patch chirurgical + test régression, zéro logique | **OWNER** — dossier LOCK à générer (`/lock-plan`) au moment choisi |
| **Gates DATA (rappel, non re-comptés)** | Images/descriptions catalogue autres catégories, VAT | OWNER connus |
| **E-ADV-7 (enrichi R2)** | Sessions caisse zombies OPEN absorbent les `cash_movements` (preuve DB ADVERSARIAL_VERDICT §5.1, write-side). Le design V5 rend la période VISIBLE mais ne corrige pas le write-side — clôture/purge + résolution writer/reader unifiée = décision owner | **OWNER** — dossier à enrichir |
| **Purge PENDING_COUNTER** | File « 68 en attente » gonflée par les expirés (observation R2 #3) | **OWNER** — documenté H3 |

---
*Plan établi 2026-06-12 (phase planification GOAL dispute superviseur). 28 propositions exécutables
(V0:5 · V1:8 · V2:3 · V3:2 · V4:4 · V5:1) + 5 fixes frozen gatés + 1 token G1 proposé. Chaque
proposition tracée gap-id → file:line re-greppé. Aucune contrainte dure violée : palette intacte,
light borne, FR, 0 prix front, frozen → LOCK groupé.*
