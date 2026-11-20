# Plan — MOBILE-EXCELLENCE-PHASE5-LOYALTY-REACTIVATION-20261016 — 2026-10-16

## Statut

HUMAN_GATE_REQUIRED — analyse et cadrage uniquement. Aucun code produit ne doit
être modifié avant décision métier et approbation du gate.

## Tier

complex — auth/privacy, fidélité persistante, migration potentielle et parité
mobile/backend.

## Périmètre d’analyse

| Zone | Fichiers vérifiés | Décision |
|---|---|---|
| UI mobile | `mobile/screens-main.jsx`, `mobile/screens-modals.jsx`, `mobile/index.html` | comportement actuel local-only à remplacer après contrat |
| API mobile | `mobile/api/client.js` | ajouter les méthodes uniquement après contrat validé |
| API backend | `routes/api.php`, `app/Http/Controllers/Frontend/LoyaltyController.php` | route opt-in existante, opt-out/réactivation absents |
| Persistance | `app/Models/LoyaltyConsent.php`, `database/migrations/2026_04_18_120008_create_loyalty_consents_table.php` | journal de consentement sans fenêtre de réactivation |

## Invariants

- Le backend reste la seule source de vérité du solde et de l’état fidélité.
- Aucun solde ou historique ne doit être restauré côté frontend par simple
  `localStorage`.
- L’utilisateur authentifié ne peut agir que sur son propre compte.
- Les événements et dispatch éventuels restent post-commit.
- Aucune migration ni zone gelée ne sera touchée sans gate approuvé.
- La politique RGPD affichée doit être identique au comportement réellement
  persisté.

## Options à faire trancher

### Option A — Réactivation à zéro

L’opt-out efface/neutralise le solde, la réactivation crée un état actif sans
restaurer les anciens points. C’est cohérent avec le texte mobile actuel, mais
doit être confirmé et documenté comme suppression effective.

### Option B — Restauration sous 30 jours

L’opt-out conserve une trace serveur protégée et la réactivation restaure les
points admissibles avant échéance. Cette option nécessite une politique de
rétention, une protection anti-double-restauration et des tests de concurrence.

## Blocage explicite

La demande « réactiver dans les 30 jours » ne suffit pas à choisir entre A et B,
et le backend actuel ne permet pas de l’implémenter de façon sûre. Toute
implémentation sans ce choix risquerait de contredire le texte RGPD ou de créer
un solde différent entre mobile, site, borne et caisse.

## Validation prévue après gate

Feature tests backend, tests de frontière temporelle, tests d’autorisation et
d’idempotence, puis Playwright mobile sur le cycle complet.

## PLAN_REVIEW

PLAN_REVIEW_VERDICT: HUMAN_GATE_REQUIRED
REASON: contrat métier de réactivation et persistance serveur non défini
