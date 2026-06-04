# Handoff Claude Design — Refonte Dashboard `/admin` Le Cayenne

**Date** : 2026-05-21
**Demandé par** : owner FoodKing
**Scope** : redesign Tableau de Bord `/admin` (+ sidebar reorganization)
**Branche** : `heal/cms-pr1-quickwins-2026-05-18`
**Screenshot état actuel** : `reports/handoff/dashboard-current-2026-05-21.png`

---

## 0. Mission (verbatim owner, traduit FR clean)

> « La page principale (Dashboard `/admin`) est trop classique, c'est pas beau pour un restaurant fast-food avec l'énergie d'un fast-food. C'est compliqué, plein de fonctions qu'on n'utilise pas actuellement, tous affichés au même endroit. Réorganise sous catégories. Les 2 boutons principaux que j'utilise vraiment quotidiennement c'est **gestion de stock** et **gestion de caisse** (la caisse est déjà en cours de refonte par un autre agent — NE PAS y toucher). Génère un meilleur design fast-food avec couleurs, boutons, vraiment tout en termes de design. **C'est pas les pages de système** (POS, caisse, écran cuisine, page de suivi commandes) que je parle, **ça reste INTOUCHABLE**, c'est juste les pages de gestion globale de Dashboard. »

---

## 1. ZONES INTOUCHABLES (ne pas redesigner)

Ces surfaces ont leur design opérationnel propre et SONT validées owner ou en cours de refonte ailleurs :

| Surface | Raison intouchable |
|---------|--------------------|
| `/admin/pos` (Caisse / Point of Sale) | Design opérationnel cashier — frozen-zone CLAUDE.md §7 sur `PaymentComponent.vue` |
| `/kds` (Écran Cuisine / Kitchen Display System) | Bump-board chef — design validé après refonte Wave U/Wave X3 |
| `/admin/pos-orders-tracker` + `/order-status-screen` (Suivi commandes / OSS) | Kanban + écran client — design intentionnel |
| `/admin/cash-overview` (Gestion Caisse Unifiée) | **EN COURS de refonte par un autre agent** — ne pas modifier |
| `public/js/pos-wizard.js` + `public/css/pos-wizard.css` (POS Vanilla JS wizard) | Frozen-zone §7 — design parfait selon owner |
| Composants `kiosk/*` (KioskWizardComponent / KioskApp / KioskUpsell) | Frozen-zone §7 |

**Si tu touches une de ces surfaces = stop et escalate au coordinator.**

---

## 2. ZONES À REDESIGNER

### 2.1 Tableau de Bord `/admin` (route principale post-login)

Screenshot état actuel : `reports/handoff/dashboard-current-2026-05-21.png`

**Composition actuelle (de haut en bas)** :
1. Header avec logo Le Cayenne + sélecteur filiale + langue + 2 icônes + profil admin
2. Sidebar gauche flat avec ~17 entrées toutes étalées (Tableau De Bord, POS, Produits & Stock, Catalogue, Attribut D'articles, Ingrédients, Commandes Caisse, Écran Cuisine, Suivi Client, Notification Pushs, Messages, Abonnés, Administrateurs, Employés, Chefs, Transactions, Rapport Des Ventes, Rapport Articles, Paramètres)
3. Salutation "Bonjour Admin Le Cayenne"
4. Section "Accès rapides" : 8 chips horizontaux (POS / Commandes caisse / Suivi caisse (kanban) / Écran cuisine / Suivi client / Catalogue / Ingrédients / Produits & Stock / Rapport caisses quotidien / Vue caisse unifiée)
5. Section "Vue D'ensemble" : 3 KPI cards (Total ventes 479,60€ / Total commandes 10 / Total articles menu 45)
6. Section "Suivi en direct" : 3 cards colorées dégradés (CA Jour 409,60€ vert / Commandes Jour 30 bleu / Ticket Moyen 13,65€ violet)
7. Section "Alertes SLA (Cuisine > 15min)" : liste tickets en retard rouge
8. Section "Répartition par Canal" : barres horizontales Web 0% / Kiosk-App 36,67% / POS 63,33%

**Problèmes identifiés** :
- Trop de chips Accès Rapides — pas hiérarchisés
- KPI cards visuellement bruyantes (3 dégradés vifs côte à côte = saturation)
- Sidebar flat = 17 items au même niveau hiérarchique = paralysie de choix
- Pas d'identité fast-food (couleurs, énergie, urgence visuelle)
- Pas de prioritisation visuelle des 2 fonctions critiques owner : **STOCK** + **CAISSE**

### 2.2 Sidebar globale (toute l'app sauf pages système intouchables)

Sidebar actuelle (verbatim de la capture, du haut au bas) :
```
TABLEAU DE BORD
POS
Produits & Stock
Catalogue
Attribut D'articles
Ingrédients
Commandes Caisse

CAISSE ET COMMANDES
Écran Cuisine
Suivi Client

COMMUNICATIONS
Notification Pushs
Messages
Abonnés

UTILISATEURS
Administrateurs
Employés
Chefs

COMPTES
Transactions

RAPPORTS
Rapport Des Ventes
Rapport Articles

CONFIGURATION
Paramètres
```

→ 17 entrées, 6 catégories majuscules, hiérarchie plate.

---

## 3. PROPOSITION DE RÉORGANISATION (concept à raffiner par Claude Design)

### 3.1 Hiérarchie sidebar recommandée

**Niveau 1 (toujours visibles, 4-5 items max)** : actions quotidiennes critiques
- 🏠 Tableau de Bord
- 🍔 Caisse (POS) ← entrée vers /admin/pos (intouchable, juste un lien)
- 📦 **Stock & Catalogue** (priorité owner) → submenu expand
- 💶 **Caisse & Comptes** (priorité owner) → submenu expand
- 🍳 Cuisine (KDS) ← lien (intouchable)

**Niveau 2 (regroupés sous accordions ou drawer)** :
- **Stock & Catalogue** : Produits & Stock / Catalogue / Attribut D'articles / Ingrédients
- **Caisse & Comptes** : Commandes Caisse / Vue caisse unifiée / Transactions / Suivi client (lien sortie)
- **Rapports** : Rapport des Ventes / Rapport Articles / Rapport Caisses Quotidien
- **Équipe** : Administrateurs / Employés / Chefs
- **Communication** : Notifications / Messages / Abonnés
- **Configuration** : Paramètres généraux

### 3.2 Dashboard layout fast-food vibe

**Header hero** (zone d'identité fast-food, ~120-160px) :
- Salutation chaleureuse "Bonsoir K !" (heure du jour adaptive + prénom court)
- Stat hero unique : "💶 CA jour : 409,60€" en gros, type-display, brand Cayenne red
- Subtle : "+12% vs hier" si data dispo (sinon retirer)

**Bloc PRIORITY ACTIONS (les 2 que l'owner utilise) — zone large 2 cards parallèles ~280-320px haut** :
- Card 1 **STOCK** (gauche)
  - Compteur visuel : produits en rupture / total
  - Mini-graph alertes stock 7j (sparkline)
  - CTA : "Voir le stock"
  - Couleur : ambre/orange-foncé (alerte mais accueillant fast-food)
- Card 2 **CAISSE** (droite)
  - Compteur visuel : sessions ouvertes / réconciliation diff
  - Mini total cash today + nombre transactions
  - CTA : "Voir la caisse" → `/admin/cash-overview`
  - Couleur : vert-jaune brand (énergie cash, pas trop austère)

**Bloc KPI rapide** (3 cards plus discrètes ~80px) :
- Commandes du jour
- Ticket moyen
- Top item du jour (NEW — ajouterait du juice fast-food)

**Bloc OPÉRATIONS LIVES** (split 2 colonnes) :
- Gauche : Alertes SLA cuisine (déjà présent, garder mais styled fast-food)
- Droite : Répartition par canal (déjà présent, simplifier visuel)

**Footer/Quick Links** (chips secondaires, ~60px) :
- Notification push / Messages / Configuration (mini chips discrets, pas en haut)

### 3.3 Palette & énergie fast-food (suggestion à challenger)

L'identité Le Cayenne brand existante :
- Rouge Cayenne `#F4501E` (primary CTA — déjà partout)
- Noir / blanc neutres

**Ajouts proposés pour fast-food energy** :
- Jaune sunshine `#FFC107` ou `#FFD60A` (énergie, accent secondaire pour Stock-card)
- Vert frais `#10B981` ou `#22C55E` (réussite cash, accent secondaire pour Caisse-card)
- Gradients SUBTILS uniquement (pas saturés comme l'actuel rose/violet/bleu/vert dégradé)
- Typo : envisager une display font pour les chiffres hero (e.g. `Manrope`, `Sora`, ou stay avec `Inter` mais en weight 800 + size 56-72px)
- Iconographie : préférer icônes lucide-react filled, jamais line, pour le poids visuel fast-food
- Coins arrondis 16-24px (vs 8-12px actuel) — plus chaleureux fast-food

**À éviter** :
- Dégradés rose-violet vifs (looks SaaS B2B classique, pas fast-food)
- Trop d'icônes différentes sur Accès rapides — favoriser 2-3 vraies actions + un "..."
- Sidebar 17 items flat

---

## 4. CONTRAINTES TECHNIQUES (ne pas violer)

| Contrainte | Détail |
|------------|--------|
| **Frozen zones §7** | Aucun touch sur `PaymentComponent.vue`, `PosV5TrancheRow.vue`, kiosk components, `pos-wizard.js`, `pos-wizard.css`, `OrderStateMachine.php`, fiscal services, `BranchScope.php`, `IdempotencyKeyMiddleware.php`, `PricingService.php` |
| **NF525** | Aucun changement à la chaîne audit_logs / z_reports. Les rapports/ventes restent reliés au backend SSOT existant. |
| **Backend SSOT** | Frontend = lecture + navigation. Toutes les valeurs (CA jour, stock counts, etc.) viennent des endpoints existants — pas de calcul frontend des chiffres business. |
| **Vue 2 + Vuex** | Stack actuel. Pas de migration Vue 3 / Pinia / autre framework dans ce ticket. |
| **i18n** | Toutes les copies en clés `resources/js/languages/{fr,en,ar}.json` — pas de hardcoded text. Ajouter des clés si besoin, jamais inliner. |
| **BranchScope multi-tenant** | Le dashboard montre les données de la filiale active uniquement, sauf admin global (branch_id=0) qui peut switcher. |
| **Responsive** | Cible desktop / tablette landscape (caissier souvent en zone admin sur tablette). Mobile = nice-to-have V2. |
| **A11y** | WCAG 2.1 AA minimum — contrast 4.5:1 sur texte, focus-visible, aria-label sur icônes |

---

## 5. LIVRABLES ATTENDUS DE CLAUDE DESIGN

1. **Mockup Figma ou capture annotée** du Dashboard redesigné (desktop 1440×900 minimum)
2. **Mockup sidebar** (collapsed + expanded states)
3. **Tokens design** mis à jour : `resources/css/tokens.css` ou équivalent — palette, spacing, radius, typography scales
4. **Composants Vue à modifier ou créer** :
   - `resources/js/components/admin/dashboard/DashboardComponent.vue` (refonte layout)
   - `resources/js/components/layout/AdminSidebar.vue` (ou équivalent — réorganisation hiérarchique + accordion)
   - Nouveaux atoms si justifiés : `PriorityActionCard.vue` (Stock + Caisse), `KPIStripCard.vue`, etc.
5. **Spec écrite** des changements en termes de scope (1 page max) listant fichiers touchés
6. **Visual diff** before/after capture

**Ce qui n'est PAS attendu** :
- Toucher aux pages POS / KDS / OSS / `/admin/cash-overview`
- Modifier la logique business (pas de calcul, pas de modification d'endpoint)
- Refactor architecture Vuex / routing globale

---

## 6. CONTEXTE CYCLE (état FoodKing au 2026-05-21)

- Branche actuelle `heal/cms-pr1-quickwins-2026-05-18` HEAD `0d9f7c141` (après Wave Y rate-limit fix)
- V1 Le Cayenne LOCAL **production-ready** post Wave K→Wave Y
- 5 commits Wave X (X1-X4 owner-mandated) + 2 commits Wave Y rate-limit shipped today
- Frozen-zone diff = 0 sur tous les §7 files (verified)
- NF525 chain integrity preserved
- 3 GREEN convergence rounds across X1+X2 + X3 + X4 via /test-e2e adversarial cycle

**Lecture prerequisite** pour cohérence cross-cycle :
1. `CLAUDE.md` (racine projet) — §7 frozen zones + §8 NF525 + §11 memory discipline
2. `PROJECT_BRAIN.md` (racine projet) — §1 NORTH STAR + §2 CURRENT STATE
3. `reports/test-e2e/wave-x-2026-05-21/CONVERGENCE_FINAL.md` — cycle most récent
4. `docs/ARCHITECTURE.md` — stack overview (si présent)

---

## 7. WORKFLOW SUGGÉRÉ POUR CLAUDE DESIGN

```
1. Read context (CLAUDE.md + PROJECT_BRAIN.md + this handoff)
2. Examine reports/handoff/dashboard-current-2026-05-21.png
3. Sketch mockup wireframe text/ASCII first → present to owner via AskUserQuestion
4. On owner go → high-fidelity mockup (Figma/PNG)
5. On owner go → implement Vue + CSS + tokens (scope-minimal)
6. Visual test via Playwright capture
7. Show before/after to owner
8. Commit with message : design(dashboard): fast-food refresh + sidebar hierarchy
```

---

## 8. QUESTION POUR OWNER (à poser avant de démarrer high-fidelity)

Recommandation : Claude Design pose ces 4 questions à l'owner avant la mockup haute-fidélité :

1. **Tonalité brand** : Tu préfères énergique-jeune (jaune+rouge vif, gros chiffres playful) ou premium-fast-food (rouge Cayenne dominant + neutres chauds, type-display sobre) ?
2. **Cibles primaires** desktop laptop OR tablette landscape OR les deux paritairement ?
3. **Stock priority card** : tu veux y voir quoi en 3 chiffres max (rupture / sous-seuil / total disponible) ?
4. **Caisse priority card** : tu veux y voir le CA jour ou plutôt sessions ouvertes / nombre transactions / dernière clôture ?

---

## Annexe — fichiers à connaître

- `resources/js/router/modules/*.js` — routes admin module
- `resources/js/components/admin/dashboard/` — dashboard components actuels (audit avant modif)
- `resources/css/tokens.css` ou `resources/scss/variables.scss` (selon stack) — design tokens
- `webpack.mix.js` — entry points compilation (admin-app.js inclut dashboard)
- `tests/js/sentinels/` — sentinelles à ne pas casser (lire avant refactor)

---

**EOF — Handoff prêt à transmettre à Claude Design.**
