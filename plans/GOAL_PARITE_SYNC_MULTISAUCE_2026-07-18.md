# GOAL — Multi-choix visible partout + web unifié + stock caisse/KDS (2026-07-18)

Séquence owner : quick wins faits → **planification (ce doc)** → correction en boucle test-e2e. Mode simulation matériel CONSERVÉ (pas de go-live prod). HEAD `5394e1a9` déployé + commits du jour.

## Bloc A — Quick wins ✅ FAIT (committé)
TVA 10 % partout (migration `fiscal:assign-menu-vat`, corrige boissons VPS) + remises manuelles COUPÉES (`config/pos.php` défaut false). Effet de bord signalé : le kill-switch masque aussi bouton fidélité borne + coupon web (à trancher owner).

## Bloc B — 🔴 BUG MAJEUR multi-sauces (le plus urgent) — frozen §7 + snapshot §8 sous LOCK
**Racine (DIAG_MULTI_SAUCES.md)** : 1ère sauce = `item_variation` nommée ; sauce EN PLUS = `item_extra` générique « Sauce supplémentaire » @0,50 **SANS nom** → le `composition_snapshot` (SSOT NF525) n'a jamais le nom de la 2e+ sauce ; il n'existe que dans l'`instruction` texte libre, que ticket ESC/POS + KDS + reçu **strippent** (`cleanInstruction`/`sanitizeKdsInstruction`). Panier/paiement OK car client-side (pas de strip). Prix déjà corrigé 15-16/07.
**Fix Option A (le seul qui rend le SSOT fiscal exact)** :
1. Wizards transmettent le NOM de chaque choix supplémentaire (sauce/garniture/supplément) : `public/js/pos-wizard.js` (FROZEN), `KioskWizardComponent.vue` (FROZEN), web (`FrontendOrderService`/builder, non-frozen).
2. `CompositionSnapshotBuilder` (non-frozen) persiste un `label` sur l'extra (additif, rétro-compat).
3. Les 3 renderers (non-frozen) affichent le nom : `KitchenTicketSymbolicFormatter`, `OrderReceiptEscPosRenderer`, `kdsSymbolic.js` (+ aperçu `ReceiptComponent.vue`).
4. Étendre à **Frites Seules/Petite/Grande** (#2/#33/#34 : pas d'extra « Sauce supplémentaire » → sauce en plus gratuite) et à tout multi-choix (garnitures/suppléments multiples).
**Gate** : LOCK `tasks/2026-07-18/LOCK_MULTISAUCE_SNAPSHOT.md` (justifié : demande owner explicite + fix additif améliorant la conformité, prix inchangé). Test + boucle test-e2e cross-surface (ticket == payé == KDS).

## Bloc C — Web unifié (non-frozen) — DIAG_WEB_UNIFIE_STOCK.md
Fondation solide (même endpoint borne, OSS complet, KDS OK). 3 manques :
- **C1** Accept/gestion **inline** caisse des commandes web PENDING (aujourd'hui `openWebOrder` redirige vers `/admin/online-orders`) → gérer dans le POS.
- **C2** Vue caisse unifiée du cycle web (une commande web suivie de bout en bout au comptoir).
- **C3** 🔴 Notif client « prête » qui ARRIVE sur le web : aujourd'hui le dispatch existe mais le broadcast est staff-only (`private-branch`), SMS coupé, web pas abonné FCM → **aucun canal client**. Fix : canal client (broadcast par token client / web-push) OU statut fiable dans le compte + polling documenté. Owner veut « le client informé quand c'est prêt ».

## Bloc D — Stock caisse/KDS — ✅ QUASI FAIT
Panel `AvailabilityTogglePanel` monté caisse ET cuisine, permission `availability_toggle` accordée POS Operator + Chef (runtime confirmé). Gaps mineurs : **D1** toggle extra/variation depuis le panel (backend prêt, UI manque) ; **D2** parité guard `web` SPA à confirmer.

## Bloc E — Chaîne fiscale (Workstream A) — gros, séparé
VPS en TAMPER (données de test staging). Réparer = geler la chaîne + repartir propre en prod réelle. À faire ensemble, hors mode simu.

## Exécution
Priorité : **B (multi-sauces)** d'abord (fiscal + cuisine), puis **C3 (notif client)** + **C1/C2**, puis **D1/D2**, puis **E** (gate owner). Chaque bloc : fix → PHPUnit/Vitest → e2e cross-surface → adversaire → commit. Rien poussé/déployé sans gate.
