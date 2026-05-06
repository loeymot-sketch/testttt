# P2 — Borne Kiosk — E2E massif — 2026-05-04

| Champ | Valeur |
| --- | --- |
| **TAG plan** | `P2` |
| **Surface** | `resources/js/components/frontend/kiosk/` + `kiosk-shell` |
| **Prérequis** | P0 + machine/borne token (Sanctum) si requis ; `branch_id` résolu serveur. |
| **Test strategy** | `playwright-full-e2e` ; offline / file d’attente selon specs existantes (`kiosk-offline-waiting.spec.js`, etc.). |

## 1. Décomposition fonctionnelle (inventaire)

### Bloc K0 — Boot & thème

| Id | Capacité | STEP |
| --- | --- | --- |
| K0a | Idle / attract screen | `S2K0a_` |
| K0b | Thème clair/sombre persistant | `S2K0b_` |
| K0c | Healthcheck / erreurs globales | `S2K0c_` |

### Bloc K1 — Parcours obligatoire avant menu

| Id | Capacité | STEP |
| --- | --- | --- |
| K1a | **Type de commande** obligatoire (emporter / sur place) | `S2K1a_` |
| K1b | Analytics / consentement si gate | `S2K1b_` |

### Bloc K2 — Navigation catalogue

| Id | Capacité | STEP |
| --- | --- | --- |
| K2a | Catégories → items | `S2K2a_` |
| K2b | Article en rupture globale invisible ou grisé | `S2K2b_` |
| K2c | Search / filtres si présents | `S2K2c_` |

### Bloc K3 — Wizard & composition

| Id | Capacité | STEP |
| --- | --- | --- |
| K3a | Étapes successives (viande, taille, sauce, crudités) | `S2K3a_` |
| K3b | Contraintes min/max attribut (si produit test prévu) | `S2K3b_` |
| K3c | Extras & addons | `S2K3c_` |
| K3d | Variation en rupture stock vs ingrédient (jeux P0) | `S2K3d_` |

### Bloc K4 — Panier & prix affiché

| Id | Capacité | STEP |
| --- | --- | --- |
| K4a | Barre panier (a11y aria-label) | `S2K4a_` |
| K4b | Total ligne / total panier (affichage) | `S2K4b_` |

### Bloc K5 — Paiement & fin

| Id | Capacité | STEP |
| --- | --- | --- |
| K5a | Paiement comptoir / TPE (simuler ce qui est possible) | `S2K5a_` |
| K5b | Écran attente / numéro file | `S2K5b_` |
| K5c | Upsell dessert (checklist `playwright.mdc`) | `S2K5c_` |

### Bloc K6 — Offline & résilience

| Id | Capacité | STEP |
| --- | --- | --- |
| K6a | Couper réseau → file offline | `S2K6a_` |
| K6b | Reconnexion → sync (cf. heal WS reconnect menu) | `S2K6b_` |

## 2. Captures & manifeste

Même convention que maître ; préfixe fichier `P2__`.

## 3. Rapport `RAPPORT_P2.md`

Sections obligatoires :

- **Kiosk vs POS** : écarts volontaires (branch résolue serveur, pas d’envoi `branch_id` panier selon mémoire store).
- **Horloge** : note Graphiti — désync horloge peut impacter ticket (fact P4 transversal).

## 4. Specs existantes

- `kiosk-order-type-required.spec.js`
- `kiosk-quote-pin.spec.js`
- `critical-flow/v1-demo-v2-flag-disabled.spec.js` (si périmètre démo)

---

*Plan P2 — création documentaire uniquement.*
