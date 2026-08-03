# 🚀 MISSION COWORK — INSTALLATION FINALE (tout, dans l'ordre) — Le Cayenne

> Objectif : mettre en production TOUT ce qui a été codé + validé cette session, sur le serveur ET
> sur les machines (caisse + borne), une fois, sans faute, stable long-terme.
> **Source de vérité : branche `pos/category-first-caisse-2026-06-23`, HEAD `594eb92f5`.**
>
> **RÈGLE D'OR (pour toujours)** : on ne modifie JAMAIS le serveur à la main / par SCP. Tout passe par
> git → `deploy.sh` (git reset --hard + rebuild complet). Un fichier posé à la main = future panne.

---

## PHASE 1 — SERVEUR (VPS)

### 1.1 Déployer la branche
```bash
ssh lecayenne && cd /var/www/lecayenne
git status --short                      # s'il y a des hot-patches locaux → git stash (ils seront écrasés proprement)
sudo LECAYENNE_BRANCH=pos/category-first-caisse-2026-06-23 bash scripts/deploy/deploy.sh
git rev-parse --short HEAD              # DOIT afficher 594eb92f5 (ou +)
```
`deploy.sh` fait : `git reset --hard` + `npm ci && npm run production` (rebuild COMPLET des bundles) + migrations.

### 1.2 Configurer le `.env` (⚠️ jamais commité)
Ajouter / vérifier ces lignes :
```env
# Encaissement unifié caisse + borne
POS_WALKIN_ROUTE_TO_COUNTER=false   # ⚠️ CORRIGÉ 02-07 : FALSE — la caisse paie INLINE (déjà encaissée). true forçait TOUTE commande caisse dans « à encaisser » (bug owner). Seule la BORNE va en à-encaisser (via KIOSK_PAYMENT_ROUTE_ALL_TO_COUNTER).
KIOSK_PAYMENT_ROUTE_ALL_TO_COUNTER=true
# Borne (auto-login machine — mettre les identifiants de la KioskMachine active)
KIOSK_MACHINE_USERNAME=<username_machine_borne>
KIOSK_MACHINE_PASSWORD=<password_machine_borne>
KIOSK_DEFAULT_LOCALE=fr
KIOSK_LOCALE_SWITCH_ALLOWED=false
KIOSK_REQUIRE_MACHINE_LOGIN=false
# Impression thermique
PRINT_DRIVER=windows_raw
# (Uber Eats — laisser vide tant qu'Uber n'a pas accordé le Production Access)
```
Puis :
```bash
php artisan config:clear && php artisan config:cache && php artisan view:clear && php artisan route:clear
```

### 1.3 Données (tinker)
```bash
php artisan tinker
# a) Largeur imprimante 58mm (anti-coupure ticket)
>>> \App\Models\Printer::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->update(['width_chars'=>32]);
# b) Terminal TPE actif (nécessaire au paiement carte) — vérifier qu'il en existe 1 status=1 branch=1, sinon :
>>> \App\Models\PaymentTerminal::firstOrCreate(['name'=>'TPE Le Cayenne #1'],['status'=>1,'branch_id'=>1,'gateway_type'=>'sumup']);
# c) KioskMachine active (borne) — vérifier :
>>> \App\Models\KioskMachine::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->get(['id','username','status','branch_id']);
# d) NETTOYER les commandes de test en attente d'encaissement (JAMAIS les fiscalisées → NF525 intact)
>>> \App\Models\Order::where('payment_status',\App\Enums\PaymentStatus::PENDING_COUNTER)->whereNull('fiscal_sequence_no')->delete();
>>> exit
# e) Stations KDS
php artisan db:seed --class=KdsStationAssignmentSeeder --force
```

---

## PHASE 2 — MACHINES (caisse + borne, Windows)

### 2.1 Vider le cache + les service workers (fini l'« ancienne version »)
Sur CHAQUE machine : fermer Chrome → `chrome://serviceworker-internals` → **Unregister** tout lecayenne.fr
→ vider le cache → `Ctrl+Shift+R`.

### 2.2 Chrome en mode kiosk/caisse avec le flag impression (INDISPENSABLE)
Raccourci (dans `shell:startup` pour l'auto-démarrage) :
```
chrome.exe --disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks
  [--kiosk --app=https://lecayenne.fr/kiosk/idle]   ← BORNE uniquement
```
> Sans ce flag, Chrome bloque l'appel page-HTTPS → `127.0.0.1` du pont d'impression → aucun ticket ne sort.

### 2.3 Pont d'impression ESC/POS local (démarrage auto au boot)
- Doit répondre `http://127.0.0.1:9100/health` → « UP » et accepter `POST /raw`.
- Vérifier une impression test.

---

## PHASE 3 — TESTS E2E RÉELS (refaire chacun ; ne pas clore si un seul casse)

**A. Borne** : idle → composer un **multi-viandes** (Tacos L / Méga : 2 viandes) → PAS d'erreur « Viande 2 »,
les 2 viandes au panier → payer en caisse → **#A00xx** affiché.
**B. Prix** : Cayenne + Menu = **9,90 €** ; monnaie 20 € → **10,10 €**. Cayenne + burgers = SANS choix viande.
**C. Encaissement unifié** : `/admin/encaissement` montre **Caisse ET Borne** (badges), même bouton Encaisser.
**D. Espèces** : Encaisser → Espèce → montant au **pavé** (aucun clavier Windows) → rendu monnaie → ticket client imprimé.
**E. Carte (manuel)** : Encaisser → **Carte** → « MONTANT CARTE » prérempli → taper au pavé → Confirmer → ticket client imprimé.
**F. Ticket client == écran** : resto + adresse + À EMPORTER + produits + compo + TVA + total, prix entiers, 0 coupure.
**G. Ticket cuisine** : symbolique `S | MÉGA | Mex Cordon | STO | MAY` + `+ suppléments` + `MENU`, sans prix.
**H. KDS** : la commande apparaît (badge « en attente encaissement » → « réglé » après encaissement) ; toutes les commandes visibles.
**I. Compta** : après un mix carte + espèces, la **Vue Caisse Unifiée / Z-report** ventile **X carte / X espèces**.

### Si le KDS est vide (ne PAS accuser `kds_station` — colonne inexistante)
```bash
php artisan tinker
>>> $o=\App\Models\Order::withoutGlobalScopes()->latest('id')->first();
>>> echo "status={$o->status} payment_status={$o->payment_status} payment_method={$o->payment_method} branch={$o->branch_id}\n";
```
Visible KDS ⇔ `status∈{4,7,8}` ET `payment_status∈{5,15}` ET même branche que le chef. Si `payment_status=10` →
la borne n'a pas routé en caisse : vérifier `KIOSK_PAYMENT_ROUTE_ALL_TO_COUNTER=true` + rebuild (1.1/1.2).

---

## PHASE 4 — À RAPPORTER (photos)
`git HEAD` VPS == 594eb92f5 · borne multi-viandes OK · prix 9,90/10,10 · `/admin/encaissement` caisse+borne ·
mode Carte avec « MONTANT CARTE » + pavé (aucun clavier Windows) · ticket client physique (== écran) ·
ticket cuisine symbolique · KDS · ventilation compta carte/espèces.
**Tout échec → photo + message exact + F12(réseau) + vérifier le HEAD.**

## PHASE 5 — STABILITÉ LONG-TERME
- Pont d'impression + flag Chrome au **démarrage automatique** de chaque machine (sinon perdus au reboot).
- Futures mises à jour : commit → push → `deploy.sh` + hard reload Chrome. **Rien d'autre à toucher.**
- Le nettoyage (1.3.d) ne supprime JAMAIS une commande fiscalisée → chaîne NF525 intacte.
- Uber Eats : la fondation est en place (inactive). L'activer quand Uber accorde le Production Access
  (mettre les clés `.env` + enregistrer le webhook `https://lecayenne.fr/api/webhooks/uber`).

> Diagnostic express des 2 pannes classiques :
> • **Ticket ne sort pas** = pont tombé (relancer) OU flag Chrome manquant. Le code envoie toujours les bons octets.
> • **Écran blanc borne** = bundle incomplet / service worker de l'ancienne version → redéployer (1.1) + vider SW (2.1).
