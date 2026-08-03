# 🖥️ MISSION 1 — COWORK HARDWARE : installer + valider la CAISSE (POS)

> Machine caisse (Windows) → Chrome → **`/admin/pos-v4`** (cloud VPS). But : caisse qui prend une
> commande, **paie sur place (inline)**, imprime le **bon** ticket (client + cuisine, comme à l'écran,
> UN seul de chaque), **sans aucune ancienne version/cache** qui traîne. Autonome — tout est ici.

Branche = `pos/category-first-caisse-2026-06-23`. **Vérifie le HEAD poussé le plus récent** (au moins `61e9ea7b7`
+ les heals V2 si l'owner les a commités — dont le fix NF525 double-mouvement-caisse).

---

## PHASE A — SERVEUR (VPS) : le rendu vient du code ACTUEL
```bash
ssh lecayenne && cd /var/www/lecayenne
git fetch origin pos/category-first-caisse-2026-06-23
git reset --hard origin/pos/category-first-caisse-2026-06-23   # écrase toute dérive
npm ci && npm run production                                    # REBUILD COMPLET des bundles
php artisan config:clear && php artisan cache:clear && php artisan view:clear && php artisan route:clear && php artisan config:cache
git rev-parse --short HEAD                                      # noter le HEAD
```
`.env` VPS (caisse) — vérifier/mettre :
```env
POS_WALKIN_ROUTE_TO_COUNTER=false   # ⭐ caisse = payée INLINE (déjà encaissée), PAS dans « à encaisser »
PRINT_DRIVER=windows_raw
```
Données (tinker) :
```bash
php artisan tinker
>>> \App\Models\Printer::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->update(['width_chars'=>32]);   # 58mm anti-coupure
>>> \App\Models\PaymentTerminal::firstOrCreate(['name'=>'TPE Le Cayenne #1'],['status'=>1,'branch_id'=>1,'gateway_type'=>'sumup']);  # requis paiement CARTE
>>> exit
```

## PHASE B — PURGE de l'ANCIENNE VERSION sur la machine caisse (cause du ticket faux)
1. Fermer TOUS les `chrome.exe` (Task Manager).
2. `chrome://serviceworker-internals` → **Unregister** tout ce qui pointe le domaine (cause n°1 de l'ancienne version qui colle).
3. `chrome://settings/clearBrowserData` → « Toutes les périodes » → cache + cookies + données de site.
4. Supprimer `%LOCALAPPDATA%\Google\Chrome\User Data\Default\Cache`, `...\Service Worker`, `...\Code Cache`.
5. Dossier Démarrage (`shell:startup`) : **supprimer tout ancien** `.lnk`/`.vbs` d'impression/kiosk résiduel
   (sinon 2ᵉ pont/2ᵉ chemin d'impression = **2ᵉ ticket cuisine**). Vérifier `Get-Process node` = 0 ou 1.

## PHASE C — CHROME caisse + PONT d'impression
- Raccourci Chrome (dans Startup) avec le flag **indispensable** :
  `chrome.exe --disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks https://<VPS>/admin/pos-v4`
  (sans ce flag, Chrome bloque l'appel → 127.0.0.1 → aucun ticket ne sort → il retombe sur l'ancien HTML).
- **UN seul** pont ESC/POS local : `http://127.0.0.1:9100/health` = « UP », `POST /raw` accepté. Tuer les node en double.
- Recharger dur (`Ctrl+Shift+R`) → vérifier que le NOUVEAU bundle charge.

## PHASE D — TESTS E2E RÉELS (refaire chacun ; ne pas clore si un casse)
1. **Prendre une commande** composée (Tacos L, 2 viandes) → wizard OK, prix SSOT.
2. **Payer INLINE** : Espèces (montant reçu → rendu monnaie) OU Carte (terminal + montant) → **PAYÉE**.
3. ✅ La commande est **déjà encaissée** → elle **N'apparaît PAS** dans « à encaisser » (`/admin/encaissement`).
4. ✅ **Ticket CLIENT imprimé** = l'aperçu écran (resto+adresse+À EMPORTER+produits+compo+TVA+total), prix entiers, 0 coupure.
5. ✅ **Ticket CUISINE imprimé** = symbolique `G | TACOS | L | Mex Cordon | STO | ALG` + suppléments + `MENU`,
   **sans prix**, **UN SEUL** exemplaire.
6. **Encaisser une commande BORNE** depuis la caisse (« à encaisser ») → modal (pavé, espèces/carte) → PAYÉE + ticket client.
7. ✅ **KDS** montre la commande (symbolique) ; **Vue Caisse/Z-report** ventile X carte / X espèces.

## PHASE E — À RAPPORTER (photos)
- `git HEAD` VPS · `serviceworker-internals` vide · Startup nettoyé · `node`/`chrome` = 1 seul chacun.
- Commande caisse PAYÉE **absente** de « à encaisser ».
- Ticket client + cuisine physiques == écran, **UN seul cuisine**.
- Encaissement d'une borne OK. KDS + ventilation compta OK.

> Si le ticket sort encore en ancien format → un service worker/cache a survécu (refaire B) ou le VPS n'a pas rebuild (A).
> Si 2 tickets cuisine → un ancien fichier Startup / un 2ᵉ pont survit (refaire B.5 / C).
