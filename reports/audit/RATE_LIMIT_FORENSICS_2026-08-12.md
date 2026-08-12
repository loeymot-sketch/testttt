# Forensique 429 — mesure navigateur, compteurs Redis et architecture de polling

**Date :** 2026-08-12  
**Environnement mesuré :** local, compte `Caissier Le Cayenne`, `branch_id=1`  
**Actions métier :** aucune ; navigation et lecture uniquement  
**Verdict :** le 429 est reproductible par construction sous le plafond production, même sans mutation opérateur

## 1. Résumé

Deux budgets différents sont consommés :

1. le bucket authentifié par `user_id`, utilisé par POS/KDS/dashboard/historique ;
2. le bucket anonyme par IP, utilisé notamment par `/api/frontend/csp-report` et les surfaces publiques/kiosk avant authentification.

Les deux sont soumis au middleware global `throttle:api`. La route CSP ajoute ensuite `throttle:1000,1`, mais un throttle interne ne peut pas élargir le plafond déjà consommé par le middleware externe.

### Mesures réelles

| Mesure | Résultat |
| --- | ---: |
| Rechargement d'une page POS | 37 POST CSP + assets |
| Ajout de tracker, dashboard, KDS, historique et encaissement au POS déjà ouvert | +50 tentatives API authentifiées |
| Six écrans connectés, environ 58 s sans action | 37 tentatives authentifiées |
| Limite locale actuelle | 1 000/min |
| Limite par défaut du code / cible production | 120/min |

La configuration locale à 1 000 masque le comportement attendu sous 120. À 120, six écrans connectés utilisent déjà environ 31 % du budget chaque minute, sans clic. Le chargement simultané consomme 50 appels supplémentaires.

## 2. Preuve du bucket global

`app/Http/Kernel.php` monte `throttle:api` sur toutes les routes API. `app/Providers/RouteServiceProvider.php:52-58` retourne :

```php
Limit::perMinute($perMinute)->by($request->user()?->id ?: $request->ip());
```

Le middleware Laravel transforme la clé en `md5('api'.$limit->key)`. Les compteurs ont été lus en Redis via `RateLimiter::attempts()` :

- avant l'ouverture multi-écrans, le POS connecté avait consommé 1 tentative dans la fenêtre active ;
- après ouverture des cinq écrans supplémentaires : 51, soit **+50** ;
- dans la fenêtre suivante, après environ 58 secondes idle : **37**.

Cette lecture ne repose pas sur une estimation de code ou un HAR incomplet : elle lit le compteur exact utilisé pour décider le 429.

## 3. P0 — Tempête CSP inutile et throttle trompeur

### 3.1 Le throttle 1 000/min n'élargit pas le global

`routes/api.php:1903-1922` documente un ancien incident : 14–17 rapports CSP au chargement saturaient l'ancien 20/min. La route a donc reçu `throttle:1000,1`.

Mais la route reste dans `routes/api.php`, derrière le groupe global `api` et son `throttle:api`. Sous la valeur par défaut 120, le chemin effectif est :

```text
throttle:api (120, user ou IP) → throttle:1000 (IP) → contrôleur
```

Le premier plafond gagne. La correction historique ne peut donc pas produire la propriété qu'annonce son commentaire.

### 3.2 Un rechargement émet aujourd'hui 37 rapports

Le journal du serveur PHP, borné par offset avant/après un reload POS, contient exactement :

```text
37 × 204 POST /api/frontend/csp-report
```

Une seule page consomme ainsi 30,8 % d'un bucket IP de 120. Quatre chargements dans la même minute suffisent théoriquement à dépasser 120, avant toute commande publique ou borne partageant la même IP NAT.

### 3.3 Les 37 rapports sont tous inutilisables

`storage/logs/observability-2026-08-12.log` enregistre chaque requête sous :

```text
csp_violation.malformed
```

Le contrôleur annonce supporter `Content-Type: application/csp-report`, mais lit uniquement :

```php
$request->input('csp-report')
```

Laravel ne garantit pas le décodage de ce media type comme un POST JSON classique ; les rapports natifs arrivent donc sans le shape attendu. Le Reporting API moderne peut également envoyer `application/reports+json` avec un tableau. Le contrôleur ne traite ni le raw body legacy ni le tableau moderne.

Effets combinés : consommation de requêtes, écritures disque en warning, aucune information sur la directive réellement violée et impossibilité de corriger la CSP à la source.

### Correction cible CSP

1. Parser explicitement le raw body pour `application/csp-report` et `application/reports+json` avec limite de taille.
2. Valider/sanitariser chaque rapport puis calculer un fingerprint sans PII.
3. Dédupliquer/agréger par fingerprint et fenêtre au lieu d'écrire un log par occurrence.
4. Donner à CSP un bucket réellement séparé du `api` global, ou le sortir du groupe qui applique ce plafond tout en conservant son limiteur dédié.
5. Corriger ensuite les directives/assets responsables jusqu'à zéro violation normale au chargement.
6. Tester derrière une IP NAT commune avec web, borne et admin simultanés.

Il ne faut pas simplement relever le plafond global : cela affaiblirait toutes les routes publiques partageant le bucket.

## 4. Budget authentifié multi-écrans

### Écrans mesurés

- POS ;
- suivi commandes ;
- dashboard ;
- KDS ;
- historique ;
- encaissement.

Les six onglets partagent le même `user_id`, donc le même bucket. Le chargement a ajouté 50 appels ; la fenêtre idle suivante en a compté 37.

### Modèle de saturation en panne WebSocket

Le régime connecté ne représente pas le pire cas :

- POS connecté : le tick principal est 60 s ; déconnecté : 5 s.
- À chaque tick POS : kiosk cash, stats, ready, web pending, web paid ; l'availability fallback est en plus auto-limité autour de 30 s.
- Coût POS approximatif : 7/min connecté contre 62/min déconnecté, soit **+55/min**.
- KDS full poll : 4/min connecté contre 12/min déconnecté, soit **+8/min**.
- `KdsSyncService` ajoute environ 6/min parce que son faux contrat WebSocket le maintient au régime déconnecté même quand la socket est saine.
- Si le bridge est présent sur l'onglet visible, cuisine et promo ajoutent jusqu'à 12/min chacun, soit **+24/min**.

À partir de la mesure idle réelle :

```text
37 idle + 55 fallback POS + 8 fallback KDS + 24 print listeners = 124/min
```

Ce modèle franchit 120 avant un seul clic, un ACK, une recherche client ou une mutation de statut. Même sans bridge, la marge tombe autour de 20/min ; un second poste ou une rafale de reconnexion suffit.

## 5. Raisons pour lesquelles le problème paraît intermittent

- La valeur locale est 1 000/min, la valeur par défaut est 120/min.
- En WebSocket sain, la cadence POS descend à 60 s ; en panne, elle passe à 5 s.
- Les listeners impression s'activent seulement si un bridge local répond.
- Certains composants sautent l'onglet caché, d'autres continuent.
- Chaque onglet démarre sa propre fenêtre et ses propres timers, mais le bucket est partagé par utilisateur.
- Les erreurs ne sont pas rendues uniformément : page vide, zéro fabriqué, ancienne valeur, toast global ou silence.

## 6. Architecture de correction

### Immédiat

1. Corriger le contrat KDS WebSocket et son test faux-vert.
2. Corriger parser/bucket/dédup CSP et les violations normales.
3. Respecter `Retry-After`, conserver les dernières données et afficher leur fraîcheur.
4. Suspendre tous les pollings non critiques dans les onglets cachés.

### Structurel

1. Une requête `OperatorInbox` agrège les files actives.
2. Un coordinateur unique possède la cadence par onglet.
3. `BroadcastChannel` élit un leader par navigateur ; les autres onglets consomment son snapshot.
4. WebSocket déclenche les deltas ; une réconciliation basse fréquence reste active.
5. Les mutations possèdent un budget séparé des lectures opérationnelles.
6. Les métriques publient `requests/min/user`, 429, `Retry-After`, freshness et nombre d'onglets leaders.

## 7. Tests falsifiables

1. Quatre reloads POS sous un plafond global 120 : CSP ne doit ni consommer le bucket métier ni produire de warning malformed.
2. Six écrans, deux minutes idle, socket saine : zéro 429 et budget documenté.
3. Même matrice, socket coupée : fallback fonctionnel sous plafond ; une mutation conserve sa capacité.
4. Deux caisses avec le même compte : définir explicitement si cela est supporté ; sinon identité device/user distincte.
5. Bridge présent : les listeners ne multiplient pas les appels par onglet.
6. `Retry-After` : aucun retry en boucle ; dernière donnée conservée ; état dégradé visible.
7. NAT commun : borne/web/CSP ne se bloquent pas mutuellement.

**RATE_LIMIT_VERDICT: ROOT_CAUSE_CONFIRMED**  
**SIMPLE_LIMIT_INCREASE: REJECTED**
