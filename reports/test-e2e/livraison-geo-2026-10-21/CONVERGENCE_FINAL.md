# Livraison / géolocalisation — audit E2E supervisé (2026-10-21)

Périmètre : barème commune + rayon, géocodage BAN, tournées 30 min, caisse `/admin/pos`, écran livreur `/livreur`, API publique du site.
Équipes : GStack (captures, correctifs) · superviseur adversarial API · superviseur adversarial visuel (×2 passes) · audit du site.

## Verdict

- **Convergence technique : atteinte au tour 7** — tours 6 et 7 : 10/10 scénarios, 189 vérifications, 0 échec, ensembles de constats identiques.
- **Relecture visuelle adversariale** : tour 1 → 1 P0 provisoire + 6 P1 ; tour 5 → 1 P1 ouvert (V-03), corrigé ensuite et constaté par lecture
  directe de l'image du tour 7. **Le superviseur visuel n'a pas relu le tour 7** : le dernier signe-off est celui du pilote, sur les défauts qu'il avait relevés.
- **Chaîne fiscale** : `fiscal:verify-chain --all` → CHAIN OK sur les 7 branches avant et après chaque tour.

## Corrigé (commits `fix(livraison)` du jour)

| # | Sév. | Défaut | Preuve que le test mord |
|---|---|---|---|
| F1 | P1 | Un compteur de débit partagé par IP bloquait le devis (21 appels à /status → 429) | test échoue sur l'ancien routage |
| ADV-1 | **P0** | `payment_method=5` (titre-restaurant) sur une livraison : livrée « payée », numéro fiscal, aucun paiement | test échoue sans le garde ; rejoué en réel : 422 |
| ADV-4 | P2 | Même défaut côté caisse (30 suggestions bloquaient bascule et adresse) | test échoue sur l'ancien routage |
| P-1/V-07 | P1 | Texte illisible : aucun message | check du banc KO tour 1, OK ensuite |
| P-2/V-06 | P1 | Refus hors zone : toast « choisissez dans la liste » | idem |
| P-3/V-04 | P1 | Champ étage/interphone inatteignable | idem |
| P-4 | P1 | Message « pas prête » effacé après « Je pars » (puis hors cadre) | idem + mesure de visibilité |
| P-5/V-05 | P1 | Remise terminée réclamait encore l'encaissement | idem |
| P-6/V-02 | P1 | Devis rogné sous l'en-tête défilant (puis estompé par le voile) | mesure ajoutée, mord avec défaut injecté |
| V-03 | P1 | Numéro de rue perdu avant d'arriver au livreur | test échoue sans le correctif |

## Reste ouvert — à décider (ne bloque pas le banc, mais pas fermé)

1. **TVA des frais de livraison sur le ticket** (zone gelée `PricingService`, décision propriétaire). Le Z fiscal applique déjà 10 % (`ZReportService`, 07/07) ;
   le ticket ne montre que la TVA des articles. **Escaladé, non touché.**
2. **Site (dépôt séparé, non modifié)** : copie « pas de livraison en propre » partout (llms.txt, CGV, SEO, JSON-LD) ; paiement « Payer sur place » et
   confirmation « Ticket retrait » en mode livraison ; suggestions sans debounce (429 muets) ; `app/www` périmé. **À corriger avant d'ouvrir au public.**
3. **Avant ouverture en production** : `free_delivery_above` = 0 ; `users.branch_id` du livreur > 0 ; `php artisan cache:clear` au déploiement (anciens géocodages en cache).
4. P2 non traités : `deliver` sans transaction ni plafond sur `collected_cents` ; BAN qui substitue une rue ou une commune sans code postal ; un compte peut occuper
   18 créneaux en espèces ; adresse sans étage sur le ticket client ; V-09…V-15 cosmétiques (✓ sur l'adresse, « VAT »/« AM », champs de connexion sans étiquette, contrastes, TypeError à l'ouverture du paiement).

## Instruments — ce qui a faussé et a été corrigé en route

- 2e serveur lancé hors `public/` : « 200 » = page d'erreur PHP.
- Un check « 0 KO » alors que l'image montrait le défaut (rien ne mesurait le rognage) → mesure ajoutée, prouvée avec un défaut injecté.
- Fuite d'état entre viewports et tournées saturées par les essais précédents (plusieurs livreurs actifs = pas d'attribution automatique, comportement voulu).
- Captures prises à 03 h : horaires du soir, ponts d'impression :9100/:9101 éteints (bruit connu).

Rapports détaillés : `round-1/` (GSTACK, ADVERSARIAL_API, ADVERSARIAL_VISUAL, SITE) · `round-5/ADVERSARIAL_VISUAL_REREVIEW.md`.
