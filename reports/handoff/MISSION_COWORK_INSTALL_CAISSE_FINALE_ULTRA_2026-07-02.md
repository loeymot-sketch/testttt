# 🖥️ MISSION COWORK (ULTRA-FINALE) — Installer la CAISSE (POS) correctement, sans faute

> Machine CAISSE (Windows tactile) → Chrome → **`/admin/pos-v4`** (VPS OVH). But : caisse qui tourne
> seule et stable, prend une commande, **paie sur place (inline)**, imprime le **bon** ticket (client +
> cuisine = écran, UN seul de chaque), encaisse les commandes borne, **zéro ancienne version/cache**,
> **survit au redémarrage**. Tout validé côté code ; reste l'install machine + e2e réel.
> **Branche `pos/category-first-caisse-2026-06-23`, HEAD `e5244b120`.**

> ✅ Vérifié côté code : endpoint ticket caisse OK (pas le bug 500 de la borne), caisse = paiement
> INLINE (`POS_WALKIN_ROUTE_TO_COUNTER=false`), ticket caisse court (le caissier le tend), 1 seul cuisine.

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
php artisan config:clear && php artisan cache:clear && php artisan view:clear && php artisan route:clear && php artisan config:cache && \
git rev-parse --short HEAD'
```
`.env` VPS — clés CAISSE :
```env
POS_WALKIN_ROUTE_TO_COUNTER=false   # ⭐ caisse = payée INLINE (déjà encaissée), PAS dans « à encaisser »
PRINT_DRIVER=windows_raw
SESSION_LIFETIME=525600             # 1 an → le caissier reste connecté (pas de /login au reboot / après pause)
```
puis `php artisan config:cache`. Données (tinker) — largeur imprimante + terminal TPE :
```bash
ssh lecayenne 'cd /var/www/lecayenne && php artisan tinker --execute='\''\App\Models\Printer::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->update(["width_chars"=>32]); \App\Models\PaymentTerminal::firstOrCreate(["name"=>"TPE Le Cayenne #1"],["status"=>1,"branch_id"=>1,"gateway_type"=>"sumup"]); echo "OK";'\'''
```
> `width_chars=32` = 58 mm (anti-coupure). Le terminal TPE actif est REQUIS pour le paiement carte (sinon 422).
> Vérifier aussi que le **worker** tourne en service : `queue:work --queue=high,default` (propage « Prêt » vers KDS/client sans délai).

## §2 — CAISSE : PURGE ancienne version + cache + anciens Startup (SCRIPT À COLLER)
> C'est ce qui règle le ticket « ancien format » et le « 2ᵉ ticket cuisine » (ancien code/pont résiduel).
```powershell
Get-Process chrome -EA 0 | Stop-Process -Force; Get-Process node -EA 0 | Stop-Process -Force; Start-Sleep 2
$cd = $env:LOCALAPPDATA + "\Google\Chrome\User Data\Default"
Remove-Item -Recurse -Force -EA 0 -Path ($cd + "\Service Worker")
Remove-Item -Recurse -Force -EA 0 -Path ($cd + "\Cache")
Remove-Item -Recurse -Force -EA 0 -Path ($cd + "\Code Cache")
Remove-Item -Recurse -Force -EA 0 -Path ($cd + "\Cache Storage")
$sp = [Environment]::GetFolderPath(7)
Remove-Item -EA 0 -Path ($sp + "\Borne Le Cayenne.lnk"); Remove-Item -EA 0 -Path ($sp + "\start-bridge.vbs")
Write-Host "=== Startup restant (aucun ancien .lnk/.vbs kiosk/print) ==="; ls $sp
Write-Host "DONE_PURGE_CAISSE"
```
Puis dans Chrome : `chrome://serviceworker-internals` → **Unregister** chaque entrée du domaine (cause n°1 de l'ancienne version qui colle).

## §3 — CAISSE : appliance stable (SCRIPT À COLLER)
```powershell
powercfg /change standby-timeout-ac 0; powercfg /change monitor-timeout-ac 0
powercfg /change hibernate-timeout-ac 0; powercfg /change disk-timeout-ac 0; powercfg /hibernate off
Set-ItemProperty "HKLM:\SOFTWARE\Microsoft\WindowsUpdate\UX\Settings" -Name "ActiveHoursStart" -Value 6  -EA 0
Set-ItemProperty "HKLM:\SOFTWARE\Microsoft\WindowsUpdate\UX\Settings" -Name "ActiveHoursEnd"   -Value 23 -EA 0
Write-Host "DONE_APPLIANCE"
```
- **Auto-login Windows** : `Win+R` → `netplwiz` → décocher « … doivent entrer un nom… » → mot de passe du compte.
- Pas de verrouillage/écran de veille (Paramètres → Comptes → Options de connexion → « Exiger une connexion » = Jamais).

## §4 — CHROME caisse + connexion CAISSIER + PONT d'impression
### 4a. Connexion caissier (une seule fois, session persistante)
1. Ouvrir Chrome (fenêtré) → `https://<VPS>/login` → se connecter avec le **compte caisse**
   (`pos@lecayenne.fr` ou le compte caissier ; mot de passe fourni par l'owner). Cocher « Se souvenir » si présent.
2. Aller sur `https://<VPS>/admin/pos-v4` → la caisse s'affiche.
3. `chrome://serviceworker-internals` → **Unregister** → revenir → **Ctrl+Shift+R** (bundle actuel).
> ⚠️ NE PAS vider les cookies APRÈS la connexion. Avec `SESSION_LIFETIME=525600` (§1) + profil persistant,
> le caissier reste connecté au reboot.

### 4b. Raccourci de démarrage plein écran (dans `shell:startup`) — SCRIPT À COLLER (remplacer <VPS>)
```powershell
$sp = [Environment]::GetFolderPath(7)
$k = 'CreateObject("WScript.Shell").Run "C:\PROGRA~1\Google\Chrome\Application\chrome.exe --start-fullscreen --disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks --noerrdialogs --disable-session-crashed-bubble --app=https://<VPS>/admin/pos-v4", 1, False'
Set-Content ($sp + "\caisse-pos.vbs") ("WScript.Sleep 6000`r`n" + $k) -Encoding ASCII
Write-Host "=== caisse-pos.vbs ==="; Get-Content ($sp + "\caisse-pos.vbs"); Write-Host "DONE_STARTUP_CAISSE"
```
> Le flag `--disable-features=...LocalNetworkAccessChecks` est INDISPENSABLE : sans lui, Chrome bloque
> l'appel vers le pont d'impression `127.0.0.1:9100` → aucun ticket ne sort (ou retombe sur l'ancien HTML).
> `--start-fullscreen` (pas `--kiosk` strict : le caissier a besoin de la barre pour certaines actions ;
> si tu veux le verrouiller, mettre `--kiosk` mais garder un clavier pour sortir).

### 4c. Pont d'impression (un seul)
- `Get-Process node` = 0 ou 1. `http://127.0.0.1:9100/health` = « UP », `POST /raw` accepté.
- Si le pont est un service/VBS : le relancer via son Startup ; sinon appliquer la config pont fournie.

## §5 — TESTS E2E RÉELS (refaire chacun ; ne pas clore si un casse)
1. **Prendre une commande** composée (Tacos L, 2 viandes) → wizard OK, prix SSOT.
2. **Payer INLINE** : Espèces (montant reçu → rendu monnaie) OU Carte (terminal + montant) → **PAYÉE**.
3. ✅ Ouvrir `/admin/encaissement` → la commande caisse **N'Y EST PAS** (déjà encaissée). *(Si elle y est →
   `POS_WALKIN_ROUTE_TO_COUNTER` pas à false / config pas rechargée : refaire §1.)*
4. ✅ **TICKET CLIENT** imprimé = l'aperçu écran (resto+adresse+À EMPORTER+produits+compo+TVA+total), prix
   entiers, 0 coupure de mots.
5. ✅ **TICKET CUISINE** = symbolique `G | TACOS | L | Mex Cordon | STO | ALG` + suppléments + `MENU`, sans
   prix, **UN SEUL** exemplaire. *(Si 2 cuisine → ancien Startup/pont : refaire §2.)*
6. **Encaisser une commande BORNE** : `/admin/encaissement` → « Encaisser » → modal (pavé, Espèces/Carte)
   → **Confirmer & Imprimer ticket** → PAYÉE + ticket client imprimé.
7. ✅ **KDS** montre la commande en symbolique · **Vue Caisse/Z-report** ventile X carte / X espèces.
8. **Enchaîner 5 ventes** (mix espèces/carte) → aucune ne plante, chaque ticket sort correct.

## §6 — REBOOT DE VALIDATION (preuve « install définitive »)
```powershell
Restart-Computer -Force
```
Au boot → auto-login Windows → ~6 s → **Chrome ouvre seul `/admin/pos-v4`, déjà connecté** (caissier), sans
taper. Refaire une vente → ticket OK. ✅

## §7 — À RAPPORTER (photos)
- `DONE_PURGE_CAISSE` / `DONE_APPLIANCE` / `DONE_STARTUP_CAISSE` · `git HEAD` VPS == e5244b120 ·
  `serviceworker-internals` vide · `Get-Process node/chrome` = 1 chacun · pont /health = UP.
- Commande caisse PAYÉE **absente** de « à encaisser ».
- **Photo des tickets physiques** : client (== écran) + cuisine (symbolique, **UN seul**).
- Encaissement d'une borne OK · KDS OK · ventilation compta carte/espèces.
- Reboot → caisse ouverte seule, connectée. · 5 ventes d'affilée sans crash.
- Tout échec → photo + message exact + F12 (réseau) + étape.

> Diag express : ticket ancien format = SW/cache survécu (refaire §2) ou VPS pas rebuild (§1). 2 cuisine =
> ancien Startup/pont (§2). Carte 422 = pas de terminal TPE actif (§1). Caisse dans « à encaisser » =
> `POS_WALKIN_ROUTE_TO_COUNTER` pas false (§1). /login au reboot = SESSION_LIFETIME pas appliqué / cookies vidés.
