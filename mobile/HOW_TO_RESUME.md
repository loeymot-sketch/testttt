# 📱 Le Cayenne Mobile App — Guide de reprise (nouvelle session)

**Dernière session** : 2026-05-10
**Branche** : `feature/mobile-app-le-cayenne-2026-05-10`
**État** : V0 100% fonctionnelle, isolée du système global, prête à connecter en Phase 6+

---

## 🟢 État actuel (sauvegardé ✓)

### ✅ Isolation confirmée
L'app mobile vit **dans le dossier `mobile/`** uniquement. Mes 7 commits ne touchent que :
- `mobile/` (tous les fichiers)
- `.claude/launch.json` (ajout config preview port 8081)
- `PROJECT_BRAIN.md` §2 + §3 (état projet)

**0 modification frozen-zone**, **0 modification backend Laravel**, **0 modification kiosk Vue**. L'app est complètement indépendante du système POS/Kiosk/KDS/OSS/Admin.

### ✅ Aucune connexion réseau
L'app boote 100% standalone via `mobile/data/*.js` et `localStorage`. Aucune requête HTTP vers le backend, aucune sync, aucun token Sanctum réel. **Tout est mock prêt à brancher** (cf. CONNECTION_PLAN.md).

### ✅ Graphiti MCP mémoire long-terme
Épisode pushé dans `group_id=foodking` : *"Mobile app Le Cayenne V0 livrée 2026-05-10 — isolée + kiosk-aligned"*. Une nouvelle session Claude qui lit Graphiti retrouvera tout le contexte.

### 📋 Commits chronologiques (7 commits mobile)
```
eb201efc2  fix(mobile): aligner data + ScreenItem 1:1 avec kiosk Le Cayenne (config/menu.php SSOT)
9afff4702  fix(mobile): home featured card slug + profile rows feedback
81ecf2554  feat(mobile): wire all missing onClicks + dynamic order detail + cart upsell
3b8a14eb2  docs(brain): §2 + §3 — livraison V0 mobile app Le Cayenne
24188a371  docs(mobile): CONNECTION_PLAN.md — roadmap Supabase + audit FoodKing global
88897dc13  feat(mobile): Phase 2 — production index.html + wizard ScreenItem complet
b1aadd010  feat(mobile): Phase 1 — Le Cayenne mobile app bundle + data layer
```

Tous restent dans l'historique de la branche même si HEAD a avancé avec du travail e2e parallèle.

---

## 🚀 Commencer une nouvelle session

### 1. Lancer Claude Code dans le projet
```bash
cd /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
claude
```

### 2. Vérifier la branche et l'état
Dans Claude :
```
Vérifie que je suis sur la branche feature/mobile-app-le-cayenne-2026-05-10
et lis mobile/HOW_TO_RESUME.md + mobile/CONNECTION_PLAN.md pour comprendre l'état.
```

Claude va lire `CLAUDE.md` + `PROJECT_BRAIN.md` automatiquement (cf. §5 LOOP), puis Graphiti `foodking` retrouvera le contexte mobile complet.

### 3. Lancer le serveur preview pour tester
**Option A** — Preview MCP (recommandé) :
```
Lance le serveur "mobile" et ouvre http://127.0.0.1:8081/index.html
```

**Option B** — Terminal manuel :
```bash
php -S 127.0.0.1:8081 -t mobile/
# Puis ouvrir http://127.0.0.1:8081/index.html dans Chrome/Safari
```

### 4. Démarrer le travail
Choisis selon ton besoin :

#### A. Continuer le design / corriger des écrans
```
J'aimerais retoucher l'écran X (ex: Loyalty, Cart, Profile).
Lance le preview mobile, scroll/clique pour me montrer l'état actuel,
puis applique les changements suivants : [ta demande].
Teste avec Playwright/Preview après chaque modif et corrige les white-on-white.
```

#### B. Ajouter / modifier des produits Le Cayenne
```
J'ai changé le menu dans config/menu.php (ou j'ai un nouveau produit X).
Re-aligne mobile/data/menu.js sur la nouvelle source de vérité et teste
que le wizard reflète bien les nouvelles règles (viandes/sauce/crudités/etc.).
```

#### C. Lancer Phase 6 — connexion réelle backend (Supabase ou FoodKing)
```
OK on lance Phase 6 chemin A (Supabase).
Bootstrap le projet Supabase, exécute le schéma SQL de CONNECTION_PLAN.md §2,
crée mobile/api/api.js (fetch wrapper + bearer), remplace data/*.js par
des calls API. Commit par phase.
```

ou bien :
```
OK on lance Phase 6 chemin B (Backend FoodKing existant).
Crée le endpoint /api/v1/frontend/menu/customer + ability mobile:order,
puis branche mobile/api/api.js sur les endpoints existants.
```

#### D. Push App Store / Play Store (Phase 11)
```
On wrappe l'app en natif Capacitor (per CONNECTION_PLAN §4 Option A) :
bootstrap capacitor + plugins (Camera, Push, Haptics), build iOS + Android,
prépare les screenshots App Store et les métadonnées.
```

---

## 📁 Fichiers à connaître

| Fichier | Rôle |
|---|---|
| [`mobile/index.html`](index.html) | Entry mobile-only (boot splash/login/home selon auth) |
| [`mobile/Le Cayenne - Prototype.html`](Le%20Cayenne%20-%20Prototype.html) | Prototype Claude Design avec nav 17 écrans (debug only) |
| [`mobile/data/menu.js`](data/menu.js) | Catalog Le Cayenne (13 cats / 60 produits / SSOT) |
| [`mobile/data/loyalty.js`](data/loyalty.js) | Points + 6 rewards + history mock |
| [`mobile/data/orders.js`](data/orders.js) | 1 active + 5 history mock |
| [`mobile/data/user.js`](data/user.js) | Profil + greeting helper |
| [`mobile/api/storage.js`](api/storage.js) | localStorage helpers (auth, cart, onboarding) |
| [`mobile/screens-onboarding.jsx`](screens-onboarding.jsx) | Splash + Onb1-4 + Login + OTP |
| [`mobile/screens-main.jsx`](screens-main.jsx) | Home + Menu + ScreenItem (wizard kiosk) + Cart + Confirm + Orders + Profile + Loyalty |
| [`mobile/screens-modals.jsx`](screens-modals.jsx) | Modals + Toast + OrderDetail |
| [`mobile/shared.jsx`](shared.jsx) | TabBar + IconBtn + ScreenHeader + Slot + QRMock + Logo |
| [`mobile/icons.jsx`](icons.jsx) | Icon library (Home, Menu, Receipt, User, Heart, etc.) |
| [`mobile/styles.css`](styles.css) | Design tokens (couleurs --orange/--yellow/--ink, typo, layouts) |
| [`mobile/CONNECTION_PLAN.md`](CONNECTION_PLAN.md) | Plan migration Supabase + audit FoodKing |
| [`mobile/HOW_TO_RESUME.md`](HOW_TO_RESUME.md) | Ce fichier |

---

## 🔧 Stack technique V0

- **Frontend** : React 18 + Babel-standalone (compilation in-browser, pas de build step)
- **Servage** : PHP built-in server (`php -S`) ou n'importe quel static server
- **Stockage local** : localStorage (auth token mock, cart, onboarding flag)
- **Mobile preview** : iframe iPhone 390×844 sur desktop, full bleed sur vrai mobile
- **Pas de** : npm install, pas de build, pas de transpilation, pas de bundler

**Pour Phase 11 (natif)** → migration prévue vers **Capacitor + Vue 3** ou **React Native + Expo** (cf. CONNECTION_PLAN.md §4).

---

## 🎯 Logique de prise de commande (kiosk-aligned)

Pour chaque produit, le ScreenItem applique automatiquement :

| Flag dans data/menu.js | Section affichée | Validation |
|---|---|---|
| `viandes: N > 0` | Choisis N viandes (9 options) | CTA disabled tant que count ≠ N |
| `has_sauce: true` | Sauce (15 options) | 1ʳᵉ gratuite, sup 0,50 €/sauce additionnelle. "Sans Sauce" exclusive |
| `has_crudites: true` | Crudités (Salade/Tomate/Oignon) | Toggle, default ON (✓ vert vs ✕ barré) |
| `has_supplements: true` | Suppléments (7 items) | Toggleable, prix unitaire (1 € ou 0,50 € sauce) |
| `has_menu_addon: true` | Faire un menu ? | Radio (Sans formule / Menu +3 € / Frites +2 € / Boisson +2 €) |

**Boissons / Desserts / Sides → aucun wizard**, juste qty stepper + Ajouter au panier.

---

## ✅ Tests déjà passés (preuves Preview MCP)

- ✅ 18 surfaces auditées white-on-white → **0 offenders** (alpha-blending parents)
- ✅ Splash + Onb1-4 + Login + OTP 1234 auto-submit + redirect home
- ✅ Home : BONJOUR/BONSOIR auto + featured Tacos XXL 12,50 € (vrai item)
- ✅ Menu : 13 catégories / 60 produits / filter chips
- ✅ Tacos XXL : 4 viandes obligatoires → CTA enabled après 4 picks
- ✅ Pricing combo : Tacos XXL + Menu(+3) + Œuf(+1) + 2ème sauce(+0,50) = **17,00 €**
- ✅ Coca-Cola : pas de wizard, ajout direct 1,50 €
- ✅ Sandwich Le Cayenne : "Choisis 1 viande" disabled
- ✅ Cart : qty +/- avec clamp 1, trash → empty state
- ✅ Modals : ModalPayChoice → caisse/Stripe ; ModalPointsGain +25 ; ModalRedeem → toast ; ModalCardLink → toast
- ✅ Order history → OrderDetail dynamique (#C-1212 vs #C-1234 montrent vraies données)
- ✅ Logout : auth+cart cleared, redirect Login

---

## ⚠️ Décisions ouvertes (owner gate avant Phase 6)

| ID | Question | Recommandation |
|---|---|---|
| D1 | Supabase ou Backend FoodKing existant ? | **Supabase** (mobile B2C, auth OTP out-of-box, Realtime, ~25 €/mois) |
| D2 | Provider SMS OTP | Twilio (€0.05/SMS) ou MessageBird (€0.04) |
| D3 | Build natif ? | Capacitor + Vue 3 (réutilise patterns kiosk) ou rester PWA |
| D4 | Paiement card | Stripe (international) ou PayPlug (FR + NF525-compliant) |
| D5 | Carte plastique fidélité | Production batch + table `loyalty_physical_cards` |

Dis-moi quelle décision tu prends et je lance la phase correspondante.

---

— *Bonne reprise. Tout est sauvegardé, isolé, prêt à brancher.*
