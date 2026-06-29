# MISSION 3 (FINALE) — COWORK : installation kiosk « fer » + test complet

> À coller à Claude cowork (AnyDesk, poste BORNE Le Cayenne).
> Objectif : installer la borne pour qu'elle s'ouvre SEULE en plein écran au démarrage,
> avec une icône bureau pour la relancer, impossible à fermer pour le client, le cache
> géré tout seul (zéro entretien), puis tester une vraie commande de bout en bout.

---

## 0. CE QUI A ÉTÉ CORRIGÉ CÔTÉ SERVEUR (déjà fait par le dev)
- **Cache** : le lanceur ci-dessous vide le cache à CHAQUE ouverture → la borne prend
  toujours la dernière version automatiquement. **L'owner n'a JAMAIS à vider quoi que ce
  soit.** Plus jamais de « configurateur vide / ChunkLoadError ».
- **WebSocket** : le bruit « 401 » est corrigé (la borne bascule proprement sur la synchro
  par polling 5 s, sans spam). La synchro vers l'écran cuisine fonctionne.
- Tous les fichiers JS sont déployés et vérifiés identiques (md5) sur le VPS.

---

## 1. INSTALLATION EN UNE FOIS (copier-coller dans PowerShell)

Ouvre **PowerShell** sur la borne et colle CE BLOC ENTIER (il crée le lanceur, l'icône
bureau, le démarrage automatique et le watchdog) :

```powershell
$dir = "C:\LeCayenne"; New-Item -ItemType Directory -Force -Path $dir | Out-Null
$bat = @'
@echo off
set "CHROME=C:\Program Files\Google\Chrome\Application\chrome.exe"
if not exist %CHROME% set "CHROME=C:\Program Files (x86)\Google\Chrome\Application\chrome.exe"
set "URL=https://vps-418872ac.vps.ovh.net/kiosk?machine_key=lcb-227b5373163391c875eeb43f7ee1affe3972"
set "PROFILE=%LOCALAPPDATA%\LeCayenneKiosk"
rmdir /s /q "%PROFILE%\Default\Cache" 2>nul
rmdir /s /q "%PROFILE%\Default\Code Cache" 2>nul
rmdir /s /q "%PROFILE%\Default\Service Worker" 2>nul
start "" %CHROME% --kiosk "%URL%" --user-data-dir="%PROFILE%" --disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks --kiosk-printing --disable-pinch --overscroll-history-navigation=0 --disable-session-crashed-bubble --noerrdialogs --disable-infobars --disable-translate --no-first-run --hide-crash-restore-bubble
'@
Set-Content -Path "$dir\start-kiosk.bat" -Value $bat -Encoding ASCII
$wd = 'if (-not (Get-Process chrome -ErrorAction SilentlyContinue)) { Start-Process "C:\LeCayenne\start-kiosk.bat" }'
Set-Content -Path "$dir\watchdog-kiosk.ps1" -Value $wd -Encoding ASCII
$ws = New-Object -ComObject WScript.Shell
foreach ($loc in @(([Environment]::GetFolderPath("Desktop")+"\BORNE Le Cayenne.lnk"), ([Environment]::GetFolderPath("Startup")+"\BORNE Le Cayenne.lnk"))) {
  $sc = $ws.CreateShortcut($loc); $sc.TargetPath = "$dir\start-kiosk.bat"; $sc.WorkingDirectory = $dir
  $sc.IconLocation = "$env:ProgramFiles\Google\Chrome\Application\chrome.exe,0"; $sc.Save()
}
$action  = New-ScheduledTaskAction -Execute "powershell.exe" -Argument "-WindowStyle Hidden -ExecutionPolicy Bypass -File C:\LeCayenne\watchdog-kiosk.ps1"
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 2)
Register-ScheduledTask -TaskName "LeCayenneKioskWatchdog" -Action $action -Trigger $trigger -RunLevel Highest -Force | Out-Null
Write-Host "INSTALL OK : lanceur + icone bureau + demarrage auto + watchdog crees."
```

Tu dois voir : **`INSTALL OK : ...`**. Résultat :
- **Icône « BORNE Le Cayenne »** sur le bureau (pour relancer en un clic).
- **Démarrage automatique** : au boot Windows, la borne s'ouvre seule en plein écran.
- **Watchdog** : si Chrome se ferme/plante, il est relancé en ≤ 2 min.
- **Plein écran verrouillé** (`--kiosk`) : le client ne peut pas sortir.

### Lancer tout de suite
Double-clique l'icône bureau **« BORNE Le Cayenne »** (ou exécute `C:\LeCayenne\start-kiosk.bat`).
→ Chrome s'ouvre en plein écran sur la page d'accueil Le Cayenne.

### Pour QUITTER (toi seulement, pas le client)
**Alt+F4**. (C'est volontaire : le client ne peut pas fermer la borne.)

---

## 2. LE PONT D'IMPRESSION (doit tourner pour les tickets)
1. `bridge.js` doit tourner : `curl http://127.0.0.1:9100/health` → `UP`. Mets-le aussi
   en démarrage automatique (`Win+R` → `shell:startup` → y mettre son raccourci) s'il n'y est pas.
2. (Si pas déjà fait) appliquer le patch « gros texte » du runbook `BORNE_BRIDGE_BIGGER_TEXT.md`
   (le pont lit `payload.bodySize`).
3. SK1-31 (borne/cuisine) branchée + papier ; SAGA (caisse) branchée + papier + tiroir.

---

## 3. TEST COMPLET (vraie commande, bout en bout)

> Le dev a déjà validé en logiciel (commande composée placée, composition scellée en base,
> impression bodySize OK, tickets client/cuisine corrects). Toi tu valides le PHYSIQUE.

1. **Commande borne composée** sur l'écran TACTILE : un Tacos L (2 viandes différentes) +
   1 supplément payant + 1 sauce + formule menu → jusqu'au paiement (Plan B → caisse).
   - Vérifier que le configurateur affiche bien les viandes (plus de vide).
2. **Ticket CLIENT (borne)** : sort du SK1-31 → toutes les étapes en toutes lettres
   (viandes, sauce, crudités, supplément, formule) + n° de commande GROS + lisible. **Photo.**
3. **Ticket CUISINE** : format symbolique 3 lignes (L1 produit|viandes|crudités STO|sauce /
   L2 supplément / L3 MENU). **Photo.**
4. **Écran KDS** : la commande apparaît (en ≤ 5 s via polling, c'est normal) au bon ordre,
   **un seul item fusionné** (pas de doublure). Compare le KDS au ticket cuisine : **mêmes
   symboles**. **Photo des deux.**
5. **Sauvegarde / synchro** : refais 2-3 commandes → vérifie qu'elles sont **toutes
   enregistrées** (visibles à la caisse `/admin/pos`) et apparaissent **toutes** au KDS, en
   ordre. **Photo.**
6. **Caisse** : encaisser une commande → **reçu imprimé** (SAGA) + **tiroir s'ouvre**
   (espèces) + numéro fiscal qui s'incrémente. Tester le **choix d'impression** : imprimer
   le ticket CLIENT seul, le ticket CUISINE seul, puis LES DEUX. **Photos.**
7. **Robustesse** : ferme Chrome (Alt+F4) → vérifie que le **watchdog le relance** seul en
   ≤ 2 min. Redémarre la borne → vérifie qu'elle **s'ouvre seule** en plein écran.

---

## 4. CE QU'IL FAUT RENVOYER (rapport final)
- Confirmation : `INSTALL OK` + icône bureau présente + démarrage auto OK + watchdog OK.
- Confirmation : la borne s'ouvre seule en plein écran au boot, impossible à fermer (sauf Alt+F4).
- Photos : ticket client, ticket cuisine, écran KDS (à côté du ticket cuisine), reçu caisse.
- Pour 2-3 commandes : toutes enregistrées (caisse) + toutes au KDS en ordre ? OUI/NON.
- Tout problème restant (avec photo + n° de commande).

**Ne modifie aucun fichier serveur ni `.env`. Tu installes (bloc PowerShell), tu lances, tu testes, tu photographies, tu rapportes.**
