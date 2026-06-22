# ULTRA-AUDIT VERDICT — Wizard Studio : surfaces cachées / indirectes / réactives
**Date:** 2026-06-14 · Workflow `wf_60c5cf12-ea1` (11 agents : 5 lanes discover→verify + completeness) · run sqlite `:memory:` (zéro risque DB).

## VERDICT GLOBAL : **0 P0 / 0 P1 — feature SAINE.** Tous les findings réels (P2/P3) sont non-frozen, dans mon scope, et traités ou documentés.

## Le headline (réfuté par le verify adversarial)
**« preview POST → 401 → déconnexion admin » = RÉFUTÉ → P3.** Le mount du wizard frozen fire bien `POST frontend/pricing/preview` (pré-sélection garnitures gratuites → watcher profond), MAIS : le SPA admin attache un token Sanctum admin (`axios-setup.js:34-41`) → `auth:sanctum` passe ; `PricingPreviewRequest::authorize()` exige `tokenCan('kiosk:order')` que l'admin n'a pas → **403** (pas 401) ; l'intercepteur **ignore les 403** (`app.js:81-84`) → **aucune déconnexion**. Le total bascule en arithmétique locale (gracieux). Confirmé empiriquement (jamais déconnecté en test live).

## Findings par lane (vérifiés en source primaire)
| Lane | Verdict | Détail |
|---|---|---|
| **Réactif frontend** | CLEAN + 3×P3 | (a) pricing-preview 403 au mount (gracieux, local total) ; (b) bruit console au remount `previewNonce` ; (c) **focus-trap document keydown** du wizard frozen actif sur la page admin (nettoyé au unmount). Pas d'Echo, pas d'idle-redirect (wizard monté bare, hors KioskApp), listeners nettoyés, analytics inerte (consent défaut false). |
| **Réactif backend** | CLEAN | `preview-projection` (GET) prouvé **sans écriture/event/observer/cache/job** (grep body = 0). `is_published=true` en mémoire seulement. Events publish ne partent que des services write (jamais du preview). Pas de collision de clé cache avec la projection menu live. |
| **Sécurité / IDOR** | 0 P0/P1 + 1 P2(test) | Double-gate : `WizardProfileBranchScope` → **404** cross-branch (route-binding) + `authorizeBranchScope` → 403. `is_published` override jamais persisté. Item représentatif résolu sur la branche de l'opérateur. Route hors per-item-guard = intentionnel (catalog.compose reste la barrière). P2 = mes tests PHPUnit étaient RED → **healé 5/5**. |
| **Synchronisation** | CLEAN (capital) | **Aucun abonnement Echo/`branch.{id}`** depuis le tree du Studio (`_subscribeEchoChannel` vit dans `KioskAppComponent`, non monté). L'aperçu est **100% passif** sur le bus temps-réel → **zéro collision avec la session centrale** `integration-v1`. |
| **Gestion / Historique / NF525** | CLEAN | Zéro écriture `audit_logs`/`action_logs`/`domain_events` depuis l'aperçu (GET). `item_wizard_step_versions` (insert-only) intouché. Aucun chemin vers chaîne fiscale/`OrderStateMachine`/Z. |
| **Completeness critic** | 2 gaps P3 | G2 : route-guard SPA **fail-open** (`userHasPermission` retourne true si permission absente) — **serveur = vraie barrière** (`permission:catalog.compose` + `block_kiosk_token_admin`), donc UX-P3 pas faille. X1 : confusion 401/403/404 entre lanes → tranchée (cross-branch=404, pricing admin=403, jamais 401). |

## Dispositions
- ✅ **HEALÉ** : P2 tests PHPUnit RED → **5/5 GREEN** (`85f74c719`, sqlite :memory:). Couverture réelle : 401/403/200+override/NF525-no-price/404-cross-branch.
- 📝 **DOCUMENTÉ (frozen-side, non corrigeable sans gate)** : pricing-preview 403 au mount (gracieux) ; focus-trap document du wizard frozen sur la page admin. **Mitigation future = containment iframe** (re-pèse le trade-off direct-mount vs iframe : l'isolation iframe neutraliserait les listeners document + le POST cross-auth). À arbitrer en W2 si gênant.
- 📝 **P3 différés** : route-guard fail-open (serveur protège ; cohérent avec le pattern app) ; bruit console remount (debounce W2).
- ⚠️ **Pré-existants HORS scope (signalés, non introduits par moi)** : 5 échecs PHPUnit composer (`FritesWizardComposerTest` ×3, `ProfilePublishMidCartRejectionTest` ×2) — touchent PricingService(frozen)/publish, pas mon code ; mon diff est additif. À traiter par la lane catalogue/pricing concernée.

## Preuves
Frozen diff **0** · Vitest wizard **4/4** · PHPUnit preview **5/5** · sync passive confirmée (anti-collision session centrale) · aucune écriture DB op / zone autre session.
</content>
