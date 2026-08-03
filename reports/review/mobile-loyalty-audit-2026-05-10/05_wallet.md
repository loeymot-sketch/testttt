# Agent 5 — Wallet Integration Plan (Apple Wallet + Google Wallet)
**Audit** FoodKing Mobile Le Cayenne — Loyalty 2026-05-10 · **Scope** V0 mobile mock + Phase 6 full backend pipeline · **Read-only** : doc de référence pour Phase 6.

---

## §0 — Sources officielles (consultées 2026-05-10)

Apple Wallet : [Pass Design](https://developer.apple.com/library/archive/documentation/UserExperience/Conceptual/PassKit_PG/Creating.html) · [Web Service for updates](https://developer.apple.com/documentation/WalletPasses/adding-a-web-service-to-update-passes) · [Badge guidelines (FR locale)](https://developer.apple.com/wallet/add-to-apple-wallet-guidelines/) · [Wallet Resources (45 locales)](https://developer.apple.com/wallet/resources/).
Google Wallet : [loyaltyobject REST](https://developers.google.com/wallet/reference/rest/v1/loyaltyobject) · [loyaltyclass REST](https://developers.google.com/wallet/reference/rest/v1/loyaltyclass) · [JWT for loyalty](https://developers.google.com/wallet/retail/loyalty-cards/use-cases/jwt) · [Brand guidelines + button assets](https://developers.google.com/wallet/retail/loyalty-cards/resources/brand-guidelines) · [PATCH + notifyOnUpdate](https://developers.google.com/wallet/retail/loyalty-cards/use-cases/updates).
PHP libs (Packagist) : `pkpass/pkpass` (MIT, Schoffelen) · `thenextweb/passgenerator` (MIT, Laravel-native).
CVE : [CVE-2025-45769 NVD](https://nvd.nist.gov/vuln/detail/CVE-2025-45769) (status DISPUTED, HMAC-only, **n/a for RS256**).

---

## §1 — Apple Wallet `.pkpass` — complete `pass.json` for Le Cayenne loyalty card

`storeCard` style ; FR locale ; brand alignment via mobile `styles.css` tokens (`--ink` `#0A0A0A`, `--yellow` `#FFD93D`, `--orange` `#FF5A1F`). Background must be dark for WCAG contrast with white labels — yellow `#FFD93D` is reserved as `labelColor` accent (not background).

```json
{
  "formatVersion": 1,
  "passTypeIdentifier": "pass.fr.lecayenne.loyalty",
  "teamIdentifier": "ABCDE12345",
  "organizationName": "Le Cayenne",
  "serialNumber": "FK-12345",
  "description": "Carte fidélité Le Cayenne",
  "logoText": "LE CAYENNE",
  "foregroundColor": "rgb(255, 255, 255)",
  "backgroundColor": "rgb(10, 10, 10)",
  "labelColor": "rgb(255, 217, 61)",
  "groupingIdentifier": "lecayenne-loyalty-group",
  "sharingProhibited": true,
  "webServiceURL": "https://api.foodking.fr/v1/wallet/apple",
  "authenticationToken": "<32+ char per-pass random token, stored DB-side>",

  "barcodes": [
    {
      "format": "PKBarcodeFormatQR",
      "message": "FK:LECAY-LOYALTY-12345-<hmac>",
      "messageEncoding": "iso-8859-1",
      "altText": "FK-12345"
    }
  ],
  "barcode": {
    "format": "PKBarcodeFormatQR",
    "message": "FK:LECAY-LOYALTY-12345-<hmac>",
    "messageEncoding": "iso-8859-1",
    "altText": "FK-12345"
  },

  "storeCard": {
    "headerFields": [
      { "key": "balance", "label": "POINTS", "value": "347", "textAlignment": "PKTextAlignmentRight" }
    ],
    "primaryFields": [
      { "key": "member",    "label": "MEMBRE",   "value": "Ikyes B." }
    ],
    "secondaryFields": [
      { "key": "card_no",   "label": "N° CARTE", "value": "FK-12345" },
      { "key": "next",      "label": "PROCHAINE RÉCOMPENSE", "value": "Burger gratuit", "textAlignment": "PKTextAlignmentRight" }
    ],
    "auxiliaryFields": [
      { "key": "progress",  "label": "RESTANT",  "value": "153 pts" },
      { "key": "tier",      "label": "STATUT",   "value": "Habitué", "textAlignment": "PKTextAlignmentRight" }
    ],
    "backFields": [
      { "key": "tos",       "label": "Conditions",
        "value": "1 € dépensé = 1 point. 100 points = 1 € de réduction. Points valables 365 jours. Présente ce code à la caisse pour cumuler ou utiliser tes points." },
      { "key": "address",   "label": "Restaurant",
        "value": "Le Cayenne — Hénin-Beaumont 62210 — Tél. 03 21 XX XX XX" },
      { "key": "privacy",   "label": "Confidentialité",
        "value": "Tes points sont liés à ton numéro de carte FK-12345 uniquement. Aucune donnée personnelle (téléphone, email) n'est stockée dans cette carte." },
      { "key": "support",   "label": "Support",
        "value": "https://lecayenne.fr/aide" }
    ]
  },

  "locations": [
    { "longitude": 2.9520, "latitude": 50.4197, "relevantText": "Bienvenue chez Le Cayenne !" }
  ],
  "maxDistance": 200,
  "relevantDate": "2026-12-31T23:59:59+01:00"
}
```

### Required files in the `.pkpass` ZIP bundle
| File | Required | Source | Notes |
|---|---|---|---|
| `pass.json` | ✅ | generated per user | UTF-8, no BOM |
| `manifest.json` | ✅ | computed | SHA-1 of each file in bundle |
| `signature` | ✅ | PKCS#7 detached sig | of `manifest.json` |
| `icon.png` / `icon@2x.png` / `icon@3x.png` | ✅ | static asset | 29×29 / 58×58 / 87×87 |
| `logo.png` / `logo@2x.png` / `logo@3x.png` | ✅ | static asset | max 160×50 pt (1x), aspect free ; Le Cayenne wordmark on transparent bg |
| `strip.png` / `strip@2x.png` / `strip@3x.png` | optional | static asset | 320×84 / 640×168 — storeCard banner. Recommended: orange-to-yellow gradient with subtle pattern |
| `thumbnail.png` | not used for storeCard | — | n/a |
| `it.lproj/pass.strings` etc. | optional | per-locale | not needed (FR-lock V1 per ADR-007) |

### Recommended image assets (to produce in Phase 6)
- `icon`: tiny chili pepper glyph from Le Cayenne brand (matches `--orange` `#FF5A1F`)
- `logo`: "LE CAYENNE" wordmark in Anton font, white on transparent
- `strip`: full-bleed dark background `#0A0A0A` with orange/yellow gradient corner — matches `ScreenLoyalty` yellow-top + ink-card aesthetic

---

## §2 — Apple Wallet signing pipeline + cert requirements

### Owner needs (deferred decision — D4 in HOW_TO_RESUME.md)
1. **Apple Developer Program** subscription — €99/year. Required to register Pass Type IDs.
2. **Pass Type ID** registration in Apple Developer portal → identifier exactly `pass.fr.lecayenne.loyalty`.
3. **Pass Type ID certificate** (.cer) → import to Keychain → export as `.p12` with private key + passphrase.
4. **WWDR (Apple Worldwide Developer Relations) intermediate cert** — download from Apple's [certificate authority page](https://www.apple.com/certificateauthority/). Use the **G4** cert (current 2026, expires 2030).
5. APNs setup if push updates required (Phase 6 §6 below).

### Pipeline (per-pass generation, runtime)
```
1.  Render pass.json from user data (member_no, points balance, next reward)
2.  Stage bundle dir : pass.json + icon*.png + logo*.png + strip*.png
3.  Compute manifest.json :
       { "pass.json": "<sha1-hex>", "icon.png": "<sha1>", ... }
4.  Sign manifest.json with PKCS#7 detached signature :
       openssl smime -binary -sign \
         -certfile WWDR-G4.pem \
         -signer pass-type-id.pem \
         -inkey  pass-type-id.key \
         -in     manifest.json \
         -out    signature \
         -outform DER \
         -passin pass:<key passphrase>
5.  ZIP bundle (no top-level dir) as <serialNumber>.pkpass
6.  Serve with Content-Type: application/vnd.apple.pkpass
```

### Recommended PHP libs (Phase 6 add to composer)

**Primary — `pkpass/pkpass` (Schoffelen)** — Packagist [pkpass/pkpass](https://packagist.org/packages/pkpass/pkpass), GitHub [tschoffelen/php-pkpass](https://github.com/tschoffelen/php-pkpass)
- MIT license, active maintenance (commits 2025-2026)
- Zero Laravel coupling — wraps openssl smime via PHP `openssl_pkcs7_sign`
- Install : `composer require pkpass/pkpass`
- Recommended version : `^1.7` (verify latest at install time)

```php
use PKPass\PKPass;
$pass = new PKPass(storage_path('certs/apple/Certificates.p12'), env('APPLE_PASS_CERT_PASSWORD'));
$pass->setData($passJsonArray);
$pass->addFile(storage_path('wallet/icon.png'));
$pass->addFile(storage_path('wallet/icon@2x.png'));
$pass->addFile(storage_path('wallet/logo.png'));
$pass->addFile(storage_path('wallet/strip.png'));
return response($pass->create())
    ->header('Content-Type', 'application/vnd.apple.pkpass');
```

**Alternative — `thenextweb/passgenerator`** — Packagist [thenextweb/passgenerator](https://packagist.org/packages/thenextweb/passgenerator)
- MIT, Laravel 7+ package, ships ServiceProvider + config publish + Storage facade integration
- Slightly heavier (couples to Laravel storage); good if Phase 11 stays Laravel-only
- Install : `composer require thenextweb/passgenerator`

**Decision tree** : Supabase path (D1=A in HOW_TO_RESUME.md) → use `pkpass/pkpass` from an Edge Function or a tiny Laravel side-service. FoodKing-backend path (D1=B) → either lib works ; prefer `pkpass/pkpass` for vendor neutrality.

---

## §3 — Google Wallet — `LoyaltyClass` (template) + `LoyaltyObject` (per-user)

### LoyaltyClass (one-time, owner sets up via console or POST `/loyaltyclass`)
```json
{
  "id": "3388000000099999999.lecayenne_loyalty_v1",
  "issuerName": "Le Cayenne",
  "programName": "Le Cayenne Fidélité",
  "programLogo": {
    "sourceUri": { "uri": "https://lecayenne.fr/assets/wallet/program-logo.png" },
    "contentDescription": { "defaultValue": { "language": "fr", "value": "Logo Le Cayenne" } }
  },
  "reviewStatus": "UNDER_REVIEW",
  "hexBackgroundColor": "#0A0A0A",
  "localizedIssuerName": { "defaultValue": { "language": "fr", "value": "Le Cayenne" } },
  "localizedProgramName": { "defaultValue": { "language": "fr", "value": "Fidélité Le Cayenne" } },
  "rewardsTier": "Habitué",
  "rewardsTierLabel": "Statut",
  "countryCode": "FR",
  "homepageUri": { "uri": "https://lecayenne.fr", "description": "Le Cayenne" },
  "locations": [{ "latitude": 50.4197, "longitude": 2.9520 }],
  "classTemplateInfo": {
    "cardTemplateOverride": {
      "cardRowTemplateInfos": [
        {
          "twoItems": {
            "startItem":  { "firstValue": { "fields": [{ "fieldPath": "object.loyaltyPoints.balance" }] } },
            "endItem":    { "firstValue": { "fields": [{ "fieldPath": "object.textModulesData['next_reward']" }] } }
          }
        }
      ]
    }
  }
}
```

### LoyaltyObject (per-user, generated when user adds card)
```json
{
  "id": "3388000000099999999.user-12345",
  "classId": "3388000000099999999.lecayenne_loyalty_v1",
  "state": "ACTIVE",
  "accountId": "FK-12345",
  "accountName": "Ikyes B.",
  "loyaltyPoints": {
    "label": "Points",
    "balance": { "int": 347 }
  },
  "secondaryLoyaltyPoints": {
    "label": "Manquant pour burger",
    "balance": { "int": 153 }
  },
  "barcode": {
    "type": "QR_CODE",
    "value": "FK:LECAY-LOYALTY-12345-<hmac>",
    "alternateText": "FK-12345"
  },
  "textModulesData": [
    { "id": "next_reward", "header": "Prochaine récompense", "body": "Burger gratuit (-153 pts)" },
    { "id": "tos",         "header": "Conditions",          "body": "1€=1pt · 100pts=1€ · Validité 365j" }
  ],
  "linksModuleData": {
    "uris": [
      { "uri": "https://lecayenne.fr/aide", "description": "Aide" },
      { "uri": "tel:+33321XXXXXX",         "description": "Téléphone" }
    ]
  },
  "notifyPreference": "NOTIFY_ON_UPDATE"
}
```

### JWT envelope for save link
```json
{
  "iss": "lecayenne-wallet@lecayenne-fk-foundation.iam.gserviceaccount.com",
  "aud": "google",
  "typ": "savetowallet",
  "iat": 1715342400,
  "origins": ["https://lecayenne.fr", "https://app.lecayenne.fr"],
  "payload": {
    "loyaltyObjects": [
      { "id": "3388000000099999999.user-12345" }
    ]
  }
}
```

Signed with service-account private key using **RS256**. Save URL :
```
https://pay.google.com/gp/v/save/<encoded-jwt>
```

---

## §4 — Google Wallet pipeline + signing

### Owner needs (deferred — D4 in HOW_TO_RESUME.md)
1. **Google Pay & Wallet Console** account — free, instant.
2. **Issuer ID** (numeric, 19 digits) — assigned on console signup. Used as prefix in all class/object IDs.
3. **Google Cloud project** + **service account** → JSON key download. Service account email gets added in Wallet Console → Users.
4. **Class submission for review** — once `LoyaltyClass` POSTed with `reviewStatus: UNDER_REVIEW`, Google reviews (1-3 business days typically). Production cards cannot be saved by real users until `reviewStatus: APPROVED`.

### Pipeline (per-pass save link generation, runtime)
```
1.  Backend builds LoyaltyObject payload from user data (member_no, balance, hmac barcode)
2.  POST /walletobjects/v1/loyaltyobject (first time) or PATCH (subsequent updates)
       — auth via OAuth2 client_credentials with service account JWT
3.  Build save-link JWT payload (see §3)
4.  Sign JWT with service-account private key (RS256)
5.  Build URL : "https://pay.google.com/gp/v/save/" + base64url(headerJSON) + "." + base64url(payloadJSON) + "." + base64url(signature)
6.  Return URL to mobile client → user taps → Google Wallet opens with pre-filled card
```

### Recommended PHP lib (Phase 6 add to composer)

**`firebase/php-jwt`** — Packagist [firebase/php-jwt](https://packagist.org/packages/firebase/php-jwt)
- Install : `composer require firebase/php-jwt:^6.10`
- Use `^6.10` is **safe for this use case** (RS256). CVE-2025-45769 only applies to HMAC algorithms with short keys and is currently DISPUTED at NVD (Aug 2025) — explicit NVD comment: "key lengths are expected to be set by the application, not by this library." Google Wallet mandates RS256 signing with a 2048-bit RSA service account key, so the CVE is **not applicable**.
- Defense-in-depth recommendation : upgrade to `^7.0` when feasible (no breaking changes for RS256 callers, but verify in a test branch).

**Already in composer (reuse, don't duplicate)** :
- `google/apiclient: ^2.16` — full Google REST SDK incl. Wallet endpoints via `Google_Service_Walletobjects` style. **Verify `walletobjects` is in the installed discovery doc** ; if not, drop a direct Guzzle call to `https://walletobjects.googleapis.com/walletobjects/v1/loyaltyobject` with OAuth2 bearer.
- `simplesoftwareio/simple-qrcode: ^4.2` — already used by FoodKing for fiscal QR ; reusable for V0 fallback QR rendering.

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
$token = JWT::encode($payload, $privateKey, 'RS256');
return "https://pay.google.com/gp/v/save/{$token}";
```

---

## §5 — V0 mobile button + instructions modal spec

### Visual spec — buttons placement inside `ScreenLoyalty`
Add a new section between the existing "QR card" (line 858-870 of `mobile/screens-main.jsx`) and the "points card black" (line 873). New section card style : white background, padded 16px, contains two buttons stacked vertically (Apple on top, Google below) on mobile (<400px width) or side-by-side on tablet.

### Apple Wallet button asset
- Official SVG : Apple provides FR-locale SVG at [developer.apple.com/wallet/add-to-apple-wallet-guidelines/](https://developer.apple.com/wallet/add-to-apple-wallet-guidelines/) — download "FR" locale package, file `Add to Apple Wallet/FR/SVG/Add to Apple Wallet badge.svg`.
- Min height : `40px` (Apple HIG minimum); recommended `48-56px` on iPhone.
- Clear space : `.1×` badge height on all sides.
- Background : white card `#FFFFFF` (the badge has dark variant ; use light variant on our white card).

### Google Wallet button asset
- Official SVG : [developers.google.com/wallet/retail/loyalty-cards/resources/brand-guidelines](https://developers.google.com/wallet/retail/loyalty-cards/resources/brand-guidelines) — download FR locale "Add to Google Wallet" SVG.
- Min height : `48dp` (Google brand minimum).
- Clear space : `8dp`.

### Recommended file layout (Phase 6 deliverables — agent is read-only, do NOT create in V0)
```
mobile/
  data/
    wallet-spec.js          ← data shape for Phase 6 wire-up (see §5.2)
  WALLET_PLAN.md            ← full Phase 6 reference (copy of this doc)
  uploads/
    add-to-apple-wallet-fr.svg
    add-to-google-wallet-fr.svg
```

### V0 modal content (verbatim — do not paraphrase)
On tap of either button in V0, open `ModalWalletV0Notice`:
```
Title : Apple Wallet / Google Wallet
Body  : « Cette fonctionnalité sera disponible lors du déploiement en
         production. Pour l'instant, présente ton QR fidélité directement
         depuis l'app. »
CTA   : « Voir mon QR » → close modal, scroll to QR card (already on screen)
Close : top-right X
```

### Data shape for Phase 6 (`mobile/data/wallet-spec.js` — to be created Phase 6)
```js
window.LC.walletSpec = {
  apple: {
    asset_url: '/uploads/add-to-apple-wallet-fr.svg',
    endpoint:  '/api/v1/frontend/loyalty/wallet/apple', // returns .pkpass blob
    accept:    'application/vnd.apple.pkpass',
  },
  google: {
    asset_url: '/uploads/add-to-google-wallet-fr.svg',
    endpoint:  '/api/v1/frontend/loyalty/wallet/google', // returns { save_url }
    accept:    'application/json',
  },
  v0_mock: true, // flip to false in Phase 6
};
```

### `screens-main.jsx::ScreenLoyalty` refactor sketch (Phase 5/6, multi-section)
Insert a `<WalletButtonsCard/>` component after the QR card render block. Both buttons share the same V0 handler `() => openModal('walletV0Notice')`. When `walletSpec.v0_mock === false`, swap handler to real `fetch(endpoint)` flow.

---

## §6 — Update strategy after balance change

### Apple Wallet (push-pull model)
1. Pass embeds `webServiceURL` + `authenticationToken` at creation. Device registers via `POST /v1/devices/{deviceLibId}/registrations/{passTypeId}/{serialNumber}` with `{ "pushToken": "<APNs token>" }`. Backend stores `(serial → push_token → device_lib_id)` in `wallet_apple_registrations`.
2. When points change : backend sends silent APNs push (empty `{}`) to registered tokens.
3. Device calls `GET /v1/devices/{deviceLibId}/registrations/{passTypeId}?passesUpdatedSince=<tag>` → list of updated serials, then `GET /v1/passes/{passTypeId}/{serialNumber}` with `Authorization: ApplePass <token>`. Backend regenerates `.pkpass` and serves.

Required : separate APNs cert (`.p12` from Apple Developer portal), HTTPS endpoint with valid TLS (no self-signed).

### Google Wallet (server-push model)
PATCH `LoyaltyObject` with new balance ; with `notifyPreference: NOTIFY_ON_UPDATE` set, Google pushes to device automatically. No client polling.

```
PATCH https://walletobjects.googleapis.com/walletobjects/v1/loyaltyobject/<id>
{ "loyaltyPoints": { "balance": { "int": 372 } } }
```

### Mobile app side
Backend listener `AwardLoyaltyPointsOnDelivery` → `WalletUpdateService::pushBalance(userId)` → fan-out (Apple APNs silent + Google PATCH) as queued jobs. Mobile fetches `/loyalty/balance` on ScreenLoyalty open — independent of wallet sync.

---

## §7 — Privacy + compliance

### PII inside the pass (allowed)
- `member_number` (`FK-12345`) — non-PII, internal identifier
- `first_name` + last-name initial (`Ikyes B.`) — minimal identification
- `loyalty_points` balance (integer) — non-PII

### NOT inside the pass (forbidden)
- Phone number, email
- Transaction history / order details
- Payment card data
- Date of birth, address
- Loyalty consent flags

### RGPD considerations
- The `.pkpass` and Google `LoyaltyObject` stay on the user's device. Both Apple and Google guarantee local storage with encryption-at-rest.
- **Revocation flow** : if user opts out of loyalty (`LoyaltyConsent.opted_out = true`) :
  - Apple : `wallet_apple_registrations` rows for this user → unregister via APNs revoke + serve a final pass update with `voided: true` flag in `pass.json` (Wallet greys out the pass).
  - Google : PATCH `LoyaltyObject` with `state: "INACTIVE"` → object is hidden from user's wallet.
- **Pass deletion** : backend ↔ device sync is unidirectional. Deleting user's row in `wallet_apple_registrations` does NOT remove the pass from their phone ; only the `voided: true` flag triggers visual indication.

### Per CLAUDE.md §9 — branch isolation
- `BranchScope` global must apply to a new `wallet_apple_registrations` table (add `branch_id` column).
- A user with `branch_id=1` should never receive APNs pushes for a pass issued by branch 2.

---

## §8 — V0 vs Phase 6 transition checklist

### Mockable in V0 (mobile-side only, no backend) ✅
- Apple/Google SVG badges → `mobile/uploads/add-to-{apple,google}-wallet-fr.svg`
- `ModalWalletV0Notice` (NEW) → `mobile/screens-modals.jsx`
- Click handler in `mobile/screens-main.jsx::ScreenLoyalty`
- Data-shape file `mobile/data/wallet-spec.js` (NEW) ready for Phase 6 wire-up
- Plan doc `mobile/WALLET_PLAN.md` (NEW, copy of this audit)

### Hard-blocked until Phase 6 (requires backend + owner decisions) ⛔
| Item | Blocker | Owner gate |
|---|---|---|
| Apple Pass Type ID cert generation | requires Apple Developer Program ($99/yr) | D4 |
| WWDR G4 cert download + chain setup | requires Apple Developer account | D4 |
| `pass.json` signing with openssl smime | requires private key + passphrase | D4 |
| Hosting `webServiceURL` for Apple push updates | requires HTTPS endpoint + APNs cert | D4 |
| Google Pay & Wallet Console signup | requires Google account + GCP project | D4 |
| Issuer ID assignment | provisioned by Google on console signup | D4 |
| Service account JSON key | generated in GCP IAM, downloaded once | D4 |
| LoyaltyClass `reviewStatus: APPROVED` | Google manual review (1-3 business days) | D4 |
| `composer require pkpass/pkpass firebase/php-jwt` | requires `composer install` rights on prod | Phase 6 deploy |
| `/api/v1/frontend/loyalty/wallet/{apple,google}` endpoints | backend route + controller | Phase 6 dev |
| `wallet_apple_registrations` migration | requires DBA review + branch_id scope | Phase 6 dev |
| APNs silent push integration | requires APNs cert + push job queue | Phase 6 dev |

### Phase 6 estimated work (post-owner-decisions)
Cert+console setup 1-2d (mostly review waits) · PHP services 2-3d · API endpoints + `wallet:loyalty` Sanctum ability 1d · migration+branch scope+tests 1d · mobile swap mock→real 0.5d · E2E real-device 1d. **Total ~1 week** after owner cert provisioning.

---

## §9 — Top risks

### R1 — Apple Pass Type ID cert expires yearly (CRITICAL)
- **Impact** : all `.pkpass` files generated after expiry have invalid signature → Wallet rejects ; existing installed passes continue to work but can no longer be updated.
- **Mitigation** :
  - Calendar reminder 60 days before expiry.
  - Renewal flow : generate new CSR → upload to Apple Developer portal → download new `.cer` → re-export `.p12` → swap `APPLE_PASS_CERT_PATH` in env → restart workers.
  - **Important** : new cert can be used immediately for new passes ; old cert remains valid until expiry, so a 1-2 day overlap is safe.

### R2 — Google service account key rotation (MEDIUM)
- **Impact** : if service account key is leaked, all loyalty objects can be PATCHed by attacker.
- **Mitigation** :
  - Rotate key every 90 days (GCP IAM → service account → keys → rotate).
  - Store key in Laravel `storage/certs/google/` with `chmod 0600` ; never commit.
  - Add new key, deploy, then delete old key in GCP console.

### R3 — `pass.json` schema drift (LOW)
- **Impact** : Apple sometimes adds fields (e.g., `sharingProhibited` came in iOS 11 ; `nfc.encryptionPublicKey` in iOS 13.4). Backwards compatibility is generally maintained but new features require schema bumps.
- **Mitigation** : pin `formatVersion: 1` (stable since 2012) ; subscribe to Apple Developer release notes ; review schema annually during cert renewal.

### R4 — Google Wallet review rejection (LOW-MEDIUM)
- **Impact** : initial `LoyaltyClass` submission could be rejected for missing logo, unclear ToS, or naming clash.
- **Mitigation** : 
  - Provide complete `programLogo`, `localizedProgramName`, `homepageUri`, ToS in `textModulesData`.
  - Match `issuerName` exactly to legal entity name (Le Cayenne SARL or similar — owner confirm).
  - Resubmission is free and usually approved within 1 business day after fixes.

### R5 — Signing key handling (LOW with proper rotation)
Private keys (Apple `.p12`, Google `.pem`) outside repo, `storage/certs/` chmod 0600, encrypted backup vault. RS256 mandated by Google ; PKCS#7 by Apple — no HMAC anywhere, so CVE-2025-45769 inapplicable.

### R6 — Double-update race (LOW)
Two near-simultaneous balance writes (POS sale + mobile redemption) can race. Mitigation : `loyalty_balance_version` int incremented on every change ; service reads atomically before pushing. Apple `lastUpdateTag` + Google 60s notification de-dup guard the rest.

---

## §10 — Phase 6 backend layout + synthesis

```
app/Services/Wallet/{AppleWalletPassService,GoogleWalletService,WalletUpdateService}.php
app/Http/Controllers/Api/V1/Frontend/WalletController.php   # GET /apple, GET /google
app/Http/Controllers/WalletWebServiceController.php          # Apple webServiceURL endpoints
app/Listeners/PushWalletUpdateOnBalanceChange.php            # on LoyaltyPointsAwarded / Redeemed
database/migrations/2026_XX_XX_create_wallet_apple_registrations_table.php  (with branch_id)
config/wallet.php                                            # cert paths, issuer_id, sa_email
storage/certs/{apple/Certificates.p12, apple/WWDR-G4.pem, google/service-account-key.pem}
routes/api.php : wallet routes under auth:sanctum + ability:wallet:loyalty
```

**Synthesis for orchestrator** :
- V0 work (this audit + Agent 6) : SVG assets, modal, handler, data-shape file, plan doc — ~2-3h.
- Phase 6 work : ~1 week post owner cert provisioning. Owner gates blocking Phase 6 :
  - D4-A : Apple Developer Program + Pass Type ID
  - D4-B : Google Cloud project + Wallet Console + Issuer ID
  - D4-C : V1-vs-V1.1 scope decision (couples with D1 Supabase/FoodKing)
- **Zero frozen-zone touch** : all new files under `app/Services/Wallet/` + listeners + new migration. No risk to NF525, BranchScope, IdempotencyKey middleware, pricing SSOT.
- **CVE-2025-45769** : NOT APPLICABLE (RS256 only, DISPUTED at NVD). Use `firebase/php-jwt:^6.10` ; 7.x as defense-in-depth.

— *Fin Agent 5. Voir 99_VERDICT.md pour priorisation cross-agent.*
