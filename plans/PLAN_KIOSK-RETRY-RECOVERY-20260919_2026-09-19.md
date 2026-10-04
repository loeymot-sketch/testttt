# PLAN — KIOSK-RETRY-RECOVERY-20260919

## Mission

Le bouton public « Réessayer » doit reproduire sans fermer l'application le
nouveau chargement qui permet à une borne autorisée de retrouver son
auto-login après une veille, un changement IPv6 ou un rechargement serveur.

PRIMARY_EXECUTION_MODEL: gpt-5.5
REASONING_EFFORT: xhigh
TEST_STRATEGY: playwright-critical-flow
PLAN_REVIEW_CHANNEL: explicit-prompt-bind (human-acknowledged)
PLAN_REVIEW_MODEL: gpt-5.5
PLAN_REVIEW_REASONING_EFFORT: xhigh
PLAN_REVIEW_VERDICT: PASS

## SUBSYSTEMS_TOUCHED

- `resources/js/components/frontend/kiosk/KioskLoginComponent.vue`
- `tests/js/KioskLogin.spec.js`

## SUBSYSTEMS_OFF_LIMITS

- Authentification backend, route API, `OrderService`, `FrontendOrderService`,
  prix, `branch_id`, KDS, paiement et migrations.

## INVARIANTS_AT_RISK

- Aucun invariant métier n'est modifié. Le changement ne révèle aucun secret,
  ne crée aucune commande, et n'altère aucun calcul de prix.
- `branch_id`, dispatch post-commit et `OrderStatus` hors périmètre.

## GATE_CONDITIONS

- Aucun fichier ciblé n'est une zone frozen dans le registre actuel.
- Stop immédiat si la correction exige un changement backend, route, cookie ou
  configuration secrète.

## SYMMETRY_NOTE

Non applicable : ni `OrderService` ni `FrontendOrderService` ne sont touchés.

## Execution Steps

1. Lorsque l'écran a déjà constaté l'absence d'identifiants auto-login,
   déclencher au clic « Réessayer » un rechargement navigateur unique au lieu
   de relire la configuration bootstrap périmée.
2. Conserver le flux de retry API inchangé lorsque des identifiants sont déjà
   présents.
3. Ajouter une régression unitaire pour les deux branches.
4. Exécuter Vitest, test Feature du gate, puis le parcours Chromium borne sur
   base dédiée. Vérifier en production que le visiteur non autorisé reste
   bloqué et que le préfixe de la borne est seul admis côté serveur.

## Risks

- Un rechargement sur une borne réellement non provisionnée laisse le même
  écran public, sans fuite. Il n'est déclenché qu'après un clic explicite.
- La validation physique est complétée par le contrôle de l'IPv6 de la borne;
  aucune clé machine ni identifiant n'est transmis dans le test.
