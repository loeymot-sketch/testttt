# Runbook — Borne Le Cayenne : impression via pont WinUSB local

> ⚠️ **DÉPENDANCE CRITIQUE NON-ÉVIDENTE** : l'impression de la borne repose sur un
> **flag Chrome** posé à la main sur le raccourci kiosque. Sans lui, Chrome bloque
> SILENCIEUSEMENT l'appel au pont (aucun ticket, aucune erreur visible). Ce runbook
> existe pour qu'un re-déploiement / une ré-image de la borne ne perde pas ce réglage.

## Contexte

La borne est un PC Windows (« SAGA ») faisant tourner **Chrome plein écran** pointant
le serveur cloud (`https://vps-…ovh.net/kiosk`). L'imprimante thermique **Sanei SK1-31**
n'a **aucun pilote spooler Windows** (driver WinUSB only) → impossible de l'utiliser
comme imprimante Windows / via `window.print()`.

Solution : un **pont local** `bridge.js` (Node.js + `usb`/node-usb) écoute sur
`http://127.0.0.1:9100` et écrit l'ESC/POS directement au SK1-31 (VID `0x10C5`/PID `0x0007`).
La page borne (cloud) POSTe le ticket au pont (`resources/js/helpers/kioskPrinter.js` →
`printViaLocalBridge`).

## 1. Le pont (`bridge.js`)

- Tourne au démarrage (raccourci dans `shell:startup` OU service).
- Écoute `127.0.0.1:9100` : `GET /health` → `UP`, `POST /` (JSON `{title,subtitle,order,lines,total,footer}`) → imprime.
- **CORS + Private Network Access** : le pont DOIT répondre, sur toutes les réponses :
  - `Access-Control-Allow-Origin: *`
  - `Access-Control-Allow-Methods: GET,POST,OPTIONS`
  - `Access-Control-Allow-Headers: Content-Type`
  - `Access-Control-Allow-Private-Network: true`  ← (durcissement PNA Chrome)
  - répondre `204` aux pré-vols `OPTIONS`.

## 2. ⚠️ LE FLAG CHROME (obligatoire)

Chrome récent bloque les requêtes d'une origine **publique HTTPS** vers le **réseau local**
(`127.0.0.1`) — « **Local Network Access** » : *« Permission denied to access the loopback
address space »*. Le header PNA du pont **ne suffit pas**. Il faut désactiver le contrôle
côté Chrome via le raccourci kiosque :

```
"C:\Program Files\Google\Chrome\Application\chrome.exe" --kiosk --kiosk-printing ^
  --disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks ^
  --disable-pinch --overscroll-history-navigation=0 ^
  "https://vps-418872ac.vps.ovh.net/kiosk?machine_key=<SECRET>"
```

C'est SÛR : la borne est une machine dédiée qui ne sert qu'à notre site (pas de navigation
arbitraire). **À recréer à l'identique sur toute ré-image / nouveau raccourci.**

## 3. Côté serveur (déjà fait)

- `config/security.php` : `connect-src` autorise `http://127.0.0.1:9100` (future-proof si la
  CSP passe un jour en `enforce` — sinon blocage CSP en plus du PNA).
- Auto-impression câblée sur les 2 fins de parcours : `KioskConfirmationComponent` (payé
  carte/TR) **et** `KioskCashInstructionComponent` (paiement à la caisse = défaut Plan B).

## 4. Test de bonne santé (au boot borne)

1. `curl http://127.0.0.1:9100/health` → doit répondre `UP`.
2. Passer une commande de test → un ticket doit sortir tout seul à la confirmation /
   à l'écran « Rendez-vous en caisse ».
3. Si rien ne sort : F12 sur la page → chercher « Local Network Access » / « 9100 » dans la
   console (flag Chrome manquant) ; sinon vérifier que le pont tourne (`/health`).

## 5. Diagnostic « pas de ticket »

| Symptôme console | Cause | Fix |
|---|---|---|
| `Permission … loopback address space` | flag Chrome absent | §2 |
| `Failed to fetch` sans message loopback | pont arrêté / port 9100 pris | relancer `bridge.js` |
| violation CSP `connect-src` (bloquant) | CSP passée en enforce sans loopback | §3 (déjà patché) |
| ticket vide/charabia | codepage / encodage | côté `bridge.js` (ASCII-fold appliqué côté front) |

## 6. Robustesse applicative (déjà en place)

- Si le pont est injoignable au montage → événement backend `printer_failure` + bouton
  « Réimprimer » à l'écran (plus de no-print silencieux).
- Garde anti-double-impression persistée (localStorage, clé order#+jour) : pas de 2ᵉ ticket
  au F5 / re-montage, pas de collision au rollover quotidien des numéros.
