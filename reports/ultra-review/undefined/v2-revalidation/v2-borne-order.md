# V2 Revalidation — CIBLE: BORNE (création commande + preview SSOT + Plan B)

HEAD `61e9ea7b7` + working-tree. Serveur LIVE `127.0.0.1:8766` (DB `foodking_e2e`).
Posture: réfuter le « GREEN ». Token machine réel minté (User 7 → KioskMachine #2, branch 1,
`kiosk:order`). Token guest sans machine minté (User 2, actif) pour tests d'abus.

## VERDICT: GREEN_HELD (1 défaut mineur P3)

Le cœur SSOT / signature quote / idempotence / NF525 Plan B a résisté à toutes les attaques.
Un seul écart réel trouvé: divergence de plafond de quantité entre `/pricing/preview` (max 20)
et `/order` + `/order/quote` (aucun plafond). Non bloquant sur mono-poste Plan B.

---

## ATTAQUES EXÉCUTÉES (angle → commande → résultat)

### Angle 1 — Correctness / SSOT (HELD)
- Commande Coca (id52) avec `subtotal:0.01, total:0.01, discount:99` + quote valide →
  **HTTP 201, serveur recalcule total=1.90, subtotal=1.90, discount=0** (order #5406). Totaux client ignorés.
- `/pricing/preview` avec `total:0.01, subtotal:0.01, discount:999, price:0` injectés →
  data serveur `subtotal=3.8, tax=0.35, total=3.8` (2×Coca). Aucun champ prix client accepté.

### Angle 2 — Failure-path (HELD, 0× HTTP 500)
- Tacos M (26) sans variations (compo incomplète) → **422** « Sélectionnez au moins 1 Viande 1 ».
- item_id 999999 inexistant → **422** « Article introuvable ».
- quantity -5 → **422** ; items JSON malformé → **422** ; items `[]` → **422** ; instruction 600 car → **422** (cap 500).
- Toutes les entrées hostiles renvoient 422, jamais 500.

### Angle 3 — Security-abuse (HELD)
- Signature forgée (64 zéros) + token quote réel → **401** « signature mismatch » (`hash_equals`).
- quote_token uuid inexistant → **401** « Invalid order quote ».
- Token guest (kiosk:order SANS machine) `order_type=25 (KIOSK)` → **422** « Le service borne nécessite une machine enregistrée ».
- Guest `/order/quote` → **403** ; guest `/pricing/preview` → **503** `KIOSK_MACHINE_NOT_FOUND`.
- Token machine sans quote_token/signature → **422** (required).

### Angle 4 — Concurrency / Idempotence (HELD)
- Double POST concurrent, MÊME `X-Idempotency-Key` (item 58) → **les 2 renvoient order #5410** (0 doublon).
- Replay d'un quote DÉJÀ consommé avec NOUVELLE idempotency-key → **409** « Order quote has already been consumed ».
  Vérifié DB: **aucun order orphelin** créé (transaction rollback propre malgré création order avant seal).
- Tamper items après quote (ajout 2e article) → **401** « intent mismatch ».

### Angle 5 — Data / NF525 (HELD)
- Orders Plan B #5406/#5410/#5420: `fiscal_sequence_no=NULL`, `payment_status=10`, `status=1` (pas de séquence
  fiscale tant que non encaissé = correct, model B owner).
- `composition_snapshot` figé à la création (schema_version 1, captured_at présent).
- Quote expiré (time-travel +10min via `OrderQuoteService::sealForCommit`) → **HTTP 410** « Order quote expired ».

### Angle 6/10 — Intersection / Zero-doubling (HELD)
- Double-POST idempotent = 1 seule ligne order (#5410, 1 item). 409-replay = 0 orphelin.
- Chaque commande créée = 1 order + 1 order_item (vérifié DB `orderItems()->count()==1`).

### Angle 7 — Reproducibility
- Chaque attaque relancée ≥2× (quote régénéré à chaque essai), résultats déterministes.

---

## DÉFAUT TROUVÉ

### [P3] Divergence plafond quantité: preview cap 20, order/quote sans plafond
- `app/Http/Requests/Kiosk/PricingPreviewRequest.php:47` → `items.*.quantity max:{config kiosk.max_item_qty=20}`.
- `app/Rules/ValidJsonOrder.php:66` → quantity seulement `is_numeric && >0`, **aucun max** (items cap = 50 lignes seulement).
- Repro LIVE:
  - `/pricing/preview` quantity 1e12 → **422** « must not be greater than 20 ».
  - `/order/quote` quantity 999999999 → **200** (subtotal 1 899 999 998.10).
  - `/order` quantity 21 → **201**, order #5420 créé, ligne quantity=21, total 39.90.
- Impact: le preview applique une règle métier (max 20/ligne) que le chemin de création NE fait PAS respecter.
  Un client bypassant le preview (wizard frozen, device legacy, replay) peut créer des commandes à quantité
  arbitraire. Pas d'overflow DB (`orders.total decimal(19,6)`), pas de 500. Sur mono-poste Plan B (non payé,
  encaissement caisse manuel) le caissier filtre → sévérité faible. Recommandation: aligner ValidJsonOrder sur
  `config('kiosk.max_item_qty')` pour parité preview↔commit.

---

## HELD-GREEN (attesté)
SSOT prix backend · signature/intent HMAC quote · idempotence double-POST · anti-replay quote consommé (409, 0 orphelin)
· quote expiré 410 · guest-sans-machine rejeté (KIOSK 422 / quote 403 / preview 503) · compo incomplète 422
· entrées hostiles 422 (0×500) · NF525 Plan B fiscal null jusqu'à encaissement · snapshot figé.
