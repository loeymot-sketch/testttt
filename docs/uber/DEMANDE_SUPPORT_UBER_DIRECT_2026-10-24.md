# Uber Direct — demande d'activation du droit `eats.deliveries` (2026-10-24)

À envoyer par le **propriétaire du compte administrateur** de l'organisation « LE CAYENNE » à `uberdirect@uber.com`
(ou via « Centre d'aide » du tableau de bord direct.uber.com). Texte prêt à copier. **Ne jamais y joindre le secret client.**

## Ce qui est mesuré (reproductible, le 2026-10-23 et le 2026-10-24)

| Appel | Réponse |
|---|---|
| `POST https://auth.uber.com/oauth/v2/token` — `client_credentials`, `scope=eats.deliveries` | **400 `invalid_scope` — « scope(s) are invalid »** |
| même appel, `scope=direct.organizations` | 200 — jeton accordé, scope `direct.organizations` seul |
| `POST /v1/customers/{customer_id}/delivery_quotes` avec ce jeton | **401 — « This endpoint requires at least one of the following scopes: eats.deliveries »** |
| `GET /v1/direct/organizations/{customer_id}` avec ce jeton | 200 — organisation « LE CAYENNE », `MERCHANT_TYPE_RESTAURANT`, facturation `BILLING_STATUS_ACTIVE`, `BILLING_TYPE_DECENTRALIZED`, 437 Rue Élie Gruyelle 62110 Hénin-Beaumont |

Le contact enregistré sur l'organisation est une adresse e-mail différente de celle du compte connecté au tableau de bord
(`direct.uber.com` n'affiche, pour ce dernier, ni l'onglet « Développeur » ni « Nouvelle livraison » actif).

## Message (FR)

> Bonjour,
>
> Nous sommes le restaurant **Le Cayenne** (Hénin-Beaumont, 62110). Notre organisation Uber Direct existe et sa facturation est active.
> Nous avons créé l'application « LE CAYENNE's App » (Customer ID `45d8ec60-797c-4553-bc05-ebbe66626d2f`,
> Client ID `vTtDm-RbW7gklFMWyWxCZpsbJ6J_XiXl`), mais ses identifiants n'obtiennent **que** le scope `direct.organizations` :
> la demande de jeton avec `eats.deliveries` répond `400 invalid_scope`, et les devis (`/delivery_quotes`) répondent
> `401 … requires … eats.deliveries`.
>
> Pouvez-vous **activer l'API Uber Direct (scope `eats.deliveries`) sur cette application / ce compte**, confirmer que notre adresse
> (437 Rue Élie Gruyelle, 62110 Hénin-Beaumont) est dans une zone couverte, et nous indiquer : (1) le nom exact de l'en-tête de signature
> des webhooks et la procédure de création du webhook ; (2) l'accès « bac à sable » pour tester sans course réelle ; (3) la validité d'un
> devis et le comportement d'une même `idempotency_key` avec un autre `quote_id` ; (4) la valeur par défaut de `undeliverable_action`.
>
> Merci.

## Message (EN)

> Hello — we are the restaurant **Le Cayenne** (Hénin-Beaumont, France). Our Uber Direct organization exists and billing is active. The
> credentials of our app "LE CAYENNE's App" (customer `45d8ec60-797c-4553-bc05-ebbe66626d2f`, client `vTtDm-RbW7gklFMWyWxCZpsbJ6J_XiXl`) are only
> granted the `direct.organizations` scope: requesting `eats.deliveries` returns `400 invalid_scope`, and `/delivery_quotes` returns
> `401 … requires … eats.deliveries`. Please enable the Uber Direct API (`eats.deliveries`) for this app/account, confirm coverage for
> 437 Rue Élie Gruyelle, 62110 Hénin-Beaumont, and tell us the webhook signature header name, the sandbox access, the quote validity,
> the idempotency semantics with a different `quote_id`, and the default `undeliverable_action`.

## Dès qu'Uber a répondu (ce que je fais, dans l'ordre)

1. Rejouer le jeton `eats.deliveries` (une commande, sans rien écrire).
2. **Un devis réel** depuis la caisse (planche « Tournées » → « Confier à Uber ») sur une adresse de test : confronter les corps de
   requête et de réponse à ce que le faux Uber local suppose (`docs/uber/UBER_DIRECT_API_FAITS_VERIFIES_2026-09-06.md §4`).
3. Poser dans le `.env` **du serveur** (jamais commité) `UBER_DIRECT_ENABLED=true` + les identifiants + `UBER_DIRECT_WEBHOOK_SECRET`,
   créer le webhook dans le tableau de bord, puis une course réelle courte ; vérifier le journal.
4. **Régénérer le secret client** (il a transité deux fois dans une conversation) et le remplacer partout.
