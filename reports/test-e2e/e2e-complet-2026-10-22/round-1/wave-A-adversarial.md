# Vague A (site) - revue adverse, tour 1

Verdict : **GREEN** selon la regle imposee (0 P0/P1 open de disposition `fix`). Au sens strict du schema : **AMBER** (4 P1 open, tous hors `fix` : A-001, A-002, A-003, A-004). Detail : `wave-A-findings.json` (13 constats : 0 P0, 4 P1, 5 P2, 4 P3).

## Ce que j'ai regarde
- Visuel (Read) : 27 PNG de `tests/e2e/__screenshots__/e2e-complet-A/` (accueil D/M, carte D/M, fiche, wizard etape 1/3/recap D et M, panier D/M, upsell, commande D/M, confirmation D/M, suivi D/M, mes commandes D/M, fidelite D/M, horaires, livraison, allergenes, 404, roue, base-M1 12/13/23, base-M2 09/11/15/16) et 2 PNG de moi (`wave-A-adv-evidence/`).
- Technique : balayage automatique des `.network.json` / `.console.json` des ~76 etats ; lecture des DOM cites ; SQL en lecture sur `foodking_e2e` ; GET locaux sur :8766 ; lecture du code du site (`api.js`, `funnel.jsx`, `screens.jsx`, `data/menu.js`) et du serveur (`WaitEstimateService`, `OrderRequest`, `KitchenDisplaySystemOrderService`).
- Mon propre banc : 1 Chromium sur le miroir :8011, a 01h50 (service ferme), sans aucun POST (ecoute des requetes non-GET = liste vide).

## Ce que j'ai refute / corrige chez l'equipe de capture
- **« Aucun P0 nouveau, tout est connu »** : faux pour deux P1. A-001 (le site laisse commander « des que pret ~10-15 min » service ferme ; absent du ledger) et A-002 (fiches produit : allergenes = « Gluten » seul sur des produits au cheddar ; le serveur n'a aucune donnee, `allergen_flags = []`).
- **(a) Cayenne 2 viandes** : « connu » mais mal cadre. L'edit `viandes:2` vient du travail non commite de l'autre session (`git diff HEAD -- data/menu.js` ; le HEAD deploye a `viandes:0`). Et le client ne voit pas le 422 serveur : `api.js:327-330` le remplace par « Certaines informations sont incompletes... Verifie tes coordonnees » alors que le wizard IMPOSE 2 viandes. Pas silencieux, mais inactionnable. Ecran jamais capture (A-006).
- **(b) roue.html -> production** : trou d'instrument, pas defaut produit (prod = configuration voulue ; l'equipe ne reecrit que index.html). Le GET prod est en lecture et 404 ; le meme 404 est reproduit en local, l'ecran « jeu pas encore ouvert » est correct. Risque reel : un test local jeu ouvert frapperait la production.
- **(c) totaux** : chaine confirmee (8657 = 6,00 ; 8659 = 4,90 ; somme des lignes ; prix SQL = affiches). Pas d'ecart. L'ecart « ~10-15 min » (commande) vs « ~17-22 min » (suivi apres acceptation) est voulu (`OrderTrackingService` lit le delai fixe par la caisse).
- **(d) contrastes** : 3,12 / 2,73 / 4,15 / 2,65 recalcules, exacts. Precision : les autres CTA du site passent (#c2410c = 5,18:1), seul le wizard et le toast utilisent #ff5a1f. Reste P2 (A-008).
- **C-4 (KDS 50)** : reste environnement + connu (plafond 50 pre-existant dans `KitchenDisplaySystemOrderService.php:255`).
- **C-6 (« 12:16 AM »)** : d'accord, P3 ; le code mort de `fmtOrderDate` est reel (Invalid Date verifie sous Node 22), donc meme avec `H:i` le client lit la chaine brute (A-009).
- Faux positifs d'instrument ecartes par l'equipe : relus, ils tiennent.
- Ecarte par moi (non retenu) : TVA absente de `orders.total_tax` sur les frais de livraison (8663 : tax 0,09 sur 4,90). Le `ZReportService.php:536-548` rattache bien la TVA des frais au seau du taux alimentaire : pas un defaut de cloture, et zone gelee.

## Ce que je n'ai pas pu verifier
- Le comportement du site DEPLOYE (HEAD) sur la Cayenne : aucun POST autorise.
- Les creneaux 00:30-00:59 en direct (j'etais a 01:50) : lu dans le code seulement.
- Fantomes de texte derriere la barre de navigation mobile (base-M2 09, 11, 15) : `backdrop-filter: blur(18px)` est declare (`styles.css:138`) ; je ne sais pas si c'est un artefact de capture. Non retenu, a rejouer.
- Mollie / Apple Pay, temps reel (pas de latence mesuree), Safari/tablettes, PWA/hors-ligne.
- Prod : `.env` de production non lu (TIME_FORMAT).

## Transparence
- Un `ssh -o BatchMode=yes lecayenne true` a ete lance par erreur vers la production (connexion uniquement, rien lu ni ecrit). Aucune requete HTTP vers la production de mon fait.
- Fichiers ajoutes : `wave-A-findings.json`, ce fichier, `wave-A-adv-evidence/` (2 PNG + le script lecture seule). Rien du produit n'a ete modifie.
