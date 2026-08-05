# [S4] PROGRESS — site client : fidélité + suivi + temps de préparation (2026-07-29)

## FAIT

### V1 — Audit total du site ✅
- Baseline : nav-smoke **13/13**, 0 erreur JS. Web `main` == `origin/main` == `5f220be`,
  LIVE sert bien cette version (`?v=20260729c`). Backend VPS UP.
- **50 surfaces capturées** (desktop 1440x900 + mobile 390x844) : home, menu, fiche,
  wizard, panier, upsell, checkout, paiement, compte, orders, fidélité, 5 pages légales,
  routes directes #checkout/#track/#confirm, hash inconnu, 404, hors-ligne.
  → `V1-shots/` + `surfaces.json`. **Toutes lues** (2 auditeurs visuels + le lead).
- 3 auditeurs code (fidélité / suivi / checkout-paiement) + **3 disputes RED**.
  Les RED ont dégradé 3 findings sur 4 — sévérités finales dans `V1-FINDINGS.md`.
- Résultats notables : aucun label brut sur 50 écrans, interface FR partout, palette OK,
  **IDOR fermé** (prouvé), idempotence 4 couches, PAID uniquement par webhook.

### V3 — Suivi + temps restant ✅ (commit `6e132c6`)
- **TRK-2** temps restant RÉEL (`wait-estimate`, file cuisine) — remplace le `'~12 MIN'`
  codé en dur. Règle dure : pas d'estimation serveur ⇒ **rien affiché**, jamais de chiffre inventé.
- **TRK-3** bascule « PRÊTE » : bandeau vert plein, pastille verte, titre d'onglet, vibration,
  `prefers-reduced-motion` respecté.
- **TRK-4** fin du polling muet : « Statut non actualisé » + « Réessayer ».
- **TRK-1** commande retrouvable : `mapOrderRow.dbId` + « Suivre ma commande » depuis Commandes.
- Preuve : `suivi-temps-restant-s4-2026-07-29.regression.js` **15 ✅ / 0 ❌** (dont cas lent).
- Visuel : `V3-shots/` lus.

### Hors-vague — TICKET FANTÔME ✅ (commit `16374bd`)
P1 trouvé par l'audit visuel, manqué par l'audit code ET par le RED.
`#confirm`/`#track` affichaient une **fausse confirmation avec un QR de retrait réel**
(« commande #— », TOTAL 0,00 €). Mécanique : `index.html:207` popstate routait vers
n'importe quel hash **sans consulter RESTORE_ROUTES** (qui ne protège que le cold load).
Chemin client réel : commander → confirmation → « Retour à l'accueil » → bouton Précédent.
Garde posée au RENDU (couvre toutes les entrées). Preuve : **6 ❌ avant / 10 ✅ après**
(vérifié par `git stash`).

### V2 — Fidélité : préjudice arrêté ✅ (commit `3aa3460`) — vague NON terminée
- **LOY-1 fermé** : le bouton « Utiliser X € » débitait les points sans produire AUCUNE
  remise (ligne PENDING `source_surface='pos'` jamais consommée ; la caisse ne la voit pas
  et redébiterait). Pré-débit supprimé, CTA redirigé vers le QR (seul chemin honoré),
  textes rendus vrais, bloc de succès mensonger supprimé.
- **Gate owner consignée** : redeem web de bout en bout ⇒ `FrontendOrderService` (§6 partagée,
  LOCK+gate). Le RED a prouvé qu'élargir le seul filtre `source_surface` serait INSUFFISANT.

## EN COURS / NON FAIT
- **V2 reste à faire** : paliers (décision produit requise, voir ci-dessous), seuil 50/100,
  solde visible hors page Fidélité, repli `ppe = 1`.
- **V4 — paiement TEST Mollie** : non commencé (gate owner sur la clé TEST).
- **V5 — convergence** : NON atteinte. Les 2 cycles adversariaux consécutifs P0+P1=0 exigés
  par la DISCIPLINE §6 n'ont PAS été exécutés.
- **Deploy** : rien n'est poussé. Délibéré — ne pas déployer une vague à moitié sur un site LIVE.

## ARBITRAGE PRODUIT EN ATTENTE (ne pas deviner)
Paliers fidélité : le web code en dur `Novice 0 / Pepper 500 / Master 1500 / Légende 5000`
(`screens.jsx:541`) ; le backend publie **5** seuils `100/250/500/1000/2000`
(`LoyaltyController.php:505`), consommés par la borne. **5 seuils vs 4 noms** — la
correspondance nom↔seuil doit être tranchée avant tout alignement.

## NEXT EXACT
1. **V2** (dans cet ordre) :
   a. `screens.jsx:884-885` — supprimer le texte MENSONGER « remise enregistrée côté caisse »
      (P1-LOY-1 : le redeem web débite les points et ne rend AUCUNE remise ; la caisse ne voit
      rien ; le reaper re-crédite 30 min plus tard). Router l'usage des points vers le QR
      comptoir, **seul chemin réellement honoré**.
      ⚠️ Ne PAS corriger côté backend : `FrontendOrderService` = zone §6 partagée (LOCK+gate),
      et le RED a prouvé qu'élargir le filtre `source_surface` serait **insuffisant seul**.
   b. `screens.jsx:541-546` — paliers codés en dur (0/500/1500/5000) → consommer `cfg.tiers`
      backend (100/250/500/1000/2000), déjà exposé et déjà consommé par la borne.
   c. Seuil : aligner le texte « utilisables dès 50 points » sur la règle réellement appliquée
      (multiple de 100 sur le chemin redeem).
   d. Solde de points visible hors page Fidélité (header / panier / confirmation / suivi).
   e. `funnel.jsx:13,23` — repli `ppe = 1` qui affiche « +12 pts » là où le backend en crédite 120.
2. **V4** — paiement TEST Mollie bout-en-bout (⚠️ gate owner : clé `MOLLIE_TEST_API_KEY` sur le VPS).
   Inclure PAY-1 (purge de `lc.funnel.idem` avant le bloc Mollie) et PAY-1bis
   (`submitting` jamais remis à `false` ⇒ bouton figé au retour bfcache).
3. **V5** — recapturer les 5 surfaces du tunnel non prouvées en V1 (voir « lacune assumée »
   dans `V1-FINDINGS.md`), puis 2 cycles adversariaux consécutifs P0+P1=0.
4. Deploy web (push `main` = Vercel) : **bumper `?v=` d'abord**, `git pull --rebase` avant push.
   Non poussé à ce stade — délibéré : ne pas déployer une vague à moitié sur un site LIVE.

## COORDINATION
- S8 travaille dans le MÊME arbre (a committé `7ed1fd4` pendant ma vague). `git add` explicite
  uniquement ; jamais `git add .`. `pull --rebase` refusé tant que S8 a des modifs non commitées
  — c'est normal, ne pas stasher son travail.
- Handoffs émis : `plans/handoffs/S4-vers-S8-wizard-total-clippe-2026-07-29.md`
  et `plans/handoffs/S4-vers-S7-404-et-vitrine-2026-07-29.md`.

## COMMITS S4 (aucun poussé)
- `16374bd` fix ticket fantôme + outil de capture des 50 surfaces
- `6e132c6` suivi : temps restant réel, bascule PRÊTE, polling non muet, commande retrouvable
- `3aa3460` fidélité : fin du débit de points sans remise + textes rendus vrais

## SUITES DURABLES (toutes vertes au dernier passage)
- `tests-e2e/nav-smoke.local.js` — 13/13, 0 erreur JS
- `tests-e2e/ghost-ticket-s4-2026-07-29.regression.js` — 10/10
- `tests-e2e/suivi-temps-restant-s4-2026-07-29.regression.js` — 15/15 (`LC_SLOW=1`)
Lancement : `cd testttt && NODE_PATH="$(pwd)/node_modules" node "<chemin du spec>"`
(serveur : `cd "Site lecayenne" && python3 -m http.server 8899 --bind 127.0.0.1 &`)


## DÉPLOIEMENT 2026-08-01 ✅
- Backend VPS `92dc856a` (PR #27 mergée) : migration `2026_08_01_130000` jouée, triggers NF525 10/10,
  healthz vert, contenu frais, CORS OK. Snapshot DB pris avant migration.
- 3 viandes Cayenne vérifiées EN BASE VPS : Poulet mariné / Viande Hachée / Mixte, toutes @0 et `["pos"]`.
- Web : les 3 commits S4 étaient déjà dans `origin/main` (poussés par une autre session avec
  cache-bust `20260731i`). LIVE www.lecayenne.fr vérifié : sert bien mes heals.
- Régression ticket fantôme rejouée **CONTRE LA PRODUCTION** : 10/10.
