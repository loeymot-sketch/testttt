# 🍳🖥️ MISSION COWORK — Transformer un vieux PC en ÉCRAN DE CUISINE (KDS) tactile dédié

> But owner : recycler un vieux PC (écran **tactile**) en **écran de cuisine permanent**. Au démarrage,
> il doit **ouvrir tout seul le KDS en plein écran, déjà connecté** (sans rien taper), afficher **toutes
> les commandes**, avec le bouton **« Prêt »** ; quand le cuisinier tape « Prêt » → ça remonte à
> **l'écran client** (prête) et à la **caisse** (sortie/livrée). **Survit au redémarrage, sans délai, sans
> bug, sans problème d'ancienne version/cache/historique** (l'historique se consulte en caisse). Piloté
> par le cowork **via AnyDesk** sur ce PC (Windows).

> ⚠️ Le KDS lui-même ne fait qu'AFFICHER + BUMP (bouton Prêt) ; les mises à jour vers l'écran client et la
> caisse sont **serveur** (events). Ce PC a juste besoin de : réseau + Chrome + rester connecté au chef.

---

## §0 — PLAYBOOK AnyDesk (si pilotage à distance, rappel)
- **AZERTY** → ne jamais taper, **coller** (AnyDesk → Actions → « Insérer à partir du presse-papiers », ou
  clic-DROIT dans une console PowerShell). Ouvrir **PS admin** : Ctrl+Alt+Suppr → Gestionnaire des tâches →
  Fichier → Exécuter une nouvelle tâche → coller `powershell` → **cocher admin** → OK.
- Les scripts tournent SUR le PC → survivent à une coupure AnyDesk.

## §1 — SERVEUR (VPS) : garder le chef CONNECTÉ en permanence (indispensable)
Le point clé pour « toujours prêt au démarrage » : par défaut la session admin expire après **120 min**
d'inactivité → une nuit éteint = déconnecté au reboot. On l'allonge (install LOCAL mono-poste — acceptable).
Sur le VPS, dans `/var/www/lecayenne/.env` :
```env
SESSION_LIFETIME=525600      # 1 an (minutes) → le chef reste connecté à travers les reboots
```
puis :
```bash
ssh lecayenne 'cd /var/www/lecayenne && php artisan config:cache && \
php artisan queue:work --queue=high,default >/dev/null 2>&1 & echo "worker lance (mettre en service systemd pour du permanent)"'
```
> Le **worker `--queue=high,default`** doit tourner en permanence (service systemd) : c'est lui qui propage
> « Prêt » vers l'écran client + la caisse **sans délai**. Sans lui → mises à jour lentes (polling).
> Vérifier aussi que le compte **chef existe** et connaît la branche : `chef@lecayenne.fr` (rôle Chef, branche 1).
> Si mot de passe inconnu, le réinitialiser depuis l'admin (Utilisateurs → Chefs).

## §2 — LE PC CUISINE : NETTOYER les démarrages automatiques de l'ancien système
Ouvrir PowerShell admin (§0) et coller ce bloc (désactive le superflu au boot — SANS casser Windows) :
```powershell
# 1) Applications au démarrage (dossier Startup utilisateur + All Users)
Write-Host "=== Startup folders (a vider des vieux raccourcis) ==="
ls ([Environment]::GetFolderPath(7)); ls "$env:ProgramData\Microsoft\Windows\Start Menu\Programs\StartUp"
# 2) Entrees Run du registre (lister — puis retirer les indesirables un par un, ne PAS tout supprimer a l'aveugle)
Write-Host "=== Run (HKCU) ==="; Get-ItemProperty "HKCU:\Software\Microsoft\Windows\CurrentVersion\Run" -EA 0
Write-Host "=== Run (HKLM) ==="; Get-ItemProperty "HKLM:\Software\Microsoft\Windows\CurrentVersion\Run" -EA 0
# 3) Taches planifiees actives non-Microsoft (candidates a desactiver)
Write-Host "=== Taches planifiees non-Microsoft actives ==="
Get-ScheduledTask | ? { $_.State -eq 'Ready' -and $_.TaskPath -notlike '\Microsoft\*' } | Select TaskName,TaskPath | Format-Table -Auto
Write-Host "DONE_LISTE_AUTOSTART"
```
- Via le **Gestionnaire des tâches → onglet « Démarrage »** : **Désactiver** tout ce qui n'est pas
  nécessaire (anciens softs, updaters, barres d'outils…). Garder Windows + Chrome.
- **Ne PAS** supprimer aveuglément des services Windows. Se limiter aux apps tierces au démarrage.
- Supprimer dans les dossiers Startup les **vieux raccourcis** de l'ancien usage.

## §3 — LE PC CUISINE : APPLIANCE 24/7 (pas de veille, pas de reboot surprise, auto-login Windows)
Coller ce bloc (PS admin) :
```powershell
# Ne JAMAIS mettre en veille / eteindre l'ecran (secteur)
powercfg /change standby-timeout-ac 0
powercfg /change monitor-timeout-ac 0
powercfg /change hibernate-timeout-ac 0
powercfg /change disk-timeout-ac 0
# Pas de veille prolongee
powercfg /hibernate off
# Windows Update : ne pas redemarrer tout seul pendant le service (heures actives large)
Set-ItemProperty "HKLM:\SOFTWARE\Microsoft\WindowsUpdate\UX\Settings" -Name "ActiveHoursStart" -Value 6  -EA 0
Set-ItemProperty "HKLM:\SOFTWARE\Microsoft\WindowsUpdate\UX\Settings" -Name "ActiveHoursEnd"   -Value 23 -EA 0
Write-Host "DONE_APPLIANCE"
```
- **Auto-login Windows** (pour qu'au boot on arrive direct au bureau, sans mot de passe) : `Win+R` →
  `netplwiz` → décocher « Les utilisateurs doivent entrer un nom… » → OK → saisir le mot de passe du compte.
  (Sur écran tactile sans clavier : brancher un clavier USB le temps du setup.)
- **Désactiver le verrouillage/écran de veille** : Paramètres → Comptes → Options de connexion →
  « Exiger une connexion » = Jamais ; Personnalisation → Écran de verrouillage → écran de veille = Aucun.

## §4 — CHROME : nettoyer l'ancien cache + créer un profil KDS PROPRE et CONNECTÉ
```powershell
# fermer Chrome + purger cache/service workers de l'ancien usage (evite "ancienne version"/historique parasite)
Get-Process chrome -EA 0 | Stop-Process -Force; Start-Sleep 2
$cd = $env:LOCALAPPDATA + "\Google\Chrome\User Data\Default"
Remove-Item -Recurse -Force -EA 0 -Path ($cd + "\Service Worker")
Remove-Item -Recurse -Force -EA 0 -Path ($cd + "\Cache")
Remove-Item -Recurse -Force -EA 0 -Path ($cd + "\Code Cache")
Remove-Item -Recurse -Force -EA 0 -Path ($cd + "\Cache Storage")
Write-Host "DONE_PURGE_CHROME"
```
Puis **connexion CHEF (une seule fois)** :
1. Ouvrir Chrome (fenêtré) → `https://<VPS>/login` → se connecter **chef@lecayenne.fr** / (mot de passe chef).
   Cocher « Se souvenir de moi » si présent.
2. Aller sur `https://<VPS>/admin/kitchen-display-system` → le KDS s'affiche avec les commandes.
3. `chrome://serviceworker-internals` → **Unregister** toute entrée du domaine (pour toujours charger le
   bundle actuel) → revenir au KDS → **Ctrl+Shift+R** (recharge dure).
> ⚠️ NE PAS vider les cookies APRÈS la connexion (sinon le chef est déconnecté). Le §1 (SESSION_LIFETIME 1 an)
> + ce profil persistant = le chef **reste connecté au boot**.

## §5 — LANCER LE KDS EN PLEIN ÉCRAN AU DÉMARRAGE (startup)
Créer le raccourci de démarrage (PS admin). Remplacer `<VPS>` (ex. `vps-418872ac.vps.ovh.net`) :
```powershell
$sp = [Environment]::GetFolderPath(7)   # dossier Startup
$k = 'CreateObject("WScript.Shell").Run "C:\PROGRA~1\Google\Chrome\Application\chrome.exe --kiosk --start-fullscreen --app=https://<VPS>/admin/kitchen-display-system --noerrdialogs --disable-session-crashed-bubble --disable-translate --overscroll-history-navigation=0 --disable-pinch", 1, False'
Set-Content ($sp + "\cuisine-kds.vbs") ("WScript.Sleep 5000`r`n" + $k) -Encoding ASCII
Write-Host "=== cuisine-kds.vbs cree ==="; Get-Content ($sp + "\cuisine-kds.vbs")
Write-Host "DONE_STARTUP_KDS"
```
- `--kiosk --start-fullscreen` = plein écran verrouillé (pas de barre, pas d'onglet).
- `--disable-session-crashed-bubble` = pas de bandeau « Chrome s'est mal fermé ».
- Lancer tout de suite pour tester (sans reboot) :
```powershell
$a = "--kiosk --start-fullscreen --app=https://<VPS>/admin/kitchen-display-system --noerrdialogs --disable-session-crashed-bubble --disable-translate"
Start-Process "C:\Program Files\Google\Chrome\Application\chrome.exe" -ArgumentList ($a -split " ")
```

## §6 — AFFICHAGE : tout visible, tactile, texte lisible
- Régler la **résolution** de l'écran à sa valeur native, puis ajuster le **zoom Chrome** (Ctrl + / Ctrl -)
  pour que les cartes de commande soient grosses et lisibles à distance, et que le maximum de commandes
  tienne à l'écran. Le zoom est mémorisé par le profil.
- Le **tactile** : le bouton « Prêt » se tape au doigt ; le défilement se fait au doigt si beaucoup de
  commandes. *(Le KDS affiche TOUTES les commandes actives ; s'il y en a plus que l'écran, ça défile au
  doigt. Un affichage « jamais de scroll » quel que soit le nombre est un réglage produit à voir avec le dev
  si tu le veux garanti — l'install ci-dessus tient tout le volume normal sans scroll via le zoom.)*
- Optionnel : masquer le clavier tactile Windows qui pourrait surgir (Paramètres → Heure et langue →
  Saisie → désactiver le clavier tactile automatique).

## §7 — TEST E2E RÉEL (la chaîne cuisine → client → caisse)
1. Passer une commande (borne ou caisse) → ✅ elle **apparaît sur le KDS** cuisine (format symbolique, toutes
   les lignes visibles).
2. Sur le KDS, taper **« Prêt »** sur la carte → ✅ la commande passe « prête » ;
   → ✅ **l'écran client (OSS)** affiche « prête » ; → ✅ la **caisse** reflète « sortie/livrée ».
3. **Consulter l'historique EN CAISSE** (`/admin/historique` ou l'écran commandes) → ✅ la commande sortie
   y est retrouvable (le KDS n'a pas à garder l'historique ; c'est la caisse qui l'archive).
4. **Test du redémarrage (le vrai test « install définitive »)** :
   ```powershell
   Restart-Computer -Force
   ```
   Au boot → Windows auto-login → au bout de ~5 s, **Chrome s'ouvre seul en plein écran sur le KDS, déjà
   connecté, toutes les commandes affichées**, sans taper quoi que ce soit, sans écran /login. ✅

## §8 — SI au reboot ça tombe sur /login (session perdue) — FALLBACK
Cela ne doit pas arriver avec §1 (SESSION_LIFETIME 1 an) + profil persistant. Si ça arrive quand même :
- Vérifier que le `.env` VPS a bien `SESSION_LIFETIME=525600` + `php artisan config:cache` (§1).
- Vérifier qu'on n'a pas vidé les cookies du profil après la connexion (§4).
- Dernier recours (auto-remplissage) : installer **AutoHotkey** et un petit script qui, s'il détecte la
  page /login, saisit chef + mot de passe et valide. (À n'utiliser que si le reste échoue — me le signaler.)

## §9 — À RAPPORTER (photos)
- `DONE_APPLIANCE` / `DONE_PURGE_CHROME` / `DONE_STARTUP_KDS`.
- Le PC **au reboot** ouvre le KDS seul, plein écran, connecté, commandes affichées (photo).
- Chaîne prouvée : commande → KDS → **Prêt** → écran client « prête » + caisse « sortie ».
- Historique retrouvé **en caisse**.
- `chrome://serviceworker-internals` vide pour le domaine · zoom réglé (lisible) · tactile OK.
- Tout blocage → photo + message exact + à quelle étape.

> Résumé : **§1 session longue + worker** (serveur) · **§2-§3 PC assaini + appliance 24/7 + auto-login** ·
> **§4 profil Chrome propre + chef connecté une fois** · **§5 KDS plein écran au boot** · **§7 test + reboot**.
> Résultat : au démarrage, l'écran de cuisine affiche tout, connecté, sans délai ni bug, et le bouton Prêt
> propage à l'écran client + la caisse.
