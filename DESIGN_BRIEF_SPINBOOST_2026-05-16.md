# DESIGN BRIEF — SpinBoost MVP (pour Claude Design / Designer)

> **Brief autonome** — le designer n'a pas lu les autres docs. Tout ce qu'il faut savoir est ici.
> **Date** : 2026-05-16
> **Founder** : solo, attend des livrables Figma complets en 5-7 jours calendaires
> **Stack visée par les devs** : Next.js 15 + Tailwind + shadcn/ui (pour t'aider à choisir des composants alignés implémentation)
> **Audience designer** : Senior/Mid Product Designer (mobile-first, SaaS B2B FR)

---

## 1. CONTEXTE PRODUIT (5 min de lecture)

### 1.1 Ce que fait SpinBoost
SpinBoost est un SaaS pour restaurateurs FR. Le client final scanne un QR code sur sa table à la fin de son repas → joue à une **roue de la fortune** sur son mobile → laisse son email → gagne un cadeau (boisson, dessert, code promo). Le restaurateur récupère :
- Un email opt-in marketing pour son CRM
- Un **sondage NPS privé** (4 emojis 😡 😐 🙂 😍) sur la qualité du service
- Un CTA secondaire « si tu as aimé, laisse-nous un avis Google » **non-conditionné, non-récompensé**

**Important** : le cadeau est **inconditionnel** une fois l'email donné. On ne demande **PAS** au client de laisser un avis Google pour débloquer le cadeau. Cette règle est juridique et non négociable (cf. politique Google avril 2026).

### 1.2 Personas
- **Cliente Camille (25-45 ans)** — joueuse, mobile only, ~90 secondes d'attention max après repas. Veut une expérience fun, sans friction, sans créer de compte.
- **Patron Pierre (35-55 ans)** — restaurateur, peu technique, desktop principalement. Veut configurer en 10 min, voir ses KPIs en 1 glance.
- **Serveur Sami** — non-cible UX (pas de surface dédiée V1)

### 1.3 Tarification — à refléter dans landing + pricing
- **Starter 39 €/mois** — 1 venue, jusqu'à 500 plays/mois
- **Pro 49 €/mois** — multi-venue, plays illimités, priority support, 1h setup incluse
- Essai 14 jours sans CB

### 1.4 KPIs business à inspirer le design
- Onboarding resto < 10 min sinon il abandonne
- Spin client < 90 secondes du scan à la révélation
- Conversion trial → payant cible 20-30%

---

## 2. BRAND FOUNDATIONS

### 2.1 Direction tonale
- **Modern, joyful, flat, organisé** (préférence founder validée sur projets antérieurs : design flat ; minimal ; pas décoratif).
- Pas de skeuomorphisme (pas de roue « bois vernis carnaval »). Roue moderne SVG plat.
- **Référence d'ambiance** : Linear (dashboard) + Sunday (mobile resto) + Cash App (player playful). PAS Toast 2015, PAS Wix gaming.
- Sérieux côté dashboard, joueur côté player.

### 2.2 Palette suggérée (à challenger)
Le doc original proposait `#E63946` (rouge passion) + `#1D3557` (bleu marine). **À discuter** :
- Rouge passion = associé au fast-food générique (McDo, BK, KFC) → peu différenciant
- Alternative à proposer : **palette "carnaval moderne"** — un rouge corail #FF5757 + un jaune chaud #FFB627 + un fond off-white #FAFAF7, avec accent noir pur #0A0A0A pour le texte. Plus joyful, moins agressif.
- Aussi à explorer : palette **violet-pink "gaming jeunes"** type #7C3AED + #EC4899 sur fond gradient — mais peut être trop éloigné du resto traditionnel.

**Livrable attendu** : 2-3 propositions de palette avec mockups player et dashboard pour choisir.

### 2.3 Typographie
- **Display / Headings** : Inter (free, Google Fonts), Cal Sans (alternatif premium gratuit) ou Geist (Vercel, free)
- **Body** : Inter
- **Mono (codes voucher)** : JetBrains Mono ou Geist Mono
- Hiérarchie 4 niveaux max : Display (32-48px), Title (20-24px), Body (14-16px), Caption (12px)

### 2.4 Iconographie
- **Lucide Icons** (déjà inclus dans shadcn/ui) — homogène, free, MIT
- Pas de emojis décoratifs sauf NPS picker (😡 😐 🙂 😍) qui est explicitement emoji-driven
- Pas de Font Awesome

### 2.5 Logo
- À designer (1 mark + 1 wordmark + favicon)
- Suggestion : pictogramme = roue stylisée géométrique (3 quartiers de couleur). Pas de mascotte cartoon.
- Format : SVG + PNG 512×512 + favicon ICO multi-tailles

---

## 3. PAGES À DESIGNER

### A. SURFACE JOUEUR (mobile-first, web mobile pas PWA)
**Viewport target** : 390×844 (iPhone 14) — design baseline mobile. Tablette 768 et desktop 1024+ = responsive secondaire.

| # | Écran | États à couvrir | Notes |
|---|---|---|---|
| A1 | `/r/[slug]` — Landing intro | default, branding loaded, branding loading | Logo resto + couleur primaire venue + titre « Tente ta chance ! » + bouton « Jouer » géant. Footer mentions légales discret. |
| A2 | Email opt-in + NPS | empty, filled, error email invalide, sending | Form 1 input email + 2 checkboxes **séparées** (1: « je veux mon voucher par email » obligatoire ; 2: « j'accepte la prospection marketing du resto » optionnel). Sondage NPS 4 emojis facultatif sous le form. CTA « Suivant ». |
| A3 | Spin wheel | idle, spinning (animation 3-4s), revealed | La star du show. Roue SVG 8 quartiers colorés. Bouton central « SPIN » avec haptic feedback. Pendant spin : roue tourne, bouton désactivé. |
| A4 | Result / voucher | win-prize, win-consolation, lose | Animation confetti court (1s max). Code voucher gros + QR à présenter au comptoir. **CTA secondaire séparé** : « Si vous avez aimé votre repas, [laissez un avis Google] » — bouton outlined gris, **pas la priorité visuelle**. |
| A5 | Confirmation | sent | « Voucher envoyé à camille@…  » + bouton « Partage à un ami » (lien parrainage). |
| A6 | États bloquants | cooldown 30j, fraud detected, campaign ended | Message clair + date à laquelle on peut rejouer. Pas de page d'erreur agressive. |

**Contraintes mobile**:
- LCP target < 1,5 s sur 4G — donc pas de hero image lourde
- Roue 60 fps sur Galaxy A10 (mid-range) — design SVG simple, pas de gradients complexes
- Touch targets ≥ 44×44 px (WCAG)
- CLS < 0.1 — réserver l'espace du logo resto au load
- Pas de modal qui bloque (preferred sheets bottom-up)

### B. SURFACE DASHBOARD (desktop-first, responsive tablette)
**Viewport target** : 1440×900 baseline desktop. Mobile 390 = read-only KPIs OK.

| # | Écran | États | Notes |
|---|---|---|---|
| B1 | `/sign-in` Magic link | empty, sent | Form email + bouton « Recevoir mon lien ». Page « Lien envoyé, vérifie tes mails » après. |
| B2 | Onboarding step 1 — Resto | empty, filled, saving | Nom resto, adresse (Google Places autocomplete), type, **URL Google Maps writereview** collé manuellement (kill OAuth V1). |
| B3 | Onboarding step 2 — Branding | empty, logo uploaded, color picked | Upload logo (drag-drop), color picker primaire (palette + custom hex). Preview live d'une mini-page joueur à droite. |
| B4 | Onboarding step 3 — Roue | preset choisi, custom | 3 presets : Boisson, Dessert, Mix. Bouton « Personnaliser » → WheelEditor (cf. composant C1). |
| B5 | Onboarding step 4 — Flyer | preview, downloading | Preview PDF en visuel + bouton « Télécharger A6 / A5 / A4 ». 1 seul template. |
| B6 | Dashboard home | empty (0 plays), populated, error | 4 KPI cards en grid : Scans, Plays, Conversion %, Emails opt-in. 1 chart line 30j. Liste 5 derniers plays. |
| B7 | Campaigns list | empty, populated | Tableau campagnes : nom, statut, plays, dates. CTA « Nouvelle campagne ». |
| B8 | Campaign edit | draft, active, paused | 3 tabs : Roue (WheelEditor), Lots (PrizeEditor), Paramètres. Bouton « Publier ». |
| B9 | CRM participants | empty, populated, search | Tableau participants. Export CSV. Pas de filtres avancés V1. |
| B10 | Billing | trial, active, past_due | État abonnement + next invoice + bouton « Gérer mon abonnement » (-> Stripe portal). |
| B11 | Settings — Compte + MFA | default, MFA setup | MFA TOTP setup obligatoire après 1ère connexion (scan QR + code). |
| B12 | Flyer history | empty, list | Liste flyers générés, re-download. |

**Contraintes desktop** :
- Sidebar gauche fixe 240px + topbar 64px
- Mobile responsive : sidebar devient bottom-nav 5 items max
- Dark mode = mandatory (Linear-style toggle)

### C. SURFACE MARKETING (desktop-first, mobile responsive)

| # | Écran | États | Notes |
|---|---|---|---|
| C1 | `spinboost.fr` landing | default | Hero + 3 value props + démo vidéo Loom embed + pricing + témoignages (placeholders) + FAQ + footer |
| C2 | `/pricing` | default | 2 cards Starter / Pro, FAQ tarif, CTA trial. |
| C3 | `/cgv` `/cgu` `/confidentialite` `/mentions-legales` `/regulement-jeu` | default | Pages de texte propres, table des matières sticky desktop. |

---

## 4. COMPOSANTS CRITIQUES À DESIGNER

| # | Composant | Variants à couvrir |
|---|---|---|
| Comp-01 | **Wheel SVG** | 4 / 6 / 8 slots ; animation idle (légère pulsation) / spinning / settled |
| Comp-02 | **WheelEditor** drag-drop | slot editing inline ; preview live à droite ; jauge probabilité 100% en bas |
| Comp-03 | **NPS emoji picker** | 4 emojis grand format mobile, hover/active states, animation pop on select |
| Comp-04 | **Prize card** | with stock, unlimited stock, sold out |
| Comp-05 | **KPI card** | with delta % (+12% vs hier), loading skeleton, error |
| Comp-06 | **Toast notifications** | success, error, info, action button |
| Comp-07 | **Onboarding stepper** | 4 steps, current/completed/upcoming |
| Comp-08 | **Empty states** | « pas encore de plays », « pas encore de campagne », « pas encore de venue » — illustrations simples (Storyset free ou SVG custom minimal) |
| Comp-09 | **Voucher card** (mobile + email) | win-prize, win-consolation, used, expired |
| Comp-10 | **Buttons** | primary, secondary, ghost, destructive, loading, disabled — tailles sm/md/lg |
| Comp-11 | **Form fields** | input, textarea, select, checkbox, radio, color picker, file upload — avec error states |
| Comp-12 | **Banner alertes** | trial expire dans 3j, payment failed, compliance update |

---

## 5. ÉTATS GLOBAUX À COUVRIR (souvent oubliés)

- **Loading** : skeleton patterns (pas spinner partout), pour dashboard data fetching
- **Error** : 4xx/5xx/network — message clair + CTA recovery
- **Empty** : every list view doit avoir un état « pas encore de X » avec illustration + CTA
- **Cookie banner** : conforme CNIL (Refuser aussi visible que Accepter, pas de dark pattern)
- **Maintenance** : page `/maintenance` propre si Vercel down
- **404** : page custom branded

---

## 6. RÉFÉRENCES MODERNES 2024-2026 (à étudier, pas copier)

| Ref | Pour quoi | URL/contexte |
|---|---|---|
| Linear | Dashboard moderne, dark mode, density | https://linear.app |
| Vercel dashboard | Layout sobre B2B, sidebar | https://vercel.com/dashboard |
| Sunday (FR resto) | Mobile resto fluide payment+review | https://sunday.app/fr |
| Stripe checkout | Form mobile clean, États error | https://stripe.com |
| shadcn/ui | Baseline composants (déjà la stack dev) | https://ui.shadcn.com |
| Cash App | Mobile playful, animations | App store |
| Posthog dashboard | KPI cards + chart density | https://posthog.com |
| Plain support | Inbox SaaS minimal | https://plain.com |

**À éviter (anti-refs)** :
- Toast POS 2015 era
- Wix-gaming flashy
- Stripe Atlas (trop B2B corporate enterprise)
- N'importe quel template ThemeForest

---

## 7. ANTI-PATTERNS À ÉVITER

- ❌ Modales bloquantes mobile (utiliser bottom sheet)
- ❌ Carousel auto-rotating (kill UX et a11y)
- ❌ Animations > 4 secondes (la roue spin = 3-4s max)
- ❌ Boutons icon-only sans label (a11y) — sauf icônes universelles (× pour close)
- ❌ Pop-up newsletter intrusive sur landing
- ❌ Dark patterns RGPD (refuser cookies caché)
- ❌ Skeuomorphisme « bois carnaval » sur la roue
- ❌ Plus de 2 fonts dans le système
- ❌ Plus de 3 couleurs primaires
- ❌ Text < 14px sur mobile (lisibilité)
- ❌ Contraste < 4.5:1 (WCAG AA mandatory)
- ❌ Hover-only interactions (mobile fail)

---

## 8. ACCESSIBILITÉ (WCAG 2.1 AA mandatory)

- Contraste texte 4.5:1 minimum, 3:1 pour large text
- Focus visible sur tous les éléments interactifs
- Aria-labels sur boutons icon-only
- Keyboard nav complète dashboard (Tab/Shift-Tab/Enter/Escape)
- Reduce motion: respecte `prefers-reduced-motion` (roue plus discrète si activé)
- Player mobile : labels clairs, font ≥ 16px, touch targets 44×44px

---

## 9. LIVRABLES ATTENDUS

### 9.1 Fichier Figma structuré
```
SpinBoost — Design System v1.0
├── 00 — Foundations
│   ├── Colors (3 propositions de palette pour choisir)
│   ├── Typography
│   ├── Spacing & grid
│   ├── Iconography (icon set)
│   └── Logo (mark + wordmark + favicon)
├── 01 — Components (variants Figma)
│   └── Comp-01 à Comp-12 (cf. §4)
├── 02 — Player (mobile-first)
│   ├── A1-Landing
│   ├── A2-EmailNPS
│   ├── A3-Spin
│   ├── A4-Result
│   ├── A5-Confirmation
│   └── A6-Blocking-states
├── 03 — Dashboard (desktop)
│   └── B1-B12 (cf. §3.B)
├── 04 — Marketing
│   └── C1-C3
├── 05 — States
│   ├── Empty states
│   ├── Loading / skeleton
│   ├── Errors
│   ├── 404
│   ├── Maintenance
│   └── Cookie banner
└── 06 — Dev handoff
    ├── Design tokens (JSON exportable Style Dictionary)
    ├── Tailwind config preview (couleurs + spacing)
    └── Component-to-shadcn mapping (note: utilise Button, Card, Dialog, etc. shadcn quand possible)
```

### 9.2 Hand-off développeur
- **Design tokens en JSON** (couleurs HEX + Tailwind class names, spacing scale, type ramp) — pour intégration Tailwind config directe
- **Composants nommés shadcn-compatible** (Button, Card, Dialog, Sheet, Toast, etc.) — facilite implémentation
- **Specs interactions** : durations animations (ms), easing curves
- **Specs responsive** : breakpoints mobile/tablet/desktop avec frames Figma

### 9.3 Export assets
- Logo : SVG + PNG 512 + favicon multi-size
- Illustrations empty states : SVG export
- 1 cover OG (1200×630) pour partage social

---

## 10. DÉLAI

| Jour | Livrable attendu |
|---|---|
| J1-J2 | Foundations (palette x3 propositions + type + logo draft) — sync founder pour valider palette |
| J3-J4 | Composants critiques + 3 écrans player (A1, A3, A4) en version définitive — sync founder |
| J5-J6 | Dashboard B1-B6 + remaining player |
| J7 | Dashboard B7-B12 + marketing C1-C3 + states + hand-off package |

**Total : 7 jours calendaires**, ~5 jours travail effectif.

---

## 11. CHANNEL DE COMMUNICATION
- Founder review : 1 sync 30 min en fin de J2 (palette) + 1 sync 30 min en fin de J4 (composants) + 1 sync 30 min en fin de J7 (hand-off)
- Async via Figma comments + Loom vidéos courtes
- Questions urgentes : Slack / Discord du founder (à fournir)

---

## 12. CE QUI N'EST PAS DANS LE BRIEF (hors scope V1)

- Admin SpinBoost UI (utilise Stripe dashboard + SQL console)
- IA assistant rédaction avis (V1.2)
- Multi-user / invitations équipe (V1.1)
- Intégration POS visuelle (V1.1+)
- Marque blanche custom domain par resto (V2)
- SMS / WhatsApp player (V2)
- Autres jeux : grattage, slot machine, memory (V2)
- App native iOS/Android (jamais — web suffit)

---

**Fin du brief. Démarre par 3 propositions de palette + 1 mockup A3-Spin pour chacune.**
