# Pont local CAISSE — impression silencieuse (endpoint `/raw`)

> 2026-06-28. Miroir du pont borne (`BORNE_LOCAL_BRIDGE_SETUP.md`), mais pour la
> **caisse** : impression SILENCIEUSE des tickets client + cuisine sans la fenêtre
> Chrome `window.print()`.

## Pourquoi
Laravel tourne sur le **cloud Linux (OVH)** → il ne peut pas sortir sur l'USB du
SAGA branché au PC caisse. Le serveur RIND les octets ESC/POS fiscaux (SSOT NF525),
le navigateur de la caisse les récupère en base64 et les POST tels quels au pont
local node, qui les passe à l'imprimante. Aucune fenêtre, ticket papier == rendu
serveur (fidèle au reçu fiscal).

```
[Caisse Chrome HTTPS]                    [PC caisse - localhost]
   ReceiptComponent.vue
     │ 1. GET /api/admin/pos/orders/{id}/escpos?ticket=client
     │    → { escpos_b64 }              (rendu serveur, octets fiscaux)
     │ 2. POST 127.0.0.1:9100/raw  ───────►  bridge.js
     │    body = octets bruts                  │ winspool RAW / USB
     │    Content-Type: octet-stream           ▼
     │                                      [SAGA thermique 80mm]  ← SILENCIEUX
```

## Ce que le pont caisse doit exposer (cowork à implémenter)
Le pont borne existant gère déjà un POST JSON reconstruit. Pour la caisse il faut
**un endpoint passthrough RAW** :

### `GET /health` → `UP`
Déjà présent côté borne ; réutiliser. Le front teste ça avant d'imprimer
(`isCaisseBridgeAvailable`, timeout 800 ms). Si absent → fallback `window.print()`.

### `POST /raw`  (NOUVEAU — passthrough octets)
- `Content-Type: application/octet-stream`
- Body = **octets ESC/POS bruts** (déjà encodés CP858, init + coupe inclus). NE PAS
  re-encoder, NE PAS reconstruire : écrire le buffer **tel quel** sur l'imprimante.
- Réponse `200` (corps libre) = imprimé ; tout autre code → le front retombe sur
  `window.print()`.

Exemple node (Express + module d'impression Windows RAW) :
```js
const express = require('express');
const app = express();
app.use('/raw', express.raw({ type: 'application/octet-stream', limit: '2mb' }));

app.get('/health', (_req, res) => res.send('UP'));

app.post('/raw', async (req, res) => {
  try {
    // req.body = Buffer d'octets ESC/POS prêts à imprimer (NE PAS toucher)
    await printRawToSaga(req.body);   // winspool RAW vers l'imprimante par nom
    res.status(200).send('PRINTED');
  } catch (e) {
    res.status(500).send(String(e));
  }
});
app.listen(9100, '127.0.0.1', () => console.log('caisse bridge on 127.0.0.1:9100'));
```

`printRawToSaga` = même primitive RAW que le pont borne (winspool `WritePrinter`,
ou `node-thermal-printer` en mode raw, ou copie sur le partage imprimante).

## Flag Chrome (raccourci caisse)
Comme la borne — une page HTTPS publique vers `127.0.0.1` est bloquée par la
Private Network Access. Lancer Chrome de la caisse avec :
```
--disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks
```
(cf. `BORNE_LOCAL_BRIDGE_SETUP.md` pour la création du raccourci.)

## Côté serveur (déjà livré — commits `8f4e1d435`, `2ccc3705f`)
- Endpoint : `GET /api/admin/pos/orders/{order}/escpos?ticket=client|kitchen`
  → `{ order_id, ticket, escpos_b64 }` (permission `pos`, read-only, pas d'audit fiscal).
- Helper front : `resources/js/helpers/posLocalPrinter.js`
  (`isCaisseBridgeAvailable`, `printEscPosViaCaisseBridge`, garde anti-double
  `markPrintedOnceCaisse`).
- `ReceiptComponent.vue` : `handlePrintClientClick` / `handlePrintKitchenClick`
  tentent le pont d'abord, fallback `window.print()` si indisponible.
- URL du pont configurable : `window.foodkingConfig.caisseBridgeUrl`
  (défaut `http://127.0.0.1:9100`).

## Bouton « Encaisser la commande » → impression du ticket
À l'encaissement (`OrderPaidAtCounter`), le serveur déclenche déjà le reçu fiscal +
tiroir (listener G10/G12, espèces). La caisse peut **aussi** réimprimer à la
demande via le bouton ticket du `ReceiptComponent` (client ou cuisine), désormais
silencieux via ce pont. Anti-double : 1 ticket par (commande,type,jour) côté front.

## Test de validation (à faire une fois le pont en place)
1. Lancer `node bridge.js` sur le PC caisse → `curl 127.0.0.1:9100/health` = `UP`.
2. Sur la caisse, encaisser une commande multi-produits.
3. Cliquer « imprimer ticket » → **aucune fenêtre Chrome**, le SAGA sort le ticket.
4. Vérifier : numéro d'appel gros/gras, compo complète, pas de doublon, coupe papier.
5. Couper le pont → ré-imprimer → la fenêtre `window.print()` réapparaît (fallback OK).
