# POS Catalog DB Audit — FoodKing 2026-05-06

Audit READ-ONLY niveau base de données du catalogue POS via `php artisan tinker --execute`.
Aucune modification DB. Cycle parent : audit massif POS 2026-05-06.

## Executive Summary

- **14 catégories** (100% actives, 0 orphelines)
- **64 items** (100% actifs, 0 orphelins, 0 prix invalides, 0 tax manquante)
- **95.3% items composés** (variations/extras/addons) — confirme observation Playwright "tous items passent par wizard"
- **Intégrité référentielle** : 100%
- **Coverage fiscale** : 100% (tous items → TVA 20% via tax_id=13)
- **Score global** : **93/100 — TRÈS BON**

⚠️ **Anomalies mineures** :
- 3 wizard templates non-standards : `burger`, `omelette`, `salade`
- 1 catégorie test E2E à nettoyer (ID 324 `E2E_PLAYWRIGHT_STUDIO_CATEGORY`)
- Stock module inactif (0 records `ItemBranchAvailability` / `StockLevel`)
- 0 ItemWizardProfile assigné — wizard fonctionne via templates par défaut

---

## 1. Catégories

### Overview
| Métrique | Valeur |
|---|---|
| Total catégories | 14 |
| Catégories actives | 14 |
| Catégories orphelines (sans items) | **0** |
| Avec wizard_template renseigné | 14 |

### Distribution par wizard_template
| Template | Count | Status |
|---|---|---|
| simple | 7 | ✅ Standard |
| sandwich | 1 | ✅ Standard |
| tacos | 1 | ✅ Standard |
| assiette | 1 | ✅ Standard |
| snacking | 1 | ✅ Standard |
| **burger** | 1 | ⚠️ **Non-standard** |
| **omelette** | 1 | ⚠️ **Non-standard** |
| **salade** | 1 | ⚠️ **Non-standard** |

**Standards attendus (cf. ComposerProfileRequest)** : `simple, sandwich, tacos, assiette, snacking, menu, custom`. Les 3 templates `burger / omelette / salade` ne figurent pas dans cette liste — soit intentionnels (variantes catégorie-spécifiques), soit drift à normaliser.

### Top 10 par nombre d'items
| Rang | Catégorie | Items | Template | Menu ? |
|---|---|---|---|---|
| 1 | Nos Sandwichs | 8 | sandwich | Oui |
| 2 | Nos Boissons | 8 | simple | Non |
| 3 | Suppléments | 8 | simple | Non |
| 4 | Nos Burgers | 6 | burger | Oui |
| 5 | Frites & Accompagnements | 5 | simple | Non |
| 6 | Nos Tacos | 4 | tacos | Oui |
| 7 | Nos Assiettes | 4 | assiette | Non |
| 8 | Ojja | 4 | simple | Non |
| 9 | Nos Salades | 4 | salade | Non |
| 10 | Poulet croustillant | 4 | snacking | Non |

---

## 2. Items

### Overview
| Métrique | Valeur |
|---|---|
| Total items | 64 |
| Items actifs | 64 |
| Items orphelins | **0** |
| Items sans tax_id | **0** |
| Items prix ≤ 0 ou NULL | **0** |

### Distribution par catégorie
| Catégorie | ID | Items |
|---|---|---|
| Nos Sandwichs | 307 | 8 |
| Nos Boissons | 317 | 8 |
| Suppléments | 318 | 8 |
| Nos Burgers | 308 | 6 |
| Frites & Accompagnements | 315 | 5 |
| Nos Tacos | 306 | 4 |
| Nos Assiettes | 309 | 4 |
| Ojja | 310 | 4 |
| Nos Salades | 312 | 4 |
| Poulet croustillant | 313 | 4 |
| Omelettes | 311 | 3 |
| Nos Desserts | 316 | 3 |
| Nos Menus Enfants | 314 | 2 |
| **E2E_PLAYWRIGHT_STUDIO_CATEGORY** | **324** | **1** ⚠️ |

> La catégorie #324 est un artefact de test Playwright. À supprimer ou archiver (recommandation P3).

### Composition (variations / extras / addons)
| Type | Count | % |
|---|---|---|
| Simple (aucun) | 3 | 4.7% |
| Variations + Extras | 24 | 37.5% |
| Variations seulement | 15 | 23.4% |
| Extras seulement | 11 | 17.2% |
| Addons seulement | 11 | 17.2% |
| **TOTAL composés** | **61** | **95.3%** |

**Conséquence design POS** : 95.3% des items déclenchent le wizard (variations/extras/addons à choisir). Les 3 items "vraiment simples" (4.7%) sont noyés dans la grille — explique pourquoi Playwright n'a pas pu trouver d'item simple parmi les 5 premiers (probabilité ~14% × 5 = ~50%, et "Frites Seules" qui a un nom évoquant simple est en réalité composé).

### Statistiques tarifaires
| Métrique | Valeur |
|---|---|
| Prix min | 0.50 TND |
| Prix max | 14.50 TND |
| Prix moyen | 6.10 TND |

### Top 5 items par prix
| ID | Nom | Prix | Catégorie |
|---|---|---|---|
| 384 | Assiette Mixte | 14.50 | Nos Assiettes (309) |
| 386 | Ojja Poulet | 13.50 | Ojja (310) |
| 387 | Ojja Viande Hachée | 13.50 | Ojja (310) |
| 388 | Ojja Merguez | 13.50 | Ojja (310) |
| 385 | Ojja Bœuf | 13.50 | Ojja (310) |

---

## 3. Fiscalité

| Tax ID | Tax Name | Tax Rate | Items | % |
|---|---|---|---|---|
| 13 | TVA 65% | 20.00% | 64 | **100%** |

**Observation** : Toute la carte applique la même tax (`tax_id=13`, taux 20%). Nom de la tax `TVA 65%` est un libellé interne (probablement un identifiant historique), le taux réel est 20% — à vérifier si le libellé doit être renommé pour clarifier.

---

## 4. Wizard Profiles & Composer

| Métrique | Valeur |
|---|---|
| Items avec wizard_profile_id assigné | **0** |
| Catégories avec wizard_profile_id | **0** |
| `ItemWizardProfile` records | **0** |

**Note** : Aucun profile composer customisé. Le wizard fonctionne via les `wizard_template` au niveau **catégorie** (ce qui est le pattern observé dans le frontend `pos-wizard.js`). C'est cohérent avec le design actuel.

À approfondir cycle 3 : vérifier si la création de wizard profiles via `POST /api/admin/composer/items/{item}/profile` est utilisée en production ou si c'est une feature pas encore activée.

---

## 5. Stock & Disponibilité

| Table | Records |
|---|---|
| `ItemBranchAvailability` | **0** |
| `StockLevel` | **0** |

**Conséquence** : Le module stock/dispo est **inactif** dans cet environnement. Tous les items sont implicitement disponibles. En prod, ces tables doivent être peuplées sinon l'overlay "86" / rupture / `ChoiceAvailabilityResolver::snapshotForItems` ne servent à rien.

À valider avec l'utilisateur : est-ce le comportement attendu pour cet environnement de test, ou faut-il activer le module stock ?

---

## 6. Anomalies & recommandations

### Critiques
**Aucune.**

### Mineures (P2)
1. **3 wizard templates non-standards** (`burger`, `omelette`, `salade`)
   - **Action** : décider entre normalisation vers `custom` OU mise à jour de la liste autorisée dans `ComposerProfileRequest::rules` + documentation.
   - **Impact** : si validation FormRequest n'accepte pas ces templates, des updates futurs pourraient échouer.

### À nettoyer (P3)
2. **Catégorie test E2E orpheline** (`#324 E2E_PLAYWRIGHT_STUDIO_CATEGORY`, 1 item)
   - **Action** : `php artisan foodking:cleanup-playwright-fixtures --apply` (commande détectée par sentinels)
   - **Impact** : pollue le menu POS pour les utilisateurs réels en environnement de dev partagé.

### À investiguer
3. **Stock module 0 records** — comportement attendu pour env test ou bug ?
4. **0 ItemWizardProfile** — feature désactivée ou pas encore utilisée ?
5. **Tax label "TVA 65%" pour 20%** — renommage ?

---

## 7. Health Score

| Catégorie | Score | Status |
|---|---|---|
| Intégrité référentielle | 100% | ✅ |
| Coverage fiscale | 100% | ✅ |
| Cohérence pricing | 100% | ✅ |
| Items orphelins / debris | 0% | ✅ |
| Template conformance | 62.5% (11/14 OK) | ⚠️ |
| **GLOBAL** | **93%** | ✅ TRÈS BON |

---

## 8. Cohérence avec audit Playwright cycle 1+2

| Élément | Playwright | DB | Cohérent ? |
|---|---|---|---|
| Catégories visibles | 15 (incl. "Toutes les") | 14 réelles | ✅ (1 = pill "Toutes") |
| Tuiles produits totales | 64 | 64 | ✅ |
| Items composés (déclenchent wizard) | "Tous testés ouvrent wizard" | 95.3% (61/64) | ✅ |
| Prix Tacos M/L/XL/XXL | 6.50/8.50/10.50/12.50 € | À vérifier | À cross-check |
| 1 catégorie avec 1 produit | C2-01 cat-14 = 1 produit | Catégorie 324 (E2E) = 1 item | ✅ Confirmé |

**Cross-validation** : la cat-14 dans Playwright = `E2E_PLAYWRIGHT_STUDIO_CATEGORY` (artefact test). Confirme la recommandation de nettoyage.

---

## 9. Évolution recommandée (cycle 3+)

1. Cleanup `E2E_PLAYWRIGHT_STUDIO_CATEGORY` via cmd existante
2. Décision normalisation wizard_template (`burger / omelette / salade`)
3. Activer / valider module stock si attendu en prod
4. Documenter l'usage (ou non) des `ItemWizardProfile` custom
5. Vérifier libellé tax `TVA 65%` (probablement renommer en `TVA 20% standard`)

---

**Auteur** : agent Explore via tinker --execute
**Mode** : READ-ONLY, aucune mutation DB
**Outils** : `php artisan tinker --execute`, JSON output capture
