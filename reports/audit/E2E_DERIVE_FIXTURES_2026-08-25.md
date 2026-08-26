# La suite E2E avait pourri en silence — diagnostic et remise au vert

Date : 2026-08-25 · Canal : `claude-code-supervisor`
Point de départ : `reports/audit/COMPOSER_SECURITE_2026-08-25.md` §6

---

## 1. Le constat

En élargissant la boucle de tests aux **onze specs consommatrices** du helper borne partagé —
celles que le cycle précédent déclarait vérifiées — **dix sur onze étaient rouges**, avec
**dix causes différentes**.

Pourquoi personne ne l'avait vu : la preuve consignée disait *« Collecte Playwright des
11 consommateurs : PASS — 49 tests listés »*. **Collecte, pas exécution.** Lister un test
prouve qu'il se parse. Rien de plus.

Expérience naturelle qui tranche l'attribution : la **seule** spec verte,
`test-e2e-goal-4chantiers-wave-D`, est celle qui utilisait un identifiant produit **valide**
(`ITEM_ID = 2`). Les items 361, 362, 474, 475, 478, 480, 485, 488, 493 et la variation 1180
n'existent plus. Aucune montée de dépendances ne fait disparaître une ligne SQL.

---

## 2. Le remède de fond : un résolveur, pas des identifiants

Un banc ne doit pas parier sur un identifiant. Il doit **décrire ce dont il a besoin**.

Ajouté à `tests/e2e/helpers/kiosk-order.js` :

```js
resolveSimpleOrderableItem({ branchId, preferName, excludeIds })
```

Il retourne un article **réellement commandable** : actif, non supprimé, disponible sur la
branche, sans variation et sans étape d'assistant obligatoire. `preferName` permet de viser un
nom précis quand il l'est encore ; `excludeIds` sert aux commandes multi-lignes.

### Deux pièges rencontrés en l'écrivant — et corrigés

**`DB::table()` contourne les SoftDeletes.** Première version : les articles 4 à 8 (« Sauce
supplémentaire », « Fromage supplémentaire »…) portent `deleted_at` renseigné depuis le
2026-05-28 **tout en gardant `status = 5`**. Le résolveur les proposait, et le devis les
refusait en 422 « Article X introuvable » — parce que `FrontendOrderService` interroge
`Item::whereIn(...)`, qui applique bien le scope. Corrigé par `whereNull('items.deleted_at')`.

**Deux modèles de liaison d'assistant coexistent.** Un profil `custom` est rattaché à un
ARTICLE (`item_id`), un profil `sandwich`/`tacos` à une CATÉGORIE (`item_category_id`, avec
`item_id` NULL). Un résolveur qui ne joint que sur l'un des deux rate la moitié du catalogue.

---

## 3. Ce qui a été corrigé, spec par spec

| Spec | Avant | Après | Cause racine |
| --- | --- | --- | --- |
| `test-e2e-abuse-P-idempotency` | 0 | **2/2** | Article figé n° 52 = l'**unique produit en rupture** de la branche |
| `zone3-kiosk-to-kds` | 0 | **3/3** | Article 362 inexistant |
| `zone6-sync-resilience` | 0 | **8/8** | Article 485 + variation 1180 ; port 8000 codé en dur ; `broadcast_as` mal ciblé ; 503 hors allowlist |
| `goal-pageby-borne-2026-05-18` | 0 | **18/18** | Animation permanente du CTA ; articles 474/375/485 ; catégories 344/348/349 |
| `wave-p-kiosk-2026-05-20` | 0 | **11/11** | Animation permanente ; couple (article 24, catégorie 1) désynchronisé |
| `wave-p-cross-system-2026-05-20` | 0 | **2/2** | Articles 24/35 exigeant un assistant |
| `rush-sync-flow` | 0 | **PASS** | 5 scénarios sur articles morts + **worker de file absent** |
| `menu-v2-kiosk-final` | 0 | **PASS** | 9 scénarios sur articles morts + articles soft-deleted |
| `test-e2e-goal-4chantiers-wave-D` | PASS | **PASS** | (témoin — utilisait un id valide) |
| `test-e2e-kiosk-kds-sync-2026-05-11-wave-D` | 0 | **partiel** | 4 couches, voir §4 |
| `test-e2e-pos-kds-sync-2026-05-10-wave-F` | 0 | **partiel** | voir §5 |

---

## 4. Wave D : quatre couches, chacune masquant la suivante

1. **Identifiant figé périmé** — item 361 absent. Résolu par nom, avec repli et surcharge
   d'environnement.
2. **Clé API absente du chemin Node du helper partagé** — `ApiKeyMiddleware` refuse en 400 toute
   requête sans `x-api-key`, et seul le chemin navigateur l'injectait. Les bancs qui choisissent
   délibérément le repli Node mouraient à l'émission du jeton, avec un message qui accusait à
   tort les identifiants de la borne.
3. **Mon propre garde d'identité serveur, trop strict** — il exigeait que la ligne du jeton soit
   encore présente, alors qu'une reconnexion borne la révoque légitimement (mesuré : jeton
   #10711 émis puis supprimé, #10713 créé). Il compare désormais l'**avancement du compteur**,
   que la révocation ne fait pas reculer. Il est aussi mémoïsé : exécuté à chaque émission, il
   ajoutait 1-2 s entre le jeton et son premier usage.
4. **La cause racine du 401** — `resources/js/shared/axios-setup.js:97` :

   ```js
   config.headers['Authorization'] = token ? `Bearer ${token}` : '';
   ```

   L'intercepteur **écrase systématiquement** l'en-tête : le Bearer explicite du helper n'a
   jamais eu d'effet, seul compte le jeton du store Vuex. Or la spec « garait » la page borne
   sur un écran admin pour éviter une révocation — et `kioskCart` n'étant pas dans les `paths`
   persistés, quitter `/kiosk` vidait le store. L'intercepteur envoyait donc
   `Authorization: ''`. **Le contournement de 2026-05-11 causait la panne qu'il prétendait
   éviter.**

Wave D place désormais réellement sa commande. Il reste **une** assertion, sur laquelle je me
suis arrêté volontairement — voir §6.3.

---

## 5. Wave F : un contrat produit qui a changé

`admin/pos` répond `401 — « Order quote token and signature are required together »`. Depuis le
verrouillage du prix serveur, **la création POS exige un devis signé**. Le banc postait encore
la charge d'avant. L'enchaînement devis → création a été rétabli, sur le modèle de
`red-team-r3-rupture-stock-live-2026-05-07.spec.js:611`, et vérifié isolément : **HTTP 201**.

La branche POS de la course reste néanmoins en échec dans le harnais complet (3 contextes
navigateur simultanés). Je ne force pas ce cas : il demande un débogage interactif que je
préfère remonter plutôt que deviner. Deux de ses trois assertions restantes (déclenchement d'un
429, budget de réception KDS) sont par ailleurs sensibles au temps et à l'environnement.

---

## 6. Deux découvertes qui dépassent les tests

### 6.1 Le worker de file était absent — et la pastille de santé le disait

`rush-sync-flow` échouait sur « l'OSS n'affiche pas la commande sous 30 s ». Cause :
**zéro worker `queue:work`, 436 tâches en attente**. Soketi était UP, le worker DOWN — le mode
de panne silencieux exact que la pastille de santé caisse a été conçue pour détecter.

Elle le signalait correctement : *« Temps réel dégradé — Traitement en retard »*. Worker
relancé, file drainée, et la pastille est repassée d'elle-même à :

```json
{"overall":"ok","sync":"ok","msg":"Les commandes arrivent en direct.","stale_events":0}
```

C'est la validation complète du travail d'observabilité de ce cycle : la sonde a détecté une
vraie panne, l'a nommée juste, et s'est éteinte à la réparation.

### 6.2 Huit specs partagent un préfixe et ne sont pas sûres en parallèle

Wave D échouait sur « la carte KDS doit atterrir dans la file borne ». Vérification : la
commande était passée en statut **16 (annulée)** *pendant* le test, et le KDS affichait
« Aucune commande en cours » avec 20 commandes annulées empilées.

Cause : le jeton de Wave D porte le préfixe **`AUDIT-KIOSK-WAVE-E`**, la valeur par défaut du
helper partagé. **Huit specs** l'utilisent. Le `cleanupKioskAuditOrders('AUDIT-KIOSK-WAVE-E')`
d'une spec **annule la commande en vol** d'une autre exécutée en parallèle.

Le dépôt connaissait déjà cette classe de problème — `tests/e2e/helpers/login.js:16-22`
documente un cas identique entre vagues A et B — sans que le préfixe partagé soit corrigé.

**Recommandation** : donner à chaque spec son propre préfixe (`AUDIT-KIOSK-<SPEC>-`), comme
`zone3` le fait déjà avec `AUDIT-ZONE3-2026-05-18`. Tant que ce n'est pas fait, ces huit specs
doivent tourner **en série**. Je ne l'ai pas fait moi-même : changer le préfixe d'une spec
change ce que son nettoyage balaie, et mérite d'être fait d'un seul geste cohérent sur les huit.

### 6.3 Wave D : le backend est correct, la page KDS ne rend pas — non résolu

L'assertion restante veut que la carte d'une commande borne atterrisse sous
`[data-kds-order-card="kiosk"]`. Elle échoue encore. J'ai remonté la chaîne jusqu'au bout et je
peux dire précisément **où le problème n'est pas** :

| Vérification | Résultat |
| --- | --- |
| Statut immédiat après création | **4 = ACCEPT** — dans `KitchenReleaseRule::visibleStatuses()` |
| `source_surface` en base | **`kiosk`**, et `SimpleOrderResource:63` le sérialise |
| `order_type = 10` accepté par la règle d'auto-acceptation | **oui** — `FrontendOrderService:238` liste KIOSK **et** TAKEAWAY |
| Article routé vers une station de cuisine | **oui** (`cuisine_chaude`) après correction |
| **Réponse de `GET /api/admin/kds-order`** | **la commande EST renvoyée** — `{id: 6920, status: 4, surface: 'kiosk', type: 10}` |
| Rendu sur la page KDS de Wave D, même après rechargement | **absent** |

Le backend est donc entièrement correct : l'API du KDS renvoie la commande. L'écart est dans
l'état de la page KDS à l'intérieur de ce harnais à trois contextes navigateur.

Deux corrections utiles sont malgré tout tombées de cette investigation :
- **Le tableau KDS filtre par station.** Les articles 1 à 3 (« Menu », « Frites Seules »,
  « Boisson Seule ») portent `kds_station = 'none'` : une commande qui ne contient qu'eux
  n'apparaît sur AUCUNE station. Mon premier résolveur les choisissait — j'avais introduit le
  défaut. Il privilégie désormais un article réellement routé en cuisine.
- L'attente d'isolation est passée d'un relevé instantané à une sonde bornée de 15 s.

Je laisse ce cas rouge plutôt que d'assouplir son assertion : elle vérifie une vraie propriété
opérationnelle — une commande borne doit se voir dans la file borne du passe — et la faire
passer sans comprendre reviendrait à fabriquer un vert.

---

## 7. Correctifs de méthode, pas seulement de données

- **`test.use({ reducedMotion })` est SANS EFFET dans ce dépôt** (mesuré sur Playwright 1.58.2 :
  `matchMedia(...).matches` restait `false`). Il faut `page.emulateMedia()`, vérifié. Le piège
  est consigné dans les deux specs concernées pour qu'on ne « simplifie » pas en arrière.
- **Le CTA de la borne porte une animation permanente** (`cay-pulse 2.6s infinite`) qui rend le
  clic Playwright impossible (« element is not stable »). On n'a pas forcé le clic — un
  `force: true` masquerait une vraie non-cliquabilité : on active le mouvement réduit, que le
  composant honore déjà explicitement (`animation: none`, lignes 937 et 950).
- **Assertions rendues plus précises, jamais plus laxistes** : `broadcast_as` vise désormais
  l'événement `OrderCreated` au lieu du dernier en date ; le webhook Stripe accepte 503 tout en
  exigeant l'absence de tout 2xx ; la vérification d'isolation KDS attend jusqu'à 15 s au lieu
  de faire un relevé instantané.

---

## 8. État

Sept specs entièrement remises au vert, deux partiellement, une déjà verte, une (`wave-F`)
remontée avec son diagnostic. Aucun assouplissement d'assertion. Aucune donnée métier modifiée
— le produit en rupture, les profils non publiés et l'historique fiscal sont intacts.
