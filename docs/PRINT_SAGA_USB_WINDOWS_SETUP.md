# Impression directe ESC/POS — SAGA USB sur caisse Windows

> [PRINT-SAGA 2026-06-24] Active l'impression du **ticket réel** (client + cuisine)
> directement sur l'imprimante thermique **SAGA branchée en USB**, en mode production.
> Remplace l'impression navigateur (`window.print()`) par un envoi ESC/POS serveur.

## Prérequis (V1 LOCAL = 1 seule machine)
- **Laravel/PHP tourne SUR le PC Windows** où la SAGA est branchée en USB.
  (Le serveur spoole vers l'imprimante locale Windows. Si le serveur est sur une
  AUTRE machine, l'USB du PC caisse est hors de portée → il faudrait un agent local
  type QZ Tray ; non couvert ici.)
- La SAGA installée dans Windows (pilote constructeur, ou « Generic / Text Only »),
  rouleau **80 mm**.

## Étape 1 — Noter le NOM exact de l'imprimante Windows
`Paramètres → Bluetooth et appareils → Imprimantes et scanners` → relever le **nom
exact** (ex. `SAGA POS-80` ou `POS-80C`). C'est ce nom qui ira dans `Printer.host`.

## Étape 2 — Flags `.env` (mode RÉEL)
```
APP_ENV=production
POS_SIMULATION_HARDWARE=false
PRINTING_BYPASS_MODE=false
PRINT_DRIVER=windows_raw
```
Les garde-fous au boot (`AppServiceProvider`) REFUSENT de démarrer en production si
la simulation/bypass est encore active — donc impossible d'imprimer « en simulé » par erreur.

## Étape 3 — Déclarer l'imprimante en base
Via tinker (`php artisan tinker`) — remplace `<NOM EXACT WINDOWS>` :
```php
\App\Models\Printer::create([
    'branch_id'   => 1,
    'name'        => 'SAGA Caisse',
    'type'        => 'escpos_usb_windows',   // informatif
    'host'        => '<NOM EXACT WINDOWS>',   // le nom imprimante Windows (winspool)
    'port'        => 0,
    'station'     => 'receipt',               // clé de recherche (NE PAS changer)
    'width_chars' => 48,                       // 80 mm = 48 colonnes
    'status'      => \App\Enums\Status::ACTIVE, // 5
    'options'     => ['code_page' => 19],      // 19 = CP858 (accents FR é à è + €)
]);
```

## Étape 4 — Vider le cache config
```
php artisan config:clear
```

## Étape 5 — Test d'impression (vérification matérielle)
`Admin → Printers → Test print` (ou `POST /api/admin/printers/{id}/test-print`).
Un petit slip « FOODKING POS / Test print OK » doit sortir de la SAGA.
- Si rien ne sort → voir Dépannage ci-dessous.

## Étape 6 — Imprimer un vrai ticket
À la caisse, encaisser une commande puis « Imprimer » :
- **Ticket client** : sort sur la SAGA (entête, lignes, compo, suppléments AVEC prix,
  TVA, total, mentions NF525, marqueur DUPLICATA en réimpression).
- **Ticket cuisine** : bouton cuisine → sort sur la SAGA (compo + suppléments, SANS prix).
- Le navigateur n'imprime plus en double (le serveur a déjà imprimé → `printed_escpos=true`).

## Comment ça marche (résumé technique)
- `OrderReceiptEscPosRenderer` construit les octets ESC/POS depuis le
  `composition_snapshot` (même SSOT que le ticket écran + NF525 via `ReceiptDataService`).
- `WindowsRawPrinterTransport` envoie ces octets en **RAW** via le spouleur Windows
  (`winspool.drv` : OpenPrinter → StartDocPrinter datatype `RAW` → WritePrinter),
  par **nom d'imprimante** (`Printer.host`) — aucun « cooking » du pilote.
- `PosReceiptPrintController` (best-effort) : si une imprimante `station=receipt` est
  configurée, il rend + envoie ; sinon `printed_escpos=false` → le navigateur imprime
  (comportement inchangé pour les installs sans imprimante configurée).

## Dépannage
- **Rien ne sort au test-print** : vérifier `PRINT_DRIVER=windows_raw`, `PRINTING_BYPASS_MODE=false`,
  et que `Printer.host` == le **nom exact** Windows. Regarder `storage/logs/laravel.log`
  (`[EscPosPrinterService] print failed` + `lastError`).
- **`requires_windows_host`** : le serveur ne tourne pas sur Windows → cf. Prérequis.
- **`winspool_spool_failed`** : le nom d'imprimante est faux, ou le spouleur Windows
  refuse le RAW → essayer la file « Generic / Text Only », ou partager l'imprimante.
- **Accents en « ? » ou mojibake** : ajuster `options.code_page` (19=CP858, 16=CP1252)
  selon le firmware SAGA.
- **Coupe-papier ne marche pas** : certaines SAGA utilisent une commande de coupe
  différente — me le signaler, j'ajuste `EscPosCommandBuilder::cut()`.

## Limite honnête
Le rendu des octets ESC/POS est **prouvé** (tests unitaires + rendu vérifié sur commande
réelle). L'envoi `winspool` RAW + la sortie physique ne peuvent se valider QUE sur le
PC Windows + la SAGA réelle (étape 5). Si le test-print ne sort pas du premier coup,
c'est presque toujours le **nom d'imprimante** ou un **réglage du spouleur** — itération
rapide via les logs.

---

## ⚡ Activation rapide (2026-06-28) — pourquoi le ticket sortait en charabia

**Symptôme** (photo owner) : à l'impression, accents/€ cassés (« Opúrateur », « áç »),
en-tête `https://…/admin/pos` + `1/1`, colonnes collées, AUCUNE coupe entre les 2 tickets.

**Cause** : `PRINT_DRIVER` valait `tcp` (défaut) **et** aucune ligne `Printer`
`station=receipt status=ACTIVE` n'existait → le contrôleur renvoyait `printed_escpos=false`
→ le front retombait sur le `window.print()` du **navigateur** (qui ajoute l'URL + le n°
de page et massacre l'encodage). Le renderer ESC/POS lui-même était déjà correct.

**Activer le vrai moteur thermique** (sur le PC Windows où la SAGA est branchée USB) :

```bash
# 1) Nom EXACT de l'imprimante (Périphériques et imprimantes)
php artisan pos:setup-receipt-printer "SAGA-80mm"   # ← remplace par le nom réel
# 2) .env
PRINT_DRIVER=windows_raw
PRINTING_BYPASS_MODE=false
# 3)
php artisan config:clear
```

Ensuite, depuis la caisse : « Ticket Client » → ticket caisse propre + coupe ; « Ticket
Cuisine » → bon de prod **symbolique** + coupe. Plus de fallback navigateur, plus d'URL.

## Ticket CUISINE = format symbolique (owner)

Le bon cuisine n'imprime plus la composition en toutes lettres mais le code court
lisible par le cuisinier — **identique à l'écran de cuisine (KDS)** :

```
1 x G | TACOS | M | K | SAM     ← [Support]|[Produit]|[Taille]|[Viande]|[Crudités]|[Sauce]
  + Cheddar                     ← suppléments (nom complet)
  MENU                          ← MENU / F
```

Tables de symboles : `app/Services/Hardware/KitchenTicketSymbolicFormatter.php`
(jumeau PHP de `resources/js/helpers/kdsSymbolic.js` — parité testée). Pour ajouter
une viande/sauce, éditer **les deux** tables + leurs tests (`tests/Unit/Hardware/
KitchenTicketSymbolicFormatterTest.php` et `tests/js/kdsSymbolic.spec.js`).

Le **ticket CLIENT** reste en toutes lettres (lisible client) + minimal NF525
(plus d'empreinte audit longue, plus d'URL, plus de « Propulsé par »).
