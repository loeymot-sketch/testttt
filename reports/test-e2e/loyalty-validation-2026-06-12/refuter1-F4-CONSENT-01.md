# REFUTER-1 — F4-CONSENT-01 (RGPD register sans consentement / pas d'opt-out)

Date: 2026-06-12 · Harnais :8767 / foodking_e2e · Rôle: réfutation adversariale indépendante

## Vérification file:line (toutes confirmées par Read/grep)

| Citation du finding | Vérifié | Détail |
|---|---|---|
| `LoyaltyController.php` register() L138-237 | ✅ | `public function register` à L138 ; crée User + loyalty_code + 25 pts welcome + LoyaltyTransaction earn ; **zéro référence "consent"** dans register() (seul optIn L447+ en a) |
| `routes/api.php:1428` route publique | ✅ | `Route::post('/register', ...)->middleware('throttle:5,1')` — throttle seul, pas d'auth (commentaire L1423: "/register is kept public") |
| `AwardLoyaltyPointsOnDelivery.php:25-175` zéro réf consentement | ✅ | fichier = 175 lignes, `grep -n consent` = 0 match ; résolution client purement par loyalty_code (L66-74) puis crédit |
| `LoyaltyOptInRequest.php:37-38` opt-in OK | ✅ | `consent_accepted => required|accepted` + `privacy_notice_version => required` |
| `LoyaltyConsentTest.php` hash-only | ✅ | 5 tests, tous `test_hash_identifier_*` — aucun test de gating accrual/retrait |
| Aucune route opt-out/withdraw | ✅ | `grep -rn "opt-out\|optOut\|opt_out\|withdraw\|revokeConsent" routes/ app/Http/Controllers/` = 0 match pertinent ; `LoyaltyConsent::create` appelé UNIQUEMENT depuis optIn (L484) |

## Repro live (indépendante, téléphone neuf)

```
curl -X POST http://127.0.0.1:8767/api/frontend/loyalty/register \
  -H 'x-api-key: b6d68vy2-...' -d '{"name":"Refuter1 NoConsent","phone":"0699887701"}'
→ HTTP 200 {"status":true,"data":{"name":"Refuter1 NoConsent","loyalty_code":"D9CFB657","points":25}}
```

Tinker (foodking_e2e):
```
user_id=75 code=D9CFB657 pts=25
consents=0          ← aucune ligne loyalty_consents pour ce user
earn_txns=1         ← ledger earn "Bonus de bienvenue" créé
total_consents_table=0
```

REPRO = 100% reproduite. Compte + code + 25 pts + ledger créés sans aucun champ ni enregistrement de consentement.

## Nuance matérielle trouvée (non mentionnée par le finder — atténuante, pas réfutante)

`resources/js/components/frontend/kiosk/KioskLoyaltyComponent.vue` L530-567 : le parcours borne RÉEL
gate l'appel `/loyalty/register` derrière une modale `KsConsentModal` explicite ("la requête N'EST PAS
émise tant que l'utilisateur n'a pas explicitement accepté"). Donc dans le parcours UI production, le
consentement EST demandé. MAIS :
1. le payload envoyé ne contient AUCUN champ consentement (name/phone/email seulement, L552-556) ;
2. le serveur ne persiste AUCUNE ligne `loyalty_consents` → le responsable de traitement ne peut PAS
   démontrer le consentement (RGPD art. 7.1 accountability) ;
3. l'endpoint reste publiquement appelable en bypassant l'UI (ma repro) ;
4. l'accrual listener et l'absence d'opt-out restent vrais indépendamment de l'UI.

→ Le finding reste FONDÉ. La nuance conforte le maintien à P2 (pas une élévation) : le geste affirmatif
existe côté UI, le gap est l'enregistrement/enforcement serveur + droit de retrait API.

## Dedup
Aucun match dans les lots connus (release/v1 A-H, dashboard-deep 06-08, system-b-final, pos-box-massive) —
`grep -rln consent` sur reports/ ne remonte que des fichiers sans rapport ou de la même campagne 06-12.
Le welcome-bonus lui-même = T-L1.4 (GOAL Loyalty 06-11), mais le gap consentement n'y était pas tracké.

## VERDICT
- **refuted = false** — toutes les citations file:line exactes, repro indépendante réussie, opt-out absent confirmé.
- **sev = P2 confirmée** : gap de gouvernance RGPD réel (consentement client-side non persisté = art. 7.1 non
  démontrable + endpoint bypassable + accrual sans gate + retrait API absent) MAIS pas un exploit, retrait
  exerçable manuellement par le gérant (admin), parcours UI borne déjà opt-in explicite, contexte V1
  perso mono-resto → deferrable, pas blocker V1. Ni P1 (pas d'exposition PII nouvelle, pas d'exploit),
  ni P3 (loi FR applicable même mono-resto, et le projet a déjà son standard interne opt-in que register bypasse).
