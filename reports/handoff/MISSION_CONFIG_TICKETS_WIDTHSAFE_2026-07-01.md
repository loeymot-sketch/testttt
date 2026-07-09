# MISSION — Tout configurer pour des tickets PROPRES (caisse + borne, sans coupure)

> Pour : Claude cowork / opérateur ayant accès au VPS + à la machine caisse/borne.
> But : mettre en ligne les corrections « ticket width-safe » (fin des coupures « 7,\n40 € »),
> régler la largeur imprimante, et vérifier en RÉEL que le ticket sort propre (caisse ET borne,
> client ET cuisine), même forme des deux côtés.

Branche : **`pos/category-first-caisse-2026-06-23`** — HEAD **`2eeeafb66`** (déjà poussée).
Commits concernés (tous poussés) : `a127fa614` (paiement + menu enfant) · `19ffe8ecf` (Echo) ·
`a8c0517d6` (seeder stations KDS) · `4ded428c2` (**ticket width-safe**) · `afecbcd3a` (note +
ligature œuf) · `2404a4b15` (**KDS toutes commandes**) · `2eeeafb66` (**borne = caisse**).

---

## ÉTAPE 1 — Déployer (apporte tout le code)

```bash
cd /var/www/lecayenne
bash tools/deploy-vps.sh /var/www/lecayenne     # git reset origin + npm run production + clear caches
php artisan view:clear && php artisan route:clear
```

---

## ÉTAPE 2 — ⭐ RÉGLER LA LARGEUR DE L'IMPRIMANTE (LA correction anti-coupure)

**C'est LE réglage clé.** Le renderer produit maintenant un ticket parfait à la largeur de
l'imprimante — MAIS il faut lui dire la bonne largeur, sinon l'imprimante 58mm ré-enroule.

### 2a. Déterminer la largeur du papier (empirique, 30 s)
Imprime un test : si **48 tirets** tiennent sur UNE ligne → 80mm → **48**. S'ils reviennent à
la ligne → 58mm → **32** (le cas de tes photos).
```bash
php artisan tinker
>>> $b = \App\Services\Hardware\EscPosCommandBuilder::init()
...   . \App\Services\Hardware\EscPosCommandBuilder::textLine(str_repeat('-',48))
...   . \App\Services\Hardware\EscPosCommandBuilder::textLine('1234567890123456789012345678901234567890  32|48')
...   . \App\Services\Hardware\EscPosCommandBuilder::cut();
# → envoie $b au pont /raw comme un ticket, regarde si la ligne de 48 tirets se coupe.
```
Le plus probable (vu tes photos) = **58mm = 32**.

### 2b. Régler `width_chars` sur TOUTES les imprimantes actives
```bash
php artisan tinker
>>> \App\Models\Printer::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
...   ->update(['width_chars' => 32]);   # 32 pour 58mm  (mettre 48 si 80mm)
>>> \App\Models\Printer::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
...   ->get(['id','station','width_chars']);   # vérifier : width_chars=32 partout
```
> Alternative UI : Admin → Imprimantes → chaque imprimante → champ « caractères par ligne » = 32.
> Il n'y a normalement qu'une imprimante (station `receipt`) ; le ticket cuisine réutilise sa
> largeur si aucune station cuisine dédiée.

---

## ÉTAPE 3 — S'assurer que ça imprime en ESC/POS (pas via le navigateur)

Le ticket propre sort par le **pont local ESC/POS**. Sans lui, ça retombe sur `window.print()`
(le HTML mal rendu = « EUR », coupures) :
- Le **pont local** doit tourner et répondre sur `http://127.0.0.1:9100/health` (« UP ») et
  accepter `POST /raw` (octets ESC/POS bruts). (cf. `docs/runbooks/BORNE_LOCAL_BRIDGE_SETUP.md`.)
- **Chrome** (caisse + borne) lancé avec le flag :
  `--disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks`
  (sinon Chrome bloque l'appel page-HTTPS → 127.0.0.1).
- Serveur : `.env` `PRINT_DRIVER=windows_raw`, imprimante déclarée
  (`php artisan pos:setup-receipt-printer "<NOM_SAGA>"`).

**Vérif pont** : `curl -s http://127.0.0.1:9100/health` doit répondre « UP ».

---

## ÉTAPE 4 — KDS + (optionnel) temps réel

```bash
# Stations KDS (filtre par poste) — 1 fois :
php artisan db:seed --class=KdsStationAssignmentSeeder --force

# (Optionnel) WebSocket temps réel — sinon polling (marche déjà) :
#   .env : PUSHER_APP_KEY + MIX_PUSHER_APP_KEY (host PUBLIC, pas 127.0.0.1) + Soketi:6001 + proxy Nginx, PUIS rebuild.
```

---

## ÉTAPE 5 — VÉRIFIER EN RÉEL (le test qui compte)

1. **Passe une commande à la CAISSE** (ex. Terminator 2 viandes + 1 supplément + menu).
   - Ticket **CLIENT** : nom resto, adresse, n° commande, articles avec **prix entiers**
     (« 10,80 € » jamais coupé), compo enroulée proprement, TVA, total. **Aucune ligne coupée.**
   - Ticket **CUISINE** : `S | TERMINATOR | Mex Cordon | STO | AND` + suppléments (`+ Champignons`),
     **aucun prix**, propre.
2. **Passe une commande à la BORNE** → le ticket qui sort doit être **IDENTIQUE** à celui de la
   caisse (même renderer serveur maintenant).
3. **Écran cuisine (KDS)** : toutes les commandes visibles, tous les produits de chaque commande
   (une grosse commande prend plus de hauteur, plus de « +N en attente » qui cache).

### Si un ticket coupe encore
→ `width_chars` n'est pas à la bonne valeur : re-vérifier l'étape 2 (probablement mettre **32**),
ou le pont n'est pas joignable (ça retombe sur window.print) : re-vérifier l'étape 3.

---

## À rapporter
- Valeur `width_chars` retenue (32 ou 48) + confirmation « 48 tirets sur 1 ligne ».
- Photos : ticket client caisse, ticket cuisine, ticket borne → **prix entiers, 0 coupure**.
- Confirmation : ticket borne == ticket caisse ; KDS montre toutes les commandes.
- Toute coupure résiduelle : photo + quelle étape.
