# Uber Direct — ce qui est CONFIRMÉ et ce qui reste à confirmer

> Rédigé le 2026-09-06, avant toute écriture de code. Ce document sépare strictement ce que
> j'ai lu dans la documentation officielle Uber de ce que je devrai vérifier contre le
> compte réel. **Rien de la seconde colonne ne doit être codé « en dur et espéré ».**

---

## 1. Confirmé — authentification

| Élément | Valeur | Source |
| --- | --- | --- |
| Portail | `https://direct.uber.com` (connexion avec le compte Uber existant) | doc Get Started |
| Jeton | `POST https://auth.uber.com/oauth/v2/token` | doc Get Started |
| Type | `grant_type=client_credentials` | doc Get Started |
| **Scope** | **`eats.deliveries`** | doc Get Started |
| Identifiants | `Customer ID`, `Client ID`, `Client Secret` — onglet **Developer**, section **Management** du tableau de bord | doc Get Started |

⚠️ Le scope diffère de l'intégration existante du dépôt (`eats.store eats.order`, Marketplace).
Ce sont **deux produits distincts** : Marketplace = recevoir des commandes d'Uber ;
Direct = payer Uber pour livrer les nôtres.

## 2. Confirmé — points d'entrée

| Action | Méthode + chemin |
| --- | --- |
| Devis | `POST https://api.uber.com/v1/customers/{customer_id}/delivery_quotes` |
| Créer une course | `POST https://api.uber.com/v1/customers/{customer_id}/deliveries` |

Base : `https://api.uber.com`.

⚠️ Une page de la doc décrit `delivery_quotes` en **GET** et une autre en **POST**. À trancher
contre la documentation que recevra le compte — voir §5.

## 3. Confirmé — webhook

| Élément | Valeur |
| --- | --- |
| En-tête de signature | **`x-uber-signature`** |
| Algorithme | **SHA-256** du corps du message, avec la **Webhook Signing Key** |
| Clé | fournie à la création du webhook dans le tableau de bord (secret partagé) |
| Type d'événement | **`event.delivery_status`** — émis à chaque changement de statut **et** sur `courier_imminent` |
| Charge utile | champ `status` (nouveau statut) + champ `data` (données de course à jour) |

**Statuts de livraison officiels**, mot pour mot :
`pending` · `pickup` · `pickup_complete` · `dropoff` · `delivered` · `canceled` · `returned` ·
`shopping_completed`

⚠️ Conséquence de conception : le corps **BRUT** de la requête doit être conservé pour
vérifier la signature. Toute normalisation JSON avant vérification casse le contrôle.

## 4. Ce que la doc publique ne dit PAS — à confirmer sur le compte

Je n'ai **pas** trouvé de source officielle lisible pour :

1. le schéma exact du corps de `delivery_quotes` (`pickup_address`, `dropoff_address`,
   `pickup_ready_dt`, `pickup_deadline_dt`, `dropoff_ready_dt`, `dropoff_deadline_dt`) ;
2. le schéma exact de `deliveries` (`quote_id`, `manifest_items`, `external_id`,
   coordonnées de retrait/livraison) ;
3. **le mécanisme d'idempotence** côté Uber (en-tête dédié ? `external_id` suffisant ?) ;
4. les points d'entrée « lire une course » et « annuler une course » ;
5. la durée de validité d'un devis (le champ d'expiration existe, sa valeur varie) ;
6. GET ou POST pour les devis (cf. §2).

**Règle que je m'impose** : aucun de ces six points ne sera codé sur une supposition. Le
service sera écrit de façon à ce qu'ils soient **paramétrables et vérifiables**, et je les
confronterai à la documentation du compte avant tout appel réel.

## 5. À demander au compte Uber (côté propriétaire)

1. Ouvrir `https://direct.uber.com` avec le compte du restaurant, terminer la création.
2. Onglet **Developer** → relever `Customer ID`, `Client ID`, `Client Secret`.
   ⚠️ **Ne jamais me les envoyer dans un message** — ils iront dans le `.env` du serveur.
3. Créer le webhook et relever la **Webhook Signing Key**.
4. Demander l'**accès bac à sable** (tests sans course réelle ni facturation).
5. Demander la **documentation des endpoints** rattachée au compte — c'est elle qui fait foi,
   pas ce document.
6. Vérifier qu'**Uber Direct couvre l'adresse du restaurant** (Hénin-Beaumont) : la couverture
   diffère de celle de la marketplace. **À vérifier AVANT tout développement facturable.**

## 6. Sources

- https://developer.uber.com/docs/deliveries/get-started
- https://developer.uber.com/docs/deliveries/overview
- https://developer.uber.com/docs/deliveries/daas/references/api/webhooks/delivery-status-webhook
- https://merchants.uber.com/uber-direct.html
