# MISSION COWORK — Encaissement unifié + montant CARTE saisi (compta) : installer + valider

> Branche `pos/category-first-caisse-2026-06-23`, HEAD **`594eb92f5`**. Prérequis : redéployer.

## Ce qui a été fait + prouvé (local, e2e)
1. **Interface d'encaissement UNIFIÉE** caisse + borne : page `/admin/encaissement`, même file (badges
   Caisse/Borne), MÊME modal `PosCounterCollectModal` (activer `POS_WALKIN_ROUTE_TO_COUNTER=false   # ⚠️ CORRIGÉ 02-07 : FALSE — la caisse paie INLINE (déjà encaissée). true forçait TOUTE commande caisse dans « à encaisser » (bug owner). Seule la BORNE va en à-encaisser (via KIOSK_PAYMENT_ROUTE_ALL_TO_COUNTER).`).
2. **Montant CARTE saisi à la main** (nouveau) : mode Carte affiche « MONTANT CARTE » (prérempli = total)
   + le MÊME pavé numérique que les espèces (readonly = pas de clavier Windows). Le TPE est manuel — la
   caisse ENREGISTRE le montant carte pour la compta. e2e : mode Carte → input+numpad → confirm 200.
3. **Ticket client + cuisine = écran** : le ticket imprimé (pont ESC/POS) reprend exactement l'aperçu.
   Client = resto+adresse+produits+compo+TVA+total ; Cuisine = symbolique (`S | MÉGA | Mex Cordon | STO
   | MAY` + `MENU`). Décodage octets vérifié.
4. **Compta par mode** : `confirmCounterPayment` enregistre le mode (CARD/CASH) + montant (Transaction +
   pos_received_amount) → le Z-report / la vue caisse ventilent **X carte / X espèces**.

---

## ORDRE D'INSTALLATION
### 1. Déployer
```bash
ssh lecayenne && cd /var/www/lecayenne
sudo LECAYENNE_BRANCH=pos/category-first-caisse-2026-06-23 bash scripts/deploy/deploy.sh
git rev-parse --short HEAD   # == 594eb92f5 (ou +)
php artisan config:clear && php artisan config:cache && php artisan view:clear
```
### 2. Activer l'unification + nettoyer les tests
`.env` : `POS_WALKIN_ROUTE_TO_COUNTER=false   # ⚠️ CORRIGÉ 02-07 : FALSE — la caisse paie INLINE (déjà encaissée). true forçait TOUTE commande caisse dans « à encaisser » (bug owner). Seule la BORNE va en à-encaisser (via KIOSK_PAYMENT_ROUTE_ALL_TO_COUNTER).` (owner-gate demandé) puis `php artisan config:cache`.
```bash
php artisan tinker
>>> \App\Models\Order::where('payment_status',\App\Enums\PaymentStatus::PENDING_COUNTER)->whereNull('fiscal_sequence_no')->delete();
>>> exit
```
### 3. Hardware impression (sinon pas de ticket)
Pont `127.0.0.1:9100` UP + flag Chrome `--disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks`
+ `.env PRINT_DRIVER=windows_raw` + `pos:setup-receipt-printer "<NOM_SAGA>"` + `width_chars=32`.
Hard reload Chrome + désenregistrer les service workers.

---

## TESTS E2E RÉELS (boucle jusqu'à tout vert)
1. **Même file** : une commande caisse ET une commande borne apparaissent dans `/admin/encaissement`
   (badges Caisse / Borne), même bouton Encaisser → même modal.
2. **ESPÈCES** : Encaisser → Espèce → taper le montant reçu au **pavé** (pas de clavier Windows) →
   rendu monnaie affiché → Confirmer → **ticket client imprimé** == aperçu écran.
3. **CARTE (manuel)** : Encaisser → **Carte** → « MONTANT CARTE » prérempli = total → **taper le montant
   au pavé** → Confirmer & Imprimer ticket → **ticket client imprimé**. (Le TPE physique est encaissé à
   la main ; la caisse n'enregistre que le montant en détail.)
4. **Ticket cuisine** : symbolique (`S | PRODUIT | viandes | STO | sauce` + `+ suppléments` + `MENU`),
   sans prix, = l'écran cuisine.
5. **Compta** : après quelques encaissements (mix carte + espèces), ouvrir la **Vue Caisse Unifiée** /
   le Z-report → doit afficher **X en carte** et **X en espèces** distinctement.

## À RAPPORTER (photos)
- `/admin/encaissement` caisse + borne ensemble.
- Mode Carte avec « MONTANT CARTE » + pavé (aucun clavier Windows).
- Ticket client physique (== écran) + ticket cuisine (symbolique).
- Ventilation compta carte/espèces (Z-report ou vue caisse).
- **Tout écart → photo + montant + vérifier `git HEAD == 594eb92f5`.**

## STABILITÉ LONG-TERME
- Jamais de patch manuel VPS → git → `deploy.sh`. Pont + flag Chrome au démarrage auto de la caisse.
- Le nettoyage ne touche JAMAIS une commande fiscalisée (NF525 intact).
- Si le ticket ne sort pas : 90% = pont tombé / flag Chrome manquant (le code envoie toujours les bons octets).
