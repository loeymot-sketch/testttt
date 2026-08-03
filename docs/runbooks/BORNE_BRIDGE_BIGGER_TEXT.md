# Borne — agrandir le texte du ticket (« +50 % bien lisible »)

> 2026-06-28. Le texte du ticket borne est rendu par **`bridge.js`** sur le PC borne
> (le serveur cloud envoie un JSON `{title, subtitle, order, lines, total, footer}`,
> le pont le transforme en ESC/POS et l'imprime). **La taille de police se règle dans
> `bridge.js`, pas côté serveur.**

## Rappel : tailles thermiques = paliers (pas de 1,5×)
Une imprimante thermique ne connaît pas le « 1,5× ». Commande ESC/POS `GS ! n`
(`0x1D 0x21 n`), `n` = `(largeur×16) + hauteur`, multiplicateurs entiers 0–7 (1×–8×) :

| `n` | Effet | Caractères/ligne (58 mm) |
|----|-------|--------------------------|
| `0x00` | normal 1×1 | 32 |
| `0x01` | **double HAUTEUR** (recommandé) | **32** (rien ne déborde) |
| `0x11` | double hauteur + largeur (2×2, le plus gros) | 16 (les lignes longues passent à la ligne) |

➡️ **Recommandé : `0x01` (double hauteur)** — le texte est nettement plus grand et très
lisible, et la composition (sauce, crudités…) reste sur une ligne. Si tu veux ENCORE
plus gros, passe à `0x11` (mais les longues lignes se couperont en deux).

## ✅ Taille pilotée par le SERVEUR (recommandé)
Le serveur envoie désormais la taille **dans le JSON** : `payload.bodySize` (corps) et
`payload.titleSize` (en-tête + n° commande + total) — octets `GS ! n` prêts à l'emploi.
**Une seule modif à `bridge.js`** : lire ces champs (avec un défaut si absents). Ensuite
la taille se règle côté serveur (`config/printing.php` → `BORNE_TICKET_BODY_SIZE`) sans
jamais retoucher la borne.

```js
const GS = 0x1D;
const SIZE = (n) => Buffer.from([GS, 0x21, n]);          // GS ! n
const SIZE_NORM = 0x00;
// Lit la taille envoyée par le serveur (défaut « grand » = double hauteur si absent) :
const bodySize  = Number.isInteger(payload.bodySize)  ? payload.bodySize  : 0x01;
const titleSize = Number.isInteger(payload.titleSize) ? payload.titleSize : 0x11;

// En-tête + numéro de commande : titleSize
write(SIZE(titleSize)); write(Buffer.from(asciiFold(payload.order ? 'Commande ' + payload.order : payload.title) + '\n', 'binary')); write(SIZE(SIZE_NORM));

// CORPS (compo) : bodySize
write(SIZE(bodySize));
for (const line of payload.lines) write(Buffer.from(asciiFold(line) + '\n', 'binary'));
write(SIZE(SIZE_NORM));

// Total : titleSize (ressort)
write(SIZE(titleSize)); write(Buffer.from('TOTAL: ' + payload.total + '\n', 'binary')); write(SIZE(SIZE_NORM));
```

> Le front réduit déjà automatiquement la largeur des lignes quand `bodySize` est en
> double largeur (0x11) → rien n'est coupé, quelle que soit la taille choisie au serveur.

## (Alternative) taille EN DUR dans `bridge.js`
Si tu préfères figer la taille dans le pont sans lire le payload :

```js
const SIZE = (n) => Buffer.from([0x1D, 0x21, n]);   // GS ! n
const SIZE_BODY  = 0x01;   // double hauteur (mets 0x11 pour 2×2)
const SIZE_NORM  = 0x00;
write(SIZE(SIZE_BODY));
for (const line of payload.lines) write(Buffer.from(asciiFold(line) + '\n', 'binary'));
write(SIZE(SIZE_NORM));
```

## `bridge.js` de référence complet (rendu + taille)
Si tu préfères remplacer le rendu d'un bloc, voici la fonction de rendu complète.
**Garde ta partie « ouverture du périphérique USB » (VID/PID du SK1-31) telle quelle** —
ne remplace QUE la construction des octets ESC/POS ci-dessous.

```js
const ESC = 0x1B, GS = 0x1D, LF = 0x0A;
const B = (...a) => Buffer.from(a);

// Tailles (GS ! n). Régler ici la taille globale du ticket :
const SIZE_TITLE = 0x11;   // titre + numéro de commande (2×2)
const SIZE_BODY  = 0x01;   // corps (compo, lignes) : DOUBLE HAUTEUR — « +50% lisible »
const SIZE_NORM  = 0x00;   // pied de page / mentions

function asciiFold(s) {
  return String(s == null ? '' : s)
    .normalize('NFD').replace(/[̀-ͯ]/g, '')
    .replace(/€/g, 'EUR').replace(/[^\x20-\x7E]/g, ' ')
    .replace(/[ \t]{2,}/g, ' ').trim();
}

// Construit le buffer ESC/POS complet à partir du JSON reçu (POST /).
function renderTicket(payload) {
  const out = [];
  const size = (n) => out.push(B(GS, 0x21, n));
  const align = (n) => out.push(B(ESC, 0x61, n)); // 0=gauche 1=centre 2=droite
  const bold = (on) => out.push(B(ESC, 0x45, on ? 1 : 0));
  const text = (s) => out.push(Buffer.from(asciiFold(s) + '\n', 'binary'));
  const feed = (n) => out.push(B(...Array(n).fill(LF)));

  out.push(B(ESC, 0x40));                 // ESC @  init

  // En-tête (centré, gros)
  align(1); bold(1); size(SIZE_TITLE);
  text(payload.title || 'LE CAYENNE');
  size(SIZE_NORM); bold(0);
  if (payload.subtitle) text(payload.subtitle);

  // Numéro de commande (très gros)
  if (payload.order) { bold(1); size(SIZE_TITLE); text('Commande ' + payload.order); size(SIZE_NORM); bold(0); }
  text('--------------------------------');

  // CORPS : compo, lignes — DOUBLE HAUTEUR (le « +50% bien lisible »)
  align(0); size(SIZE_BODY);
  for (const line of (payload.lines || [])) text(line);
  size(SIZE_NORM);
  text('--------------------------------');

  // Total (un peu plus gros)
  if (payload.total) { bold(1); size(SIZE_BODY); text('TOTAL: ' + payload.total); size(SIZE_NORM); bold(0); }

  // Pied de page (normal, centré)
  align(1);
  if (payload.footer) text(payload.footer);
  feed(3);
  out.push(B(GS, 0x56, 0));               // GS V 0  coupe

  return Buffer.concat(out);
}

// ... puis : writeToPrinter(renderTicket(payload))  via TA partie node-usb existante.
```

## Déploiement (sur le PC borne)
1. Modifier `bridge.js` (palier de taille au choix : `0x01` recommandé).
2. **Relancer le pont** : fermer la fenêtre/le service `bridge.js` et le rouvrir
   (le raccourci `shell:startup` ou le service). `curl http://127.0.0.1:9100/health` → `UP`.
3. Passer une commande test → le ticket sort avec le texte agrandi.
4. Si trop gros / lignes coupées → repasser `SIZE_BODY` à `0x01` (double hauteur seule).

> Aucun changement serveur nécessaire : le serveur envoie le même JSON ; seule la
> **taille de rendu** côté pont change. La compo complète (sauce, crudités, viandes…)
> déployée aujourd'hui reste identique, juste imprimée plus grand.
