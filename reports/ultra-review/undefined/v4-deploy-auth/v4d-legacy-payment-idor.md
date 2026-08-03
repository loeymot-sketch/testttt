# V4-DEPLOY Axe 2 — Surface: Legacy /payment/{order} IDOR + success forgé

Slug: v4d-legacy-payment-idor · HEAD 61e9ea7b7 · Live 127.0.0.1:8766 (foodking_e2e)

## Verdict: SAFE — 0 P0/P1/P2

## Routes (routes/web.php:47-53, prefix `payment`, middleware `installed` seul, PAS d'auth)
- GET/POST `/payment/{order}/pay` → index/payment
- GET/POST `/payment/{gateway:slug}/{order}/success|fail|cancel`
- GET `/payment/successful/{order}`

## Attaque 1 — surface entière disable (kill-switch code-owned)
Chaque méthode publique de `PaymentController` appelle `guardWebPaymentV1()`
(app/Http/Controllers/Frontend/PaymentController.php:131-136) qui fait
`abort(404)` si `config('payment.web_payment_v1.enabled') === false`.
- config/payment.php:14-19 → `enabled => false`, **code-owned** (aucun `env()`),
  commentaire: "enabling requires a new reviewed gate and explicit config change".
- Runtime confirmé tinker: `web_payment_v1.enabled=false`.
- Repro LIVE (curl): tous 404
  - `payment/1/pay` → 404
  - `payment/stripe/1/success` → 404
  - `payment/stripe/1/fail` → 404
  - `payment/successful/1` → 404
→ La totalité du flux legacy est injoignable en V1. Un env var ne peut PAS l'activer.

## Attaque 2 — IDOR {order} (shape présent mais neutralisé)
`{order}` = implicit binding par clé primaire `id` (Order.php n'override PAS
`getRouteKeyName`/`resolveRouteBinding`) → id séquentiel devinable. La forme IDOR
existe MAIS est bloquée en amont par le 404 (Attaque 1). Non exploitable.

## Attaque 3 — success forgé (bare GET → PAID) : RÉFUTÉ même si activé
Stripe.php:120-155 et Credit.php:67-96 `success()`:
- exigent `$request->token` présent, présent dans table `capture_payment_notifications`,
  ET `$order->id == $token->order_id`.
- Sans ce token (créé par le flux capture PSP), `$this->response` reste false →
  redirect `payment.fail`. Un simple `GET /success/{order}` NE marque PAS PAID.
- Ce n'est pas une signature HMAC PSP (faiblesse de conception théorique), mais un
  jeton lié à l'order_id — pas forgeable par un simple GET. Combiné au 404, non-atteignable.

## Conclusion
Surface morte en V1 LOCAL par flag code-owned + success token-gated. Aucun drain /
commande gratuite reproductible. Rien à surfacer owner.
