# GOAL — UX & Logique Globale : CAISSE · BORNE · CUISINE (2026-07-05)

> Plan ultra-détaillé demandé par l'owner (`/goal`). Objectif : passer les 3 surfaces au
> niveau « production propre » — offres/upsell cohérents, wizard complet, livraison
> fonctionnelle, KDS propre, tickets pro (niveau caisse partout), sync intelligente.
> **Validation = test-e2e réel (vrai parcours client) + agents adversaires, EN BOUCLE**
> jusqu'à ce que TOUS les points signalés soient verts (P0+P1 = 0 sur 2 cycles identiques).

---

## 0. Méthode (discipline non négociable)

Pour CHAQUE point ci-dessous, dans l'ordre :
1. **Reproduire en réel** (navigateur, vrai parcours) + capture d'écran.
2. **Cause racine vérifiée** (`file:line`, grep/Read/curl — jamais de supposition).
3. **Fix scope-minimal** (frozen-zone → LOCK + gate owner).
4. **Test-e2e** de la surface touchée + capture analysée.
5. **Agent adversaire** qui DISPUTE le fix (essaie de le casser / trouve le cas manquant).
6. **Boucle** re-plan → re-fix → re-test tant que l'adversaire trouve quelque chose.
7. Un système à la fois, **un problème à la fois** (test global qui cible 1 chose).

**Convergence** : livrer un point seulement quand 2 cycles consécutifs = 0 défaut, findings identiques.

**Zones gelées** (§7 CLAUDE.md) touchées par ce goal : `pos-wizard.js`, `KioskWizardComponent.vue`,
`KioskUpsellComponent.vue`, `PaymentComponent.vue` → chaque modif = doc LOCK + accord owner explicite.

---

## 1. Backlog structuré (par système)

### 🟠 SYSTÈME BORNE (client)

**A1 — Upsell incohérent (propose des sandwiches au lieu de desserts/boissons/menu enfant)**
- **Owner** : « après ajout au panier / au paiement, il offre des produits en supplément mais il offre des SANDWICHES. Normalement ça doit être du DESSERT (les 3 desserts), ou les ~10 BOISSONS, ou le MENU ENFANT. »
- **Cause probable** : `UpsellRule` / `KioskUpsellComponent.vue` propose des items sans filtrer par catégorie complémentaire (dessert/boisson/menu enfant). À confirmer : la règle upsell tire quels items ?
- **Fix** : restreindre l'upsell aux catégories **Desserts + Boissons + Menu Enfant** (jamais Sandwichs/Tacos/plats principaux). Config `UpsellRule` (catégories éligibles) OU filtre dans `KioskMenuService`/`KioskUpsellComponent`.
- **Fichiers** : `app/Models/UpsellRule.php`, `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue` (FROZEN → LOCK), `app/Services/Kiosk/KioskMenuService.php`.
- **Validation e2e** : borne → ajouter un sandwich → écran upsell → doit proposer UNIQUEMENT desserts/boissons/menu enfant (capture).
- **Adversaire** : « et si le panier contient déjà un dessert ? un menu enfant ? un tacos ? l'upsell propose-t-il encore n'importe quoi ? »

### 🔵 SYSTÈME CAISSE (POS)

**C1 — Wizard menu/formule : PAS de choix de boisson**
- **Owner** : « quand je choisis la formule (menu complet = boisson + frites), je ne trouve pas le choix de la BOISSON. Je veux la choisir directement → elle apparaît sur le ticket. Plus productif. »
- **Cause probable** : le wizard menu (`pos-wizard.js`) propose frites/options mais pas la sélection de boisson (ou elle est masquée). À confirmer : structure du menu addon (a-t-il un attribut « Boisson » ?).
- **Fix** : ajouter/afficher le choix de boisson dans l'étape menu du wizard → sérialisé dans `menu_extras`/`item_extras` → visible sur le ticket. `pos-wizard.js` FROZEN → LOCK.
- **Fichiers** : `public/js/pos-wizard.js` (FROZEN → LOCK), `ItemComponent.vue`, données menu (attribut Boisson).
- **Validation e2e** : caisse → produit avec menu → wizard → choisir une boisson → panier + ticket affichent la boisson.
- **Adversaire** : « la boisson choisie arrive-t-elle jusqu'au ticket ET au KDS ? et si pas de boisson choisie, comportement ? »

**C2 — Nom du client (commande à emporter) introuvable**
- **Owner** : « pour une commande à emporter, je veux ajouter le NOM du client, je ne le trouve pas. Optionnel mais mieux de l'avoir. »
- **Cause probable** : le POS a un customer par email/téléphone mais pas de champ simple « nom » pour un walk-in emporter. (`PosComponent.vue:2435` cherche le walking customer par email/nom.)
- **Fix** : champ « Nom du client » (optionnel) sur emporter/sur place → stocké sur la commande → **imprimé sur le ticket** (client + cuisine).
- **Fichiers** : `PosComponent.vue`, `OrderReceiptEscPosRenderer.php` (afficher le nom), backend order (champ nom).
- **Validation e2e** : caisse → emporter → saisir « Marc » → ticket affiche « Client : Marc ».
- **Adversaire** : « le nom se propage-t-il au KDS ? au ticket cuisine ? est-il bien optionnel (vide = pas de ligne) ? »

**C3 — Livraison : saisie d'adresse cassée (P0 fonctionnel)**
- **Owner** : « si client livraison, je n'arrive pas à taper l'adresse, la fonction ne marche pas. Avant c'était configuré avec Google (géocodage + calcul périmètre). Fais-la marcher même “fausse”, l'important c'est qu'elle fonctionne. Règle : **3 km gratuit, puis +1 €/km au-delà de 3 km**. »
- ⚠️ **Note règle** : nouvelle règle donnée = 3 km gratuit + 1 €/km au-delà. (Ancienne mémoire : base 4 € ≤5 km. **À CONFIRMER owner** avant de figer le barème.)
- **Cause probable** : le formulaire livraison inline (`PosComponent.vue:675-722`) : la saisie d'adresse / le géocodage (Nominatim) ne répond pas (clé, réseau, ou champ désactivé).
- **Fix** : (1) réparer la saisie d'adresse + géocodage → obtenir lat/lng ; (2) calcul distance depuis l'origine restaurant ; (3) frais = 0 si ≤3 km, sinon (dist-3)×1 €. Fallback « saisie manuelle sans géocodage » si la géo échoue (l'important : ça fonctionne).
- **Fichiers** : `PosComponent.vue` (form livraison), `DeliveryFeeService.php`, `DeliveryConfigSeeder`, helper géocodage.
- **Validation e2e** : caisse → livraison → taper une adresse → adresse acceptée + frais calculés (0 à 3 km, +1 €/km après) → commande créée.
- **Adversaire** : « adresse hors zone ? géocodage échoue ? distance = exactement 3 km ? le fee du ticket == le fee facturé (SSOT) ? »

### 🟢 SYSTÈME CUISINE (KDS)

**K1 — Purger les commandes de TEST en attente sur le KDS**
- **Owner** : « le KDS affiche beaucoup de commandes (des commandes de test), mieux de les supprimer. »
- **Cause** : commandes de test accumulées (statuts actifs). Mécanisme sûr connu (cf. nettoyage file caisse) : passer les commandes de test en ANNULÉ / clôturer, PAS de DELETE fiscal.
- **Fix** : script de purge des commandes de test actives (borne+caisse) → sortent du KDS. **Lié au nettoyage global test + reset chaîne fiscale** (déjà identifié : audit_logs.id=1 tamper, 31 cmds test).
- **Validation** : après purge → KDS vide (ou seulement vraies commandes).
- **Adversaire** : « une vraie commande en cours a-t-elle été supprimée ? le compteur fiscal reste-t-il cohérent ? »

**K2 — Carte KDS : suppléments visibles (jaune + gras + étoile)**
- **Owner** : « la fiche en JAUNE quand il y a des suppléments, suppléments en gras avec une étoile ⭐ pour qu'on sache qu'il y en a. »
- **Cause** : le KDS affiche les items mais ne met pas en évidence les suppléments.
- **Fix** : carte KDS → fond/bandeau JAUNE si l'item a des suppléments payants ; suppléments en **gras + ⭐**.
- **Fichiers** : `KdsV2Grid.vue` / `KitchenDisplaySystemComponent.vue`, `kdsSymbolic.js`.
- **Validation e2e** : commande avec supplément → carte KDS jaune + « ⭐ **Cheddar** » en gras.
- **Adversaire** : « et sans supplément (crudités gratuites) ? la carte ne doit PAS être jaune. »

### 🖨️ TICKETS

**T1 — Ticket BORNE = ticket CAISSE (design pro, prix alignés)** — *partiellement fait*
- **Owner** : « le ticket caisse est au top (prix affichés de l'autre côté, clair). Je veux pareil pour le ticket borne. »
- **État** : ✅ largeur borne découplée (48) déjà faite (`91b008c7d`) — même renderer que la caisse. **RESTE** : valider le rendu réel borne (prix à droite, gras, colonnes) après déploiement + capture.
- **Validation e2e** : imprimer un ticket borne → identique caisse (design, prix à droite).

**T2 — Ticket CUISINE (imprimé depuis la caisse) trop petit**
- **Owner** : « quand j'imprime le ticket cuisine depuis la caisse (seul moyen), il sort trop petit, on le voit mal. Agrandir un peu la POLICE + élargir la FEUILLE de **+30 % de la largeur mini actuelle** → plus grand, pas perdu en cuisine. »
- **Cause** : le ticket cuisine caisse utilise la largeur/police standard (compact).
- **Fix** : (1) police cuisine un peu plus grande (double-hauteur renforcée) ; (2) largeur cuisine = largeur mini × 1,3 (≈ +30 %). Configurable (`RECEIPT_KITCHEN_WIDTH_CHARS` ou multiplicateur).
- **Fichiers** : `OrderReceiptEscPosRenderer::renderKitchenTicket`, `EscPosTicketBytesService` (largeur cuisine), config.
- **Validation e2e** : rendre le ticket cuisine → largeur ≈ +30 % + police plus lisible (décodage octets + capture physique owner).
- **Adversaire** : « +30 % dépasse-t-il la largeur physique de la SAGA (42) ? → sinon coupures. Calibrer sans déborder. »

**T3 — Ticket CUISINE : noms produits en 3 lettres + suppléments gras+étoile**
- **Owner** : « noms produits en 3 premières lettres (Cayenne→CAY, Terminator→TER…). Suppléments en GRAS + ⭐. »
- **État** : format symbolique existant (`KitchenTicketSymbolicFormatter` / `kdsSymbolic.js` : `G|TACOS|M|K|SAM`). L'owner veut la **passe 2** : abréviation 3-lettres du NOM produit + suppléments en gras+étoile. (Déféré en mémoire « format 3 lettres KDS passe 2 ».)
- **Fix** : abréviation 3-lettres déterministe (majuscules, sans accents) côté PHP `KitchenTicketSymbolicFormatter` ET JS `kdsSymbolic.js` (parité testée) ; suppléments payants → `⭐ **NOM**`.
- **Validation e2e** : ticket + KDS montrent `CAY`, `TER` + `⭐ Cheddar` gras. Parité PHP↔JS testée.
- **Adversaire** : « nom < 3 lettres ? collision (2 produits même 3 lettres) ? viande/menu vs produit ? »

### 🔄 SYNC (transversal)

**E1 — Synchronisation ultra-smart 3 surfaces**
- **Owner** : « synchronisation ultra intelligente et smart » entre caisse/borne/cuisine.
- **État** : sync via broadcasts (queue `high`) + polling 5s de secours. **RESTE** : confirmer temps-réel (worker `queue:work --queue=high,default` + Pusher/soketi) OU documenter le polling comme mode nominal.
- **Validation e2e** : commande borne → visible caisse (file) + KDS en <5 s ; bump KDS → reflété partout.

---

## 2. Ordre d'exécution proposé (vagues, 1 chose à la fois)

1. **Vague 0 — quick wins bas risque** : K1 (purge KDS test) · T2 (ticket cuisine +30 % + police) · C2 (nom client).
2. **Vague 1 — wizard/tickets** : C1 (boisson menu) · T3 (3-lettres + ⭐ gras) · T1 (valider borne).
3. **Vague 2 — KDS visuel** : K2 (carte jaune + suppléments).
4. **Vague 3 — livraison** : C3 (adresse + barème) — *après confirmation owner du barème*.
5. **Vague 4 — offres** : A1 (upsell cohérent desserts/boissons/menu enfant).
6. **Vague 5 — validation globale** : E1 sync + **test-e2e adversaire en boucle** sur les 3 surfaces (vrai parcours client, captures, dispute, convergence).

À chaque vague : test-e2e + agent adversaire + boucle jusqu'à vert, puis déploiement + capture owner.

---

## 3. Décisions owner requises avant certains fix

- **C3 barème livraison** : confirmer « 3 km gratuit puis +1 €/km » (diffère de l'ancien « base 4 € ≤5 km »). Origine restaurant = adresse Le Cayenne.
- **A1 upsell** : confirmer les catégories exactes proposées (Desserts + Boissons + Menu Enfant uniquement ?).
- **K1 purge** : confirmer « effacer les 31 commandes de test » + reset chaîne fiscale (opération unique avant vraie exploitation).
- **Frozen zones** (pos-wizard.js, KioskUpsell, PaymentComponent) : gate owner pour C1, A1.

---

## 4. Definition of Done

- Les **9 points signalés** sont verts, prouvés par test-e2e réel + capture.
- Chaque fix a survécu à **≥1 agent adversaire** + 2 cycles de convergence.
- 3 surfaces synchronisées, tickets pro (caisse-grade) partout.
- Zones gelées : LOCK + gate owner respectés. Tests techniques + visuels verts.
</content>
