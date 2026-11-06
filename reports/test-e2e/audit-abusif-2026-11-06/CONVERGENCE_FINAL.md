# Audit E2E abusif — convergence (2026-11-06)

Demande du propriétaire : « deploy et act like supervisor complete al dsystems test-e2e abusif ».
Déployé avant l'audit : backend 31a2401ea, site/app 5120c3b. Abus en local uniquement (:8766 et serveurs d'agents), production en lecture seule.

## Verdict

**Convergence au tour 5** : tours 4 et 5 consécutifs avec **P0 + P1 = 0** sur toutes les vagues (écrans, site/app, serveur, relecture adverse). L'ensemble bloquant (P0+P1) est identique (vide) aux deux tours. Les P3 diffèrent (nouveaux P3 d'affichage au tour 5, non bloquants, déjà visibles sur les captures du tour 4).

| Tour | Vagues | P0 | P1 | Issue |
|---|---|---|---|---|
| 1 | 6 vagues | 0 | 15 | corrigés (W1…W5) |
| 2 | confirmation + adverse | 0 | 4 contournements | corrigés : cea2c46e9, ee137eacf, 222ad102a, b6afaf015 |
| 3 | serveur, site, écrans, adverse | 0 | 2 | R3-ADV-001 → 929afba84 ; ECR-R3-001 → f74b6bc5f |
| 4 | écrans, adverse+serveur (site = tour 3 sur 929afba84, code site inchangé) | 0 | 0 | P3 → d7cd618ab |
| 5 | écrans, site/app, adverse | 0 | 0 | **convergence** |

## Correctifs de l'audit (backend testttt, branche pos/category-first-caisse-2026-06-23)

`git log --oneline 31a2401ea..d7cd618ab` — 25 commits, dont ceux de la session voisine (fidélité). Principaux :
- Plan B borne : payment-confirm et reconcile-pending refusés (1defc81d0, ee137eacf).
- Ticket promo nominatif : code exigé, usage unique sous concurrence MySQL (54f53283a, cea2c46e9) ; coupon_code non scalaire refusé lisiblement (929afba84).
- Instruction web en liste blanche, puis liée au rôle de formule payé (bfc0fc0ec, 222ad102a, 929afba84).
- OTP : seau par /64 IPv6 (9c66308b1, 222ad102a).
- Employés : pas de prise de compte d'un pair, supprimé sans accès ; sessions fermées au changement d'identifiants par un tiers sur TOUTES les fiches (33ee09da6, 929afba84).
- Livreur : espèces réelles, montant client ignoré (539ef9bf7) ; planche Tournées « plusieurs moyens » / « annulée » (f74b6bc5f, d7cd618ab).
- Caisse : clé d'idempotence stable par panier, gardée au F5, oubliée au vidage réel (1282bf7e1, ee137eacf, f74b6bc5f, d7cd618ab).
- Horaires : refus serveur d'une commande immédiate hors service / fermeture exceptionnelle (b6afaf015, d7cd618ab).
- Zones gelées : diff 31a2401ea..d7cd618ab vide sur les 13 fichiers/dossiers. Chaîne NF525 : CHAIN OK (7 branches) à chaque tour.

Tests : Vitest complet 674 fichiers / 5947 verts (après d7cd618ab) ; PHPUnit complet 7574 verts sur 88dfbda82..b6afaf015 (session voisine) ; suites ciblées vertes après chaque correctif. **Un PHPUnit complet sur d7cd618ab reste à faire avant mise en ligne.**

## Ordre de déploiement (impératif)

1. **Site d'abord** (`site-deploy-2026-11-03` HEAD 6208324 : envoie `coupon_code`), puis
2. **backend** d7cd618ab (exige `coupon_code`) — sinon tout code promo web casse ;
3. **app** : `npm run build:www` + `cap sync` avant tout build store (S3-008 : copies iOS/Android sans `coupon_code`).

## Ouverts, non bloquants (à divulguer)

- P2 S3-008 : paquets natifs de l'app non resynchronisés (voir ordre ci-dessus).
- P3 caisse : F5 reçu ouvert → panier payé réapparaît (encaissement refusé 409, à vider à la main ; fermeture du reçu dans PaymentComponent.vue, zone gelée) ; vente perdue + rupture de stock qui retire une ligne → clé neuve (hypothèse R5-ADV-002) ; haut du ticket coupé à 1280 ; « ACTIO » dans /admin/items à 1280.
- P3 livraison : « Ce créneau est trop proche » au lieu de « fermé ce jour-là » (DeliveryWaveService:359 avant :365) ; nom de champ brut sur créneau mal formé ; choix livreur grisé sur arrêt annulé.
- P3 site : autre code tapé après « Appliquer » ignoré ; « Réouverture vendredi » un vendredi ; bandeau hors ligne qui parle de « la carte » ; pas de X-Device-Id (deux onglets se déconnectent) ; horaires.html « 18h à minuit » ; suivi d'une commande remboursée ; ticket livraison « À RÉGLER EN CAISSE » ; retrait affiché « LIVRÉE ».
- P3 admin : erreur coupon partiellement hors écran à 1280 ; Horaires « DU » décalé ; Hygiène « Déjà validée » sans rafraîchir la fiche ; KDS 1280 note coupée / pastille « EN LIGNE ».

## Décisions propriétaire (tranchées après l'audit, relayées par la session voisine)

- Points d'une commande pas encore REMISE : visibles mais NON dépensables.
- Borne : plusieurs moyens d'identification pour dépenser des points (QR, téléphone, e-mail), pas le téléphone seul.
- Mise en œuvre : session voisine, lot séparé APRÈS la mise en ligne (KioskLoyaltyComponent, hors fichiers borne gelés).

## Mise en ligne (feu vert propriétaire « après l'audit »)

1. Site 6208324 : en ligne (session voisine, vercel --prod ; www.lecayenne.fr/api.js sert coupon_code, vérifié).
2. Backend : PHPUnit complet relancé sur f60b64974 par la session voisine, déploiement par elle une fois vert.
3. App : check:www à jour (285 fichiers) + cap sync iOS/Android dans le worktree site-deploy-2026-11-03 ; copies natives identiques à www, coupon_code présent. Aucun build store, aucun envoi.

## Effets de bord de l'audit (base de dev partagée foodking_e2e)

Ventes de test scellées NF525 (non annulables) laissées : n° fiscaux 3596–3629 environ (commandes 9785–9934 listées dans les rapports de vague). Commande 9792 (session voisine) encaissée au tour 3. `Cache::flush()` du Redis de dev au tour 5 (13h55-14h00). Réglages `horaires` restaurés (aucune fermeture de test restante, vérifié).

Rapports de vague : `round-3/`, `round-4/`, `round-5/` (`wave-*-findings.json`).
