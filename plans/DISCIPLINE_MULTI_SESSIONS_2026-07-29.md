# DISCIPLINE MULTI-SESSIONS — 2026-07-29 (LU PAR CHAQUE SESSION S1→S6 AVANT TOUT)

> 5-6 sessions Claude travaillent EN PARALLÈLE sur ce repo, comme des leads
> d'équipes. Ce fichier = la loi commune. Chaque GOAL S* le référence.
> Owner mandate : « c'est lui qui prend les décisions, pas d'attente de mon
> retour » — AUTONOMIE TOTALE dans les limites ci-dessous.

## 0. Lecture obligatoire au démarrage (dans cet ordre)
1. `CONSTITUTION.md` · 2. `PROJECT_BRAIN.md §2` · 3. `SYSTEM_MAP.md` ·
4. `PARALLEL_PROTOCOL.md` · 5. CE fichier · 6. Ton `plans/GOAL_S<N>_*.md`.
Skills à charger quand pertinents : `superpower-gstack`, `test-e2e`,
`ultra-audit-profond`, `verify-before-report`, `systematic-debugging`.

## 1. Propriété DISJOINTE (la règle n°1)
Ton GOAL liste TES chemins (ownership). Tu n'édites JAMAIS un fichier hors de
ta liste. Besoin d'un changement chez un autre système ? → écris une demande
dans `plans/handoffs/S<N>-vers-S<M>-<sujet>.md` (contexte + diff proposé) et
continue autre chose. Le fichier partagé interdit à TOUS sans LOCK :
frozen CLAUDE.md §7 + `OrderStateMachine` + `PricingService` + Fiscal/*.

## 2. Git (5 sessions, 1 branche : `pos/category-first-caisse-2026-06-23`)
- AVANT chaque commit : `git pull --rebase origin pos/category-first-caisse-2026-06-23`.
- Commits PETITS et fréquents, préfixe `[S<N>]` dans le message.
- `git add <fichiers explicites>` UNIQUEMENT (jamais `.` ni `-A`), scan secrets avant.
- Conflit de rebase DANS tes chemins → résous. HORS de tes chemins → `git rebase --abort`,
  note dans handoffs, réessaie 10 min plus tard.
- Web repo (`/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne`,
  branche `main`) : réservé à S4 (+ S6 lecture). Cache-bust `?v=` à CHAQUE deploy web.

## 3. Deploy (sérialisé par verrou)
- Backend : `bash tools/deploy-lecayenne.sh <SHA>` (rollback auto). AVANT :
  pull-rebase + suite verte de TON périmètre + frozen diff 0 + chaîne OK.
- Verrou : `mkdir /tmp/fk-deploy-lock` (mkdir atomique). Échec = un autre deploy
  en cours → attends 90 s, réessaie. `rmdir` en fin (même si échec) .
- Web : commit → `git push origin main` (Vercel auto) → vérifier le LIVE sert
  le nouveau `?v=` (`curl | grep`).
- JAMAIS de push --force, jamais --no-verify.

## 4. Tests (DB-SAFE, non négociable)
- PHPUnit : `bash ~/.claude/skills/brain/scripts/safe-test.sh --phpunit "<Filtre>"`.
  JAMAIS `php artisan test` (a déjà détruit la DB partagée).
- Vitest : `npx vitest run <fichiers|dossier>` ; full suite avant deploy majeur.
- Chaque défaut corrigé = un test de régression nommé.
- NF525 : `php artisan fiscal:verify-chain --all` (lecture seule) après tout
  travail fiscal-adjacent. TAMPER staging id=1 = état CONNU documenté, ne pas toucher.

## 5. E2E RÉEL web (obligatoire à chaque vague qui touche un parcours)
- Smoke sans effet : `tests-e2e/nav-smoke.local.js` (13 checks, serveur local
  `python3 -m http.server 8899` dans le dossier web) — 13/13 exigé.
- Commande RÉELLE (avec modération — 1 par validation majeure, pas en boucle) :
  `tests-e2e/order-live.REAL-ORDER.js` → www.lecayenne.fr, code OTP lu en DB VPS
  (`otps.token` — PAS `code` qui est l'indicatif). CHAQUE commande test créée est
  consignée dans `reports/goal-s<N>-*/COMMANDES_TEST.md` (l'owner les annule).
- Paiement : clé TEST Mollie (`MOLLIE_TEST_API_KEY` sur le VPS quand posée par
  l'owner) — les paiements de test utilisent CETTE clé, JAMAIS la live.
- Playwright backend : port 8000 local ; borne/caisse/KDS via specs `tests/e2e/`.

## 6. Boucle adversariale (chaque vague)
1. Implémente (scope minimal, TDD si logique).
2. Fan-out lecture seule EN UN MESSAGE : auditeurs spécialisés (logique, sécu,
   sync, UX) + captures Playwright LUES via Read (mandat visuel CLAUDE.md §6 :
   layout, labels bruts, états vides, i18n, boutons morts — pages CACHÉES incluses).
3. RED-team dispute chaque finding (reproduce → dispute → prove ; findings sans
   file:line reproduit = REJETÉS).
4. Heal → re-test → re-capture. Convergence = **2 cycles consécutifs P0+P1=0
   à findings identiques**. Max 3 heals même problème → pivot d'approche (pas d'owner).
5. Sortie disque, pas chat : `reports/goal-s<N>-<slug>/` (findings JSON + captures
   + CONVERGENCE.md). Chat = résumés courts (discipline tokens).

## 7. Autonomie & gates
- TU décides (archi locale, priorités, heals) — jamais d'attente owner.
- SEULES gates owner (STOP + noter dans le rapport, continuer autre chose) :
  frozen §7 logique (procédure LOCK), secrets/clés, suppression données réelles,
  argent réel (paiement live), annulation de commandes fiscales réelles.
- Commandes owner à exécuter côté serveur : TOUJOURS via petit script posé sur
  le VPS + commande COURTE (leçon : une commande longue s'est fait couper par
  le terminal → .env corrompu → app down).

## 8. Fin de session / checkpoint
- Toutes les 45-60 min ET avant tout risque de limite : commit + push +
  `reports/goal-s<N>-*/PROGRESS.md` (fait / en cours / next exact).
- Fin : BRAIN §2 (1 entrée datée `[S<N>]`), memory topic si leçon durable,
  résumé court. Le GOAL n'est clos que sur convergence §6 prouvée.

## 9. Anti-doublage (mandat owner « zéro doublon »)
- 1 seule source de vérité par fait (prix=PricingService, dispo=AvailabilityService,
  release=KitchenReleaseRule, séquence=FiscalSequenceService). Toute logique
  dupliquée trouvée entre borne/caisse/web = finding P1 → consolider (twin PHP↔JS
  autorisé UNIQUEMENT avec test de parité, ex. kdsSymbolic).
- Numéros de commande : jamais générés côté client ; unicité = serveur.
