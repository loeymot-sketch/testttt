# DESIGN_SPEC_KDS_V2_2026-05-11.md

> Extract structuré du design Claude Design final (handoff bundle 2026-05-11)
> Source : `plans/kds-design/kds/project/Le Cayenne KDS.html` (1334 lignes, React+Tailwind)
> README + chat : `plans/kds-design/kds/{README,chats/chat1}.md`

## ⚠️ Note brand color

**Le design utilise `#111827` (gris foncé / quasi-noir) comme brand primary**, pas le violet `#4C1A96` actuel FoodKing. Discrepance avec l'instruction owner "garder palette actuelle". À trancher owner avant Sprint 2 implementation. Pour Sprint 1 (bug fixes orthogonaux), pas d'impact.

## Layout

- Canvas 1920×1080 letterboxé via CSS `transform: scale()` (Math.min(sx, sy))
- Background body `#0b0b10` (let-edge), stage `#F9FAFB`
- Header strip 64px (h-16)
- Banner strip 32px conditionnel (yellow `#FFFBEB`, border `#FDE68A`, text `#92400E`)
- Disconnect strip 40px red `#DC2626` avec spinner
- Grid `grid-cols-4 grid-rows-2` gap-4 p-4
- Card height fixe 462px
- 8 cards max, placeholders dashed `#E5E7EB` quand <8

## Color tokens

```
BRAND
brand                 #111827   (queue, CTA bg, primary actions)
brand hover           #000000
text primary          #111827
text secondary        #4B5563
text muted            #9CA3AF
bg soft               #F9FAFB
white                 #FFFFFF
border neutral        #E5E7EB

AGE COLORING
fresh (0-3 min)       border #E5E7EB  bg #FFFFFF  time #111827
warning (3-6 min)     border #EA580C  bg #FFEDD5  time #9A3412
critical (>6 min)     border #DC2626  bg #FEE2E2  time #991B1B + pulse 1Hz
critical bg pulse     box-shadow 0 0 0 0 rgba(220,38,38,0.55) → 10px @ 0

ALLERGEN (override age border)
border item row       #F97316  (4px left)
item row bg           rgba(249,115,22,0.06)
allergen pill bg      #EA580C  text white outer-ring rgba(234,88,12,0.30)
allergen modifier     #C2410C  bold italic

SUPPLEMENT
text                  #CA8A04  italic 600
prefix                "+" stays in normal style

STATE BADGES
NOUVEAU pill          bg white   border #D1D5DB  dot #6B7280   text #374151
EN COURS pill         bg #DBEAFE  text #1E3A8A   dot #2563EB
READY pill            bg #DCFCE7  text #14532D   dot #16A34A

SOURCE CHIPS
CAISSE                bg #F3F4F6  text #374151   icon receipt
BORNE                 bg #EFF6FF  text #1E40AF   icon kiosk/tablet
ONLINE (reserved)     bg #ECFDF5  text #047857   icon globe
DINE-IN (reserved)    bg #FEF3C7  text #B45309   icon chair

ACCENT STRIPE TOP CARD (6px)
fresh + NEW           #E5E7EB
fresh + PREPARING     #111827 (brand)
warning age           #EA580C
critical age          #DC2626
allergen (override)   #F97316

LIVE DOT (active count + station status)
green                 #16A34A  pulse 1.6s

KPI HEADER
served today bg       #F0FDF4  text #166534
border                #BBF7D0

TOAST
bg                    #0F172A   (dark slate)
text                  white
subtext               #94A3B8
icon circle           #16A34A
undo button bg        white  text #0F172A  shadow #CBD5E1
progress bar          #16A34A  shrinks 3s
```

## Typography

```
Font primary          Inter (400,500,600,700,800)
Font mono             JetBrains Mono (500,700)
Font Arabic           Noto Naskh Arabic (500,700)

Queue "N°42"          52-56px  800  -0.03em  #111827
  prefix "N°"         26px  700  opacity 0.55
Elapsed "07:18"       34-36px  800  -0.02em  age-color
  label ATTENTE       10px  700  uppercase tracked  age-muted
Item name             22px  500  #111827  clamp 2 lines
Item qty "1×"         26px  800  #111827  tabular-nums minWidth 42px
  × symbol            18px opacity 0.55
Modifier              16px  italic 400  #4B5563  paddingInlineStart 56px
Supplement            16px  italic 600  #CA8A04
Allergen modifier     16px  italic 700  #C2410C
CTA "Prêt"            22px  700  white  letter-spacing 0.01em
State pill            11px  700  tracked widest
Source chip           13px  700  uppercase tracked 0.1em
Branch "Le Cayenne"   17px  800  -0.01em
Subtitle FoodKing·KDS 11px  600  uppercase tracked  #9CA3AF
Header clock          26px  800  -0.02em  tabular-nums
KPI value             15px  800  tabular-nums  #111827
KPI label             10px  700  uppercase tracked  #9CA3AF
Active count value    14px  800  tabular-nums  #166534
Slot [A]              11px  700  mono  bg rgba(0,0,0,0.04)  minWidth 22px h 18px
Empty state title     32px  700  #374151
Empty state sub       16px  400  #9CA3AF
Banner text           13px  600  #92400E
Toast title           18px  700  white
Toast subtext         13px  400  #94A3B8
Toast undo            16px  800  #0F172A
History button label  11px  800  tracked uppercase  #4B5563
```

## Card structure

```
<div order-card> (462px, 3px solid border by age, rounded-xl, white bg, fade-bump/pulse-critical/slide-in/prep-pulse classes)
  ├── TOP ACCENT STRIPE (6px height, color by state/age/allergen)
  ├── HEADER (background headerBg by age, borderBottom 1px borderColor + 0x33)
  │     ├── meta row (h26 px-3 paddingTop 6)
  │     │     ├── [shortcut slot left] (e.g. "[A]") OR spacer 22px
  │     │     ├── state badge + source chip (centered, gap 1.5)
  │     │     └── allergen pill (right) OR spacer 22px
  │     └── main row (px-4 paddingTop 2 paddingBottom 10)
  │           ├── queue number "N°42" (left, fontSize 52, font-extrabold)
  │           └── elapsed block (right, gap 2)
  │                 ├── "ATTENTE" label (10px uppercase)
  │                 └── time "07:18" (34px tabular-nums, age-color, pulse if critical)
  ├── BODY (.card-body flex-1 overflow-y-auto px-4 py-1 divide-y divide-gray-100)
  │     ├── ItemRow × N
  │     │     ├── line: qty + allergen-icon + name
  │     │     │     - qty: 26px font-extrabold minWidth 42px
  │     │     │     - allergen ⚠ icon: 20px #F97316 if item allergen
  │     │     │     - name: 22px medium clamp-2 flex-1
  │     │     │     - if itemAllergen: border-inline-start 4px solid #F97316, bg rgba 0.06
  │     │     └── modifiers (italic, paddingInlineStart 56px, gap 6px)
  │     │           - standard: prefix "·" gray #4B5563
  │     │           - supplement (/^\+/): no prefix, #CA8A04 italic 600
  │     │           - allergen (ALLERGEN_RE): prefix "⚠" #C2410C italic 700
  │     └── bottom fade-hint (16px gradient white→transparent, sticky bottom)
  ├── READY OVERLAY (when state === 'READY', conditional)
  │     - inset-0 bg rgba(22,163,74,0.12)
  │     - 96×96 round #16A34A with white check icon, shadow
  ├── PRINT FLASH (when bumping, conditional, bottom)
  │     - bg #16A34A, height 52px, white printer icon
  └── CTA BUTTON
        - margin 4px 8px 8px 8px, height 52px (NOT 60px contrary to brief!)
        - bg #1F2937 (NOT #111827!), white text 22px font-700
        - rounded-xl, gap 14px, check icon + Prêt label
        - active:translate-y-px
```

## Header structure

```
<header h-16 bg-white border-bottom #E5E7EB px-6>
  LEFT GROUP (gap-4)
    LC logo (40×40 rounded-lg bg #111827) + text "Le Cayenne" 17px-800 + subtitle "FoodKing · KDS"
    vertical separator
    Station dropdown disabled (h-10 min-w-190 border #E5E7EB bg #F9FAFB) + chevron
    Active count pill (h-10 bg #F0FDF4 border #BBF7D0 live-dot + tabular value + label)

  RIGHT GROUP (gap-3)
    KPI block (hidden xl:flex) — Servies today + Temps moyen, hardcoded 47/4:32 (Sprint 2 wires to GET /api/admin/kds/stats/today)
    Clock 26px tabular
    vertical separator
    History button (h-10 px-3 border icon + "HISTO" label) — opens Sprint 2 stub
    Settings gear button (h-10 w-10 border) — opens SettingsMenu
    Demo button (h-10 px-3) — opens DemoPanel (production: remove)
    Sound toggle (h-10 w-10, when on bg #111827 white icon)
    Language pills FR EN AR (h-10 border rounded-md, active bg #111827 text white)
```

## State machine

```
NEW arrives → if zero PREPARING on screen, auto-promote oldest NEW to PREPARING
chef taps Prêt → state READY → fade 300ms → undo toast 3s shrinking bar → final remove
undo within 3s → restore PREPARING
```

Auto-transition rule code:
```js
useEffect(() => {
  const anyPrep = orders.some(o => o.state === 'PREPARING');
  if (!anyPrep) {
    const oldestNew = [...orders].filter(o => o.state === 'NEW').sort((a,b) => a.id - b.id)[0];
    if (oldestNew) setOrders(prev => prev.map(o => o.id === oldestNew.id ? {...o, state:'PREPARING'} : o));
  }
}, [orders]);
```

## Animations (most are NEUTRALIZED — animation: none — except those listed)

ACTIVE animations:
- `.live-dot` 1.6s ease-in-out infinite (active count green dot + station status pill)
- `.pulse-digit` 1s infinite (>6 min elapsed time digit opacity 1↔0.55)
- `.fade-bump` 300ms ease-out forwards (card removal)
- `.order-card` transition 250ms ease-out / shadow 150ms / opacity 200ms / outline 150ms
- `.order-card:hover` translateY(-1px) + shadow 0 2px 8px
- `.order-card:focus-visible` outline 4px solid `#EA580C` offset 4px
- `.slide-in` 500ms cubic-bezier(.2,.8,.2,1) for new arrivals
- `.toast-in` 220ms cubic-bezier
- `.shrink-bar` 3s linear (toast progress)
- `.printer-flash` 800ms total

NEUTRALIZED (defined but `animation: none`):
- `.pulse-critical` (was border pulse)
- `.prep-dot` (was preparing dot)
- `.new-ring` (was NEW pulse ring)
- `.prep-pulse` (was state change pulse)
- `.state-cross` (was state crossfade)

Reduced motion: ALL animations set to `animation: none !important` + transitions to 0.001ms.

## i18n keys (3 langues complètes)

Object `I18N[lang]` with keys:
- branch, station, sound, lang, ready, undo
- new, preparing, readyState
- pos, kiosk, online, dinein
- emptyTitle, emptySub
- banner, toastCanceled(n), toggleEmpty, toggleFull
- allergen, print, activeOrders

AR uses `Noto Naskh Arabic`, latin numbers stay `JetBrains Mono` via `.keep-latin` class.
RTL flipped at root via `dir="rtl"`.

## Sample data (FIFO N°42 oldest → N°49 newest)

```js
{ queueNo: 42, source: "POS",   state: "PREPARING", elapsedSeconds: 440, items: [{qty:1, name:"Tacos XXL Mixte", modifiers:["Sans oignon"]}] }
{ queueNo: 43, source: "KIOSK", state: "NEW", elapsedSeconds: 380, items:[...4 items kafteji+frites+coca+brick] }
{ queueNo: 44, source: "POS",   state: "NEW", elapsedSeconds: 270, items:[ojja+pain+méchouia] }
{ queueNo: 45, source: "KIOSK", state: "NEW", elapsedSeconds: 215, items:[couscous + merguez supp + thé] }
{ queueNo: 46, source: "POS",   state: "NEW", elapsedSeconds: 135, items:[lablabi ALLERGIE GLUTEN + œuf dur] }
{ queueNo: 47, source: "KIOSK", state: "NEW", elapsedSeconds: 80,  items:[burger + cheddar supp + onion rings] }
{ queueNo: 48, source: "POS",   state: "NEW", elapsedSeconds: 35,  items:[3× brick] }
{ queueNo: 49, source: "KIOSK", state: "NEW", elapsedSeconds: 5,   items:[2× tacos poulet + frites grandes] }
```

## Allergen detection regex

```js
const ALLERGEN_RE = /(allergie|allergy|gluten|lactose|arachide|nuts|sans gluten|gluten free|peanut|noix|fruits à coque|fruits a coque)/i;
const SUPPLEMENT_RE = /^\s*\+/;
```

Owner note : extend with AR keywords (`حساسية`, `غلوتين`, `لاكتوز`) in `kdsLineSemantics.js` Sprint 2.

## Settings Menu (real, replaces "Vider l'écran")

```
SECTION Audio
  - Volume slider 0-100% accent #111827, default 70%
  - Checkbox "Son nouvelle commande" defaultChecked
  - Checkbox "Son alerte allergène" defaultChecked

SECTION Station
  - <select disabled> "Station Cuisine 1" V1
  - Checkbox "Group by table" disabled V1

SECTION Affichage
  - Theme: 2 buttons "Clair" (active bg #FEF3C7 border #111827) / "Sombre (Sprint 3)" disabled

SECTION Compte
  - Avatar LC + "Chef Le Cayenne"
  - "Déconnexion" red button bg #FEF2F2 border #FECACA text #B91C1C

SECTION À propos
  - "FoodKing v1.0.0" left muted
  - "Sync OK" right green with dot
```

## Demo Panel (will be REMOVED in production)

Boutons :
- ÉTATS : État vide / Carte longue
- ANIMATIONS : Nouvelle commande / Bump oldest / Toast standalone / State change NEW→EN COURS
- SYSTÈME : Chargement / Disconnect 60s+
- LANGUE : FR / EN / AR (RTL)

## Backend hooks (existing per audit)

- GET    /api/v1/admin/kds-order/ (KDSOrderDetailsResource)
- POST   /api/v1/admin/kds-order/change-status/{order}
- GET    /api/v1/admin/kds-order/items
- GET    /api/v1/admin/kds-order/sync
- WS     `private-branch.{branchId}` (shared POS/Kiosk/KDS)
- Events : OrderCreated, OrderStatusChanged, OrderPaymentStatusChanged, OrderCancelled

NF525 : KDS reads composition_snapshot only, no writes.
BranchScope inherited.
