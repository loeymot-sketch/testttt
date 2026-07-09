# V3 DEPTH — UBER GO-LIVE (profond) — « Uber est prêt / sûr » RÉFUTÉ

Cible : intégration Uber Eats (webhook + client + mapper + config). HEAD 61e9ea7b7.
Serveur LIVE 127.0.0.1:8766 (foodking_e2e). Uber PAS encore en prod (0 commande, Production Access en attente).
Posture : GREEN = hypothèse à réfuter. Tests existants = VERTS mais SUPERFICIELS.

## Verdict : BROKEN (go-live NON prêt). 5 findings confirmés + gaps checklist.

Les tests `UberIntegrationTest` couvrent OAuth-cache, signature 401, fail-closed, idempotence — mais
**avec un event NON-commande** (`stores.provisioned`) « pour isoler la signature ». Le chemin
`createFromUber` (création réelle, résolution item, dedup, rollback) N'EST JAMAIS testé bout-en-bout.
Le vert ne prouve rien du chemin critique.

---

## F1 — P1 — item non résolu → commande PAYÉE PERDUE (rollback silencieux + map VIDE)
Angle : 2 Failure-path / 8 Degradation.
- `UberOrderMapper::resolveItemId` peut retourner `null` (aucun match exact ni LIKE).
- `order_items.item_id` = `foreignId->constrained('items')` → **NOT NULL + FK** (vérifié LIVE :
  `SHOW COLUMNS order_items.item_id` → `Null: NO, Key: MUL`).
- `createFromUber` fait `DB::transaction`. Un `OrderItem` avec `item_id=null` → violation FK/NOT-NULL →
  exception → **toute la transaction rollback** → `catch` → 503 → Uber rejoue → après 5 tentatives → 200
  `error_gave_up`. **La commande Uber payée est DÉFINITIVEMENT perdue** (jamais en KDS/cuisine, client débité).
- Aggravant : `config/uber_menu_map.php` est **entièrement commenté (VIDE)** → 100 % des commandes
  dépendent du fallback nom-DB. Tout titre Uber sans correspondance (combos, boissons de marque,
  intitulés marketing/emoji) → null → perte.
- Repro : `UberWebhookController.php:146-157` + `UberOrderMapper.php:80-102` + migration
  `2022_11_17_110832_create_order_items_table.php:20` + LIVE `SHOW COLUMNS`.

## F2 — P2 — zéro-doublage NON garanti : pas d'UNIQUE sur `orders.transaction_id`
Angle : 4 Concurrency-idempotence / 10 Zero-doubling.
- Dedup commande = `Order::withoutGlobalScopes()->where('transaction_id','uber:'.$id)` (`:113`).
- LIVE : `SHOW INDEX FROM orders` où Column_name='transaction_id' → **`[]` (AUCUN index, même pas
  non-unique)**. Le seul UNIQUE(transaction_id) du repo est sur `pending_payment_confirmations`
  (table différente).
- Le commentaire `[UBER-DEDUP]` reconnaît lui-même que 2 events Uber (`event_id` distincts) pour la
  MÊME commande peuvent arriver (filtre `str_contains('order')` large). Ces 2 events passent chacun
  l'idempotence webhook (`webhook_id`=event_id distinct), appellent `createFromUber` en concurrence,
  font tous deux le SELECT (rien trouvé), puis 2 INSERT → **2 commandes internes = 2 tickets cuisine =
  double préparation**. Aucune contrainte DB ne rattrape la course (read-then-write non atomique).
- Réel : Uber envoie plusieurs notifications par commande ; combiné au 503-rejeu de F1 (503 → Uber
  rejoue pendant qu'une nouvelle notification arrive) la fenêtre est réaliste.
- Repro : `:110-116` + LIVE `SHOW INDEX FROM orders`.

## F3 — P2 — annulations Uber NON gérées → cuisine prépare une commande annulée
Angle : 1 Correctness / 2 Failure-path.
- Filtre : `if ($eventType !== '' && ! str_contains(strtolower($eventType),'order'))` → ack (`:73`).
  Les events d'annulation Uber (`orders.cancel`, `order.canceled`…) **contiennent 'order'** → NE sont
  PAS ack → tombent dans le chemin création.
- `createFromUber` sur un event cancel : dedup trouve la commande déjà créée → retourne l'id existant,
  **NE l'annule PAS** ; status reste `ACCEPT` + KDS. Si la commande n'existait pas encore → il la CRÉE.
- Résultat : client annule sur Uber → notre KDS l'affiche toujours → **cuisine la prépare** (gâchis +
  litige). Aucun `cancel/deny/refund` dans le controller (`grep` = 0 hit).
- Repro : `grep -ni cancel app/Http/Controllers/Webhook/UberWebhookController.php` → vide ; `:72-76`.

## F4 — P2 — config « pilotage » = NO-OP (fiscalize, deny_on_out_of_stock, store status)
Angle : 5 Data-NF525 / 9 UI-UX data-drift (config trompeuse).
- `config('uber.fiscalize')` n'est lu NULLE PART sauf un COMMENTAIRE (`grep fiscalize app/` → seul
  `UberWebhookController.php:141` en commentaire). Le fichier config AFFIRME « Si fiscalize=true, le
  cron/encaissement alloue un fiscal_sequence_no » → **FAUX** : aucun code ne le fait. `UBER_FISCALIZE=true`
  ne change RIEN. Une commande Uber PAID n'obtient JAMAIS de `fiscal_sequence_no` (le cron
  `RetryFiscalAllocCommand` exige `fiscal_alloc_error_at IS NOT NULL`, jamais posé pour Uber).
  → Si l'owner active fiscalize croyant intégrer Uber au Z NF525 : **silence, non-conformité invisible**.
- `denyOrder()`, `storeStatus()`, `config('uber.deny_on_out_of_stock')` : **jamais appelés** (`grep` = 0
  hit hors `UberClient.php`). Donc : pas de refus rupture stock, pas de synchro ouverture/fermeture store.
  Une commande d'un produit en rupture est acceptée et non préparable.
- Repro : greps ci-dessus + `RetryFiscalAllocCommand.php:70-73`.

## F5 — P2 — résolution LIKE → mauvais produit/prix silencieux (map vide)
Angle : 1 Correctness / 9 data-drift.
- Fallback `whereRaw('LOWER(name) LIKE ?', ['%'.title.'%'])->first()` (`:99`) : retourne le PREMIER
  match arbitraire. LIVE prouvé : titre `Menu Enfant` → exact NULL → LIKE → **`Menu Enfant Nuggets`
  (id 40)** même si le client a commandé Menu Enfant Burger. `%`/`_` du titre non échappés = wildcards.
- Map `by_title` VIDE → tout dépend de ce fallback. Résultat : `item_id`, prix unitaire snapshot et
  ticket cuisine peuvent référencer un AUTRE produit. (Pas d'injection SQL : bindings paramétrés.)
- Repro : LIVE `resolveItemId('Menu Enfant')` → 40/Menu Enfant Nuggets ; `UberOrderMapper.php:92-101`.

---

## (a) HMAC fail-closed — TENU (attesté)
- Secret vide → `signatureValid` retourne false → 401 (`:176-180`). Header absent → false. `hash_equals`
  timing-safe. Tests `webhook_refuse_si_aucun_secret_configure` + `signature_invalide` VERTS.
- Fallback `UBER_WEBHOOK_SECRET → UBER_CLIENT_SECRET` : **PAS une forge** (attaquant sans secret ne peut
  signer). MAIS risque go-live : si la clé de signature Uber ≠ client_secret, TOUTES les signatures
  échouent → 401 → 0 commande ingérée (panne silencieuse). À VÉRIFIER sur le dashboard Uber.
- Manque : la signature ne couvre pas de timestamp → un body signé capturé est rejouable ; sauvé
  UNIQUEMENT par l'idempotence event_id (webhook_events UNIQUE). OK mais fragile.

## (b) idempotence replay même webhook_id → 1 commande — TENU (partiel)
- `webhook_events` UNIQUE(provider,webhook_id) + check `status==processed` (`:54-57`) → rejeu du MÊME
  event_id = `already_processed` 200. Tenu. **MAIS** : distinct event_id / même commande = F2 (non couvert).

## (c) mapping Uber→composition_snapshot — PARTIEL / fragile
- Montants ÷100 corrects ; extras (modifier groups) mappés. MAIS : `lines=[]` (viande/sauce jamais
  structurées → tout en extras) ; total commande vs somme lignes non réconcilié (frais/livraison Uber) ;
  résolution item = F1/F5. Fidélité NON garantie sur produits composés.

## (d) chemin fiscal Uber — ÉCHAPPE au Z (by-design mais config MENTEUSE)
- `source_surface=uber_eats`, PAID, jamais d'encaissement → **jamais de `fiscal_sequence_no`**, jamais
  dans le Z. C'est le choix owner (canal séparé, Uber facture à part) = ACCEPTABLE en soi. MAIS la config
  documente un `fiscalize=true` opérationnel qui n'existe pas (F4) → piège de conformité.

---

## CE QUI MANQUE POUR GO-LIVE (checklist)
1. Résolution item robuste : remplir `uber_menu_map.by_title/by_uber_id` avec la VRAIE carte Uber +
   **refuser (deny_pos_order) la commande si un item ne résout pas** au lieu de rollback/perte (F1).
2. UNIQUE sur `orders.transaction_id` (ou upsert atomique) pour garantir zéro-doublage (F2).
3. Gestion des events `orders.cancel`/annulation → annuler la commande + retirer du KDS (F3).
4. Câbler `denyOrder`/`deny_on_out_of_stock` + `storeStatus` (rupture/horaires) ; retirer ou implémenter
   `fiscalize` (config ne doit pas mentir) (F4).
5. Durcir le fallback LIKE (exact-only, échapper `%`/`_`, sinon deny) (F5).
6. Vérifier sur le dashboard Uber la VRAIE clé de signature (a) + tester une commande réelle de bout en bout.
7. Monitoring `webhook_events provider=uber_eats status=failed` (déjà noté « à brancher » dans le code).
8. Tests d'intégration du chemin `createFromUber` (order event réel, item null, concurrence, cancel).
