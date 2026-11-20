// Static OTP contract verifier. Run: node mobile/tests/mobile-otp.spec.js
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..', '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const onboarding = read('mobile/screens-onboarding.jsx');
const index = read('mobile/index.html');
const helper = read('tests/mobile-e2e/utils/realAuth.js');
const e2e = read('tests/mobile-e2e/auth-otp-local.spec.js');

assert.match(onboarding, /function ScreenOTP\(\{ phone, devCode, onNext, onResend, onBack \}\)/);
assert.match(onboarding, /useState_o\(30\)/, 'resend starts with a 30-second cooldown');
assert.match(onboarding, /data-testid="otp-resend"/);
assert.match(onboarding, /disabled=\{resendLoading \|\| resendCooldown > 0/);
assert.match(onboarding, /setResendCooldown\(30\)/, 'successful resend restarts the cooldown');
assert.match(onboarding, /data-testid="otp-resend-error"/);
assert.doesNotMatch(onboarding, /Renvoyer \(29s\)/, 'no static resend countdown remains');

assert.match(index, /onResend=\{async \(\) =>/);
assert.match(index, /api\.guestOtp\(authPhone\)/, 'resend uses the real guestOtp API');
assert.match(index, /otpResponse\.dev_code != null/);
assert.match(index, /setDevOtp\(''\)/, 'stale local codes are cleared before a new auth attempt');
const resendStart = index.indexOf('onResend={async () =>');
const resendEnd = index.indexOf("}} onBack={() => { setDevOtp(''); go('back'); }}/>; break;", resendStart);
assert.ok(resendStart >= 0 && resendEnd > resendStart, 'resend callback is bounded');
const resendSource = index.slice(resendStart, resendEnd);
const clearIndex = resendSource.indexOf("setDevOtp('');");
const requestIndex = resendSource.indexOf('const otpResponse = await api.guestOtp(authPhone);');
assert.ok(clearIndex >= 0 && clearIndex < requestIndex, 'devOtp is cleared before every resend request');
assert.doesNotMatch(resendSource, /if \([^\n]*dev_code != null[\s\S]*?else[\s\S]*?setDevOtp\(/, 'missing dev_code cannot restore a stale local code');
assert.match(resendSource, /if \(window\.LC && window\.LC\.isDev && otpResponse && otpResponse\.dev_code != null\)\s*\{\s*setDevOtp\(String\(otpResponse\.dev_code\)\);/,
  'only an explicitly returned dev_code becomes usable');
assert.doesNotMatch(index, /token:\s*['"](?:1234|0000)['"]/);

assert.match(helper, /loopbackApiBase/);
assert.match(helper, /parsed\.hostname/);
assert.match(helper, /otp\.devCode/);
assert.doesNotMatch(helper, /1234/);
assert.doesNotMatch(helper, /JSON\.stringify/);
assert.doesNotMatch(helper, /console\.(log|error|warn)/);
assert.match(helper, /API locale OTP sans dev_code/);
assert.match(e2e, /otp-resend/);
assert.match(e2e, /waitForTimeout\(30_500\)/, 'expiry is exercised rather than bypassed');
assert.match(e2e, /guest-signup\/otp/);

console.log('PASS: OTP resend cooldown, local dev_code boundary, loopback helper and focused E2E contract');
