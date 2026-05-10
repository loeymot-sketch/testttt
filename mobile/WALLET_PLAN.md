# 📱 Le Cayenne — Wallet Integration Plan (Apple + Google)

**Status** : V0 mock livré ; Phase 6 backend pipeline planifié post owner-gate D4.
**Source** : `reports/review/mobile-loyalty-audit-2026-05-10/05_wallet.md` (Agent-5).

---

## §1 — Quick reference

| Aspect | Apple Wallet | Google Wallet |
|---|---|---|
| V0 button asset | `uploads/add-to-apple-wallet-fr-stub.svg` | `uploads/add-to-google-wallet-fr-stub.svg` |
| V0 click handler | Opens `ModalWalletV0Notice` (modal informant) | Same modal, kind=google |
| Phase 6 endpoint | `GET /api/v1/frontend/loyalty/wallet/apple` → `.pkpass` blob | `GET /api/v1/frontend/loyalty/wallet/google` → `{ save_url }` JWT |
| Format | `.pkpass` ZIP (storeCard style) | Google Wallet `LoyaltyClass` + `LoyaltyObject` JWT-signed |
| Signing | PKCS#7 detached (openssl smime) | RS256 JWT (service account private key) |
| PHP lib | `pkpass/pkpass:^1.7` (MIT) | `firebase/php-jwt:^6.10` (RS256 — CVE-2025-45769 inapplicable) |
| Cert needs | Apple Developer Program $99/yr + Pass Type ID + WWDR G4 | Google Pay Console + Issuer ID + service account JSON |

---

## §2 — Owner gates (D4 in HOW_TO_RESUME.md)

Before Phase 6 ships :
1. **Apple** : Apple Developer Program subscription, Pass Type ID `pass.fr.lecayenne.loyalty`, WWDR G4 cert download, `.p12` private key with passphrase.
2. **Google** : Google Pay & Wallet Console signup (free), Issuer ID assignment (19 digits), service account JSON key (chmod 0600, never commit), `LoyaltyClass` submission (review 1-3 business days).
3. **APNs** (Apple push update) : separate APNs `.p12` cert from Apple Developer portal.

---

## §3 — Apple `pass.json` example for Le Cayenne

```json
{
  "formatVersion": 1,
  "passTypeIdentifier": "pass.fr.lecayenne.loyalty",
  "teamIdentifier": "<APPLE_TEAM_ID>",
  "organizationName": "Le Cayenne",
  "serialNumber": "FK-12345",
  "description": "Carte fidélité Le Cayenne",
  "logoText": "LE CAYENNE",
  "foregroundColor": "rgb(255,255,255)",
  "backgroundColor":  "rgb(10,10,10)",
  "labelColor":       "rgb(255,217,61)",
  "groupingIdentifier": "lecayenne-loyalty-group",
  "sharingProhibited": true,
  "webServiceURL": "https://api.foodking.fr/v1/wallet/apple",
  "authenticationToken": "<32+ char per-pass random>",
  "barcodes": [{
    "format": "PKBarcodeFormatQR",
    "message": "FK:<loyalty_code>",
    "messageEncoding": "iso-8859-1",
    "altText": "FK-12345"
  }],
  "storeCard": {
    "headerFields":     [{ "key": "balance",   "label": "POINTS",    "value": "347" }],
    "primaryFields":    [{ "key": "member",    "label": "MEMBRE",    "value": "Ikyes B." }],
    "secondaryFields":  [{ "key": "card_no",   "label": "N° CARTE",  "value": "FK-12345" },
                         { "key": "next",      "label": "PROCHAINE RÉCOMPENSE", "value": "Burger gratuit" }],
    "auxiliaryFields":  [{ "key": "progress",  "label": "RESTANT",   "value": "153 pts" }]
  }
}
```

**Important** : `barcodes[].message` MUST be `FK:<loyalty_code>` — the existing backend `LoyaltyController::scan()` (`app/Http/Controllers/Frontend/LoyaltyController.php:611`) strips `FK:` prefix and matches against `users.loyalty_code`. Tout autre format (e.g., `LECAY-LOYALTY-{user_id}-{hmac}`) **est rejeté** par le scanner kiosk (cross-validated par 3 agents dans 99_VERDICT.md §1 DEC-01).

---

## §4 — Apple signing pipeline

```
1. Render pass.json from user state
2. Stage bundle dir : pass.json + icon@1x,2x,3x.png + logo@1x,2x,3x.png + strip*.png
3. Compute manifest.json (SHA1 hash of each file)
4. Sign manifest.json with PKCS#7 detached :
   openssl smime -binary -sign \
     -certfile WWDR-G4.pem \
     -signer pass-type-id.pem \
     -inkey pass-type-id.key \
     -in manifest.json \
     -out signature \
     -outform DER \
     -passin pass:<key passphrase>
5. ZIP bundle (no top-level dir) as <serialNumber>.pkpass
6. Serve with Content-Type: application/vnd.apple.pkpass
```

PHP library : `pkpass/pkpass` — `composer require pkpass/pkpass:^1.7`

```php
use PKPass\PKPass;
$pass = new PKPass(storage_path('certs/apple/Certificates.p12'), env('APPLE_PASS_CERT_PASSWORD'));
$pass->setData($passJsonArray);
$pass->addFile(storage_path('wallet/icon.png'));
$pass->addFile(storage_path('wallet/icon@2x.png'));
$pass->addFile(storage_path('wallet/logo.png'));
$pass->addFile(storage_path('wallet/strip.png'));
return response($pass->create())->header('Content-Type', 'application/vnd.apple.pkpass');
```

---

## §5 — Google Wallet save flow

### LoyaltyClass (one-time admin setup)
```json
{
  "id": "<ISSUER_ID>.lecayenne_loyalty_v1",
  "issuerName": "Le Cayenne",
  "programName": "Le Cayenne Fidélité",
  "hexBackgroundColor": "#0A0A0A",
  "countryCode": "FR",
  "reviewStatus": "UNDER_REVIEW"
}
```

### LoyaltyObject (per-user, generated at sign-up)
```json
{
  "id": "<ISSUER_ID>.user-12345",
  "classId": "<ISSUER_ID>.lecayenne_loyalty_v1",
  "state": "ACTIVE",
  "accountId": "FK-12345",
  "accountName": "Ikyes B.",
  "loyaltyPoints": { "label": "Points", "balance": { "int": 347 } },
  "barcode": { "type": "QR_CODE", "value": "FK:<loyalty_code>" },
  "notifyPreference": "NOTIFY_ON_UPDATE"
}
```

### Save URL JWT (RS256)
```php
use Firebase\JWT\JWT;
$payload = [
  'iss' => env('GOOGLE_WALLET_SA_EMAIL'),
  'aud' => 'google',
  'typ' => 'savetowallet',
  'iat' => time(),
  'origins' => ['https://lecayenne.fr', 'https://app.lecayenne.fr'],
  'payload' => ['loyaltyObjects' => [['id' => $objectId]]],
];
$privateKey = file_get_contents(storage_path('certs/google/service-account-key.pem'));
return 'https://pay.google.com/gp/v/save/' . JWT::encode($payload, $privateKey, 'RS256');
```

**Note CVE-2025-45769** : DISPUTED at NVD ; applies to HMAC short-key flows ; **N/A for RS256** (Google Wallet mandate). `firebase/php-jwt:^6.10` is safe ; upgrade to `^7.0` as defense-in-depth only.

---

## §6 — Update strategy on balance change

### Apple (push-pull)
1. Device registers via `POST /v1/devices/{deviceLibId}/registrations/{passTypeId}/{serial}` with `{ "pushToken": "<APNs token>" }`. Backend stores `(serial → push_token → device_lib_id)` in `wallet_apple_registrations`.
2. On balance change : backend sends silent APNs push `{}` to registered tokens.
3. Device pulls `GET /v1/devices/.../registrations/.../?passesUpdatedSince=<tag>` → list of updated serials, then `GET /v1/passes/{passTypeId}/{serial}` with `Authorization: ApplePass <token>` → regenerated `.pkpass`.

### Google (server-push)
PATCH `LoyaltyObject` with new balance ; `notifyPreference: NOTIFY_ON_UPDATE` triggers automatic push.
```
PATCH https://walletobjects.googleapis.com/walletobjects/v1/loyaltyobject/<id>
{ "loyaltyPoints": { "balance": { "int": 372 } } }
```

---

## §7 — Privacy + RGPD compliance

### PII allowed in pass
- `member_number` (`FK-12345`) — internal identifier, non-PII
- `first_name` + last-name initial (`Ikyes B.`) — minimal identification
- `loyalty_points` balance (integer)

### PII forbidden in pass
- Phone number, email
- Transaction history / order details
- Payment card data
- Date of birth, address
- Loyalty consent flags

### Revocation flow (RGPD opt-out)
- **Apple** : `wallet_apple_registrations` row deleted → APNs revoke + send final pass update with `voided: true` → Wallet greys out the pass.
- **Google** : PATCH `LoyaltyObject` with `state: "INACTIVE"` → object hidden from user's wallet.

### Branch isolation
- New table `wallet_apple_registrations` must declare `branch_id` column (CLAUDE.md §9 BranchScope).
- A user with `branch_id=1` MUST NOT receive APNs pushes for branch 2's pass.

---

## §8 — Phase 6 backend layout

```
app/Services/Wallet/
  AppleWalletPassService.php        # Build pass.json + sign via pkpass/pkpass
  GoogleWalletService.php           # Build LoyaltyObject + RS256 JWT save URL
  WalletUpdateService.php           # Push updates on balance change

app/Http/Controllers/Api/V1/Frontend/
  WalletController.php              # GET /apple → .pkpass blob; GET /google → { save_url }

app/Http/Controllers/
  WalletWebServiceController.php    # Apple webServiceURL endpoints (registration, list, get)

app/Listeners/
  PushWalletUpdateOnBalanceChange.php  # Hook on loyalty_transactions insert

database/migrations/
  2026_XX_XX_create_wallet_apple_registrations_table.php
                                    # with branch_id column + BranchScope

config/wallet.php                   # cert paths, issuer_id, sa_email
storage/certs/
  apple/Certificates.p12
  apple/WWDR-G4.pem
  google/service-account-key.pem

routes/api.php :
  Wallet routes under auth:sanctum + ability:wallet:loyalty
```

**Sanctum ability** : `wallet:loyalty` separated from `mobile:order` (CLAUDE.md §9 invariants).

---

## §9 — Top risks

| Risk | Mitigation |
|---|---|
| Apple Pass Type ID cert expires yearly | Calendar reminder 60d before. Renewal : new CSR → upload to Apple Developer → new `.cer` → re-export `.p12` → swap env. 1-2d overlap safe. |
| Google SA key rotation (90d cadence) | Rotate via GCP IAM. Add new key, deploy, delete old key. Existing saved passes remain valid until expiration. |
| `pass.json` schema drift | Pin `formatVersion: 1`. Annual review of Apple Developer release notes. |
| Google LoyaltyClass review rejection | Provide complete `programLogo`, `localizedProgramName`, ToS in `textModulesData`. Match `issuerName` to legal entity name. |
| Signing key leak | `storage/certs/` `chmod 0600`, encrypted backup vault. Never commit. |
| Double-update race (POS sale + mobile redeem) | `loyalty_balance_version` int incremented on every change. Apple `lastUpdateTag` + Google 60s notification dedup. |

---

## §10 — V0 → Phase 6 transition checklist

### Mockable in V0 ✅ (already shipped)
- Stub SVG assets `uploads/add-to-{apple,google}-wallet-fr-stub.svg`
- `ModalWalletV0Notice` in `screens-modals.jsx`
- Click handler in `screens-main.jsx::ScreenLoyalty` ACTIONS RAPIDES section
- Data shape `mobile/data/wallet-spec.js`
- This plan document

### Hard-blocked until Phase 6 ⛔
- Replace stub SVGs with official Apple/Google FR-locale assets
- Backend `pkpass/pkpass` + `firebase/php-jwt` install + Service classes
- `wallet_apple_registrations` migration + BranchScope
- APNs silent push integration
- LoyaltyClass review by Google (1-3 business days)
- Owner provisioning of Apple/Google certs

### Estimated Phase 6 work (post owner-gate)
- Cert + console setup : 1-2d (review wait)
- PHP services : 2-3d
- API endpoints + ability gate : 1d
- Migration + branch scope + tests : 1d
- Mobile swap mock→real : 0.5d
- E2E real-device test : 1d
- **Total ~1 week**

---

## §11 — V0 modal content (verbatim, do not paraphrase)

```
Title : Apple Wallet / Google Wallet
Body  : « Cette fonctionnalité sera disponible lors du déploiement
         en production. Pour l'instant, présente ton QR fidélité
         directement depuis l'app. »
CTA   : « Voir mon QR » → close modal, scroll to QR card (already on screen)
Close : top-right X
```

— *Fin WALLET_PLAN.md. Synthèse complète : `reports/review/mobile-loyalty-audit-2026-05-10/05_wallet.md`.*
