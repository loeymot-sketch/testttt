# MISSION COWORK (ULTRA) — Encaissement UNIFIÉ caisse+borne : nettoyer, installer, valider

> Branche `pos/category-first-caisse-2026-06-23`, HEAD **`7efe7454c`**.
> Objectif owner : **une seule interface d'encaissement** pour la caisse ET les commandes borne,
> file d'attente nettoyée (c'étaient des tests), ticket client imprimé, pavé numérique (pas de clavier
> Windows). Tout validé e2e en local. Reste : déployer + configurer + re-tester sur la vraie machine.

## Ce qui a été fait + prouvé (local)
- **Interface UNIFIÉE** : la page **`/admin/encaissement`** liste caisse + borne dans la MÊME file
  (badges « Caisse » / « Borne »), avec le MÊME modal `PosCounterCollectModal` (pavé numérique,
  modes Espèce/Carte/Mobile/Ticket, readonly = pas de clavier Windows). Capture à l'appui.
- **Ticket client imprimé** à l'encaissement via le pont ESC/POS (endpoint `escpos` = même rendu que
  l'aperçu écran) — corrigé sur les 2 handlers (`PosComponent` + `EncaissementComponent`).
- **e2e prouvé** : file = {Caisse A0046, Borne B0001} → clic Encaisser → même modal → `confirm` →
  `GET escpos` → `POST pont/raw` = ticket imprimé.
- **Base test nettoyée** en local (148 commandes en attente soft-deleted → file vide).

---

## ORDRE D'INSTALLATION (à suivre exactement)

### 1. Déployer
```bash
ssh lecayenne && cd /var/www/lecayenne
sudo LECAYENNE_BRANCH=pos/category-first-caisse-2026-06-23 bash scripts/deploy/deploy.sh
git rev-parse --short HEAD   # doit == 7efe7454c (ou +)
```

### 2. Activer l'unification (route les ventes caisse vers la file d'encaissement)
`.env` du VPS :
```env
POS_WALKIN_ROUTE_TO_COUNTER=false   # ⚠️ CORRIGÉ 02-07 : FALSE — la caisse paie INLINE (déjà encaissée). true forçait TOUTE commande caisse dans « à encaisser » (bug owner). Seule la BORNE va en à-encaisser (via KIOSK_PAYMENT_ROUTE_ALL_TO_COUNTER).
```
> Effet : une vente prise à la caisse devient PENDING_COUNTER + COUNTER_DEFERRED (comme la borne) →
> apparaît dans `/admin/encaissement` → encaissée via le MÊME modal. Le n° fiscal reste alloué à
> l'encaissement (NF525 gap-free). C'est un **gate owner** — l'owner l'a explicitement demandé.
```bash
php artisan config:clear && php artisan config:cache
```

### 3. NETTOYER les commandes de test en attente d'encaissement (VPS)
⚠️ **Ne supprime QUE les non-fiscalisées (jamais encaissées) = sûr, aucun impact chaîne NF525.**
```bash
php artisan tinker
>>> $n = \App\Models\Order::where('payment_status', \App\Enums\PaymentStatus::PENDING_COUNTER)
...       ->whereNull('fiscal_sequence_no')->delete();
>>> echo "nettoyées: $n | reste: ".\App\Models\Order::where('payment_status',\App\Enums\PaymentStatus::PENDING_COUNTER)->count()."\n";
>>> exit
```
Résultat attendu : file d'encaissement **VIDE** (clean slate).

### 4. Config HARDWARE impression (sinon le ticket ne sort pas)
- Pont local `http://127.0.0.1:9100/health` → « UP », accepte `POST /raw`.
- Chrome (caisse) avec flag `--disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks`.
- `.env` : `PRINT_DRIVER=windows_raw` + `php artisan pos:setup-receipt-printer "<NOM_SAGA>"`.
- `\App\Models\Printer::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->update(['width_chars'=>32]);`
- Hard reload Chrome (Ctrl+Shift+R) + désenregistrer les service workers.

---

## 5. TESTS E2E RÉELS (boucle jusqu'à tout vert)

### A. Interface unifiée
1. Prendre une commande à la **caisse** → elle doit apparaître dans **`/admin/encaissement`** (badge **Caisse**).
2. Passer une commande à la **borne** → elle apparaît dans la MÊME page (badge **Borne**).
3. ✅ Les deux sont dans la MÊME file, avec le MÊME bouton « Encaisser ».

### B. Même modal + pavé + pas de clavier Windows
4. Cliquer « Encaisser » (sur l'une puis l'autre) → le MÊME modal s'ouvre.
5. ⭐ Taper le montant sur le **pavé de l'app** → **aucun clavier Windows ne surgit** (input readonly).
6. Choisir Espèce (rendu monnaie) ou Carte (TPE simulé, 4 chiffres) → « Confirmer & Imprimer ticket ».

### C. Impression + statut
7. ✅ Le **ticket CLIENT s'imprime** (resto, adresse, produits, TVA, total), **identique à l'aperçu écran**.
8. ✅ La commande passe de « à encaisser » à **réglée** et **disparaît de la file** ; fiscal alloué.
9. Vérifier le **KDS** : la commande y était (préparée pendant l'attente), badge passe réglé.

## 6. À RAPPORTER (photos)
- `/admin/encaissement` avec caisse + borne ensemble (badges).
- Modal unique + pavé numérique + **aucun clavier Windows**.
- Ticket client physique imprimé (== aperçu écran).
- File vide après nettoyage ; commande encaissée disparaît.
- **Tout échec → message exact + capture réseau F12 + vérifier `git HEAD == 7efe7454c`.**

## STABILITÉ LONG-TERME
- Jamais de patch manuel sur le VPS → toujours git → `deploy.sh`.
- Pont d'impression + flag Chrome au démarrage auto de la caisse (sinon perdus au reboot).
- Le nettoyage (étape 3) ne touche JAMAIS une commande fiscalisée → chaîne NF525 intacte.
- Futures MàJ : `deploy.sh` + hard reload Chrome. Rien d'autre.

> Si le ticket ne sort pas : 90% = pont tombé (relancer) ou flag Chrome manquant. Le code envoie
> toujours les bons octets. Si la caisse n'apparaît pas dans la file : `POS_WALKIN_ROUTE_TO_COUNTER`
> pas à true / config pas rechargée (étape 2).
