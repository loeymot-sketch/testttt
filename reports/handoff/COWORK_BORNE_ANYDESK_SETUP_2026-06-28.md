# Prompt pour Claude Cowork — finaliser la BORNE (via AnyDesk)

> À COLLER tel quel à Claude cowork connecté en AnyDesk sur le PC de la borne.
> Le serveur cloud est déjà à jour ; il reste 3 actions LOCALES sur la borne.

---

Tu es sur le **PC de la BORNE** (Windows, Chrome plein écran qui ouvre le site cloud
Le Cayenne, imprimante thermique **SK1-31 en USB**). Le serveur cloud vient d'être mis à
jour (nouvel écran d'accueil, ticket avec composition complète, et la **taille de police
est maintenant envoyée par le serveur dans les données d'impression**). Il reste 3 choses
à finaliser sur CETTE machine. Ne touche à rien d'autre.

## TÂCHE 1 — Mettre à jour le pont d'impression `bridge.js`

Le pont `bridge.js` tourne sur ce PC (Node.js), écoute `http://127.0.0.1:9100`, reçoit un
JSON `{title, subtitle, order, lines, total, footer, bodySize, titleSize}` et imprime sur
le SK1-31. Il faut qu'il **applique la taille envoyée** (`bodySize`/`titleSize`) pour que
le ticket sorte plus grand.

1. Localise `bridge.js` (regarde `shell:startup`, le gestionnaire de tâches, ou le dossier
   où il a été installé). Repère la fonction qui ouvre le SK1-31 en USB (VID/PID) et celle
   qui écrit les octets — **GARDE la partie USB telle quelle**.
2. Remplace UNIQUEMENT la construction des octets ESC/POS par cette fonction (elle lit la
   taille envoyée par le serveur, défaut « grand » si absente) :

```js
const ESC = 0x1B, GS = 0x1D, LF = 0x0A;
const B = (...a) => Buffer.from(a);

function asciiFold(s) {
  return String(s == null ? '' : s)
    .normalize('NFD').replace(/[̀-ͯ]/g, '')
    .replace(/€/g, 'EUR').replace(/[^\x20-\x7E]/g, ' ')
    .replace(/[ \t]{2,}/g, ' ').trim();
}

// Construit le buffer ESC/POS à partir du JSON reçu (POST /).
function renderTicket(payload) {
  const out = [];
  const size  = (n) => out.push(B(GS, 0x21, n));     // GS ! n : taille de caractere
  const align = (n) => out.push(B(ESC, 0x61, n));    // 0 gauche, 1 centre
  const bold  = (on) => out.push(B(ESC, 0x45, on ? 1 : 0));
  const text  = (s) => out.push(Buffer.from(asciiFold(s) + '\n', 'binary'));

  // Tailles envoyees par le serveur (defaut grand : corps double hauteur, titre 2x2)
  const bodySize  = Number.isInteger(payload.bodySize)  ? payload.bodySize  : 0x01;
  const titleSize = Number.isInteger(payload.titleSize) ? payload.titleSize : 0x11;

  out.push(B(ESC, 0x40));                              // init

  align(1); bold(1); size(titleSize); text(payload.title || 'LE CAYENNE');
  size(0x00); bold(0);
  if (payload.subtitle) text(payload.subtitle);
  if (payload.order) { bold(1); size(titleSize); text('Commande ' + payload.order); size(0x00); bold(0); }
  text('--------------------------------');

  align(0); size(bodySize);                            // CORPS : compo (GRAND)
  for (const line of (payload.lines || [])) text(line);
  size(0x00);
  text('--------------------------------');

  if (payload.total) { bold(1); size(titleSize); text('TOTAL: ' + payload.total); size(0x00); bold(0); }
  align(1);
  if (payload.footer) text(payload.footer);

  out.push(B(LF, LF, LF));
  out.push(B(GS, 0x56, 0));                            // coupe papier
  return Buffer.concat(out);
}

// Puis ecris le resultat avec TA fonction USB existante :  writeToPrinter(renderTicket(payload));
```

3. Vérifie que le pont répond toujours aux pré-requis (sinon le navigateur ne peut pas lui
   parler). Sur CHAQUE réponse, ces en-têtes doivent être présents :
   `Access-Control-Allow-Origin: *`, `Access-Control-Allow-Methods: GET,POST,OPTIONS`,
   `Access-Control-Allow-Headers: Content-Type`, `Access-Control-Allow-Private-Network: true`,
   et répondre `204` aux requêtes `OPTIONS`. `GET /health` doit renvoyer `UP`.
4. Assure-toi que `bridge.js` démarre **automatiquement au boot** (raccourci dans
   `shell:startup` ou service). 

## TÂCHE 2 — Vérifier le raccourci Chrome de la borne

Le raccourci qui lance la borne DOIT contenir le flag réseau local (sinon Chrome bloque
l'appel au pont = pas d'impression). Vérifie / recrée le raccourci avec EXACTEMENT :

```
"C:\Program Files\Google\Chrome\Application\chrome.exe" --kiosk --kiosk-printing ^
  --disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks ^
  --disable-pinch --overscroll-history-navigation=0 ^
  "https://vps-418872ac.vps.ovh.net/kiosk?machine_key=<LA_CLE_SECRETE_DE_LA_BORNE>"
```
> Garde la clé `machine_key` déjà utilisée (ne la change pas). Si tu ne la connais pas,
> récupère-la depuis l'ancien raccourci / l'historique Chrome de la borne — NE l'invente pas.

## TÂCHE 3 — Redémarrer le pont + recharger la borne

1. Ferme et relance `bridge.js` (pour charger le nouveau code).
2. Ferme complètement Chrome et **relance-le via le raccourci** (ou redémarre la borne) —
   c'est un SPA : tant que l'ancienne page reste ouverte, elle ne se met pas à jour.

## VÉRIFICATION (preuve attendue — fais-la et rapporte)

1. `curl http://127.0.0.1:9100/health` → doit renvoyer `UP`.
2. La borne affiche le **nouvel écran d'accueil** (fond orange, logo « LE CAYENNE », carrousel
   de produits qui défile, grosse pill « Touchez l'écran »).
3. Passe une **commande test** (ex. un Cheese Burger avec une sauce + des crudités). Le ticket
   doit sortir **EN GRAND** et décrire **toute la composition** :
   ```
   Commande A00xx
   1x Cheese Burger
     > Sauce: ...
     > Salade, Tomate, Oignon
     > Formule (...)
     > (boisson)
   ```
4. Si le ticket sort encore petit / sans la sauce ni les crudités : le pont n'a pas rechargé
   le nouveau code → refais la Tâche 1 + relance `bridge.js`. Si « Touchez l'écran » ne lance
   rien ou la console montre « Local Network Access / loopback » → le flag Chrome manque
   (Tâche 2).

Rapporte : `/health`, une photo du nouvel écran d'accueil, et une photo du ticket test.
Ne modifie aucun autre fichier, ne touche pas au `.env`, ne désinstalle rien.
