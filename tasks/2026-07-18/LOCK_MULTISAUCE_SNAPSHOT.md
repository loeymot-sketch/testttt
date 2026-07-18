# LOCK — Multi-choix (sauces/garnitures/suppléments) nommés jusqu'au ticket + KDS

**Justification gate** : owner demande EXPLICITE « le client choisit plusieurs sauces… ça s'enlève au ticket et au KDS, on ne voit que la première… corriger sur tous les systèmes ». Le fix rend le ticket EXACT (conformité NF525 améliorée), il est ADDITIF et rétro-compatible, le PRIX est déjà correct (inchangé). Diagnostic : `reports/goal-parite-sync-2026-07-18/DIAG_MULTI_SAUCES.md`.

## Fichiers frozen touchés (§7) + snapshot (§8)
- `public/js/pos-wizard.js` (FROZEN) — caisse : émettre le NOM de chaque choix supplémentaire dans le payload structuré (pas seulement quantity), en plus de l'instruction texte.
- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` (FROZEN) — borne : idem.
- `composition_snapshot` (NF525 §8) — enrichi d'un champ `label` par extra (ADDITIF, rétro-compat ; lecture des anciens snapshots inchangée).

## Fichiers non-frozen (même lot)
- `app/Services/.../CompositionSnapshotBuilder.php` — persiste le `label`.
- `app/Services/Hardware/OrderReceiptEscPosRenderer.php`, `KitchenTicketSymbolicFormatter.php`, `resources/js/.../kdsSymbolic.js`, `ReceiptComponent.vue` — affichent « Sauce supplémentaire : Andalouse » / symbole.
- Frites Seules/Petite/Grande (#2/#33/#34) : garantir l'extra « Sauce supplémentaire » (via `menu:ensure-sauce-supplement-extras` étendu ou migration) pour que la sauce en plus soit facturée+nommée comme les bols.

## Scope / non-régression
- Le PRIX par ligne et le total NE CHANGENT PAS (déjà corrects depuis 15-16/07). Seul le NOM du choix supplémentaire devient visible ticket+KDS.
- Rétro-compat : un snapshot sans `label` s'affiche comme avant (« Sauce supplémentaire » générique).
- Tests : cross-surface (borne/caisse/web) → ticket ESC/POS == écran paiement == KDS == payé, avec 2+ sauces nommées. + non-régression renderers.

## Rollback
Revert du lot ; le champ `label` additif n'a pas d'effet sur les commandes déjà scellées. Aucune migration destructive.

## Sign-off
- [x] Demande owner explicite (2026-07-18) = autorisation du fix « sur tous les systèmes ».
- [ ] Revue finale owner du diff frozen avant push/deploy (les commits restent locaux jusqu'au gate deploy).
