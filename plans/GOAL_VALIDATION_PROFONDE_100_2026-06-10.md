# GOAL VALIDATION PROFONDE 100% — toutes fonctionnalités & parcours, par système et par page (2026-06-10)

> Owner (ultracode) : « tout valider niveau technique en profondeur, comme le goal d'avant : test → audit → correction → capture → re-test, en boucle jusqu'à tout bon ; ne retourner que 100% validé avec preuve. »
> Base : spine `heal/pre-cloud-exec-2026-06-05` @ `f6541d937` (post GOAL ultra-audit convergé — suites 3085/2096 ×2, sync live, volume >100, fiscal CHAIN OK).
> Harnais : :8766 `foodking_e2e` (mutations OK, jetable) · soketi :6001 · worker `--queue=high,default` · captures → `reports/test-e2e/validation-profonde-2026-06-10/<wave>/`.
> Règles dures inchangées : frozen §7 = observe-only (capture OK, 0 logic change sans LOCK) · NF525 §8 · anti-hallucination §3ter (chaque finding = preuve) · P2/P3 divulgués, P0/P1 = heal loop (max 3 cycles puis escalade) · convergence = 2 cycles propres identiques par vague + adversarial épuisé.

## Décomposition maximale par vague (chaque vague = BREAKDOWN code → PILOTE spec+captures → ANALYSE visuelle Read → ADVERSARIAL → HEAL → RE-RUN ×2)

### W-A — BORNE (kiosk) : 100% du parcours client
- A1 idle : touch CTA, choix type (À emporter ; dine-in désactivé V1 = vérifier absence), inactivité overlay.
- A2 catégories : sidebar 7+ catégories (images per-category), grille produits, badges (Nouveau/Épuisé), upsell items, produit filtré-out = clic no-op.
- A3 wizard par catégorie (7) : CHAQUE étape capturée (viandes/sauces/crudités/suppléments/menu/boisson), min/max select enforcement visuel, back/next, prix backend live, annulation wizard.
- A4 panier : ajout multiple, +/- quantité (clamp MAX=20 + total recalc), suppression ligne, panier vide (état), retour menu.
- A5 upsell post-checkout : accept (ajout réel au panier + total) ET skip.
- A6 loyalty : page consult (code), erreurs code invalide.
- A7 paiement : route comptoir Plan-B (confirm → écran « Rendez-vous en caisse » + numéro), idempotence re-clic.
- A8 erreurs/offline : rupture mid-parcours (toggle dispo pendant panier), réseau (kioskOfflineQueue) si simulable sans casse.

### W-B — CAISSE (POS) : 100% du poste caissier (wizard popup = FROZEN observe-only)
- B1 login admin → /admin/pos : catalogue tuiles, recherche, catégories.
- B2 wizard popup Vanilla (FROZEN) : OUVRIR, parcourir étapes, capturer chaque écran, fermer — AUCUNE édition de code ; commander 1 item composé bout-en-bout (CAISSE-01 patch actif : vérifier upgrade frites facturé si seedé, sinon disclose data-gap).
- B3 panier POS : quantités, remise (gate manuel), note, hold/park → recall (idempotent déjà prouvé, re-capturer UI), clear.
- B4 paiement POS inline : CASH (rendu monnaie), reçu modal (ReceiptComponent : mentions NF525, duplicata counter), impression policy (increment même si endpoint KO = continuité opérationnelle déjà testée unitairement — capturer le modal).
- B5 sessions tiroir : ouvrir session (fond de caisse), mouvements, fermer + réconciliation (écarts), rapport session.
- B6 posOrders : liste (badges statut/paiement FR), show (détail, téléphone null-guard), tracker (colonnes, collect).
- B7 encaissement : (déjà prouvé mixte+race) — re-vérifier UI badges file (collision N°A0001 inter-jours = P2 divulgué, re-capturer).

### W-C — DASHBOARD : sweep MUTATIONS par page (clone jetable ⇒ CRUD autorisé)
- C1 catalogue : item create→edit (prix/TVA/images)→indispo toggle→delete(soft) ; catégorie create/edit/réordonner ; ItemExtra/variations éditeur ; composer builder (re-run smoke W1 wizard-parity).
- C2 ventes/gestion : coupon create/edit/delete (P2 i18n jours = vérifier post-heal éventuel) ; offers ; remise logs.
- C3 staff : employee create (rôle FR)/edit/disable ; administrators guard (H8b sibling) ; chefs/waiters/delivery.
- C4 clients : customer show/édition adresse (F-C4 permissions séparées = vérifier 403 avec user limité si seedable).
- C5 settings : company/site (time format 12h→24h DATA-OP à exécuter sur clone + re-render Historique), order-setup, kiosk-setup — edit→save→revert.
- C6 reports : sales/items/credit + exports (download OK, contenu FR) ; historique filtres+export N° fiscal ; transactions filtres.
- C7 stock : rupture toggle item (re-tester race lost-update F-DASH-2 connue = adversarial cross-bench, vérifier sur spine) ; cascade borne (item Épuisé immédiat ?).
- C8 divers : messages, subscribers, push-notifications (compose SANS send massif), observability (outbox UI).

### W-D — KDS/OSS PROFOND
- D1 KDS : flux complet NOUVELLE→Démarrer→Prêt→servie (footer), recall inline (POST serveur — fix KDS-OSS-01), history drawer (refetch on 403/409/422), filtres excepts, cap overflow « +N », multi-postes (2 contexts, propagation <5s), a11y focus CTA.
- D2 OSS : colonnes préparation/prêt, transition après bump, jour-courant only, mur public vs admin.
- D3 dégradé : soketi DOWN → polling 2-5s continue (KDS+OSS), bannière connexion, retour UP → re-sync sans doublon.

### W-E — AUTH/RBAC & SÉCURITÉ PARCOURS
- E1 login/logout admin (FR), mauvais mot de passe (message générique post-F3), throttle lockout (429 propre).
- E2 kiosk machine login (F3 enumeration-fix côté spine ? vérifier — fix porté au checkout principal, PAS à la spine → tester comportement actuel + disclose si divergent).
- E3 token kiosk sur /api/admin/* = 403 structuré (middleware Layer-1) ; permission-denied UI (page propre, pas de crash).

### W-F — CONVERGENCE
- Re-run intégral des specs de toutes les vagues ×2 cycles identiques + suites PHPUnit/Vitest si du code a bougé + adversarial final (1 agent, toutes les galeries) → `PROOF_INDEX.md` (table fonctionnalité → preuve → statut) + rapport consolidé + BRAIN/Graphiti/insight.

## Dispositif
- 1 agent PILOTE par vague (spec Playwright + captures + analyse Read + rapport), ≤2 concurrents (collision logins/browser), commits checkpoint chemins explicites.
- Adversarial après chaque vague (read-only, refute).
- Heals : non-frozen only, scope-minimal, test-first quand testable ; frozen/NF525/data-owner → GATE documenté.
- Disque surveillé (8,3 Gi) : captures JPEG qualité raisonnable, purge test-results entre vagues.
