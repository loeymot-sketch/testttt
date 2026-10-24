# Revue adverse TOUR 2 — e2e-complet-2026-10-22 (vagues A, B, C)

Verdict : **GREEN** selon la règle (0 P0/P1 `open` de disposition `fix`). 16 constats ADV2-xxx (2 P1, 3 P2, 11 P3), détail et preuves dans `adversarial-findings.json`.
Réserve : GREEN ne veut PAS dire « le site marche » : les deux P1 côté client (service fermé ADV2-008, Cayenne à 2 viandes ADV2-009) sont `other_session`, ni corrigés ni fermés ce tour.
Aucune écriture en base ni en production. Les probes tournent en PHPUnit SQLite mémoire depuis le scratchpad (`.../scratchpad/AdvProbeTest.php`, hors dépôt).

## 1. REGARDÉ (visuel d'abord, 25 PNG relus)

- **Planche Tournées, alerte `uber_attention` (F1)** : lisible, rouge `rgb(185,28,28)` sur rose `rgb(254,242,242)` (~5,9:1), texte entier, non coupé, pictogramme ⚠. Bouton « Nos livreurs » grisé sur la même ligne. OK. Mais aucune sortie (pas d'acquittement) → ADV2-004.
- **En-tête collant (F2, 1, 2, 3, 4, 1920)** : « Fermer » identique au repos et en bas (y=79), ne masque aucun bouton à la souris. Le contenu passe sous l'en-tête sans bande parasite. Le bandeau d'erreur est maintenant collé sous l'en-tête (F3, y 119-153) et visible quand on clique en bas. Mais il s'efface au relevé de 20 s (ADV2-006). Sur F4, après 16 Shift+Tab le focus est sorti du dialogue vers « + Supplément libre » : pas de piège à focus ni d'Échap (ADV2-005, antérieur, non régressé). À 1920×1080 la fixture montre deux « #9001 » (Karim, Sonia) : artefact de `page.route`, pas du produit.
- **Site, service fermé (R2-01 à R2-03)** : « SERVICE FERMÉ — OUVRE À 18H CE SOIR » mais COMMANDER plein et actif ; l'étape d'heure propose « Dès que prêt ~10-15 min » et un CTA actif sans mot de fermeture. Confirmé (ADV2-008).
- **422 Cayenne (R2-04, R2-05, 12-wizard-viandes)** : le wizard dit « CHOISIS 2 VIANDES, 2 incluses, 5 maximum » ; le client reçoit « Sélectionnez au maximum 1 Viande 1 (actuel : 2). (et 2 autres erreurs) », sous la ligne de flottaison, recouvert par la barre « Confirmer ». Confirmé (ADV2-009, ADV2-010). **R2-04 ne montre pas ce que le rapport dit** : on y voit « Le code n'a pas pu être envoyé » (429 OTP, instrument) → ADV2-011.
- **App, panier vide (13a/13b)** : C-104 confirmé à l'image : depuis l'accueil, « PANIER » et « Ton panier est vide » sont presque invisibles ; depuis Menu, lisibles. Non fermé (ADV2-012).
- **Autres** : confirmation de commande desktop/mobile (QR, #…), refus Douai mobile (« Livraison Offerte » encore affichée dans le récap alors que l'adresse est refusée : à noter, non chiffré), barre/accueil app 390 : RAS nouveau. Le toast « Ton compte a été supprimé… » recouvre le CTA COMMANDER sur 12-suppression : transitoire, non mesuré.

## 2. Revue de code des correctifs (relue ligne à ligne)

- **`dispatch()` en 2 transactions + `Cache::lock`** : le verrou est libéré dans tous les chemins (`$lock->get()` faux → retour avant le `try` ; sinon `finally { release() }`). Les `return` dans la closure de `DB::transaction` ne sortent que de la closure ; `$early/$order/$row` sont capturés par référence et cohérents (lus après la transaction). Probe : verrou tenu → HTTP 422 « Une demande Uber est déjà en cours ». Pilote de cache local `redis` ; le magasin `file` supporte aussi `Cache::lock` (FileLock présent) ; `array`/`null` sont interdits en production par le garde de démarrage. Course concurrente : un 2e double-clic est refusé par le verrou ; clé d'idempotence `lecayenne-order-<id>` envoyée à Uber (comportement chez Uber NON vérifié, aucun compte). Pire cas de temps (jeton 8 s + appel 8 s + retry 401) ≈ 32 s > TTL 30 s : fenêtre théorique. Défauts trouvés : `release()` garde l'appel HTTP DANS la transaction avec `lockForUpdate` (ADV2-003) ; aucun test ne mord sur le verrou (ADV2-014).
- **`cancelCourseForClosedOrder` / listener / `AssignPendingDeliveries`** : l'annulation d'une commande ORDINAIRE n'est pas cassée : `activeCourse()` renvoie null → `true`, arrêt supprimé ; les 159 tests Delivery passent (dont la libération sur `OrderCanceled` et le nettoyage du filet). Le listener est enregistré sur `OrderStatusChanged` ET `OrderCanceled` (EventServiceProvider.php:158-161, 213-217) : deux exécutions par annulation, idempotentes. Une exception (table absente) est avalée par le `try/catch` du listener, mais l'arrêt n'est alors plus libéré ; la commande `delivery:assign-pending` n'a AUCUN try/catch autour et meurt (probe : `QueryException`) → ADV2-002 (P3, probabilité faible : migration versionnée). Une annulation Uber refusée en 400 « déjà annulée » laisse l'arrêt indéfiniment (probe : 2 appels Uber en 2 passages) → ADV2-004.
- **`applyStatus` + `STATUS_RANK`** : `shopping_completed` ou tout statut inconnu est bien enregistré (consigne respectée) MAIS il écrase un statut « sac pris » : probe `pickup_complete` puis `courier_update` → statut `courier_update`, puis `/uber/release` renvoie HTTP 200 (la protection BAG_TAKEN saute). → ADV2-001 (P2). Le reste du B-004 tient (événement plus ancien ignoré, terminal jamais rouvert).
- **Webhook** : signature HMAC du corps brut, `hash_equals`, fail-closed sans secret, throttle propre. RAS. Nom exact de l'en-tête réel d'Uber non vérifiable ici.
- **`lang/fr.json`** : 2 clés seulement (`(and :count more error|errors)`), aucune autre chaîne `__()` touchée (grep + tinker : « (et 1 autre erreur) », « (et 3 autres erreurs) »). Pas de régression possible hors ces deux messages.
- **`PosDeliveryBoard.vue`** : `sticky` + `top:-1rem` + `scroll-padding-top:7rem` (112 px) : mesuré 85 px d'en-tête+bandeau à 1280×720. En bottom-sheet < 640 px (non capturé, aucun PNG mobile de la planche) un bandeau sur 2-3 lignes dépasserait 112 px : risque non mesuré. Le bundle servi `pos-shell.68efd8a8.js` contient `pos-delivery-board-head` (le correctif est bien servi).

## 3. Classements contestés

- **« ASAP → lendemain » rétracté (M2-004/C-11)** : RÉTRACTATION PROUVÉE par un SELECT indépendant : `orders` 8586-8592 `created_at = 2026-10-23 00:03` (Paris) et `delivery_slot_at = 2026-10-23 20:00` : même jour calendaire à Paris ; la spec comparait au jour UTC (le 22). Réserve : le volet « la confirmation n'affiche pas l'heure » (`heure=false`) n'a pas été rejoué.
- **« A-003 corrigé »** : FAUX si c'est ce qui est écrit. Seul le libellé du 422 est francisé (C-101). Le wizard impose toujours 2 viandes, le serveur en accepte 1 ; le site (`data/menu.js:499` « mono-viande viandes:0 » puis `:502 viandes: 2`, travail non commité d'une autre session) se contredit lui-même. P1 `other_session` ouvert.
- **B-001 / B-003 « fermés par PHPUnit seulement »** : les tests MORDENT (B-003 compare `transactionLevel()` à l'appel HTTP ; B-001 compte l'appel `/cancel` et l'arrêt supprimé). Limites : listener appelé directement ; le verrou n'est couvert par aucun test (ADV2-014).
- **R2-01 / R2-02 « ouverts »** dans le rapport B : le rapport est périmé par rapport à son propre code et ses PNG (corrigés par a4c72f9a7, PNG et JSON de 02:38 le prouvent) ; l'unique « obscurci » résiduel est « Fermer » lui-même (ADV2-013).
- **C-4 KO d'A-etats qui disparaît** : « cause non élucidée » ≠ corrigé (ADV2-016, environment).
- **« 429 = instrument »** : exact pour le test, mais un limiteur `3/5 min` par IP sur `/verify` est un risque réel derrière une IP partagée (réseau mobile) : non mesuré, à ne pas classer « instrument » sans le dire.
- **C-104 « onglet Menu non reproduit »** : confirmé aux deux états (13a/13b) ; le défaut reste atteignable depuis l'accueil.

## 4. Zones gelées / NF525

- `git diff --stat HEAD~8 -- public/js/pos-wizard.js public/css/pos-wizard.css resources/views/admin-pos-v4.blade.php PaymentComponent.vue PosV5TrancheRow.vue resources/js/components/frontend/kiosk app/Domain app/Services/Fiscal app/Services/Pricing app/Models/Scopes IdempotencyKeyMiddleware.php` : **vide**.
- `public/js/pos-wizard.js` sha256 `9196c2d3f3548f8344df1f216f9aebecf24255e57db0201617b662726a5378e8` = `git show HEAD:…` (identique) ; `git ls-files -d` : vide (aucun fichier suivi manquant) ; `git diff --name-only HEAD` : vide.
- `php artisan fiscal:verify-chain --all` : « SWEEP COMPLETE — CHAIN OK on every active branch (7 total) ». (Rappel : « CHAIN OK » n'atteste pas le gap-free, cf. rapport B §4.)
- `OrderStateMachine` non modifiée ; le code Uber l'appelle par `allows()/apply()` existants.

## 5. NON VÉRIFIABLE

- Comportement réel d'Uber (idempotence, codes d'erreur d'annulation, forme/en-tête des webhooks, latence du relevé dans `board`) : aucun compte, Uber éteint.
- État de migration de la production (`uber_direct_deliveries`) : lecture de production interdite à ce tour (ADV2-002).
- Planche Tournées en bottom-sheet < 640 px (aucun PNG mobile ; ≤ 5 PNG neufs et pas de navigateur lancé par moi).
- C-103 (barre d'état), iOS, Apple/Google : non rejouables sans émulateur/Xcode ; C-105 (instrument réécrit) : spec non rejouée par moi.
- Pic de requêtes `uberView` en durée réelle (ADV2-007 compté par lecture, pas mesuré).
- Tests lancés : `tests/Feature/UberDirect` 37/37 (127 assertions), `tests/Feature/Delivery` 159/159 (679), vitest `posTourneesUberDirect` 10/10.
