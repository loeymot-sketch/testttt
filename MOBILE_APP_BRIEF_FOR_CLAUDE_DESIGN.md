# 📱 LE CAYENNE — Mobile App Design Brief
**For** : Claude Design
**Project** : Le Cayenne / FoodKing — Mobile customer app + integrated loyalty card
**Visual reference** : Pop's Villepinte mobile app (yellow/black, bold, French casual)
**Output expected** : Complete mobile app design (iOS-first, ~22 screens)

---

## 1. APP IDENTITY

### What it is
A **fast-casual restaurant ordering mobile app** for **Le Cayenne** (a French smash-burger / tacos / bowls / wraps / fried-chicken restaurant chain).

The app lets a customer :
1. Browse the menu
2. Customize a meal with extras
3. Place an order from their phone (pickup at the counter — *no delivery in V1*)
4. Pay in advance OR at counter (cash/card)
5. Earn loyalty points on every order
6. Show a personal QR code at the counter to add/redeem points (or use a physical loyalty card with the same QR)

### Brand soul
- **Loud, bold, friendly** — "du peuple, pour le peuple" energy
- **No corporate stiffness** — texts use casual French ("Salam toi !", "Viens récupérer", "Pas de spam, promis")
- **Speed-obsessed** — "Commande en 30 sec", "prêt dans quelques minutes"
- **Visual hero = food** (close-up smash burgers, golden tenders, bowls cheese-pulled)

### Target audience
- 16–35 ans, urban France (Île-de-France, banlieue parisienne)
- Habitués livraison rapide / fast-casual
- Mobile-first, jamais sur desktop
- 70% Android, 30% iOS (but design iOS-first, scale down)

---

## 2. VISUAL SYSTEM

### Color palette
```
Primary yellow      #FFD93D    (saturated, bold — like Pop's reference)
Pure black          #000000    (typography, CTAs, hero blocks)
Off-white           #FFFFFF    (content backgrounds for menu/cart)
Muted dark          #1A1A1A    (secondary text on yellow)
Soft gray           #F5F5F5    (card subtle separators)
Accent red          #FF3B30    (alerts, "épicé" tag, errors)
Accent orange       #FF9500    (loyalty points, "TOP" badges)
Success green       #34C759    (confirmation states)
```

### Typography
- **Display / Headlines** : a CONDENSED bold sans-serif (e.g. *Anton*, *Bebas Neue*, *Druk Wide Bold* — anything that reads "smash"/"tacos"/"bowl" in CAPS with confidence)
- **Body** : a clean modern sans-serif (Inter / Poppins / DM Sans)
- **Numbers / prices** : tabular sans (Inter / Manrope), always **black bold**, never light
- All-caps for section headers (NOUVEAUTÉS, CATEGORIES, SUPPLÉMENTS, MENU)
- Descriptions in sentence case, friendly tone

### Mood / texture
- Yellow backgrounds with **decorative repeated food illustrations** (popcorn buckets, fries cartons, burgers — like the LOGIN screen reference)
- White content blocks with **rounded corners** (16–24px radius) on cards
- Heavy use of **shadow-less flat design** with bold solid colors
- Photos = high contrast, dramatic angles, food held in hand on yellow background, splash effects (cf. burger splash screen Pop's)
- CTAs = pill-shaped, fully filled (yellow on black or black on yellow)
- Tags / chips : **fully rounded pills** with solid color backgrounds (red SPICY, orange NOUVEAU, yellow TOP)

### Spacing rhythm
- Screen padding : **20px** horizontal, **24px** between sections
- Card padding : **16px** internal
- Touch targets : **min 44pt** (Apple HIG)

---

## 3. NAVIGATION ARCHITECTURE

### Bottom tab bar (4 tabs)
1. **ACCUEIL** (home icon) — landing / featured / categories
2. **MENU** (crossed-utensils icon) — full menu list with cart preview
3. **COMMANDES** (receipt icon) — past + active orders + status
4. **PROFIL** (person icon) — account + loyalty card + settings

### Tab bar visual
- Background : **white with top shadow** OR yellow band (designer's call — Pop's reference uses yellow)
- Active state : black icon + text under
- Inactive : gray icon + lighter label
- Always visible except : during onboarding, login, code entry, item detail (modal-style), checkout

---

## 4. SCREEN-BY-SCREEN BRIEF

### A. ONBOARDING (4 screens, swipeable)

#### A.1 — Splash / Brand reveal
- **Full yellow background**
- Centered : large logo "LE CAYENNE" with chef-hat icon (or fork-and-knife emblem)
- No text, no buttons → auto-advance after 1.5s OR tap anywhere

#### A.2 — Welcome
- Yellow bg
- Burger close-up image (with splash effect) on right side, top half
- Headline (HUGE, condensed bold) : **"BIENVENUE CHEZ LE CAYENNE"**
- Subline : "Smash burgers, tacos, bowls — fait maison à Villepinte. Du peuple, pour le peuple."
- Bottom-left : "Passer" (skip, gray text)
- Bottom-right : pagination dots (●○○○) + black circular arrow button (→)

#### A.3 — Promise: speed
- Yellow bg
- Hand holding a tender dipping in sauce, top half
- Headline : **"COMMANDE EN 30 SEC"**
- Subline : "Choisis ton plat, personnalise-le avec tes suppléments, et valide. C'est tout."
- Same nav (Passer + dots ○●○○ + arrow)

#### A.4 — Promise: pickup
- Yellow bg
- Bucket of fries + tenders mid-air explosion, bottom half
- Headline : **"VIENS RÉCUPÉRER"**
- Subline : "Ta commande est prête quand tu arrives. Pas de file, pas d'attente. Cash ou CB."
- Nav : Passer + dots ○○○● + arrow → leads to LOGIN

### B. AUTH FLOW (2 screens)

#### B.1 — Login (phone number)
- Yellow bg with **decorative repeated icons pattern** (small popcorn buckets, fries cartons in subtle yellow-on-yellow)
- Headline (top, black bold) : **"CONNEXION"**
- Subline (small, gray) : "Entre ton numéro pour commander. Pas de spam, promis."
- Phone input (BLACK rounded pill, white text inside) :
  ```
  [+33]  06 12 34 56 78
  ```
  (the +33 is a separate small badge inside the input, country code)
- CTA full-width : **"RECEVOIR LE CODE"** (BLACK pill button, yellow text — or vice versa)
- Bottom : tiny gray "Conditions d'utilisation · Confidentialité"

#### B.2 — Code entry
- Yellow bg + small back arrow top-left
- Logo "LE CAYENNE" centered top
- Headline : **"ENTRE TON CODE"**
- Subline : "Code envoyé au 06 42 79 98 84"
- 4 large square inputs in a row, BLACK fill when typed, yellow empty
- Below : tiny gray "Code de démo : 1234" (dev only, REMOVE in prod)
- "Renvoyer le code" link (gray) after 30s countdown timer
- Auto-submit on 4th digit

### C. HOME — ACCUEIL (1 screen, scrollable)

#### C.1 — Home / Featured
- **WHITE BACKGROUND** (not yellow anymore — content mode)
- Top header :
  - Centered small logo "LE CAYENNE"
  - LEFT : (none) or hamburger menu (skip in V1)
  - RIGHT : (none)
- H1 (condensed bold black) : **"SALAM, [PRÉNOM] !"** (auto-greets the user — "Salam" is informal "hello" in FR-Maghrebi)
- Subline (gray) : "Qu'est-ce qui te fait envie ?"
- **Horizontal chip carousel** of category tags (RED background, white text) : `🔥 FAIT MAISON · 🍔 SMASH BURGERS · 🌮 TACOS · 🥣 BOWLS · 🌯 WRAPS · 🍗 BUCKETS · ...`
  - Tap → scrolls Menu tab to that category
- **Featured "SIGNATURE" card** :
  - Yellow LEFT half (text) : SIGNATURE pill badge (black) + product name "BOX FAMILIALE" (huge condensed bold) + description "4 smash burger, 5 wings, 5 tenders, Frite XXL, 4 boissons" (small gray) + CTA pill "COMMANDER →" (black)
  - RIGHT half (photo) : product photo with cropped composition
  - Price overlay bottom-right : **29,00 €** (huge bold)
- Section title : **"CATEGORIES"** + secondary "9 choix" right-aligned
- Grid of 6 categories : icon + name (Box, Smash Burgers, Buckets, Bowls, Wraps, Tacos)
  - Each chip = white card, yellow icon emoji, black text
- Section title : **"LES ENVIES DU MOMENT"** + "Voir tout" (small button, top-right)
  - Subtitle : "Notre sélection de la semaine"
  - Horizontal scroll of 2-3 items :
    - Card with photo (rounded 16px) + heart icon (favorite) overlay
    - Below : product name (bold) + price € (orange/black)
- Section title : **"NOUVEAUTÉS"** (4 items, 2x2 grid)
  - Card with photo + name + small "NOUVEAU" badge + price
- **Restaurant info card** (BLACK background, yellow accent line, white text) :
  - Title : **"LE CAYENNE — VILLEPINTE"**
  - Body : "Abdoullah en cuisine, fait maison chaque jour. Smash burgers, bowls, tacos — du peuple, pour le peuple."
  - Footer : "OUVERT 11H — 00H · 06 51 30 XX XX"
- Bottom safe-area : tab bar visible

### D. MENU (1 screen)

#### D.1 — Menu list
- White bg
- Top header : back arrow (none, it's a tab) + "MENU" (huge condensed bold) + search icon (right)
- Subline (small) : "6 categories · 16 produits"
- **Filter chips horizontal scroll** : `Tout · Box · Smash Burgers · Buckets · Bowls · Wraps · Tacos`
  - Active = yellow fill black text, inactive = gray outline
- Sections per category :
  - Title (h2 condensed bold) : "Box" + "2 créations" subline
  - List of items :
    - Product card horizontal layout : square photo LEFT (96x96, rounded 12px) + content RIGHT
    - Content : product name (bold), description (1 line gray ellipsis), price € (bold, right-aligned)
- **Sticky bottom cart bar** (when cart not empty) :
  - Yellow pill : **"1 article — 19,00 € · VOIR LE PANIER →"**
  - Tap → opens Cart tab modal

### E. ITEM DETAIL (modal/full-screen)

#### E.1 — Item detail page
- **Hero photo top half** (16:9 ratio, no padding on sides — full bleed)
  - Top-left overlay : circular WHITE button with back arrow ←
  - Top-right overlay : circular WHITE button with heart 🤍 + circular WHITE button with X (close)
  - Bottom-left price overlay : yellow pill "**15,00 €**"
  - Bottom-right time pill : "🕐 15 min"
- White content area below :
  - Tags row : red pill "🌶 SPICY" + orange pill "⭐ NOUVEAU" + star rating "⭐ 4.8"
  - H1 (condensed bold) : **"BOX NASHVILLE"**
  - Description (gray, 2 lines) : "2 tenders nashville, frite, burger aux choix et une boisson."
  - Info row : "🕐 15 min" + "📍 Retrait sur place" (rounded pill style)
  - **SUPPLÉMENTS section** :
    - Title : "SUPPLÉMENTS" + right-side "Optionnel · +1 € chacun" (small gray)
    - List of toggleable supplements (radio/checkbox style with yellow fill when ON) :
      ```
      Oignon caramélisée                         ✓
      Oignon frits                               ✓
      Jalapeños                              + 1,00 €
      Galette de pomme de terre              + 1,50 €
      Chedar                                 + 1,00 €
      Fromage de chèvre
      Raclette                               + 1,00 €
      Viande hachée                          + 2,00 €
      Pastrami                               + 2,00 €
      ```
    - Each row : name (left, bold black) + price/check (right)
  - Bottom safe spacing for sticky CTA
- **Sticky bottom CTA** (full-width yellow pill) :
  - **"AJOUTER AU PANIER"** + price (right side, bold black) **"15,00 €"**
  - On tap : haptic + cart count animates + modal dismisses

### F. CART / CHECKOUT (1 screen)

#### F.1 — Cart preview
- White bg, modal-style with rounded top corners
- Top header : back arrow ← + "PANIER" centered
- H1 (condensed bold) : **"VOTRE COMMANDE"**
- Subline (gray) : "2 articles · prêt dans quelques minutes"
- Item rows :
  - Square photo LEFT (96x96 rounded 12px)
  - Content : name (bold) + supplements summary (1 line gray)
  - Quantity stepper : `[−] 1 [+]` rounded
  - Price € right-aligned bold
  - Swipe-left to delete OR small × icon
- Section : **"ET POUR ACCOMPAGNER ?"** + "NOTRE CONSEIL" subtitle
  - Horizontal carousel of 3 small item suggestions (photo + name + price)
- **Sticky bottom CTA** (yellow band) :
  - LEFT : "TOTAL" small label + **"33,00 €"** huge condensed bold
  - RIGHT : black pill button **"VALIDER"** → opens Payment modal

#### F.2 — Payment / pickup choice (CRITICAL)

> **Note** : NF525 fiscal compliance requires receipt printing at restaurant. Mobile payment is OPTIONAL — most users pay at counter (cash or CB) like Pop's reference shows ("Cash ou CB").

- Modal stack on top of cart
- Title : **"COMMENT TU PAIES ?"**
- 2 big bold cards stacked vertically :
  - **"💳 PAYER MAINTENANT"** — small text "CB sécurisée Stripe"
  - **"🏪 PAYER À LA CAISSE"** *(default, recommended)* — small text "Cash ou CB sur place. Ton plat t'attend."
- If "PAYER MAINTENANT" → Stripe sheet integration (out of scope for design — just a placeholder card form screen)
- If "PAYER À LA CAISSE" → confirmation screen :
  - Big check icon ✓ animated
  - **"COMMANDE ENVOYÉE !"**
  - "Ta commande #1234 est prête dans 12 min. Présente ce code à la caisse pour récupérer."
  - Big QR code (or short order code "C-1234")
  - Estimated time : "Prêt à 19h45"
  - "Annuler la commande" (small gray, with confirmation modal)
  - "Retour à l'accueil" (yellow pill button)

### G. ORDERS — COMMANDES (1 screen)

#### G.1 — Orders history + active
- White bg
- Tab top : "EN COURS" (yellow active) | "HISTORIQUE" (gray)
- **Active section** : if any order in progress, sticky top card :
  - Status pill (orange "EN PRÉPARATION" or green "PRÊT À RÉCUPÉRER")
  - Order # + total + ETA
  - "Voir détail" link
- **History list** :
  - Date separator (small caps gray) : "AUJOURD'HUI", "HIER", "30 AVRIL"
  - Each order row :
    - Order # + small status icon
    - Product names (truncated 1 line) "Box Nashville, Bolws gratiné..."
    - Total € + "Reorder" button (yellow pill)
- Tap row → order detail screen with item breakdown + receipt + "Recommander" CTA

### H. PROFIL (4 screens — see I for loyalty)

#### H.1 — Profil home
- White bg
- Top : avatar circle (initials yellow bg) + name "Ikyes B." + phone number small
- "Modifier" small link
- **CARD : Loyalty card preview** (clickable, see section I) :
  - Black background, yellow accent
  - Big number "**347 POINTS**" condensed bold
  - "Plus que 153 pts pour ton prochain burger gratuit"
  - Yellow progress bar
  - Tap → opens Loyalty card detail
- **List of menu items** (each row = icon + label + chevron right) :
  - 🎟️ Ma carte fidélité
  - 📍 Mes adresses (skip if no delivery V1)
  - 💳 Moyens de paiement
  - 🔔 Notifications
  - 🌶️ Allergènes & préférences
  - 🌐 Langue (FR / EN / AR)
  - 📩 Nous contacter
  - ⚖️ CGU & Confidentialité
  - 🚪 Se déconnecter (red text)

---

## 5. LOYALTY SYSTEM (integrated in PROFIL — section I)

### Concept
Le Cayenne loyalty = **points-based fidelity card**, accessible via :
- The **mobile app** (digital QR code in profile)
- A **physical plastic card** (printed QR, given at first order at counter, OPTIONAL)

Both share the **same QR code value** = `loyalty:{user_id}:{token_hmac}` (signed token, scannable at POS or kiosk).

### Earn / spend mechanics
- **Earn** : 1 point = 1 € spent (configurable backend)
- **Spend** : every 500 points → -5 € on next order (configurable threshold)
- **Auto-credit** on order DELIVERED status (existing `AwardLoyaltyPointsOnDelivery` listener)
- **Manual scan at counter** → cashier scans QR → adds points OR redeems

### I.1 — Loyalty card detail (full screen)

- **TOP HALF: Yellow gradient or solid yellow bg**
- Top header : back arrow ← + "MA CARTE FIDÉLITÉ" small caps centered
- **Big animated QR code** centered (rounded white card, 280x280, with subtle shadow)
- Below QR : "Présente ce code à la caisse" (small gray)
- Card label : "**LE CAYENNE FIDÉLITÉ**" (small caps black) + member ID "#FK-12345" (gray monospace)
- **POINTS card** (black background, yellow text) :
  - Huge number : **"347 POINTS"** (condensed bold)
  - Below : "Soit 3,47 € de réduction disponible"
  - Progress bar : current / 500 (next reward)
  - "Plus que **153 pts** pour ton prochain **BURGER GRATUIT** 🍔"

- **BOTTOM HALF: white content area**
- **Tabs** : "MES POINTS" | "RÉCOMPENSES" | "HISTORIQUE"

  **Tab 1 : MES POINTS** (default)
  - Cards listing nearest reward thresholds :
    - 100 pts → -1 € (DÉBLOQUÉ ✓ green badge)
    - 500 pts → -5 € (153 pts manquants)
    - 1000 pts → Burger gratuit (653 pts)
    - 2000 pts → Box Familiale -50% (1653 pts)
  - Each row : reward name + points needed + small CTA "Utiliser" (if unlocked)

  **Tab 2 : RÉCOMPENSES**
  - Vertical list of redeemable rewards
  - Each card : reward name + points cost + "ÉCHANGER" pill (yellow) OR locked state

  **Tab 3 : HISTORIQUE**
  - Reverse-chrono list of point activity :
    - "+25 pts · Box Nashville · 8 mai" (green +)
    - "−500 pts · Burger gratuit utilisé · 2 mai" (red −)
    - Each row : amount, reason, date

- **Bottom info card** (small) :
  - "💳 Tu as une carte plastique ?" → "Lier ma carte" button (links physical card QR to account)
  - Or "📤 Partager mon code" → share QR via SMS/WhatsApp to friend (referral)

### I.2 — Loyalty onboarding card (one-time, after first order)

- Modal popup after first DELIVERED order :
  - Confetti animation
  - Headline "**+25 POINTS GAGNÉS !**"
  - "Tu fais partie du club Le Cayenne. Continue à commander pour débloquer des récompenses."
  - CTA : "Voir ma carte" (yellow pill) + "Plus tard" (gray text)

### I.3 — Reward redemption flow

- Tap "Utiliser" on a reward → modal :
  - "Confirmer ?"
  - Reward summary + points cost
  - "ANNULER" (gray) | "CONFIRMER" (yellow pill)
- On confirm → points deducted, reward becomes a discount applied at next order
- Visible at checkout : "🎟️ Réduction -5 € appliquée" line item

### I.4 — Physical card linking flow (one-time)

- "Lier ma carte" CTA → camera opens to scan plastic card QR
- Scan → backend confirms card belongs to user (or NEW card linked) → success modal
- Plastic card and app QR now share the same backend `loyalty_account_id`

---

## 6. USER FLOWS (for Claude Design's reference)

### Flow 1 — First-time order (no account)
```
Splash → Onboarding (4 screens) → Login (phone) → SMS code → Home →
Browse Menu → Item detail → Add to cart → Cart → Pay at counter →
Confirmation with QR pickup code → Receive +25 loyalty points modal
```

### Flow 2 — Repeat order (logged in)
```
Open app → Home (already logged) → Tap recent item OR category →
Item detail → Add to cart → Cart → Pay (instant, saved CB) →
Confirmation
```

### Flow 3 — Use loyalty points
```
Profil tab → Ma carte fidélité → RÉCOMPENSES tab →
Tap "Burger gratuit (1000 pts)" → Confirm → Points deducted →
Add Burger to cart at next order → Discount auto-applied
```

### Flow 4 — Show QR at counter
```
Profil tab → Ma carte fidélité → Big QR centered →
Cashier scans with POS scanner → Points added/used in real-time →
Push notification "+25 points crédités"
```

---

## 7. COMPONENTS LIBRARY (for designer to systematize)

- **Button — Primary** : black pill, yellow text, 56pt height, full-width or auto
- **Button — Secondary** : yellow pill, black text, same dimensions
- **Button — Tertiary** : transparent, gray underline, no fill
- **Pill chip** : rounded full, solid color, white text (red/orange/yellow variants)
- **Card — Product** : white bg, 16px radius, 12px padding, photo+text horizontal
- **Card — Featured** : split yellow-photo, 24px radius, drop shadow
- **Card — Black accent** : black bg, yellow text, used for loyalty + restaurant info
- **Input — Phone** : black pill, white text, country code badge inset
- **Input — Code box** : 4 squares, big black fill, yellow empty
- **Tab bar — Bottom** : 4 icons, white bg, top shadow, 80pt safe-area
- **Modal — Bottom sheet** : white, rounded top 24px, 80% height, drag handle
- **Toast — Notification** : yellow band slide from top, 3s auto-dismiss
- **Empty state** : centered illustration + "Rien ici pour l'instant" friendly tone
- **Loading state** : skeleton shimmer (gray pulse) instead of spinners

---

## 8. COPY / TONE GUIDE

- Greeting : "Salam toi !" (informal Magrhebi-French, very casual)
- CTAs : ALL CAPS, action-first ("COMMANDER", "AJOUTER", "VALIDER")
- Empty cart : "Ton panier est vide. Faim ?"
- Error : "Aïe, ça plante. Réessaye."
- Success : "C'est parti !"
- Loyalty : "Plus que X pts pour ton prochain Y"
- Address : "tu" (never "vous")

---

## 9. BACKEND CONSTRAINTS (so design respects API reality)

The mobile app talks to an existing Laravel backend. Design must accommodate :

- **Phone OTP login** via `POST /api/auth/signup/otp` (4-digit code, 5min TTL)
- **Sanctum bearer token** stored in keychain after login
- **Menu fetch** : `GET /api/v1/frontend/menu` returns categories + items + variations + extras (already structured for the kiosk, will be reused as-is)
- **Order creation** : `POST /api/v1/frontend/order` with idempotency key + cart payload + branch_id
- **Loyalty endpoints** existing :
  - `GET /api/v1/frontend/loyalty/balance` → user points
  - `GET /api/v1/frontend/loyalty/history` → activity feed
  - `POST /api/v1/frontend/loyalty/redeem` → use reward
  - `POST /api/v1/frontend/loyalty/scan` → cashier scans QR (POS-side, not mobile-side)
- **QR payload format** : `LECAY-LOYALTY-{user_id}-{hmac_signature}` (backend validates HMAC)
- **Pickup-only V1** : no delivery address, no map screens
- **NF525 fiscal** : payment receipt is printed at restaurant by counter, not on phone

---

## 10. DELIVERABLES EXPECTED FROM CLAUDE DESIGN

For each of the ~22 screens listed in section 4 :
1. **Hi-fi mockup** (mobile portrait, iOS frame)
2. **All states** : default / loading / empty / error / success
3. **Light mode + dark mode** (dark mode = yellow swap to muted gold #B8860B, black bg, white text)
4. **Responsive notes** : how it adapts to small phones (iPhone SE) and tall ones (iPhone 16 Pro Max)
5. **Component spec** : reusable parts in a design library (figma/Sketch tokens preferred)
6. **Loyalty card detail screen** in 3 states : 0 points (new user), mid-tier (347 pts shown), max-tier (1000+ pts)
7. **Animation suggestions** : QR code subtle pulse, points counter incrementing, confetti on first order

### Bonus (if time permits)
- Onboarding **video/lottie** suggestions for the 4 splash screens
- **Merchandise mockup** : how the physical loyalty card looks (front + back, plastic credit-card size, same yellow/black branding, QR centered, member ID + phone last-4)

---

## 11. WHAT NOT TO DESIGN (out of scope V1)

- ❌ Delivery address / map / live tracking → V1 = pickup only
- ❌ Group ordering / split bills (handled at counter, not mobile)
- ❌ Table service / dine-in flow (cf. ADR : `pos.dine_in_enabled=false` in V1)
- ❌ Multi-language UI (FR-lock V1, ADR-007 ; AR/EN reserved for V2)
- ❌ Dark mode (V1 ; reserve for V1.1)
- ❌ Tablet layout (mobile-first, no iPad/iPad Pro variants)

---

## 12. INSPIRATION REFERENCES TO MIRROR

The reference app **Pop's Villepinte** (provided as 23 screenshots) is the visual + tonal benchmark :
- Same yellow #FFD93D + black palette
- Same condensed bold display typography
- Same friendly Magrhebi-French copy ("Salam toi !", "fait maison", "du peuple")
- Same restaurant-info card pattern (black bg, yellow accent line)
- Same featured-card layout (split yellow-photo)
- Same onboarding rhythm (4 screens, big food hero per screen)
- Same bottom-tab nav (ACCUEIL / MENU / COMMANDES / PROFIL)
- Same item detail modal (full-bleed photo + sticky CTA)

**Make ours feel like a sibling app, not a copy** : same DNA, distinct identity.

---

## 13. FILE / FORMAT REQUEST FOR HANDOFF

Please deliver :
1. **Figma file** (or Sketch) with all 22 screens organized in pages
2. **Design tokens JSON** (colors, typography scales, spacing, radii)
3. **Component library** (atomic → molecular → organism)
4. **Asset export** : PNG @1x/@2x/@3x for icons + WebP for product photos
5. **Animation specs** (Lottie JSON for splash/celebration moments preferred)

Timeline expected : ~1-3h depending on Claude Design depth (we have ~1h credit, so prioritize the 22 core screens first ; bonus items only if time remains).

---

**END OF BRIEF — Pass this entire document to Claude Design as a single prompt.**
