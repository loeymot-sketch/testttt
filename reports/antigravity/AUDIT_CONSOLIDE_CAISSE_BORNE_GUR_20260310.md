# 📋 AUDIT CONSOLIDÉ — Caisse, Borne & Benchmark GUR KEBAB

> **Date:** 10 Mars 2026  
> **Sources:** AUDIT_COMPLET_CAISSE_BORNE_20260310.md + AUDIT_PROFOND_CAISSE_BORNE_20260310.md + Analyse photos GUR KEBAB  
> **Mission:** Rapport unifié, benchmark concurrence (GUR KEBAB), idées d'amélioration, checkers de vérification.  
> **Contrainte:** Documentation uniquement — aucune modification du code.

---

## 📊 PARTIE 1 — SYNTHÈSE CONSOLIDÉE

### 1.1 Matrice des problèmes (tous rapports fusionnés)

| ID | Type | Sévérité | Description | Source |
|----|------|----------|-------------|--------|
| **D-001** | Sécurité | 🔴 Critique | Fallback prix client quand item inexistant | Audit Profond |
| **D-002** | Sécurité | 🔴 Critique | POS : variations/extras utilisent prix client | Audit Profond |
| **D-003** | Sécurité | 🔴 Haute | Routes `/api/table/*` sans auth | Audit Profond |
| **D-004** | Validation | 🔴 Haute | ValidJsonOrder accepte items sans `item_id` | Audit Profond |
| **A-001 / D-005** | Technique | 🔴 | POSComprehensiveTest : items sans `branch_id` | Complet + Profond |
| **A-002** | Technique | 🟡 | POS export : `BinaryFileResponse::status()` | Complet |
| **D-006** | Technique | 🟡 | Export POS : nom fichier "Online-Order.xlsx" | Profond |
| **A-003 / D-007** | UX | 🟡 | Token POS : obligatoire frontend, nullable backend | Complet + Profond |
| **A-004 / D-008** | UX | 🟡 | Dine-In masqué sans explication | Complet + Profond |
| **D-009** | Architecture | 🟡 | Wizard POS : interception XHR fragile | Profond |
| **D-010** | UX | 🟡 | KDS : instruction non parsée (VIANDES, SUPPLÉMENTS, FORMULE) | Profond + Suite E2E |
| **D-011** | UX | 🟡 | Kiosk : pas de confirmation vocale/visuelle forte | Profond |
| **D-012** | Données | 🟡 | Table order : `branch_id` items vient du client | Profond |
| **UX-01 à UX-05** | UI/UX | 🟡 | Token, client, récap, breadcrumb POS | Complet |
| **KUX-01 à KUX-05** | UI/UX | 🟡 | Confirmation, idle, temps d'attente Kiosk | Complet |

### 1.2 État des tests

| Suite | Résultat | Détails |
|-------|----------|---------|
| AntiGravityTest | 20/20 ✅ | Auth, isolation, prix, KDS, OSS |
| POSComprehensiveTest | 6/8 🟡 | 2 échecs : branch_id, export |
| Tests éphémères (supprimés) | 5/5 ✅ | ValidJsonOrder, table routes, etc. |

---

## 📊 PARTIE 2 — BENCHMARK GUR KEBAB (Photos fournies)

### 2.1 Parcours GUR KEBAB (reverse engineering visuel)

D'après les captures d'écran de la borne GUR KEBAB :

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  ÉCRAN PRINCIPAL                                                             │
│  Header: MON COMPTE | ALLERGÈNES                                             │
│  Sidebar: NOS BAGUETTES, FALUCHE, GALETTE, BURGER, ASSIETTES, SALADE,        │
│           MENU ENFANT, BOISSONS, DESSERTS, EXTRAS                            │
│  Footer: ABANDONNER MA COMMANDE | Panier (X articles) | PAYER                │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│  WIZARD PERSONNALISATION (ex: MENU GUR BAGUETTE MIXTE)                       │
│  Barre de progression horizontale : Étape 1 → 2 → 3 → 4 → 5 → 6 → 7         │
│  Chaque étape = icône + libellé (QUELLE SAUCE?, QUELLE CRUDITÉE?, etc.)      │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
        ┌───────────────────────────┼───────────────────────────┐
        ▼                           ▼                           ▼
   Étape 1: SAUCE            Étape 2: CRUDITÉS           Étape 3: SUPPLÉMENT
   - Grille sauces           - Avec/Sans granulaires      - Prix affichés
   - 0.00€ (inclus)          - Images ingrédients         - EN RUPTURE si indispo
   - Bouton + par option     - Bouton + par option        - Badge NOUVEAU
        │                           │                           │
        └───────────────────────────┼───────────────────────────┘
                                    ▼
   Étape 4: FRITES MENU      Étape 5: SAUCE FRITE       Étape 6: BOISSON
   - Frite menu (inclus)     - Options sauces           - Choix boisson
   - Cheddar +1.00€          - 0.00€ première            - Prix selon choix
   - Cheddar+Oignons +2.00€
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│  FOOTER WIZARD (persistant)                                                  │
│  ABANDONNER L'ARTICLE | PRÉCÉDENT | SUIVANT | Total XX.XX €                 │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 2.2 Patterns UX GUR KEBAB (à comparer avec FoodKing)

| Pattern | GUR KEBAB | FoodKing (état actuel) |
|---------|-----------|-------------------------|
| **Barre de progression** | Horizontale, numérotée (1-7), icônes par étape | Pas de breadcrumb visible "Étape X sur Y" (UX-03) |
| **Instruction** | "Veuillez choisir votre QUELLE SAUCE?" | À vérifier dans wizard |
| **Options avec images** | Chaque sauce/crudité = image + nom + prix | Wizard utilise emojis ou thumbs |
| **Prix par option** | Toujours affiché (0.00€ ou +X.XX€) | Badge "Gratuit" 1ère sauce, +0.50€ suivantes |
| **Rupture de stock** | "EN RUPTURE" en bannière rouge, item grisé | Non documenté dans FoodKing |
| **Badge NOUVEAU** | "NOUVEAU À CROQUER!" sur suppléments | Non documenté |
| **Abandon** | "ABANDONNER L'ARTICLE" (item) vs "ABANDONNER MA COMMANDE" (global) | À vérifier |
| **Total** | Toujours visible en bas à droite | Partiel selon écran |
| **MON COMPTE / ALLERGÈNES** | Header permanent | Non documenté pour Kiosk FoodKing |
| **Panier** | "X Article(s)" + 0.00€ visible | CartScreen dédié |
| **Accessibilité** | Icône fauteuil roulant | Non documenté |

### 2.3 Écarts FoodKing vs GUR KEBAB

| Écart | Impact | Priorité |
|-------|--------|----------|
| Pas de barre de progression explicite (Étape X/Y) | Utilisateur ne sait pas où il en est | Haute |
| Rupture de stock non gérée visuellement | Client peut sélectionner un item indisponible | Haute |
| Pas de badge NOUVEAU sur items/suppléments | Moins d'upsell, moins de visibilité nouveautés | Moyenne |
| MON COMPTE / ALLERGÈNES absents du header Kiosk | Conformité, fidélité, santé | Moyenne |
| Granularité crudités (Avec/Sans par ingrédient) | GUR offre "Sans Oignon", "Seulement Salade" etc. | À évaluer |
| Double abandon (article vs commande) | GUR distingue clairement | Basse |

---

## 📊 PARTIE 3 — CINQ À DIX IDÉES D'AMÉLIORATION & PROBLÈMES POTENTIELS

### Idée 1 : Barre de progression wizard (POS + Kiosk)

**Problème potentiel :** L'utilisateur ne voit pas combien d'étapes restent. GUR KEBAB affiche "Étape 3/7" avec icônes.

**Vérification :** Le `WIZARD_LOGIC_DOCUMENTATION.md` décrit les étapes par catégorie, mais le frontend n'affiche pas de breadcrumb. Le `pos-wizard.js` a une variable `currentStep` et `steps` — à vérifier si un indicateur visuel existe.

**Checker suggéré :** Test manuel — Ouvrir un Tacos L, compter les clics "Suivant" avant récap. Y a-t-il un indicateur "Étape X sur 7" visible ?

---

### Idée 2 : Gestion rupture de stock (items, extras, addons)

**Problème potentiel :** Si un supplément (ex. Cheddar) est en rupture, le client peut quand même le sélectionner. GUR KEBAB grise et affiche "EN RUPTURE".

**Vérification :** Les modèles `Item`, `ItemExtra`, `ItemAddon` ont un champ `status`. La doc `BUSINESS_RULES.md` ne mentionne pas la rupture. Les APIs frontend filtrent-elles par `status = ACTIVE` ? Un item désactivé est-il masqué ou grisé ?

**Checker suggéré :** Créer un item_extra avec `status = INACTIVE`, ouvrir le wizard — l'option doit être masquée ou grisée. Documenter le comportement attendu dans `BUSINESS_RULES.md`.

---

### Idée 3 : Badge NOUVEAU / Promo sur items et suppléments

**Problème potentiel :** GUR KEBAB met en avant "NOUVEAU À CROQUER!" sur des suppléments. FoodKing a `is_featured` sur les items. Les suppléments/extras ont-ils un champ équivalent ?

**Vérification :** `Item` a `is_featured`. `ItemExtra`, `ItemAddon` — vérifier le schéma. Si absent, pas de possibilité de badge "Nouveau" sur un supplément sans modifier le modèle.

**Checker suggéré :** Audit du schéma DB — colonnes `is_new`, `is_featured`, `badge` sur item_extras, item_addons.

---

### Idée 4 : MON COMPTE / ALLERGÈNES sur Kiosk

**Problème potentiel :** GUR KEBAB propose "MON COMPTE" (fidélité) et "ALLERGÈNES" (conformité) en header. FoodKing Kiosk — ces entrées existent-elles ?

**Vérification :** Le flux Kiosk documenté est : Auth machine → Type → Menu → Détail → Panier → Paiement. Pas de mention de compte client ou allergènes. Le Kiosk est "client non loggué" (DEVICE_FLOW).

**Checker suggéré :** Revue des écrans Kiosk Flutter — présence de boutons Compte / Allergènes. Si absents, documenter comme gap de conformité (réglementation allergènes en restauration).

---

### Idée 5 : Granularité des garnitures (Avec/Sans par ingrédient)

**Problème potentiel :** GUR KEBAB propose "Sans Oignon", "Seulement Salade", "Sans Tomate" etc. FoodKing a "Complet", "Sans Oignon", "Sans Tomate", "Sans Salade", "Aucune" (WIZARD_LOGIC). La granularité est-elle équivalente ?

**Vérification :** `pos-wizard.js` — étape garnitures. GUR permet des combinaisons (ex. Salade + Tomate sans Oignon). FoodKing a-t-il des options mutuellement exclusives ou des combinaisons ?

**Checker suggéré :** Comparer la liste des options garnitures dans `pos-wizard.js` avec les écrans GUR. Documenter les combinaisons possibles.

---

### Idée 6 : Double abandon (article vs commande)

**Problème potentiel :** GUR distingue "ABANDONNER L'ARTICLE" (annuler l'item en cours de config) et "ABANDONNER MA COMMANDE" (vider tout le panier). FoodKing a-t-il les deux ?

**Vérification :** Dans le wizard, fermer sans valider = abandon article. Dans le panier, vider = abandon commande. Le libellé et le placement sont-ils aussi clairs ?

**Checker suggéré :** Test UX — En plein wizard, existe-t-il un bouton "Annuler" ou "Abandonner" ? Au panier, "Vider le panier" ou équivalent ?

---

### Idée 7 : Total toujours visible

**Problème potentiel :** GUR affiche "Total XX.XX €" à chaque étape du wizard. FoodKing met-il à jour le total en temps réel pendant le wizard ?

**Vérification :** `renderRecapStep()` calcule le total. Mais aux étapes 1-6 (viandes, sauce, garnitures...), le total est-il affiché ? Ou seulement au récap ?

**Checker suggéré :** Test manuel — À l'étape "Sauce", après avoir coché 2 sauces, le total doit afficher +0.50€. Vérifier si c'est le cas avant d'arriver au récap.

---

### Idée 8 : Idempotency — double soumission

**Problème potentiel :** Réseau lent + double clic sur "Payer" = 2 commandes identiques. GUR et FoodKing — aucune idempotency key documentée.

**Vérification :** Les endpoints `POST /api/admin/pos`, `POST /api/frontend/order` n'ont pas de paramètre `idempotency_key` ou `client_request_id`. En cas de timeout, le client peut renvoyer.

**Checker suggéré :** Test — Simuler un délai réseau, double-cliquer "Payer". Vérifier si 2 commandes sont créées. Documenter le risque dans `docs/SECURITY_NOTES.md`.

---

### Idée 9 : Accessibilité (icône fauteuil roulant)

**Problème potentiel :** GUR affiche une icône accessibilité. Les bornes FoodKing sont-elles conformes (hauteur, contrastes, taille des zones tactiles) ?

**Vérification :** Hors périmètre code — audit physique et design. Documenter dans un futur `docs/ACCESSIBILITY.md` si absent.

**Checker suggéré :** Revue des tailles de boutons (min 44x44px recommandé), contrastes couleurs (WCAG).

---

### Idée 10 : Ordre des étapes wizard vs logique métier

**Problème potentiel :** GUR : Sauce → Crudités → Supplément → Frites → Sauce frites → Boisson. FoodKing Tacos : Viandes → Sauce → Garnitures → Suppléments → Menu → Sauce frites → Récap. L'ordre est-il optimal (ex. sauce frites après frites) ?

**Vérification :** La doc WIZARD_LOGIC décrit l'ordre. Comparer avec le parcours GUR. La "sauce frites" après "menu combo" a du sens car on choisit d'abord si on prend des frites.

**Checker suggéré :** Validation avec un utilisateur terrain — l'ordre des questions est-il naturel ?

---

## 📊 PARTIE 4 — CHECKERS DE VÉRIFICATION (Sans toucher au code)

### 4.1 Checklist manuelle — Parcours POS

| # | Vérification | Attendu | Comment vérifier |
|---|--------------|---------|------------------|
| C1 | Barre de progression | "Étape X sur Y" visible | Ouvrir Tacos L, compter les étapes |
| C2 | Total temps réel | Total mis à jour à chaque choix | Ajouter 2 sauces, vérifier +0.50€ |
| C3 | Abandon article | Bouton annuler dans wizard | Chercher "Annuler" ou "X" |
| C4 | Token vide | Message d'erreur si vide | Cliquer Payer sans token |
| C5 | Client vide | Message ou blocage | Pas de client sélectionné |
| C6 | Dine-In | Masqué ou message | Vérifier présence option |
| C7 | Instruction ticket | VIANDES, SUPPLÉMENTS, FORMULE | Commander Tacos L avec menu, imprimer |
| C8 | KDS reçoit instruction | Parsée ou texte brut | Vérifier écran cuisine |

### 4.2 Checklist manuelle — Parcours Kiosk

| # | Vérification | Attendu | Comment vérifier |
|---|--------------|---------|------------------|
| K1 | Type commande en premier | Emporter/Sur place/Livraison | Écran après idle |
| K2 | Barre de progression | Étapes visibles | Wizard item |
| K3 | Confirmation après paiement | Numéro + message | Payer, observer écran |
| K4 | Idle 3 min | Retour menu ou avertissement | Attendre 3 min |
| K5 | MON COMPTE / ALLERGÈNES | Présents ou absents | Inspecter header |
| K6 | Upsell dessert | Affiché si pas dessert | Commander sans dessert |
| K7 | Temps d'attente estimé | Affiché ou non | Après validation |

### 4.3 Checklist documentaire — Incohérences

| # | Document | Vérification |
|---|----------|--------------|
| D1 | `PosOrderRequest` vs `PosComponent` | token nullable vs obligatoire |
| D2 | `ORDER_FLOW.md` | Transitions PENDING→ACCEPT→PREPARING documentées |
| D3 | `BUSINESS_RULES.md` | Règle prix central (SSOT) respectée |
| D4 | `WIZARD_LOGIC_DOCUMENTATION.md` | Étapes par catégorie à jour avec pos-wizard.js |
| D5 | `DEVICE_FLOW.md` | Kiosk = client non loggué, panier mémoire |
| D6 | `docs/` | Rupture de stock, allergènes — mentionnés ? |

### 4.4 Tests automatisés suggérés (à créer plus tard)

| Test | Objectif |
|------|----------|
| ValidJsonOrder structure | Rejeter `[{"quantity":1}]` sans item_id |
| Item inexistant | POST order avec item_id=999999 → 422 |
| POS variations prix DB | Envoyer variation avec price=0, vérifier prix DB utilisé |
| Table route auth | POST /api/table/dining-order sans auth → 200 (documenter) |
| Idempotency | Double POST identique → 1 ou 2 commandes ? |

---

## 📊 PARTIE 5 — RECOMMANDATIONS PRIORITAIRES

### P0 — Critique (sécurité)

1. **D-001** — Rejeter commande si item inexistant (pas de fallback prix client)
2. **D-002** — POS : utiliser ItemVariation::find / ItemExtra::find pour les prix
3. **D-004** — ValidJsonOrder : exiger item_id, quantity

### P1 — Haute

4. **D-003** — Documenter sécurité routes table (API key = trust boundary)
5. **A-001 / D-005** — Corriger POSComprehensiveTest (branch_id)
6. **D-012** — Table order : branch_id depuis order, pas item
7. **Idée 2** — Rupture de stock : comportement documenté et implémenté

### P2 — Moyenne

8. **UX-03 / Idée 1** — Barre de progression wizard
9. **D-007** — Aligner token frontend/backend
10. **D-008** — Message Dine-In masqué
11. **D-010** — KDS parsing instruction (Phase 3)
12. **D-011** — Confirmation Kiosk forte
13. **Idée 4** — Allergènes : conformité réglementaire

### P3 — Basse

14. **D-006** — Nom fichier export POS
15. **D-009** — Découpler wizard XHR
16. **Idée 3** — Badge NOUVEAU sur suppléments
17. **Idée 8** — Idempotency key

---

## 📊 PARTIE 6 — FICHIERS DE RÉFÉRENCE

| Fichier | Rôle |
|---------|------|
| `reports/antigravity/AUDIT_COMPLET_CAISSE_BORNE_20260310.md` | Rapport initial |
| `reports/antigravity/AUDIT_PROFOND_CAISSE_BORNE_20260310.md` | Rapport approfondi |
| `reports/antigravity/AUDIT_MASSIF_E2E_POS_KDS_KIOSK_SUITE.md` | Suite E2E, Phase 2/3 |
| `docs/WIZARD_LOGIC_DOCUMENTATION.md` | Logique wizard |
| `docs/ORDER_FLOW.md` | Flux commande |
| `docs/DEVICE_FLOW.md` | Flux par appareil |
| `docs/BUSINESS_RULES.md` | Règles métier |

---

## 📊 CONCLUSION

Ce rapport consolide les audits précédents, intègre le benchmark GUR KEBAB (parcours, patterns UX), et propose 10 idées d'amélioration avec des checkers de vérification. Les problèmes de sécurité (D-001, D-002, D-004) restent prioritaires. L'alignement avec les standards du marché (barre de progression, rupture de stock, allergènes) permettrait de renforcer la compétitivité de FoodKing.

**Aucune modification de code.** Document uniquement — pour audit manuel et planification.

---

**Fin du rapport consolidé.**

*Prochaine étape :* Revue manuelle, priorisation, exécution selon `workflows/task-routing.md`.
