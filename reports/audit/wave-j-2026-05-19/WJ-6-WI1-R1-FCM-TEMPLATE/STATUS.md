# WJ-6 WI-1 R1 P1 — FCM_SECRET_KEY template rename mismatch

**Status**: GREEN
**Discipline**: doc fix, low-risk, scope-minimal
**Wall-clock**: ~15 min
**Branch**: heal/cms-pr1-quickwins-2026-05-18
**Date**: 2026-05-19

---

## Bug (WI-1 R1)

- `docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt:197` declared `FCM_SECRET_KEY=`
- BUT `config/services.php:55` reads `env('FCM_SERVER_KEY')`
- Same class of bug on line 198: `FCM_TOPIC` vs config :57 `FCM_TOPIC_PREFIX`
- Impact: operator copies template verbatim → mobile push silently no-ops
  (no exception, no log, no fallback) because env() resolves to empty string

## Recon (read-only)

| File | Line(s) | Before | Expected (config) |
| --- | --- | --- | --- |
| `docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt` | 197 | `FCM_SECRET_KEY=` | `FCM_SERVER_KEY=` |
| `docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt` | 198 | `FCM_TOPIC=` | `FCM_TOPIC_PREFIX=` |
| `.env.example` | 182-184 | `FCM_SERVER_KEY=` / `FCM_SENDER_ID=` / `FCM_TOPIC_PREFIX=foodking` | already aligned — no change needed |
| `deploy/ansible/group_vars/vault.yml.example` | — | no FCM mention | out-of-scope (V1.0.X follow-up) |
| `config/services.php` | 55-57 | reads `FCM_SERVER_KEY` / `FCM_SENDER_ID` / `FCM_TOPIC_PREFIX` | SOT |

**Bonus gap surfaced**: production template was missing `FCM_SENDER_ID=`
entirely (config reads it). Added in the same edit for parity with
`.env.example` and `config/services.php`.

## Sentinel (TDD)

File: `tests/Feature/Sentinels/FcmTemplateNamingSentinelTest.php` (NEW)

Three tests:
1. `test_production_env_template_uses_fcm_server_key_not_fcm_secret_key`
2. `test_production_env_template_uses_fcm_topic_prefix_not_fcm_topic`
3. `test_env_example_aligned_with_config_services` (pin .env.example)

### RED (pre-fix)

```
Tests: 3, Assertions: 9, Failures: 2.
```

Failures on template (FCM_SERVER_KEY/FCM_TOPIC_PREFIX missing). The
`.env.example` assertion already PASSED — proves .env.example was clean.

### GREEN (post-fix)

```
PHPUnit 9.6.29 by Sebastian Bergmann and contributors.
...                                                                 3 / 3 (100%)
Time: 00:00.170, Memory: 87.00 MB
OK (3 tests, 11 assertions)
```

## Fix applied

`docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt:196-205` — renamed and added
explanatory header pointing to `config/services.php` as the SOT. Diff is
documentation-only.

## Constraints respected

- 0 frozen-zone touch (template is documentation, not on frozen list)
- 0 DIRTY file touch (template + .env.example + sentinel path all clean
  pre-edit per `git status --short`)
- Sentinel runs in <0.2 s, no external dependency

## Files touched

1. `docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt` (rename + header doc)
2. `tests/Feature/Sentinels/FcmTemplateNamingSentinelTest.php` (NEW)
3. `reports/audit/wave-j-2026-05-19/WJ-6-WI1-R1-FCM-TEMPLATE/STATUS.md` (this file)

## Commit

`fix(env-template-WJ-6-P1): rename FCM_SECRET_KEY → FCM_SERVER_KEY + FCM_TOPIC → FCM_TOPIC_PREFIX (WI-1 R1 pre-cloud)`

## Follow-ups (out of scope)

- `deploy/ansible/group_vars/vault.yml.example` does NOT mention FCM
  vars. If push is to be controlled via Ansible vault rather than
  per-host `.env`, add `fcm_server_key` / `fcm_sender_id` /
  `fcm_topic_prefix` to the example (V1.0.X backlog).
