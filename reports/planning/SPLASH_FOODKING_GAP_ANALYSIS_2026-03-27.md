# Analyse Comparative Splash vs FoodKing — Gap Analysis Borne

**Date** : 2026-03-27  
**Analyste** : Claude (Architect)  
**Sources** : `frontend public/` (build Splash), `resources/js/components/frontend/kiosk/` (FoodKing Vue)

### Décision produit (2026-03-27)

- **Navigation catalogue** : conserver la **sidebar verticale + grille produits** (référence UX type McDonald’s / fast-food). Le **carrousel horizontal de catégories** type Splash n’est **pas** dans le backlog : pas de chantier associé.
- **Suite du benchmark** : voir le plan d’implémentation détaillé `reports/planning/KIOSK_SPLASH_BACKLOG_DEEP_PLAN_2026-03-27.md` (upsell par catégorie, « comme d’habitude », fidélité ticket, P2/P3).

---

## 1. Vue d'ensemble architecturale

| Dimension | Splash | FoodKing |
|-----------|--------|----------|
| **Stack** | React SPA + Electron shell | Vue 3 SPA (web) |
| **Bundle** | Webpack single bundle ~12MB | Laravel Mix chunks (kiosk.js ~1MB) |
| **Config** | `window.data` injecté serveur | `config/kiosk.php` → `window.foodkingConfig` |
| **Offline** | Non visible dans le build | IndexedDB (`kioskMenuCache.js`) + queue offline |
| **Auth** | Non visible (probablement clé API simple) | Sanctum machine token + auto-login |
| **i18n** | JSON statique `locales/{fr,gb,nl}/translation.json` | Vue-i18n dynamique + backend settings |

---

## 2. Reconstruction du flow Splash

### Flow identifié (par traductions + assets)

```
Attract (home.start: "TOUCHEZ POUR COMMANDER")
  → Type commande (sur place / à emporter)
  → Catalogue catégories (carousel Slick côté Splash ; FoodKing garde sidebar type McDo)
  → Wizard personnalisation
  → Upsell / Suggestion ("PRENDREZ VOUS AUTRE CHOSE ?")
  → Panier validation ("Votre commande est-elle exacte ?")
  → Paiement (CB / comptoir)
  → TPE intégré ("Suivez les instructions sur le lecteur")
  → Ticket / Confirmation
  → Idle timeout avec redirect forcé
```

### Points forts Splash identifiés

1. **Direct-start absolu** : aucun écran login visible, attract immédiat
2. **Attract loop** : slideshow configurable + vidéo + CTA pulsé
3. **Navigation catégorie carousel** (Slick) horizontal fluide — **non retenu** chez nous (préférence sidebar)
4. **Upsell catégorisé** : par config catégorie (`suggestion_config: {8: false, 9: false...}`)
5. **TPE intégré** : écran attente avec instructions lecteur physique
6. **Offline handling** : messages explicites + redirect caisse
7. **Loyalty intégré** : Youfid points affichés sur ticket
8. **Timeout session** : compte à rebours visible avant retour accueil
9. **PMR mode** : header séparé pour accessibilité
10. **White-label theming** : CSS entreprise (`peper_grill.css`, `pepe_manzo.css`)

---

## 3. Mapping équivalents FoodKing

| Surface Splash | Équivalent FoodKing | Fichier clé |
|----------------|---------------------|-------------|
| Attract / Home | `KioskIdleScreenComponent.vue` | `resources/js/components/frontend/kiosk/KioskIdleScreenComponent.vue` |
| Catalogue catégories | `KioskCategoriesComponent.vue` | `resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue` |
| Wizard personnalisation | `KioskWizardComponent.vue` | `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` |
| Panier validation | `KioskCartComponent.vue` | `resources/js/components/frontend/kiosk/KioskCartComponent.vue` |
| Paiement | `KioskPaymentComponent.vue` | `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue` |
| Attente / Status | `KioskWaitingComponent.vue` | `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue` |
| Upsell | `KioskUpsellComponent.vue` | `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue` |
| Confirmation | `KioskConfirmationComponent.vue` | `resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue` |
| Global state | `kioskCart.js` / `kioskMenu.js` | `resources/js/store/modules/kioskCart.js` |
| Offline queue | `kioskOfflineQueue.js` | `resources/js/helpers/kioskOfflineQueue.js` |
| Menu cache | `kioskMenuCache.js` | `resources/js/helpers/kioskMenuCache.js` |

---

## 4. Grille d'écarts détaillée

### 4.1 Attract / Direct-start

| Pattern Splash | FoodKing actuel | Écart | Risque | Priorité |
|----------------|-----------------|-------|--------|----------|
| Slideshow promo configurable | Vidéo loop ou fallback gradient | Moyen — Splash a plus de contrôle admin sur les slides | Low | P2 |
| CTA "TOUCHEZ POUR COMMANDER" large | Pulse button similaire OK | Aligné | - | - |
| Langue switch | Sélecteur FR/EN en haut à droite | Aligné | - | - |
| PMR mode header | Non implémenté | Gap UX accessibilité | Low | P3 |

### 4.2 Catalogue & Navigation

| Pattern Splash | FoodKing actuel | Écart | Risque | Priorité |
|----------------|-----------------|-------|--------|----------|
| Carousel Slick horizontal | Sidebar vertical + grille produit | **Choix produit** : conserver la sidebar (réf. McDonald’s) — pas d’écart à combler | - | **Hors périmètre** |
| Catégories avec icônes | OK avec emojis fallback | Aligné | - | - |
| Hors-stock visuel | `hors-stock.png` détecté | Non visible dans FoodKing | Low | P2 |
| Merchandising catégories | Non visible | Gap marketing | Low | P3 |

### 4.3 Wizard & Personnalisation

| Pattern Splash | FoodKing actuel | Écart | Risque | Priorité |
|----------------|-----------------|-------|--------|----------|
| Étape viandes / sauces / toppings | Wizard multi-étapes OK | Aligné fonctionnellement | - | - |
| Progress stepper visuel | Stepper avec icônes | Aligné | - | - |
| Personnalisation texte "SANS " | `withoutPrefix` dans traductions Splash | Écart copy | Low | P2 |

### 4.4 Upsell / Suggestion

| Pattern Splash | FoodKing actuel | Écart | Risque | Priorité |
|----------------|-----------------|-------|--------|----------|
| Suggestion post-panier | `KioskUpsellComponent.vue` existe | Aligné | - | - |
| Config par catégorie (`suggestion_config`) | Non visible | **Écart majeur** — Splash permet d'activer/désactiver l'upsell par catégorie | Medium | P1 |
| Carousel produits suggérés | Carousel Slick | Écart de polish | Low | P2 |

### 4.5 Panier & Validation

| Pattern Splash | FoodKing actuel | Écart | Risque | Priorité |
|----------------|-----------------|-------|--------|----------|
| Récap visuel complet | Panier avec sélections visibles | Aligné | - | - |
| Question validation explicite | "Votre commande est-elle exacte ?" | Aligné | - | - |
| Type commande (à emporter / sur place) | Toggle dans panier | Aligné | - | - |
| "Comme d'habitude ?" | Non visible | **Écart majeur** — Splash propose la dernière commande client | Medium (loyalty/UX) | P1 |

### 4.6 Paiement

| Pattern Splash | FoodKing actuel | Écart | Risque | Priorité |
|----------------|-----------------|-------|--------|----------|
| CB vs Comptoir vs TR | 3 méthodes OK | Aligné | - | - |
| TPE intégré avec instructions | Animation TPE + message lecteur | Aligné | - | - |
| Écran attente paiement | Animation processing OK | Aligné | - | - |
| Message échec CB | "CARTE BLEUE" explicite | Écart copy/formulation | Low | P3 |
| Mode comptoir redirect | "AU COMPTOIR" clair | Aligné | - | - |

### 4.7 Post-commande & Robustesse

| Pattern Splash | FoodKing actuel | Écart | Risque | Priorité |
|----------------|-----------------|-------|--------|----------|
| Numéro commande gros | Numéro visible OK | Aligné | - | - |
| Timeout retour accueil | Timer avec redirect auto | Aligné | - | - |
| Offline banner | "Borne hors connexion" | Aligné | - | - |
| Message offline caisse | " dirigez-vous vers une caisse" | Écart — FoodKing a queue offline, pas juste un message | **FoodKing supérieur** | - |
| Ticket avec TVA détaillée | Ticket simple | Écart de détail fiscal | Low | P3 |
| Youfid points sur ticket | Loyalty intégré | Écart — FoodKing a loyalty mais pas affichage ticket | Medium | P1 |

### 4.8 Offline & Queue (FoodKing supérieur)

| Pattern FoodKing | Splash | Écart | Note |
|------------------|--------|-------|------|
| IndexedDB menu cache | Non visible | **FoodKing supérieur** | - |
| Queue offline avec retry | Non visible | **FoodKing supérieur** | - |
| Abandoned orders tracking | Non visible | **FoodKing supérieur** | - |
| Sync automatique | Non visible | **FoodKing supérieur** | - |

### 4.9 Shell & Hardware (Splash supérieur)

| Pattern Splash | FoodKing actuel | Écart | Risque | Priorité |
|----------------|-----------------|-------|--------|----------|
| Electron frameless 1080×1920 | Web SPA | **Écart majeur structurel** | High (besoin hardware) | P1 (si borne physique) |
| IPC main/renderer | Non implémenté | Pour impression/TPE natif | High | P2 |
| Ouverture tiroir caisse | Non visible | Gap hardware | Medium | P2 |

---

## 5. Synthèse par priorité

### Quick Wins UX (P1 — implémentable vite, fort impact)

1. ~~**Carousel catégories horizontal**~~ : **exclu** — sidebar conservée (décision produit).
2. **Upsell par catégorie configurable** : flags sur `item_categories` + filtrage dans `ItemController::kioskUpsell` et/ou saut d’écran (plan détaillé dans `KIOSK_SPLASH_BACKLOG_DEEP_PLAN_2026-03-27.md`).
3. **"Comme d'habitude ?"** : dernière commande client identifié (loyalty / token session anonyme) — voir plan phase B.
4. **Loyalty sur ticket** : écran confirmation déjà partiel (`pointsEarned`) ; étendre **ticket imprimé** + cohérence données API — voir plan phase C.

### Middle Changes (P2 — besoin adaptation, pas de révolution)

5. **Config slideshow admin** : permettre upload images/vidéos idle depuis admin (actuellement vidéo fixe ou gradient).
6. **Hors-stock visuel** : badge grisé sur produits épuisés (backend a déjà `status`, besoin affichage).
7. **Theming par restaurant** : CSS dynamique inline ou classes thème (actuellement `--kiosk-primary` seul).
8. **Copy/UX wording** : aligner formulations sur Splash (ex: "TOUCHEZ POUR COMMANDER").
9. **PMR mode** : header dédié accessibilité.

### Structural Changes (P3 — besoin réflexion architecture)

10. **Shell Electron** : wrapper natif pour TPE physique, imprimante, tiroir caisse (hors scope web pur).
11. **Real-time sync** : WebSocket/Pusher pour état commande temps réel (actuellement polling).
12. ~~**Slick carousel complet**~~ : **hors périmètre** (même décision que §4.2).

---

## 6. Recommandations stratégiques

### Ce qu'il faut copier de Splash (forte valeur perçue)

- **Direct-start** : déjà OK après corrections auth machine.
- **Upsell configurable** : à renforcer par catégorie (Splash `suggestion_config`) — impact AOV.
- **"Comme d'habitude"** : retention client fortement améliorée.
- **Copy explicite** : formulations Splash sont plus directes.

### Ce qu'il faut garder de FoodKing (supérieur)

- **Offline-first** : queue IndexedDB + retry est meilleur que Splash.
- **Auth machine** : plus sécurisé que l'approche probable de Splash.
- **Recalcul serveur prix** : invariants métier protégés.
- **Maintenance mode** : pas vu dans Splash.

### Ce qu'il ne faut PAS copier (risque ou inutile)

- **Shell Electron** : sauf si vraiment besoin hardware natif — notre SPA web suffit pour la plupart des bornes modernes.
- **Config `window.data` massive** : notre approche `config/kiosk.php` + API est plus propre et cacheable.

---

## 7. Test types recommandés

| Chantier | Test type | Justification |
|----------|-----------|---------------|
| ~~Carousel UX~~ | — | Hors périmètre |
| Upsell config | Kimi-test | Feature tests `kioskUpsell` + requêtes SQL / policy catégories |
| "Comme d'habitude" | Anti-Gravity | Flow cross-session nécessite données réelles |
| Theming CSS | No-test | CSS pur, revue visuelle suffit |
| Shell Electron | Anti-Gravity | Test hardware/borne physique requis |

---

## 8. Livrables proposés

1. **Plan détaillé** : `reports/planning/KIOSK_SPLASH_BACKLOG_DEEP_PLAN_2026-03-27.md` (phases, risques, tests).
2. **Backend spec** : colonnes `item_categories` pour upsell + endpoint « dernière commande » kiosk.
3. **Plan CSS theming** : variables dynamiques + classes thème.
4. **Plan Electron** : si décision de wrapper natif est prise.

---

*Fin de l'analyse — prêt pour décision GO/MODIFY/STOP sur les chantiers priorisés.*
