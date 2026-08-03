# GOAL_FABLE5_ULTRA_V2_REVALIDATION_ABSOLUE — 2026-07-02

> **Réfère** `plans/GOAL_ULTRA_REVIEW_FULL_STACK_2026-07-02.md` (v1) pour disciplines / orchestration /
> barre 1000 % (inchangées). V2 = **révalidation ABSOLUE adversariale** : ne refait pas, **abuse plus fort**.

## §0 Posture (le renversement)
- **« 11/11 GREEN » = HYPOTHÈSE À RÉFUTER**, pas un acquis. Chaque « vert » doit survivre à une attaque active.
- **Leçon load-bearing : GREEN ≠ correct.** Preuves passées : les 2 bugs Uber (map vide, 200-on-fail) et le
  fix loyalty à moitié raté (vecteur PHONE oublié au 1er passage) étaient « verts » côté surface avant refute.
- **RE-PROUVER avant de reporter** (verify-before-report strict) : tout finding = repro LIVE (curl/tinker/DOM),
  sinon REJECTED. Tout « held-green » = attestation avec la commande d'attaque tentée.
- **Angles morts prouvés à NE PAS reproduire** : `kds_station` = mythe (colonne inexistante) ; ne pas
  re-signaler les 22 findings/6 réfutés/déférés déjà triés (v1 `HEAL_TRIAGE`) sauf NOUVELLE preuve.

## §1 Les 10 angles (par fonctionnalité, ≥2 cycles, jusqu'à 10 passes pour le critique)
1. **Correctness** — résultat exact (prix SSOT, compo, totaux, statuts).
2. **Failure-path** — entrées invalides / partielles / hostiles → dégrade proprement (422/409/410, pas 500).
3. **Security-abuse** — IDOR, mass-assign, token-scope, énumération, forge, XSS, secrets.
4. **Concurrency-idempotence** — double-POST, retry, race counter-collect/cash-drawer, X-Idempotency-Key.
5. **Data-NF525** — chaîne HMAC gap-free, seq monotone, composition_snapshot figé, rétention.
6. **Intersection** — cross-surface (borne→caisse→KDS→OSS→encaissement→fiscal), **zéro doublage**.
7. **Reproducibility** — le résultat tient sur ≥2 exécutions (anti-flake).
8. **Degradation** — worker down / soketi down / offline → poll fallback, no-data-loss.
9. **UI-UX capture** — capture réelle analysée (layout, raw-label, i18n FR, a11y, ticket==écran).
10. **Zero-doubling** — 1 commande = 1 ligne KDS, 1 seq fiscale (à l'encaissement), 1 ticket.

## §2 Cibles (plus profond sur le sous-couvert)
- **Cœur LIVE** : borne, caisse (espèces + carte), encaissement, KDS, OSS, chaîne fiscale 4 branches, ticket==écran.
- **Sous-couvert** : **web standalone** (`/Users/1millnonstop/Downloads/web`, code — NO API V1), **mobile RN**
  (`mobile/`, code standalone), **fidélité bout-en-bout** (register/check/redeem/QR signer), **Uber go-live**
  (OAuth/HMAC/mapping/fiscalize path), **angles morts dormants** : dine-in QR (`/api/table/dining-order`),
  cash livreur (delivery-boy sessions → Z), exports Excel (~20), cron Z (scheduler close/open/backup), legacy `/install`.

## §3 Data pack (réel, vérifié 2026-07-02)
- **HEAD `61e9ea7b7`** (owner a committé loyalty+Uber : `3319dd202` + `61e9ea7b7`) ; mes heals v1 = working-tree.
- Audits/triages : `reports/ultra-review/2026-07-02/{01-STRUCTURE,RAPPORT_ULTRA_REVIEW_COMPLET,RAPPORT_VALIDATION_SYSTEME_PAR_SYSTEME,HEAL_TRIAGE}.md` + `verify-findings.json`.
- Conventions test : DB `foodking_e2e`, admin/pos `123456` (test-DB), header `x-api-key`=`MIX_API_KEY`.
- Config : `APP_ENV=local APP_DEBUG=false POS_SIMULATION_HARDWARE=true KIOSK_REQUIRE_MACHINE_LOGIN=false IDEMPOTENCY=true walkin_route_to_counter=true kiosk.payment_route_all_to_counter=true`.
- Surfaces : `/login`, `/admin/{dashboard,pos,encaissement,kitchen-display-system,order-status-screen,items/studio}`, `/kiosk/idle`.
- Gates : NF525 `php artisan fiscal:verify-chain --all` ; frozen `git diff --stat -- <§7>` = 0.

## §4 Orchestration (v1 §8 + abuse)
Fan-out adversaire refute-by-default par cible × angles → verify indépendant par finding → critic de complétude
(≥2 cycles, jusqu'à 10) → **loop-until-dry** (K=2 rounds secs). Live e2e navigateur piloté par le main-thread
(borne+caisse espèces+carte, ticket==écran) ; workflows = code+API+data (curl/tinker read-only).

## §5 Barre (v1 §10, inchangée)
Validé ABSOLU ⇔ chaque cible survit aux 10 angles sur ≥2 cycles + repro stable + frozen-diff 0 + NF525 CHAIN OK +
zéro doublage + captures analysées. Tout nouveau P0/P1 réel = heal (non-frozen, TDD) puis re-refute. Rien de partiel.
