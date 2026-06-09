# GATE (owner design decision) — W5 personal-page in-builder RE-EDIT

**Status**: OPEN — owner decision required. **Not a ship blocker.** The shipped behaviour (create-only
conservative guard) is safe and green; this gate only governs whether/how to *restore* in-builder
re-editing of a personal page's options.

**Branch**: `heal/pre-cloud-exec-2026-06-05` · NO push · **Date**: 2026-06-08.

---

## TL;DR

The W5 "Créer une page personnalisée" builder lets an admin create a catalog `ItemExtra` group on the
fly. We wanted re-submitting the same label to **update** the options idempotently. Every mechanism we
tried to make that safe was empirically broken by an adversarial pass (3 rounds). The robust fixes are
all **architectural**, so per CLAUDE.md §10 (healing cap = 3 cycles → escalate, don't auto-pick) the
feature ships with a **create-only conservative guard** (cannot overwrite any existing catalog group),
and the re-edit enhancement is handed to the owner as a design choice.

## What ships now (safe, green)

`ComposerProfileController::createPersonalPage` rejects (422) any label whose `group_label` already
exists on a target item. **Robust by construction**: the collision check runs *before* the transaction,
so the `updateOrCreate` can only INSERT new rows — it can never overwrite a real catalog group's prices.
No provenance marker, no client-resendable ownership key, no bypass class.

- Create a new page → works (replicates across category items, price on the construct, NF525-clean).
- Re-POST the same label → **422** ("Le libellé … est déjà utilisé — choisissez un autre nom").
- Edit a created page's options today → via the normal **catalog / step editor** (`items_edit`), not the
  builder modal.

## Why the idempotent re-edit was removed (the 3 falsified attempts)

The hard problem: distinguish *"a personal-page group THIS builder created (re-edit OK)"* from
*"a pre-existing catalog group (must not overwrite)"*, using a signal that (a) survives the editor's
`steps()->delete()` + recreate flow and (b) a client cannot forge.

| Round | Mechanism | How it was broken (empirical) | Sev |
|---|---|---|---|
| 1 | Guard keyed on `source_type='extra_group' AND source_ref=label` ("does a step own this label?") | A **normal** composed step bound to a real catalog group via the source picker has the SAME shape → it disarmed the guard → personal page overwrote the real group (0.80→99.00, rogue option injected) | P1 (borderline P0) |
| 2 | `is_personal_page` provenance column on the step | The full-profile editor "Save draft" does `steps()->delete()` + recreate via `normalize()` (which omits the flag) → marker **erased** → author can no longer re-edit their own page (false 422) | P1 |
| 3 | Re-stamp the flag in `ComposerProfileService::update`, keyed on `source_ref` (then tried `step_key`) | Both keys are **client-resendable** & non-unique → an operator with `catalog.compose` but not `items_edit` can rebind a normal step's `source_ref`/`step_key` to re-arm the flag → catalog price overwrite, silent 201 (privilege escalation on multi-role; accidental overwrite on single-admin) | P0 / P1 |

Root cause: **step-level provenance is the wrong place** — the editor rebuilds steps destructively and
the rebuild payload is client-controlled. Each fix closed one path and opened another.

## Options to RESTORE safe re-edit (owner picks one; each is a real design decision)

- **(A) Re-edit by step identity, not by label.** The builder edits an existing personal page through a
  dedicated `PUT .../personal-page/{step}` keyed on the step's **primary key** (server-trusted), updating
  the bound `ItemExtra` rows in place. The create endpoint stays create-only. *Smallest UX change, no
  schema; needs a new endpoint + the modal to carry the step id when editing.*
- **(B) Provenance on the CONSTRUCT, not the step.** Add `item_extras.created_via='personal_page'`
  (nullable). Guard allows overwrite only when every existing row under `group_label` is
  `created_via='personal_page'`. Survives step rebuild (ItemExtras persist); forging `created_via`
  requires `items_edit` (which already grants direct price edit → no escalation). *Additive migration on
  a catalog table (NF525-adjacent surface) → owner sign-off.*
- **(C) Namespacing.** Store personal-page groups under a reserved `group_label` prefix that the normal
  catalog editor can't produce, so collisions with real groups are structurally impossible. *Changes
  display/label semantics; needs projection awareness.*
- **(D) Keep create-only (do nothing).** Accept that re-edit happens via the catalog/step editor. Zero
  new code, zero risk. *Recommended default until the builder genuinely needs in-modal re-edit.*

**Recommendation**: ship **(D)** now (already done); adopt **(A)** when in-modal re-edit is actually
requested — it keeps trust on the server-side step id and needs no catalog-table migration.

## Decision log

- 2026-06-08 — Claude: hit §10 healing cap (3 falsified rounds) on the ownership-detection cluster;
  reverted the marker/re-stamp approach; landed the conservative create-only guard (green, 0 frozen);
  escalated re-edit restoration to the owner via this gate. Awaiting owner choice of A/B/C/D.
