# Bilan — livraison sur le site lecayenne.fr (2026-10-23)

Demande : « assure que sur le site Web il y a bien l'option de livraison et que ça fonctionne correctement ».

## État avant

Le site EN LIGNE (déployé le 17/10 par `vercel --prod` depuis une copie non commitée) n'avait pas la livraison propre : la
tuile « Livraison » n'était qu'un lien Uber Eats, alors que le serveur de production répond `delivery/status → enabled:true`.
La copie de travail `Site lecayenne` contenait le tunnel de livraison (adresse vérifiée par le serveur, créneaux de 30 min,
refus au-delà de 10 km) mais il n'a jamais été mis en ligne, et avait des défauts propres (ci-dessous).

## Corrigé dans la copie de travail (rien commité, poussé ni déployé)

S-101 viandes du Cayenne / Classique / Tacos XL alignées sur la production · S-001 confirmation + suivi en mode livraison (adresse,
départ prévu, règlement, étapes ; plus de QR ni de texte de retrait) · S-002 « Payer à la livraison / Espèces à la remise » ·
S-003 adresse refusée : « Livraison à calculer », total hors livraison, lien Uber Eats, bouton bloqué · S-004 suggestions d'adresse
(5 caractères, 350 ms, annulation, message sur 429) · S-006 champ adresse mobile · S-103 refus serveur visible et lisible ·
S-102 viandes proposées = viandes du serveur, affectation correcte (Mixte) · S-005 pages « livraison à Hénin-Beaumont et jusqu'à 10 km ».

## Défaut trouvé à la relecture indépendante (non vu par l'implémenteur, sous 11 tests verts)

Le correctif S-102 refusait « La viande … n'est pas proposée pour <produit> » quand le serveur n'offre aucune viande. Sur la base
LOCALE les burgers ont un attribut « Viande 1 » ; en PRODUCTION ils n'en ont aucun (`viande_count 0`, de même que Suprême). Or le
site leur propose « Choisis 1 viande » (`viandes: 1` dans `menu.js`). Résultat avec le code de l'implémenteur : **les 6 burgers
devenaient incommandables en production**. Corrigé (`api.js`, `serveurPropose`) : le refus n'a lieu que si le produit propose des
viandes. Prouvé par `site-profils-production.spec.js` (catalogue de production figé dans `fixtures-prod/`, lecture seule, aucune
commande) : 19 lignes construites, toutes dans les min/max du profil réel ; le test devient rouge si le correctif est retiré.

## Mesuré

- `compile-jsx --check` et `check-asset-versions --check` : verts (jetons `20261023liv1`, `20261023liv2`, `api.js` → `20261023liv3`).
- Spec `site-livraison.spec.js` + `site-profils-production.spec.js` : 12 verts, 1 diagnostic désactivé, 0 requête vers la production.
- Captures relues (desktop 1440, mobile 390) : tuile, devis Hénin 3,90 € (total 9,90 €), Lille refusé + Uber Eats + bouton bloqué,
  confirmation « Espèces à la remise », suivi sans texte de retrait.
- Production lue en lecture seule (catalogue public, 70 produits) : les 3 écarts S-101 sont bien fermés ; 7 écarts de `menu.js` restent
  (6 burgers `viandes:1` vs 0, Suprême 2 vs 0) — sans effet commande après le correctif ci-dessus.

## Audit « tout est validé ? » (2026-10-23, soir) — trois relecteurs adverses indépendants + porte de tests

Porte : PHPUnit ciblé 720 tests (1 échec antérieur, `UberOrderMapperNoteTest`, mapper inchangé depuis le 15/08) ; Vitest 65 fichiers /
457 verts ; zone gelée : 0 ligne ; chaîne NF525 : OK sur 7 branches ; lot caisse à déployer = 7 fichiers, 109 lignes, 0 migration.

Trouvé puis corrigé (chacun verrouillé par un test qui devient rouge si le correctif est retiré) :
- **Caisse, P1** — le tableau « Suivi commandes » recomptait une livraison payée à la porte comme « à encaisser » (repli local sur
  les marqueurs bruts, bouton « Encaisser » voué au 422) ; seul le tiroir avait été corrigé. `PosOrdersTrackerComponent.vue`,
  commit `252276aa5` ; capture `captures-brain/tracker-carte-porte.png` (3 livraisons porte du jour : sans badge ni bouton).
- **Site, P1** — livraison payée par carte avec passage chez la banque (3-D Secure, portefeuille) : au retour `?order=`, la
  confirmation relisait `lc.mollie.pending` sans le mode et affichait un ticket de RETRAIT avec QR. Le pending emporte désormais le
  mode, l'adresse, les frais, le règlement et le départ ; le fil d'étapes suit. `funnel.jsx`, `racine.jsx` ;
  banc `site-retour-carte-livraison.spec.js` (témoin : un pending ancien retombe sur le retrait).
- **Site, P2** — « Choisir une heure » laissé vide en retrait bloquait le bouton après passage en livraison (consigne sur une section
  cachée) ; le refus « Saisissez votre adresse complète » restait affiché pendant la frappe. Corrigés, même banc.

Vérifié et écarté : double appel `email-login` (deux étapes distinctes ; le 422 = absence de SMTP en local) ; « trois durées » de
tournée (5 min aller + 4 min de remise + 3 min retour = 12 min, cohérent ; seul le libellé omet la remise) ; seuil de livraison
offerte en production = 0 (lu en lecture seule) ; montants identiques sur toutes les séquences (caisse 6,80 / 9,30 / 1,90 ; site
9,90 / 11,80 dont 3,90).

Restes P2/P3 non corrigés (à décider) : suivi livraison au statut 8 affiche la pastille « PRÊTE » au-dessus de « En préparation »
(`OrderTrackingService.php:286`) ; « Mes commandes » classe une livraison en route (statut 10) comme terminée et promet des points
« après retrait » (`orders.jsx:14,181,200`) ; boutons icône de la caisse sans `aria-label` (antérieur) ; purge d'abandon : une
livraison porte/site restée PREPARED sans départ n'est jamais purgée (antérieur, `CleanupStalePendingKioskOrders.php:177`) ;
registre tu/vous mêlé hors du bloc livraison (D5) ; libellés staff « paiement à la porte » / « Encaisser en espèces » à
harmoniser ; contrastes limites des boutons désactivés.

## Ouvert / décisions

1. **Suprême, burgers : le catalogue de PRODUCTION n'a pas d'attribut viande** (Suprême : seulement pain + sauce). Sur le site comme
   sur l'ancien, le choix de viande du Suprême n'est pas transmis : la cuisine ne le reçoit pas. À corriger dans le catalogue (admin).
2. Profils composés (`composer_profile`) vides en production pour les sandwichs : les correctifs « pain » de l'implémenteur y sont
   inertes (comportement historique conservé) ; ils ne jouent que là où un profil est publié.
3. Mise en ligne : le déploiement part de la copie de travail (125 fichiers non commités, lignée divergente de `origin/main`) ; il
   embarque donc aussi tout ce qui n'a jamais été en ligne depuis le 17/10. Retour arrière : `vercel rollback`.
4. `legal/cgv.html` dit encore « pas de livraison en propre » (texte juridique, propriétaire). Tutoiement restant hors du bloc
   livraison, accueil/FAQ en vocabulaire « retrait » (D1/D4/D5 non construits). `app/www` (application des stores) à reconstruire.
5. Heure d'arrivée non annoncée au suivi (le serveur ne la fournit pas) ; seule l'heure de départ l'est.
