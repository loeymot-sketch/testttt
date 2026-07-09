# MISSION COWORK — Test réel BORNE + CAISSE (prise de commande + impression)

> À coller à Claude cowork (connecté en AnyDesk au poste borne/caisse Le Cayenne).
> Objectif : passer de VRAIES commandes, vérifier chaque ticket (client + cuisine +
> caisse), l'encaissement, les choix d'impression, la synchro KDS, puis rendre un
> RAPPORT COMPLET validé. Tu testes pour de vrai ET tu abuses (cas limites).

---

## 0. CE QU'ON VEUT PROUVER

Pour CHAQUE commande, répondre OUI/NON + preuve (photo) :
1. **Borne → client** : le ticket qui sort de la borne est-il correct pour le client (toutes les étapes choisies, lisible, bien numéroté) ?
2. **Borne → caisse** : la commande arrive-t-elle bien à la caisse (à l'écran POS + la copie comptoir imprimée) ?
3. **Borne → cuisine** : le ticket cuisine (symbolique 3 lignes) + l'écran KDS sont-ils corrects et **identiques entre eux** ?
4. **Encaissement** : à la caisse, le bouton « encaisser » sort-il le reçu client + ouvre le tiroir + numéro fiscal correct ?
5. **Choix d'impression caisse** : peut-on **choisir** d'imprimer le ticket CLIENT, le ticket CUISINE, ou **les DEUX**, à la demande ?
6. **Zéro doublon, zéro détail manquant.**

---

## 1. TOPOLOGIE & CONNEXIONS (à vérifier AVANT de commencer)

```
[BORNE]  Chrome plein écran ──HTTPS──► CLOUD VPS (Laravel)
   │                                   https://vps-418872ac.vps.ovh.net
   │  imprime via
   ▼
[Pont local bridge.js]  http://127.0.0.1:9100  ──USB──►  [SK1-31]  (ticket client borne + ticket cuisine)

[CAISSE]  navigateur ──► CLOUD VPS /admin/pos      ──► écran POS (la commande borne apparaît)
   │  imprime via
   ▼
[Pont local caisse /raw] 127.0.0.1:9100  ──USB──►  [SAGA 80mm]  (copie comptoir + reçu fiscal + reprints)
```

- **URL borne** : `https://vps-418872ac.vps.ovh.net/kiosk?machine_key=<CLE_SECRETE>` (garder la clé existante).
- **Flag Chrome obligatoire** sur le raccourci borne (sinon l'impression ne part pas) :
  `--disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks`
- **Pont** : `curl http://127.0.0.1:9100/health` doit répondre `UP`.

### Check connexion (à faire et noter dans le rapport, section A)
- [ ] Borne ouvre bien le site (nouvel écran d'accueil orange + carrousel) ? (sinon : recharger / vider Local Overrides)
- [ ] `bridge.js` tourne ? `/health` = UP ?
- [ ] Imprimante **borne** SK1-31 branchée + papier ? Imprimante **caisse** SAGA branchée + papier + **tiroir** raccordé ?
- [ ] À la caisse, `/admin/pos` ouvre l'écran POS et tu es connecté (compte caisse) ?
- [ ] Lignes imprimantes configurées côté serveur : une `station=receipt` (caisse) ACTIVE, et la cuisine (`kitchen_hot`/`kitchen`) ACTIVE ? (sinon le ticket cuisine ne part pas — le noter, pas bloquant pour le ticket client)

---

## 2. LES 2 FORMATS DE TICKET (référence — ce que tu compares)

### TICKET CLIENT (détaillé, en toutes lettres) — borne ET caisse
Doit montrer **chaque étape choisie** + prix :
```
Commande A00xx
1x Cheese Burger
  > Sauce: Samouraï
  > Salade, Tomate, Oignon
  > + Cheddar            0,90 EUR   (suppléments payants avec prix — côté caisse)
  > Formule (frites + boisson)
  > (Oasis Tropical 33cl)
```

### TICKET CUISINE (symbolique, 3 lignes) — borne ET caisse + écran KDS
```
1 x  G | MÉGA | Cordon Tender | STO | SAM     ← L1 : support | produit | viandes | crudités(STO) | sauce
       + Cheddar                              ← L2 : suppléments payants
       MENU                                   ← L3 : formule (MENU complet, ou F = frites seules)
```
Symboles : viandes K(haché)/P(poulet)/Tender/Nug/Mex/Frec/Cordon · sauces SAM/ALG/BBQ/CURY/BL/MAY/HAN/AND/KTP/HAR/FRO/SPI · crudités S(salade) T(tomate) O(oignon), toujours dans l'ordre **STO**.
**L'écran KDS et le ticket cuisine papier DOIVENT afficher exactement les mêmes symboles.**

---

## 3. BATTERIE DE TESTS — COMMANDES NORMALES (différents produits)

Pour CHAQUE test : passer la commande sur la borne → relever ce qui s'imprime (borne) + ce qui apparaît au KDS + à la caisse → **prendre une photo de chaque ticket** → remplir le tableau du rapport.

| # | Produit à composer | Ce qu'il faut vérifier |
|---|---|---|
| **T1** | **1 boisson seule** (Coca-Cola 33cl) | Cas minimal : ticket client = 1 ligne nom+prix, n° commande gros. Pas de compo. |
| **T2** | **Cheese Burger** : 1 sauce (Samouraï) + crudités (Salade+Tomate+Oignon) + **sans formule** | Client : sauce + 3 crudités listées. Cuisine : `... | STO | SAM`, pas de L3. |
| **T3** | **Méga** : 2 viandes DIFFÉRENTES (Cordon Bleu + Tenders) + 1 supplément payant (Cheddar) + **formule MENU complet** + boisson (Oasis) | Client : 2 viandes distinctes + Cheddar + Menu + nom boisson. Cuisine : L1 2 viandes (`Cordon Tender`) + STO + sauce, L2 `+ Cheddar`, L3 `MENU`. **Les 2 viandes doivent être DIFFÉRENTES (pas dupliquées).** |
| **T4** | **Tacos M** : 1 viande + 1 sauce, **+ formule frites seules** | Cuisine : support `G`, L3 = `F` (frites, pas MENU). |
| **T5** | **Tacos L** : 2 viandes + 2 sauces (1ʳᵉ gratuite + 1 payante) | Client : les 2 sauces. Cuisine : 2 symboles sauce. Vérifier le supplément sauce facturé côté caisse. |
| **T6** | **Bol** (Frites ou Riz) + viande au choix | La viande choisie ressort bien (client + cuisine). |
| **T7** | **Menu Enfant** | Compo enfant correcte sur les 2 tickets. |
| **T8** | **Commande MULTI-PRODUITS** : Méga (compo T3) + 2× Tacos M + 1 Coca, en une seule commande | Tous les articles sur le ticket, quantités correctes (`2x Tacos`), **rien oublié, rien doublé**. Total = somme correcte. |
| **T9** | **Retrait de crudités** : un sandwich AVEC salade mais SANS oignon | Client : « Salade, Tomate » sans Oignon. Cuisine : `ST` (sans O). Le retrait est-il visible ? |
| **T10** | **Sans aucune crudité** | Cuisine : pas de bloc crudités. Cohérent. |

Pour chaque : **n° de commande imprimé gros/gras**, **identique sur le ticket client et le ticket cuisine** ? **Texte en GRAND** (double hauteur) ?

---

## 4. BATTERIE DE TESTS — SYNCHRO (KDS + transfert caisse)

| # | Action | Vérifier |
|---|---|---|
| **S1** | Après T3, regarder l'**écran KDS** | La commande apparaît **en temps réel** (pas après 10 s de polling) ? Au bon endroit dans l'ordre des commandes ? |
| **S2** | Comparer **écran KDS** vs **ticket cuisine papier** de la même commande | **Symboles strictement identiques** (mêmes lettres, même ordre STO) ? |
| **S3** | Sur le KDS, un produit à quantité 2 (les 2 Tacos de T8) | Affiché **fusionné en 1 carte « 2x »**, PAS deux cartes / PAS une carte « standard » vide + une « réelle ». |
| **S4** | À la **caisse** (`/admin/pos`), après une commande borne | La commande **apparaît** à l'écran POS, avec le bon n°, le bon total, la bonne compo ? |
| **S5** | La **copie comptoir** imprimée sur la SAGA à la création | Étiquetée distinctement (ex. « COMMANDE BORNE – COPIE CAISSE »), pas confondue avec un reçu fiscal ? |

---

## 5. BATTERIE DE TESTS — ENCAISSEMENT + CHOIX D'IMPRESSION (caisse)

À faire sur l'écran caisse `/admin/pos` pour une commande borne en attente :

| # | Action | Vérifier |
|---|---|---|
| **E1** | Cliquer **« Encaisser »** la commande, mode **espèces** | Reçu fiscal client imprimé sur la SAGA + **le tiroir-caisse s'ouvre** + numéro fiscal présent. |
| **E2** | Encaisser une 2ᵉ commande, mode **carte/ticket resto** | Reçu imprimé, **tiroir ne s'ouvre PAS** (espèces seulement). |
| **E3** | Numéro fiscal de E1 vs E2 | **Monotone, sans trou** (ex. 2571 puis 2572). |
| **E4** | **Choix d'impression — CLIENT** : bouton imprimer **ticket client** | Le ticket client détaillé sort (silencieux, sans fenêtre Chrome). |
| **E5** | **Choix d'impression — CUISINE** : bouton imprimer **ticket cuisine** | Le ticket cuisine symbolique 3 lignes sort. |
| **E6** | **Choix d'impression — LES DEUX** | Les deux tickets sortent (client + cuisine), une fois chacun. |
| **E7** | Re-cliquer le même bouton tout de suite | **Pas de 2ᵉ ticket** identique (garde anti-double) — ou comportement attendu documenté. |

> Note : si l'impression silencieuse caisse ne part pas, vérifier que le **pont caisse** (endpoint `/raw`) tourne et le **flag Chrome** est présent sur le navigateur caisse aussi. Sinon ça retombe sur la fenêtre d'impression du navigateur (à signaler).

---

## 6. TESTS D'ABUS / CAS LIMITES (force le système)

| # | Abus | Vérifier |
|---|---|---|
| **A1** | **Compo MAX** : un produit avec le MAX de viandes possible + toutes les crudités + plusieurs suppléments payants + formule | Tout ressort (client en toutes lettres, cuisine condensé), rien tronqué, ticket lisible même long. |
| **A2** | **3 commandes rapides** d'affilée | N° de commande **séquentiels sans collision** (A0001, A0002, A0003), chaque ticket distinct, KDS montre les 3 en ordre. |
| **A3** | **Abandonner** une commande en plein milieu (bouton abandonner/retour accueil) | Aucun ticket imprimé, panier vidé, retour à l'écran d'accueil propre. |
| **A4** | **Couper le pont** `bridge.js` puis tenter d'imprimer | La borne ne plante pas ; un bouton « réimprimer » ou un message apparaît (pas d'écran figé, pas de faux « imprimé »). Rallumer le pont → réimpression OK. |
| **A5** | **Recharger la borne (F5)** juste après une commande | La borne ne sort PAS un 2ᵉ ticket pour la même commande (garde anti-double). |
| **A6** | **Accents / caractères spéciaux** (Méga, Samouraï, Algérienne) | Sur le ticket : lisibles (la borne simplifie volontairement les accents — « Mega », « Samourai » — c'est NORMAL et accepté, juste vérifier que c'est lisible et pas du charabia). |
| **A7** | **Commande à 0 compo** (boisson) puis **encaisser** | Flux complet OK même sans composition. |
| **A8** | **Couper le papier** d'une imprimante en plein job (si possible sans risque) | Comportement géré (erreur visible), pas de blocage applicatif. |

---

## 7. LE RAPPORT À RENDRE (remplir ce template, avec photos)

```
=================  RAPPORT TEST RÉEL BORNE + CAISSE — <date> =================

A. CONNEXION / SETUP
- Borne ouvre le nouvel écran d'accueil : OUI / NON  (+photo)
- bridge.js /health = UP : OUI / NON
- Flag Chrome présent (borne / caisse) : OUI / NON / NON-applicable
- Imprimante borne SK1-31 OK : OUI / NON   | Imprimante caisse SAGA OK : OUI / NON   | Tiroir OK : OUI / NON
- Stations serveur (receipt / kitchen) ACTIVES : OUI / NON / inconnu

B. COMMANDES NORMALES (T1→T10) — une ligne par test
| Test | Produit | Ticket CLIENT correct ? | Ticket CUISINE 3 lignes correct ? | KDS correct ? | n° gros & identique ? | Problème ? | Photo |
|------|---------|-------------------------|-----------------------------------|---------------|-----------------------|------------|-------|
| T1   | Coca    | OUI/NON                 | n/a                               | OUI/NON       | OUI/NON               | ...        | #     |
| ...  |         |                         |                                   |               |                       |            |       |

C. SYNCHRO (S1→S5)
- S1 KDS temps réel : OUI/NON
- S2 KDS == ticket cuisine (mêmes symboles) : OUI/NON  (+2 photos côte à côte)
- S3 quantité fusionnée (pas de doublure) : OUI/NON
- S4 commande visible à la caisse : OUI/NON
- S5 copie comptoir étiquetée distincte : OUI/NON

D. ENCAISSEMENT + IMPRESSION (E1→E7)
- E1 encaisser espèces → reçu + tiroir ouvert : OUI/NON
- E2 carte → reçu, tiroir fermé : OUI/NON
- E3 n° fiscal monotone sans trou : OUI/NON (valeurs : ___ → ___)
- E4 imprimer ticket CLIENT seul : OUI/NON
- E5 imprimer ticket CUISINE seul : OUI/NON
- E6 imprimer LES DEUX : OUI/NON
- E7 anti-double sur reclic : OUI/NON

E. ABUS (A1→A8)
- (une ligne par test : OUI/NON + ce qui s'est passé)

F. PROBLÈMES TROUVÉS (liste priorisée)
- [BLOQUANT / MAJEUR / MINEUR] description précise + n° de commande + photo

G. VERDICT GLOBAL
- Prise de commande borne : VALIDÉE / PROBLÈME
- Ticket client (borne + caisse) : VALIDÉ / PROBLÈME
- Ticket cuisine symbolique + KDS : VALIDÉ / PROBLÈME
- Encaissement + tiroir + fiscal : VALIDÉ / PROBLÈME
- Choix d'impression (client/cuisine/les deux) : VALIDÉ / PROBLÈME
- Anti-doublon : VALIDÉ / PROBLÈME
=============================================================================
```

---

## 8. RÈGLES POUR COWORK
- **Photographie CHAQUE ticket** (client, cuisine, reçu caisse) + l'écran KDS. Une commande = ses photos.
- Note le **n° de commande** sur chaque ligne du rapport (pour qu'on retrouve).
- Si un ticket sort **petit** (pas en gros) ou **charabia** : c'est le pont `bridge.js` (taille/encodage) → le signaler, ne pas bricoler le code serveur.
- Si l'écran d'accueil est **blanc** : vider les **Local Overrides** de Chrome + le cache + recharger (ne PAS re-patcher de JS).
- Si « Touchez l'écran » ne lance rien / console « Local Network Access » : le **flag Chrome** manque.
- **Ne modifie aucun fichier serveur, aucun `.env`, ne désinstalle rien.** Tu testes et tu rapportes.
- À la fin : rends le **rapport complet rempli + toutes les photos**. C'est ça le livrable.
```
