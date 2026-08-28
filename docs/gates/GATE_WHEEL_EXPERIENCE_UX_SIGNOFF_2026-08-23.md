# Gate Brief — Wheel Experience UX Sign-off

Gate ID: `GATE-WHEEL-EXPERIENCE-UX-SIGNOFF-2026-08-23`
Date drafted: 2026-08-23
Status: `PENDING_HUMAN_GATE`

## Decision Needed

Approve or request adjustments to the customer-facing Wheel experience after its automated checks are green. This is an acceptance gate only: it does not authorize changes to Wheel rules, prize probabilities, stock, loyalty, routes, services, migrations or frozen zones.

## Required Proof Before Approval

- Desktop and mobile entry, spin, pending, win/reveal, claim/redemption and recovery states are legible and coherent.
- Keyboard navigation, focus visibility, live announcements and `prefers-reduced-motion` behavior are acceptable.
- Loading, server error and retry states do not imply a prize, duplicate a spin or hide a server-owned result.
- The celebratory visual remains decorative: core reward information is available without the image, with sufficient contrast and no disruptive layout shift.
- The visible outcome follows the already server-authorized result; no UI change computes, substitutes or predicts the prize.

## Evidence Required

- Scoped PHPUnit and browser test output.
- Desktop and mobile captures (including one reduced-motion state) attached to the cycle report.
- A short residual-risk note if the reviewer requests any visual refinement.

## Prepared Technical Evidence

- Focused browser suite: **23/23 PASS**; tablet feature suite: **6 passed**.
- [Desktop recovery](/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site%20lecayenne/tests-e2e/roue-experience-shots/desktop-recovery-1365x768.png), [mobile recovery](/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site%20lecayenne/tests-e2e/roue-experience-shots/mobile-recovery-375x812.png), [mobile gain](/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site%20lecayenne/tests-e2e/roue-experience-shots/mobile-gain-375x812.png) and [mobile reduced motion](/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site%20lecayenne/tests-e2e/roue-experience-shots/mobile-gain-reduced-motion-375x812.png) are generated from the current source by the focused suite.
- Residual condition: photos and wording are proven with controlled current-source data; the approver must still assess the real campaign catalogue and the staff redemption flow before approving this gate.

## Reprise superviseur — 2026-08-24

Le dossier technique a été rejoué intégralement, sans faire confiance aux
chiffres du rapport précédent. Deux manques ont été trouvés et corrigés avant
de reposer ce gate devant l'humain.

### Manque 1 — la branche « refus serveur » n'était plus testée

Le banc `roue-2026-08-09.regression.js` contenait la vérification la plus
sensible d'une roue promotionnelle : *un refus serveur ne doit JAMAIS afficher
un écran de gain*. Ce bloc a été retiré le 2026-08-23 — retrait justifié en soi,
le banc consommait un vrai jeton à usage unique — avec un commentaire annonçant
que la couverture passait dans le banc focalisé. C'était vrai pour le chemin de
GAIN, faux pour le chemin de REFUS : les trois mocks `/wheel/spin` du banc
focalisé renvoyaient tous `status: true`. Aucun test ne couvrait plus le refus.
L'audit final GPT ne l'a pas relevé.

Couverture réimplantée, deux scénarios distincts : refus métier
(`status: false`, HTTP 200) et panne serveur (HTTP 500). Chacun prouve que
l'écran de gain ne s'ouvre pas, que le refus est expliqué au client, qu'aucune
photo de lot n'est révélée, que le client ne relance pas un second tour
(`/wheel/spin` appelé exactement une fois) et qu'aucune erreur JavaScript ne
survient.

### Manque 2 — une suite modifiée puis jamais rejouée

`roue-fond-carrousel-redirection-2026-08-13.spec.js` a été modifié le 2026-08-23
puis laissé **rouge à 78/84**, et ne figurait dans aucune preuve d'audit. Deux
causes, aucune n'étant une régression produit :
- quatre contrôles de photo ajoutés mockaient `segment_index: 0` tout en
  annonçant un autre lot ; `afficherVisuelGain()` masquait donc la photo — à
  raison, c'est sa garde anti-configuration-périmée. Les mocks pointent
  désormais sur le segment dont le libellé correspond exactement au lot ;
- deux attentes de condition réclamaient « numéro au comptoir », vestige d'un
  parcours par téléphone supprimé bien avant ce cycle. Vérification faite : les
  textes de `roue.html` sont identiques au bit près avant et après le cycle.

La garde anti-configuration-périmée, jusque-là déclenchée par accident, est
maintenant testée délibérément.

### Preuves rejouées le 2026-08-24

| Suite | Résultat |
| --- | --- |
| `roue-experience-2026-08-23.spec.js` | **33/33 PASS** (23 précédents + 10 contrôles de refus) |
| `roue-fond-carrousel-redirection-2026-08-13.spec.js` | **87/87 PASS** (était 78/84 ROUGE) |
| `roue-2026-08-09.regression.js` | **10/10 PASS** |
| `roue-lots-bandeau-2026-08-13.spec.js` | **17/17 PASS** |
| `roue-mouvement-2026-08-14.spec.js` | **41/41 PASS** |
| `php artisan test tests/Feature/Wheel/WheelKioskScreenTest.php` | **6 passed** |

Captures ajoutées : `tests-e2e/roue-experience-shots/mobile-refus-refus-métier-375x812.png`
et `mobile-refus-erreur-serveur-375x812.png`.

### Point d'attention UX soumis au décideur

Sur la capture de refus, le message « Ce jeton a déjà été utilisé. » s'affiche
en bas de page, **sous** le bandeau des lots, tandis que le haut de l'écran
continue d'annoncer « Tu gagnes à 100 %. Appuie, et regarde. » avec le bouton
« TOURNER LA ROUE » toujours en évidence. Le comportement est correct — aucun
second tour n'est déclenché, c'est prouvé — mais la hiérarchie visuelle peut
laisser un client croire qu'il doit réessayer. Décision d'opportunité, pas de
correctness : elle appartient à l'approbateur de ce gate.

## Invariants

- Server is the authority for spin and reward attribution.
- No price, order, payment, stock, loyalty, `branch_id`, dispatch or fiscal semantics change.
- This gate cannot be self-approved by an agent or an automated test.

## Human Approval

Decision: `PENDING_HUMAN_GATE`
Approver:
Date:
Notes:
