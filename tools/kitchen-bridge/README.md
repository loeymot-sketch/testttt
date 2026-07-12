# Pont cuisine — impression AUTO + SILENCIEUSE du ticket cuisine (depuis le KDS)

**Le problème** : Laravel tourne sur le cloud OVH. Le serveur ne peut donc PAS écrire
sur l'imprimante USB branchée au PC **cuisine** (à côté de l'écran KDS). Il faut un
petit pont local — exactement comme pour la caisse (`tools/caisse-bridge/`).

**La solution** : ce pont tourne sur le PC cuisine. Le serveur rend les octets ESC/POS
du **ticket cuisine** (symbolique, width-safe, SSOT NF525). L'écran KDS, à **chaque
nouvelle commande** (toutes sources : borne, caisse, web…), récupère ces octets et les
POSTe ici, et le pont les imprime **tels quels** → ticket papier == rendu serveur, sans
fenêtre Chrome, sans charabia, **automatiquement**.

- Dé-dup robuste côté KDS (localStorage `kds.printedKitchenIds`) → chaque commande est
  imprimée **exactement une fois**, jamais de doublon au rafraîchissement / reconnexion.
- Toggle « 🖨️ Impression auto ON/OFF » dans la barre d'outils du KDS (défaut **ON**).
- Best-effort : si le pont est éteint, le KDS continue normalement (aucune erreur bloquante).

## Installation (PC Windows de la CUISINE)

1. Installer **Node.js** (https://nodejs.org, LTS) si absent.
2. Copier ce dossier sur le PC cuisine.
3. Trouver le **nom exact** de l'imprimante cuisine : Panneau de configuration →
   Périphériques et imprimantes (ex. `EPSON-TM`, `POS-80`, `Generic / Text Only`…).
4. Lancer le pont :
   ```
   node kitchen-bridge.js "NOM_EXACT_IMPRIMANTE_CUISINE"
   ```
   ou via variables d'environnement :
   ```
   set KITCHEN_PRINTER=EPSON-TM
   set KITCHEN_BRIDGE_PORT=9101
   node kitchen-bridge.js
   ```
   (port par défaut **9101** — distinct du pont caisse qui utilise 9100 ; laisser la
   fenêtre ouverte, ou créer une tâche planifiée pour un démarrage auto).
5. Lancer **Chrome/KDS** avec le flag (page HTTPS → 127.0.0.1) :
   ```
   chrome.exe --disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks
   ```
   (créer un raccourci avec ce flag — même topologie que la caisse).
6. (Si l'URL du pont diffère) régler `window.foodkingConfig.kitchenBridgeUrl`
   (défaut `http://127.0.0.1:9101`).

## Vérifier
- Naviguer vers `http://127.0.0.1:9101/health` → doit afficher `UP`.
- Créer une commande (borne / caisse) → le ticket cuisine sort **tout seul** sur
  l'imprimante cuisine, propre, symbolique, coupé. Une seule fois.
- Bouton « 🖨️ Impression auto » du KDS = OFF → plus d'impression auto (utile pour couper
  temporairement sans arrêter le pont).

## Si ça ne marche pas
- Rien ne sort → vérifier le **nom d'imprimante** (étape 3), le **port** (9101, pas 9100),
  et la console du pont (`winspool_send_failed`). Tester avec « Generic / Text Only ».
- Le KDS n'appelle pas le pont → vérifier le **flag Chrome** (étape 5), `/health`, et que
  le toggle « Impression auto » du KDS est bien **ON**.
- Doublon avec un ticket cuisine imprimé côté caisse/serveur → il ne doit PAS exister
  d'imprimante de station `kitchen`/`kitchen_hot`/`kitchen_cold` ACTIVE en base : le KDS
  est la **seule** source d'impression cuisine (le listener serveur reste no-op sans
  imprimante cuisine configurée).

## Tests
```
node tools/kitchen-bridge/kitchen-bridge.test.js     # contrat HTTP du pont (5/5)
npx vitest run tests/js/kitchenLocalPrinter.spec.js  # helper front + dé-dup (11/11)
```

Zéro dépendance npm (http + winspool RAW via PowerShell intégré). Miroir exact du pont
caisse (`tools/caisse-bridge/`), port 9101 au lieu de 9100.
