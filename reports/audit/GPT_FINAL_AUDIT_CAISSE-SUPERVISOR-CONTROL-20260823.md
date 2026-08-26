# GPT final audit — CAISSE-SUPERVISOR-CONTROL-20260823

Canal: `foodking-complex-implementer` (`codex-extension` fallback), read-only, raisonnement maximal.

## Findings

- P0 — La garde des écritures E2E accepte encore `APP_ENV=testing` ou un nom de base de test sans exiger le marqueur explicite `FOODKING_E2E_DEDICATED_DB=1`.
- P1 — `queuePendingCount()` est appelé après le calcul de `sync` et `overall`; une panne de file peut donc retourner `queue_pending=null` avec un faux état global vert. Le test n'assert que la valeur nulle.
- P1 — Le teardown multi-produits ne traite que la commande du run courant. La preuve DB post-run conserve `active_orders=[6606]`, contrairement au PASS déclaré.
- P1 — La fixture multi-produits peut modifier un utilisateur et écrase la configuration de la machine `kiosk-lecayenne` sans restauration.
- P1 — `missions/.../input.json` référence encore le fichier gelé `KioskAppComponent.vue`, un test absent et un contrat inspection-only qui ne correspond ni au plan ni au code.
- P2 — La pastille n'offre pas `Réessayer` pour un contrôle backend `unknown` et masque les messages précis fiscal/stock/aging inconnus.

GPT_FINAL_AUDIT_VERDICT: REWORK
