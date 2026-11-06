# Vague B — chemin doré application → cuisine → caisse → écran client → suivi (round 1)

Rédigé par l'orchestrateur le 2026-10-01 à 09 h 40 : l'agent de capture a été coupé (fin de session)
après ses deux passages complets, sans écrire ce rapport. Source : `tmp/e2e-stores/B/resultats.json`,
`sortie-banc.txt` (passages O + F, 150 contrôles) et `sortie-banc-F-seul.txt` (F rejoué, 72 contrôles).
Banc : `site-wt-stores-2026-09-30/tests-e2e/e2e-stores-vague-B-2026-10-01.spec.js`.

## Passages
- **O (service ouvert, `fenetre-service.sh ouvert`)** : client neuf, e-mail d'abord, Tacos M + Fanta en
  application, paiement sur place, caisse accepte, cuisine prépare puis « prête », encaissement, écran
  client, suivi. Commande 7564 (A0051).
- **F (service fermé, la nuit)** : même client type, créneau « À l'ouverture 18:20 », commande programmée.
  Commandes 7565 (A0052) puis 7566 (A0053), toutes deux annulées « client non venu » par la caisse en fin
  de passage.

## Prouvé vert (extraits)
- Montant identique sur 9 surfaces (panier, bouton, envoyé, réponse, confirmation, suivi, caisse, file
  d'encaissement, base) : 9,70 €.
- Cuisine : une commande programmée n'apparaît pas avant T-20 ; le bandeau « ⏰ Programmées (1) : 18:20 »
  l'annonce. Suivi « Prévue pour 18 h 20 » avant et après acceptation.
- Rappel natif retiré à l'annulation ; aucune alerte « prête » pour une commande annulée ; aucune requête
  vers la production.

## Défauts prouvés (et sort)
| Id | Gravité | Défaut | Preuve | Sort |
|---|---|---|---|---|
| O-B5 | **P0** | Suivi d'une commande web acceptée : `GET /api/frontend/order/show/{id}` répond 500 pendant la préparation ; après 3 échecs « connexion perdue », plus de « prête » ni de notification | journal local + **53 erreurs en production du 25 au 28/09** (même pile) | **Corrigé** backend `1f33aef6e` (test rouge→vert) |
| O-B6 | P1 | L'application montre la série « 0110267564 » ; écran client, cuisine et caisse appellent « N°A0051 » | capture + `PreparingAndReadyComponent.vue:37` | **Corrigé** site (lot `20261001lot1`) + backend `d1f33574c` (`queue_number` dans « Mes commandes ») |
| F-B2 | P1 | Fiche caisse d'une commande « À l'ouverture » de ce soir : « Heure de livraison : 02-10-2026 », sans heure | capture F-06b + base (`scheduled_at` 01/10 18:20) | **Corrigé** backend `d1f33574c` (`App\Support\CreneauRetrait`, 3 ressources) |

## Observations (non bloquantes, ou à arbitrer)
- **O-B2 / O-B6 composition** : la base de test sert Tacos M avec 2 viandes requises (« Viande 3 »), la
  production une seule. `api.js` complète tout attribut requis non rempli avec sa PREMIÈRE option : en
  local, un Cordon Bleu non choisi est parti en cuisine. Artefact d'environnement aujourd'hui ; **risque
  latent** si le catalogue de production diverge un jour du menu statique du site. Le complément est
  volontaire (sinon 422 sur les attributs que le site ne sait pas exprimer, ex. pain) : décision à prendre,
  pas un correctif rapide.
- **O-B7 synchro** : en local, aucune trame websocket ne porte la commande (aucun worker ne vide la file
  « high ») : tout passe par le sondage — caisse 60 s, encaissement 18 s, écran client « Prêt » 42 s. À
  remesurer avec un worker avant de conclure quoi que ce soit sur la production.
- **F-B2a** : le panneau « Commandes web » de la caisse n'affiche que les 4 plus ANCIENNES (89 en attente
  dans la base de test, dont des dizaines d'août) : une commande neuve y est invisible tant que les
  anciennes ne sont pas soldées. Données de test, mais l'ordre « plus ancien d'abord + 4 lignes » mérite
  une décision.
- **F-B2 ticket d'encaissement** : l'heure programmée n'y figure pas.

## Anomalies d'instrument
- « F-B5 GET order/show 2xx après acceptation » : aucun statut relevé (liste vide) — l'instrument n'a pas
  capturé l'appel dans ce passage ; le défaut réel est O-B5, corrigé.
- « FIN fenêtre remise à `defaut` » : `service_ouvert=null` (lecture limitée à 30/min) ; vérifié à part :
  `.env` ne porte plus aucune clé `KDS_SCHEDULED_*`.

## Données de test créées (base `foodking_e2e`)
Commandes 7564 (O), 7565 et 7566 (F, annulées) ; utilisateurs 705, 706, 707
(`e2e-stores-b-*@lecayenne-test.fr`).
