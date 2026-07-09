# MISSION 2 — COWORK : Setup kiosk PROPRE + déblocage + re-test

> À coller à Claude cowork (AnyDesk poste borne Le Cayenne).
> Contexte : le 1er test a marché (écran d'accueil OK) mais 2 blocages + un setup
> kiosk incomplet. Voici le diagnostic du dev + ce qu'il faut faire, dans l'ordre.

---

## 0. DIAGNOSTIC DU DEV (lis d'abord — ça change ce que tu dois faire)

1. **Le « ChunkLoadError » (configurateur Tacos vide) N'EST PAS un bug serveur.**
   Le dev a vérifié : **tous** les fichiers JS (y compris les chunks `kiosk-wizard.js`,
   `325.js`, `52.js`, `968.js`…) sont présents sur le VPS, servis en `200 application/javascript`,
   et **identiques (md5) à la référence**. → La cause est le **CACHE de Chrome de la borne** :
   elle a gardé un `app.js`/chunk d'un état intermédiaire (pendant le bug vendor.js d'hier).
   **Fix = vider le cache Chrome (pas seulement les Local Overrides).**

2. **Le « WebSocket 401 » (Pusher/realtime)** est un réglage serveur séparé. Il **N'EMPÊCHE
   PAS** de prendre une commande ni d'imprimer : la borne commande en HTTP, et l'écran KDS a
   un **repli par polling** (la commande apparaît, juste pas en temps réel instantané). On le
   traite après. Ne bloque PAS le test d'impression.

3. **« Impossible de fermer sauf au clavier » = NORMAL.** Le mode kiosk (`--kiosk`) est plein
   écran exprès, pour que le client ne puisse pas en sortir. **On quitte avec `Alt+F4`** (ou
   `Ctrl+W`). Ce n'est pas un bug. Ce qui manquait : l'icône bureau + le lancement propre.

---

## 1. DÉBLOCAGE IMMÉDIAT (vider le cache + relancer proprement)

But : refaire fonctionner le configurateur tout de suite.

1. **Fermer Chrome complètement** (toutes les fenêtres). Dans PowerShell :
   ```powershell
   Get-Process chrome -ErrorAction SilentlyContinue | Stop-Process -Force
   ```
2. **Supprimer le cache** du profil kiosk dédié (et l'ancien profil par défaut, par sécurité) :
   ```powershell
   Remove-Item -Recurse -Force "$env:LOCALAPPDATA\LeCayenneKiosk" -ErrorAction SilentlyContinue
   Remove-Item -Recurse -Force "$env:LOCALAPPDATA\Google\Chrome\User Data\Default\Cache" -ErrorAction SilentlyContinue
   Remove-Item -Recurse -Force "$env:LOCALAPPDATA\Google\Chrome\User Data\Default\Code Cache" -ErrorAction SilentlyContinue
   ```
3. **Lancer le kiosk propre** (voir le `.bat` §2). Au chargement, ouvrir le configurateur
   Tacos L → les viandes doivent maintenant s'afficher (plus de ChunkLoadError).

> Si le ChunkLoadError persiste APRÈS cache vidé : ouvre F12 → onglet Network → recharge,
> repère la ligne `/js/....js` en rouge, note son **nom exact** + son **code** (404 ? 0 ?) +
> son **Content-Type**, et envoie-le au dev. (Le dev a déjà prouvé que tous les chunks sont
> en 200/JS depuis l'extérieur ; si la borne voit autre chose, c'est réseau local/proxy.)

---

## 2. SETUP KIOSK PROPRE (icône bureau + plein écran + cache frais)

Crée un fichier **`C:\LeCayenne\start-kiosk.bat`** avec EXACTEMENT ce contenu :

```bat
@echo off
REM ===== Kiosk Le Cayenne - lanceur =====
set "CHROME=C:\Program Files\Google\Chrome\Application\chrome.exe"
if not exist %CHROME% set "CHROME=C:\Program Files (x86)\Google\Chrome\Application\chrome.exe"
set "URL=https://vps-418872ac.vps.ovh.net/kiosk?machine_key=lcb-227b5373163391c875eeb43f7ee1affe3972"
set "PROFILE=%LOCALAPPDATA%\LeCayenneKiosk"

REM Vider le cache a chaque lancement (evite les ChunkLoadError de cache perime apres une MAJ serveur)
rmdir /s /q "%PROFILE%\Default\Cache" 2>nul
rmdir /s /q "%PROFILE%\Default\Code Cache" 2>nul
rmdir /s /q "%PROFILE%\Default\Service Worker" 2>nul

start "" %CHROME% --kiosk "%URL%" ^
 --user-data-dir="%PROFILE%" ^
 --disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks ^
 --kiosk-printing ^
 --disable-pinch --overscroll-history-navigation=0 ^
 --disable-session-crashed-bubble --noerrdialogs --disable-infobars ^
 --disable-translate --no-first-run --fast --fast-start --hide-crash-restore-bubble
```

Pourquoi chaque option :
- `--kiosk` : vrai plein écran, pas de barre, le client ne peut pas sortir.
- `--user-data-dir=...LeCayenneKiosk` : **profil dédié** (isolé, pas pollué par tes sessions, pas d'extension MetaMask qui injecte des erreurs comme on a vu).
- `--disable-features=...LocalNetworkAccess...` : autorise l'appel au pont d'impression `127.0.0.1:9100` (sinon pas d'impression).
- `--kiosk-printing` : impression sans dialogue.
- le `rmdir Cache` en tête : **vide le cache à chaque lancement** → plus jamais de ChunkLoadError de cache après une mise à jour serveur.

### Icône sur le bureau
1. Clic droit sur le bureau → **Nouveau → Raccourci** → cible : `C:\LeCayenne\start-kiosk.bat` → nommer **« BORNE Le Cayenne »**.
2. (Option icône jolie) Propriétés du raccourci → Changer d'icône.

### Démarrage automatique au boot
Copier le raccourci dans le dossier démarrage : touche `Win+R` → taper `shell:startup` → Entrée →
y coller le raccourci **« BORNE Le Cayenne »**. La borne se lancera seule à chaque démarrage Windows.

### Pour QUITTER le kiosk (toi, pas le client)
- **Alt+F4** (ferme Chrome kiosk). Ou `Ctrl+W`.
- Pour reprendre la main sans fermer : `Alt+Tab` ou touche Windows.

---

## 3. WATCHDOG (relance auto si Chrome meurt)

Crée **`C:\LeCayenne\watchdog-kiosk.ps1`** :
```powershell
$proc = Get-Process chrome -ErrorAction SilentlyContinue |
        Where-Object { $_.MainWindowTitle -ne '' -or $_.Path -like '*chrome.exe' }
if (-not (Get-Process chrome -ErrorAction SilentlyContinue)) {
    Start-Process "C:\LeCayenne\start-kiosk.bat"
}
```

Planifier (toutes les 2 min), dans PowerShell admin :
```powershell
$action  = New-ScheduledTaskAction -Execute "powershell.exe" -Argument "-WindowStyle Hidden -ExecutionPolicy Bypass -File C:\LeCayenne\watchdog-kiosk.ps1"
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 2)
Register-ScheduledTask -TaskName "LeCayenneKioskWatchdog" -Action $action -Trigger $trigger -RunLevel Highest -Force
```
→ Si Chrome disparaît (crash, fermeture accidentelle), il est relancé en mode kiosk en ≤ 2 min.

---

## 4. PONT D'IMPRESSION (rappel — doit tourner pour les tickets)

- `bridge.js` doit tourner (`curl http://127.0.0.1:9100/health` → `UP`). Le mettre aussi
  dans `shell:startup` s'il n'y est pas.
- (Optionnel mais recommandé) appliquer le patch « gros texte » du runbook
  `BORNE_BRIDGE_BIGGER_TEXT.md` (lire `payload.bodySize`).

---

## 5. RE-TEST (une fois le kiosk propre + cache vidé)

Reprends la **MISSION 1** (`MISSION_COWORK_TEST_REEL_BORNE_CAISSE`) à partir de T1 :
1. **T1** Coca seul, **T2** Cheese Burger (sauce+crudités), **T3** Tacos L 2 viandes différentes + supplément + formule. → vérifier que le **configurateur affiche bien les viandes** (le bug #1 doit être parti).
2. Pour chaque : photo du **ticket client** (détaillé) + du **ticket cuisine** (symbolique 3 lignes) + de l'**écran KDS**.
3. Encaissement caisse (E1-E7) + choix d'impression (client / cuisine / les deux).
4. Remplir le tableau du rapport (T, S, E, A) + verdict VALIDÉ/PROBLÈME par domaine.

### Sur le KDS / temps réel
- La commande **doit apparaître** au KDS (même si pas instantané — repli polling). Note le
  **délai** (instantané ? ~10-30 s ?). Si elle n'apparaît PAS du tout après 60 s → noter
  (le dev traitera le WebSocket 401).
- Le WebSocket 401 en console est **attendu pour l'instant** (réglage Pusher serveur à part).
  Ne pas chercher à le corriger côté borne.

---

## 6. CE QU'IL FAUT ME RENVOYER
- Confirmation : configurateur Tacos affiche les viandes après cache vidé (OUI/NON + photo).
- Le rapport rempli (T1-T10, S, E, A) + photos des tickets et du KDS.
- Si un chunk JS reste en rouge dans Network : son **nom exact + code + content-type**.
- Confirmation que l'icône bureau + démarrage auto + watchdog sont en place.

**Ne modifie aucun fichier serveur, aucun `.env`. Tu installes le lanceur, tu vides le cache, tu testes, tu rapportes.**
