# Bilan — audit E2E caisse + site (2026-10-23)

Banc : caisse `:8766` (base locale, bundles recompilés) ; site = **miroir du site EN LIGNE** (`:8012`, empreintes identiques à
www.lecayenne.fr, rebranché sur le backend local). Aucune commande ni appel vers la production. Deux rounds, relecture adverse
indépendante à chaque round (constats : `round-1/`, `round-2/`).

## Verdict par vague

| Vague | Round 1 | Round 2 | Ouverts bloquants |
|---|---|---|---|
| C2 — caisse livraison + écran livreur | ROUGE (1 P1, 15 P2, 6 P3) | **VERT** (0 P0, 0 P1 ; 11 fermetures confirmées, aucune régression) | — (un 2ᵉ tour vert reste à faire pour la convergence) |
| C1 — caisse vente, ticket | ROUGE (3 P1) | ROUGE (2 P1) | C1-001 (arbitrage de mise en page), C1-002 (zone gelée) |
| S — site en ligne | ROUGE (1 P0, 3 P1) | non rejoué (corrections dans le dépôt du site) | S-101 P0, S-102/103/104 P1 |

Vérifié de bout en bout et juste : 9,30 € identique panier = paiement = ticket ESC/POS = base ; « Caissier : » = l'opérateur ;
numéros fiscaux uniques (chaîne 3180→3195 sans trou) ; « Commande livraison » : 6,80 € identique devis = toast = base = planche
= écran livreur = encaissé, scellée à la remise (numéro fiscal une fois, remise rejouée → 409) ; aller simple côté client,
aller-retour côté livreur.

## Corrigé pendant l'audit (commits `e0728f09b`..`084e36797`, non poussés)

- **C2-001 (P1)** : `TypeError … reading 'id'` à chaque « Commande livraison ». Cause prouvée avec le vrai `vue-next-select` :
  la valeur arrivait avant son option ; la garde M4-104 du 21/10 ne fonctionnait pas dans un vrai parent (son test ne vérifiait
  que le calcul isolé). Option posée un cycle avant la valeur.
- **C1-003 (P1)** : le tiroir annonçait « à encaisser » des livraisons payées à la porte (bouton « Encaisser » refusé 422,
  18 pour 16). Aligné sur la règle exacte du comptoir ; parité tiroir/file prouvée (16 = 16).
- Planche / écran livreur : arrêt remis « Encaissé … (espèces) », tournée « terminée », ETA jamais dans le passé, tournées à faire
  d'abord, contraste, « Quitter » 44 px confirmé, photo au clavier, ville non répétée, espace manquant.
- Bouton « Commande livraison · paiement à la porte » aux couleurs de la charte ; refus sans téléphone qui amène au champ.
- Commandes Uber Eats exclues des tournées (une ERROR par commande Uber Eats dans le journal de production).

## Décisions qui vous reviennent

1. **S-101 (P0) — le Cayenne ne peut pas être commandé sur lecayenne.fr** (et Sandwich Classique, Tacos XL) : le site impose 2
   viandes, le serveur de production 1 (resp. 1, 3) depuis le 22/10. Correctif prêt et VALIDÉ (Cayenne → HTTP 201) :
   `scratchpad/correctif-site/S-101-viandes.diff` (3 lignes de `data/menu.js`) + bump du `?v=` de `data/menu.js` dans `index.html`.
   Bloqué : le dépôt du site a deux lignes divergentes et `git fetch` y échoue (« repository not found ») ; le site en ligne a
   été déployé depuis une copie non commitée. Il faut choisir la ligne de référence, puis déployer (Vercel).
2. **S-104 (P1)** : le site en ligne n'a pas la livraison propre (tuile Uber Eats seulement) alors que caisse et serveur livrent.
3. **S-102/S-103 (P1)** : Bols / Tacos « Mixte » refusés selon le choix ; message de refus hors écran et inutilisable.
4. **C1-002 (P1)** : tuiles viande écrasées dans l'assistant caisse — `public/css/pos-wizard.css`, ZONE GELÉE (LOCK requis).
5. **C1-001 (P1)** : sélecteur emporter/livraison coupé en haut du panier avec un panier rempli. Le plancher du corps (28vh) ne peut
   descendre qu'à ≈ 23vh sans casser la sentinelle C3 (zoom 0,9) : +49 px pour ≈ 77 nécessaires. Il faut repenser l'en-tête
   du panier (ex. sélecteur fixé hors de la zone qui défile).
6. **Itinéraires** : `DELIVERY_ROUTING_ENABLED` absent du `.env` de production → coordonnées des domiciles envoyées au serveur
   OSRM public de démonstration.

## Ouverts non bloquants (P2/P3, détail dans les JSON)

Tiroir en retard de ~40 s sur la file après une commande téléphone ; aperçu ticket « PM »/« VAT »/prix HT ; 404 `item/details/1`
avalé ; durées ambiguës ; pas de signal « tournée sans livreur » ; photo de preuve non consultable en caisse ; en-tête collant de
la page Livraisons ; « Commande téléphone » en indigo ; banc pollué par les données de test des audits successifs.

PHPUnit complet (avant ces correctifs) : 7 158 tests, 3 échecs antérieurs et stables, hors périmètre (sentinelle
`withoutGlobalScopes` sur `ReviewAccountCommand`, note Uber). Après correctifs : Vitest complet 5 840 verts ; suites
Delivery/Pos/Uber ciblées vertes hors ce même échec Uber.
