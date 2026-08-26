# Audit Claude indépendant #2 — CAISSE-SUPERVISOR-CONTROL-20260823

Date : 2026-08-24
Canal : `claude-code-supervisor` + trois sous-agents adverses indépendants, lecture seule
Méthode : rejeu intégral de la matrice, boucles navigateur réelles, puis trois audits
adverses mandatés pour **réfuter** le travail — y compris le mien.

Le premier audit Claude (2026-08-23) avait rendu PASS sans P0/P1. Ce PASS ne clôt plus
rien : du code a changé depuis, et surtout la méthode d'alors n'a pas trouvé ce que
celle-ci a trouvé. C'est un audit neuf, pas une reconduction.

---

## 1. Verdicts des trois auditeurs adverses

| Auditeur | Périmètre | Verdict initial |
| --- | --- | --- |
| A1 | Santé POS, pastille, cockpit SLA et leurs tests | **REWORK** |
| A2 | Harnais E2E, double garde, non-destructivité du nettoyage | **REWORK** |
| A3 | A11y opérateur/borne, file hors-ligne, mission Roue | **REWORK** |

Trois REWORK indépendants sur un cycle déclaré prêt. Chaque finding a été **revérifié par
mes propres commandes** avant toute action : deux ont été écartés, tous les autres
confirmés et remédiés.

---

## 2. Findings confirmés puis remédiés

### P1 — `globalSetup` Playwright écrivait sans aucune garde de base

`tests/Playwright/global-setup.js` s'exécute pour **toutes** les specs, avant tout
`beforeAll`, et n'était conditionné qu'à `E2E_BACKEND_AVAILABLE=1`. Il appelle
`foodking:ensure-admin`, dont `app/Console/Commands/EnsureAdminLoginCommand.php:127-135`
réécrit le mot de passe, réactive le compte et remet `deleted_at` à `null` — donc
**ressuscite un administrateur supprimé** — sans aucune garde d'environnement (vérifié :
zéro occurrence de `environment`/`production`/`APP_ENV` dans ce fichier). Quatre seeders
suivent. Aucune de ces écritures ne vérifiait la base cible.

La double garde protégeait les helpers borne ; elle ne protégeait pas le harnais.

**Remédié** : `globalSetup` exige désormais les deux mêmes signaux indépendants —
`FOODKING_E2E_DEDICATED_DB=1` **et** un nom de base portant un segment de test. Preuve
exécutée : sans les deux, il s'arrête avec un message nommant la base vue ; avec les deux
sur `foodking_e2e`, il franchit la garde et les seeders passent.

**Point d'intégrité** : mes propres exécutions n'ont jamais positionné
`E2E_BACKEND_AVAILABLE`, donc `globalSetup` sortait immédiatement. Aucune preuve de ce
cycle n'est contaminée par ce chemin.

**Escalade non résolue** : l'absence de garde de production sur la commande
`foodking:ensure-admin` elle-même reste ouverte. Elle est atteignable hors E2E, c'est du
code produit hors périmètre, et elle appartient au propriétaire.

### P1 — La garde vérifiait la base du CLI, pas celle du serveur qui écrit

`assertCurrentE2EWriteScope()` résout le nom de base via `php artisan tinker` — un
processus **séparé** de l'application testée. Toutes les écritures partent en revanche du
serveur HTTP visé par `PLAYWRIGHT_BASE_URL`, et `playwright.config.js:54` porte
`reuseExistingServer: true` : Playwright adopte un serveur démarré par quelqu'un d'autre,
avec l'environnement de quelqu'un d'autre. Un serveur pointant sur la production passait
donc la garde sans un mot.

**Remédié** : `assertServerSharesVerifiedDatabase()` exploite la forme `{id}|{secret}` du
jeton Sanctum. Après la connexion borne, on vérifie que la ligne `personal_access_tokens`
que le **serveur** vient d'écrire est visible depuis la base vérifiée en CLI. Si les deux
processus divergent, on s'arrête **avant la première commande**, donc avant toute écriture
métier ou fiscale. Branché sur les deux chemins de `getKioskApiToken`.

**Résidu assumé et écrit dans le code** : la création du jeton reste écrite avant le
contrôle. Une preuve entièrement pré-écriture demanderait un point d'entrée serveur
exposant l'identité de sa base — code produit, hors périmètre, remonté au propriétaire.

### P1 — Compteur « en retard » : une alarme permanente pour zéro retard réel

`agingOrdersCount()` n'avait aucune borne basse : toute commande jamais sortie de
PENDING/ACCEPT/PREPARING comptait **à vie**. Mesuré en base sur la branche 1 :
**248 commandes « en retard », dont ZÉRO datant des dernières 24 h**, la plus ancienne du
2026-05-28. La pastille affichait donc un nombre à trois chiffres en permanence. Un
compteur qui hurle sans arrêt se fait ignorer aussi sûrement qu'un faux vert.

Aggravant relevé par l'auditeur : la sonde voisine du **même fichier**,
`staleOutboxCount()`, avait déjà tranché ce cas avec une fenêtre de 24 h. La leçon n'avait
pas été portée.

**Remédié** : fenêtre 24 h alignée sur la sonde voisine, et test étendu avec deux
traînardes (25 h, 7 jours) qui ne doivent plus compter.

### P1 — Régression introduite par ce cycle : les Admin recevaient un 422 permanent

Le diff avait fait de « branche authentifiée > 0 » une précondition renvoyant **HTTP 422**.
Or l'axios de la pastille part en `catch` sur tout non-2xx : les comptes Admin en
`branch_id = 0` — **16 comptes actifs, comptés en base** — voyaient « Contrôle
indisponible » ambre à vie, avec un bouton « Réessayer » incapable de réussir. Le corps 422
soigneusement rédigé n'était rendu par personne. Cela contredisait CLAUDE.md §9, où
`branch_id = 0` est une identité admin légitime.

**Remédié** : HTTP **200** affichable, quatre sondes `unknown`, `branch_required: true` et
des messages qui disent quoi faire. **Aucune sonde n'est exécutée** : l'exactitude fiscale
par branche reste entière, zéro donnée d'une autre branche. La résolution de branche passe
en outre par `DefaultAccessModelTrait::branch()`, la voie canonique du projet, ce qui
supprime la divergence entre la pastille et le tracker qu'elle surmonte.

### P2 — Deux masquages de sévérité, en front et en back

- **Backend** : `queuePending === null` était testé **avant** `socket === 'fail'`. Une
  panne de file — qui accompagne typiquement une panne de socket — rétrogradait un « temps
  réel coupé » certain (rang 2, rouge) en « indisponible » (rang 1, ambre).
- **Pastille** : `hasUnknownCheck` était évalué **avant** `overall === 'down'`, avec le même
  effet à l'écran, et « Contrôle dégradé » effaçait le libellé « Temps réel coupé ».

Dans les deux cas on effaçait une mauvaise nouvelle certaine avec l'incertitude d'un voisin.

**Remédié** : le fait dur passe avant l'inconnue, des deux côtés. Un `overall` inattendu ne
retombe plus en vert non plus. Deux tests neufs figent chaque sens.

### P1 — Mon propre correctif d'accessibilité ne couvrait que la moitié des écrans

L'auditeur A3 a retourné mon travail contre lui-même : six composants admin de plus
portaient le défaut identique (`HistoriqueList`, `TransactionList`, `ItemsReportList`,
`SubscriberList`, `TableOrderList`, `OnlineOrderList`), et **ma sentinelle codait la liste
des fichiers en dur** — elle leur était structurellement aveugle, comme à tout composant
ajouté demain.

**Remédié** : les **12** composants du dépôt sont traités, et la sentinelle **découvre
elle-même** tout `.vue` déclarant `preset-ranges`, avec un plancher pour qu'une découverte
vide ne passe pas inaperçue.

Deux défauts supplémentaires trouvés pendant cette reprise, dont un que j'avais introduit :
- `TableOrderListComponent.vue` utilise des guillemets doubles ; mon script y avait
  **dupliqué la clé `slot`** et laissé l'entrée de démo. C'est la sentinelle renforcée qui
  l'a attrapé. Réparé, et la sentinelle interdit désormais la clé dupliquée.
- Cinq écrans affichaient encore « Today / This month / Last month / This year » en
  violation d'ADR-007 (locale FR). Traduits, avec une assertion qui interdit le retour.

### P2 — Répétition clavier : Espace maintenu lançait 25 commandes

`.prevent` sur `keydown.space` déplace l'activation du `keyup` natif vers le `keydown`. Un
`<button>` natif ne répète pas sur Espace maintenu ; un handler `keydown`, si. Sur une
borne, un doigt posé sur la barre d'espace lançait donc une commande par répétition.

**Remédié** : garde `$event.repeat` sur les tuiles de type de commande et sur la carte
produit, plus une garde `loadingItemId` sur le chemin carte extérieure qui n'en avait pas.
**Prouvé par mutation** : garde retirée → **25** départs au lieu de 1, le test rougit ;
garde remise → vert.

### P2 — La bannière de quarantaine faisait disparaître l'avertissement hors-ligne

`quarantineDepth > 0` mettait le message hors-ligne en `v-else-if`. Or aucune entrée en
quarantaine n'est jamais retirée : dès qu'un poste en avait une, « Hors connexion : aucune
commande n'est enregistrée localement » devenait **définitivement invisible** — exactement
quand il compte le plus.

**Remédié** : les trois états coexistent, et « hors connexion » prime sur la quarantaine
dans le libellé et la couleur, parce que c'est le plus urgent pour le caissier.

### P2 — La sentinelle de nettoyage prouvait l'orthographe, pas l'ordre

Sa découpe de fonction allait de `function X` jusqu'à la mention suivante d'un autre nom :
la tranche de `getKioskApiToken` avalait `resolveBranchId` **et** le JSDoc de
`placeKioskOrder`. `toContain('assertCurrentE2EWriteScope()')` restait donc vert si la garde
était déplacée dans une autre fonction, ou placée **après** la création de la commande.

**Remédié** : découpe par appariement d'accolades, plus un test qui compare la **position**
de la garde à celle de la première mutation dans chaque fonction. **Prouvé par mutation** :
garde retirée de `placeKioskOrder` → 2 tests rougissent.

Durcissements complémentaires du même helper :
- la reconnaissance d'une base dédiée exige un **segment entier** (`protest`,
  `contest_prod`, `foodking_greatest`, `lecayenne_latest` étaient acceptés ; ils sont
  maintenant refusés, et les sept bases de test réelles passent toujours) ;
- `cleanupKioskAuditOrders` refuse un préfixe de moins de 8 caractères ou porteur d'un
  joker `LIKE` — un préfixe vide donnait `LIKE '%'`, donc l'annulation de **toutes** les
  commandes annulables de la branche ;
- la garde y est enfin la **première** instruction.

### P2 — Roue : deux de mes propres assertions surpromettaient

- `« le client ne relance pas un second tour après un refus »` était invérifiable ici (le
  banc ne recliquait jamais) **et fausse comme propriété** : `roue.html` réactive
  délibérément le bouton pour qu'un 428 puisse être retenté. Reformulée en ce qui est vrai
  et prouvable : **aucune relance automatique** sur neuf secondes sans action.
- Les trois segments partageaient la même photo : `gain.photo === PHOTO` prouvait « une
  photo s'affiche », jamais « celle du bon segment ». Photos rendues distinctes ;
  l'assertion vise maintenant le segment retourné et exclut ses voisins.

---

## 3. Findings écartés après vérification

- **Plafond `MAX_ENTRIES` de la file hors-ligne** : aucun chemin produit n'appelle plus
  `enqueueOrder`, et `posOfflineNoFalsePromiseSentinel.spec.js` verrouille ce compte à zéro.
  Le plafond ne peut rien bloquer aujourd'hui. Latent, consigné, non corrigé.
- **`BROADCAST_DRIVER` non-`pusher` renvoyant `ok` en dur** : réel, mais dans
  `HealthzController`, sonde partagée hors périmètre et au rayon d'action large. Escaladé,
  non touché.

---

## 4. Preuves après remédiation

| Preuve | Résultat |
| --- | --- |
| Matrice backend, 8 suites séparées | **41 passed, 0 failed** |
| Vitest complet | **440 fichiers, 3 609 passed, 3 skipped, 0 failed** |
| `npm run pos:lint:status` | OK — 36 fichiers |
| Build production | OK · `pos-wizard.js` md5 `19bc97222ad0e9ee41e93ca9492446e8` inchangé |
| Wave E réel (double opt-in) | **1 passed** |
| Multi-produit réel (double opt-in) | **1 passed** |
| Parcours obligatoires (kds-caisse-smoke + multi-appareils) | **3 passed** |
| Boucle superviseur, 16 surfaces | **9/9**, 0 fuite i18n, 0 `NaN`, 0 erreur JS |
| Roue — banc focalisé | **34/34** |
| Roue — carrousel | **87/87** (était 78/84 ROUGE) |
| Roue — bandeau / mouvement / entrée sans jeton | 17/17 · 41/41 · 10/10 |
| Roue — suite tablette PHPUnit | 6 passed |
| Diff des 13 chemins gelés §7 | **vide** |
| `git diff --check` scoped | propre |
| Identité borne après trois parcours | `KIOSK-LC-001` **inchangée** |
| Postcondition DB | 0 commande synthétique active, 0 ligne supprimée |

Tests ajoutés dont la capacité à échouer est **prouvée par mutation** : garde de répétition
clavier (25 vs 1), position de la garde E2E (2 tests rouges), slot d'accessibilité des
préréglages (1/3 nommé).

---

## 5. Ce que cet audit ne couvre pas

- L'ergonomie de la caisse fait l'objet d'un rapport séparé,
  `reports/audit/CAISSE_ERGONOMIE_CAISSIER_2026-08-24.md`. Il contient une trouvaille de
  gravité élevée — la grille de vente sous la ligne de flottaison en 1366×768 — qui
  **appartient au propriétaire** et n'a pas été corrigée unilatéralement.
- Le gate UX de la Roue reste `PENDING_HUMAN_GATE`. Il ne peut pas être auto-approuvé.
- Les escalades §2 et §3 (garde de production sur `ensure-admin`, `BROADCAST_DRIVER`,
  requête SLA sans borne basse dans `DashboardService`) sont hors périmètre et ouvertes.

---

AUDIT_CHANNEL: claude-code-supervisor + 3 sous-agents adverses lecture seule
TERMINAL_AUDIT_OK: 1
AUDIT_FINDINGS_INITIAL: 3 × REWORK indépendants
AUDIT_FINDINGS_REMEDIATED: 11 (2 P1 sécurité harnais, 2 P1 honnêteté de la santé, 1 P1 régression 422, 1 P1 accessibilité incomplète, 5 P2)
AUDIT_FINDINGS_REJECTED_AFTER_VERIFICATION: 2
AUDIT_VERDICT: PASS — sous réserve des escalades nommées au §5, qui sont hors périmètre et remontées au propriétaire
