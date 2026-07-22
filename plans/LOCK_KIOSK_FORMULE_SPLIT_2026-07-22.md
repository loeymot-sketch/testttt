# LOCK — Formule split 3 pages (KioskWizardComponent FROZEN §7)

**Gate owner EXPLICITE (2026-07-22, verbatim)** : « Je t'autorise pour toucher la zone qui est
gelée pour corriger et tester massivement jusqu'à ça fonctionne correctement. Et sans aucune faute »

**Portée autorisée** : `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
(computeActiveSteps + cascade formule) — UNIQUEMENT pour le split formule en 3 pages dédiées
(page1 formule menu/boisson-seule/frites-seules · page2 boissons · page3 sauces frites) demandé
par l'owner (référence concurrents). Test massif exigé avant tout deploy (KioskWizard.spec 97+,
e2e clic-par-clic, captures lues). Rollback = git revert du commit dédié.

**Note prix** : G-PRIX (1,90/1,90) résolu SANS frozen — config kiosk.menu_pricing ratios 0.76.
