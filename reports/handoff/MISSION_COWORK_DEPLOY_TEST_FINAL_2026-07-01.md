# MISSION COWORK — Déployer TOUT + tester compo/tickets/KDS/borne (zéro problème)

> Pour : Claude cowork (accès SSH VPS + AnyDesk borne/caisse/cuisine).
> But : mettre en ligne tous les correctifs validés, configurer la machine, puis TESTER en
> RÉEL produit par produit (composition, tickets, KDS, borne, cuisine) pour qu'il n'y ait
> aucun problème quotidien, aucun crash, aucun bug caché.

**Tout est committé + poussé.** Branche : **`pos/category-first-caisse-2026-06-23`** — HEAD **`3d905eb80`**.
Inclus : **prix caisse** (9,90€ pas 10€ + monnaie juste + viande suppl +2,50€) · ticket width-safe
(fin des coupures) · note+œuf · KDS toutes commandes · borne = caisse (même ticket) · seeder stations KDS
· paiement « à régler en caisse » + menu enfant.

---

## PARTIE 1 — DÉPLOIEMENT

### ⚠️ Piège : la prod déploie `main`, MAIS les fixes sont sur la BRANCHE → déployer la branche.
```bash
ssh lecayenne
cd /var/www/lecayenne
git fetch origin pos/category-first-caisse-2026-06-23
git status --short          # s'il y a des hot-patches locaux (SCP) : les sauvegarder avant (le script bloque reset --hard sinon)
sudo LECAYENNE_BRANCH=pos/category-first-caisse-2026-06-23 bash scripts/deploy/deploy.sh
php artisan view:clear && php artisan route:clear
```

---

## PARTIE 2 — CONFIGURATION

### 2a. ⭐ Largeur imprimante (LA correction anti-coupure ticket)
```bash
php artisan tinker
>>> \App\Models\Printer::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->update(['width_chars' => 32]);
>>> \App\Models\Printer::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->get(['id','station','width_chars']);
```
Test papier : imprimer une ligne de 48 tirets → si elle revient à la ligne = 58mm → garder **32**
(mettre 48 seulement si le papier est 80mm et que 48 tirets tiennent sur une ligne).

### 2b. Stations KDS
```bash
php artisan db:seed --class=KdsStationAssignmentSeeder --force
```

### 2c. Impression ESC/POS (caisse ET borne)
- Pont local répond `http://127.0.0.1:9100/health` → « UP » et accepte `POST /raw`.
- Chrome (caisse + borne) lancé avec :
  `--disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks`
- `.env` : `PRINT_DRIVER=windows_raw` + `php artisan pos:setup-receipt-printer "<NOM_SAGA>"`.

---

## PARTIE 3 — TESTS E2E RÉELS (le cœur — ne rien sauter)

### 3a. PRIX (les 2 bugs owner)
1. **Cayenne + Menu (Frites+Boisson)** → doit afficher **9,90 €** partout (grille/panier/total), PAS 10 €.
2. Encaisser espèces : payer **20 €** sur 9,90 € → « Monnaie à rendre **10,10 €** » (PAS 11,10). Tester 10→0,10 / 50→40,10.
3. Le **ticket encaissé** met bien **9,90 €** et **Rendu 10,10 €**.

### 3b. COMPOSITION produit par produit (le « produit qui fait une erreur »)
Composer CHAQUE produit composable et vérifier compo + prix corrects, ajout panier SANS erreur :
- **Sandwichs multi-viandes** (Méga, Terminator, Tacos L) : choisir **2 viandes différentes** →
  les 2 doivent rester au panier + sur le ticket. **+ viande supplémentaire** → facturée **+2,50 €** (récap = encaissé).
- **Cayenne / Suprême / TOUS les burgers / Galette** : **AUCUN choix de viande** (recette fixe, NORMAL) —
  seulement crudités / sauce / suppléments / menu. Vérifier qu'il n'y a PAS d'étape « viande » vide.
- **Bols** (Bol Frites, Bol Riz) : viande au choix (composer profile) + sauce.
- **Tacos M** : viande + sauce.
Pour chacun : le prix du récap == prix encaissé, la compo persistée == ce qui a été choisi.

> ⚠️ **Point à surveiller (connu, non bloquant fiscalement)** : « Viande supplémentaire » peut
> apparaître à DEUX endroits (panneau viande + case Suppléments). Si tu la coches aux deux, le
> récap peut sur-afficher. Cocher UNE seule fois. (Correctif propre prévu côté dev, sous LOCK.)

### 3c. TICKETS (caisse ET borne, client ET cuisine)
4. Ticket **CLIENT** : nom resto + adresse + n° commande, prix **entiers** (« 12,90 € » jamais
   coupé « 12,\n90 »), compo complète enroulée proprement, TVA, total. **AUCUNE coupure.**
5. Ticket **CUISINE** : `S | TERMINATOR | Mex Cordon | STO | AND` + suppléments (`+ Champignons`),
   **aucun prix**. Menu Enfant → « MENU ENFANT BURGER » ≠ « NUGGETS » (distincts).
6. Passer une commande **BORNE** → ticket **IDENTIQUE** à la caisse (même renderer serveur).

### 3d. ÉCRAN CUISINE (KDS)
7. Toutes les commandes visibles (plus de plafond « +N en attente »), tous les produits de chaque
   commande (grosse commande = plus de hauteur), symboles + suppléments corrects.

---

## PARTIE 4 — À RAPPORTER (photos)
- `width_chars` retenu (32/48) + « 48 tirets sur 1 ligne » oui/non.
- Cayenne+Menu = 9,90 € · monnaie 20→10,10 · viande suppl +2,50.
- Compo : les multi-viandes gardent 2 viandes ; Cayenne/burgers sans étape viande.
- Ticket client (prix entiers, 0 coupure) · ticket cuisine · ticket borne == caisse.
- KDS : toutes les commandes + tous les produits.
- **Toute erreur/coupure/crash → photo + produit + étape exacte. NE PAS clore si un seul cas casse.**

> Si un ticket coupe → `width_chars` mal réglé (2a) OU pont ESC/POS injoignable (2c → retombe sur
> window.print = HTML mal rendu). Si un prix affiche « 10 » au lieu de « 9,90 » → le déploiement n'a
> pas pris (rebuild bundle) : re-vérifier Partie 1.
