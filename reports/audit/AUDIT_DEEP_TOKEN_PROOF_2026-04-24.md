# Audit profond + preuve de consommation tokens — 2026-04-24

## Synthèse

- **Modèle retenu (meilleur essai)**: `gpt-5.5`
- **usage API**: non renvoyé en stream (fréquent) ; longueur texte: 16054 car.

## Paramètres de la preuve

- `CODEX_REASONING_EFFORT` effectif: **xhigh** (corps: `reasoning.effort` mappé xhigh→high si besoin).
- Taille **contexte** injecté: **29260** car.
- Taille **message utilisateur** total: **30091** car. (contexte + consigne d’audit).
- `max_completion_tokens` demandé: **48000**.
- Fil: **`/v1/chat/completions` en stream: true** (recommandé pour générations longues ; évite souvent 504 sur one-shot lourd).

## Détails des essais (machine)

### `gpt-5.5`
- HTTP: 200 | 175167 ms | chars réponse: 16054
- extrait (4k) :

```
## 1. Synthèse exécutive

Le dépôt FoodKing correspond à un monolithe Laravel 9 avec SPA Vue 3 couvrant plusieurs surfaces critiques : administration, POS caisse, KDS cuisine, écran client, kiosk web, et intégrations temps réel. Le choix monolithique reste cohérent pour un SaaS métier restaurant si les frontières de domaine sont explicites et si les services centraux, notamment commande, paiement, pricing, synchronisation et fiscalité, restent protégés contre les modifications opportunistes. Le risque principal n’est pas la stack elle-même, mais la combinaison de flux métier fortement couplés : une action POS peut impacter cuisine, borne, stock, paiement, reporting, notifications et conformité. La recommandation globale est de conserver le monolithe, mais de renforcer les invariants par tests sentinelles, documentation vivante, événements domaine après commit et observabilité.

## 2. Architecture backend Laravel

L’architecture backend repose sur Laravel 9, Sanctum, Spatie Permission, MySQL et des services applicatifs. Les contrôleurs semblent suivre un modèle classique contrôleur → service → ressource, ce qui est sain si les règles métier ne restent pas dans les contrôleurs. Le risque est la dilution progressive de la logique dans des services trop larges, notamment autour des commandes, paiements, statuts cuisine et utilisateurs multi-branches. Les zones critiques doivent être traitées comme des domaines : OrderService, PaymentService, PricingService, synchronisation et fiscalité. Les mutations importantes doivent passer par des méthodes explicites, transactionnelles, testées, et idéalement produire des événements domaine après commit. Toute écriture directe sur les modèles critiques doit être suspecte et auditée.

## 3. Architecture frontend Vue 3

Le frontend Vue 3 couvre plusieurs applications métier dans une SPA : admin, POS, KDS, OSS et kiosk. Cette richesse impose une discipline forte sur les stores, routes, composants partagés et appels API. Le risque majeur est la duplication de logique métier côté client, notamment le calcul de prix, les statuts de commande, les disponibilités, les remises et la composition d’articles. Le frontend doit rester une couche d’interaction, pas une source d’autorité. Les composants kiosk et POS peuvent avoir des besoins UX très différents, mais doivent consommer les mêmes contrats API et les mêmes snapshots métier. Les recommandations prioritaires sont : contrats typés documentés, tests Vitest sur flux critiques, séparation entre présentation et orchestration, et fallback réseau explicite.

## 4. Authentification, rôles et permissions

L’usage de Sanctum et Spatie Permission est adapté à une application SaaS Laravel. La vigilance doit porter sur la séparation entre authentification, autorisation globale, autorisation par branche et autorisation par ressource. Une permission comme `administrators_show` ne suffit pas si l’utilisateur peut accéder à des données d’une autre branche ou d’un autre tenant. Chaque endpoint sensible doit combiner permission fonctionnelle et filtre de périmètre métier. Les contrôleurs admin doivent éviter de recevoir un modèle injecté implicitement sans vérifier son appartenance au tenant courant. Les politiques Laravel ou gates dédiés peuvent centraliser cette logique. Le risque P0 est la fuite inter-branches : elle doit être couverte par des tests Feature systématiques.

## 5. Isolation tenants et branches

FoodKing est un SaaS multi-branches, ce qui rend l’isolation des données fondamentale. Les canaux privés de broadcasting par branche sont une bonne direction, mais ils doivent être cohérents avec les requêtes SQL, les API REST, les notifications FCM et les écrans temps réel. L’invariant central est simple : un utilisateur, un POS, une borne ou un KDS ne doit jamais voir, modifier ou recevoir un événement d’une branche non autorisée. Les modèles critiques devraient contenir des clés de branche ou de tenant vérifiées par scopes ou services. Les endpoints doivent
```


## Pourquoi les « 10 tokens » auparavant

- Un **smoke** du type `Reply with OK` est volontairement **minimal** côté complétion (tâche triviale — le modèle s’arrête tôt, peu de tokens de sortie).
- Ici, le scénario impose **gros contexte (prompt)** + consigne d’**audit long** : la consommation **prompt** + **complétion** est censée monter (vérif. tableau *usage* si le proxy le remplit).

## Artifacts

- JSON: `reports/audit/DEEP_AUDIT_PROBE_2026-04-24T11-23-34-310Z.json`
