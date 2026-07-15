# Loyalty SYNC evidence — 2026-07-09

## 1. Test suite
`php artisan test --filter=Loyalty` → **83 passed** (0 fail), 13.67s.
Includes LoyaltyQrSigningSentinelTest: valid signed scan OK, qr_expired reject, qr_invalid_signature (tampered hmac), qr_replay reject, legacy fk accepted-with-header, boot-guard refuses empty secret. Full log: loyalty-test-suite.txt

## 2. Balance source SHARED (web === mobile === backend)
- WEB   /Users/1millnonstop/Downloads/web/api.js:468-469  profile() → GET /api/profile
- MOBILE mobile/api/client.js:101-102  profile() → GET /api/profile
- BACKEND route: `GET|HEAD api/profile → Frontend\ProfileController@profile`
  - ProfileController.php:27 returns `new UserResource(auth()->user())`
  - UserResource.php:35 `"loyalty_points" => (int)($this->loyalty_points ?? 0)`, :34 loyalty_code
=> Both clients read the identical endpoint; balance is the single User.loyalty_points column.

## 3. Redeem carries branch_id (P1 heal)
- WEB   api.js:496-500  POST /api/frontend/loyalty/redeem body {code, points, branch_id: CFG.branchId}
- MOBILE api/client.js:123-129  POST /api/frontend/loyalty/redeem body {branch_id: CFG.branchId, code, points}

## 4. QR signed lqr. mint-on-display TTL 300s + replay/forge/expired rejected
- LoyaltyQrSigner.php:39 TOKEN_PREFIX = 'lqr.'; :16 format lqr.<payload>.<hmac>
- TTL: config/loyalty.php:39 ttl_seconds env LOYALTY_QR_TTL_SECONDS default 300; signer :206 default 300
- signer.verifyAndConsume:
  - :104 malformed / wrong prefix guard
  - :119-120 hash_equals fail → throw qr_invalid_signature (forge)
  - :137 exp passed → throw qr_expired
  - :154 UNIQUE nonce violation → throw qr_replay (consume-once)
- scan controller LoyaltyController.php:709-720 maps LoyaltyQrInvalidException.errorCode into response
- legacy rejection: LoyaltyController.php:724-732 qr_legacy_rejected when accept_legacy_plaintext=false
- Boot guard: config rejects empty/sentinel/<32char secret in prod (config/loyalty.php:80-98)
