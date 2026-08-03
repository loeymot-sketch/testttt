# GOAL S5 — ÉCRAN CUISINE (KDS) + OSS : GESTION DES COMMANDES PARFAITE (2026-07-29)

> Tu es le LEAD CUISINE. Lis `plans/DISCIPLINE_MULTI_SESSIONS_2026-07-29.md`
> D'ABORD. Mission : l'écran cuisine = zéro commande ratée, zéro ambiguïté de
> lecture, tickets impeccables ; l'écran client (OSS) toujours juste. Le chef
> travaille les mains sales : tout doit être lisible à 2 m et actionnable en
> 1 geste. Convergence §6, autonomie §7.

## Ownership (tes chemins)
- `resources/js/components/admin/kitchenDisplaySystem/**` + helpers/services JS
  `kds*`/`KdsSyncService*` + `kdsSymbolic.js` (twin JS)
- OSS : `resources/js/components/admin/orderStatusScreen/**` (écran client)
- Tickets : `app/Services/Hardware/KitchenTicketSymbolicFormatter.php` (twin PHP —
  parité kdsSymbolic OBLIGATOIRE, tests parité existants 343+) + pont impression
  `tools/kitchen-bridge` (:9101)
- Backend KDS : contrôleurs/routes KDS, `KitchenReleaseRule` en LECTURE (S3 owner)
- `tests/js` kds* + `tests/Feature/Kds*` + sentinel bundle admin-kds ·
  rapports `reports/goal-s5-kds/`

## État connu (anchors)
- KDS V2 : bannières + son réparés (autoplay débloqué par geste), légende
  symboles, réimpression, board-release source-agnostique (borne/caisse/web).
- Ticket cuisine symbolique : viandes en symboles espacés (« K P »), sauce ligne
  produit / ligne menu, extras nommés (« + Viande supplémentaire : Nuggets »,
  « Sauces en plus : X »), parité PHP↔JS testée sur 600 commandes.
- Impression AUTO ticket cuisine via pont USB local ; poll fallback configurable
  (`FK_CATALOG_KDS_*`, défaut 10 s, tune 4 s/2 s dispo).
- Commandes programmées : release T-20 min (KitchenReleaseRule SSOT).

## Vagues
### V1 — Cartographie cuisine + captures plein-écran
Tous les états KDS : vide, 1 commande, 10 commandes, commande programmée (avant/
après release), annulée en cours de prépa, modifiée, borne vs caisse vs web
(badges source), son/bannière, réimpression, mode dégradé (backend down), légende.
OSS : file, appelé, servi. Captures 1920×1080 LUES une à une.
Acceptance : `V1-SURFACES.md` 100 % + findings lisibilité (taille police à 2 m !).

### V2 — Logique de flux cuisine
Agents raisonnement : ordre d'affichage (FIFO ? programmées ?), états et
transitions (nouveau→en prépa→prête→servie) EXACTEMENT alignés caisse/suivi web
(carte S3 V1 en référence), temps affichés (âge commande, cible prépa), règles
de groupement. Chaque ambiguïté = finding. RED dispute.
Acceptance : matrice état×écran (KDS/OSS/caisse/web) 100 % cohérente + tests.

### V3 — Tickets parfaits
Chaque archétype (tacos multi-viandes, menu enfant, bol gratiné, formule,
suppléments multiples, note allergie) → ticket généré + LU (rendu réel via bridge
si dispo, sinon payload). Parité PHP↔JS re-prouvée + cas limites (UTF-8, œ,
longueurs). Réimpression depuis KDS et caisse.
Acceptance : galerie tickets ×10 archétypes validée + tests parité verts.

### V4 — Résilience service du soir
Rush simulé (20 commandes/10 min via e2e), poll dégradé (soketi off), écran
freeze/reload (beacon), imprimante off (fallback écran), double-écran.
Latence borne→KDS re-prouvée <3 s (P95 sur 20 commandes).
Acceptance : zéro commande perdue/dupliquée sur le rush + mesures consignées.

### V5 — Convergence
Suites Kds + parité + e2e cuisine ×2 cycles propres + deploy §3 + BRAIN + memory.

## Rappels
« Prête » côté KDS doit déclencher ce que S4 affiche au client (handoff S3 si
event manquant). Le chef ne lit pas de prix : le ticket cuisine reste SANS prix.
