# Pont caisse — impression SILENCIEUSE (le vrai correctif du charabia)

**Le problème** : Laravel tourne sur le cloud OVH (`vps-…ovh.net`). Le serveur ne
peut donc PAS écrire sur l'imprimante SAGA branchée en USB sur le PC caisse. Quand
ce pont n'est pas lancé, la caisse retombe sur le `window.print()` de Chrome → d'où
le charabia (accents/€ cassés, en-tête `https://…`, `1/1`, mise en page moche).

**La solution** : ce petit pont tourne sur le PC caisse. Le serveur rend les octets
ESC/POS exacts (ticket client propre + ticket cuisine **symbolique**), Chrome les
récupère et les POSTe ici, et le pont les imprime **tels quels** → ticket papier ==
rendu serveur, sans fenêtre, sans charabia.

## Installation (PC Windows de la caisse)

1. Installer **Node.js** (https://nodejs.org, LTS) si absent.
2. Copier ce dossier sur le PC caisse.
3. Trouver le **nom exact** de l'imprimante : Panneau de configuration →
   Périphériques et imprimantes (ex. `SAGA`, `POS-80`, `Generic / Text Only`…).
4. Lancer le pont :
   ```
   node caisse-bridge.js "NOM_EXACT_IMPRIMANTE"
   ```
   (laisser la fenêtre ouverte ; pour démarrage auto, créer une tâche planifiée).
5. Lancer **Chrome de la caisse** avec le flag (page HTTPS → 127.0.0.1) :
   ```
   chrome.exe --disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks
   ```
   (créer un raccourci avec ce flag — cf. `docs/runbooks/BORNE_LOCAL_BRIDGE_SETUP.md`).
6. (Si l'URL du pont diffère) régler `window.foodkingConfig.caisseBridgeUrl`
   (défaut `http://127.0.0.1:9100`).

## Vérifier
- Naviguer vers `http://127.0.0.1:9100/health` → doit afficher `UP`.
- Encaisser une commande → le ticket sort **tout seul**, propre, + le ticket
  cuisine symbolique, chacun coupé. Plus aucune fenêtre d'impression Chrome.

## Si ça ne marche pas
- Rien ne sort → vérifier le **nom d'imprimante** (étape 3) et la console du pont
  (`winspool_exit_…`). Tester avec la file « Generic / Text Only » si besoin.
- Chrome n'appelle pas le pont → vérifier le **flag** (étape 5) et `/health`.
- Tant que le pont n'est pas lancé, l'app retombe sur `window.print()` (le charabia).

Zéro dépendance npm (http + winspool RAW via PowerShell intégré). Le ticket cuisine
suit le format symbolique (`G | SANDWICH | P | STO | SAM` + suppléments + MENU/F).

## Imprimante débranchée : plus de vieux tickets (2026-10-20)
Avant, une imprimante éteinte laissait le spouleur Windows empiler les tickets, tous réimprimés
au retour. Le pont :
- **refuse** un ticket (`503 printer_offline`) quand Windows déclare l'imprimante hors ligne —
  le caissier voit l'échec, rien n'entre dans le spouleur ; au moindre doute il imprime quand même ;
- **supprime** toutes les 5 s les jobs du spouleur de CETTE imprimante plus vieux que 120 s
  (`CAISSE_STALE_JOB_SECONDS`, `0` = désactivé) et, au démarrage, ceux de plus de 30 s ;
- `CAISSE_OFFLINE_GATE=0` désactive le refus.
Un ticket bloqué plus de 2 min (papier épuisé non rechargé) est donc PERDU, volontairement : mieux
vaut un ticket manquant qu'un ticket vieux d'une heure. Reprendre le pont : télécharger
`https://vps-418872ac.vps.ovh.net/dl/caisse-bridge.js`, remplacer le fichier, relancer le pont.
