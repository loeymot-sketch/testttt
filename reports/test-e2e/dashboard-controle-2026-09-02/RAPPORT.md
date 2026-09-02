# Rapport — GOAL Dashboard & contrôle : contre-audit Codex, dispute, correction

**Date :** 2026-09-02 · **Branche :** `pos/category-first-caisse-2026-06-23`
**Départ :** `ef0e41d01` · **Arrivée :** `4f1e8f696` · **9 commits**
**Source auditée :** `reports/audit/CODEX_DEEP_AUDIT_DASHBOARD_CONTROL_CONTINUATION_2026-09-02.md`

---

## 1. Ce qui a été trouvé, et ce que ça change pour le restaurant

Le contre-audit Codex listait douze défauts P1 et quatre P2. Après vérification une par une —
lecture du code à la ligne, puis reproduction — **neuf sont réels et corrigés**, deux relèvent
du processus de preuve et non du produit, un dépend d'une décision du propriétaire.

La dispute a aussi trouvé **quatre défauts que Codex n'avait pas vus**, dont deux qui
empêchaient purement et simplement l'écran de fonctionner.

### Les deux plus graves — invisibles dans le code, trouvés en mesurant

**Choisir une période sur le tableau de bord ne renvoyait aucun chiffre.**
Les quatre cartes datées passaient l'objet `Date` du sélecteur directement dans l'URL.
Mesuré : la chaîne produite est `Sun Mar 01 2026 00:00:00 GMT+0100 (heure normale d'Europe
centrale)`, et le serveur la REFUSE (`Carbon::parse` → « Could not parse »). Ce n'était pas
« des chiffres faux » : c'était aucun chiffre, plus le message d'exception interne affiché en
clair à l'écran. Personne ne l'avait signalé — parce que ni un test de service, ni une lecture
du code, ni une capture d'écran au repos ne le montrent. Il fallait déclencher le sélecteur.

**Une sauvegarde qu'on n'a jamais su restaurer s'affichait en vert.**
`backup:verify-restore` tourne à 5 h : il remonte la dernière sauvegarde dans une base
jetable, compare les comptes de lignes et vérifie la chaîne NF525. Son verdict finissait dans
un fichier de log que personne n'ouvre — et qui n'existe même pas sur cette machine. Le
cockpit et `/health/ready` ne regardaient QUE la date du `.sql.gz`. Un fichier de deux heures
totalement corrompu affichait « Tout va bien ». C'est le pire des faux verts : on ne le
découvre que le jour où on a besoin de la sauvegarde.

### Les sept autres

| # | Défaut | Effet concret |
| --- | --- | --- |
| 1 | L'outbox comptait un CLAIM comme une livraison | 2 149 événements réclamés-jamais-livrés comptés comme délivrés ; les sondes disaient « en service » |
| 2 | Purge de la file sans trace d'audit | Des lignes supprimées définitivement, sans savoir qui ni quand |
| 3 | Deux jours par an faux dans le CA moyen | Le 31 mars disparaissait, le 25 octobre comptait double (heure d'été) |
| 4 | Une carte échappait au garde de branche | `popular-items` servait le classement de TOUTES les branches à un compte refusé partout ailleurs |
| 5 | Les pannes racontaient l'intérieur du serveur | Requête SQL, SQLSTATE, chemins de fichiers servis en 422 à qui a la permission `dashboard` |
| 6 | Couper le paiement fractionné ne laissait qu'un `Log::info` | Fichier rotaté, tronquable, purgeable par la personne même qui a basculé |
| 7 | Le widget NF525 affirmait attester une intégrité qu'il ne vérifiait pas | « Le préfixe de hash atteste l'intégrité de la chaîne » — faux, et rassurant là où il ne faut pas |

### Les quatre trouvés en plus, par la campagne navigateur

| # | Défaut | Comment il a été vu |
| --- | --- | --- |
| 8 | Le cockpit outbox gardait son vert quand il ne mesurait plus rien | `loadAll()` sans `catch` — lu dans le code, prouvé par un banc |
| 9 | `« 7/16/2026, 6:57:02 AM »` sur une date de clôture Z, en français | **Photographié** — pas déduit |
| 10 | `user.device_revoked` affiché en code brut dans le journal d'audit | **Photographié** ; 4 actions du journal réel sans libellé |
| 11 | Le PDF de clôture acceptait `2026-02-31` et produisait le PDF du 3 mars | Reproduit ; le jour par défaut était en plus choisi par le navigateur |

---

## 2. Ce qui a été refusé à Codex

Trois affirmations du contre-audit ne tiennent pas :

- **« Ticket Moyen compte de minuit à minuit alors que les tuiles comptent en journée
  commerciale. »** Faux : `business_date` est dérivé de la date civile Paris de
  `order_datetime` (`OrderService::resolveBusinessDate`). Les deux populations sont
  identiques ; il n'y a pas deux comptages.
- **« Il faut un double audit PASS avant de déclarer quoi que ce soit. »** C'est une exigence
  de procédure, pas un défaut produit. Elle n'a pas été traitée comme un correctif.
- **« Les campagnes de l'autre worktree sont rouges. »** Exact, et sans portée : elles
  décrivent un autre HEAD. Elles n'ont servi à rien ici.

---

## 3. Preuves

**Chaque banc a été prouvé mordant** — correctif neutralisé, banc rouge, correctif remis,
banc vert. Un banc qui n'a jamais rougi ne prouve rien.

| Banc | Rouge avant | Vert après |
| --- | --- | --- |
| Sémantique de livraison outbox | 5/6 | 6/6 |
| Actions outbox auditées | 6/6 | 6/6 |
| Restauration mesurée | 3/7 | 7/7 |
| Fraîcheur sans faux vert | 2/11 | 11/11 |
| Carte sauvegarde (écran) | 4/6 | 6/6 |
| Contrat de dates (4 points × 5 cas) | 4/6 | 6/6 |
| Jours civils / heure d'été | 3/3 | 3/3 |
| Dates envoyées par le sélecteur | 3/5 | 5/5 |
| PDF de clôture | 1/9 puis 3/3 (écran) | 9/9 et 3/3 |
| `popular-items` fail-closed | 1/4 | 4/4 |
| Fuite de message interne | 3/4 | 4/4 |
| Bascule d'interrupteur auditée | 3/3 | 3/3 |
| Widget NF525 honnête | 4/4 + 1 (service) | 4/4 + 7/7 |
| Cockpit outbox en panne | 9/9 | 12/12 |
| Dates françaises | 4/4 | 6/6 |

**Un banc a d'abord été faux, et c'est instructif.** Sous Node, `toLocaleString()` sans
argument rend déjà un format français : le test restait vert pendant que Chromium affichait
l'américain. Il simule maintenant un navigateur américain. De même, la campagne Playwright a
d'abord échoué en 401 parce qu'un `fetch()` nu ne porte pas le jeton Sanctum : elle passe
désormais par `window.axios`, l'instance que la page utilise réellement.

### Campagne navigateur

`tests/Playwright/dashboard-controle-captures-2026-09-02.spec.js` — trois écrans, trois tours.
Ce n'est pas un album : le test échoue sur toute réponse ≥ 400, tout libellé i18n brut, tout
écran vide, et il rejoue trois comportements corrigés dans le navigateur.

Dernier tour (`round-3/`) :
- 0 réponse HTTP ≥ 400 sur les trois écrans ;
- 0 libellé i18n brut, 0 code d'action brut ;
- période personnalisée : 200 sur les quatre points, 422 sur la période inversée ;
- heure d'été : `['2026-03-28','2026-03-29','2026-03-30','2026-03-31']` — quatre jours ;
- carte sauvegarde : « Restauration de vérification jamais mesurée — une sauvegarde non
  restaurée ne prouve rien », en rouge. C'est l'état réel de cette machine ;
- seules erreurs console : le serveur WebSocket (port 6001) n'est pas lancé localement.

### Parité serveur

`public/js/app.js` servi par `:8766` et le fichier sur disque : **empreinte SHA-256
identique** à chaque tour. Ce qui a été photographié est ce qui a été construit.

---

## 4. Ce qui reste ouvert

**Deux points pour le propriétaire :**

1. **Aucun style de bouton désactivé dans toute l'administration.** `.db-btn` n'a pas de
   règle `:disabled` : un bouton inerte a exactement l'apparence d'un bouton actif, partout.
   Corrigé localement sur les deux boutons du cockpit ; la règle globale vit dans
   `resources/css/app.css`, en cours de modification par une autre session.
2. **`fiscal:verify-chain` daté par branche au cockpit** (T-3.4.2) reste en attente : il
   dépend de l'arbitrage sur le faux positif TAMPER, qui n'est pas le mien à rendre.

**Le gate `safety-check` reste ROUGE**, pour une raison qui ne vient pas de ce travail :
`resources/js/components/frontend/kiosk/KioskWizardComponent.vue` (zone gelée) est **stagé
dans l'index** avec 223 autres fichiers, par une autre session. Aucun de mes neuf commits ne
contient de fichier gelé — ils ont tous été faits avec une liste de chemins explicite,
précisément pour ne pas emporter cet index.

**Une autre session travaille dans le même arbre.** Ses fichiers ont été modifiés pendant
cette mission (POS 18:41 → 19:31, catalogue 16:56, traductions). Conséquences assumées :
- les rouges Vitest sur `PosComponent` / `ItemCategoryResource` sont les siens, pas les miens ;
- le commit des traductions a été reconstruit depuis HEAD pour n'emporter que mes quatre clés,
  puis sa version de travail lui a été rendue intacte ;
- **aucun compteur de suite complète pris pendant cette mission ne certifie un instantané
  figé** : l'arbre bouge sous la mesure.

---

## 5. État des suites

| Suite | Résultat |
| --- | --- |
| PHPUnit ciblé (dashboard, observabilité, pilotage, outbox, sauvegarde) | 384 tests, 0 échec |
| PHPUnit voisinage dates/authz | 187 tests, 0 échec |
| PHPUnit pilotage + fiscal | 415 tests, 0 échec |
| Vitest complet | 4 031 tests, 7 rouges — tous sur `PosComponent`, autre session |
| Playwright campagne dédiée | 1/1 vert, trois tours |
| `safety-check` | **BLOQUÉ** — zone gelée stagée par une autre session |

Un rouge de fond a aussi été réparé au passage : `OrderStatisticsSingleGroupedQueryTest`
échouait depuis le 29 août (le test s'appuyait sur `branch_id = 0` sans le rôle Admin, alors
que le fail-closed exige le rôle). Le contrat de production est le bon ; c'est le test qui
était resté sur l'ancien.

---

## 6. Les neuf commits

```
4f1e8f696  cockpit outbox : un bouton inerte avait l'apparence d'un bouton actif
44a0a9738  dates : « 7/16/2026 » sur une clôture Z, en interface française
d89ed0922  cockpit outbox : gardait son vert quand il ne mesurait plus rien
36728776b  audit NF525 : le widget affirmait attester une intégrité non vérifiée
1b880a10e  pilotage : couper le paiement fractionné ne laissait qu'une ligne de log
0cd8ef3c9  tableau de bord : une carte échappait au garde de branche ; pannes bavardes
47c001fbb  tableau de bord : la période ne renvoyait aucun chiffre ; deux jours/an faux
28c2771a8  cockpit : une sauvegarde jamais restaurée s'affichait en vert
179e27d99  cockpit : l'outbox comptait un claim comme une livraison, purgeait sans trace
```
