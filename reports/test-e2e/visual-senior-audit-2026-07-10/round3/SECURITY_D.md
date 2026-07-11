# VAGUE D — SÉCURITÉ (2026-07-11)

## D1 — endpoints sensibles sans session (attendu 401)
| endpoint | code | verdict |
|---|---|---|
| api/admin/users | 401 | ✅ |
| api/admin/setting/company | 401 | ✅ |
| api/admin/cash-overview | 401 | ✅ |
| api/frontend/menu | 401 | ✅ |
| api/admin/pos-order/list | 200 **text/html** (shell SPA, 0 data) | ✅ faux-positif écarté |
| api/admin/z-report/list | 200 **text/html** (shell SPA, 0 data) | ✅ faux-positif écarté |

Les deux 200 = index.html du SPA (15537 o), `content-type: text/html`, 0 champ sensible
(`total/fiscal_sequence/z_report/order_serial/current_hash/grand_total` = 0 match). Vérifié AU CORPS.

## D2 — OSS public : 0 PII
Payload public order-status-screen : 0 match `email|phone|customer_name|address`.

## D3 — pas de secret exposé
Aucun secret dans les réponses publiques ; endpoints data protégés par Sanctum (401).

## Nuance adversaire (reformulation, PAS une faille)
`/api/frontend/oss-order` renvoie 200 JSON `{"data":[]}` avec juste `x-api-key` (clé frontend
publique par design, livrée en clair dans `public/js/pos-app.js` — pattern accepté SPA, ce n'est
PAS un secret). C'est le board OSS semi-public (mur d'affichage client) : **0 donnée, 0 PII**.
`dining-order/show/5637` sans/avec clé = 400/404. Aucune fuite total/PII/fiscal. Pas de P0/P1.
→ Formulation exacte : « 200 non-auth = shell SPA HTML + board OSS public (JSON vide) ».
