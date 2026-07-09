# 🎭 MATRICE DE SCÉNARIOS PERSONA — ultra-audit utilisation réelle (2026-07-08)

> Principe : je **joue un humain réel** devant chaque écran, avec son raisonnement
> (« je veux un tacos pas trop épicé, sans oignon, avec un coca »), ses hésitations
> (retour arrière, changement d'avis), ses erreurs (double-tap, abandon). Chaque
> scénario = déroulé complet + assertions (prix, état, synchro). Un échec = bug → fix → re-test.

## Personas
- **Sofiane, 24 ans** — client borne pressé, commande vite, change d'avis, teste le tactile.
- **Mme Diallo, 46 ans** — cliente borne posée, lit tout, personnalise beaucoup (sans oignon, sauce à part), prend un menu enfant pour sa fille.
- **Karim** — caissier en plein rush : enchaîne commandes comptoir + téléphone + encaissements borne, se trompe et corrige.
- **Léa** — cliente web depuis chez elle (livraison).
- **Chef Momo** — cuisine devant le KDS : reçoit, prépare, bump.

---

## C1 — BORNE (persona client) — 14 scénarios

| # | Scénario (raisonnement du persona) | Étapes clés | Assertions |
|---|---|---|---|
| B1 | Sofiane : « juste un coca » | idle→tap→Boissons→Coca→Payer→Valider→(upsell) non merci→paiement→confirmer | total 1,90 ; n° A00xx ; cash-instruction ; retour idle |
| B2 | Sofiane : Tacos M complet « poulet, algérienne, sans oignon cru, avec oignons cuits » | wizard : viande→sauce→crudités (décocher Oignon, cocher Oignons cuits → EXCLUSIVITÉ) →supp→sans menu→récap | oignon cru se décoche seul ; total 6,90 |
| B3 | Mme Diallo : Tacos L 2 viandes + menu complet | viande1+viande2→sauce→crudités→menu complet (frites+boisson au choix) | 2 viandes distinctes exigées ; prix = 7,90+2,50 |
| B4 | Méga : 2 viandes + pain + supplément payant (Cheddar) | pain→viande×2→sauce→crudités→supp Cheddar 0,90 | total 8,90 ; récap liste le supplément |
| B5 | Menu Enfant Chicken Burger pour la fille | catégorie Menu enfant→ajout direct | 4,90 ; pas de wizard bloquant |
| B6 | Panier multi-articles : B2+B5+2 boissons, puis passe la qté coca à 2, supprime le menu enfant | panier : +/-/suppr | totaux recalculés exacts à chaque action |
| B7 | Changement d'avis : ÉDITER le tacos du panier (changer la sauce) | panier→éditer→wizard pré-rempli→changer sauce→enregistrer | compo mise à jour, prix inchangé, pas de doublon |
| B8 | Upsell : accepte un dessert proposé | après Valider→upsell→tap Tiramisu | ajouté 3,50 ; images ENTIÈRES visibles |
| B9 | Abandon en cours : wizard puis « abandonner l'article » ; puis panier→« abandonner ma commande » | | retour propre, panier vidé, pas d'ordre créé |
| B10 | Inactivité : rester immobile → overlay « Toujours là ? » → « Je suis là » puis re-test → laisser expirer | | overlay lisible (boutons contrastés) ; expiry→idle+panier vidé |
| B11 | Double-tap rapide sur « Ajouter » (2×) | | pas de double ligne fantôme |
| B12 | Paiement : confirmer comptoir | | commande PENDING_COUNTER ; apparaît à la caisse « à encaisser » ; KDS la voit |
| B13 | Filtres allergènes/régime (si visibles) : activer végétarien | | produits non-végé grisés/cachés cohérents |
| B14 | Reprise après erreur réseau simulée (couper le serveur 10 s pendant le devis) | | message d'erreur clair, pas de page blanche, retry OK |

## C2 — CAISSE (persona caissier Karim) — 15 scénarios

| # | Scénario | Étapes | Assertions |
|---|---|---|---|
| K1 | Vente comptoir simple : 1 Coca, espèces | grille→Boissons→Coca→Commande→Espèces→montant reçu 2,00 | rendu 0,10 ; tiroir (ordre pont) ; ticket ; n° ≥ A0032 |
| K2 | Cayenne composé : pain galette, sans tomate, supp Jambon | wizard caisse | prix 7,40+0,90 ; aperçu ticket correct |
| K3 | Carte TPE : payer 16,70 carte, 4 derniers chiffres | Carte (TPE)→TPE simulation→1234→confirmer | pos_payment_method=2 ; pas de « Aucun TPE » |
| K4 | Multi-paiement : 20 € en 10 carte + reste espèces | Multi-paiement→tranches | reste dû 0 ; 2 paiements enregistrés |
| K5 | Commande TÉLÉPHONE : « M. Bernard, 06…, 2 tacos » → noté, PAS encaissé | produits→Commande téléphone (nom+tél) | file à encaisser avec 📞 ; cuisine reçoit ; PENDING_COUNTER |
| K6 | Client Bernard arrive : encaisser sa commande téléphone en espèces | file→Encaisser→espèces | payé ; disparaît de la file ; ticket |
| K7 | Encaisser une commande BORNE de la file (créée en B12) | file→Encaisser | même flux ; totaux identiques borne/caisse |
| K8 | IMPRIMER CUISINE avant encaissement (nouveau bouton 🖨️) | file→🖨️ Cuisine | GET /escpos?ticket=kitchen 200 |
| K9 | Modifier ligne panier (✎) depuis le hub catégories | ajout→hub→✎ | wizard rouvre pré-rempli (fix récent revalidé) |
| K10 | Mettre en attente / reprendre | panier→Mettre en attente→nouvelle commande→reprendre | panier restauré intact |
| K11 | Remise 10% avec motif | panier→remise+motif | total recalculé ; motif obligatoire |
| K12 | Annuler dernière ligne / vider panier | | états cohérents, pas de résidu |
| K13 | Livraison : type Livraison + adresse/tél | | frais livraison appliqués selon règle |
| K14 | Erreur volontaire : carte sans 4 chiffres → 422 lisible ; puis corriger | | message clair, pas silencieux |
| K15 | Rush : 3 commandes enchaînées vite (K1×3) | | n° séquentiels sans trou ni doublon ; UI ne se bloque pas |

## C3 — WEB (Léa) + KDS (Chef Momo) + MOBILE

| # | Scénario | Assertions |
|---|---|---|
| W1 | Léa parcourt le menu web, compose un burger, panier, checkout livraison | commande créée source=web ; frais 4€/≤5km |
| W2 | La commande web apparaît à la caisse + KDS | synchro <5s ou polling |
| M1 | Audit mobile : menu.js aligné DB (produits/prix/Chicken Burger/crudités tacos) | écarts listés → fix |
| KDS1 | Toutes les commandes des tests (B12, K5, W1) visibles au KDS ; bump ; O̲ oignons cuits | affichage 3 cartes ; symbolique correcte |

## Règles de validation (chaque scénario)
1. **Prix affiché == devis serveur == ticket == KDS** (intégrité numérique NF525).
2. **0 page blanche, 0 erreur console applicative, 0 4xx silencieux.**
3. **Raisonnement persona documenté** (ce que l'humain attendait vs ce qui s'est passé).
4. Bug trouvé → fix scope-minimal (LOCK si frozen) → re-jouer le scénario → vert.
5. Convergence : tous scénarios verts 1 passe complète + re-passe des scénarios corrigés.
