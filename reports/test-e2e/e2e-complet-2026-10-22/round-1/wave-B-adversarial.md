# Vague B — contre-vérification adverse (caisse POS + aval cuisine) — tour 1

Superviseur adverse. Aucun fichier produit modifié, aucune écriture en base (SELECT via tinker, `fiscal:verify-chain --all`, lectures de code). Sortie structurée : `wave-B-findings.json` (24 constats : 0 P0, 3 P1, 13 P2, 8 P3 ; tous `open`).

**Verdict : AMBER** — calculé sur les seuls P0/P1 `open` de disposition `fix` : 0 P0, **3 P1** (B-001, B-002, B-003), tous dans le CODE NEUF Uber (2026-10-22), aucun dans la surface déjà capturée. Rien de fiscal ni de sécurité de branche n'atteint le P0.

## 1. Regardé

PNG relus avec l'outil Read (20) : 16-caisse 1280/1440/1920, 24, 25-1280, 25-1920, 05, 09, 22, 01, 14, 30, 32, 04, 06, 31, 11, 21, 02, 15 ; DOM/console/network jumeaux ouverts pour 04, 16, 17, 25. L'équipe en avait relu 11 sur 31.

Ce que les captures montrent et que le rapport équipe ne dit pas (ou dit à moitié) :
- **16-caisse-1280** : le toast « Article ajouté au panier » recouvre le sélecteur À emporter/Livraison et la rangée « Livraison active / Tournées » est hors champ ; la bannière rouge « Article indisponible : Coca-Cola 33cl » est coupée par le ticket. Le test `elementFromPoint` « 6/6 cibles OK » est vrai (le panneau défile) mais ne dit pas que, sans défilement, la rangée n'est pas visible à 1280 et 1440.
- **16-caisse-1440 / 22** : la file « À encaisser » affiche « Départ prévu jeu. 22/10 à 23:30 » à 01:4x le 23/10 (B-010 : même défaut que le B-003 équipe, sur une 2ᵉ surface).
- **25-planche-1280 / 1920** : modale défilée, **en-tête et « Fermer » disparus** (B-009). Le bandeau d'erreur est dans le même conteneur défilant que les boutons Uber.
- **09** : le toast de refus de remise est coupé à droite et superposé au nom du caissier (B-011).
- **05** : ticket en HT sous l'en-tête « Prix », « VAT » en anglais, « Opérateur: Client passage » (connus : M3-007, M3-010, M4-006).
- **04** : pavé numérique avec **deux ⌫ et deux C** (DOM : 2 `--back`, 2 `--clear`) — nouveau, zone gelée (B-016).
- **31** : « Rapport X » = JSON brut en modale (B-013), nouveau.
- **11 (KDS)** : pastille « +46 en attente · 4 6 8 » recouvre l'en-tête des cartes, flèche ▶ recouvre la carte [E] — déjà au ledger (M1-001 / M6).
- **32** : « 405 affichées sur 506 » (voir B-014).
- Palette : orange #F4501E, mode clair, jaune/noir cohérents ; bouton Uber grisé blanc sur ~#999 = 2,8:1 (B-022, désactivé donc exempté WCAG mais bruit ×80). Aucune clé i18n brute vue. Toast = `role=alert` (ok).

Technique relu : `fiscal:verify-chain --all` = CHAIN OK 7 branches (conforme). Mais `fiscal:verify-sequence-continuity` = 50 numéros manquants sur 1..3147 (2506–2781, historiques) et 4 commandes « payées et jamais promues » : **« CHAIN OK » n'atteste pas le gap-free** (B-021, environnement). Z-28 : `cash_*` NULL comme tous les Z précédents (B-024, zone gelée, décision propriétaire).

## 2. Contre-vérification des classements de l'équipe

**(a) M4-02 / M4-04 « instrument » — CONFIRMÉ.** `tests/e2e/__screenshots__/verif-globale-M4/_etat.json` : `SLOT = 2026-10-23 00:30:00`. La spec a franchi minuit : à 01:4x ce créneau est dans le passé, donc `wave < earliestWave` => « trop proche » est la réponse correcte (`DeliveryWaveService.php:269-271`), évaluée avant « complet » (:285) ; pour un client réel qui choisit un créneau proposé par `availableWaves` (lui-même ≥ earliestWave), un créneau parti donne bien « complet ». Pas de mauvais code d'erreur au client. M4-02 : le créneau est plein 6/6 des commandes de la spec elle-même, le test s'invalide.

**(b) M3-05 / M3-10 « instrument » — PARTIELLEMENT RÉFUTÉ (B-014).** Le plafond de 300 trie `order_datetime` croissant puis `limit` (`PosOrderController.php:405-407`) : il coupe les commandes les plus RÉCENTES. Annoncer « 405 affichées sur 506 » ne dit pas lesquelles manquent. Le test rouge est un artefact de base saturée, mais le tri est un vrai défaut dès que le plafond mord (et il mord avec les « payer au comptoir non venues », purge en attente propriétaire).

**(c) « connu » — vérifié contre `verif-globale-2026-10-21` (FINDINGS_LEDGER, RAPPORT_FINAL, wave-*.json) :** M3-02 (M3-004/M3-006) OK ; plafond 24 cartes KDS OK (M1-001/M6-005, décision propriétaire) ; ticket Opérateur/12:16 AM/VAT OK (M3-007, M3-010, M4-006) ; « Coca-Cola ... » OK (M3-105). **Étiquetés connus mais ABSENTS du ledger** : rien ; **nouveaux non signalés comme tels** : B-010 (2ᵉ surface), B-011 (durée des toasts : 0 entrée), B-013, B-014, B-016, B-021, B-023, B-024. Les constats équipe B-001 à B-005 sont bien nouveaux au ledger ; l'équipe B-001 (bloc Remise) est repris en B-012 de disposition `owner_gate` (kill-switch remise 2026-07-18). Correspondance équipe -> adversaire : B-001→B-012, B-002→B-011, B-003→B-010, B-004→B-015, B-005→B-017.

**(d) 5 sessions de tiroir fermées par SQL — a laissé des traces (B-018).** Sessions 71-75 : `status=closed`, `expected_closing_amount`/`variance`/`reconciled_by` NULL ; 72-75 portent un mouvement 5,40 € mais `closing_amount=100,00` => 4 × 5,40 € de caisse non réconciliée. Seule la session 76 (UI) est `reconciled` écart 0,00 : le constat « tiroir = attendu = comptage » ne vaut que pour elle. La spec relancée a aussi créé six ventes espèces de 5,40 € (orders 8614, 8640, 8645, 8650, 8654, 8656) dont une seule est citée ; elles sont dans le Z-28. Pas de constat produit masqué, mais le chemin « clôture avec écart » n'a jamais été exercé.

**(e) B-004 `item/details/{1|3}` 404 — émetteur non identifié (B-015).** Appelants possibles dans `resources/js` : `ItemComponent.vue` (préchauffage au survol `:617-640` avalé, clic `:1011`, édition `:1109`), `PosComponent.vue:4099` (revue commande vocale), `AvailabilityTogglePanel.vue:281`. `pos-wizard.js:303` n'est qu'un intercepteur XHR : la sonde de l'équipe le désigne à tort. Aucun appelant « upsell » ; ids 1/3 en catégorie 27 inactive, la sonde a aussi vu 12 (catégorie 8 inactive). Protocole littéral = P0, abaissé P2 (requête avalée, sans effet écran) — décision au orchestrateur. Instrumenter `handleTilePrewarm`.

## 3. Code neuf Uber (priorité f) — failles trouvées

Lu en entier : `UberDirectDispatchService.php` (464 l.), `UberDirectClient.php`, `UberDirectWebhookController.php`, `PosDeliveryController.php` (uberView/Quote/Dispatch/Release), routes `pos-delivery/uber/*`, `DeliveryWaveService::assignCourier`, `PosDeliveryBoard.vue`, test `UberDirectCaisseSwapTest.php` (couvre : prix/devis, double appui, devis périmé, release avant sac, webhook delivered/bag/canceled avant sac, replay, signature ; **ne couvre pas** : annulation de commande, retour après sac, statut PREPARING, ordre d'événements, timeout après création, 2ᵉ branche).

| Id | Sév | Faille | file:line |
|---|---|---|---|
| B-001 | P1 | Annuler la commande d'un arrêt Uber supprime l'arrêt, la course Uber reste active et injoignable | `ReleaseDeliveryStopOnOrderClosed.php:28-34`, `AssignPendingDeliveries.php:26-29` |
| B-002 | P1 | `returned`/annulée après prise du sac : arrêt non rendu (`left_at` déjà posé), doc §6 fausse, message « livreur déjà parti » trompeur | `UberDirectDispatchService.php:323-329`, `:305-309` |
| B-003 | P1 | Création HTTP dans la transaction : course fantôme si réponse perdue ou écriture suivante échoue ; message « reste à nos livreurs » faux | `:161-213`, `UberDirectClient.php:222-225` |
| B-004 | P2 | Pas d'ordre des statuts (rejeu/retard régresse `pickup_complete`→`pickup`), `release()` sans lecture vive | `:244-258`, `:217-239` |
| B-005 | P2 | `moveOrder` no-op silencieux (PREPARING/ACCEPT) ; ligne terminale jamais retentée ; commande bloquée | `:332-343`, `:249-251`, `:291` |
| B-006 | P2 | `board` = jusqu'à 25 appels Uber synchrones sous verrou 15 s, toutes les 20 s | `PosDeliveryController.php:176-179`, `:280-301` |
| B-007 | P2 | La planche montre le montant stocké (périmé), le blocage Uber lit le vif | `PosDeliveryController.php:200`, `:94` |
| B-008 | P3 | « Aucun livreur » si le 1ᵉʳ arrêt est Uber | `PosDeliveryController.php:195` |

**Réfuté / non trouvé :**
- **Fuite de branche** : `DeliveryStop` porte `BranchScope` (`DeliveryStop.php:48`) ; `stopOf()` (`findOrFail` scopé) donne 404 à un caissier d'une autre branche ; les `withoutGlobalScope` du service partent d'un id déjà scopé. Prédiction par lecture, non exécutée. Seul `syncActive` agit toutes branches (inoffensif). `assignCourier` accepte tout livreur de toute branche (`User` non scopé, connu CLAUDE.md §9, V2).
- **Webhook rejouable** : HMAC corps brut, fail-closed, `hash_equals`, replay sans effet sur états terminaux (testé). Reste le rejeu d'états non terminaux (B-004).
- **Règle d'encaissement contournable** : `blockedReason` relit `payment_status` en direct à chaque devis/dispatch (`:94`), verrou de ligne anti-double. Pas de contournement trouvé AVANT dispatch ; les trous sont APRÈS (annulation B-001, remboursement).
- Permission `pos` seule suffit à engager une dépense Uber, sans plafond de frais : décision propriétaire possible, non classée faille.

**Tests PHPUnit à écrire (non écrits : la suite partage le MySQL local ; décrits dans le JSON, helpers `fakeUber()`, `dispatched()`, `webhook()`, `stop()` du test existant) :** B-001 annulation→POST `*/cancel`==1 ; B-002 dropoff puis returned→alerte/arrêt rendu ; B-003 échec d'écriture après création→course retrouvable ; B-004 pickup_complete puis pickup→statut inchangé ; B-005 stop PREPARING + delivered→commande DELIVERED ; B-007 payée après coup→`amount_due==0`.

## 4. Non vérifiable ici

- Comportement réel d'Uber (idempotence de `idempotency_key`, annulation après prise du sac, forme des webhooks) : aucun compte (`eats.deliveries` absent, docs/LIVRAISON.md §6) ; B-003/B-004 reposent sur la lecture du code.
- Le DOM du KDS au-delà de 24 cartes et l'impression (agents 9100/9101 absents) : hors instrument.
- Fonctionnement des scénarios Uber en navigateur réel : Uber éteint ; seule la spec simulée `uber-direct-2026-10-22` (3/3) les couvre, et elle ne contient aucun des 7 scénarios ci-dessus.
- Si `cash_*` NULL des Z est voulu (B-024).
- Aucun nouveau PNG pris (disque 2,6 Go).
