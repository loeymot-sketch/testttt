# Comparaison massive FoodKing vs Splash360 et concurrents POS restaurant

Date : 2026-05-05  
Portee : systeme global, interfaces, caisse POS, borne, KDS, admin, stock, produits, operations, fiscal, release-readiness.  
Mode : benchmark documentaire + lecture du depot + sources publiques officielles.

## 1. Verdict executif

FoodKing est deja plus profond qu'une simple caisse : le depot contient un monolithe Laravel/Vue avec surfaces admin, POS, KDS, OSS et kiosk web, temps reel par branche, permissions, outbox, fiscal, stock/rupture, catalogue produit et une discipline de tests importante. La documentation locale confirme que le backend est la source de verite du prix, que les transitions de commande passent par une machine d'etat, que les surfaces POS/Kiosk/KDS/OSS sont separees, et que l'isolation `branch_id` est un invariant central.

Face aux concurrents, FoodKing est fort sur la maitrise metier, la personnalisation, le controle fiscal francais et la coherence multi-surfaces. Les concurrents comme Toast, Square, Lightspeed, Innovorder, Zelty ou Oracle Simphony sont plus matures sur le packaging commercial, l'ecosysteme materiel, les integrations, l'onboarding, les tableaux de bord prets a vendre, le support multi-sites industrialise et les preuves terrain.

Le risque principal de FoodKing n'est pas le manque de fonctionnalites brutes. C'est la finition produit : UX caisse encore heterogene, release HOLD, preuves hardware/UAT/runtime manquantes, packaging concurrentiel absent, et gestion stock encore orientee disponibilite/rupture plutot qu'inventaire complet avec achats, fournisseurs, cout matiere et previsions.

## 2. Sources et limites

### Sources internes FoodKing

- `README.md:3` : Laravel 9 + SPA Vue 3, admin, POS, KDS, OSS, kiosk web, MySQL, Sanctum, Spatie, broadcasting/Soketi/Pusher, FCM.
- `README.md:90-98` : le depot contient API REST, SPA admin et kiosk web ; le shell Electron borne et apps mobiles peuvent etre hors depot.
- `docs/BUSINESS_RULES.md:11-18` : le frontend n'a pas le droit de fixer le prix ; le backend recalcule depuis les items.
- `docs/BUSINESS_RULES.md:57-68` : `OrderStatus` enum, pipeline, interdiction de transitions invalides, isolation branche.
- `docs/BUSINESS_RULES.md:81-96` : stock V1 = disponibilite/rupture par branche, toggle admin, verification a la commande, temps reel, cache kiosk, quotas journaliers.
- `docs/BUSINESS_RULES.md:98-120` : briques fiscales NF525/Z, audit HMAC, branch_id fiscal.
- `docs/ORDER_FLOW.md:5-9` : SOT = MySQL + `OrderService` + `FrontendOrderService`, machine d'etat.
- `docs/ORDER_FLOW.md:93-116` : qui cree, valide, prepare, livre les commandes.
- `docs/ORDER_FLOW.md:131-153` : invariants, outbox apres commit, audit transitions, tests.
- `docs/DEVICE_FLOW.md:5-28` : roles Kiosk, POS, KDS, OSS.
- `reports/release/CAISSE_V1_FINAL_READINESS_WAVES_2026-04-26.md:8-17` : verdict HOLD malgre preuves locales.
- `reports/release/CAISSE_V1_FINAL_READINESS_WAVES_2026-04-26.md:54-93` : 82 tests PHP cibles + Vitest + guards.
- `reports/release/CAISSE_V1_FINAL_READINESS_WAVES_2026-04-26.md:116-158` : build OK mais cutover legacy/POS wizard encore HOLD.
- `reports/release/CAISSE_V1_FINAL_READINESS_WAVES_2026-04-26.md:199-243` : hardware lab et evidence fiscale reelle manquants.
- `reports/release/CAISSE_V1_FINAL_READINESS_WAVES_2026-04-26.md:263-328` : UAT/runbooks/pilot watch/release decision restent HOLD.
- Captures locales consultees : POS dashboard, Kiosk accueil, KDS grid, floor plan.

### Sources publiques concurrentielles

Sources officielles consultees le 2026-05-05 :

- Splash360 : https://splash360.fr/
- Toast POS : https://pos.toasttab.com/products/restaurant-pos ; https://pos.toasttab.com/products/self-ordering-kiosk ; https://pos.toasttab.com/products/kitchen-display-system ; https://pos.toasttab.com/products/inventory-management
- Square Restaurants : https://squareup.com/us/en/point-of-sale/restaurants ; https://squareup.com/us/en/point-of-sale/restaurants/kiosk ; https://squareup.com/us/en/point-of-sale/restaurants/kitchen-display-system
- Lightspeed Restaurant : https://www.lightspeedhq.com/pos/restaurant/ ; https://www.lightspeedhq.com/pos/restaurant/inventory/
- Oracle Simphony : https://www.oracle.com/food-beverage/pos-systems/
- Innovorder : https://www.innovorder.com/
- Zelty : https://www.zelty.fr/
- L'Addition : https://www.laddition.com/
- Tiller by SumUp : https://www.tiller.fr/

Limite importante : les pages publiques de concurrents sont marketing. Elles prouvent l'existence commerciale de modules, pas leur qualite reelle, leur profondeur de workflow, ni leur adaptation exacte a un restaurant type Le Cayenne.

## 3. Positionnement par concurrent

### FoodKing

Position : produit vertical proprietaire, tres controle, adapte au terrain restaurant rapide/tacos/borne/POS/KDS, avec logique fiscale et sync critique dans le coeur.  
Force : maitrise des invariants, personnalisation, coherence back-end, fiscal local, testabilite.  
Faiblesse : maturite commerciale et release. Le rapport final-readiness reste HOLD, notamment hardware, UAT, runtime, cutover, fiscal evidence.

### Splash360

Position : solution commerciale de caisse/gestion restaurant presentee comme un systeme operationnel pret a l'emploi.  
Force probable : simplicite de vente, packaging, interface deja orientee client final, moins de complexite technique visible.  
Risque pour nous : si Splash360 propose une interface plus claire et un parcours plus immediat pour POS/admin/stock, le client percoit Splash360 comme plus mature meme si FoodKing est plus solide techniquement.

Lecture concurrentielle : Splash360 est le concurrent direct a battre sur perception de simplicite. FoodKing doit eviter de se presenter comme "logiciel en chantier" et devenir un produit demo-first.

### Toast

Position : plateforme restaurant US tres mature, avec POS, self-ordering kiosk, KDS, inventaire, paiements, payroll, reporting et ecosysteme.  
Force : profondeur operationnelle, hardware, integrations, modules payants, onboarding, reseau client.  
Faiblesse vs FoodKing : fiscal francais et personnalisation locale potentiellement moins native ; cout et dependance ecosysteme.

Lecture concurrentielle : Toast est le benchmark haut de gamme "tout integre". FoodKing ne doit pas essayer de copier tout Toast d'un coup ; il faut battre Toast localement sur fiscal FR, workflow tacos, borne/POS/KDS synchronises, et flexibilite.

### Square for Restaurants

Position : POS restaurant accessible, fort sur paiement, hardware, ecosysteme PME, kiosk et KDS.  
Force : UX propre, activation rapide, paiements natifs, hardware lisible, marketplace.  
Faiblesse vs FoodKing : adaptation complexe au metier custom et fiscal local moins controlee si on sort des flux standards.

Lecture concurrentielle : Square est le benchmark d'ergonomie et de packaging. FoodKing doit s'inspirer de sa clarte : caisse lisible, actions evidentes, onboarding court, dashboards simples.

### Lightspeed Restaurant

Position : POS restaurant/cloud avec inventaire, multi-sites, reporting, integrations.  
Force : gestion restaurant mature, stock plus avance, rapports, multi-etablissements.  
Faiblesse vs FoodKing : personnalisation specifique borne/tacos/fiscal local potentiellement moins directe.

Lecture concurrentielle : Lightspeed est le benchmark stock/admin. FoodKing doit renforcer achats, inventaire quantitatif, cout matiere et alertes.

### Oracle MICROS Simphony

Position : enterprise POS pour chaines, hotels, gros reseaux, multi-sites et integrations lourdes.  
Force : robustesse enterprise, scalabilite, integrabilite, deploiement multi-sites.  
Faiblesse vs FoodKing : cout, complexite, lourdeur, moins adapte a un restaurateur qui veut de la vitesse terrain.

Lecture concurrentielle : Oracle n'est pas a battre fonctionnalite par fonctionnalite. Il sert de reference pour gouvernance, roles, audit, reporting et multi-branch enterprise.

### Innovorder

Position : acteur francais QSR/restaurant avec caisse, borne, commande en ligne, kitchen/order management, back-office.  
Force : marche FR, ecosysteme borne + caisse + production + click&collect, image moderne.  
Faiblesse vs FoodKing : dependance SaaS standard ; personnalisation locale et acces code plus limites.

Lecture concurrentielle : Innovorder est le concurrent francais le plus dangereux sur borne + caisse + production. FoodKing doit assumer la meme promesse : "tout est synchronise", mais avec preuves techniques et workflow terrain.

### Zelty

Position : solution caisse restaurant orientee omnicanal, back-office, franchises, integrations.  
Force : API/omnichannel, back-office, multi-sites, ecosyteme restaurant.  
Faiblesse vs FoodKing : differenciation locale et fiscal/personnalisation a verifier selon client.

Lecture concurrentielle : Zelty est un bon benchmark pour back-office, API et reseaux/franchises.

### L'Addition

Position : caisse restaurant FR orientee restauration traditionnelle, commande, encaissement, salle, reservations/relations selon modules.  
Force : UX terrain restaurant, salle, service, notoriéte FR.  
Faiblesse vs FoodKing : moins oriente borne rapide/KDS custom selon les offres.

Lecture concurrentielle : L'Addition est un benchmark POS/salle/service, surtout si FoodKing vise aussi dine-in et plan de salle.

### Tiller by SumUp

Position : caisse restaurant/commerces avec ecosysteme SumUp, paiement, back-office, simplicite.  
Force : paiement et activation commerciale, image simple PME.  
Faiblesse vs FoodKing : profondeur metier custom, borne/KDS/sync/fiscal avance a verifier.

Lecture concurrentielle : Tiller/SumUp est le benchmark "simple a acheter, simple a installer".

## 4. Matrice fonctionnelle massive

Legende :

- `++` = fort ou avance
- `+` = present / correct
- `~` = partiel, depend des modules, ou evidence insuffisante
- `-` = absent/non prioritaire
- `?` = non confirme par source publique ou depot

| Domaine | FoodKing | Splash360 | Toast | Square | Lightspeed | Oracle | Innovorder | Zelty | L'Addition | Tiller |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Caisse POS | ++ | + | ++ | ++ | ++ | ++ | ++ | ++ | ++ | + |
| Borne self-order | ++ | ~ | ++ | ++ | ~ | + | ++ | + | ~ | ~ |
| KDS cuisine | ++ | ~ | ++ | ++ | + | ++ | ++ | + | + | ~ |
| Ecran client / OSS | + | ~ | + | ~ | ~ | + | + | ~ | ~ | ~ |
| Admin back-office | + | + | ++ | + | ++ | ++ | ++ | ++ | + | + |
| Produits/catalogue | ++ | + | + | + | ++ | ++ | ++ | ++ | + | + |
| Wizard produit/composition | ++ | ~ | ~ | ~ | ~ | ~ | + | ~ | ~ | ~ |
| Photos produits | + | + | + | + | + | + | + | + | + | + |
| Variations/extras/addons | ++ | + | ++ | + | ++ | ++ | + | + | + | + |
| Allergens snapshot | + | ? | ~ | ~ | ~ | ~ | ~ | ~ | ~ | ~ |
| Loyalty | + | ~ | ++ | ++ | + | + | + | + | + | + |
| Upsell borne | + | ~ | + | ~ | + | + | + | ~ | ~ | ~ |
| Promo carousel borne | + | ~ | ~ | ~ | ~ | ~ | ~ | ~ | ~ | ~ |
| Paiement cash POS | ++ | + | ++ | ++ | ++ | ++ | ++ | ++ | ++ | ++ |
| Paiement carte integre | ~ | + | ++ | ++ | ++ | ++ | ++ | + | ++ | ++ |
| TPE externe restreint | + | + | ~ | ~ | ~ | ~ | + | + | + | ++ |
| Encaissement cash borne au comptoir | ++ | ~ | ~ | ~ | ~ | ~ | + | ~ | ~ | ~ |
| Fiscal Z / NF525 local | ++ | + | ~ | ~ | ~ | + | ++ | + | ++ | + |
| Audit hash/HMAC | ++ | ? | ? | ? | ? | ++ | ? | ? | ? | ? |
| Outbox apres commit | ++ | ? | ? | ? | ? | + | ? | ? | ? | ? |
| Branch isolation | ++ | + | ++ | + | ++ | ++ | ++ | ++ | + | + |
| Multi-branch admin | + | + | ++ | + | ++ | ++ | ++ | ++ | + | + |
| Real-time POS/KDS/Kiosk | ++ | + | ++ | ++ | + | ++ | ++ | + | + | ~ |
| Offline borne | ~ | ? | + | ~ | ~ | + | ~ | ~ | ~ | ~ |
| Sync fallback polling | + | ? | ? | ? | ? | + | ? | ? | ? | ? |
| Disponibilite/rupture par branche | ++ | + | + | + | ++ | ++ | ++ | + | + | + |
| Inventaire quantitatif complet | ~ | + | ++ | + | ++ | ++ | + | + | + | + |
| Achats/fournisseurs | -/~ | ~ | + | ~ | ++ | ++ | + | ~ | ~ | ~ |
| Cout matiere / COGS | -/~ | ~ | ++ | ~ | ++ | ++ | + | ~ | ~ | ~ |
| Alertes stock bas | + | + | ++ | + | ++ | ++ | + | + | + | + |
| Plan de salle | ~ | + | ++ | ++ | ++ | ++ | + | + | ++ | + |
| Reservations | - | ~ | + | + | + | ++ | ~ | ~ | ++ | ~ |
| Delivery / livreur | frozen | ~ | ++ | + | + | ++ | + | + | + | ~ |
| Click & collect | + | + | ++ | ++ | ++ | ++ | ++ | ++ | + | + |
| Reporting ventes | + | + | ++ | ++ | ++ | ++ | ++ | ++ | ++ | + |
| Reporting fiscal | ++ | + | ~ | ~ | ~ | ++ | ++ | + | ++ | + |
| Observabilite technique | ++ | ? | ? | ? | ? | ++ | ? | ? | ? | ? |
| Runbooks ops | + | ? | ++ | + | ++ | ++ | + | ? | ? | ? |
| Hardware certifie/propose | HOLD | + | ++ | ++ | ++ | ++ | ++ | + | ++ | ++ |
| Support/onboarding commercial | -/~ | + | ++ | ++ | ++ | ++ | ++ | ++ | ++ | ++ |
| Marketplace integrations | -/~ | ~ | ++ | ++ | ++ | ++ | ++ | ++ | + | ++ |
| API publique/documentee | ~ | ? | ++ | ++ | ++ | ++ | + | ++ | ~ | + |
| Tests automatises visibles | ++ local | ? | ? | ? | ? | ? | ? | ? | ? | ? |
| Release readiness terrain | HOLD | + | ++ | ++ | ++ | ++ | ++ | + | + | + |

## 5. Analyse interface par surface

### 5.1 POS / Caisse

Constat FoodKing :

- L'ecran POS existe, avec ticket lateral, recherche, type de commande, parking, floor plan, paiement cash/card, tiroir-caisse, quote, loyalty, commandes borne cash et sync disponibilite.
- La capture POS montre une structure fonctionnelle mais encore peu dense : grande zone vide, header hero tres large, ticket lateral fort, mais peu de categories/produits visibles dans l'etat capture.
- L'UX actuelle peut paraitre moins "caisse pro" que Square/Toast/Lightspeed : ces concurrents optimisent la densite tactile, les boutons produits, les categories, les paiements et le temps d'encaissement.

Forces FoodKing :

- Flux securise : prix backend, quote, paiement restreint, statuts controles.
- Kiosk cash panel integre au POS.
- Parking orders, floor plan, customer, delivery/takeaway, loyalty.
- Sync rupture en direct.

Faiblesses :

- First impression trop vide si les produits ne sont pas charges ou si l'etat de demo n'est pas peuple.
- Design heterogene : admin shell classique + POS custom + elements de style V4/V5.
- Il manque une experience demo "restaurant vivant" avec produits, categories, tickets, raccourcis paiement, plan de salle rempli.
- Les concurrents gagnent sur la perception : le caissier doit voir immediatement des tuiles, categories, paniers actifs, commandes en attente, alertes.

Priorite UX :

1. POS demo mode peuple par defaut.
2. Layout caisse dense : categories horizontales, produits visibles, ticket toujours stable.
3. Raccourcis clavier/tactiles : cash exact, cash 10/20/50, TPE, no sale, remise manager.
4. Barre sync : online/offline, derniere synchro, commandes borne, KDS status.
5. Vue "rush" : moins de decoration, plus d'actions.

### 5.2 Borne / Kiosk

Constat FoodKing :

- Borne web avec ecran d'accueil fort, choix sur place/a emporter, theme sombre/clair, idle timeout, panier, wizard, paiement, waiting, confirmation, erreurs.
- Le design est plus marque et plus immersif que le POS.
- Composants DS kiosk presents : boutons, modals, chips, stepper, virtual keyboard, a11y settings, allergen badge.

Forces FoodKing :

- Workflow borne complet.
- Customisation menu/tacos/wizard.
- Erreurs specifiques : produit supprime, reseau, menu indisponible, paiement refuse.
- Accessibilite et touch targets testees.
- Parite rupture/catalogue avec POS/KDS.

Faiblesses :

- Release note indique encore des risques cutover legacy et fallback i18n/devise.
- Paiement online/Stripe/offline card sont volontairement restreints dans le pilot ; commercialement cela peut sembler moins complet que Toast/Square/Innovorder.
- Besoin de preuves hardware borne reelles : ecran tactile, imprimante, TPE, mode reseau degrade.

Priorite UX :

1. Borne "fast-food production-ready" : panier toujours visible ou recap en stepper.
2. Parcours wizard plus court pour best-sellers.
3. Photos produits reelles, pas seulement UI.
4. Mode assistance : taille texte, contraste, langue, rappel allergenes.
5. Scenario offline explique proprement.

### 5.3 KDS / Cuisine

Constat FoodKing :

- KDS avec filtres All/Confirmed/Preparing/Done, station, group by table, recherche, sons, volume, badges temps reel/perte connexion, cap 50 commandes.
- Capture KDS montre beaucoup de messages de garde en haut, une colonne items board et colonnes Dine-in/Online/Takeaway/Borne.

Forces FoodKing :

- Tres bon niveau de controle operationnel : sync perdue visible, fallback, cap, station filter, dedupe, expected_status.
- Separation des sources de commandes : dine-in, online, takeaway, borne.
- KDS interdit edition prix/produit.

Faiblesses :

- Interface visuellement dense en alertes et messages techniques ; pour la cuisine, il faut simplifier.
- Les tickets prennent peu d'espace utile par rapport aux bannieres.
- Les concurrents KDS matures priorisent lisibilite a 2-3 metres, gestes rapides, bump/recall, couleurs temps, stations.

Priorite UX :

1. Mode cuisine plein ecran : cacher l'admin topbar, reduire alertes techniques, gros tickets.
2. Timer SLA visuel : vert/orange/rouge.
3. Bump/recall gros boutons tactiles.
4. Stations cuisine simplifiees : grill/frites/assemblage/boissons.
5. Mode multi-ecran KDS avec roles station.

### 5.4 Admin / Catalogue / Produits

Constat FoodKing :

- Catalog Studio, quick product, category wizard, composer par produit/categorie, image upload, availability toggle, lien stock rupture, variations/addons/extras.
- Cette zone est differenciante : beaucoup de concurrents ont catalogue + modifiers, mais le wizard produit/category de FoodKing peut etre plus adapte aux menus tacos/sandwichs complexes.

Forces FoodKing :

- Modelisation fine des compositions.
- Wizard categorie et produit.
- Publication/sync composer.
- Tests nombreux autour du composer.
- Stock/rupture inline dans catalogue.

Faiblesses :

- Le vocabulaire "composer/wizard/profile" peut etre trop technique pour un restaurateur.
- Il manque probablement une experience "creer un menu en 2 minutes" comparable aux produits SaaS matures.
- Besoin de templates metier : tacos, burger, bowl, boisson, menu enfant, supplement, sauce.

Priorite UX :

1. Renommer en langage metier : "Formule", "Etapes de choix", "Options", "Supplements".
2. Templates prets : Tacos, Burger, Pizza, Poke, Menu.
3. Preview live POS + borne + KDS.
4. Assistant de publication : impact sur produits existants, commandes en cours, version.
5. Import/export catalogue simple.

### 5.5 Stock / Produits / Ingredients

Constat FoodKing :

- V1 gere disponibilite/rupture par branche, quotas journaliers, toggles admin, verification a la commande, invalidation kiosk, broadcast.
- Des services et tests existent pour ingredients, stock, ruptures, decrement order, release on cancel/refund, stock low alerts.

Forces FoodKing :

- La rupture est branche-scoped et synchronisee jusqu'au POS/Kiosk.
- L'indisponibilite est verifiee au moment de la commande, pas seulement affichee.
- Le systeme sait eviter qu'un article indisponible soit vendu.

Faiblesses face aux concurrents :

- Ce n'est pas encore un inventaire complet comparable Lightspeed/Toast/Oracle : fournisseurs, achats, recettes, cout matiere, inventaire physique, transferts inter-branches, previsions, pertes, ecarts, valorisation.
- Il manque le langage "gestion globale stock" attendu par un restaurateur : entree stock, sortie, fiche ingredient, unite, seuil, alerte, inventaire, marge.

Priorite produit :

1. Transformer "rupture" en "stock operations".
2. Ajouter fiches ingredient avec unite, seuil, fournisseur, cout.
3. Recettes liees aux produits : un tacos consomme X grammes viande, Y sauce.
4. Mouvements stock : reception, correction, casse, perte, transfert, annulation, retour.
5. Dashboard marge : prix vente, cout matiere, marge theorique.
6. Inventaire physique mobile/tablette.

## 6. Comparaison par experience utilisateur

| Experience | FoodKing actuel | Niveau attendu concurrent mature | Gap |
| --- | --- | --- | --- |
| Premier lancement POS | Fonctionnel mais peut etre vide | Produits/categories demo visibles immediatement | Eleve |
| Rapidite encaissement | Flux robuste | 2-3 touches pour vente simple | Moyen |
| Paiement | Cash/card TPE restreint, drawer | Paiements integres + hardware | Eleve |
| Borne accueil | Fort, immersif | Fort, photo-rich, conversion optimise | Faible/Moyen |
| Borne wizard | Tres custom | Simple, rapide, images, upsell | Moyen |
| KDS | Tres controle/sync | Gros tickets, stations, bump facile | Moyen |
| Admin catalogue | Puissant | Simple a comprendre sans dev | Moyen/Eleve |
| Stock | Rupture solide | Inventaire complet | Eleve |
| Reporting | Existe mais pas packaging | Tableaux decisionnels prets | Moyen |
| Multi-site | Invariant fort | Gestion franchise mature | Moyen |
| Release client | HOLD | Produit vendable installe | Eleve |

## 7. SWOT FoodKing

### Forces

- Architecture controlee et auditable.
- Backend pricing SSOT, meilleure base que beaucoup de POS bricolent en front.
- Machine d'etat commande et audit transitions.
- Isolation branche structurelle.
- POS + Kiosk + KDS + OSS dans un meme systeme.
- Fiscal Z/NF525 localise.
- Tests nombreux et sentinels.
- Customisation forte pour menus complexes.
- Rupture par branche synchronisee.

### Faiblesses

- Release officiellement HOLD.
- Hardware/UAT/runtime non prouves.
- UX POS/KDS moins polie que les leaders.
- Gestion stock V1 encore insuffisante pour "gestion globale".
- Integrations commerciales faibles : paiements, compta, delivery, marketplace.
- Documentation interne riche mais pas transformee en promesse produit claire.
- Vocabulaire trop technique pour admin restaurateur.

### Opportunites

- Se positionner comme solution caisse/borne/KDS francaise hyper adaptee fast-food/tacos, fiscal-ready.
- Transformer le composer en avantage concurrentiel : menus complexes faciles.
- Faire une demo "Le Cayenne" avec flux complet realiste.
- Ajouter stock/marge pour concurrencer Lightspeed.
- Pack hardware pilote : TPE, imprimante, tiroir, borne, KDS tablette.

### Menaces

- Innovorder/Square/Toast peuvent gagner uniquement par packaging et confiance commerciale.
- Splash360 peut gagner localement si son interface est plus simple.
- Les concurrents ont support, hardware, onboarding, integrations deja vendables.
- Si FoodKing reste en HOLD, la perception sera "logiciel interne" plutot que "produit".

## 8. Scoring global

Score sur 10, lecture produit et interface.

| Systeme | Fonctionnel restaurant | Interface caisse/borne | Back-office | Stock | Fiscal FR | Integrations | Maturite release | Score moyen |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| FoodKing | 8 | 6 | 7 | 5 | 8 | 3 | 4 | 5.9 |
| Splash360 | 6 | 7 | 6 | 6 | 6 | 5 | 7 | 6.1 |
| Toast | 9 | 9 | 9 | 8 | 4 | 9 | 9 | 8.1 |
| Square | 8 | 9 | 8 | 6 | 4 | 9 | 9 | 7.6 |
| Lightspeed | 8 | 8 | 9 | 9 | 5 | 8 | 9 | 8.0 |
| Oracle Simphony | 9 | 7 | 9 | 9 | 7 | 9 | 9 | 8.3 |
| Innovorder | 8 | 8 | 8 | 7 | 8 | 7 | 8 | 7.7 |
| Zelty | 8 | 7 | 8 | 6 | 7 | 8 | 8 | 7.4 |
| L'Addition | 8 | 8 | 7 | 6 | 8 | 6 | 8 | 7.3 |
| Tiller/SumUp | 7 | 8 | 7 | 6 | 6 | 8 | 8 | 7.1 |

Interpretation : FoodKing est sous-score a cause de la maturite release, stock complet et integrations, pas a cause du coeur metier. Avec 3 sprints de finition UX + stock + packaging demo, le score peut monter vers 7.2-7.6 sans refonte totale.

## 9. Roadmap concurrentielle recommandée

### P0 - Transformer FoodKing en demo vendable

Objectif : un client doit comprendre en 5 minutes que FoodKing fait caisse + borne + cuisine + admin.

Actions :

1. Demo seed "restaurant vivant" : categories, produits, photos, menus tacos, commandes actives, stock bas.
2. Ecran POS dense avec tuiles produits visibles.
3. KDS plein ecran cuisine.
4. Borne avec 10 produits photo et un flow tacos complet.
5. Dashboard admin "Aujourd'hui" : CA, tickets, panier moyen, top produits, ruptures, commandes borne, KDS.
6. Script demo complet : borne commande -> POS encaisse -> KDS prepare -> OSS affiche -> fiscal Z.

### P1 - Stock global concurrentiel

Objectif : passer de rupture V1 a vraie gestion stock restaurant.

Actions :

1. Ingredients avec unite, seuil, fournisseur, cout.
2. Recettes produits.
3. Mouvements stock avec raisons.
4. Decrement sur commande, release sur annulation/refund.
5. Inventaire physique.
6. Dashboard cout matiere/marge.
7. Alerts stock bas et produits bientot en rupture.

### P2 - UX admin produits

Objectif : rendre le composer comprehensible sans formation technique.

Actions :

1. Templates par type de produit.
2. Preview borne/POS/KDS.
3. Assistant publication.
4. Import CSV/images.
5. Traductions FR metier.

### P3 - Hardware et release

Objectif : neutraliser le plus gros ecart avec Toast/Square/Innovorder.

Actions :

1. Pack hardware reference.
2. Tests imprimante/tiroir/TPE/scanner/borne/KDS.
3. UAT operateur.
4. Evidence fiscal Z reelle.
5. Runbook drills.
6. Decision cutover POS wizard.

### P4 - Integrations

Objectif : ne pas perdre face aux ecosystemes concurrents.

Actions :

1. Paiement TPE integre ou connecteur stable.
2. Export comptable.
3. Uber Eats/Deliveroo/commande en ligne selon priorite marche.
4. API publique minimale.
5. Webhooks commande/stock.

## 10. Message commercial recommande

Ne pas vendre FoodKing comme "un POS". Le vendre comme :

> FoodKing synchronise la caisse, la borne, la cuisine, le stock et le fiscal dans un seul systeme adapte aux restaurants rapides francais.

Promesse en 5 blocs :

1. Encaisser vite : POS tactile, cash/TPE, ticket, plan de salle.
2. Commander sans file : borne self-order avec menus complexes.
3. Produire sans erreur : KDS temps reel, stations, timers.
4. Controler le restaurant : admin produits, ruptures, stock, dashboard.
5. Securiser : prix backend, audit, branche, fiscal Z.

## 11. Conclusion

FoodKing a une base technique et metier superieure a ce que laisse voir son interface actuelle. La concurrence gagne surtout sur trois axes : clarte d'interface, maturite operationnelle, integrations/hardware. Pour battre Splash360 localement et se rapprocher d'Innovorder/Lightspeed/Square, il faut prioriser la demo vendable, la finition POS/KDS, le stock complet et les preuves terrain. Le coeur POS/Kiosk/KDS/fiscal est deja assez fort pour construire un produit competitif, mais pas encore assez emballe pour etre percu comme tel.
