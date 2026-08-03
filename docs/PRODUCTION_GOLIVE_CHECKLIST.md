# 🚦 Go-live production — Le Cayenne (caisse Windows + SAGA)

> Runbook pas-à-pas pour passer en production sur le **PC Windows** (la caisse réelle).
> Coche chaque point ; chaque étape a une condition de réussite. Mappe les 6 gates du
> `/brain ship` (G1 chaîne NF525, G2 frozen, G3 garde-fous, G4 tests, G5 sentinelles, G6 backup).
>
> Contexte : ce qui suit ne peut PAS être fait depuis le Mac de dev (disque ~0, pas de SAGA) —
> ça se fait sur le Windows (100 Go) avec l'imprimante branchée. Code = validé + committé
> (`42ea74a4a` impression, `9b8398d2f`+`12dc32aaa` heal commande).

## 1. Récupérer le code
```
git fetch && git checkout pos/category-first-caisse-2026-06-23 && git pull
git log --oneline -3   # doit montrer 42ea74a4a feat(print) en tête
```

## 2. Dépendances + BUILD du bundle (corrige le risque double-ticket)
```
composer install --no-dev --optimize-autoloader
npm ci
npm run dev        # ⚠️ PAS `npm run production` : cssnano strippe les guillemets
                   #    et casse KeyboardNavigationSentinel (quirk connu du repo)
```
→ le changement UI anti-double-impression (`ReceiptComponent`) devient actif.

## 3. `.env` production (les garde-fous REFUSENT le boot sinon — G3)
```
APP_ENV=production
APP_DEBUG=false
APP_URL=http://<ip-ou-nom-caisse>      # requis (Sanctum/webhooks)
CACHE_DRIVER=file                       # ou redis (pas array/null)
IDEMPOTENCY_MIDDLEWARE_ENABLED=true
POS_SIMULATION_HARDWARE=false           # ⚠️ false en prod (NF525)
PRINTING_BYPASS_MODE=false              # ⚠️ false = impression réelle
PRINT_DRIVER=windows_raw                # imprimante USB Windows (SAGA)
```

## 4. Migrer la base + caches
```
php artisan migrate --force            # la base prod DOIT être à jour
php artisan config:clear && php artisan config:cache
php artisan route:cache && php artisan event:cache
```

## 5. Déclarer l'imprimante SAGA (caisse)
Relever le **nom EXACT** dans Windows → Imprimantes, puis (tinker) :
```
\App\Models\Printer::create([
 'branch_id'=>1,'name'=>'SAGA Caisse','type'=>'escpos_usb_windows',
 'host'=>'<NOM EXACT IMPRIMANTE WINDOWS>','port'=>0,'station'=>'receipt',
 'width_chars'=>48,'status'=>\App\Enums\Status::ACTIVE,'options'=>['code_page'=>19],
]);
```
(détails + dépannage : `docs/PRINT_SAGA_USB_WINDOWS_SETUP.md`)

## 6. Backup + restore-drill (G6)
```
php artisan foodking:backup-daily            # OK — écrit daily-YYYY-MM-DD.sql.gz
php artisan backup:verify-restore            # le restore-drill doit PASSER
```

## 7. Boot + gates de vérification
```
php artisan serve  (ou le vrai vhost)        # doit démarrer (sinon un garde-fou .env a sauté)
php artisan fiscal:verify-chain --all        # G1 = CHAIN OK
vendor/bin/phpunit --filter "Fiscal|Pos|Order|Outbox|KDS|Branch|Idempotency"  # G4 = GREEN
vendor/bin/phpunit --filter "BranchScopeCoverageSentinel|FormRequestAuthzDriftSentinel"  # G5
```

## 8. Validation impression RÉELLE (le point que je n'ai pas pu faire sur le Mac)
1. Admin → Printers → **Test print** → un slip sort de la SAGA.
2. **Caisse** : encaisser une commande → **ticket sort sur la SAGA** (compo, suppléments avec prix, TVA, total, NF525).
3. **Borne** : passer une commande → **1 ticket sur l'imprimante de la borne** (bridge Electron) **+ 1 copie « COMMANDE BORNE - COPIE CAISSE » sur la SAGA**.
4. Vérifier : pas de double ticket à la caisse (le bundle rebuild de l'étape 2 garantit ça).

## Critère GO
Tous verts : boot OK (G3), chaîne NF525 OK (G1), tests + sentinelles GREEN (G4/G5), backup+restore OK (G6), 0 frozen non-LOCK (G2 — voir note), **et les 3 impressions de l'étape 8 sortent correctement**. Alors → **GO**, tu signes le go-live (décision humaine §10).

> Note G2 : `git diff main..HEAD` montre beaucoup de fichiers frozen car `main` est un
> baseline ancien (la branche porte des semaines de travail LOCK-gé). Les 2 commits de
> CETTE session (impression + heal) touchent **0 fichier frozen**. Décider de la stratégie
> de merge de la branche vers `main` séparément.
