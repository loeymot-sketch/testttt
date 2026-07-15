# AUDIT E2E COMPLET — site web Le Cayenne (2026-07-09)

Goal owner : « test-e2e tout le site web UI et UX sécurité et images et vérifie tout ».
Méthode : 13 surfaces capturées+lues par moi (visuel) + workflow adversaire 10 agents
(5 dims × prouve→vérifie, chaque finding reproduit) + heals + re-vérif adversaire.

## Résultat : P0 = 0 · P1 = 0 (après heal) · convergence atteinte

### Visuel (13 surfaces, toutes ✅) — `VISUAL_AUDIT.md`
Home desktop+mobile, Menu, Item detail, Wizard, Cart, Upsell ×2, Checkout, **Payment (Stripe
OFF, comptoir only)**, OTP gate, Account modal, About. 0 erreur console partout ; intégrité
numérique money-path (1,90€ cohérent, +1 pt floor) ; responsive OK ; 0 résidu démo ; images OK.

### Code adverse (10 agents) : 1 seul P1, healé + 4 heals sûrs
| Heal | Sév | Fichier | Fix | Vérif |
|---|---|---|---|---|
| OTP → échec commande **silencieux** | **P1** | funnel.jsx verifyOtp | erreur de placement surfacée via `apiError` (visible page paiement) au lieu de `authErr` (modale fermée) | ✅ runtime + adverse |
| Clé idempotence régénérée → **doublon commande** | P2 | funnel.jsx + api.js | clé stable par session paiement (`idemKey`), retry = même clé → backend dédoublonne | ✅ adverse (flux jusqu'au header X-Idempotency-Key) |
| Earn fallback `Math.round` | P3 | funnel.jsx:793 | → `Math.floor` (règle 1pt/€ floor) | ✅ |
| Promo input + note textarea sans nom a11y | P2 | funnel.jsx + flows.jsx | `aria-label` ajoutés (×4) | ✅ |

Régression : **0** (parity VERT, 0 erreur console, re-vérif adverse PASS). Heals synchronisés
dans le dossier de déploiement `lecayenne-web-deploy/`.

## Restes NON bloquants (P2/P3 — divulgués, pas de heal ce cycle)
- **P2** hero 1,4 Mo eager PNG (LCP) — passer en WebP/srcset (étape build).
- **P2** CartDrawer sans focus-trap Tab (a11y).
- **P2** contraste « Modifier » wizard (wizard-v2.jsx, zone sensible).
- **P2** reload en cours de funnel perd le panier (pas de persistance).
- **P2→P3** mixed-content : `api-base-url`/`menu-image-base` en `http://127.0.0.1` → mettre l'URL
  https prod au déploiement (déjà dans le plan go-live + guide Vercel).
- **P3** : X-API-Key public-by-design (non-secret, endpoints sensibles restent Bearer-gated),
  token localStorage TTL ~30j, pas de CSP/HSTS, geocode adresse via OSM (RGPD à divulguer),
  OTP sans validation vide côté client, item qty wizard =1, alt="" vignettes (correct=décoratif
  avec libellé adjacent), loyalty redeem non-multiple de 100 (backend = SSOT).

## Sécurité — posture saine (confirmée par curl live)
Auth 100% Bearer server-enforced (profile/history/order/redeem/qr → 401 sans token) · QR signé
serveur (aucun secret client) · aucune clé sk_/pk_ · PAN/CVC jamais transmis · Stripe OFF
triple-verrou (DOM + config serveur + webhook 503) · liens `rel=noopener` · 0 log PII.
