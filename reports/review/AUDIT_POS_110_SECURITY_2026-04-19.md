# Axe 11 — Sécurité surface POS / admin

| Sujet | Constats |
|-------|----------|
| **Auth** | Sanctum Bearer + `apiKey` middleware sur groupe admin — standard SPA. |
| **CSRF** | API JSON stateless — surface CSRF navigateur réduite vs formulaires blade ; vérifier si cookies session mélangés (hors scope preuve). |
| **Rate limit** | `throttle:pos-order-create`, `pos-order-update`, fiscal `10,1` sur open/close Z (`routes/api.php` 625–636, 797–800). |
| **Secrets prod** | `FiscalSecretProductionGuardTest` refuse secrets dev courts / sentinels en prod. |
| **Validation entrées** | P5–P10 renforcent bornes numériques — **ne remplace pas** contrôle autorisations. |
| **CORS** | Fichiers config non relus exhaustivement — **P3** suivi infra. |
| **XSS admin** | Vue templates — pas d’audit penetration ici. |

**Pentest interne simulé** : **non exécuté** (read-only, pas d’outillage attaque).

**Liens tracker :** F-SEC-001, F-SEC-002.
