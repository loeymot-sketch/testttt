# Intégration Uber Eats → Caisse + Écran cuisine (KDS)

> 2026-06-28. Structure de la phase 1 (raisonnement + design + ce que l'owner doit
> fournir). Le câblage réel (phase 2) démarre quand l'owner donne l'accès au compte
> Uber (voir §6).

## 0. Objectif owner (verbatim reformulé)
- Toutes les commandes Uber **s'affichent EN ORDRE** sur l'écran cuisine, avec les
  autres commandes.
- Chaque commande Uber est **bien marquée « UBER »** (proéminent, au-dessus).
- Deux lectures :
  - **(a) commande bien décrite** (texte lisible client : produits, options).
  - **(b) partie cuisinier SYMBOLIQUE** (symboles sauces / produits, comme déjà
    programmé : `G | SANDWICH | P | STO | SAM`).
- La commande Uber est **liée à la caisse** (encaissement déjà payé chez Uber) ET au
  **KDS**.

## 1. Décision d'architecture (canal vs fulfillment)
- `order_type` reste la **sémantique de préparation/emballage** : `DELIVERY` (Uber
  livre) ou `TAKEAWAY` (client retire) selon le `fulfillment_type` Uber
  (`DELIVERY` vs `PICK_UP`). → réutilise toute la logique existante (étiquettes
  ticket, KDS, NF525).
- Le **CANAL** Uber est porté par `source_surface = 'uber'` (et `source = 'uber_eats'`).
  C'est ce qui pilote la puce UBER. **Pas** besoin d'un nouvel `OrderType`.

✅ **DÉJÀ LIVRÉ (phase 1)** — affichage KDS : commit `0d51149a5`.
`resources/js/helpers/kdsSource.js` : `source_surface 'uber'/'uber_eats'` → puce
**UBER vert brand `#06C167` texte blanc**, distincte de tous les canaux natifs,
label i18n fr/en/ar. La carte KDS lit `KDS_SOURCE_THEME` + `KDS_SOURCE_I18N_KEYS`
→ zéro template touché. Test `tests/js/kdsSource.spec.js` (16, vert).

## 2. La clé : mapper Uber → `composition_snapshot` (réutilise TOUT)
Le ticket cuisine symbolique (b) ET le reçu imprimé lisent **uniquement**
`composition_snapshot` (`lines` / `extras` / `addons`). Donc **si on mappe les items
Uber dans notre `composition_snapshot`, la partie symbolique + l'impression
fonctionnent SANS code nouveau** (`KitchenTicketSymbolicFormatter` + `kdsSymbolic.js`).

Forme cible (stricte) :
```json
{
  "lines":  [ {"attribute_name":"Viande 1","variation_name":"Poulet Mariné"},
              {"attribute_name":"Sauce (1ère Gratuite)","variation_name":"Blanche"} ],
  "extras": [ {"extra_name":"Cheddar","line_total":0.90,"quantity":1},
              {"extra_name":"Salade","line_total":0} ],
  "addons": [ {"addon_name":"Menu (Frites + Boisson)","line_total":2.50,"role":"menu_drink"} ]
}
```
Routage des modifiers Uber (plats : titre de groupe + options choisies) :
- groupe contenant `viande / meat / sauce / pain / galette` → **`lines`**
  (`attribute_name` = titre du groupe, `variation_name` = option).
- garniture gratuite (Salade/Tomate/Oignon, prix 0) → **`extras` lt=0** (repliée dans
  le slot crudités `STO`).
- supplément payant → **`extras`** avec `line_total`.
- menu / boisson / formule → **`addons`** (`role: menu_*` pour le marqueur `MENU`/`F`).

### Mapping des noms Uber → catalogue Le Cayenne
Les IDs Uber ≠ `item.id` local. On résout **par nom** (comme le wireup web déjà fait),
via une table de correspondance configurable `config/uber_menu_map.php` (titre Uber →
nom catalogue + groupe d'attribut). Fallback : si un modifier n'est pas reconnu, on le
met en clair dans `instruction` pour que rien ne soit perdu en cuisine.

## 3. Création de la commande (lien caisse + release KDS)
- Uber **a déjà encaissé le client** → on crée l'`Order` avec
  `payment_status = PAID`, `status = ACCEPT`, `source_surface = 'uber'`.
- `KitchenReleaseRule` libère au board toute commande PAID → la commande Uber
  apparaît **en ordre** avec les autres, sans modification. L'event `OrderCreated`
  (outbox after-commit) déclenche le broadcast KDS.
- Numéro d'appel : on réutilise le `queue_number` (séquence locale) ; le code Uber
  (display id court, ex « A1B2 ») est stocké et affiché en sous-titre + sur le ticket.

### NF525 — prix scellés Uber (NE PAS recalculer)
- Les prix Uber peuvent différer du catalogue (commissions/majoration plateforme) →
  **bypasser `PricingService`** : sceller les prix Uber dans le snapshot + `total`.
- Encaissement = **Uber** (hors caisse). Décision owner : ces ventes **entrent-elles
  dans le Z fiscal local** ? Recommandation V1 : oui mais **moyen de paiement
  « UBER »** distinct (traçabilité), pas dans le tiroir espèces. → à confirmer (§5).

## 4. Ingestion (webhook idempotent) — À CONSTRUIRE phase 2
- Contrôleur webhook `POST /api/webhooks/uber` (n'existe pas encore).
- **Signature** : Uber signe le body en HMAC-SHA256 avec le `client_secret`
  (header `X-Uber-Signature`). Vérifier avant tout traitement → sinon 401.
- **Idempotence** : la table `webhook_events` est déjà multi-provider →
  `provider = 'uber_eats'`, `webhook_id = resource_id Uber`, `firstOrCreate` +
  contrainte UNIQUE = traitement unique même si Uber rejoue le webhook.
- Événements clés : `orders.notification` (nouvelle commande) → fetch détail via
  `GET /v2/eats/order/{order_id}` → map → crée l'Order. `orders.cancel` → annule.

## 5. Décisions owner à trancher (avant phase 2)
1. **Z fiscal** : les ventes Uber comptent-elles dans le Z local (avec moyen de
   paiement « UBER ») ou sont-elles purement hors-caisse (reporting séparé) ?
2. **Acceptation** : auto-accept à réception, ou le cuisinier accepte sur le KDS ?
3. **Impression auto** : ticket cuisine Uber imprimé automatiquement à réception
   (comme la borne) ou seulement affiché à l'écran ?
4. **Indisponibilités** : si un produit Uber est en rupture locale, on refuse la
   commande chez Uber (sync stock) ou on l'accepte quand même ?

## 6. CE QUE L'OWNER DOIT FOURNIR (accès compte Uber)
Tout se passe dans le **Uber Eats Developer Dashboard** (developer.uber.com), espace
**Eats Marketplace / Orders API**. L'owner (ou son compte resto Uber) doit :

1. **Créer une application** dans le dashboard développeur Uber et me transmettre :
   - `client_id`
   - `client_secret`  ← **secret, à m'envoyer de façon sécurisée** (pas par message
     en clair ; voir note ci-dessous).
2. **Le(s) Store UUID** du restaurant (identifiant Uber du point de vente) —
   visible dans Uber Eats Manager → Paramètres → infos de l'établissement, ou
   fourni par le support intégration.
3. **Activer le rôle « Integrator »** sur l'application + **activer l'intégration
   POS** sur le store (« Integration Activation » / « POS integration ») — souvent
   via une demande au support Uber Eats partenaires.
4. **Scopes OAuth2** à cocher sur l'application :
   `eats.store`, `eats.store.orders`, `eats.store.orders.read`, `eats.order`,
   `eats.report` (lecture commandes + gestion store).
5. **URL de webhook** à enregistrer côté Uber (je la fournis une fois l'endpoint
   déployé) : `https://<domaine-cloud>/api/webhooks/uber`.
6. (Optionnel selon flux) **Menu publié** côté Uber correspondant au catalogue, pour
   que la correspondance par nom (§2) soit fiable.

### Comment me transmettre le secret en sécurité
- **Ne colle pas** `client_secret` dans le chat en clair.
- Mets-le dans le `.env` du serveur cloud (variables `UBER_CLIENT_ID`,
  `UBER_CLIENT_SECRET`, `UBER_STORE_ID`, `UBER_WEBHOOK_SECRET`) — je lis depuis
  `config/services.php`, jamais hardcodé, jamais commité (cf. CLAUDE.md §3quater).
- Ou transmets-le via un gestionnaire de secrets / coffre, et dis-moi juste « c'est
  dans le .env ».

## 7. Plan d'exécution phase 2 (à mon go, après accès)
1. `config/services.php` + `.env` : creds Uber.
2. `UberOrderMapper` (service pur, testable) : payload Uber → `composition_snapshot`
   + lignes + total scellé. TDD.
3. `UberWebhookController` : signature HMAC + idempotence `webhook_events` + création
   Order (PAID/ACCEPT, source 'uber'). TDD + test de rejeu.
4. `UberClient` : OAuth2 client_credentials + fetch détail commande.
5. Vérif e2e : injecter un payload Uber réel → Order créé → puce UBER au KDS, ticket
   cuisine symbolique correct, lien caisse OK.
6. Brancher l'impression cuisine auto (réutilise `PrintKioskKitchenTicketOnOrderCreated`
   généralisé au canal Uber si owner le veut).
