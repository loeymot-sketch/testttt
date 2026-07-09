# V2 Révalidation adversariale — CIBLE : Uber Eats go-live readiness

HEAD `61e9ea7b7` + working-tree. Serveur LIVE 127.0.0.1:8766 (foodking_e2e). Posture : réfuter le « GREEN » v1.

Fichiers : `app/Http/Controllers/Webhook/UberWebhookController.php`, `app/Services/Uber/UberOrderMapper.php`,
`app/Services/Uber/UberClient.php`, `config/uber.php`, `config/uber_menu_map.php`, `routes/api.php:161`.

VERDICT : **BROKEN** — 1 P1 (perte silencieuse de commande Uber PAYÉE) + gaps go-live.

---

## HELD-GREEN (attaques échouées = robuste)

1. **HMAC fail-closed** — secret vide en env live → TOUT rejeté 401. Prouvé :
   - `POST /api/webhooks/uber` sans signature → `{"status":"invalid_signature"}` HTTP 401
   - même body + `X-Uber-Signature: deadbeef` → 401
   - `signatureValid()` : secret vide → `return false` (ligne 177-180) ; hash_equals à clé constante.
2. **Fallback WEBHOOK_SECRET→CLIENT_SECRET ≠ forge** — le client_secret reste secret (connu de nous + Uber
   seulement). Uber signe effectivement ses webhooks avec le client_secret en HMAC-SHA256. Schéma correct.
3. **503-retry (fix v1) confirmé** — sur échec de traitement, `attempts < 5` → 503 (Uber rejoue), pas de
   200-silent-loss ; give-up 200 seulement après 5 tentatives (ligne 94-98). Le fix v1 tient.
4. **Idempotence webhook (event_id)** — `UNIQUE(provider, webhook_id)` sur `webhook_events` (index
   `uk_webhook_provider_id` vérifié live) + court-circuit `status === 'processed'`.
5. **Mapping correct sur payload bien formé** — testé live : `payment.charges.total.amount 1980 → total 19.8`,
   `unit_price 790→7.9`, `total_price 1580→15.8`, extras modifier→snapshot, `queue_number U1234`. OK.

---

## FINDINGS

### [P1] Article Uber non résolu → item_id NULL → commande PAYÉE perdue en silence
**Angle 1/2/10 correctness/failure/zero-doubling.** `UberOrderMapper::resolveItemId` retourne `null` quand
le titre n'est ni dans `uber_menu_map.by_title` (VIDE — tout commenté) ni matché en base (exact ou LIKE).
`order_items.item_id` est **NOT NULL** (défaut NULL). Dans `createFromUber`, chaque ligne mappée est insérée
sans filtrer les `item_id` null → l'insert viole la contrainte NOT NULL → QueryException dans
`DB::transaction` → catch (ligne 85) → 503 x5 → 200 « error_gave_up ». **La commande Uber prépayée n'est
jamais créée** (ni caisse ni KDS ni cuisine). Le client a payé, la commande n'existe pas.

Repro live :
```
resolveItemId("Zzzznotexist","") => NULL        # titre non mappé
resolveItemId("","")            => 1            # (voir P2)
SHOW COLUMNS order_items item_id => Null=NO Default=NULL
config('uber_menu_map.by_title') => []          # map VIDE, tout dépend du fallback
```
Impact go-live : `by_title` étant VIDE, CHAQUE commande dépend d'un match de nom fragile. Les titres Uber
réels (« TACOS - Viande au choix », « Coca-Cola 33cl », combos, menus) ne matchent pas les noms DB → perte
de commande payée systématique. Un seul article non résolu dans le panier fait échouer TOUTE la commande.
**Blocker go-live.** Fix : remplir `by_title` avec la vraie carte Uber ET/OU skip+log les lignes non
résolues au lieu de tuer la commande (item_id « inconnu » placeholder + alerte), au lieu de crash+give-up 200.

### [P2] Titre vide / court → LIKE '%...%' renvoie le mauvais produit (prix Uber scellé)
**Angle 1 correctness.** `resolveItemId("")` → `LIKE '%%'` matche le PREMIER item → **item 1 = "Menu (Frites
+ Boisson)"**. Un titre court/générique matche largement le premier partiel. Le prix vient d'Uber (scellé),
donc le ticket/KDS/cuisine affiche un produit ARBITRAIRE au prix d'un autre. Repro live :
`resolveItemId("") => 1` ; `Item::find(1)->name => "Menu (Frites + Boisson)"`. Fix : garde-fou titre vide +
match LIKE ancré/pondéré, sinon null explicite (traité par le fix P1).

### [P2] `uber.fiscalize` est un no-op — aucun chemin NF525 pour les ventes Uber
**Angle 5 NF525.** `config('uber.fiscalize')` n'est référencé QUE dans un commentaire
(`UberWebhookController.php:141`). `grep` app/ : aucun code ne lit `uber.fiscalize` / `UBER_FISCALIZE` ni
n'alloue de `fiscal_sequence_no` pour une commande Uber. Le commentaire prétend « si fiscalize=true, le
cron/encaissement alloue un fiscal_sequence_no » — **ce cron n'existe pas** (`grep uber` dans Kernel/console
= 0). Les commandes Uber sont créées PAID+ACCEPT et ne passent JAMAIS par l'encaissement (modèle B) → jamais
de séquence fiscale, quel que soit le flag. Si l'owner active `UBER_FISCALIZE=true` pour intégrer les ventes
Uber au Z NF525, RIEN ne se passe → non-conformité silencieuse. Défaut false + « Uber facture à part » est
défendable, mais le flag ment. Fix : implémenter le chemin fiscal OU retirer le flag+commentaire trompeur.

### [P3] Dédup commande sur `transaction_id` sans index UNIQUE → double commande sous course
**Angle 4/10 concurrence/zero-doubling.** `createFromUber` dédup via `SELECT ... WHERE transaction_id =
'uber:'+id` puis INSERT, mais `orders.transaction_id` n'a **AUCUN index unique** (SHOW INDEX count=0). Le
filtre event-type est large (`str_contains(strtolower,'order')`) → plusieurs events Uber (event_id
distincts, même resource_id) passent à createFromUber. SELECT-then-INSERT non atomique + pas de contrainte DB
→ deux events concurrents = 2 commandes internes = doublage KDS/cuisine. Mono-poste V1 = risque faible
(livraisons webhook ~ séquentielles) mais réel pour go-live sous rejeu Uber. Fix : index UNIQUE sur
`transaction_id` (ou colonne dédiée `external_order_id`) + `insertOrIgnore`/catch.

### [P3] INSERT webhook_events hors try/catch → 500 sur double-fire concurrent
**Angle 2 failure-path.** L'insert `webhook_events` (ligne 59) est HORS du bloc try (qui démarre ligne 78).
Deux livraisons concurrentes du même `webhook_id` passent toutes deux le `if (! $event)` → la 2e viole
`UNIQUE(provider,webhook_id)` → QueryException non gérée → **500** (au lieu d'un 200/409 gracieux). Uber
retentera et le 2e tour trouvera la ligne, donc pas de perte, mais un 500 transitoire. Fix : `insertOrIgnore`
ou try/catch autour de l'insert.

### [P3] `denyOrder` / `storeStatus` / `deny_on_out_of_stock` définis mais jamais câblés
**Angle completeness go-live.** `UberClient::denyOrder` et `storeStatus` existent mais ne sont appelés nulle
part (grep app/ = définitions seules). `config uber.deny_on_out_of_stock` n'est lu par aucun code → refus
auto sur rupture = no-op. Seul `auto_accept` est câblé. Gap fonctionnel go-live (non bloquant : défaut =
accepter).

---

## GO-LIVE CHECKLIST (concret)
- [ ] **BLOQUANT** Remplir `config/uber_menu_map.php by_title` avec la vraie carte Uber + rendre le mapper
      tolérant aux lignes non résolues (skip+log+alerte, JAMAIS crash → give-up 200 qui perd la commande).
- [ ] Garde-fou titre vide dans `resolveItemId` (pas de LIKE '%%').
- [ ] Trancher NF525 Uber : implémenter le chemin `fiscalize` OU retirer flag+commentaire.
- [ ] Index UNIQUE `orders.transaction_id` (anti-doublage sous rejeu).
- [ ] `insertOrIgnore` sur webhook_events (anti-500 concurrent).
- [ ] Câbler deny/storeStatus si le refus-sur-rupture est voulu.
- [ ] Renseigner `UBER_CLIENT_SECRET`/`UBER_WEBHOOK_SECRET` en prod (actuellement vide → intégration inerte,
      401 sur tout — le fail-closed protège mais rien n'est ingéré).
