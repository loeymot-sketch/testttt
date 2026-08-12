# TASK_GLOB_OPS_PRINT_DELIVERY_001 — Impression : de l'intention à la preuve worker

## Meta

- **Priority:** P0 production cuisine
- **EXECUTION_TIER:** `complex`
- **PRIMARY_EXECUTION_MODEL:** `gpt-5.5-pro`
- **REASONING_EFFORT:** `xhigh`
- **TEST_STRATEGY:** `local-validation` + `playwright-critical-flow` + `human-verification`
- **SOURCE:** audit global F-05 et contre-audits impression/sync
- **STATUS:** `PENDING_PRINT_GATE_SCHEMA_AND_DIRTY_OWNER_RECONCILIATION`

## Problème prouvé

Le système confond aujourd'hui quatre faits distincts :

1. ticket éligible ;
2. ticket réclamé par un navigateur ;
3. octets mis en file dans un bridge ;
4. résultat réel du worker/spooler.

`kitchen_ticket_printed_at` est écrit au claim avant l'impression, sans lease ni propriétaire. Un crash ou onglet fermé bloque le ticket à vie. Le bridge caisse retourne 202 à l'enqueue et le frontend ACKe alors que le worker peut échouer ensuite. Le KDS conserve parallèlement une déduplication `localStorage`; la caisse et le KDS sont donc deux autorités indépendantes et peuvent perdre, doubler ou envoyer vers la mauvaise imprimante. Un admin `branch_id=0` peut en outre réclamer plusieurs branches.

## Décision d'architecture recommandée

Une seule file serveur, consommée par **une seule lease active par imprimante**, avec agent local principal ou secondaire enregistré par **branche + station + printer + device** :

`PENDING → LEASED → SPOOL_ACCEPTED | FAILED_BEFORE_SPOOL | UNKNOWN_AFTER_SUBMIT | DEAD_LETTER`

`kitchen_ticket_printed_at` ne peut plus être présenté comme preuve papier sur un simple ACK Winspool. S'il est conservé pour compatibilité, sa sémantique doit être renommée/documentée comme soumission spool ou n'être renseignée qu'après une preuve physique/confirmation humaine explicitement définie. Il n'est plus claim ou lease.

## Modèle minimal proposé

`print_jobs` :

- `id`, `branch_id`, `order_id`, `order_revision`, `document_type`, `target_station_id`, `printer_id`, claimant `device_id` nullable avant lease ;
- `state`, `lease_token_hash`, `lease_until`, `attempts`, `next_attempt_at` ;
- `idempotency_key` et unicité logique `(branch_id, order_id, order_revision, document_type, target_station_id, generation)` ;
- snapshot immuable des octets ou référence blob immuable, `payload_version`, checksum, `last_error_code`, `last_error_at` ;
- `spool_accepted_at`, `confirmed_physical_at` nullable, claimant device, `created_at`, `updated_at`.

Le payload ne contient aucun secret et est rendu depuis les données serveur. La réimpression manuelle crée une génération/attempt audité, elle ne réinitialise pas silencieusement le job initial.

## Scope étape A — confinement du chemin existant

1. `sendRaw() === false`, `null`, réponse malformée ou exception ne marque jamais imprimé.
2. HTTP 202 du bridge caisse signifie `queued`, jamais `delivered`.
3. Quand le transport échoue avant effet connu, le ticket redevient réclamable ; pas de claim permanent sans timeout explicite.
4. Les erreurs sont visibles et le reprint manuel est opérable.
5. Cette étape ne promet pas exactly-once et doit le dire dans l'UI/rapport.

## Scope étape B — autorité durable

1. Créer le job après commit de l'ordre, avec branche/station explicites et politique document/origine approuvée.
2. L'agent local tire uniquement les jobs de son identité enregistrée ; le rôle global de l'utilisateur n'élargit jamais son périmètre matériel. Un agent secondaire peut reprendre après expiration du heartbeat/lease, mais deux agents ne sont jamais actifs simultanément pour la même file imprimante.
3. Claim atomique avec lease expirante, token non réutilisable et heartbeat borné.
4. Le bridge attend le résultat réel du worker et retourne job ID + résultat normalisé ; enqueue seul ne suffit pas. Les commandes sont authentifiées/signées, bornées en taille, limitées à l'imprimante autorisée et protégées anti-replay.
5. ACK idempotent ; token expiré ou device/branche différent rejeté. Le worker vérifie écriture complète et fins page/document ; un simple `WritePrinter=true` ne suffit pas si les octets sont partiels ou les appels suivants échouent.
6. Retry avec backoff, tentatives maximales, dead-letter et alerte humaine. Un crash après spool mais avant ACK donne `UNKNOWN_AFTER_SUBMIT`; aucun retry automatique. La politique at-least-once ou anti-doublon est décidée par type de document, et tout reprint à risque porte la mention/audit « DUPLICATA ».
7. KDS et listener caisse consomment la même autorité ; supprimer la déduplication locale comme source de vérité.
8. Matrice explicite : ticket cuisine auto, ticket boissons/station, copie comptoir configurable, reçu client selon politique fiscale. Le job planifié est créé/released uniquement à l'événement métier correct après commit et après `release_at`.
9. Retry technique = même génération avec `attempts++`; reprint humain = nouvelle génération avec acteur/motif. Une correction de commande produit une génération/addendum, jamais la mutation du snapshot déjà soumis.
10. Le cutover désactive de façon coordonnée listener caisse, KDS/localStorage et listeners serveur consommateurs. Dual-write temporaire peut être audité, mais dual-consume est interdit et fail-closed.

## SUBSYSTEMS_OFF_LIMITS

- Aucun claim global quand `branch_id=0`.
- Aucun `printed_at` présenté comme preuve papier sur simple claim/ACK worker ; renommer la sémantique ou exiger une preuve définie.
- Aucun exactly-once affirmé à partir d'un browser/localStorage.
- Aucun double listener autoritaire.
- Aucun ticket client auto-imprimé sans décision de politique explicite.
- Aucun GO fondé sur mocks/202/absence d'exception.

## INVARIANTS_AT_RISK

- `branch_id` et identité device/station.
- Dispatch/job creation après commit.
- Schema/frozen printing/order lifecycle.
- Parité des sources POS/téléphone/web/kiosk/delivery.
- Données fiscales et politique reçu client.
- Fichiers impression actuellement dirty.

## Tests falsifiables

### Contrat logiciel

1. Deux agents tentent le même claim : un seul lease valide avec fencing.
2. Agent branche B ou admin global sans device B : aucun accès au job A.
3. Lease expirée avant dispatch : job redevient disponible et l'ancien token est rejeté. Après submit sans ACK, le job passe `UNKNOWN_AFTER_SUBMIT` et n'est jamais auto-repris.
4. Worker échoue avant spool : `FAILED_BEFORE_SPOOL/PENDING_RETRY`; échec ou réponse perdue après submit : `UNKNOWN_AFTER_SUBMIT`; jamais `DELIVERED` automatique.
5. ACK identique rejoué : idempotent ; ACK contradictoire : conflit/audit.
6. KDS + POS actifs : un seul consumer autorisé par station/génération ; le mode dual-consume fait échouer le cutover.
7. Crash navigateur/agent avant résultat, après papier avant ACK, après ACK : état explicite et doublon borné/audité.
8. Toutes sources `pos/phone/web/kiosk/delivery` : job et cible dérivés de la politique serveur, pas de chaîne magique frontend.
9. Deux branches, deux imprimantes de même nom : aucune fuite/mauvaise station.
10. Reprint manuel : nouveau attempt/génération avec acteur/motif, sans altérer la preuve initiale.
11. Agent principal arrêté/veille : l'agent secondaire ne reprend qu'après expiry ; aucun overlap et heartbeat visible.
12. Crash après spool avant ACK : `UNKNOWN_AFTER_SUBMIT`; décision opérateur explicite et duplicata audité.
13. `WritePrinter=true` mais octets partiels ou `EndDocPrinter=false` : jamais `SPOOL_ACCEPTED`.
14. Commande modifiée après création : snapshot/checksum initial immuable ; nouvelle génération/addendum.

### Chaos terrain

- Papier vide, capot ouvert, spooler figé, mauvais nom imprimante, bridge arrêté, réseau coupé puis restauré.
- Redémarrage agent et serveur pendant chaque phase.
- Vrai ticket web et vrai ticket borne ; vérifier heure, ordre, station et absence de doublon.
- Impression boissons et cuisine sur leurs cibles respectives.

## Acceptance Criteria

- [ ] Aucun ticket ne peut rester bloqué définitivement par un claim navigateur.
- [ ] `SPOOL_ACCEPTED` exige résultat worker complet ; aucun état ne prétend la sortie papier sans télémétrie/confirmation définie.
- [ ] Un device ne voit que sa branche/station, même avec utilisateur global.
- [ ] POS et KDS ne possèdent plus deux domaines d'idempotence concurrents.
- [ ] Les échecs/retries/dead letters sont visibles et actionnables.
- [ ] Les commandes web impriment automatiquement selon la même autorité que les autres origines.
- [ ] La grille imprimante réelle est remplie et signée.

## Gate et collisions

- Étape A : `HG-GLOBAL-OPS-RELIABILITY-2026-08-11` Décisions 1 et 3, plus réservation des fichiers dirty.
- Étape B : gates `PRINT-AUTHORITY`, `PRINT-DELIVERY-SEMANTICS`, schema et matériel explicitement signés.
- Toute modification de `KitchenTicketPrintListener.vue`, bridges, renderers ou services déjà dirty s'arrête jusqu'à identification/réconciliation du propriétaire.
- Le gate matériel `HG-HARDWARE-LAB-SIGNOFF` ne peut pas être remplacé par la validation logicielle.
