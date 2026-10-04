# Corrections QA — 30/09/2026

Périmètre demandé : `RAPPORT_DEV_CAISSE_2026-09-24.md` (8 P0) et
`QA_LOOP_NEXT_ACTION_2026-09-29.md` (auto-login borne).

Règle appliquée à chaque point : **rien n'est déclaré corrigé sans reproduction**, et
rien n'est déclaré faux sans preuve. Deux défauts ont été rejoués **par l'écran** avant
d'être touchés. Zéro ligne de zone gelée modifiée.

---

## 1. Corrigé, avec preuve

### P0-01 — « Modifier » perdait deux viandes sur trois, en silence

Le rapport en fait son **risque n°1** : « Tacos XL 3 viandes … à la réouverture
*Modifier*, la modale recharge une seule viande ». Un correctif du 26/09 (`c3dafb064`)
avait traité une première cause ; **le défaut restait**, pour une autre raison.

**Reproduit par l'écran** (arbre `:8000`) : composer 3 viandes → panier 11,40 € →
« Modifier » → **une seule tuile rallumée sur trois**, alors que l'écran affiche
toujours « 3/3 incluses ». Le caissier voit une composition amputée et peut la valider.

**Cause racine, prouvée en base** (Tacos XL, item 234) — le backend attribue un id de
variation **différent par attribut** pour le même nom :

| viande | Viande 1 | Viande 2 | Viande 3 |
|---|---|---|---|
| Mexicanos | 777 | 784 | 791 |
| Cordon Bleu | 778 | 785 | 792 |
| Viande Hachée | 779 | 786 | 793 |

Une ligne à trois viandes enregistre donc `777 / 785 / 793`. Or l'assistant de caisse
(`public/js/pos-wizard.js`, **zone gelée**) dédoublonne ses tuiles **par nom** et ne
garde que l'id du **premier** attribut : ses tuiles sont `v_777 / v_778 / v_779`, et il
lit le compte sous cette clé seulement. `v_785` et `v_793` ne correspondaient à aucune
tuile. Le compteur « 3/3 », lui, somme toutes les clés — d'où l'écran contradictoire.

**Correctif** (hors zone gelée) : `resources/js/helpers/posViandeCanonique.js` normalise
la clé vers l'id du premier attribut portant ce nom, dans l'ordre exact de déduplication
du wizard. Ni la viande, ni le prix, ni la quantité ne changent : seulement la clé sous
laquelle la surface d'affichage sait la lire.

**Un défaut dans mon propre correctif, attrapé par la suite** : ma première version
appelait `this.idViandeCanonique` et cassait trois bancs —
`buildWizardRestorePayload` est appelée **non liée**, donc `this` y est `undefined`.
C'est une fonction pure par conception ; le correctif est donc un helper de module.

**Preuves** : banc navigateur **3 → 1 avant**, **3 → 3 après** (il mord) ; 7 tests
unitaires dont un qui interdit nommément toute clé d'attribut secondaire ; suite JS
complète **4 583 verts**.

### P0-07 / P0-08 — « Tout va bien » avec zéro imprimante active

Vérifié par lecture : `PosSystemHealthController` contrôlait le temps réel, les files,
le stock et les commandes en souffrance — et **jamais les imprimantes** (zéro occurrence
de `printer` dans le fichier). Un restaurant pouvait ouvrir, lire « Tout va bien », et
découvrir à la première commande que rien ne sortait en cuisine.

Ajout d'un contrôle `impression` : 0 imprimante active → l'écran cesse de dire que tout
va bien, avec un message qui dit la **conséquence** (« rien ne sortira en cuisine »).
Sévérité plafonnée à l'ambre, jamais au rouge : on encaisse toujours et l'écran cuisine
reste la voie de secours. Échec du contrôle lui-même → `unknown`, jamais `0` — « je ne
sais pas » ne doit se lire ni « tout va bien » ni « rien ne marche ».

**Le TPE n'est volontairement pas signalé** : `Simulation / SIM-CAYENNE-1` est l'état V1
assumé et documenté (CLAUDE.md §3bis). Le signaler contredirait une décision écrite.

Deux bancs existants viraient à l'ambre pour une raison étrangère à ce qu'ils mesurent ;
ils posent désormais une imprimante pour isoler leur invariant, qui est inchangé.
**5 nouveaux tests**, 19 verts sur la santé caisse, 134 sur l'observabilité.

### Borne — un refus d'auto-login dit enfin pourquoi

`QA_LOOP_NEXT_ACTION_2026-09-29`. Constat re-vérifié sur la production à l'instant :
`/kiosk/idle` répond **200** et le HTML porte bien `kioskAutoLogin: null`.

**Le garde est correct et n'a pas été touché.** Sans voie autorisée, aucun identifiant
machine ne part dans le HTML : c'est ce qui empêche un anonyme de les récolter par
`curl`. Le rapport a raison de refuser tout contournement.

Ce qui manquait : **un refus ne laissait aucune trace**. La recette a dû remonter la
cause en lisant le code puis en testant à l'aveugle avec et sans `machine_key`. Même
famille que deux autres défauts corrigés cette semaine (erreur SQL masquée sans journal,
repli paiement muet).

`KioskAutoLoginGate::motifDeRefus()` nomme la première condition manquante
(`identifiants_machine_absents`, `aucune_voie_configuree`, `secret_fourni_invalide`,
`borne_non_autorisee`…). Elle **ne décide rien** — un test le fige — et distingue « rien
n'est configuré » de « configuré, mais cette borne n'y est pas » : deux gestes
différents. Journalisé une fois par minute et par motif. Ne sortent jamais : le secret,
le mot de passe machine, l'adresse de la borne — un test l'exige nommément.

Vérifié de bout en bout, entrée réelle produite :
`[borne] auto-login refusé : secret_fourni_invalide {"chemin":"kiosk/idle",
"voie_secret_configuree":true,"nb_plages_de_confiance":0}`.

---

## 2. Réfuté, avec preuve

### P0-06 — « Ventes du jour 0,00 € » alors que la supervision montre une commande

**Ce n'est pas une contradiction.** La tuile compte le chiffre **réalisé** :
`Order::realizedRevenue()` exige `payment_status = PAID`. La supervision montre les
commandes **en cours**, y compris celles qui attendent d'être encaissées. Une commande
en attente d'encaissement vaut légitimement 0 € de chiffre réalisé.

La vraie divergence — deux tuiles du même écran avec deux repères de date différents,
mesurée à **104,90 €** d'écart le 28/05 — avait déjà été corrigée le **29/08** :
`scopeJourMetier` définit « aujourd'hui » une seule fois, avec
`COALESCE(business_date, DATE(order_datetime))` qui rattrape les 167 commandes à date
métier nulle.

### P0-02 — « POS : 5 à encaisser ; Tracker : 0 aujourd'hui + 5 antérieures »

Ce sont **les mêmes cinq commandes**, décrites deux fois. La file d'encaissement n'a
volontairement **aucun filtre de journée** (une commande non encaissée doit rester
visible), tandis que le tracker sépare par journée de service. Le tracker est plus
précis, pas contradictoire.

L'ambiguïté réellement dangereuse — deux commandes de jours différents portant le même
numéro court — a été corrigée séparément (badge de date sur la file d'encaissement).

---

## 3. Hors de portée d'un correctif de code

### P0-03 / P0-04 — KDS en « Mode secours actif », WebSocket désactivé

L'écran de santé **détecte déjà** l'état du socket (`websocketStatus()` → `ok` / `fail` /
`unknown`) et descend jusqu'à `down` quand il est en échec avéré. Le « mode secours » du
KDS est le **repli prévu**, pas une panne silencieuse.

`MIX_PUSHER_APP_KEY not set` est une **configuration de déploiement**. Aucun correctif de
code ne doit la contourner.

### Borne — la mise en service elle-même

Le rapport du 29/09 le dit et il a raison : il faut, **sur le VPS**, vérifier la machine
active et les identifiants machine, puis choisir la voie —
`KIOSK_AUTO_LOGIN_SECRET` (lien secret, robuste au changement d'IP) **ou** le CIDR réel
de la borne. Ce lot rend ce geste diagnosticable ; il ne le remplace pas.

⚠️ **À faire en même temps** : les identifiants par défaut `kiosk-lecayenne` /
`kiosk123` sont publics (dépôt + `.env.example`). Poser un secret d'auto-login sans
tourner ce mot de passe laisserait la porte ouverte.

---

## 4. Ce qui reste ouvert, et pourquoi

| Point | État | Raison |
|---|---|---|
| P0-05 (`APP_ENV=staging`) | traité en session précédente | décision propriétaire : ne pas basculer, terminaux non câblés |
| P0-08 (TPE simulé) | non signalé, à dessein | état V1 assumé et documenté |
| Remise manuelle en caisse | LOCK propriétaire | le vrai correctif est dans `PaymentComponent.vue`, **gelé** |
| Empreinte gelée `KioskWizardComponent.vue` | contresignature propriétaire | 3 lignes de commentaire, déjà en production |
| Cron `schedule:run` | exploitation | la purge automatique existe, testée, mais rien ne l'appelle |
