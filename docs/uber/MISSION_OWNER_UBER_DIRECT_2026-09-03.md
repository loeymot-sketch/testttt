# Mission propriétaire — obtenir Uber Direct pour livrer nos commandes

> À faire par le propriétaire (Kossay), pas par un agent. Rédigé le 2026-09-03.
> Source technique : audit `reports/goal-audit-fidelite-web-uber-2026-09-03/wave-a/A5-uber-livraison.md`.

---

## 1. Le point le plus important : ce n'est PAS le même produit qu'aujourd'hui

Le restaurant possède déjà un compte commerçant Uber Eats, et le logiciel contient déjà du code Uber.
**Ce code ne sert pas à ce que tu veux faire.**

| | Ce qui est déjà là | Ce que tu demandes |
|---|---|---|
| Nom du produit | **Uber Eats Marketplace** (Order API) | **Uber Direct** |
| Sens | Uber t'envoie DES commandes passées sur son appli | Tu paies Uber pour livrer TES commandes |
| Qui a le client | Uber | Toi |
| Preuve dans le code | `config/uber.php:29-34` → `/v1/eats/orders/{id}`, `accept_pos_order`, `deny_pos_order` ; scopes `eats.store eats.order` (`config/uber.php:20-22`) | **0 ligne dans tout le dépôt** |

Conséquence pratique : **c'est un second dossier à ouvrir chez Uber**, distinct de celui en cours.
Ne laisse personne te dire « c'est déjà branché ».

---

## 2. Ce que tu demandes, mot pour mot

> « Je veux **Uber Direct**. J'ai déjà un compte commerçant Uber Eats pour la marketplace.
> Je veux en plus pouvoir **payer Uber pour livrer les commandes que MES clients passent
> sur MON site**, en débordement : quand je n'ai pas de livreur disponible ou que la
> production est saturée, je bascule la course sur un coursier Uber.
> Je veux l'**API** (devis tarifaire par adresse + création de course + suivi), pas seulement
> le tableau de bord. »

Cette formule est importante : « en débordement » explique ton volume irrégulier, et « l'API »
évite qu'on te réponde en te donnant un simple accès web manuel.

---

## 3. Le chemin d'accès (source : documentation officielle Uber, septembre 2026)

1. Aller sur **https://direct.uber.com** et se connecter avec le **compte Uber existant**
   (celui du restaurant — pas besoin d'en créer un autre).
2. Accepter les conditions, terminer la création du compte Uber Direct.
3. Dans le tableau de bord : onglet **Developer**, section **Management**. Tu y récupères
   **trois** valeurs :
   - `Customer ID`
   - `Client ID`
   - `Client Secret`
4. Ces trois valeurs sont les seules choses dont j'ai besoin pour brancher le logiciel.
   ⚠️ **Ne me les envoie jamais dans un message.** Tu les déposeras toi-même dans le fichier
   `.env` du serveur (je te donnerai les 3 noms de variables exacts le moment venu).

### Ce que l'API fournit, et pourquoi ça correspond exactement à ta demande

| Ton besoin | Le mécanisme Uber |
|---|---|
| « le prix doit être dynamique selon l'adresse » | `POST /v1/customers/{customer_id}/delivery_quotes` — tu envoies l'adresse, Uber renvoie le prix de la course |
| « le livreur Uber vient récupérer la commande » | `POST /v1/customers/{customer_id}/deliveries` — crée la course, un coursier est dépêché au restaurant |
| suivi de la course | webhooks Uber + lecture de la course |

Authentification : `POST https://auth.uber.com/oauth/v2/token`, `grant_type=client_credentials`,
**scope `eats.deliveries`** (différent des scopes `eats.store eats.order` qu'on utilise aujourd'hui).

---

## 4. Ce qu'il faut EXIGER par écrit avant de signer

À demander au gestionnaire de compte Uber, et à garder :

1. **La grille tarifaire par course** dans notre zone — c'est le chiffre qui décide si la
   bascule est rentable (cf. §6, l'alerte marge).
2. **Uber Direct est-il disponible à notre adresse ?** La couverture géographique n'est pas
   la même que celle de la marketplace. **À vérifier AVANT tout développement.**
3. **Une application développeur dédiée** avec `client_id` / `client_secret` **distincts** de
   ceux de la marketplace (ne jamais mélanger les deux intégrations).
4. **L'accès bac à sable (sandbox)** pour tester sans facturer de vraies courses.
5. **La documentation des endpoints** et le **format des webhooks de suivi**.
6. Le **délai de validation**. À savoir : le dossier marketplace en cours attend la validation
   « Basic Production » **depuis le 2026-08-02**, soit plus d'un mois
   (`docs/runbooks/UBER_GO_LIVE.md`, branche `fix/uber-order-fetch-v2`). Demande explicitement
   si le dossier Direct suit la même file d'attente, pour ne pas attendre deux fois.

---

## 5. Ce que je peux construire SANS attendre Uber

Rien de ce qui suit ne dépend de ton accès Uber. Tout est vérifié dans le code :

- **Le tarif par adresse existe déjà** : `DeliveryQuoteService.php:32-87` calcule la distance
  (haversine) et vérifie le polygone de zone, puis `DeliveryFeeService.php:26-56` applique
  `max(minimum, base + prix_au_km × km)`.
  ⚠️ Un agent adverse est en train de vérifier si ce code est **réellement branché** sur un
  parcours client ou s'il dort ; le résultat est attendu avant de s'appuyer dessus.
- **Un écran d'administration pour ces tarifs** : il n'existe pas, les colonnes sont peuplées
  en SQL à la main (`OrderSetupRequest.php:38` l'admet). C'est un manque à combler de toute façon.
- **L'interrupteur de bascule** : un seul point dans le code décide quel livreur prend une
  commande — `OrderService.php:3163 selectDeliveryBoy()`. On y ajoute un mode
  `in_house` / `uber` et une colonne `orders.delivery_provider`.
  **Effet immédiat, sans API** : tu bascules une commande sur « Uber », le logiciel la marque
  comme telle et cesse de l'attribuer à un livreur maison — toi tu commandes la course à la
  main dans l'appli Uber. Le jour où l'API arrive, on remplace le geste manuel par l'appel,
  sans rien changer d'autre.
- **Bonne nouvelle** : les statuts de commande existants encaissent déjà une course Uber
  (`PREPARED → OUT_FOR_DELIVERY → DELIVERED`, `OrderStateMachine.php:100-102`).
  **Aucune zone gelée à ouvrir.**

---

## 6. ⚠️ L'alerte à ne pas ignorer — la marge

Aujourd'hui le client paie des frais de livraison calculés par NOTRE barème.
Si tu bascules la course sur Uber sans rien changer, **tu encaisses ton tarif et tu paies
celui d'Uber** — la différence sort de ta poche, à chaque bascule, sans que rien ne l'affiche.

Il faut donc, dès la bascule manuelle : un **supplément « bascule Uber » paramétrable**, ou au
minimum un **compteur du coût réel** pour que tu voies ce que la bascule te coûte.
Ce point est une **décision commerciale — la tienne**, pas une décision technique.

---

## 7. Ce que je NE prétends pas savoir

- Le prix qu'Uber te facturera : je n'ai aucune source fiable, c'est négocié par compte.
- Si ton compte marketplace donne un accès accéléré à Direct : **à confirmer auprès d'Uber**.
- Les endpoints ci-dessus viennent de la documentation développeur publique d'Uber
  (septembre 2026). Ils devront être **reconfirmés contre la documentation que TON compte
  recevra** — c'est elle qui fait foi, pas ce document.
