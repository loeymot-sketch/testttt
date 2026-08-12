# TASK_GLOB_OPS_CSP_RATE_LIMIT_001 — Parser CSP réel et bucket indépendant

## Meta

- **Priority:** P0 request amplification / P1 observability
- **EXECUTION_TIER:** `complex`
- **PRIMARY_EXECUTION_MODEL:** `gpt-5.5-pro`
- **REASONING_EFFORT:** `xhigh`
- **TEST_STRATEGY:** `local-validation` + `playwright-critical-flow`
- **SOURCE:** `reports/audit/RATE_LIMIT_FORENSICS_2026-08-12.md`
- **STATUS:** `PENDING_SECURITY_GATE_AND_RUN_CYCLE`

## Problème prouvé

1. Un reload POS réel génère 37 POST `/api/frontend/csp-report`.
2. Tous sont loggés `csp_violation.malformed`.
3. La route ajoute `throttle:1000,1`, mais le groupe API applique déjà `throttle:api` (120/min par défaut). Le plafond 1 000 ne peut pas élargir le plafond externe.
4. Les tests utilisent exclusivement `postJson()`, dont le media type est `application/json`. Ils ne reproduisent ni `application/csp-report` ni `application/reports+json`.
5. Le contrôleur ne lit que `$request->input('csp-report')`; il ne parse pas explicitement le raw body natif.

## Goal

Accepter les deux formats navigateur réels, empêcher les rapports CSP de consommer le bucket métier/public global, agréger les doublons sans PII et ramener le chargement normal à zéro violation explicable.

## SUBSYSTEMS_TOUCHED

| Fichier | Action |
| --- | --- |
| `app/Http/Controllers/Frontend/CspReportController.php` | EDIT : parsing raw, formats legacy/Reporting API, taille/shape, fingerprint |
| `app/Providers/RouteServiceProvider.php` | EDIT borné : limiter CSP réellement séparé ou exception nommée dans le limiteur global |
| `routes/api.php` | EDIT minimal si nécessaire pour appliquer le limiter nommé sans double plafond trompeur |
| `tests/Feature/Observability/CspReportEndpointTest.php` | EDIT : media types natifs, tableau moderne, malformed, taille, PII |
| `tests/Unit/Security/RateLimiterConfigTest.php` | EDIT : prouver la séparation du bucket |
| `tests/Feature/Security/CspRateLimitIsolationTest.php` | CREATE recommandé : 120 API métier n'affectent pas CSP et inversement |
| `config/security.php` / assets directs responsables | EDIT seulement après preuve de la directive violée ; aucun assouplissement générique |

## SUBSYSTEMS_OFF_LIMITS

- Auth/login, pricing, paiement, OrderService, FrontendOrderService.
- Aucun relèvement général de `API_THROTTLE_PER_MINUTE`.
- Aucun `unsafe-*` supplémentaire dans la CSP.
- Aucune désactivation globale de CSP en production.
- Aucun logging du raw body, token, email, téléphone, PIN ou URL query sensible.

## INVARIANTS_AT_RISK

- **Security / CORS / CSP:** un rapport ne doit jamais exécuter, rediriger ou stocker une charge arbitraire.
- **Rate-limit isolation:** le bucket observabilité ne doit pas permettre de contourner le throttle d'une mutation.
- **PII:** les URL et payloads restent sanitisés ; taille bornée avant décodage.
- **Availability:** l'endpoint retourne 204 pour un rapport valide ou invalide sans produire une tempête de logs.

## Contrat cible

### Formats acceptés

1. `Content-Type: application/csp-report`
   - raw JSON object `{ "csp-report": {...} }` ;
   - limite de corps explicite, par exemple 32 KiB.
2. `Content-Type: application/reports+json`
   - tableau borné de rapports ;
   - n'accepter que les types CSP attendus ;
   - extraire `body` selon le standard supporté.
3. `application/json`
   - conservé pour compatibilité et tests/outils internes.

### Déduplication

- Fingerprint HMAC/hash des champs sanitisés : directive, blocked origin/path tronqué, source path tronqué, line/column et policy version.
- Une occurrence détaillée par fenêtre, puis compteur agrégé.
- Aucun hash construit directement depuis une valeur secrète non sanitisée.

### Rate limit

Le test doit prouver l'ordre middleware effectif. Choisir une solution qui produit réellement un bucket CSP indépendant :

- limiter nommé traité explicitement par le limiteur `api` selon la route, **ou**
- endpoint déplacé dans un groupe minimal sans `throttle:api`, conservant `installed`, correlation ID, taille et son propre limiter.

Ne pas empiler simplement `throttle:1000` derrière `throttle:api`.

## Tests obligatoires

- Raw `application/csp-report` valide → 204 + log info structuré, jamais `malformed`.
- `application/reports+json` tableau valide → occurrences bornées/agrégées.
- JSON invalide, tableau trop grand, body > limite → 204, compteur d'erreur agrégé, aucune exception/PII.
- Paramètres sensibles URL masqués dans les deux formats.
- 121 rapports CSP sous limite dédiée n'épuisent pas le bucket API métier 120.
- 120 requêtes API métier n'empêchent pas un rapport CSP d'être accepté dans son bucket.
- Une mutation reste limitée normalement ; aucune route autre que CSP ne profite de l'exception.
- Test navigateur : reload POS, kiosk et web ; compter rapports, statuts et types ; objectif zéro malformed et correction des violations normales.

## Acceptance Criteria

- [ ] Le test n'utilise pas seulement `postJson()`.
- [ ] Legacy et Reporting API modernes sont parsés.
- [ ] Zéro `csp_violation.malformed` sur un chargement normal.
- [ ] Le limiter CSP est prouvé indépendant au niveau middleware, pas seulement configuré sur la route.
- [ ] Aucune hausse du plafond global.
- [ ] Les violations restantes sont expliquées et corrigées sans élargir la CSP par défaut.
- [ ] Le volume de logs est dédupliqué/agrégé.
- [ ] Le test NAT commun couvre web/borne/admin.

## SYMMETRY_NOTE

N/A — services de commande hors scope.

## Gate

La modification du limiteur global et de `routes/api.php` est security/frozen-adjacent. Un plan de cycle et une décision enregistrée dans `docs/gates/GATE_LOG.md` sont requis avant exécution. Cette task ne constitue pas une approbation.
