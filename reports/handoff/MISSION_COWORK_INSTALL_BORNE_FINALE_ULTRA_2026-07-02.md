# 🖲️ MISSION COWORK (ULTRA-FINALE) — Installer la BORNE (kiosk libre-service) + ticket long

> Machine BORNE (Windows) → Chrome **kiosk plein écran** → `/kiosk?machine_key=…` (VPS OVH). But :
> la borne tourne SEULE, sans terminal/fenêtre ouverte qui traîne, prend une commande, l'envoie en
> caisse + KDS, et imprime un **ticket client LONG (~15-20 cm, coupe partielle → ne tombe PLUS par
> terre)**. Tout validé côté code (e2e adversarial + tests) ; reste l'install machine + e2e réel.
> **Branche `pos/category-first-caisse-2026-06-23`, HEAD `e5244b120`.**

> ✅ Corrigé cette session (poussé) : le ticket borne long+coupe-partielle (avant, l'endpoint serveur
> renvoyait 500 → la borne imprimait toujours un ticket court ; 3 bugs healés + testés).

---

## §0 — PLAYBOOK AnyDesk → PowerShell (méthode FIABLE)
- **AZERTY** → ne jamais taper, **coller** (Actions → « Insérer à partir du presse-papiers », ou clic-DROIT
  dans une console PS). **PS admin** : Ctrl+Alt+Suppr → Gestionnaire des tâches → Fichier → Exécuter une
  nouvelle tâche → coller `powershell` → cocher admin → OK. Les scripts survivent à une coupure AnyDesk.

## §1 — SERVEUR (VPS) — SSH depuis le Mac, coller en un bloc
```bash
ssh lecayenne 'cd /var/www/lecayenne && \
git fetch origin pos/category-first-caisse-2026-06-23 && \
git reset --hard origin/pos/category-first-caisse-2026-06-23 && \
npm ci && npm run production && \
php artisan db:seed --class=KdsStationAssignmentSeeder --force && \
php artisan config:clear && php artisan cache:clear && php artisan view:clear && php artisan route:clear && php artisan config:cache && \
git rev-parse --short HEAD'
```
`.env` VPS — les clés borne + **le ticket long** :
```env
KIOSK_MACHINE_USERNAME=<username KioskMachine active>
KIOSK_MACHINE_PASSWORD=<password KioskMachine active>
KIOSK_AUTO_LOGIN_SECRET=lcb-227b5373163391c875eeb43f7ee1affe3972   # == ?machine_key= de l'URL
KIOSK_PAYMENT_ROUTE_ALL_TO_COUNTER=true
KIOSK_DEFAULT_LOCALE=fr
# Ticket client borne LONG (~12 cm de queue) + coupe partielle (ne tombe pas)
BORNE_CLIENT_FEED_LINES=30
BORNE_CLIENT_CUT_MODE=partial     # si l'imprimante NE coupe PAS en partiel → mettre "full"
```
puis `php artisan config:cache`.
> ⚠️ `KIOSK_AUTO_LOGIN_SECRET` DOIT être exactement le `?machine_key=` de l'URL borne.

## §2 — BORNE : PURGE ancienne version + cache + anciens Startup (SCRIPT À COLLER)
```powershell
Get-Process chrome -EA 0 | Stop-Process -Force; Get-Process node -EA 0 | Stop-Process -Force; Start-Sleep 2
$cd = $env:LOCALAPPDATA + "\Google\Chrome\User Data\Default"
Remove-Item -Recurse -Force -EA 0 -Path ($cd + "\Service Worker")
Remove-Item -Recurse -Force -EA 0 -Path ($cd + "\Cache")
Remove-Item -Recurse -Force -EA 0 -Path ($cd + "\Code Cache")
Remove-Item -Recurse -Force -EA 0 -Path ($cd + "\Cache Storage")
$sp = [Environment]::GetFolderPath(7)
Remove-Item -EA 0 -Path ($sp + "\Borne Le Cayenne.lnk"); Remove-Item -EA 0 -Path ($sp + "\start-bridge.vbs")
Write-Host "=== Startup restant (garder seulement borne-kiosk.vbs + borne-bridge.vbs) ==="; ls $sp
Write-Host "DONE_PURGE_BORNE"
```
Puis dans Chrome : `chrome://serviceworker-internals` → **Unregister** chaque entrée du domaine.

## §3 — « TOURNE SEULE, RIEN D'OUVERT » : appliance kiosk (SCRIPT À COLLER)
```powershell
# jamais de veille / ecran eteint / hibernation (secteur)
powercfg /change standby-timeout-ac 0; powercfg /change monitor-timeout-ac 0
powercfg /change hibernate-timeout-ac 0; powercfg /change disk-timeout-ac 0; powercfg /hibernate off
# Windows Update : pas de reboot pendant le service
Set-ItemProperty "HKLM:\SOFTWARE\Microsoft\WindowsUpdate\UX\Settings" -Name "ActiveHoursStart" -Value 6  -EA 0
Set-ItemProperty "HKLM:\SOFTWARE\Microsoft\WindowsUpdate\UX\Settings" -Name "ActiveHoursEnd"   -Value 23 -EA 0
Write-Host "DONE_APPLIANCE"
```
- **Auto-login Windows** : `Win+R` → `netplwiz` → décocher « … doivent entrer un nom… » → mot de passe du compte.
- **Fermer toute autre fenêtre/terminal** : la borne ne doit avoir QUE le Chrome kiosk au premier plan (le
  `--kiosk` le verrouille plein écran ; aucune console PS/CMD ne doit rester ouverte après l'install).

## §4 — BORNE : (re)créer les 2 Startup PROPRES (SCRIPT À COLLER — remplacer <VPS>)
```powershell
$sp = [Environment]::GetFolderPath(7)
Set-Content ($sp + "\borne-bridge.vbs") 'CreateObject("WScript.Shell").Run "node C:\borne-print\bridge.js", 0, False' -Encoding ASCII
$k = 'CreateObject("WScript.Shell").Run "C:\PROGRA~1\Google\Chrome\Application\chrome.exe --kiosk --kiosk-printing --disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks --noerrdialogs --disable-session-crashed-bubble https://<VPS>/kiosk?machine_key=lcb-227b5373163391c875eeb43f7ee1affe3972", 1, False'
Set-Content ($sp + "\borne-kiosk.vbs") ("WScript.Sleep 8000`r`n" + $k) -Encoding ASCII
Write-Host "=== VBS ==="; Get-Content ($sp + "\borne-kiosk.vbs"); Get-Content ($sp + "\borne-bridge.vbs")
Write-Host "DONE_STARTUP"
```
Lancer à chaud pour tester :
```powershell
Start-Process node -ArgumentList "C:\borne-print\bridge.js" -EA 0; Start-Sleep 2
$a = "--kiosk --kiosk-printing --disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks --noerrdialogs --disable-session-crashed-bubble https://<VPS>/kiosk?machine_key=lcb-227b5373163391c875eeb43f7ee1affe3972"
Start-Process "C:\Program Files\Google\Chrome\Application\chrome.exe" -ArgumentList ($a -split " ")
```
Vérifier : `Get-Process node` = 1, `Get-Process chrome` = 1 groupe, `http://127.0.0.1:9100/health` = « UP ».

## §5 — TESTS E2E RÉELS (le vrai test « pas de bug/crash »)
1. **Attract** : « Touchez l'écran » (pas d'écran blanc, pas de login).
2. **Multi-viandes** : Tacos L / Méga → 2 viandes → pas d'erreur « Viande 2 » → panier.
3. **Recette fixe** : Cayenne / burgers → aucun choix viande. Cayenne + Menu = 9,90 €.
4. **Valider** → « payer en caisse » → **#A00xx**.
5. ✅ **TICKET CLIENT** : sort **LONG (~15-20 cm)**, **ne tombe pas** (coupe partielle = il pend, le client
   le détache), format = l'aperçu écran, **UN seul cuisine** (symbolique, sans prix). ⭐ C'est le point owner.
   *(Si le papier ne coupe plus du tout → l'imprimante ne fait pas la coupe partielle : mettre
   `BORNE_CLIENT_CUT_MODE=full` dans le .env (§1) + `config:cache` → coupe totale, la longue queue suffit
   à le rendre attrapable.)*
6. ✅ **Caisse** (`/admin/encaissement`) : la commande apparaît (badge Borne) → encaissable.
7. ✅ **KDS** : la commande apparaît en symbolique.
8. **Enchaîner 5 commandes** d'affilée → aucune ne plante, chaque ticket sort correct, la borne revient à
   l'attract seule. (Test de robustesse « tourne seule ».)

### Si le KDS reste vide — diagnostic UI sans DB (ne PAS accuser `kds_station`, colonne inexistante)
Ouvrir `/admin/encaissement` : la commande y est → doit être au KDS (sinon F5 / mauvaise branche / worker).
Elle n'y est pas → UNPAID → `KIOSK_PAYMENT_ROUTE_ALL_TO_COUNTER` pas effectif → refaire §1 + reboot borne.

## §6 — REBOOT DE VALIDATION (preuve « install définitive »)
```powershell
Restart-Computer -Force
```
Au boot → auto-login Windows → ~8 s → **Chrome s'ouvre seul en plein écran sur la borne, attract**, sans
terminal ouvert, sans rien taper. Refaire une commande → ticket long OK. ✅

## §7 — À RAPPORTER (photos)
- `DONE_PURGE_BORNE` / `DONE_APPLIANCE` / `DONE_STARTUP` · `git HEAD` VPS == e5244b120 · `serviceworker-internals` vide.
- `Get-Process node/chrome` = 1 chacun · pont /health = UP · **aucune console ouverte** après install.
- **Photo du ticket client physique** : long (~15-20 cm), qui PEND (ne tombe pas) + ticket cuisine (1 seul).
- Commande dans « à encaisser » + KDS · 5 commandes d'affilée sans crash · reboot → attract seul.
- Tout échec → photo + message exact + étape.
