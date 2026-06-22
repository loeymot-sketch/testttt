# DESIGN BRIEF — NEW POS FEATURES (Codes Promo + Multi-paiement)

- **Date** : 2026-05-06
- **Author** : Claude (orchestrator)
- **Cible** : Claude Design (Anthropic) — passe design intégrale
- **Status** : READY FOR DESIGN PASS
- **Cycle** : Post-POS-AUDIT (38/38 PASS Playwright + 685 phpunit + 28 sentinels, axe-core 0 critical)
- **DS** : POS V5 (warm premium) — `resources/css/foundations/pos-v5-tokens.css`
- **Surfaces** : POS (caisse) + Admin (back-office)

---

## Section 1 — Contexte produit

**FoodKing** = SaaS restaurant fast-food, type Le Cayenne (kebab / tacos / burgers / sauces / boissons).

**Pas une marque de vêtements**. Pas de "lifestyle photography". Le caissier passe **100+ commandes/jour** sous lumière mixte, doit cliquer le moins possible, lire les chiffres au premier regard.

### Persona caissier
- Opérateur rapide, écran 13–22 pouces, parfois tactile
- Multitâche : prise commande + paiement + impression + KDS dispatch
- Stress horaires : 12h–14h et 19h–22h (rush)
- Pas formé design, pas formé IT — UI doit être **évidente**

### Surfaces concernées
| Surface | Codes Promo Dashboard | Multi-paiement |
|---|---|---|
| **POS (caisse)** | Lecture seule (apply code) | OUI — modal paiement principal |
| **Admin back-office** | OUI — full CRUD | NON |
| **Borne kiosk (client)** | Apply code (clavier numérique) | NON V1 (kiosk = paiement unique) |
| **KDS (cuisine)** | NON | NON |
| **Web futur** | Apply code | OUI V2 |
| **Mobile futur** | Apply code | OUI V2 |

### Hors scope produit V1
- Pas de "sur place" / dine-in (désactivé)
- Pas de loyalty card (V2)
- Pas de multi-currency (V2)
- Pas de refund partiel d'une tranche (V2)

---

## Section 2 — Design system POS V5 actuel (référence stricte)

> Source unique vérité tokens : `resources/css/foundations/pos-v5-tokens.css`
> Source unique vérité atomes : `resources/js/components/admin/pos/v5/`

### 2.1 Palette de couleurs (à utiliser tel quel)

| Token | Hex | Usage |
|---|---|---|
| `--pos-v5-bg-app` | `#FFFBF5` | Fond global crème (jamais blanc froid) |
| `--pos-v5-bg-panel` | `#FFFFFF` | Cartes, panels, modals |
| `--pos-v5-bg-subtle` | `#F7F3EC` | Zones secondaires (header, search, numpad) |
| `--pos-v5-bg-strong` | `#1A1A1A` | Inversé (tableau head ticket noir) |
| `--pos-v5-bg-receipt` | `#FCF9F4` | Aperçu ticket caisse |
| `--pos-v5-brand-red` | `#E8001C` | CTA primaire pay/order |
| `--pos-v5-brand-red-dark` | `#B8000F` | Hover/active CTA |
| `--pos-v5-brand-red-soft` | `#FFF0F2` | Fond sélection tinted |
| `--pos-v5-brand-red-faint` | `#FFF8F9` | Hover row très subtil |
| `--pos-v5-success` | `#1B8A3A` | Encaissé / prêt |
| `--pos-v5-warning` | `#B8730B` | Attention (programmé, expire bientôt) |
| `--pos-v5-danger` | `#C21E2F` | Annulation / erreur |
| `--pos-v5-info` | `#2563EB` | Focus ring + accent info |
| `--pos-v5-ink` | `#1A1A1A` | Texte primaire (15.8:1 sur bg-app) |
| `--pos-v5-ink-soft` | `#5A5A5A` | Texte secondaire (7.4:1) |
| `--pos-v5-ink-muted` | `#8A8278` | Méta, micro |
| `--pos-v5-border` | `#EEE6D9` | Bordure standard warm |
| `--pos-v5-border-strong` | `#D9C9B8` | Bordure renforcée |

**Règle absolue** : aucune couleur hardcodée hors `pos-v5-tokens.css` (sauf print receipt fiscal). Aucun gris froid (`rgba(0,0,0,...)`) — utiliser `rgba(26,26,26,...)` warm.

### 2.2 Typographie

- **Famille shell POS** : `Inter` (scopée à `.pos-v5-shell`)
- **Famille admin (codes promo dashboard)** : `Rubik` (système actuel admin — ne pas casser)
- **Mono** : `JetBrains Mono` (codes promo affichage code = mono pour lisibilité)

| Token | Taille | Usage |
|---|---|---|
| `--pos-v5-text-eyebrow` | 11px UPPER | Labels meta (CAISSE FOODKING, CODE PROMO) |
| `--pos-v5-text-caption` | 12px | Méta secondaire |
| `--pos-v5-text-body` | 14px | Corps standard |
| `--pos-v5-text-body-lg` | 15px | Listes, prix tile |
| `--pos-v5-text-h6` | 16px | Titres carte |
| `--pos-v5-text-h5` | 18px | Titres section |
| `--pos-v5-text-h4` | 22px | Titre cart |
| `--pos-v5-text-h3` | 26px | Hero panneau, total cart |
| `--pos-v5-text-display` | 34px | Total final, "Restant dû" |
| `--pos-v5-text-display-lg` | 48px | Hero paiement "À ENCAISSER" |

**Tabular nums** : obligatoire sur tout chiffre montant (`.pos-v5-tabular`).

### 2.3 Spacing (4px grid)

`--pos-v5-space-1` = 4px → `--pos-v5-space-12` = 48px. **Densité caissier élevée** : préférer `space-3` (12px) entre lignes plutôt que `space-4` (16px) admin standard.

### 2.4 Radius

| Token | Valeur | Usage |
|---|---|---|
| `--pos-v5-radius-md` | 12px | Inputs, cards par défaut |
| `--pos-v5-radius-lg` | 16px | Tiles produits, cart panel |
| `--pos-v5-radius-xl` | 20px | Hero panneaux, **modals** |
| `--pos-v5-radius-pill` | 999px | Badges, pills, stat chips |

### 2.5 Ombres warm (jamais grises froides)

| Token | Usage |
|---|---|
| `--pos-v5-shadow-sm` | Hover row subtle |
| `--pos-v5-shadow-md` | Cards par défaut |
| `--pos-v5-shadow-lift` | Hover cards |
| `--pos-v5-shadow-modal` | Modal paiement |
| `--pos-v5-shadow-cta` | Bouton primary-pay actif |

### 2.6 Tactile minimums

| Token | Valeur | Cible |
|---|---|---|
| `--pos-v5-tap-min` | 40px | WCAG AA minimum |
| `--pos-v5-tap-comfort` | 48px | CTA standard |
| `--pos-v5-tap-large` | 56px | Paiement, confirm |
| `--pos-v5-tap-hero` | 64px | "Encaisser" final |

### 2.7 Atomes existants à réutiliser (NE PAS recréer)

| Atom | Path | Variantes / props |
|---|---|---|
| **PosV5Button** | `resources/js/components/admin/pos/v5/PosV5Button.vue` | variant: `primary` / `primary-pay` / `secondary` / `ghost` / `ghost-counter` / `danger` / `danger-ghost` / `success` / `kiosk-cash` / `tracker` ; size: `sm` (32) / `md` (40) / `lg` (48) / `xl` (56) ; slots: icon, default, badge ; props: loading, disabled, block |
| **PosV5Card** | `resources/js/components/admin/pos/v5/PosV5Card.vue` | tone: `default` / `brand` / `success` / `warning` / `danger` / `inverse` ; padding: `sm` / `md` / `lg` / `none` ; slots: head, headActions, default, foot |
| **PosV5Pill** | `resources/js/components/admin/pos/v5/PosV5Pill.vue` | variant: `default` / `brand` / `success` / `warning` / `danger` / `info` / `ghost` / `inverse` / `live` ; size: `xs` (18) / `sm` (22) / `md` (26) / `lg` (32) ; outlined |
| **PosV5Numpad** | `resources/js/components/admin/pos/v5/PosV5Numpad.vue` | layout 4×4 ; émet `@input(value)` / `@back` / `@clear` |
| **PosV5TotalRow** | `resources/js/components/admin/pos/v5/PosV5TotalRow.vue` | tone: `default` / `hero` / `muted` / `info` / `success` / `danger` ; sign: `+` / `-` / `none` |
| **PosV5StatChip** | `resources/js/components/admin/pos/v5/PosV5StatChip.vue` | tone: `neutral` / `live` / `brand` / `success` / `warning` / `danger` / `ghost` |
| **PosV5QtyStepper** | idem | stepper qty |
| **PosV5SearchInput** | idem | input search |

**Règle de réutilisation** : si un comportement match à 80%, on étend l'atome existant via prop nouvelle. **Jamais** créer un atome doublon.

---

## Section 3 — Brief Feature 1 : Codes Promo Dashboard

> **Surface** : Admin back-office (font Rubik, pas Inter). MAIS les badges/pills réutilisent les tokens POS V5 pour cohérence visuelle.

### 3.1 Pages requises V1

1. `/admin/coupons` — **Liste** (priorité 1)
2. `/admin/coupons/new` — **Création** (priorité 1)
3. `/admin/coupons/edit/:id` — **Édition** (priorité 1)
4. `/admin/coupons/analytics/:id` — **Stats** (V2 — out of scope V1, juste counter "X / Y utilisations" en V1)

### 3.2 Page Liste — wireframe ASCII

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  ◀ Admin   Codes promo                                  [+ Nouveau code]    │
├─────────────────────────────────────────────────────────────────────────────┤
│  Filtres :                                                                  │
│  Status: [Toutes ▼]  Surface: [Toutes ▼]  Période: [Toutes ▼]  🔍 [recherche]│
├─────────────────────────────────────────────────────────────────────────────┤
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │ Code        Nom              Type  Valeur  Période       Surf. Util.│   │
│  ├─────────────────────────────────────────────────────────────────────┤   │
│  │ WELCOME10   Bienvenue        %     10%     Permanent     P K W 124/-│   │
│  │             [Active]                                                 │   │
│  ├─────────────────────────────────────────────────────────────────────┤   │
│  │ HAPPY12     Happy hour midi  %     20%     12h-14h L-V   P     38/100│  │
│  │             [Programmée]                                             │   │
│  ├─────────────────────────────────────────────────────────────────────┤   │
│  │ SUMMER25    Été 2026        €     5,00€   01/07-31/08    P K W 0/500│   │
│  │             [Programmée]                                             │   │
│  ├─────────────────────────────────────────────────────────────────────┤   │
│  │ XMAS2025    Noël (expiré)   %     15%     20-25/12       P K   312/-│   │
│  │             [Expirée]                                                │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                Page 1 / 4    [◀] [▶]        │
└─────────────────────────────────────────────────────────────────────────────┘
```

#### Colonnes du tableau
| Colonne | Contenu | Composant |
|---|---|---|
| Code | `WELCOME10` (mono, uppercase, copyable au clic) | `<code>` mono |
| Nom | "Bienvenue" (libellé interne) | text |
| Type | `%` ou `€` | icône simple |
| Valeur | `10%` ou `5,00€` | tabular-nums |
| Période | "Permanent", "12h-14h L-V", "01/07-31/08" | text condensé |
| Surfaces | `P K W` (POS / Kiosk / Web) — pills 18px | `PosV5Pill xs` |
| Utilisations | `124/-` ou `38/100` (X / Y, "-" si illimité) | tabular-nums |
| Status | Badge Active / Inactive / Programmée / Expirée | `AdminCouponBadge` (NEW) |
| Actions | `⋮` menu : Edit, Toggle, Duplicate, Delete | dropdown |

#### Mapping status → badge
| Status | Variant pill | Couleur token |
|---|---|---|
| **Active** | `success` | `--pos-v5-success` |
| **Inactive** | `default` (gris warm) | `--pos-v5-ink-soft` |
| **Programmée** (start_date > now) | `warning` | `--pos-v5-warning` |
| **Expirée** (end_date < now) | `danger` outlined | `--pos-v5-danger` |

#### Filtres header
- **Status** : select `Toutes / Active / Inactive / Programmée / Expirée`
- **Surface** : select `Toutes / POS / Kiosk / Web`
- **Période** : select `Toutes / En cours / Future / Expirée`
- **Recherche** : input texte sur code + nom (debounced 250ms)

### 3.3 Page Création / Édition — sections ordonnées

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  ◀ Codes promo   Nouveau code                  [Annuler] [Enregistrer]      │
├─────────────────────────────────────────────────────────────────────────────┤
│  ┌──────────────────────────────┐  ┌─────────────────────────────────────┐  │
│  │ § 1. Identité                 │  │ APERÇU CLIENT                       │  │
│  │ Code *      [WELCOME10____]✓  │  │ ┌───────────────────────────────┐  │  │
│  │             (auto UPPERCASE)  │  │ │ 🎟️ WELCOME10                  │  │  │
│  │ Nom *       [Bienvenue____]   │  │ │ -10% sur votre commande       │  │  │
│  │ Description [____________]    │  │ │ Min 15€ — POS / Kiosk / Web   │  │  │
│  │                               │  │ └───────────────────────────────┘  │  │
│  │ § 2. Réduction                │  │                                     │  │
│  │ Type    (•) %    ( ) Montant  │  │ EXEMPLE CHIFFRÉ                     │  │
│  │ Valeur  [10____] %            │  │ Sous-total :    20,00€              │  │
│  │ Min commande [15,00] €        │  │ Réduction :    -2,00€  ▼            │  │
│  │ Cap max     [____] € (option) │  │ Total :         18,00€              │  │
│  │                               │  │                                     │  │
│  │ § 3. Période                  │  └─────────────────────────────────────┘  │
│  │ Presets : [Aujourd'hui]       │                                            │
│  │           [Week-end prochain] │                                            │
│  │           [Tout le mois]      │                                            │
│  │           [Happy hour 12-14h] │                                            │
│  │ Début   [📅 01/07/2026 00:00] │                                            │
│  │ Fin     [📅 31/08/2026 23:59] │                                            │
│  │                               │                                            │
│  │ § 4. Restrictions             │                                            │
│  │ Jours valides:                │                                            │
│  │  [L][M][M][J][V][S][D] (chips)│                                            │
│  │ Heures (option) :             │                                            │
│  │  De [12:00] à [14:00]         │                                            │
│  │ Branches:                     │                                            │
│  │  [✓] Toutes                   │                                            │
│  │  [ ] Le Cayenne Centre        │                                            │
│  │  [ ] Le Cayenne Nord          │                                            │
│  │ Surfaces:                     │                                            │
│  │  [✓] POS  [✓] Kiosk  [✓] Web  │                                            │
│  │ Limite globale : [500] (opt.) │                                            │
│  │ Limite/utilisateur : [1] (opt)│                                            │
│  └──────────────────────────────┘                                            │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 3.4 UX critique Codes Promo

#### Validation `code` unique en temps réel
- Input force `text-transform: uppercase` ; trim spaces ; allowed chars `A-Z0-9_-`
- Debounced 400ms AJAX `GET /admin/api/coupons/check?code=WELCOME10`
- 3 états visuels :
  - **idle** : input neutre, pas d'icône
  - **checking** : spinner inline droite, "Vérification…"
  - **available** : icône `✓` verte (`--pos-v5-success`), libellé "Disponible"
  - **taken** : icône `✗` rouge (`--pos-v5-danger`), libellé "Code déjà utilisé"
- aria-live polite sur le libellé

#### Presets de période (cliquables)
- "Aujourd'hui" → start = today 00:00, end = today 23:59
- "Week-end prochain" → start = prochain samedi 00:00, end = dimanche 23:59
- "Tout le mois" → start = 1er du mois 00:00, end = dernier 23:59
- "Happy hour 12-14h" → ne touche PAS dates, set heures + jours L-V

#### Preview client (carte aperçu droite)
- Live-update à chaque input change (debounced 200ms)
- Layout : icône 🎟️ + code mono + libellé réduction + min/contraintes condensés
- Surface = `PosV5Card padding="md" tone="brand"` pour cohérence DS

#### Exemple chiffré
- Sous-total fictif : 20,00€ (constant)
- Calcul réduction live selon Type + Valeur + Min/Cap
- Si min commande non atteint : libellé "Code non applicable (min 15€)" en `--pos-v5-warning`

#### A11y
- Tous inputs ont `<label>` explicite (pas placeholder seul)
- Fieldsets pour groupes (jours, branches, surfaces) avec legend sr-only
- Focus visible 3px (`--pos-v5-focus-color`)
- Erreurs validation : `aria-invalid="true"` + `<p role="alert">` sous le champ

---

## Section 4 — Brief Feature 2 : Multi-paiement / Split

> **Surface** : POS uniquement V1. Modal lancée depuis `PaymentComponent` existant.
> **Backend = source vérité** pour totaux. Frontend agrège tranches client-side, valide côté serveur à confirm.

### 4.1 Modal paiement — état initial 3 modes

```
┌─────────────────────────────────────────────────────────────────┐
│  ✕                  Paiement commande #12345                    │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│              ┌─────────────────────────┐                        │
│              │  À ENCAISSER             │                        │
│              │      25,00 €             │  ← display-lg 48px     │
│              └─────────────────────────┘                        │
│                                                                 │
│  Mode de paiement                                               │
│  ┌──────────┐  ┌──────────┐  ┌──────────────┐                   │
│  │ 💵       │  │ 💳       │  │ 🔀           │                   │
│  │ Espèces  │  │ Carte    │  │ Multi        │  ← 3 segmented    │
│  └──────────┘  └──────────┘  └──────────────┘     toggle 56px   │
│                                                                 │
│  ─────── contenu mode actif ───────                             │
│  (Espèces : numpad + tendered + change)                         │
│  (Carte : confirm + statut TPE)                                 │
│  (Multi : voir 4.2)                                             │
│                                                                 │
├─────────────────────────────────────────────────────────────────┤
│           [Annuler]              [Encaisser 25,00 €]            │
└─────────────────────────────────────────────────────────────────┘
```

### 4.2 Mode "Multi-paiement" actif

```
┌─────────────────────────────────────────────────────────────────┐
│  ✕                  Paiement commande #12345                    │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  Mode : [Espèces] [Carte] [🔀 Multi-paiement] ← actif           │
│                                                                 │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │  RESTANT DÛ                                              │   │
│  │       15,00 €              ← display 34px, rouge si > 0  │   │
│  │       (vert #1B8A3A si = 0)                              │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
│  Tranches enregistrées :                                       │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │ 💵 Espèces       10,00 €    Reçu 12,00 € → Rendu 2,00 € ✕│  │
│  ├─────────────────────────────────────────────────────────┤   │
│  │ (vide — encore 15,00 € à encaisser)                     │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
│  [+ Ajouter une tranche]   [↔ Diviser entre N personnes]       │
│                                                                 │
├─────────────────────────────────────────────────────────────────┤
│  [Annuler]   [Confirm & Print Receipt]   ← disabled si dû > 0   │
└─────────────────────────────────────────────────────────────────┘
```

### 4.3 Sub-form "Ajouter une tranche"

```
┌──────────────────────────────────────────────┐
│  Nouvelle tranche                             │
├──────────────────────────────────────────────┤
│  Mode :                                       │
│  ( ) 💵 Espèces                              │
│  (•) 💳 Carte                                │
│  ( ) 🎫 Ticket-resto                         │
│  ( ) 📝 Chèque                               │
│                                               │
│  Montant :  [_____.__] €     (max = restant) │
│                                               │
│  ── Si Espèces : ──                           │
│  Reçu :    [_____.__] €                       │
│  Rendu :   2,00 € (auto-calcul)               │
│                                               │
│  [Annuler]              [Ajouter la tranche]  │
└──────────────────────────────────────────────┘
```

### 4.4 Modal "Diviser entre N personnes"

```
┌──────────────────────────────────────────────┐
│  Diviser le restant dû                        │
├──────────────────────────────────────────────┤
│  Restant à diviser :  15,00 €                 │
│                                               │
│  Nombre de personnes :   [-] [ 3 ] [+]        │
│                          (slider 2-10)        │
│                                               │
│  Méthode :  (•) Égal   ( ) Items assignés (V2)│
│                                               │
│  Aperçu :                                     │
│  • Personne 1 :  5,00 €                       │
│  • Personne 2 :  5,00 €                       │
│  • Personne 3 :  5,00 €                       │
│                                               │
│  [Annuler]            [Créer 3 tranches]      │
└──────────────────────────────────────────────┘
```

V1 : bouton "Créer N tranches" ajoute N tranches en mode `pending` (mode = à choisir individuellement). Caissier choisit ensuite mode + amount sur chacune (mais amount pré-rempli égal).

### 4.5 UX critique Multi-paiement

#### "Restant dû" affichage
- Composant `PosV5RemainingDue` (NEW)
- Taille : `--pos-v5-text-display` (34px), font-weight black
- Couleur :
  - **> 0** : `--pos-v5-danger` (#C21E2F)
  - **= 0** : `--pos-v5-success` (#1B8A3A)
  - **< 0** (surpaiement) : `--pos-v5-warning` + libellé "Trop perçu : X€"
- aria-live="polite" sur changement de valeur
- Tabular-nums

#### Tranches list
- Composant `PosV5TrancheRow` (NEW)
- Animation entrée : slide-down 220ms `--pos-v5-ease-decelerate`
- Animation suppression : fade 140ms
- Bouton ✕ : `PosV5Button variant="danger-ghost" size="sm"` (40px tap)
- Tap target row : 56px min height (`--pos-v5-tap-large`)

#### Calcul change cash temps réel
- Sur mode Espèces, input "Reçu" trigger calcul `change = reçu - amount`
- Affiché en `--pos-v5-success-dark` si change >= 0
- Si change < 0 : input "Reçu" en `aria-invalid="true"`, libellé erreur "Montant insuffisant"

#### Receipt format (à valider avec ReceiptComponent existant)
```
PAIEMENT
─────────────────
Espèces ........ 10,00€
  Reçu 12,00€  Rendu 2,00€
Carte ........... 15,00€
─────────────────
Total ........... 25,00€
```

#### A11y
- Focus management : à l'ajout d'une tranche, focus passe sur le 1er champ
- Screen reader announces "Restant dû : 15 euros" sur changement (aria-live)
- Bouton "Confirm" : `aria-disabled="true"` + libellé sr-only "Total restant non couvert"
- Tab order : Mode → Restant → Liste tranches → Boutons add/split → Confirm

---

## Section 5 — Composants atomiques nouveaux requis

### 5.1 POS V5 (path: `resources/js/components/admin/pos/v5/`)

#### `PosV5TrancheRow.vue` (NEW)
- **Props** : `mode` (cash/card/ticket/cheque) ; `amount` (number) ; `tendered` (nullable, cash only) ; `change` (computed, cash only) ; `removable` (bool, default true)
- **Slots** : `actions` (override ✕ button)
- **Emits** : `@remove`
- **Variantes visuelles** : icône leading par mode (💵/💳/🎫/📝)
- **Layout** : flex row, 56px min height, border-bottom warm
- **Tokens** : `--pos-v5-bg-panel`, `--pos-v5-border`, `--pos-v5-tap-large`

#### `PosV5RemainingDue.vue` (NEW)
- **Props** : `value` (number) ; `currency` (default `€`) ; `label` (default "Restant dû")
- **Computed tone** : auto (danger > 0, success = 0, warning < 0)
- **A11y** : `<output role="status" aria-live="polite">`
- **Tokens** : `--pos-v5-text-display`, `--pos-v5-weight-black`, `--pos-v5-radius-lg`

#### `PosV5SplitDialog.vue` (NEW)
- **Props** : `remaining` (number) ; `min` (default 2) ; `max` (default 10)
- **Emits** : `@confirm(payload)` où payload = `{ count, method, amounts: [...] }`
- **Slots** : footer custom
- **Tokens** : `--pos-v5-radius-xl`, `--pos-v5-shadow-modal`

### 5.2 Admin generic (path: `resources/js/components/admin/coupons/`)

#### `AdminCouponBadge.vue` (NEW)
- **Props** : `status` (active/inactive/scheduled/expired)
- **Wrap** : `PosV5Pill` interne avec mapping :
  - `active` → `variant="success"`
  - `inactive` → `variant="default"`
  - `scheduled` → `variant="warning"`
  - `expired` → `variant="danger" outlined`
- **A11y** : `aria-label` localisé fr/en

#### `AdminCouponPreview.vue` (NEW)
- **Props** : `code` ; `discountType` (% / €) ; `discountValue` ; `minOrder` (nullable) ; `surfaces` (array) ; `period` (string condensé)
- **Layout** : `PosV5Card tone="brand" padding="md"` avec icône 🎟️ + code mono large + libellé réduction + meta condensée
- **Use** : aperçu form édition + tooltip hover liste

---

## Section 6 — Inspirations concurrence

### 6.1 Splash POS (référence visuelle warm)
- **À prendre** : densité info caissier, gros boutons paiement, tabular-nums systématique
- **À NE PAS prendre** : illustrations style "lifestyle", animations idle pulsantes envahissantes, couleurs pastel délavées

### 6.2 Square POS (référence split payment)
- **À prendre** : pattern "Restant dû en grand" + liste tranches sous, bouton "Even split" ergonomique
- **À NE PAS prendre** : multi-step wizard 3 écrans (trop lent caissier rush) ; on reste **single modal scrollable**

### 6.3 Toast POS (référence codes promo)
- **À prendre** : preview client live à droite du form, presets période ("Happy hour" en 1 clic)
- **À NE PAS prendre** : 12 onglets de configuration (overengineering — fast-food a 4 sections suffisantes)

### 6.4 iZettle / Sumup (référence modal compact)
- **À prendre** : 3 modes segmented toggle, transitions courtes (140ms), boutons larges (56px)
- **À NE PAS prendre** : style "carte bancaire skeumorphique" — on reste flat warm

**Principe synthèse** : prendre l'**information density** de Square + le **warm friendly** de Splash, en restant **fast-food simple**.

---

## Section 7 — Contraintes techniques pour Claude Design

| Item | Décision |
|---|---|
| Framework | Vue 3 (Options API mixé Composition API — accepter les 2 styles existants) |
| CSS | Tailwind + tokens DS POS V5 (jamais hex hardcodé hors tokens.css) |
| Component lib | **AUCUNE** externe (pas Element Plus, pas Vuetify, pas PrimeVue) — on étend les atomes V5 |
| Fonts | `Inter` pour `.pos-v5-shell` (modal multi-paiement) ; `Rubik` pour admin codes promo (système actuel) |
| Icônes | Font Awesome déjà installé pour admin ; **emojis Unicode** acceptables pour modes paiement (💵 💳 🎫 📝) — leur tracking-friendly screen readers OK |
| A11y | WCAG 2.1 AA min, validé axe-core (cycle 5 POS = 0 violations critiques) |
| Responsive | Base 1280×800 ; max 1920×1080 ; tablette caissier 768×1024 ; modal multi-pay = max-width 720px |
| Performance | Lazy-load tableau codes promo (>50 entrées V2) ; pas plus de 2 images >50KB par page ; tabular-nums obligatoire chiffres |
| Backend | **Source vérité prix** : tous calculs (réduction effective, tranches sum) revalidés serveur à confirm |
| Branch isolation | Liste codes promo filtrée par branche du user connecté (sauf admin global) |
| Reduced motion | `@media (prefers-reduced-motion: reduce)` — animations passent à 1ms (déjà câblé dans tokens) |

---

## Section 8 — Acceptance criteria pour Claude Design

### 8.1 Codes Promo Dashboard (8 AC)

1. **AC-CP-01** — Given le manager sur `/admin/coupons`, when la page charge, then la liste affiche tous les codes triés par `created_at desc`, paginée 20/page, avec status badge correct par code.
2. **AC-CP-02** — Given un code WELCOME10 status active, when le manager clique le badge ⋮ → Toggle, then le code passe inactive sans recharger la page (optimistic update + toast confirmation).
3. **AC-CP-03** — Given le manager sur `/admin/coupons/new`, when il saisit `welcome10` dans champ Code, then l'input affiche `WELCOME10` (auto-uppercase) et déclenche check unicité après 400ms.
4. **AC-CP-04** — Given un code déjà utilisé saisi, when la vérification AJAX retourne `taken`, then icône ✗ rouge + libellé "Code déjà utilisé" + bouton Enregistrer disabled.
5. **AC-CP-05** — Given le manager clique preset "Happy hour 12-14h", when le preset s'active, then heures `12:00-14:00` + jours `L-V` pré-remplis, dates non touchées.
6. **AC-CP-06** — Given un code Type=% Valeur=10 Min=15€, when manager change Valeur à 20, then preview chiffré update : sous-total 20€ → réduction -4€ → total 16€ (live).
7. **AC-CP-07** — Given un code expiré (end_date passée), when il apparaît dans la liste, then badge "Expirée" rouge outlined + actions Toggle/Edit disabled (sauf Duplicate).
8. **AC-CP-08** — Given un manager utilisateur du screen reader NVDA/VoiceOver, when il navigue le form, then chaque fieldset annonce son legend, focus 3px visible, erreurs annoncées via `role="alert"`.

### 8.2 Multi-paiement (8 AC)

1. **AC-MP-01** — Given le caissier sur le modal paiement total 25€, when il clique "Multi", then les 3 boutons sont visibles, "Multi" est en état actif (border brand-red + bg brand-red-soft), restant dû affiche 25€ rouge.
2. **AC-MP-02** — Given mode Multi actif, when caissier clique "+ Ajouter tranche" → Mode Espèces → Montant 10 → Reçu 12 → Ajouter, then tranche apparaît en liste avec rendu 2€ calculé, restant dû passe à 15€ animation 220ms.
3. **AC-MP-03** — Given une tranche cash sans tendered, when caissier clique "Ajouter", then erreur affichée "Montant reçu requis" sous champ + `aria-invalid="true"` + focus reste sur Reçu.
4. **AC-MP-04** — Given une tranche cash avec reçu < amount, when caissier valide, then libellé erreur "Montant insuffisant" en danger, bouton Ajouter disabled.
5. **AC-MP-05** — Given restant dû = 0€, when caissier regarde le restant, then affichage passe en vert success, bouton "Confirm & Print Receipt" devient enabled (focus visible si Tab).
6. **AC-MP-06** — Given caissier clique "Diviser entre 3 personnes", when il valide, then 3 tranches `pending` à 5€ chacune apparaissent, focus passe sur 1ère tranche.
7. **AC-MP-07** — Given screen reader actif, when restant dû change de 25 à 15, then `aria-live="polite"` annonce "Restant dû : 15 euros" sans interrompre flux.
8. **AC-MP-08** — Given tranche en surpaiement (3 tranches 10€ + 10€ + 10€ = 30 sur 25), when caissier valide, then warning "Trop perçu : 5€" affiché + bouton Confirm autorisé (cas légal cash uniquement, V1 : on bloque card/ticket si > restant).

---

## Section 9 — Out of scope V1 (clarté)

| Feature | Status | Note |
|---|---|---|
| Loyalty card scan dans paiement | V2 | Plan séparé |
| Receipt email PDF | V2 | Plan séparé |
| Multi-currency | V2 | EUR uniquement V1 |
| Refund partiel d'une tranche | V2 | Plan refund POS séparé |
| Analytics codes promo (graphes utilisation) | V2 | V1 = juste counter `X / Y` |
| Bulk import codes CSV | V2 | V1 = création unitaire |
| Codes promo cumulables (stack) | V2 | V1 = 1 code max par commande |
| Auto-suggest codes au caissier (top 5) | V2 | V1 = saisie manuelle |
| Items-assigned split (pas égal) | V2 | V1 = égal uniquement |
| Drag-to-reorder tranches | V2 | V1 = ordre = ordre d'ajout |
| Codes promo par catégorie produit | V2 | V1 = tous produits |

---

## Section 10 — Livraison attendue de Claude Design

### 10.1 Artefacts attendus
- **Maquettes** : Figma (lien partagé) OU wireframes ASCII détaillés markdown
- **Specs CSS** : par composant nouveau (TrancheRow, RemainingDue, SplitDialog, CouponBadge, CouponPreview), avec sélecteurs + tokens utilisés
- **States couverts** par composant : `default`, `hover`, `focus-visible`, `active`, `disabled`, `loading`, `error`, `empty`
- **Animations** : durée + easing pour chaque transition critique :
  - Tranche entrée : 220ms `--pos-v5-ease-decelerate`
  - Tranche suppression : 140ms ease-out
  - Restant dû change : pulse 200ms `--pos-v5-ease-bounce`
  - Modal open : 220ms slide-up + fade
  - Preview client update : 200ms debounced fade
- **Reduced motion** : variante 1ms pour chaque animation

### 10.2 Tests utilisateur recommandés
- **Test 1 codes promo** : un manager découvre l'écran new code et crée un code "WELCOME15" à -15% sur surface POS uniquement, valable juin 2026. Mesurer : temps écoulé, erreurs, abandons sur preset.
- **Test 2 multi-paiement** : un caissier en rush simulé encaisse 32€ avec 20€ cash + 12€ carte. Mesurer : nombre de clics, erreurs sur change, focus management.

### 10.3 Format livrable préféré
- 1 fichier markdown `DESIGN_SPECS_OUTPUT_2026-XX-XX.md` par feature
- ASCII wireframes inline + screenshots Figma si dispo
- Section "Tokens utilisés" en début pour traçabilité

---

## Section 11 — Calendrier suggéré

| Phase | Durée | Livrables |
|---|---|---|
| **Phase 1** | 1 semaine | Maquettes Codes Promo (Liste + Form + Preview + Badges) |
| **Phase 2** | 1 semaine | Maquettes Multi-paiement (Modal 3 modes + Tranches + Split dialog + Receipt) |
| **Phase 3** | 3 jours | Revue cross-surface (POS desktop + tablette caissier 768×1024) + ajustements |
| **Phase 4** | 3 jours | Hand-off développeur + Q&A specs (Cursor implémente avec specs Claude Design) |

**Total estimé** : ~3 semaines design pour les 2 features.

---

## Annexe A — Fichiers existants à lire avant design

| Path | Pourquoi |
|---|---|
| `resources/css/foundations/pos-v5-tokens.css` | Tokens source unique vérité |
| `resources/css/pos-v5.css` | Composants POS V5 partagés |
| `resources/css/pos-a11y.css` | Surcouche a11y existante |
| `resources/js/components/admin/pos/v5/PosV5Button.vue` | API button (variants + sizes) |
| `resources/js/components/admin/pos/v5/PosV5Card.vue` | API card (tones + paddings) |
| `resources/js/components/admin/pos/v5/PosV5Pill.vue` | API pill (variants + outlined) |
| `resources/js/components/admin/pos/v5/PosV5Numpad.vue` | API numpad réutilisé tranche cash |
| `resources/js/components/admin/pos/v5/PosV5TotalRow.vue` | API total row (tone hero) |
| `resources/js/components/admin/pos/v5/PosV5StatChip.vue` | API stat chip |
| `docs/audit/POS_AUDIT_FINAL_REPORT_2026-05-06.md` | Évidence cycle 5 (axe-core 0 critical) |
| `docs/PROJECT_CONTINUITY_AND_VISION.md` | Vision produit FoodKing |
| `docs/BUSINESS_RULES.md` | Règles métier (pricing backend) |
| `docs/AUTHZ_MATRIX.md` | Permissions admin codes promo |

---

## Annexe B — Anti-patterns interdits (rappel)

1. **Aucune couleur hardcodée** hors `pos-v5-tokens.css` (sauf print fiscal `#444`)
2. **Aucun gradient** sombre noir-bordeaux (le rouge brand est un accent, pas un fond)
3. **Aucune ombre grise froide** (`rgba(0,0,0,...)`) — toujours warm `rgba(26,26,26,...)`
4. **Aucun composant lib externe** (Element Plus, Vuetify, PrimeVue interdits)
5. **Aucun atome doublon** — étendre les V5 existants si match à 80%
6. **Aucun calcul prix client-side autoritaire** — backend = source vérité, frontend = display
7. **Aucune surcharge `!important`** sans justification commentée
8. **Aucun multi-step wizard** (>2 écrans) pour caissier — single modal scrollable
9. **Aucun placeholder seul** comme label — toujours `<label>` explicite
10. **Aucune animation idle pulsante** envahissante (sauf `live` indicator validé tokens)

---

## Annexe C — Glossaire

- **POS** : Point of Sale (caisse)
- **Kiosk** : borne tactile self-service client
- **KDS** : Kitchen Display System (écran cuisine)
- **OSS** : Order Status Screen (écran public statut commande)
- **TPE** : Terminal de Paiement Électronique (carte)
- **Tranche** : sous-paiement d'une commande en mode multi
- **Tendered** : montant remis par le client (cash) ; `change = tendered - amount`
- **Branch isolation** : règle FoodKing — un user ne voit que les codes/commandes de sa branche
- **Warm premium** : style visuel POS V5 (crème + rouge brand + ombres warm)
- **Frozen zone** : code/UI gelé (wizard kiosk popup capture-only)

---

**FIN DU BRIEF** — Claude Design peut commencer Phase 1 (Codes Promo Dashboard) sans poser de questions sur tokens, atomes, ou contraintes techniques. Toutes les décisions produit V1 sont arbitrées dans Section 9 (out of scope) et Section 7 (contraintes techniques).
