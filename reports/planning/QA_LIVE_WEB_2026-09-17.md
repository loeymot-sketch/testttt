# QA live web — 2026-09-17

## Périmètre et sécurité

- Cible : `https://vps-418872ac.vps.ovh.net`.
- Vérifications réelles, lecture seule : aucune création de compte, commande,
  paiement, encaissement ou impression physique.
- La borne a été inspectée depuis un navigateur externe non approuvé, donc sans
  transmettre de secret `machine_key`.

## Résultats constatés

| Contrôle | Résultat | Preuve |
| --- | --- | --- |
| Santé prête | PASS | `GET /api/health/ready` retourne DB, Redis, worker, scheduler, backup et restauration `ok`. |
| Santé intégrité | PASS avec alerte | `GET /api/healthz` retourne DB, Redis, WebSocket et chaîne fiscale `ok`; file Redis `notifications=4923`. |
| Connexion POS | PASS | `/admin/pos` redirige vers le formulaire de connexion; aucun écran blanc ni erreur console. |
| Borne depuis Internet | BLOQUÉ de manière sécurisée | `/kiosk/idle` redirige vers `/kiosk/login`, avec l'écran explicite « Borne indisponible » et aucun écran blanc / erreur JavaScript. |
| Pont caisse déployé | PASS partiel | `/dl/caisse-bridge.js` est téléchargeable et contient `/raw` ainsi que `drawer-kick`. |
| Retour utilisateur tiroir | NON DÉPLOYÉ | Le bundle en production (SHA `2a8765d7`) ne contient pas `cash_drawer_bridge_offline`; le correctif local qui expose l'échec du pont à la caisse n'est donc pas encore livré. |

## Remédiation borne déployée — 2026-09-17

- Déploiement vérifié : SHA `e75c46e3`; migrations, triggers d'immutabilité
  NF525, chaîne fiscale, CORS et smoke de bundle passent.
- La borne renouvelle désormais son jeton Sanctum avant son expiration de huit
  heures. Sur redémarrage/veille prolongée, le lien machine validé émet un
  cookie chiffré, `Secure`, `HttpOnly`, `SameSite=Strict`, limité à `/kiosk`
  pendant quatorze jours. Il ne remet pas le secret dans l'URL.
- Test HTTP directement sur le VPS : sans grant, `kioskAutoLogin` reste `null`;
  avec un grant chiffré valide, un rechargement propre reçoit l'auto-login.
  Le bundle servi contient `kioskCart/refreshKioskToken`.
- Le navigateur externe non approuvé affiche toujours l'écran d'indisponibilité
  prévu, sans erreur console ni écran blanc. La borne physique doit être
  ouverte une fois avec son raccourci habituel après le déploiement pour créer
  le grant persistant.

## Contrôle opérationnel post-déploiement

- Le `cache:clear` du déploiement avait effacé le battement scheduler :
  `healthz:check` a réécrit le marqueur et le cron est présent chaque minute.
- Le premier drill a correctement refusé le backup quotidien de 01:00, antérieur
  à une migration du déploiement (105 tables restaurées contre 106 vivantes).
  Il n'a touché que la base scratch.
- Un second dump post-déploiement a été créé séparément, sans écraser le dump
  quotidien existant. Le drill a restauré 106/106 tables, retrouvé les mêmes
  comptes de commandes/audits/Z et validé la chaîne NF525. `/api/health/ready`
  est désormais entièrement `ok`.

## Diagnostic borne

Les identifiants machine et le secret d'auto-login sont présents côté serveur,
mais `KIOSK_AUTO_LOGIN_TRUSTED_IPS` est absent. C'est conforme à la protection
anti-fuite : une visite extérieure ne reçoit jamais les identifiants de borne.
La borne physique doit donc être ouverte avec son URL déjà provisionnée
`?machine_key=…`, ou son IP de sortie doit être ajoutée à la liste approuvée.
Ne pas rendre les identifiants publics pour contourner ce contrôle.

## Actions de correction prioritaires

1. P1 — assainir la queue `notifications` (4 923 jobs). Le worker VPS écoute
   seulement `high,default`; ajouter `notifications` maintenant enverrait des
   milliers de notifications historiques. Il faut décider explicitement de
   purger ces anciennes notifications ou de les traiter.
2. P1 — vérifier sur la tablette borne réelle son raccourci provisionné une
   dernière fois; ce premier lancement crée le grant, puis les rechargements et
   mises à jour restent connectés.
3. P1 — livrer le bundle qui contient l'alerte `cash_drawer_bridge_offline`,
   puis tester sur le PC caisse : `GET http://127.0.0.1:9100/health` doit
   retourner `UP`, suivi d'une vente espèces de test autorisée par le gérant.
4. P1 — avant ce test physique, désactiver le mode simulation matériel suivant
   une validation opérateur: le VPS a `POS_SIMULATION_HARDWARE=true`, donc un
   test distant ne peut pas déclencher le tiroir USB réel.

## Verdict

`PASS WITH OPERATOR DECISION` — la correction de session borne est déployée,
le site répond et les sauvegardes sont restaurables. Le choix de traitement de
la queue notifications et le test physique du tiroir restent nécessaires.
