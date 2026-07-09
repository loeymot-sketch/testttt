# MISSION COWORK — Déployer + configurer TOUT (prix, tickets, KDS, borne)

> Pour : Claude cowork (accès SSH VPS + AnyDesk borne/caisse).
> But : mettre EN LIGNE tout ce qui a été codé + validé, et configurer la machine pour que :
> les prix soient justes (9,90€ pas 10€, monnaie correcte), les tickets sortent PROPRES (sans
> coupure, caisse = borne), et l'écran cuisine montre toutes les commandes.

**Tout est committé + poussé.** Branche : **`pos/category-first-caisse-2026-06-23`** — HEAD **`3d905eb80`**.
Contenu (dernier au premier) :
`3d905eb80` **prix caisse** (décimales 9,90€ + monnaie juste + viande suppl +2,50€, LOCK owner) ·
`2eeeafb66` borne = caisse (même ticket) · `2404a4b15` KDS toutes commandes · `afecbcd3a` note+œuf ·
`4ded428c2` **ticket width-safe (fin des coupures)** · `a8c0517d6` seeder stations KDS ·
`a127fa614` paiement « à régler en caisse » + menu enfant.

---

## ÉTAPE 0 — Pré-vol (état du VPS)
La prod déploie habituellement `main`, MAIS les fixes sont sur la branche ci-dessus → on déploie
**cette branche explicitement**. Vérifier d'abord s'il y a des hot-patches locaux sur le VPS
(le script bloque `git reset --hard` s'il y en a) :
```bash
ssh lecayenne
cd /var/www/lecayenne
git fetch origin pos/category-first-caisse-2026-06-23
git status --short        # s'il y a des M/?? locaux (SCP hot-patches) : les sauvegarder puis git stash/checkout avant reset
```

## ÉTAPE 1 — Déployer la branche
```bash
cd /var/www/lecayenne
sudo LECAYENNE_BRANCH=pos/category-first-caisse-2026-06-23 bash scripts/deploy/deploy.sh
php artisan view:clear && php artisan route:clear
```
→ `git reset --hard origin/<branche>` + `npm ci && npm run production` (rebuild COMPLET) + migrations
+ clear caches + vérif chaîne NF525. (Si le garde « local changes » bloque : régler l'étape 0.)

## ÉTAPE 2 — ⭐ Régler la largeur imprimante (LA correction anti-coupure ticket)
Le renderer produit un ticket parfait À LA LARGEUR de l'imprimante. Sur une 58mm il faut 32.
```bash
php artisan tinker
>>> \App\Models\Printer::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->update(['width_chars' => 32]);
>>> \App\Models\Printer::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->get(['id','station','width_chars']);
```
Test papier : imprimer une ligne de 48 tirets → si elle revient à la ligne = 58mm → **32** (mettre 48 si 80mm).

## ÉTAPE 3 — Stations KDS (filtre par poste)
```bash
php artisan db:seed --class=KdsStationAssignmentSeeder --force
```

## ÉTAPE 4 — Impression ESC/POS (caisse ET borne = même ticket serveur)
- Pont local doit répondre `http://127.0.0.1:9100/health` → « UP » et accepter `POST /raw`.
- Chrome (caisse + borne) lancé avec :
  `--disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks`
- `.env` : `PRINT_DRIVER=windows_raw` + imprimante déclarée
  (`php artisan pos:setup-receipt-printer "<NOM_SAGA>"`).
- La borne imprime maintenant via le MÊME renderer serveur (nouvel endpoint
  `GET /api/frontend/order/show/{id}/escpos`) → ticket identique à la caisse.

## ÉTAPE 5 — (Optionnel) Temps réel WebSocket
`.env` : `PUSHER_APP_KEY` + `MIX_PUSHER_APP_KEY` (host PUBLIC, pas 127.0.0.1) + Soketi:6001 + proxy
Nginx, PUIS rebuild. Sinon le polling marche déjà.

---

## ÉTAPE 6 — VÉRIFIER EN RÉEL (les tests qui comptent)

### Prix (les 2 bugs owner)
1. **Cayenne + Menu (Frites+Boisson)** au panier → doit afficher **9,90 €** (pas 10 €) partout.
2. Encaisser en espèces : payer **20 €** sur 9,90 € → « Monnaie à rendre **10,10 €** » (pas 11,10).
   Tester aussi 10→0,10 / 50→40,10.
3. **Viande supplémentaire** : ajouter +1 viande → facturée **+2,50 €** (récap = encaissé).

### Tickets (caisse ET borne, client ET cuisine)
4. Ticket **client** : prix **entiers** (« 10,80 € » jamais coupé « 10,\n80 »), nom resto + adresse
   + n° commande, compo enroulée. **AUCUNE coupure.**
5. Ticket **cuisine** : `S | TERMINATOR | Mex Cordon | STO | AND` + suppléments, **aucun prix**.
6. Passer une commande **borne** → ticket **IDENTIQUE** à la caisse.

### KDS
7. Écran cuisine : **toutes** les commandes visibles, tous les produits de chaque commande
   (grosse commande = plus de hauteur, plus de « +N en attente » qui cache).

---

## À RAPPORTER
- Valeur `width_chars` retenue (32 ou 48).
- Photos : Cayenne+Menu = 9,90 € ; monnaie 20→10,10 ; ticket client (prix entiers, 0 coupure) ;
  ticket cuisine ; ticket borne == caisse ; KDS toutes commandes.
- Toute coupure/erreur résiduelle : photo + étape.

> Si un ticket coupe encore → `width_chars` mal réglé (étape 2) ou pont ESC/POS injoignable
> (étape 4, ça retombe sur window.print = HTML mal rendu).
