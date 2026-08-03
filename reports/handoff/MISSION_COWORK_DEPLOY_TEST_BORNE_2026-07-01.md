# MISSION COWORK — Mettre la borne à jour (nouvelle version) + test e2e réel produit par produit

> **Pour : Claude cowork (accès machine réelle / VPS / borne).**
> **But : remplacer l'ANCIENNE version par la version validée, PROPREMENT (aucune
> version périmée, aucun résidu d'historique), puis tester CHAQUE produit en réel
> jusqu'à la commande, sans bug, et vérifier les tickets.**

---

## 0. Ce qui a déjà été validé en local (Claude principal) — tu n'as PAS à le re-coder

- **35/35 produits** commandés via navigateur réel (portrait 1080×1920 = résolution borne),
  chacun par son **wizard complet** (sauce, viandes ×2 où requis, pain, menu, boisson) jusqu'à
  une commande réelle acceptée (HTTP 201). **0 crash, 0 notification jaune/rouge visible.**
- **Multi-viandes** (Cayenne, Suprême, Méga, Terminator, Tacos M, Tacos L) : les **2 viandes**
  arrivent bien au panier ET sur les tickets (le bug « 2e viande perdue » est corrigé).
- **Tickets client + cuisine synchronisés** (même composition, format cuisine symbolique).
- **Ticket cuisine Menu Enfant corrigé** : Burger et Nuggets sont désormais DISTINCTS (avant,
  les deux sortaient « MENU » → la cuisine ne pouvait pas les différencier).
- **Audit adversarial** (reverse-agents) : 0 P0, SSOT prix solide (formule = 3 paliers
  cohérents), idempotency at-most-once prouvée (pas de commande dupliquée au double-tap).
- **Fix paiement** : une commande borne non encaissée affiche « À RÉGLER EN CAISSE » (plus
  jamais « PAIEMENT 6 : 0,00 € ») sur le ticket ET l'écran ET le reçu JS (cohérence 3 surfaces).
- Intégrité base : 38 commandes test, numérotation continue, état `PENDING_COUNTER`, **aucun
  n° fiscal posé avant encaissement** (NF525 respecté).

Ta mission = **déployer ça sur la vraie borne** + **prouver en réel** que tout marche.

---

## 1. PRÉ-REQUIS (à confirmer AVANT de déployer)

⚠️ Le déploiement se fait par `tools/deploy-vps.sh` qui fait `git reset --hard origin/<branche>`
puis `npm run production`. **Donc la branche validée DOIT être poussée sur origin** avec
TOUS les fixes (multi-viande + sync paiement). **Ne lance PAS le déploiement si la branche
n'est pas à jour sur origin** — tu déploierais une version plus ancienne.

- Branche : `pos/category-first-caisse-2026-06-23`
- Vérifie d'abord : `git fetch origin && git log origin/pos/category-first-caisse-2026-06-23 --oneline -3`
  → le dernier commit doit inclure le fix « 2e viande » + le fix « paiement À RÉGLER EN CAISSE ».
  Si ce n'est PAS le cas → **STOP**, signale-le (le push n'a pas été fait).

---

## 2. DÉPLOIEMENT PROPRE (anti-version-périmée) — SUR LE VPS

```bash
# 1) Se placer dans l'app sur le VPS
cd /var/www/lecayenne        # (adapter au vrai chemin)

# 2) Déploiement canonique : reset propre + rebuild COMPLET des bundles + clear caches + auto-vérif
bash tools/deploy-vps.sh /var/www/lecayenne
#   → git fetch + reset --hard origin/<branche>
#   → npm ci && npm run production   (REBUILD COMPLET = jeu de bundles cohérent, JAMAIS un sous-ensemble)
#   → php artisan config:clear && cache:clear
#   → auto-vérif + ROLLBACK auto si échec
php artisan view:clear        # (en plus, pour purger les vues Blade compilées)
php artisan route:clear
```

**Pourquoi ça règle le problème « ancienne version / historique » :** `reset --hard` aligne
le VPS exactement sur la branche poussée (zéro résidu local), et `npm run production` reconstruit
**l'ensemble complet** des bundles d'un coup (jamais un app.js neuf avec un vendor.js périmé =
la cause des écrans blancs). Les caches Laravel sont vidés.

---

## 3. VÉRIFIER QUE LA BONNE VERSION TOURNE (pas l'ancienne)

1. **Bundle servi == bundle buildé** : compare le hash du `mix-manifest.json` servi par le VPS
   à celui buildé (`curl -s https://<domaine>/mix-manifest.json | head`). Doit être identique.
2. **Sur la borne (Chrome plein écran)** : **vider le cache + recharger** (ou redémarrer le Chrome
   kiosk) pour forcer le chargement du nouveau bundle (le hash dans mix-manifest casse le cache,
   mais force quand même un hard-reload pour être sûr).
3. **Écran d'accueil OK** : `/kiosk` affiche l'attract (PAS d'écran blanc, PAS de page figée).
   Si écran blanc → bundle incohérent → relance `npm run production` (jeu complet) + recharge.
4. **Pont d'impression** : vérifier que le pont local d'impression tourne + le flag Chrome
   `--disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks` est actif
   (cf. mission borne AnyDesk précédente). Sans ça, les tickets ne s'impriment pas.

---

## 4. TEST E2E RÉEL — LES 35 PRODUITS, UN PAR UN (le cœur de la mission)

Pour **CHAQUE** produit ci-dessous : `Accueil → Emporter → [catégorie] → [produit] →
compléter le wizard → Ajouter au panier → Payer → (upsell : Non merci) → Confirmer`.
À chaque fois, exiger : **0 erreur, 0 notification jaune/rouge, 0 blocage, commande acceptée.**

| Cat | Produits | Wizard à tester |
|---|---|---|
| Sandwichs | Cayenne, Suprême, **Méga**, **Terminator** | **2 VIANDES** + sauce + pain + (menu+boisson) |
| Galette | Galette Normale, Galette Cayenne | viande + sauce + (menu) |
| Burgers | Chicken, Cheese, Double Cheese, Fish, Big, Grill | sauce + (menu+boisson) |
| Tacos | **Tacos M**, **Tacos L** | **2 VIANDES** (Tacos L) + sauce + (menu) |
| Bols | Bol Frites, Bol Riz | viande + sauce |
| Frites | Petite, Grande, +Cheddar (×4) | ajout direct |
| Desserts | Glace, Tarte Daim, Tiramisu | ajout direct |
| Boissons | Coca, Coca Zero, Fanta, Sprite, Oasis, Orangina, Eau, Capri-Sun | ajout direct |
| Menu enfant | Nuggets, Burger | ajout direct |

**⭐ POINT CRITIQUE — produits 2 viandes** (Méga, Terminator, Tacos L, Cayenne, Suprême, Tacos M) :
choisis **2 viandes DIFFÉRENTES** (ex. Mexicanos + Cordon Bleu). Vérifie :
- l'article au panier garde **les 2 viandes** (pas seulement la 1ère),
- le paiement passe (pas de rejet « Viande 2 manquante »),
- les 2 viandes apparaissent sur le **ticket client** ET le **ticket cuisine**.

---

## 5. VÉRIFIER LES TICKETS + KDS + CAISSE (la synchro)

Pour au moins 1 commande par catégorie (et TOUTES les multi-viandes) :

1. **Ticket CLIENT (papier)** : composition lisible, prix corrects, **« À RÉGLER EN CAISSE »**
   (et surtout **PAS « PAIEMENT 6 : 0,00 € »**), les 2 viandes listées.
2. **Ticket CUISINE (papier)** : format symbolique `support | produit | taille | viandes |
   crudités | sauce` (ex. `S | MÉGA | Mex Cordon | STO | MAY`), les 2 viandes présentes.
   - **⭐ Menu Enfant** : le ticket cuisine doit afficher **« MENU ENFANT BURGER »** vs
     **« MENU ENFANT NUGGETS »** (DISTINCTS) — surtout PAS « MENU » tout court pour les deux
     (bug corrigé ; vérifier que le déploiement l'a bien pris).
3. **Écran KDS** : la commande apparaît avec le même format symbolique (Burger ≠ Nuggets aussi).
4. **Caisse** : la commande est dans la file **« à encaisser borne »** → **encaisser** (espèces
   ou carte) → un **ticket fiscal** sort avec le **vrai mode de paiement** (plus « méthode 6 »).

---

## 6. RAPPORT ATTENDU

- **Tableau des 35 produits** : PASS / FAIL + (si FAIL) capture + message d'erreur exact.
- Pour chaque multi-viande : confirmer « 2 viandes au panier + sur les 2 tickets ».
- Confirmer : 0 écran blanc, 0 notification jaune, tickets propres, KDS + caisse OK.
- Toute anomalie : capture + l'étape précise + le produit.

**Si un seul produit bloque / sort une notif / un ticket faux → NE PAS clore, signale-le
avec la capture et l'étape exacte.**

---

## 7. Vérif NF525 sur la base de PROD (à faire une fois sur le VPS)

Sur la base de **production** (pas la base de test), confirme que le `composition_snapshot`
est bien **immuable** : le trigger MySQL `BEFORE UPDATE`/`BEFORE DELETE` sur la table
`order_items` (ou équivalent) doit exister et empêcher toute modification d'un snapshot scellé
(loi NF525). Commande : `SHOW TRIGGERS LIKE 'order_items';` → il doit y avoir le(s) trigger(s)
d'immuabilité. (Côté base de test ce trigger est absent, c'est normal ; il doit être présent
en prod.) Si absent en prod → **signale-le**, c'est un point fiscal.
