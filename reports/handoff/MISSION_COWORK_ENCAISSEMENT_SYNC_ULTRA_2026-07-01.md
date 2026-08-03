# MISSION COWORK (ULTRA-DÉTAILLÉE) — Encaissement TPE/carte + synchro caisse↔borne↔KDS

> Prérequis : exécuter d'abord `MISSION_COWORK_INSTALL_DEFINITIVE_2026-07-01.md` (déploiement propre).
> Branche `pos/category-first-caisse-2026-06-23`, HEAD **`258f74722`**.

## DIAGNOSTIC (à comprendre avant de tester)
J'ai reproduit et PROUVÉ en local (endpoints réels) que **l'encaissement fonctionne côté code** :
- **Carte caisse** : commande #5372 créée **PAYÉE** (payment_status=5). ✅
- **Encaissement borne au comptoir** : #5367 PENDING_COUNTER→**PAID** + **fiscal #2582 alloué à l'encaissement**. ✅
- **Synchro** : la caisse affiche « À ENCAISSER BORNE (151) », chaque commande borne avec bouton « Encaisser ». ✅
- **KDS** : distingue « à encaisser » (payment_status=15) vs « réglé » (=5). ✅

**Donc si « ça ne marche pas » sur la vraie machine, la cause est l'UNE de ces 3 (PAS le code) :**
1. **Le VPS tourne l'ancien code** (cause récurrente — redéployer d'abord).
2. **Aucun terminal TPE ACTIF** pour la branche → toute vente CARTE renvoie 422 (« terminal id required »).
3. **Friction simulation TPE** : en attendant le vrai terminal, la carte exige la saisie manuelle des
   4 derniers chiffres + le choix du terminal (pas encore d'intégration matérielle — c'est ASSUMÉ V1).

Un fix code a été livré (`258f74722`) : la file d'encaissement **exclut les commandes annulées** et
**rattrape les commandes borne à `source_surface` NULL** (sinon invisibles/inencaissables).

---

## PARTIE 1 — CONFIG INDISPENSABLE

### 1a. Vérifier qu'un TERMINAL TPE est ACTIF (sinon carte = 422)
```bash
php artisan tinker
>>> \App\Models\PaymentTerminal::withoutGlobalScopes()->get(['id','name','status','branch_id']);
# Il DOIT y avoir au moins 1 terminal status=1 sur branch_id=1. Sinon en créer un :
>>> \App\Models\PaymentTerminal::create(['name'=>'TPE Le Cayenne #1','status'=>1,'branch_id'=>1,'gateway_type'=>'sumup']);
>>> exit
```

### 1b. Simulation TPE (mode V1 assumé — pas de vrai terminal câblé)
`.env` : `POS_SIMULATION_HARDWARE=true` en dev/test. **INTERDIT en prod NF525** (le boot refuse).
En simulation, l'encaissement CARTE = **la caissière saisit 4 chiffres quelconques** (ex. `0000`)
+ le terminal est pré-sélectionné automatiquement → clic « Encaisser » → la vente est enregistrée PAYÉE.
> C'est le comportement voulu tant que le vrai TPE n'est pas intégré. L'intégration matérielle
> (le montant part au terminal, le terminal répond approuvé) = évolution future, pas un bug V1.

### 1c. Synchro temps-réel (optionnel — le polling marche déjà)
Pour que les commandes borne apparaissent en caisse **instantanément** (au lieu de ~10-20 s polling) :
`.env` : `BROADCAST_DRIVER=pusher` (ou reverb) + `PUSHER_APP_KEY`/`MIX_PUSHER_APP_KEY` (host PUBLIC),
puis **rebuild** (`npm run production`). Sans ça, le polling rafraîchit tout seul (dégradation acceptée).

### 1d. Impression ticket
`.env` : `PRINT_DRIVER=windows_raw` + pont ESC/POS `127.0.0.1:9100` + `php artisan pos:setup-receipt-printer "<NOM_SAGA>"`.

---

## PARTIE 2 — TESTS E2E RÉELS (le cœur — refaire chaque cas sur la vraie machine)

### TEST A — Vente CAISSE payée par CARTE (le cas « ça marche pas »)
1. Caisse → prendre une commande (ex. 1 Tacos M) → **Payer**.
2. Choisir **Carte (TPE)** → le terminal doit être **pré-sélectionné** (sinon config 1a).
3. Saisir **4 chiffres** (ex. `0000`) → **Encaisser**.
4. ✅ Attendu : commande **enregistrée PAYÉE**, **ticket client imprimé**, commande **envoyée en cuisine (KDS)**.
   ⚠️ Si « ça ne marche pas » : noter le message EXACT + faire F12→Réseau→regarder le POST `/api/admin/pos`
   (code 422 ? 401 ? 200 ?). 422 « terminal id required » = config 1a. 401 = re-login. 200 mais toast rouge = me remonter (faux négatif UI).

### TEST B — Vente CAISSE payée en ESPÈCES
1. Prendre commande → Payer → **Espèces** → saisir le montant reçu (ex. 20€ sur 6,90).
2. ✅ Attendu : **monnaie à rendre 13,10€**, tiroir s'ouvre, ticket imprimé, PAYÉE, va en cuisine.

### TEST C — Commande BORNE encaissée en CAISSE (Plan B)
1. Sur la **borne** : passer une commande, choisir « payer en caisse ».
2. Sur la **caisse** : panneau « **À ENCAISSER BORNE** » → la commande apparaît (avec son n° A00xx).
3. Cliquer « **Encaisser** » → choisir **Carte** (4 chiffres) OU **Espèces**.
4. ✅ Attendu : commande passe **« à encaisser » → « réglée »**, **disparaît de la file**, **fiscal alloué**,
   ticket imprimé. La commande RESTE en cuisine (elle y était déjà) mais son badge passe **réglé**.

### TEST D — Écran cuisine (KDS) : statut réglé vs en attente
1. Ouvrir le KDS (`/admin/kitchen-display-system`).
2. ✅ Attendu : une commande borne non encaissée = badge « **EN ATTENTE ENCAISSEMENT** » ;
   après encaissement (test C) = le badge disparaît / passe **réglé**. Toutes les commandes visibles.

### TEST E — Ticket client + cuisine
1. À l'encaissement, vérifier qu'on peut **imprimer un ticket client** (bouton).
2. ✅ Attendu : ticket client (prix, TVA, total) + ticket cuisine (symboles, sans prix) — voir
   `MISSION_COWORK_ECRAN_CUISINE_SYMBOLES`. La commande part en cuisine à la **création** (pas au paiement)
   pour que la cuisine prépare pendant que le client paie ; le badge « réglé » se met à jour à l'encaissement.

### TEST F — Synchro croisée (les 2 sens)
1. Passer 3 commandes borne d'affilée → les 3 apparaissent en caisse « à encaisser » (dans les ~20 s, ou
   instantané si Echo activé).
2. Encaisser la 2e → seule la 2e disparaît de la file, les 2 autres restent.
3. ✅ Attendu : aucune commande perdue, aucun double, la file caisse == réalité, le KDS cohérent.

---

## PARTIE 3 — À RAPPORTER (photos + preuves)
- 1a : capture du terminal TPE actif.
- Test A : vente carte → PAYÉE + ticket (ou, si échec, le POST réseau F12 + message exact).
- Test B : monnaie rendue correcte.
- Test C : commande borne encaissée (avant/après dans la file + fiscal).
- Test D : KDS « en attente » puis « réglé ».
- Test E : ticket client + cuisine.
- Test F : 3 commandes, encaissement sélectif sans perte.
- **Tout échec → message EXACT + capture réseau (F12) + n° commande. Ne pas conclure « bug » sans avoir
  vérifié `git HEAD` du VPS == `258f74722` (sinon = ancien code).**

> Bugs code déjà corrigés (dans `258f74722`) : file d'encaissement robuste (annulées exclues, NULL rattrapé).
> Restant = config (terminal/Echo/print) + friction simulation TPE (assumée jusqu'au vrai terminal).
