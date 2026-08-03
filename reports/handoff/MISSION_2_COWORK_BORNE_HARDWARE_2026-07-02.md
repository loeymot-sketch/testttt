# 🖲️ MISSION 2 — COWORK HARDWARE : installer + valider la BORNE (Kiosk)

> Machine borne (Windows) → Chrome **kiosk plein écran** → **`/kiosk?machine_key=…`** (cloud VPS). But :
> borne qui prend une commande client (multi-viandes), la route **en caisse (Plan B)**, apparaît en
> caisse + au KDS, imprime le **bon** ticket (client + cuisine, comme à l'écran, UN seul de chaque),
> **sans aucune ancienne version/cache**. Autonome — tout est ici.

Branche = `pos/category-first-caisse-2026-06-23`. Vérifie le **HEAD poussé le plus récent** (≥ `61e9ea7b7`).

---

## PHASE A — SERVEUR (VPS) : code actuel + config auto-login borne
```bash
ssh lecayenne && cd /var/www/lecayenne
git fetch origin pos/category-first-caisse-2026-06-23
git reset --hard origin/pos/category-first-caisse-2026-06-23
npm ci && npm run production
php artisan config:clear && php artisan cache:clear && php artisan view:clear && php artisan route:clear && php artisan config:cache
```
`.env` VPS (borne) — **les 4 clés sont OBLIGATOIRES** (sans elles : pas d'auto-login → commande pas kiosk → jamais au KDS) :
```env
KIOSK_MACHINE_USERNAME=<username de la KioskMachine active>
KIOSK_MACHINE_PASSWORD=<password de la KioskMachine active>
KIOSK_AUTO_LOGIN_SECRET=lcb-227b5373163391c875eeb43f7ee1affe3972   # == le ?machine_key= de l'URL borne
KIOSK_PAYMENT_ROUTE_ALL_TO_COUNTER=true   # ⭐ borne → PENDING_COUNTER → visible KDS + file caisse (Plan B)
KIOSK_DEFAULT_LOCALE=fr
```
```bash
php artisan config:cache
php artisan db:seed --class=KdsStationAssignmentSeeder --force   # stations KDS
```
> ⚠️ Le `?machine_key=` de l'URL borne DOIT être **exactement égal** à `KIOSK_AUTO_LOGIN_SECRET`.

## PHASE B — PURGE de l'ANCIENNE VERSION sur la borne (écran blanc / ticket faux / double cuisine)
1. Sortir du kiosk (Ctrl+Alt+Suppr → Gestionnaire des tâches) et tuer TOUS les `chrome.exe`.
2. `chrome://serviceworker-internals` → **Unregister** tout (cause n°1 de l'écran blanc / ancienne version).
3. `chrome://settings/clearBrowserData` → tout le cache + données de site. Supprimer aussi les dossiers
   `Default\Cache`, `Default\Service Worker`, `Default\Code Cache`.
4. Dossier Démarrage (`shell:startup`) : **GARDER seulement** `borne-kiosk.vbs` + `borne-bridge.vbs` ;
   **SUPPRIMER** les anciens : `Borne Le Cayenne.lnk`, `start-bridge.vbs`, tout autre `.lnk`/`.vbs` antérieur
   (sinon 2ᵉ Chrome / 2ᵉ pont = **2ᵉ ticket cuisine** + double kiosk).
```powershell
ls ([Environment]::GetFolderPath(7))
Remove-Item ([Environment]::GetFolderPath(7) + "\Borne Le Cayenne.lnk") -EA 0
Remove-Item ([Environment]::GetFolderPath(7) + "\start-bridge.vbs") -EA 0
```

## PHASE C — CHROME kiosk + PONT (un seul de chaque)
- `borne-kiosk.vbs` lance :
  `chrome.exe --kiosk --app=https://<VPS>/kiosk?machine_key=lcb-227b5373… --disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks`
- `borne-bridge.vbs` lance le pont node `127.0.0.1:9100`. Vérifier `Get-Process node` = 1, `Get-Process chrome` = 1 groupe.
- `http://127.0.0.1:9100/health` = « UP ».

## PHASE D — TESTS E2E RÉELS (refaire chacun)
1. **Écran d'accueil** : attract « Touchez pour commander » (pas d'écran blanc, pas de formulaire login).
2. **Multi-viandes** : Tacos L / Méga → **2 viandes** → PAS d'erreur « Viande 2 » → les 2 au panier.
3. **Recette fixe** : Cayenne / burgers → **aucun choix viande**. Prix Cayenne+Menu = **9,90 €**.
4. **Paiement** : valider → « payer en caisse » (Plan B) → **#A00xx** en grand.
5. ✅ **Ticket CLIENT + CUISINE imprimés** au pont = l'aperçu écran, **UN seul cuisine** (symbolique, sans prix).
6. ✅ **En caisse** : la commande apparaît dans « à encaisser » (badge Borne) → encaissable.
7. ✅ **KDS** : la commande apparaît (symbolique, badge « en attente encaissement »).

### Si le KDS est vide (NE PAS accuser `kds_station` — colonne inexistante) — diagnostic UI, sans DB :
Ouvrir la caisse « à encaisser » après une commande borne :
- **Elle y est** → PENDING_COUNTER OK → doit être au KDS (sinon chef sur autre branche / F5 / worker).
- **Elle n'y est PAS** → UNPAID → `KIOSK_PAYMENT_ROUTE_ALL_TO_COUNTER` pas effectif → refaire A + rebuild + reboot borne.

## PHASE E — À RAPPORTER (photos)
- `git HEAD` VPS · `serviceworker-internals` vide · Startup = seulement les 2 VBS · `node`/`chrome` = 1.
- Attract OK · multi-viandes 2 viandes · #A00xx · commande dans « à encaisser » + KDS · ticket client+cuisine == écran, 1 seul cuisine.

> Écran blanc = bundle incomplet / SW ancien (refaire B). Ticket illisible = pont injoignable → Chrome retombe
> sur `--kiosk-printing` (ancien HTML) : vérifier le pont (C). 2 tickets cuisine = ancien Startup/pont survit (B.4).
