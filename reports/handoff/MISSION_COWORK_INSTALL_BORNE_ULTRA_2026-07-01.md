# MISSION COWORK (ULTRA) — Installer la BORNE (kiosk) correctement, de A à Z

> Cible : la machine BORNE (Windows, ex. DESKTOP-QR70CJ8 / borne 108683978), Chrome plein écran
> pointant vers le cloud `https://lecayenne.fr`. Objectif : commande client → paiement en caisse
> (Plan B) → ticket imprimé → cuisine (KDS), sans faute, une fois pour toutes.
> Branche déployée : `pos/category-first-caisse-2026-06-23`, HEAD **`258f74722`**.

---

## ÉTAPE 0 — PRÉREQUIS SERVEUR (à faire AVANT de toucher la borne)
La borne ne fait que charger le cloud. Le cloud doit d'abord être à jour :
1. Déployer la branche (cf. `MISSION_COWORK_INSTALL_DEFINITIVE`) — sinon la borne charge l'ancien code.
   ```bash
   ssh lecayenne
   cd /var/www/lecayenne
   sudo LECAYENNE_BRANCH=pos/category-first-caisse-2026-06-23 bash scripts/deploy/deploy.sh
   git rev-parse --short HEAD    # doit == 258f74722 (ou +)
   ```

---

## ÉTAPE 1 — ENREGISTRER LA BORNE CÔTÉ SERVEUR (machine kiosk + identifiants)
La borne s'auto-connecte avec un compte MACHINE (pas un humain). Il faut une `KioskMachine` ACTIVE.

### 1a. Créer / vérifier la machine kiosk (Admin → Réglages → Bornes, OU tinker)
```bash
php artisan tinker
>>> \App\Models\KioskMachine::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->get(['id','name','username','status','branch_id']);
# S'il n'y en a pas une ACTIVE (status=1) sur branch_id=1, en créer une via l'admin
# (écran « Bornes ») avec un username + password que tu notes.
>>> exit
```

### 1b. `.env` du VPS — identifiants d'auto-login + réglages borne
Ajouter/vérifier dans `/var/www/lecayenne/.env` (⚠️ jamais commité) :
```env
KIOSK_MACHINE_USERNAME=<le username de la machine 1a>
KIOSK_MACHINE_PASSWORD=<le password de la machine 1a>
KIOSK_PAYMENT_ROUTE_ALL_TO_COUNTER=true    # Plan B : le client paie en caisse
KIOSK_DEFAULT_LOCALE=fr                     # FR-lock (obligatoire ADR-007)
KIOSK_LOCALE_SWITCH_ALLOWED=false
KIOSK_REQUIRE_MACHINE_LOGIN=false          # pas d'écran login machine (auto)
```
Puis :
```bash
php artisan config:clear && php artisan config:cache
```
> Ces identifiants sont injectés dans `window.foodkingConfig` UNIQUEMENT sur les requêtes `/kiosk*`
> → la borne s'auto-connecte, le client ne voit aucun formulaire. Ne PAS exposer ces valeurs ailleurs.

---

## ÉTAPE 2 — LA MACHINE BORNE (Windows)

### 2a. Vider le cache + les service workers (fini l'« ancienne version » collée)
1. Fermer Chrome complètement.
2. Rouvrir, aller sur `chrome://serviceworker-internals` → **Unregister** tout ce qui pointe vers lecayenne.fr.
3. `chrome://settings/clearBrowserData` → vider images/fichiers en cache.

### 2b. Lancer Chrome en mode BORNE (plein écran + flags impression)
Créer un raccourci Chrome avec ces arguments (la cible du raccourci) :
```
"C:\Program Files\Google\Chrome\Application\chrome.exe" --kiosk --start-fullscreen
  --app=https://lecayenne.fr/kiosk/idle
  --disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks
  --disable-pinch --overscroll-history-navigation=0 --disable-translate
  --noerrdialogs --disable-session-crashed-bubble --incognito
```
> Le flag `--disable-features=...LocalNetworkAccessChecks` est **INDISPENSABLE** : sans lui, Chrome
> (récent) bloque l'appel HTTPS public → `127.0.0.1` du pont d'impression → le ticket ne s'imprime pas.
> `--kiosk` = plein écran verrouillé. `/kiosk/idle` = l'écran d'accueil « Touchez pour commander ».

### 2c. Auto-démarrage (la borne redémarre = Chrome relance tout seul)
Placer le raccourci 2b dans `shell:startup` (Win+R → `shell:startup`).

---

## ÉTAPE 3 — IMPRESSION TICKET (pont local ESC/POS)
La borne imprime le MÊME ticket serveur que la caisse (renderer unifié). Deux options :

### Option A — pont léger local (Chrome, recommandé si pas d'app Electron)
- Lancer le pont d'impression local qui écoute `http://127.0.0.1:9100` et accepte `POST /raw`
  (relaie les octets ESC/POS → imprimante SAGA USB). Vérifier `http://127.0.0.1:9100/health` → « UP ».
- Le flag Chrome de 2b autorise l'appel borne→pont.

### Option B — app Electron `window.borne`
- Si la borne tourne dans un wrapper Electron exposant `window.borne.printReceipt(orderData)`,
  l'impression passe par l'IPC Electron (pas besoin du pont HTTP). `kioskPrinter.js` détecte
  automatiquement (`window.borne` prioritaire, sinon pont HTTP, sinon `window.print`).

Dans les deux cas : `.env` VPS `PRINT_DRIVER=windows_raw` + imprimante déclarée
(`php artisan pos:setup-receipt-printer "<NOM_SAGA>"`) + `printer.width_chars=32` (58mm anti-coupure).

---

## ÉTAPE 4 — VÉRIFIER LA BORNE EN RÉEL (chaque point, sinon ne pas clore)

1. **Écran d'accueil** : la borne affiche l'attract « Touchez l'écran pour commander » (pas d'écran blanc,
   pas de formulaire login). Si écran blanc → cache/service worker (2a) ou bundle incomplet (redéployer).
2. **Prise de commande** : toucher → menu → composer un **multi-viandes (Tacos L / Méga : 2 viandes)** →
   AUCUNE erreur « Sélectionnez au moins 1 Viande 2 » (bug corrigé), les 2 viandes au panier.
3. **Recette fixe** : Cayenne / burgers → PAS d'étape « choisir viande » (normal).
4. **Suppléments + menu** : ajouter Cheddar + menu → prix corrects, +2,50€ viande suppl si ajoutée.
5. **Paiement** : valider → écran « **payer en caisse** » (Plan B) → numéro **#A00xx** en GRAND.
6. **Synchro caisse** : sur la caisse, la commande apparaît dans « **À ENCAISSER BORNE** » avec bouton Encaisser.
7. **Cuisine (KDS)** : la commande apparaît sur l'écran cuisine en **symboles** avec badge « EN ATTENTE
   ENCAISSEMENT » ; après encaissement en caisse → badge « réglé ».
8. **Ticket** : à l'encaissement, le **ticket client** s'imprime (pont ESC/POS) sans coupure.

---

## ÉTAPE 5 — À RAPPORTER (photos)
- `git HEAD` VPS == `258f74722`.
- Borne : attract OK, multi-viandes OK (2 viandes), #A00xx affiché.
- Caisse : commande borne dans « À ENCAISSER BORNE ».
- KDS : symboles + badge « en attente » puis « réglé ».
- Ticket imprimé (sans coupure).
- **Tout écran blanc / erreur / non-impression → photo + étape + (F12→Console/Réseau si accessible).
  Ne pas conclure « bug » sans avoir vérifié le HEAD VPS + vidé cache/service worker.**

> Rappels des causes récurrentes déjà connues : écran blanc = bundle incomplet (déployer le JEU COMPLET,
> pas un sous-ensemble) OU service worker de l'ancienne version ; ticket coupé = `width_chars` ≠ 32 ou
> pont ESC/POS injoignable ; « ça ne marche pas » au paiement = ancien code VPS.
