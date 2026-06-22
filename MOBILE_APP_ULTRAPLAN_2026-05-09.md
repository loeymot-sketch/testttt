# 🎯 ULTRAPLAN — Mobile App Le Cayenne + Loyalty Handoff
**Date** : 2026-05-09
**Owner** : Le Cayenne
**Orchestrateur** : Claude Code (YC GStack)
**Cible** : Claude Design (~1h crédit) → produit design hi-fi → ré-import projet pour dev

---

## §0 — TL;DR

✅ **2 fichiers livrés à la racine du projet** :
1. `MOBILE_APP_BRIEF_FOR_CLAUDE_DESIGN.md` → **À COPIER-COLLER chez Claude Design** (brief complet, 22 écrans, branding, components, loyalty intégré)
2. `MOBILE_APP_ULTRAPLAN_2026-05-09.md` → **CE FICHIER** (master plan owner-facing, instructions de bout en bout)

✅ **Décision orchestrateur** : **1 SEUL brief unifié** (mobile app + loyalty intégré dans Profil) au lieu de 2 séparés. **Économie ~30% du crédit Claude Design.**

✅ **Backend prêt** : 730 LOC `LoyaltyController` + Sanctum auth + OTP signup + endpoints menu/order/profile EXISTENT déjà. Aucun blocker. App mobile = nouvelle couche front sur backend existant.

---

## §1 — Pourquoi 1 brief unifié et pas 2

### Décision rationale

| Critère | 1 brief unifié (chosen) | 2 briefs séparés |
|---|---|---|
| Crédit Claude Design | ~1h suffit | ~1h45 (faut redéfinir contexte 2x) |
| Cohérence design | ✅ Même branding partout | ⚠️ Risque drift visuel |
| Loyalty = section app | ✅ Naturel (Profil > Carte fidélité) | ⚠️ Forçage si app séparée |
| Backend partage auth | ✅ Sanctum unique | ✅ Sanctum unique |
| Effort dev intégration | ✅ 1 codebase mobile | ⚠️ 2 codebases séparés |
| Time-to-market | ✅ V1 plus rapide | ⚠️ +1-2 semaines |

**Verdict YC GStack** : 1 brief = **bon choix pour ton crédit limité ET pour la cohérence produit**. La fidélité est intrinsèquement liée à l'identité utilisateur — la séparer serait artificiel.

### Si tu veux quand même 2 briefs plus tard

Le fichier `MOBILE_APP_BRIEF_FOR_CLAUDE_DESIGN.md` peut être splitté en 2 :
- **Brief #1** : sections 1-8 + 10-13 (app sans fidélité)
- **Brief #2** : section 5 (loyalty détaillé) + extraits 1-2 pour partager le branding

→ NE FAIS PAS ÇA pour l'instant. Garde 1 brief unifié, c'est optimal.

---

## §2 — Procédure pour utiliser le brief avec Claude Design

### Étape 1 — Copier le brief
```bash
# Ouvre le fichier
open /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/MOBILE_APP_BRIEF_FOR_CLAUDE_DESIGN.md

# Sélectionne TOUT (Cmd+A)
# Copie (Cmd+C)
```

### Étape 2 — Lancer Claude Design
- Va sur claude.ai/design (ou ton interface Claude Design)
- Crée nouvelle conversation
- Colle le brief ENTIER (Cmd+V)
- Ajoute un message : *"Génère les 22 écrans en hi-fi mobile portrait iOS, plus la card library de design tokens. On a ~1h de crédit, priorise les écrans core (sections 4 + 5)."*

### Étape 3 — Joindre les 23 screenshots Pop's
- Drag-and-drop les 23 captures Pop's Villepinte du Reel Instagram que tu m'as envoyées
- Légende : *"Voici la référence visuelle Pop's Villepinte que je veux mirror (DNA, pas copie). Section 12 du brief explique."*

### Étape 4 — Récupérer la livraison
- Claude Design devrait produire :
  - Figma file ou inline mockups des 22 écrans
  - Design tokens JSON (colors, typo, spacing)
  - Component library
  - Asset exports
- **Save tout** dans un dossier `claude-design-output/` à la racine du projet

### Étape 5 — Suite (post-Claude-Design, avec moi)
- Dis-moi *"OK design reçu, lance l'intégration"*
- Je créerai un **plan d'implémentation mobile dev** :
  - Stack proposé : **React Native** ou **Vue Native** ou **Capacitor + Vue 3** (cf. §4 ci-dessous)
  - Bootstrap projet mobile
  - Wire-up des endpoints backend existants
  - Auth flow (OTP → Sanctum)
  - Loyalty QR generation (HMAC-signed)

---

## §3 — Ce qui est dans le brief (récap pour ton info)

### 22 écrans couverts
1. Splash (1)
2. Onboarding (4 screens)
3. Login phone (1)
4. Code entry (1)
5. Home / Accueil (1)
6. Menu list (1)
7. Item detail (1)
8. Cart (1)
9. Payment choice (1)
10. Confirmation pickup (1)
11. Orders en cours (1)
12. Orders history (1)
13. Profil home (1)
14. Loyalty card detail (1)
15. Loyalty rewards tab (1)
16. Loyalty history tab (1)
17. Reward redemption modal (1)
18. Physical card linking (1)
19. Settings sub-screens (allergens, notifications, payment methods) — groupés (3)

### Système couvert
- ✅ Visual identity (yellow #FFD93D + black, condensed bold typo, friendly FR copy)
- ✅ Component library (buttons, pills, cards, inputs, tabs, modals)
- ✅ Navigation 4-tabs (Accueil/Menu/Commandes/Profil)
- ✅ User flows (4 flows : first-order, repeat, redeem points, show QR counter)
- ✅ Backend constraints (endpoints existants Le Cayenne)
- ✅ Loyalty system détaillé (QR, points, rewards, physical card link)
- ✅ Out-of-scope V1 (delivery, dine-in, multi-locale → ADR-007)

---

## §4 — Stack mobile recommandé (post-design)

Quand tu auras les designs Claude Design, voici les 3 options pour le dev :

### Option A — **Capacitor + Vue 3** *(je recommande pour Le Cayenne)*
- ✅ **Réutilise 70% du code Vue 3 existant** du kiosk (`KioskWizardComponent`, components)
- ✅ Dev rapide (équipe déjà Vue)
- ✅ Backend 100% compatible (mêmes endpoints Sanctum)
- ✅ Build iOS + Android depuis 1 codebase
- ⚠️ Performance native légèrement < React Native sur listes longues (ok pour app commande)
- **Effort estimé** : 3-5 semaines pour V1 complet

### Option B — **React Native + Expo**
- ✅ Performance native excellente
- ✅ Écosystème mobile mature (camera, notifications, QR scan)
- ❌ **Codebase totalement séparé du kiosk Vue** (zéro réutilisation)
- ❌ Apprentissage React si équipe Vue
- **Effort estimé** : 5-7 semaines pour V1 complet

### Option C — **Flutter**
- ✅ Performance excellente
- ✅ Material Design + Cupertino natifs
- ❌ Dart language (apprentissage)
- ❌ Zéro réutilisation Vue
- **Effort estimé** : 6-8 semaines

**Verdict YC GStack** : Option A (**Capacitor + Vue 3**). Time-to-market + réutilisation kiosk + équipe Vue = combo gagnant V1.

---

## §5 — Système fidélité — architecture technique (post-design)

Voici comment je connecterai le design Claude Design au backend existant :

### Backend (déjà existant, 0 LOC à écrire pour V1)

```
Endpoints actuels LoyaltyController.php (730 LOC) :
  GET    /api/v1/frontend/loyalty/check        → vérifie utilisateur fidélité
  POST   /api/v1/frontend/loyalty/register     → enregistre nouveau membre
  POST   /api/v1/frontend/loyalty/add-points   → cashier ajoute points (POS)
  POST   /api/v1/frontend/loyalty/redeem       → utilise récompense
  GET    /api/v1/frontend/loyalty/balance      → solde points actuel
  POST   /api/v1/frontend/loyalty/opt-in       → opt-in programme
  GET    /api/v1/frontend/loyalty/config       → config admin (seuils, ratio)
  GET    /api/v1/frontend/loyalty/history      → historique points
  POST   /api/v1/frontend/loyalty/scan         → POS scan QR mobile (cashier-side)
```

### Mobile app (à dev, post-design)

```
Mobile screens → Backend endpoints :
  Profil > Carte fidélité  → GET /balance (refresh on focus)
  Onglet Historique        → GET /history (paginated)
  Onglet Récompenses       → GET /config + GET /balance
  Bouton "Utiliser"        → POST /redeem
  Affichage QR             → calcul local : `LECAY-LOYALTY-{user_id}-{hmac}` 
                             (HMAC signé côté backend, expiration 5min, refresh auto)
```

### Carte plastique (option utilisateur)

Quand l'utilisateur scanne sa **carte plastique** au comptoir :
1. Cashier POS scanne QR plastique → backend `POST /loyalty/scan` avec `card_id`
2. Backend crée OU lookup `loyalty_account` lié au card_id
3. Si l'utilisateur a aussi le mobile app → backend lie les 2 (`POST /loyalty/link-card` à ajouter)
4. Désormais : QR mobile + QR plastique = même compte = mêmes points

**Migration backend nécessaire pour V1.x post-mobile** :
- Ajouter table `loyalty_physical_cards` (card_id, user_id nullable, batch_id, issued_at)
- Endpoint `POST /loyalty/link-card` (mobile-side) + `POST /loyalty/issue-card` (admin-side)

---

## §6 — Calendrier prévisionnel V1 mobile

| Phase | Durée | Action |
|---|---|---|
| **Phase 1** : Design Claude Design | 1-2h (today) | Tu prends le brief, lance Claude Design, récupère les designs |
| **Phase 2** : Validation owner | 1 jour | Tu reviews les designs, ajustes si besoin (chat avec Claude Design) |
| **Phase 3** : Bootstrap mobile (Capacitor + Vue 3) | 1 semaine | Setup projet, intégration design tokens, navigation 4-tabs |
| **Phase 4** : Auth + onboarding | 1 semaine | OTP login, Sanctum, splash, onboarding 4 screens |
| **Phase 5** : Catalog + cart | 1 semaine | Menu, item detail, cart, checkout (pickup-only) |
| **Phase 6** : Loyalty + Profil | 1 semaine | QR generation, balance/history/rewards, redeem flow |
| **Phase 7** : Tests + polish | 1 semaine | E2E tests Playwright mobile (Detox optionnel), bug fixes |
| **Phase 8** : Submit App Store + Play Store | 3-5 jours | Provisioning, screenshots App Store, review |
| **TOTAL V1** | **~5-6 semaines** post-design | |

---

## §7 — Checklist post-Claude-Design (pour quand tu reçois les designs)

- [ ] Designs Claude Design reçus (Figma ou inline)
- [ ] Design tokens JSON exportés (colors, typo, spacing)
- [ ] Component library accessible
- [ ] Asset exports (icônes, photos placeholder)
- [ ] Animations Lottie pour splash + celebrations (optionnel)
- [ ] Mockup carte plastique fidélité (front + back) — bonus
- [ ] Mode dark variant (optionnel V1.1)
- [ ] Tu m'envoies *"OK design reçu, lance l'intégration"* → je bootstrappe le projet mobile

---

## §8 — Risques + mitigations

| Risque | Probabilité | Mitigation |
|---|---|---|
| Claude Design dépasse 1h crédit | Moyenne | Brief priorise core 22 écrans (section 4-5). Bonus 6-7 zone si temps |
| Designs ne respectent pas backend constraints | Faible | Section 9 du brief lists endpoints + payload structure |
| Loyalty QR HMAC sécurité | Faible | Backend `LoyaltyController::scan` (line 575) déjà valide HMAC |
| Multi-langue V1 vs FR-lock | Bloqué V1 | Section 11 du brief = ADR-007 FR-lock active V1 |
| Pickup-only V1 vs delivery confusion | Faible | Section 11 du brief explicit "no delivery V1" |
| Carte plastique batch | Moyenne | Migration `loyalty_physical_cards` à add post-design (V1.x) |

---

## §9 — Owner action items immédiats

### Maintenant (avant que ton crédit Claude Design expire)
1. ✅ Lis ce ULTRAPLAN
2. ✅ Ouvre `MOBILE_APP_BRIEF_FOR_CLAUDE_DESIGN.md`
3. ✅ Cmd+A → Cmd+C
4. ✅ Va sur Claude Design, paste le brief
5. ✅ Drag-drop les 23 screenshots Pop's
6. ✅ Lance la génération
7. ⏳ Attends 30-60 min selon dépendant Claude Design

### Une fois designs reçus
8. ⏳ Sauvegarde les designs dans `claude-design-output/` à la racine
9. ⏳ Reviens me dire *"OK design reçu"* → je bootstrap le projet mobile

### Après bootstrap
10. ⏳ Validation visuelle de la V0 mobile (1 semaine après design)
11. ⏳ Itérations bug fixes + UX polish
12. ⏳ Submit App Store + Play Store

---

## §10 — Files à supprimer après usage

Comme tu m'as dit : *"Write me a copy of the files that are necessary, put them in the first, in the main file of the project, as a copy, that I will take them, then I will delete them."*

→ Une fois que tu as donné le brief à Claude Design + récupéré les designs :

```bash
cd /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/

# Supprime les 2 fichiers handoff (ils ne servent plus)
rm MOBILE_APP_BRIEF_FOR_CLAUDE_DESIGN.md
rm MOBILE_APP_ULTRAPLAN_2026-05-09.md

# OU déplace-les dans plans/ pour archive
mkdir -p plans/mobile-app-handoff
mv MOBILE_APP_BRIEF_FOR_CLAUDE_DESIGN.md plans/mobile-app-handoff/
mv MOBILE_APP_ULTRAPLAN_2026-05-09.md plans/mobile-app-handoff/
```

**Je recommande l'archive** (`mv plans/mobile-app-handoff/`) plutôt que `rm` — pour garder trace si tu veux re-générer plus tard ou auditer le brief.

---

## §11 — Notes pour l'orchestrateur (moi, Claude Code)

Quand l'utilisateur revient avec *"OK design reçu, lance l'intégration"* :

1. Read le dossier `claude-design-output/` pour comprendre les designs livrés
2. Lance 4 sub-agents en parallèle :
   - **MOBILE-STACK-AUDIT** : confirme Capacitor + Vue 3 viable, audit deps
   - **BACKEND-API-AUDIT** : vérifie tous les endpoints listés section 9 du brief sont up
   - **DESIGN-TOKENS-IMPORT** : extrait les tokens JSON et map vers Tailwind/CSS vars
   - **AUTH-FLOW-AUDIT** : audit Sanctum mobile (token storage keychain) + OTP existing
3. Bootstrap mobile project sous `mobile/` à la racine
4. Wire-up basic auth + nav + 1 écran (Home) en V0
5. Itère

---

## §12 — Conclusion

✅ **Tu as 2 fichiers prêts à utiliser** dans `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/` :
- `MOBILE_APP_BRIEF_FOR_CLAUDE_DESIGN.md` → pour Claude Design
- `MOBILE_APP_ULTRAPLAN_2026-05-09.md` → pour toi (ce fichier)

✅ **Décision unifiée 1 brief** = optimal pour ton crédit + cohérence produit

✅ **Backend déjà prêt** = aucun blocker côté API pour mobile + loyalty

✅ **Inspiration Pop's mirror** = DNA visuel + tonal capturé fidèlement dans le brief (sections 2 + 12)

✅ **Roadmap claire** : Phase 1 (design today) → Phase 8 (App Store ~6 semaines)

— *Quand tu as les designs Claude Design, reviens me voir avec "OK design reçu" et je lance le bootstrap mobile en mode YC GStack.*
