# PLAN_ANTI_GRAVITY — Tests E2E Post-Implémentation KIMI
**Rôle :** Anti-Gravity (E2E & Critical QA)
**Architecte :** Claude (Antigravity)
**Date :** 12 Mars 2026
**Décision Claude :** ✅ OPTION A — GO pour Anti-Gravity (avec une réserve sur D-010)
**Serveur :** http://127.0.0.1:8000

---

## 🔍 AUDIT PRÉ-TEST (Décision Claude)

### Ce qui a été implémenté et validé ✅
| Plan | Implémentation | Verdict |
|------|----------------|---------|
| PLAN_01 D-001 | FrontendOrderService : throw si item null | ✅ Code correct |
| PLAN_02 D-002 | posOrderStore : ItemVariation::find + ItemExtra::find | ✅ Code correct |
| PLAN_03 D-004 | ValidJsonOrder : item_id + quantity requis | ✅ Code correct |
| PLAN_04 MA-001+002 | payment.blade null-safe + SettingResource | ✅ 56/56 tests |
| PLAN_05 D-005 | POSComprehensiveTest : 8/8 | ✅ |
| PLAN_07 UX-03 | Badge "X/Y" dans wizard header | ✅ Corrigé ternaire redondant |
| PLAN_08 D-007 | Token nullable backend + optionnel frontend | ✅ |
| PLAN_11 ARCH-01 | Migration wizard_template + has_menu | ✅ |
| PLAN_12 ARCH-02 | detectCategory lit wizard_template depuis API | ✅ |

### ⚠️ GAP identifié — PLAN_06 D-010 (KDS Instruction Parsing)
**Verdict :** ❌ NON IMPLÉMENTÉ — `grep parseInstruction KitchenDisplaySystemComponent.vue → 0 résultats`
**Action :** Anti-Gravity doit noter l'absence et KIMI devra implémenter après les tests E2E.

### 🔧 Correction mineure Claude (déjà appliquée)
- `pos-wizard.js` L675 : ternaire mort corrigé → `totalStepsWithoutRecap = activeStepsCount - 1`

---

## 📋 SUITES DE TESTS ANTI-GRAVITY

---

## SUITE AG-01 — Wizard POS : Badge Étape (PLAN_07 UX-03)

**Pré-requis :** Connecté en admin, POS chargé avec au moins un item Tacos
**Naviguer vers :** http://127.0.0.1:8000/admin/pos

### AG-01-T01 : Badge visible sur étape 1
**Actions :**
1. Connecter : `admin@lecayenne-henin-beaumont.fr` / `123456`
2. Naviguer vers `/admin/pos`
3. Cliquer sur "Tacos L (2 Viandes)"
4. Observer le header du wizard

**Assertion :**
- ✅ Badge visible "1/3" (ou "1/N" selon le nb d'étapes actives)
- ✅ Badge positionné à droite dans le header, style rouge/arrondi
- ✅ Pas de badge sur la première étape si c'est directement le récap

**Screenshot :** Capturer le badge et annoter

---

### AG-01-T02 : Badge se met à jour à chaque étape
**Actions :**
1. Suite de AG-01-T01 (wizard ouvert étape 1)
2. Sélectionner une viande
3. Cliquer "Suivant"
4. Observer le badge

**Assertion :**
- ✅ Badge affiche "2/3"
- ✅ Numéro correct (pas coincé à 1/3)

---

### AG-01-T03 : Badge absent sur le récap
**Actions :**
1. Continuer jusqu'à l'étape Récap
2. Observer le header

**Assertion :**
- ✅ Pas de badge dans le header du récap
- ✅ La section récap affiche normalement les choix

---

### AG-01-T04 : Badge pour un item sans wizard (Nos Boissons)
**Actions :**
1. Cliquer sur une boisson (ex: Coca-Cola)
2. Observer si un wizard s'ouvre

**Assertion :**
- ✅ Soit pas de wizard (direct panier) = correct
- ✅ Soit wizard minimal avec badge cohérent

---

## SUITE AG-02 — Wizard DB-Driven : wizard_template API (PLAN_12 ARCH-02)

**Pré-requis :** Migration ARCH-01 exécutée, seeder ItemCategoryWizardSeeder exécuté

### Vérification préalable
```bash
# Vérifier que la migration est passée
php artisan migrate:status | grep wizard_config
# Vérifier que le seeder a peuplé les catégories
mysql -u root foodking -e "SELECT name, wizard_template, has_menu FROM item_categories;"
```

### AG-02-T01 : API retourne wizard_template pour Tacos
**Actions :**
1. Ouvrir les DevTools (F12) → Network
2. Cliquer sur "Tacos L" dans le POS
3. Observer la requête XHR vers `/admin/item/{id}` ou `/admin/setting/item/{id}`

**Assertion :**
- ✅ Réponse JSON contient `"wizard_template": "tacos"`
- ✅ Réponse contient `"has_menu": true`

**Si wizard_template absent :** Vérifier que ItemResource retourne ces champs et que la migration a été exécutée.

---

### AG-02-T02 : Console JS — "wizard_template from API" (pas le fallback)
**Actions :**
1. Ouvrir la console JS (F12)
2. Cliquer sur "Tacos L"

**Assertion :**
- ✅ Console affiche : `[POS-WIZARD] wizard_template from API: tacos`
- ❌ Ne DOIT PAS afficher : `[POS-WIZARD] detectCategory (fallback):`
- Si fallback → wizard_template non retourné par l'API → signaler à KIMI pour vérifier ItemResource.php

---

### AG-02-T03 : Wizard correct pour chaque catégorie
| Item à tester | wizard_template attendu | Étapes wizard attendues |
|---------------|------------------------|------------------------|
| Tacos L | tacos | viande_sauce → perso → menu_choice → recap |
| Sandwich Poulet | sandwich | sauce_garnitures → supplements_menu → recap |
| Burger | burger | sauce_garnitures → supplements_menu → recap |
| Chicken & Tenders | snacking | sauce_single → recap |
| Nos Salades | salade | sauce_supplements → recap |
| Coca-Cola | boisson | direct panier (pas de wizard) |

**Actions :** Tester chaque item, noter le nombre d'étapes et le type
**Assertion :** Wizard cohérent avec le tableau ci-dessus

---

## SUITE AG-03 — Sécurité P0 (Tests E2E via API)

### AG-03-T01 : Rejet item_id inexistant (D-001)
**Actions :**
1. Ouvrir DevTools → Console
2. Exécuter la requête suivante :
```javascript
fetch('/api/admin/pos', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content
    },
    body: JSON.stringify({
        items: JSON.stringify([{item_id: 999999, quantity: 1, item_price: 0.01}]),
        type: 2,
        token: 'test'
    })
}).then(r => r.json()).then(d => console.log('[D-001 TEST]', d.status, d.message));
```

**Assertion :**
- ✅ Réponse HTTP 422
- ✅ Message contient "Item ID 999999 introuvable"
- ❌ HTTP 200 = FAIL (sécurité cassée)

---

### AG-03-T02 : ValidJsonOrder rejette sans item_id (D-004)
**Actions :**
```javascript
fetch('/api/admin/pos', {
    method: 'POST',
    headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content},
    body: JSON.stringify({
        items: JSON.stringify([{quantity: 1, item_price: 5.00}]), // Pas d'item_id
        type: 2
    })
}).then(r => r.json()).then(d => console.log('[D-004 TEST]', d.status, d.errors));
```

**Assertion :**
- ✅ Réponse HTTP 422
- ✅ Erreur sur le champ `items`
- ❌ HTTP 200 = FAIL

---

### AG-03-T03 : Commande POS normale → passe bien (régression)
**Actions :**
1. Naviguer vers POS
2. Ajouter un Tacos L au panier normalement
3. Token: "123", Type: Takeaway
4. Confirmer et payer

**Assertion :**
- ✅ Commande créée avec succès
- ✅ KDS reçoit la commande
- ✅ 0 erreur 422 inattendue

---

## SUITE AG-04 — KDS Instruction Parsing (D-010)

### ⚠️ ATTENTION : Cette suite est susceptible d'ÉCHOUER

**Contexte :** KIMI n'a pas implémenté D-010 (grep = 0 résultats dans KDS Vue).
Le test documentera l'état actuel pour informer KIMI.

### AG-04-T01 : État actuel du KDS (baseline)
**Actions :**
1. Naviguer vers `/admin/kitchen-display-system`
2. Trouver une commande avec instruction structurée
3. Observer l'affichage de l'instruction

**Assertion attendue (état actuel) :**
- ℹ️ L'instruction est affichée en texte brut (normal — D-010 non implémenté)

**Si sections colorées visibles :** ✅ KIMI a bien implémenté → corriger l'audit

### AG-04-T02 : Créer une commande de test avec instruction complète
**Actions :**
1. POS → Tacos L → Wizard complet (2 viandes, sauce, formule)
2. Dans le champ "Instructions spéciales" (récap) : laisser vide (l'instruction est auto-générée)
3. Commander en Takeaway
4. Aller sur le KDS

**Contenu instruction attendu :**
```
VIANDES: Merguez, Poulet. SAUCE: Ketchup. FORMULE: Menu Complet
```

**État attendu (non implémenté) :** Texte brut affiché
**État souhaité (à implémenter) :** Sections colorées 🥩 🥄 🍟

**→ Signaler à KIMI pour implémenter PLAN_06**

---

## SUITE AG-05 — LeCayenne Configuration Validation

### AG-05-T01 : Vérification branding
**Actions :**
1. Naviguer vers `/admin/dashboard`

**Assertions :**
- ✅ Nom "LeCayenne" visible (header, sidebar ou titre)
- ✅ Copyright "© LeCayenne 2026" en footer
- ✅ Devise "€" (Euro) sur les prix

### AG-05-T02 : 1 seule branche visible
**Actions :**
1. POS → Sélecteur de branche

**Assertion :**
- ✅ Seulement "LeCayenne" disponible (plus de Mirpur-1, Gulshan-1)
- ✅ Menu charge correctement pour LeCayenne

---

## 📊 GRILLE DE RÉSULTAT

Compléter après chaque suite :

| Suite | Test | Résultat | Notes |
|-------|------|---------|-------|
| AG-01 | T01 Badge étape 1 | ⬜ | |
| AG-01 | T02 Badge se met à jour | ⬜ | |
| AG-01 | T03 Badge absent sur récap | ⬜ | |
| AG-01 | T04 Badge item simple | ⬜ | |
| AG-02 | T01 API wizard_template | ⬜ | |
| AG-02 | T02 Console "from API" | ⬜ | |
| AG-02 | T03 Chaque catégorie | ⬜ | |
| AG-03 | T01 Item 999999 rejeté | ⬜ | |
| AG-03 | T02 Sans item_id rejeté | ⬜ | |
| AG-03 | T03 Commande normale | ⬜ | |
| AG-04 | T01 KDS état baseline | ⬜ D-010 non impl | |
| AG-04 | T02 Instruction brute | ⬜ À signaler Kimi | |
| AG-05 | T01 Branding LeCayenne | ⬜ | |
| AG-05 | T02 1 seule branche | ⬜ | |

---

## 🎬 INSTRUCTIONS ANTI-GRAVITY

### Avant de commencer
```bash
# 1. Vérifier le serveur
curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8000/
# Doit retourner 200

# 2. Vérifier la migration
php artisan tinker --execute="echo DB::table('item_categories')->value('wizard_template');"
# Doit retourner 'tacos' ou autre (pas NULL ni erreur)
```

### Credentials
- **Admin :** `admin@lecayenne-henin-beaumont.fr` / `123456`
- **POS Operator :** `caissier@lecayenne-henin-beaumont.fr` / (même mdp si inchangé)

### Rapport à produire
Écrire le résultat dans : `reports/execution/ANTI_GRAVITY_E2E_20260312.md`
Format : chaque test avec PASS/FAIL/NOTE + screenshot path

---

## ⚡ PRIORITÉ D'EXÉCUTION

```
1. AG-05 (LeCayenne config) — rapide, prérequis pour tout
2. AG-03 (sécurité) — critique, via console JS
3. AG-01 (badge étape) — visuel
4. AG-02 (wizard_template API) — technique
5. AG-04 (KDS) — documentation état actuel
```

---

## 📬 APRÈS LES TESTS

### Si tous AG-01/02/03/05 = PASS
→ Signaler résultat à Claude. Demander à KIMI d'implémenter PLAN_06 (D-010 KDS).

### Si AG-02 échoue (wizard_template absent de l'API)
→ Retourner à KIMI : vérifier que `ItemResource.php` charge la relation `itemCategory`
et expose `wizard_template`. Commande de vérification :
```bash
php artisan tinker --execute="echo json_encode(App\Models\Item::with('itemCategory')->find(4)->itemCategory->wizard_template);"
```

### Si AG-03 échoue (sécurité 200 au lieu de 422)
🚨 **CRITIQUE** → Stopper, retourner à KIMI avec urgence maximale.
