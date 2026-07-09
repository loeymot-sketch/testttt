# Rapport d'audit complet — Borne Le Cayenne (e2e produit par produit)

> **Date** : 2026-06-30 · **Méthode** : test automatisé headless Chrome à la **vraie
> résolution borne 1080×1920 portrait** (le bug n'apparaît qu'au format borne — un test en
> paysage ne le voit pas). Chaque produit : ouverture wizard → composition complète →
> récap → **ajout panier** → capture des erreurs console/réseau/notifications + prix. Puis
> une commande complète **jusqu'au paiement**. 35 produits, 8 catégories.

## 1. Verdict

| Avant | Après correctifs |
|---|---|
| Sandwichs multi-viandes **rejetés au paiement** (2ᵉ viande perdue) | **35/35 ajout panier OK** |
| Toasts jaunes/rouges parasites en pleine commande | **0 notification** sur les 35 produits |
| « ça sort du tableau / scroll bizarre » au portrait | Scroll lisible (indice visible + fondu) |
| Commande multi-viandes impossible | **Commande complète jusqu'au paiement OK** (#5311, 2 viandes en DB) |

## 2. Les 4 bugs trouvés — cause + correctif (pour le développeur)

### 🟥 BUG 1 (CRITIQUE) — La 2ᵉ viande est perdue à l'ajout panier → commande rejetée
- **Symptôme** : sur Tacos L/XL, Méga, Terminator (et tout produit 2 viandes), le client
  choisit 2 viandes, le wizard affiche **2/2 ✓**, mais l'article ajouté au panier ne garde
  que la **1ʳᵉ viande**. Au paiement, le backend rejette **422 « Sélectionnez au moins 1
  Viande 2 (actuel : 0) »**. = ta plainte « je choisis la viande, ça fait une erreur ».
- **Cause** : `KioskWizardComponent.vue` → `buildCartItem()` faisait
  `const allVars = Array.isArray(item.variations) ? item.variations : []`. En prod,
  `item.variations` est un **OBJET groupé par attribut** (pas un tableau) → `allVars = []`
  → le `match` « viande de ce nom sous l'attribut Viande 2 » échouait toujours → seule la
  1ʳᵉ viande survivait (fallback `idx===0 ? v.id`). Le fix P0 précédent (`cfcd27d53`) avait
  un test qui **mockait un tableau** → il passait au vert sans jamais tester la forme réelle.
- **Correctif** (frozen §7, LOCK `docs/locks/LOCK_KIOSK_WIZARD_VIANDE2_2026-06-30.md`) :
  aplatir l'objet → `Object.values(item.variations).flat()`. `<template>`/`<style>` intouchés.
- **Preuve** : article panier Tacos L AVANT = `[Viande 1: Mexicanos, Sauce]` ; APRÈS =
  `[Viande 1: Mexicanos (361), Viande 2: Cordon Bleu (369), Sauce: Mayonnaise (375)]`.
  Commande #5311 placée → composition DB = 2 viandes distinctes. Test régression
  `kioskWizardMultiViande.spec.js` 5/5 (dont un cas **forme objet** qui aurait attrapé le bug).

### 🟧 BUG 2 — Toast jaune « Session rafraîchie automatiquement »
- **Symptôme** : toast jaune parasite en pleine commande (la « notification jaune qui revient »).
- **Cause** : `app.js` émet `kiosk-auth-retried` quand un 401 est silencieusement récupéré
  (re-login OK). `KioskAppComponent.vue:380` (frozen) affiche alors un toast `warning`.
  Cet event n'existait que pour un protocole d'audit auto — inutile/anxiogène pour le client.
- **Correctif** (NON-frozen, `kioskAuthInterceptor.js`) : supprimer ENTIÈREMENT l'event
  `kiosk-auth-retried` (capture-phase + `stopImmediatePropagation`). `kiosk-auth-failed`
  (vraie déconnexion) reste. Test `kioskAuthInterceptor.spec.js` 2/2.

### 🟥 BUG 3 (CRITIQUE) — Déconnexion + toast ROUGE pendant la composition
- **Symptôme** : pendant la sélection (viande/sauce), retour brutal à l'écran login + toast
  rouge « Borne déconnectée ». Contribue à « ça fait une erreur quand je choisis la viande ».
- **Cause** : `app.js` → un 401 sur `pricing/preview` (token expiré) → re-login → replay →
  le replay renvoie **422** (compo incomplète = ATTENDU pendant la saisie) → traité comme
  panne d'auth terminale → `CLEAR_KIOSK_TOKEN` + `kiosk-auth-failed` + `router.push(login)`.
- **Correctif** (NON-frozen, `app.js`) : dans le `.catch` du replay, une erreur **4xx
  non-401** (ex. 422) = erreur MÉTIER, pas panne d'auth → `Promise.reject` silencieux
  (token préservé, pas de déconnexion). Seuls 401 / 5xx / pas-de-réponse = vraie panne.

### 🟨 BUG 4 — « ça sort du tableau / scroll bizarre » au format portrait
- **Symptôme** : sur les étapes à beaucoup d'options (12 sauces, 12 suppléments), le contenu
  dépasse l'écran et le client doit scroller sans le savoir.
- **Cause** : `KioskWizardComponent.vue` `.kiosk-step-content` a `scrollbar-width:none` +
  `::-webkit-scrollbar{display:none}` → AUCUN indice de scroll → options « coupées » perçues.
  (Le layout scrolle déjà proprement, la barre du bas ne recouvre rien — vérifié.)
- **Correctif** (NON-frozen, surcharges globales `!important` dans `master.blade.php`,
  wizard frozen intouché) : scrollbar fine brand visible + marge basse + **fondu d'incitation
  au scroll** au-dessus de la barre d'action.
- **Reste possible (frozen, sur ton accord)** : supprimer le double indicateur d'étape
  redondant (`.kiosk-step-visuals`, ~137 px) pour que les étapes denses **tiennent sans
  scroll du tout**. Non fait (touche le frozen + change le visuel).

## 3. Catégorie par catégorie — 35 produits (prix, type, ajout panier, variations)

> `vars` = nb de variations dans l'article panier. Multi-viandes : Méga/Terminator=4
> (Viande 1+2, Sauce, Pain), Tacos L=3 (Viande 1+2, Sauce). Cayenne/Suprême=2 (Pain+Sauce,
> viande intégrée à la recette). Burgers=1 (sauce). Bols=2 (viande+sauce).

| Cat | Produit | Prix | Type | Panier | vars |
|---|---|---|---|---|---|
| **Sandwichs** | Cayenne | 7,40 € | composable | ✅ | 2 |
| | Suprême | 7,00 € | composable | ✅ | 2 |
| | **Méga** | 8,00 € | composable | ✅ | **4** |
| | **Terminator** | 9,00 € | composable | ✅ | **4** |
| **Galette** | Galette Normale | 6,50 € | composable | ✅ | 2 |
| | Galette Cayenne | 7,00 € | composable | ✅ | 2 |
| **Burgers** | Chicken Burger | 4,90 € | composable | ✅ | 1 |
| | Cheese Burger | 6,00 € | composable | ✅ | 1 |
| | Double Cheese | 7,00 € | composable | ✅ | 1 |
| | Fish Burger | 6,00 € | composable | ✅ | 1 |
| | Big Burger | 9,00 € | composable | ✅ | 1 |
| | Grill Burger | 8,00 € | composable | ✅ | 1 |
| **Tacos** | Tacos M | 6,90 € | composable | ✅ | 2 |
| | **Tacos L** | 7,90 € | composable | ✅ | **3** |
| **Bols** | Bol Frites | 7,90 € | composable | ✅ | 2 |
| | Bol Riz | 7,90 € | composable | ✅ | 2 |
| **Frites** | Petite Frites | 2,50 € | simple | ✅ | – |
| | Grande Frites | 4,00 € | simple | ✅ | – |
| | Petite Frites Cheddar fondu | 3,50 € | simple | ✅ | – |
| | Petite Frites Cheddar + Oignons frits | 4,50 € | simple | ✅ | – |
| | Grande Frites Cheddar fondu | 5,00 € | simple | ✅ | – |
| | Grande Frites Cheddar + Oignons frits | 6,00 € | simple | ✅ | – |
| **Desserts** | Glace | 3,50 € | simple | ✅ | – |
| | Tarte Daim | 3,50 € | simple | ✅ | – |
| | Tiramisu | 3,50 € | simple | ✅ | – |
| **Boissons** | Coca-Cola 33cl | 1,90 € | simple | ✅ | – |
| | Coca-Cola Zero 33cl | 1,90 € | simple | ✅ | – |
| | Fanta Orange 33cl | 1,90 € | simple | ✅ | – |
| | Sprite 33cl | 1,90 € | simple | ✅ | – |
| | Oasis Tropical 33cl | 1,90 € | simple | ✅ | – |
| | Orangina 33cl | 1,90 € | simple | ✅ | – |
| | Eau Plate 50cl | 1,00 € | simple | ✅ | – |
| | Capri-Sun | 1,50 € | simple | ✅ | – |
| **Menu enfant** | Menu Enfant Nuggets | 4,90 € | simple | ✅ | – |
| | Menu Enfant Burger | 4,90 € | simple | ✅ | – |

## 4. Erreurs réseau/console résiduelles = ATTENDUES (aucune notification client)

Sur les produits composables, la console montre `422 pricing/preview` (compo incomplète en
cours de saisie = normal), `401` (refresh token), `403 broadcasting/auth` (WebSocket KDS).
**Aucune ne produit de notification** (toast=0 sur 35/35) — c'étaient justement ces 401/422/403
qui déclenchaient avant les toasts jaune/rouge, désormais neutralisés par les bugs 2 & 3.

## 5. Vérification finale (commande complète)

Tacos L, 2 viandes (Mexicanos + Cordon Bleu), parcours UI réel à 1080×1920 :
`wizard → panier → Valider → upsell → paiement → Confirmer` →
**#A0003 / #5311, 7,90 €, écran « Rendez-vous en caisse », 0 toast**.
DB : `composition_snapshot` = Viande 1 Mexicanos + **Viande 2 Cordon Bleu** + Sauce Mayonnaise.

## 6. Fichiers modifiés (à committer + rebuild + déployer)

| Fichier | Bug | Frozen |
|---|---|---|
| `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` | 1 | **OUI** (LOCK fourni + baseline SHA màj) |
| `resources/js/helpers/kioskAuthInterceptor.js` (+ test) | 2 | non |
| `resources/js/app.js` | 3 | non |
| `resources/views/master.blade.php` | 4 | non |
| `tests/js/kioskWizardMultiViande.spec.js` (cas forme-objet) | 1 | non |

Déploiement : `npx mix` puis SCP du jeu **complet** des bundles (`public/js/*.js` + `mix-manifest.json`,
`app.js` gitignoré) vers le VPS + vérif md5 (cf. leçon déploiement bundle complet).

---

## 7. Corrélation avec le rapport cowork (test sur la VRAIE borne production VPS)

Le cowork a testé la borne en ligne (`vps-418872ac.vps.ovh.net`) et documenté 4 bugs.
Corrélation avec mes findings :

| Cowork | = | Mon fix | Statut |
|---|---|---|---|
| **#1 CRITIQUE** Tacos L/Méga/Terminator impossibles (Viande 2 manquante → 422, 100% échec) | = | **BUG 1** (2e viande perdue au panier) | ✅ Corrigé local, **PAS encore déployé** |
| **#4** pricing/preview 422 sur Tacos L | = | conséquence du BUG 1 | ✅ Résolu avec BUG 1 |
| **#2 mineur** token kiosk « ~30s » → boucle 401 visible | ≈ | BUG 2+3 (toast/déconnexion masqués) | ✅ symptômes masqués ; racine = 401 cosmétique sur `kiosk-event` (TTL réel = **480 min**, pas 30s) |
| **#3 mineur** rate-limit 429 sur `order/quote` | — | non reproduit | ⚠️ Artefact des quotes rapides du cowork (throttle API global). Vrai client = 1 quote/checkout, pas de 429. Reco : debounce si besoin. |

> Note : le cowork pense que l'UI « n'affiche qu'un sélecteur de viande ». En réalité l'UI
> affiche bien « 0/2 » et le client peut sélectionner 2 viandes — c'est `buildCartItem` qui
> droppait la 2e à l'assemblage de l'article panier. Même symptôme, fix identique.

## 8. ⚠️ Le bug critique est LIVE sur la borne en ligne — déploiement requis

Preuve : VPS `app.js` = md5 `bca5e5c2` (ancien, sans le fix viande) ; local = `f3ba9543`
(avec `Object.values(item.variations).flat()`). **Tant que ce n'est pas déployé, les
sandwichs multi-viandes restent incommandables sur la vraie borne.**

Bundles à déployer (SEULEMENT les bundles kiosk modifiés — POS/KDS intouchés) :
- `public/js/app.js` (f3ba9543) + `public/js/kiosk-wizard.js` (31edc81f) + `public/mix-manifest.json` (589828b2)
- `resources/views/master.blade.php` (affordance scroll) + `php artisan view:clear` sur le VPS

Commande (à lancer avec ton accord) :
```bash
KEY=~/.ssh/lecayenne_ovh ; HOST=ubuntu@51.210.111.124
scp -i "$KEY" public/js/app.js public/js/kiosk-wizard.js "$HOST:/var/www/lecayenne/public/js/"
scp -i "$KEY" public/mix-manifest.json "$HOST:/var/www/lecayenne/public/"
scp -i "$KEY" resources/views/master.blade.php "$HOST:/var/www/lecayenne/resources/views/"
ssh -i "$KEY" "$HOST" "cd /var/www/lecayenne && php artisan view:clear"
# vérif : md5sum public/js/app.js doit == f3ba9543... ; tester un Méga sur la borne
```
