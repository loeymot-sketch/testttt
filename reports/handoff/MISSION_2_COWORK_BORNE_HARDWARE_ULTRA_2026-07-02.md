# 🖲️ MISSION 2 (ULTRA-DÉTAILLÉE) — COWORK HARDWARE : BORNE (Kiosk)

> Machine BORNE (Windows) → Chrome **kiosk plein écran** → **`/kiosk?machine_key=…`** (VPS OVH). But :
> commande client → route **en caisse (Plan B)** → visible caisse + KDS → **bon** ticket (client + cuisine
> = écran, UN seul de chaque), **zéro ancienne version/cache**. Intègre le **PLAYBOOK AnyDesk** (AZERTY,
> clipboard, kiosk-focus, sessions 4 min) + des **scripts à coller EN UN BLOC**.

---

## §0 — PLAYBOOK AnyDesk → PowerShell (IDENTIQUE à la mission caisse — méthode FIABLE)
- **AZERTY** → ne JAMAIS taper, **TOUJOURS coller**.
- **Coller** : AnyDesk → Actions → **« Insérer à partir du presse-papiers »** (clipboard Mac), OU dans une
  console PS ouverte, **clic-DROIT = coller** (`Ctrl+V` ne marche pas dans la console).
- **Chrome kiosk vole le focus** → **Ctrl+Alt+Suppr** (menu AnyDesk « Réglages du clavier ») → **Gestionnaire des tâches**.
- **Session ~4 min** → blocs courts ; si coupée, « Répéter ».
### Ouvrir PowerShell ADMIN
1. `write_clipboard "powershell"` (Mac). 2. Ctrl+Alt+Suppr → **Gestionnaire des tâches**. 3. **Fichier →
Exécuter une nouvelle tâche**. 4. Dans le champ : **Actions → Insérer** (affiche `powershell`). 5. **Cocher
privilèges admin** → **OK**. 6. Console PS ouverte.
> Exécuter un script : `write_clipboard "<script>"` → clic dans la PS → **clic-DROIT** → **Entrée**.

---

## §1 — SERVEUR (VPS) — terminal Mac (SSH), coller en un bloc :
```bash
ssh lecayenne 'cd /var/www/lecayenne && \
git fetch origin pos/category-first-caisse-2026-06-23 && \
git reset --hard origin/pos/category-first-caisse-2026-06-23 && \
npm ci && npm run production && \
php artisan config:clear && php artisan cache:clear && php artisan view:clear && php artisan route:clear && \
php artisan db:seed --class=KdsStationAssignmentSeeder --force && \
php artisan config:cache && git rev-parse --short HEAD'
```
`.env` VPS — **4 clés OBLIGATOIRES** (sans elles : pas d'auto-login → commande pas kiosk → jamais au KDS) :
```env
KIOSK_MACHINE_USERNAME=<username de la KioskMachine active>
KIOSK_MACHINE_PASSWORD=<password de la KioskMachine active>
KIOSK_AUTO_LOGIN_SECRET=lcb-227b5373163391c875eeb43f7ee1affe3972
KIOSK_PAYMENT_ROUTE_ALL_TO_COUNTER=true
KIOSK_DEFAULT_LOCALE=fr
```
puis `php artisan config:cache`.
> ⚠️ `KIOSK_AUTO_LOGIN_SECRET` DOIT être **exactement** le `?machine_key=` de l'URL borne, sinon pas d'auto-login.

## §2 — BORNE : PURGE ancienne version + cache + Startup (SCRIPT À COLLER EN UN BLOC)
> `write_clipboard` ce bloc → coller (clic-droit) dans la PS → Entrée. Tue Chrome/node, purge SW+cache
> (cause n°1 de l'écran blanc / ancien format), supprime les anciens Startup (cause du **2ᵉ ticket cuisine**).
```powershell
Get-Process chrome -EA 0 | Stop-Process -Force; Get-Process node -EA 0 | Stop-Process -Force; Start-Sleep 2
$cd = $env:LOCALAPPDATA + "\Google\Chrome\User Data\Default"
Remove-Item -Recurse -Force -EA 0 -Path ($cd + "\Service Worker")
Remove-Item -Recurse -Force -EA 0 -Path ($cd + "\Cache")
Remove-Item -Recurse -Force -EA 0 -Path ($cd + "\Code Cache")
Remove-Item -Recurse -Force -EA 0 -Path ($cd + "\Cache Storage")
$sp = [Environment]::GetFolderPath(7)
Remove-Item -EA 0 -Path ($sp + "\Borne Le Cayenne.lnk")
Remove-Item -EA 0 -Path ($sp + "\start-bridge.vbs")
Write-Host "=== Startup (doit rester borne-kiosk.vbs + borne-bridge.vbs uniquement) ==="; ls $sp
Write-Host "DONE_PURGE_BORNE"
```
### Puis désenregistrer les SW dans Chrome
Ouvrir Chrome (fenêtré) → coller `chrome://serviceworker-internals` → **Unregister** chaque entrée du domaine.

## §3 — BORNE : (RE)CRÉER les 2 fichiers Startup PROPRES (SCRIPT À COLLER)
> Remplace `<VPS>` par le vrai host (ex. `vps-418872ac.vps.ovh.net`). Utilise le chemin court `PROGRA~1`
> pour éviter les guillemets/espaces (l'erreur « Start Menu tronqué » vécue). Écrit en ASCII.
```powershell
$sp = [Environment]::GetFolderPath(7)
Set-Content ($sp + "\borne-bridge.vbs") 'CreateObject("WScript.Shell").Run "node C:\borne-print\bridge.js", 0, False' -Encoding ASCII
$k = 'CreateObject("WScript.Shell").Run "C:\PROGRA~1\Google\Chrome\Application\chrome.exe --kiosk --kiosk-printing --disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks https://<VPS>/kiosk?machine_key=lcb-227b5373163391c875eeb43f7ee1affe3972", 1, False'
Set-Content ($sp + "\borne-kiosk.vbs") ("WScript.Sleep 8000`r`n" + $k) -Encoding ASCII
Write-Host "=== VBS créés ==="; Get-Content ($sp + "\borne-kiosk.vbs"); Get-Content ($sp + "\borne-bridge.vbs")
Write-Host "DONE_STARTUP_BORNE"
```

## §4 — BORNE : lancer maintenant (test à chaud, sans reboot) — SCRIPT À COLLER
```powershell
Start-Process node -ArgumentList "C:\borne-print\bridge.js" -EA 0
Start-Sleep 2
$a = "--kiosk --kiosk-printing --disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks https://<VPS>/kiosk?machine_key=lcb-227b5373163391c875eeb43f7ee1affe3972"
Start-Process "C:\Program Files\Google\Chrome\Application\chrome.exe" -ArgumentList ($a -split " ")
Write-Host "BORNE_LANCEE"
```
Vérifier : `Get-Process node` = 1, `Get-Process chrome` = 1 groupe, `http://127.0.0.1:9100/health` = « UP ».

## §5 — TESTS E2E RÉELS (sur la borne)
1. **Attract** : « Touchez l'écran pour commander » (pas d'écran blanc, pas de formulaire login).
   *(Écran blanc → un SW/bundle ancien a survécu : refaire §2.)*
2. **Multi-viandes** : Tacos L / Méga → **2 viandes** → PAS d'erreur « Viande 2 » → les 2 au panier.
3. **Recette fixe** : Cayenne / burgers → aucun choix viande. **Cayenne + Menu = 9,90 €**.
4. **Valider** → écran « payer en caisse » (Plan B) → **#A00xx** en grand.
5. ✅ **Ticket CLIENT + CUISINE** sortent au pont = l'aperçu écran, **UN seul cuisine** (symbolique, sans prix).
   *(Ticket illisible/HTML → le pont était injoignable : vérifier §4. 2 cuisine → ancien Startup/pont : refaire §2.)*
6. ✅ **En caisse** (`/admin/encaissement`) : la commande apparaît (badge **Borne**) → encaissable.
7. ✅ **KDS** : la commande apparaît en symbolique (badge « en attente encaissement »).

### Si le KDS reste vide — DIAGNOSTIC UI, SANS DB (ne PAS accuser `kds_station`, colonne inexistante)
Ouvrir la caisse `/admin/encaissement` après une commande borne :
- **La commande y est** → PENDING_COUNTER OK → elle DOIT être au KDS. Si le KDS reste vide → le chef
  regarde une **autre branche** OU le KDS n'a pas rafraîchi (**F5**) OU le **worker** ne tourne pas
  (`queue:work --queue=high,default`).
- **La commande n'y est PAS** → elle est **UNPAID** → la borne n'a pas routé en caisse → `KIOSK_PAYMENT_ROUTE_ALL_TO_COUNTER`
  pas effectif → **refaire §1 (config + rebuild) + reboot borne**.

## §6 — À RAPPORTER (captures)
- `DONE_PURGE_BORNE` + Startup = 2 VBS seulement · `serviceworker-internals` vide · `node`/`chrome` = 1.
- `DONE_STARTUP_BORNE` (contenu des VBS) · `git HEAD` VPS (sortie §1).
- Attract OK · multi-viandes 2 viandes · #A00xx · commande dans « à encaisser » + KDS.
- **Photo des tickets physiques** : client (== écran) + cuisine (symbolique, **UN seul**).
- **Tout échec** → photo + message exact + à quelle étape + (le diag §5 si KDS vide).

## §7 — REBOOT DE VALIDATION (le vrai test « install définitive »)
Redémarrer la borne (`Restart-Computer -Force`). Au boot, **les 2 VBS de Startup doivent relancer seuls**
le pont + le Chrome kiosk → attract. C'est la preuve que l'install survit à un redémarrage. Vérifier ensuite
qu'il n'y a **qu'UN** node et **qu'UN** groupe chrome (pas de double = pas de 2ᵉ ticket).
