# 🖥️ MISSION 1 (ULTRA-DÉTAILLÉE) — COWORK HARDWARE : CAISSE (POS)

> Machine CAISSE (Windows) → Chrome → **`/admin/pos-v4`** (VPS OVH). But : prendre commande, **payer sur
> place (inline)**, imprimer le **bon** ticket (client + cuisine = écran, UN seul de chacun), **zéro
> ancienne version/cache**. Cette version intègre le **PLAYBOOK AnyDesk** (les galères vécues : AZERTY,
> clipboard, kiosk-focus, sessions 4 min) et des **scripts à coller EN UN BLOC** (plus de saisie au clavier).

---

## §0 — PLAYBOOK AnyDesk → PowerShell (la méthode FIABLE, à suivre à la lettre)
Les problèmes connus + leur solution :
- **AZERTY** : taper au clavier donne du charabia (`powershell`→`qqqq`). → **NE JAMAIS taper. TOUJOURS coller.**
- **Presse-papiers** : le sync bidirectionnel écrase le clipboard Mac. → utiliser **AnyDesk → Actions →
  « Insérer à partir du presse-papiers »** (injecte le clipboard Mac), OU dans une console PS ouverte,
  **clic-DROIT = coller** (comportement console Windows ; `Ctrl+V` ne marche PAS dans la vieille console).
- **Chrome kiosk vole le focus** (watchdog). → passer par **Ctrl+Alt+Suppr** (menu AnyDesk « Réglages du
  clavier » → « Ctrl+Alt+Suppr ») → **Gestionnaire des tâches**.
- **Session AnyDesk ~4 min (licence gratuite)** → travailler par blocs courts ; si coupée, « Répéter ».

### Ouvrir une PowerShell ADMIN (séquence exacte)
1. `write_clipboard "powershell"` côté Mac (le clipboard Mac contient maintenant `powershell`).
2. Menu AnyDesk → **Réglages du clavier → Ctrl+Alt+Suppr** → écran bleu Windows → **Gestionnaire des tâches**.
3. Dans le Gestionnaire : **Fichier → Exécuter une nouvelle tâche**.
4. Dans le champ : **AnyDesk → Actions → « Insérer à partir du presse-papiers »** (le champ affiche `powershell`).
5. **Cocher « Créer cette tâche avec des privilèges d'administrateur »** → **OK**.
6. → une console PowerShell s'ouvre (`PS C:\Windows\system32>`).
> Pour EXÉCUTER un script : `write_clipboard "<script>"` côté Mac → clic dans la console PS → **clic-DROIT**
> (colle) → **Entrée**. (Si le clic-droit ne colle pas : Actions → « Insérer à partir du presse-papiers ».)

---

## §1 — SERVEUR (VPS) — depuis le terminal Mac (SSH). Coller en un bloc :
```bash
ssh lecayenne 'cd /var/www/lecayenne && \
git fetch origin pos/category-first-caisse-2026-06-23 && \
git reset --hard origin/pos/category-first-caisse-2026-06-23 && \
npm ci && npm run production && \
php artisan config:clear && php artisan cache:clear && php artisan view:clear && php artisan route:clear && php artisan config:cache && \
git rev-parse --short HEAD'
```
Puis vérifier/ajouter dans `/var/www/lecayenne/.env` (SSH, éditeur nano) :
```env
POS_WALKIN_ROUTE_TO_COUNTER=false
PRINT_DRIVER=windows_raw
```
et `php artisan config:cache`. Enfin (tinker) largeur imprimante + terminal TPE — coller :
```bash
ssh lecayenne 'cd /var/www/lecayenne && php artisan tinker --execute='\''\App\Models\Printer::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->update(["width_chars"=>32]); \App\Models\PaymentTerminal::firstOrCreate(["name"=>"TPE Le Cayenne #1"],["status"=>1,"branch_id"=>1,"gateway_type"=>"sumup"]); echo "OK";'\'''
```

## §2 — MACHINE CAISSE : PURGE ancienne version + cache (SCRIPT À COLLER EN UN BLOC dans la PS admin)
> `write_clipboard` ce bloc entier côté Mac → coller (clic-droit) dans la PS → Entrée. Il tue Chrome/node,
> purge SW+cache (la cause n°1 du ticket « ancien format »), et nettoie les anciens fichiers Startup
> (la cause du **2ᵉ ticket cuisine**).
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
Write-Host "=== Startup restant (doit ne PAS contenir d'ancien .lnk/.vbs kiosk/print) ==="; ls $sp
Write-Host "DONE_PURGE_CAISSE"
```
Attendu : `DONE_PURGE_CAISSE` + la liste Startup nettoyée.

### Désenregistrer les service workers dans l'UI Chrome (complément indispensable)
Ouvrir Chrome → barre d'adresse : coller `chrome://serviceworker-internals` (via Actions→Insérer) → pour
CHAQUE entrée du domaine, cliquer **Unregister**. (Le SW survit à un simple vidage de cache — c'est LUI qui
sert l'ancienne version hors-ligne.)

## §3 — MACHINE CAISSE : lancer Chrome (avec le flag impression) — SCRIPT À COLLER
> Adapter `<VPS>` (ex. `vps-418872ac.vps.ovh.net`). Le flag `LocalNetworkAccessChecks` est INDISPENSABLE
> (sinon Chrome bloque l'appel vers le pont 127.0.0.1 → aucun ticket → retombe sur l'ancien HTML).
```powershell
$a = "--disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks https://<VPS>/admin/pos-v4"
Start-Process "C:\Program Files\Google\Chrome\Application\chrome.exe" -ArgumentList ($a -split " ")
Write-Host "CAISSE_CHROME_LANCE"
```
Pont d'impression : s'assurer qu'UN SEUL node tourne (`Get-Process node`) et que
`http://127.0.0.1:9100/health` répond « UP ». (Si le pont est un service/VBS, le relancer ; sinon voir la config pont fournie.)

## §4 — TESTS E2E RÉELS (dans le Chrome caisse ; refaire chacun, ne pas clore si un casse)
1. **Prendre une commande** composée : Tacos L → wizard **2 viandes** (Mexicanos + Poulet mariné) → sauce
   → menu → **Ajouter au panier** → **Payer**.
2. **Payer INLINE** : **Espèces** (saisir montant reçu → « rendu monnaie » affiché) OU **Carte** (choisir le
   terminal + saisir le montant) → **Confirmer** → la commande passe **PAYÉE**.
3. ✅ Ouvrir `/admin/encaissement` → la commande caisse **N'Y EST PAS** (elle est déjà encaissée). *(Si elle
   y est → `POS_WALKIN_ROUTE_TO_COUNTER` n'est pas à false / config pas rechargée : refaire §1.)*
4. ✅ **Ticket CLIENT** sort de l'imprimante = l'aperçu écran : resto + adresse + « À EMPORTER » + produits
   + compo + TVA + total, prix entiers (« 12,90 € » jamais coupé).
5. ✅ **Ticket CUISINE** = symbolique `G | TACOS | L | Mex Cordon | STO | ALG` + `+ suppléments` + `MENU`,
   **SANS prix**, **UN SEUL** exemplaire. *(Si 2 cuisine → ancien Startup/pont : refaire §2.)*
6. **Encaisser une commande BORNE** : `/admin/encaissement` → « Encaisser » → modal (pavé, Espèces/Carte)
   → **Confirmer & Imprimer ticket** → PAYÉE + ticket client.
7. ✅ **KDS** (`/admin/kitchen-display-system`) montre la commande en symbolique.

## §5 — À RAPPORTER (captures d'écran)
- Sortie `DONE_PURGE_CAISSE` + Startup nettoyé · `chrome://serviceworker-internals` vide · `Get-Process node/chrome` = 1.
- `git HEAD` VPS (la sortie de §1).
- Commande caisse PAYÉE **absente** de `/admin/encaissement`.
- **Photo des tickets physiques** : client (== écran) + cuisine (symbolique, **UN seul**).
- Encaissement borne OK · KDS OK.
- **Tout échec** → photo + message exact + (F12 → Réseau si accessible) + indiquer à quelle étape.
