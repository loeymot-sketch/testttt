# OWNER GATE RUNBOOK — GOAL 100% (FoodKing / Le Cayenne, E.DELICE SAS)

**Date:** 2026-06-07 · Owner-Gate Runbook agent (research + scripting only — NOTHING applied, NOTHING pushed)
**Branch (release / deployed HEAD):** `heal/pre-cloud-exec-2026-06-05`
**Scope:** the 6 owner gates that are the ONLY remaining path to 100%-on-device per `CONVERGENCE_VERDICT.md`.
**How to read this:** each gate gives you a **RECOMMENDED value/decision** (with legal citation), the **EXACT command(s)** (copy-paste), the **verification step**, and **what only you (owner) can decide**. Execute top-to-bottom. Nothing here mutates the operating DB or pushes — Claude prepared it; you pull the trigger.

> ⚠️ TWO HARD FOOTGUNS that govern this whole runbook:
> 1. **NEVER `php artisan test`** on this box — it can wipe the operating `foodking` DB via `.env.testing` → `RefreshDatabase` (the shared-infra footgun, MEMORY DEVDB-GUARD). Use **`vendor/bin/phpunit`** for every test below.
> 2. **Before any write command, read the printed `DB: …` line** — it must say the operating DB (`foodking` on the OVH box), not a test/clone DB.

---

## GATE ORDER (recommended execution sequence)

| # | Gate | Why this order |
|---|------|----------------|
| 1 | **SEC-SECRET-01** | Do FIRST if you intend any public push (rotate → purge → force-push). Blocks safe publication. |
| 2 | **G3** | Footer wording — feeds G4. Decide the text first. |
| 3 | **G7** | VAT policy decision — confirm 10%-only or create a 5,5% row. Independent of others. |
| 4 | **G4** | Apply legal identity (uses G3 footer). Branch-level, run once. |
| 5 | **G5** | Merge the auto-print branch (heal already applied). Re-validate. |
| 6 | **hardware** | Printer IP + cross-device sync — only after G5 merged (printer command lives on `feat`). |

---

# G3 — Legal footer wording (NF525 mandatory mentions)

## Legal basis (researched, cited)
A receipt/note handed to a consumer in a VAT-registered restaurant is a **fiscal document**. Mandatory mentions (Code de commerce art. L.441-9 / L.441-3; CGI art. 289 & 242 nonies A ann. II; arrêté NF525 / loi anti-fraude TVA 2018):
- **Raison sociale + forme juridique** (E.DELICE **SAS**), **adresse complète**, **SIRET**, **n° TVA intracommunautaire**
- **Date + heure** de la transaction, **n° de ticket / séquence** (≈ `fiscal_sequence_no`)
- **Désignation** des articles, **quantité**, **prix unitaire TTC**
- **Ventilation HT / TVA par taux** + **total TTC**, **mode de paiement**
- Restaurant **assujetti à la TVA** → **logiciel de caisse certifié** (NF525) requis. The legal obligation is to *hold the attestation*, not necessarily to *print* a "NF525" line — printing it is common practice, not mandatory.

**`TVA non applicable, art. 293 B du CGI` → DOES NOT APPLY.** That is the *franchise en base* (micro-entreprise) wording; E.DELICE SAS bills VAT-10 and is VAT-registered, so this mention would be self-contradictory and is explicitly excluded (this is exactly the bug HEAL-H5 fixed).

> The receipt header fields (SIRET, TVA, fiscal n°, date/heure, HT/TVA ventilation, total TTC, payment mode) are already rendered by the app from `branches` + the order. **`legal_footer` is ONLY the free-text mentions line at the bottom.** G3 = decide that bottom line.

## ⚠️ The footer feeds TWO surfaces (don't under-spec it)
Post-HEAL-H1, the same `legal_footer` renders on **(a)** the POS thermal ticket AND **(b)** the order-history "**Imprimer La Facture**" (both share `buildNf525Footer()` — `resources/js/helpers/posReceiptBuilder.js:113`, fed by `ReceiptDataService` `pos_legal_footer`). A **note/ticket** can be light, but a SAS **facture** legally requires more (forme juridique + **RCS [ville] [SIREN]** + **capital social**; CGI art. 242 nonies A, Code de commerce art. R.123-237). If you ever hand a real *facture* (B2B / on request), the footer should carry these.

## RECOMMENDED footer (VAT-registered SAS) — owner must fill the bracketed gaps
Claude does NOT have your RCS ville or share capital, so they are templated, NOT invented:

```
E.DELICE SAS au capital de [____] € — RCS [____] [SIREN 104170501] — SIRET 10417050100019 — TVA FR19104170501 — Logiciel de caisse certifié NF525 — Merci de votre visite
```

**Minimal ticket-only variant** (if you want a short footer and reserve the full legal block for the facture):
```
E.DELICE SAS — SIRET 10417050100019 — TVA FR19104170501 — Logiciel certifié NF525 — Merci de votre visite
```

> The command's built-in default (`SetBranchLegalCommand::DEFAULT_LEGAL_FOOTER = 'TVA intracommunautaire - Merci de votre visite'`) is a **safe placeholder only** — it is NOT the legally-signed text. Replace it via `--legal-footer` (G4).

## The 2023 "receipts on demand" rule (AGEC) — a DISPLAY obligation, not a ticket mention
Since **1 août 2023** (loi AGEC du 10/02/2020), automatic ticket printing ended: tickets print **only on the customer's request**, and the merchant must put up an **affichage** at the point of payment informing customers of this. **This is NOT a line on the ticket** — it's a notice at the till + a print-on-demand default.
- **Owner action:** display the notice at the caisse/borne ("Vos tickets ne sont imprimés que sur demande").
- **Restaurant exception (important):** **notes/additions in restauration** AND any sale **≥ 25 € TTC** must still be provided. This is why the POS **auto-print** path (G5) is **compliant, not over-printing** — printing a fiscal ticket per restaurant order falls squarely within the exception.

Sources: [economie.gouv.fr](https://www.economie.gouv.fr/ticket-caisse-obligation-professionnels-reglementation-consommateurs) · [info.gouv.fr](https://www.info.gouv.fr/actualite/le-ticket-de-caisse-remis-sur-demande-du-consommateur-des-le-1er-aout-2023).

## What ONLY the owner can decide (G3)
- The **share capital** amount and **RCS ville** (these are real registry facts — do not guess).
- Whether to print a short ticket footer + full facture footer, or one footer for both.
- Final validation of the exact wording with your accountant (this is signed fiscal text).

## Verify (G3)
Footer chosen → it is applied in **G4** and you verify it on a printed ticket in G4's verification step.

---

# G7 — French VAT rates for fast-food → category→tax_id mapping

## Legal basis (researched, cited — restauration rapide, halal so NO alcohol)
Three rates coexist (CGI art. 278-0 bis / 279 / 278): **5,5 %** (reduced), **10 %** (intermediate), **20 %** (standard). The discriminator for takeaway is **immediate consumption vs conservable/packaged**, NOT the place of consumption:

| Product | On-site | Takeaway | Rate |
|---|---|---|---|
| Hot prepared dish (tacos, burger, hot sandwich) | 10% | **10%** (immediate consumption) | **10%** |
| Cold sandwich / salad (consumed now, with cutlery) | 10% | **10%** | **10%** |
| Frites / hot sides | 10% | **10%** | **10%** |
| Bowl (assembled to order, immediate) | 10% | **10%** | **10%** |
| Dessert/glace **à l'unité** (cornet, pot individuel) | 10% | **10%** | **10%** |
| Soft drink in **cup / fountain** (non-refermable, immediate) | 10% | **10%** | **10%** |
| Soft drink in **sealed bottle/can** (refermable, conservable) | — | **5,5%** | **5,5%** |
| Bottled water (sealed) | — | **5,5%** | **5,5%** |
| Cold packaged item for conservation (sous-vide, family pot) | — | **5,5%** | **5,5%** |
| Alcohol | 20% | 20% | N/A — **halal, none sold** |

**Bottom line for Le Cayenne:** if every drink is **fountain/cup** and there are no sealed bottles/cans or packaged-for-conservation items, **everything is 10%** — which is exactly the live state (45/45 items on `tax_id=3`). That is the clean, correct mapping.

## The taxes table as it exists (verified `TaxTableSeeder.php`)
| id | name | code | rate | use |
|----|------|------|------|-----|
| 1 | No-VAT | VAT-0 | **0%** | the 8 intentional 0% suppléments (ids 4–11) |
| 2 | VAT | VAT-5% | **5,0%** | ⚠️ **NOT a legal food rate** — see warning |
| 3 | VAT | VAT-10% | **10%** | the 45 live menu items |
| 4 | GST | GST-5% | 5,0% | non-FR, unused |
| 5 | GST | GST-10% | 10,0% | non-FR, unused |

## ⚠️ CORRECTNESS BLOCKER — there is NO 5,5% row
`id2` is **5,0 %**, not 5,5 %. The legal reduced rate is **5,5 %**. **Do NOT map any conservable/bottled product to `id2`** — 5,0% **under-collects** vs the legal 5,5% (a fiscal under-declaration for a VAT-registered biz). If you sell any sealed bottle/can or conservable cold item, a **new 5,5% taxes row must be CREATED first** (data change, owner gate), then bind those SKUs to it.

## RECOMMENDED category → tax_id mapping
| Le Cayenne category | Recommended tax_id | Rate | Note |
|---|---|---|---|
| Sandwiches | **3** | 10% | hot/immediate |
| Tacos | **3** | 10% | hot/immediate |
| Bowls | **3** | 10% | assembled/immediate |
| Burgers | **3** | 10% | hot/immediate |
| Frites | **3** | 10% | hot/immediate |
| Desserts (à l'unité) | **3** | 10% | individual portion |
| Boissons — fountain/cup | **3** | 10% | non-refermable / immediate |
| **Boissons — sealed bottle/can** (IF SOLD) | **NEW 5,5% row** | 5,5% | ⛔ owner must CREATE row first; do NOT use id2 |
| Suppléments (8 items, ids 4–11) | **1** (if free) / **3** (if priced) | 0% / 10% | see below |

## The 8 suppléments at tax_id=1 (0%) — owner decision
The discriminator is **price**:
- **Free add-on (price 0)** → 0% is moot (no taxable amount). Leave as `tax_id=1`.
- **Priced supplément** → selling it at 0% **under-collects** for a VAT-registered business → should be `tax_id=3` (10%). This is the exact silent-0% hole the LOCK (`LOCK_PRICINGSERVICE_NULL_TAX_FAILLOUD`) guards against. Confirm each priced supplément is 10%, not 0%.

## The 6 soft-deleted ghosts (NULL tax_id)
ids 16,28,29,30,31,32 (Bacon + 5 Bols Gourmands) are soft-deleted, unsold, `tax_id=NULL`. They cannot be priced live today (PricingService doesn't `withTrashed`). The LOCK §0bis interim + the optional cleanup bind below handle the latent risk.

## EXACT commands (G7) — VERIFY BEFORE RUN, operating DB only
**(a) Confirm live catalogue is clean (read-only):**
```bash
# On the OVH box, operating foodking. Read-only inspection.
php artisan tinker --execute="echo \DB::connection()->getDatabaseName().PHP_EOL; \
  echo 'NULL tax (live): '.\App\Models\Item::whereNull('tax_id')->count().PHP_EOL; \
  echo 'NULL tax (incl trashed): '.\App\Models\Item::withTrashed()->whereNull('tax_id')->count().PHP_EOL; \
  \App\Models\Item::withTrashed()->whereNull('tax_id')->get(['id','name','deleted_at'])->each(fn(\$i)=>print(\$i->id.' '.\$i->name.' del='.\$i->deleted_at.PHP_EOL));"
```
Expect: `NULL tax (live): 0`, and the incl-trashed list = ONLY the 6 known ghosts.

**(b) Optional defensive cleanup** — bind the ghosts to VAT-10 so a future restore can't sell silent-0% (run ONLY after (a) shows the matches are exactly the 6 ghosts):
```bash
php artisan tinker --execute="\$n=\App\Models\Item::withTrashed()->whereNull('tax_id')->update(['tax_id'=>3]); echo \"rebound \$n ghost item(s) to tax_id=3\".PHP_EOL;"
```

**(c) Close the new-item ingress (LOCK §0bis interim, non-frozen, no gate needed):** edit `app/Http/Requests/ItemRequest.php:50` `'tax_id' => ['nullable',...]` → `['required','numeric','not_in:0','exists:taxes,id']` (after confirming the item-UPDATE form always posts `tax_id`; run `vendor/bin/phpunit --filter Item` after).

**(d) The frozen PricingService fail-loud fix** = countersign `plans/LOCK_PRICINGSERVICE_NULL_TAX_FAILLOUD_2026-06-07.md §10` (Option F recommended). Not applied here — that LOCK is the durable fix.

## What ONLY the owner can decide (G7)
- **Do you sell any drink in a sealed bottle/can, or any conservable cold/packaged item?** If yes → create a real **5,5% taxes row** and rebind those SKUs (do NOT use id2). If no → 10%-only is confirmed correct.
- Whether the 8 suppléments are **free (0% OK)** or **priced (must be 10%)**.
- Sign-off on the LOCK option (F vs T vs interim-only) for the frozen PricingService fix.

## Verify (G7)
After (a): `NULL tax (live) = 0`. After any binding: re-run (a), then a sim order on the e2e clone prices the affected category at the expected rate; `vendor/bin/phpunit --filter Pricing` green.

---

# G4 — Apply NF525 legal identity per branch (`foodking:set-branch-legal`)

## What it is (verified `app/Console/Commands/SetBranchLegalCommand.php`)
Idempotent command writing `branches.{siret, vat_intra, register_id, legal_footer}` for one branch. Validates SIRET=14 digits and TVA=`FR`+11 chars (rejects bad format, no write). Touches the `branches` table ONLY — never the fiscal chain, orders, or a frozen service. These fields feed the printed ticket via `ReceiptDataService` (SSOT for both Vue receipt and ESC/POS renderer).

## ⚠️ It is BRANCH-level, not literally per-device
The command writes the `branches` row for `branch_id=1`. On a **shared DB** (V1 single-box / one OVH server), **run it ONCE** — every device reads the same branch row. "Per device" in the verdict conflated this with **printer config** (which *is* per device — see hardware gate). If you ever run truly separate DBs per device, run it once per DB.

## EXACT command (G4) — real E.DELICE SAS values, signature-verified
```bash
# On the OVH box, operating foodking DB. Replace <REGISTER_ID> and <G3 FOOTER>.
php artisan foodking:set-branch-legal \
  --branch=1 \
  --siret=10417050100019 \
  --vat-intra=FR19104170501 \
  --register-id="CAISSE-01" \
  --legal-footer="E.DELICE SAS — SIRET 10417050100019 — TVA FR19104170501 — Logiciel certifié NF525 — Merci de votre visite"
```
(Format checks pass: SIRET `10417050100019` = 14 digits ✓; `FR19104170501` = FR+11 ✓. Use your final G3 footer text.)

## Verify (G4)
1. The command prints a **before/after table** and a `DB: <name>` line — **confirm `DB:` = the operating `foodking`**, not a clone/test DB.
2. Re-run the same command → it prints "**No change (idempotent re-run)**" = the row is at target.
3. Read-only confirm:
   ```bash
   php artisan tinker --execute="\$b=\App\Models\Branch::withTrashed()->find(1); echo \$b->siret.' | '.\$b->vat_intra.' | '.\$b->register_id.PHP_EOL.\$b->legal_footer.PHP_EOL;"
   ```
4. **Print a real ticket** (after G5 + printer up): confirm the thermal ticket shows SIRET, TVA FR19104170501, the footer line, the fiscal sequence n°, and HT/TVA ventilation.

## What ONLY the owner can decide (G4)
- The `register-id` value (your caisse identifier).
- Supplying the final footer (G3) and confirming the SIRET/TVA are E.DELICE's real registry values.
- Running it against the real operating DB (Claude must not browser-write the operating fiscal data).

---

# G5 — Merge `feat/pos-printer-saga-autoprint` (auto-print) into the release branch

## State (verified)
- Target = **`heal/pre-cloud-exec-2026-06-05`** (current deployed HEAD), **NOT main**.
- `feat/pos-printer-saga-autoprint` HEAD = **`b27365295`** (the H7-on-paper heal) on top of `e446a2084` (the auto-print feature). 2 commits ahead of the merge-base.
- The required pre-merge heal (ESC/POS discounted-ticket TVA netting) is **already applied + proven on `feat`** (`G5-HEAL-PREP.md`: receipt suite 22/22, broader 47/47, frozen sentinel 1/1, ticket TVA 1,37 == signed Z). Patch for review: `reports/test-e2e/goal-100pct-2026-06-07/G5-DISCOUNT-HEAL.patch`.
- **Read-only merge preview ran clean** (`git merge-tree` from the merge-base showed **no textual conflicts**).

## EXACT commands (G5) — local merge, NO push
```bash
# 0. Be on the release branch, clean tree.
git checkout heal/pre-cloud-exec-2026-06-05
git status   # must be clean (or stash) before merging

# 1. (optional) re-confirm no conflicts read-only:
git merge-tree $(git merge-base heal/pre-cloud-exec-2026-06-05 feat/pos-printer-saga-autoprint) \
  heal/pre-cloud-exec-2026-06-05 feat/pos-printer-saga-autoprint | grep -iE "conflict|<<<<<<<" || echo "NO CONFLICTS"

# 2. Merge (no fast-forward so the merge is an explicit, revertable commit):
git merge --no-ff feat/pos-printer-saga-autoprint \
  -m "merge(G5): auto-print NF525 receipt SAGA SGPR-200II + discounted-ticket TVA netting heal (b27365295)"

# 3. Confirm the heal commit is now in:
git branch --contains b27365295   # must list heal/pre-cloud-exec-2026-06-05

# 4. Frozen-zone diff must be empty of §7 files:
git diff --stat e446a2084^..HEAD -- \
  app/Services/Pricing/PricingService.php \
  app/Services/Fiscal/FiscalSequenceService.php \
  app/Services/Fiscal/ZReportService.php   # expect: no output
```

## Post-merge re-validation checklist (use `vendor/bin/phpunit` — NEVER `php artisan test`)
```bash
vendor/bin/phpunit tests/Feature/Receipt                                   # 22/22 (incl 4 EscPos netting)
vendor/bin/phpunit --filter 'EscPos|Receipt|PosReceipt|PrintPosReceipt'    # 47/47
vendor/bin/phpunit --filter FrozenZoneSha256BaselineSentinel               # 1/1
vendor/bin/phpunit --filter Print                                          # the auto-print listener/claim tests
APP_ENV=e2e php artisan fiscal:verify-chain --all                          # CHAIN OK
```
**Discounted-ticket sim (the heal's whole point):** the `EscPosDiscountTvaNettingTest` already proves per-rate TVA nets to the signed-Z value (1,37 == Z, gross 1,82 absent from bytes). If you want an extra manual sanity check, run a discounted order through the renderer in tinker and confirm the decoded bytes show the netted `HT 13,64 € / TVA 1,37 €`.

**After merge: run a formal re-audit** — the merge changes the live print path (`CONVERGENCE_VERDICT.md` recommends this explicitly). Suggested: `/brain` or a `test-e2e` pass focused on the receipt/print surface.

## What ONLY the owner can decide (G5)
- Performing the merge into the deployed branch (frozen-adjacent print path → owner gate).
- Whether to push afterward (push is a separate owner gate — and gated behind **SEC-SECRET-01** if pushing publicly).

---

# SEC-SECRET-01 — Purge the leaked AWS key (and co-leaked secrets) from git history

## What's actually in history (verified — broader than the verdict's one-liner)
The AWS key `AKIA<REDACTED-see-commit-9b1e741f4>` was committed and is in **permanent history** in **two secret files** plus **~17 audit/report docs that quote it verbatim**:
- `.env` (commit `9b1e741f4`)
- `.env.backup-pre-round2` (commit `a4a88df06`) — **this file ALSO leaked**: `AWS_SECRET_ACCESS_KEY`, `APP_KEY`, **`FISCAL_AUDIT_SECRET`**, **`FISCAL_Z_REPORT_SECRET`**, `DB_PASSWORD`, `MAIL_PASSWORD`, `PUSHER_APP_SECRET`.
- 17 doc files under `reports/audit/...`, `reports/test-e2e/...` that print the key literal (e.g. `reports/audit/cto-global-2026-05-16/*.md`) — **some are still in current HEAD**.

> ⛔ **Path-only removal is NOT enough.** `filter-repo --invert-paths --path .env` deletes the two env files but **leaves the key string in all 17 docs**. You must **redact the literal across ALL history** with `--replace-text`, AND remove the env-backup files.

## Rotation ≠ purge — and rotation differs per secret (READ THIS)
Purging the *string* from history is safe (doesn't touch runtime). **Rotating the live value is not a checkbox** and diverges:
- **AWS access key** → rotate/disable in AWS IAM console (owner). Safe to rotate; nothing in V1 depends on it at runtime (V1 is local, no-cloud).
- **APP_KEY** → rotating **invalidates existing sessions / encrypted cookies** (manageable: users re-login). Decide deliberately.
- **`FISCAL_AUDIT_SECRET` / `FISCAL_Z_REPORT_SECRET`** → ⛔ **rotating BREAKS the NF525 HMAC chain** — the existing signed `audit_logs` / `z_reports` were computed with the current secret; a new secret makes `fiscal:verify-chain` FAIL on all historical rows. **Do NOT rotate the fiscal secrets** unless you fully understand the chain-rebuild consequence (you almost certainly should keep them and rely on the purge + the fact they were never deployed publicly). Purging the *string* from history is still correct and safe.
- DB_PASSWORD / MAIL_PASSWORD / PUSHER_APP_SECRET → rotate at the respective service (owner), low blast radius.

## ORDER (mandatory): ROTATE → PURGE → FORCE-PUSH
1. **ROTATE FIRST** (owner, AWS console + services) — so even if history leaks before purge, the keys are already dead. (Fiscal secrets: purge-string-only, do NOT rotate — see above.)
2. **PURGE** history locally (commands below).
3. **FORCE-PUSH** only after purge.

## EXACT command sequence (SEC-SECRET-01 — do NOT run until owner has rotated; Claude has NOT run this)
> ⚠️ **Run the rewrite against a fresh MIRROR clone, not the live working repo.** This repo backs ~20 worktrees; running `git filter-repo` in the working tree can corrupt worktree state. Do the rewrite in the mirror, verify, then force-push from the mirror; afterward every clone/worktree re-clones (see coordination note below).
```bash
# --- 0. Make a fresh mirror clone and do ALL the rewriting THERE (never the working repo) ---
git clone --mirror /path/to/testttt /path/to/testttt-purge.git
cd /path/to/testttt-purge.git
# (keep a second copy as an untouched backup too)
cp -R /path/to/testttt-purge.git /path/to/testttt-backup-$(date +%Y%m%d).git

# --- 1. Build the redaction list (key NAMES shown; you paste the real literals) ---
#     Extract the leaked literals from the backup file to fill replacements.txt:
git show a4a88df06:.env.backup-pre-round2 | grep -E '^(AWS_ACCESS_KEY_ID|AWS_SECRET_ACCESS_KEY|APP_KEY|FISCAL_AUDIT_SECRET|FISCAL_Z_REPORT_SECRET|DB_PASSWORD|MAIL_PASSWORD|PUSHER_APP_SECRET)='
#     Create replacements.txt with ONE line per leaked literal value:
#       AKIA<REDACTED-see-commit-9b1e741f4>==>REDACTED
#       <the-aws-secret-literal>==>REDACTED
#       <the-app-key-literal>==>REDACTED
#       <the-fiscal-audit-secret-literal>==>REDACTED
#       <the-fiscal-z-secret-literal>==>REDACTED
#       <db-password-literal>==>REDACTED
#       <mail-password-literal>==>REDACTED
#       <pusher-app-secret-literal>==>REDACTED

# --- 2. git filter-repo: redact literals EVERYWHERE + drop the env-backup files ---
#     (install: pip install git-filter-repo)
git filter-repo \
  --replace-text replacements.txt \
  --invert-paths --path .env --path .env.backup-pre-round2 \
  --force

# --- 3. Verify the key is GONE from ALL history (must print nothing) ---
git log --all -S 'AKIA<REDACTED-see-commit-9b1e741f4>' --oneline
git grep 'AKIA<REDACTED-see-commit-9b1e741f4>' $(git rev-list --all) || echo "CLEAN"

# --- 4. Re-add the remote (filter-repo strips it) and FORCE-PUSH (owner gate) ---
git remote add origin <repo-url>
git push origin --force --all
git push origin --force --tags
```
**BFG alternative** (if `git filter-repo` unavailable): `bfg --replace-text replacements.txt` then `bfg --delete-files '{.env,.env.backup-pre-round2}'` (delete BOTH env files, not just the backup), then `git reflog expire --expire=now --all && git gc --prune=now --aggressive`, then force-push. `filter-repo` is preferred (BFG won't touch the most-recent commit and is path-coarser).

## ⚠️ Coordinate with ALL clones before force-push
History rewrite changes every SHA. After force-push:
- The **OVH deploy clone** (`loeymot-sketch/testttt`, `ssh lecayenne`) and **~20 shared worktrees** all diverge — each must **re-clone** (or hard-reset to the rewritten history). Pulling normally will conflict/duplicate.
- Notify anyone with a clone BEFORE the force-push.

## What ONLY the owner can decide (SEC-SECRET-01)
- AWS IAM console rotation (Claude cannot touch AWS or `.env`).
- The deliberate choice to **NOT rotate the fiscal secrets** (chain-preserving) vs accept a chain rebuild.
- Running the destructive history rewrite + force-push (CLAUDE.md §10 human gate — push to protected/public branch).

---

# hardware — Printer config + cross-device sync bring-up

> ⚠️ The `pos:configure-receipt-printer` command lives on **`feat/pos-printer-saga-autoprint`** — it is usable **only AFTER G5 is merged**. Do G5 first.

## Printer command (verified on `feat` — `app/Console/Commands/ConfigurePosReceiptPrinterCommand.php`)
Signature: `pos:configure-receipt-printer {ip} {--port=9100} {--branch=1} {--station=...} {--width=48} {--code-page=...} {--name=...} {--no-test}`. It `updateOrCreate`s the `printers` row (type `escpos_tcp`, host=ip, port, width, code_page) and fires a test print unless `--no-test`.

### EXACT command (after G5 merge, on the device wired to the printer)
```bash
# Replace 192.168.1.50 with the SAGA SGPR-200II's real LAN IP (static / DHCP-reserved).
php artisan pos:configure-receipt-printer 192.168.1.50 --port=9100 --branch=1
# It prints a config table + a test ticket. If the test paper comes out → wired correctly.
# Add --no-test to skip the immediate test (auto-print fires on the next counter-paid order).
```

### Printer network requirements
- **ESC/POS over RAW TCP, port 9100** (JetDirect). The printer must be on the **same LAN** as the box, reachable by IP.
- **Static IP or DHCP reservation** (a changing IP breaks the configured `host`).
- Quick reachability check before configuring:
  ```bash
  ping -c2 192.168.1.50
  nc -zv 192.168.1.50 9100    # "succeeded" = port open
  ```
- This is **per physical device** that owns a printer (unlike G4 which is once per branch/DB).

## Cross-device real-time sync bring-up checklist
The verdict's `(hardware)` gate = borne/caisse/KDS on separate machines syncing in real time. Round-1 **SYNC-INFRA-01** (`round-1/01-SYNC.md:10`) proved the producer→outbox→soketi→subscriber path is **product-correct**, but on the test box **the only queue worker was bound to the operating DB and both envs shared the redis prefix** → live-push showed as PARTIAL (fell back to polling). On the real setup you need the workers actually running. MEMORY (OVH deploy) notes **supervisor/workers are not yet up** and browser `ws:6001` currently falls back to polling (SYNC-WS-01).

Bring-up steps (operating box):
1. **soketi (WebSocket server) reachable** — running + the port the clients use (`:6001` per SYNC-WS-01) open on the LAN/host. Test from a device: the kiosk/POS WS connects (no `ws:6001` connection error in console).
2. **Redis up** and `REDIS_*` env correct — the queue + broadcast backbone.
3. **Queue worker(s) actually running** — start/supervise `php artisan queue:work` (the `DispatchDomainEventsJob` that pushes `OrderStatusChanged` to soketi runs on the worker). MEMORY: supervisor/workers were **dormant on OVH** — bring them up (supervisor or systemd). Without a running worker, events sit in the outbox and clients only get them via the ~60s polling fallback, not real-time.
4. **Per SYNC-INFRA-01**: if you run an isolated test env alongside, give it a **distinct `REDIS_DB`/prefix** so a test worker doesn't consume operating jobs (and vice-versa). For production single-box, just ensure the one operating worker is up.
5. **Verify live**: change an order status on device A (borne) → it appears on device B (KDS) and C (caisse) within ~1s (WS path) or ≤60s (polling fallback). The CONVERGENCE_VERDICT validated this on a single browser context; the genuinely-new thing on hardware is the multi-machine confirmation + the worker being up.

## What ONLY the owner can decide / do (hardware)
- The printer's **real LAN IP** + static/DHCP reservation.
- Physically wiring the SAGA SGPR-200II and confirming **paper actually comes out**.
- Bringing up **supervisor/queue workers + soketi** on the OVH box (currently dormant per MEMORY).
- Multi-device cross-sync confirmation (only the real 3-machine setup proves X-1).

---

# COVERAGE CONFIRMATION
This runbook covers all **6 gates**: **G3** (footer wording + legal basis + SAS-facture nuance) · **G7** (VAT mapping + the no-5,5%-row blocker + supplément/ghost handling) · **G4** (set-branch-legal exact invocation, branch-level once) · **G5** (clean merge into the named release branch + phpunit re-validation) · **SEC-SECRET-01** (full literal-redaction purge + per-secret rotation rules + clone coordination) · **hardware** (printer command post-G5 + ESC/POS:9100 reqs + worker/soketi sync bring-up).

**Nothing was applied, committed, or pushed. Supervisor commits this runbook.**

---
## Sources (French legal research)
- [economie.gouv.fr — ticket de caisse remis à la demande du client (loi AGEC, restaurants = exception)](https://www.economie.gouv.fr/ticket-caisse-obligation-professionnels-reglementation-consommateurs)
- [info.gouv.fr — ticket de caisse sur demande dès le 1er août 2023](https://www.info.gouv.fr/actualite/le-ticket-de-caisse-remis-sur-demande-du-consommateur-des-le-1er-aout-2023)
- [Legalstart — ticket de caisse restaurant : mentions obligatoires + logiciel certifié NF525](https://www.legalstart.fr/fiches-pratiques/hotellerie-restauration/ticket-caisse-restaurant/)
- [Legalstart — mentions obligatoires ticket de caisse](https://www.legalstart.fr/fiches-pratiques/facturation/ticket-caisse-mention-obligatoire/)
- [Indy — TVA restauration à emporter : 5,5% / 10% / 20%, consommation immédiate vs conditionné](https://www.indy.fr/guide/fiscalite/taxes/tva/restauration-a-emporter/)
- [Baker Tilly — taux de TVA restauration 2026](https://www.bakertilly.fr/actualites/chr-quels-sont-les-taux-tva-applicables-secteur-restauration)
- [Cegid — facture de restaurant 2026 (mentions, ventilation HT/TVA)](https://www.cegid.com/fr/blog/facture-restauration/)
