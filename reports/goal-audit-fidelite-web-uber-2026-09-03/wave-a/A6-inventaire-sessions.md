# A6 — Inventaire des sessions et des branches : ce qui n'est PAS dans HEAD

**Arbre** : `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt`
**HEAD** : `a91f95e2e` · branche `pos/category-first-caisse-2026-06-23` · 2026-09-03
**Mode** : lecture seule stricte. Aucun `checkout`, `stash`, `add`, `commit`. Toutes les
affirmations ci-dessous sortent d'une commande `git` réellement exécutée.

Méthode centrale : `git cherry HEAD <branche>` — il distingue *« commit absent de HEAD »* de
*« commit dont le PATCH est déjà dans HEAD sous un autre SHA »*. Le simple compte
`git log HEAD..<branche>` ment sur ce point : il surestime le travail à risque.

---

## § Branches et leur écart à HEAD

`git rev-list --count HEAD..<b>` / `<b>..HEAD`, puis `git cherry -v HEAD <b>` :

| Branche | Dernier commit | En avance | En retard | Réellement absents de HEAD (`cherry +`) | Verdict |
|---|---|---|---|---|---|
| `fix/tableau-de-bord-2026-09-03` | 2026-09-03 | 1 | **0** | **1** | (b) non intégrée — enfant direct de HEAD |
| `garde/sauces-pain-2026-09-03` | 2026-09-03 | 0 | 14 | 0 | (a) fusionnée |
| `fix/rattrapage-fusion-2026-09-03` | 2026-09-03 | 1 | 24 | **1** | (b) non intégrée — **collision, voir §cluster** |
| `livraison/wizard-pages-prod` | 2026-09-03 | 5 | 55 | **3** | (b) non intégrée |
| `fusion/deploy-2026-09-02` | 2026-09-02 | 0 | 24 | 0 | (a) fusionnée |
| `livraison/wizard-pages-sur-prod` | 2026-09-02 | 0 | 54 | 0 | (a) fusionnée |
| `fix/caisse-sauces-pain-galette-2026-09-02` | 2026-09-02 | 1 | 57 | **0** | (a) patch déjà dans HEAD (doublon) |
| `chef/cockpit-2026-09-01` | 2026-09-01 | 1 | 258 | **0** | (a) patch déjà dans HEAD (doublon) |
| `fix/sauces-caisse-2026-08-30` | 2026-08-30 | 0 | 57 | 0 | (a) fusionnée |
| `hold/pos-wizard-sauces-attente-contreseing` | 2026-08-28 | 0 | 261 | 0 | (a) fusionnée |
| `pos/caisse-kds-fidelite-2026-08-21` | 2026-08-21 | 4 | 282 | **4** | (b) non intégrée |
| `fix/uber-order-fetch-v2` | 2026-08-03 | 10 | 871 | **10** | (b) non intégrée |
| `backend-email-dabord-sur-origine` | 2026-09-02 | 4 | 54 | **2** | (b) non intégrée |
| `port/e2e-2026-09-02` | 2026-09-02 | 0 | 55 | 0 | (a) fusionnée |
| `voice-order/assist-v1-2026-08-31` | 2026-09-02 | 5 | 57 | **5** | (b) non intégrée |
| `goal/roue-concours-saas-2026-08-19` | 2026-08-20 | 52 | 338 | 52 | (b) non intégrée — 160 fichiers |
| `goal/caisse-vision-2026-08-24` | 2026-08-29 | 0 | 59 | 0 | (a) fusionnée |
| `release/consolidation-2026-08-28` | 2026-08-28 | 0 | 85 | 0 | (a) fusionnée |
| `goal/onboarding-commercant-2026-08-26` | 2026-08-28 | 0 | 151 | 0 | (a) fusionnée |
| `feat/tacos-xl-3-viandes-2026-08-24` | 2026-08-26 | 0 | 226 | 0 | (a) fusionnée |
| `goal/caisse-parfaite-2026-08-22` | 2026-08-22 | 0 | 265 | 0 | (a) fusionnée |

(c) Branches `backup/**` : toutes celles datées ≥ 2026-08 (`backup/pre-consolidation-2026-08-25`,
`backup/pre-supervision-2026-08-19`, `backup/pre-goal-ops-swap-2026-08-12`, `backup/pre-8axes-2026-08-05`)
sont dans `git branch --merged HEAD` → **à ignorer**, elles ne portent rien d'unique.

**Divergence côté distant** : `origin/pos/category-first-caisse-2026-06-23` est **1 commit devant
HEAD local** (`9e1dd6447`, le même que `fix/tableau-de-bord`). Le tableau de bord est donc déjà
*poussé* mais pas *dans l'arbre local*. `origin/livraison/wizard-pages-2026-09-03` est à 5 devant.

---

## § Travail NON intégré — le risque de perte

Neuf branches portent du patch réellement absent de HEAD. Par ordre de risque :

1. **`fix/uber-order-fetch-v2` — 10 commits, 22 fichiers, orpheline depuis le 2026-08-03 (871 commits
   de retard).** C'est le plus gros gisement à risque et il est directement dans le périmètre de ce
   GOAL (« web / uber »). Contenu : `UberClient`, `UberOrderMapper`, `UberMenuBuilder`,
   `UberWebhookController`, `PushUberMenuJob`, 3 listeners, `config/uber.php`, 3 suites
   `tests/Feature/Uber/*`, visibilité Uber en caisse (`PosOrdersTrackerComponent.vue`,
   `HistoriqueListComponent.vue`, `KitchenDisplaySystemComponent.vue`) et
   `docs/runbooks/UBER_GO_LIVE.md`. Corrige des défauts prouvés en sandbox payante (fetch v1
   → v2, body `[]` rejeté 400, `tax_info` stdClass). **Rien de tout cela n'est dans HEAD.**
2. **`goal/roue-concours-saas-2026-08-19` — 52 commits, 160 fichiers** (roue + produit autonome
   Kadora). Volume énorme, hors périmètre caisse, mais 338 commits de retard : la fusion sera chère.
3. **`voice-order/assist-v1-2026-08-31` — 5 commits, 43 fichiers**, dont un service Python autonome
   `services/voice-gateway/`. Attention : `git cat-file -e HEAD:resources/js/components/admin/pos/VoiceOrderAssistantPanel.vue`
   réussit — le panneau **existe déjà dans HEAD** alors que les 5 commits de la branche sont tous
   marqués absents. Branche et HEAD portent donc deux états divergents du même sujet : reprise à
   faire au patch, pas en fusion aveugle.
4. **`pos/caisse-kds-fidelite-2026-08-21` — 4 commits, 21 fichiers** (supplément libre saisi à la
   main, TVA ventilée, `PosManualCharges`, créneaux de retrait). Le commit de tête dit lui-même
   « non pousse ».
5. **`livraison/wizard-pages-prod` — 3 commits absents, 64 fichiers** dont **deux migrations**
   (`2026_09_02_100000_create_wizard_pages_tables`, `..._110000_bootstrap_wizard_pages_library`).
   Une migration non intégrée est un risque de schéma divergent entre l'arbre et la production.
6. **`backend-email-dabord-sur-origine` — 2 commits** (connexion « e-mail d'abord » + le retrait de
   la porte dérobée d'examen App Store). Les deux vont ensemble : n'en reprendre qu'un
   réintroduirait le contournement d'authentification.
7. **`fix/tableau-de-bord-2026-09-03` — 1 commit, 7 fichiers, ZÉRO retard.** Trivial à intégrer
   (avance rapide), déjà sur `origin`. C'est le seul cas sans coût.
8. **`fix/rattrapage-fusion-2026-09-03` — 1 commit, 52 fichiers.** Voir §cluster : à NE PAS fusionner
   tel quel.
9. Doublons sans enjeu : `fix/caisse-sauces-pain-galette-2026-09-02` et `chef/cockpit-2026-09-01`
   apparaissent « 1 en avance » mais `git cherry` les marque `-` → **le patch est déjà dans HEAD**
   sous un autre SHA. Rien à récupérer, rien à perdre.

---

## § Cluster sauces / pain / galette / caisse : fichiers en collision

Le cluster annoncé comme risqué **ne l'est pas** — mesuré, pas supposé :

- `git diff --name-only HEAD...garde/sauces-pain-2026-09-03` → **0 fichier**
- `... HEAD...fix/sauces-caisse-2026-08-30` → **0 fichier**
- `... HEAD...hold/pos-wizard-sauces-attente-contreseing` → **0 fichier**
- `... HEAD...fix/caisse-sauces-pain-galette-2026-09-02` → 2 fichiers :
  `tests/js/borneBolSaucesMultiples.spec.js`, `tests/js/wizardTemplatePainEtSaucesMultiples.spec.js`

Ces deux fichiers sont **strictement identiques** à ceux de HEAD :
`git diff HEAD 1be63407b -- <les 2 fichiers>` renvoie **vide**. Les deux commits
`1be63407b` (branche `fix/…`, 2026-09-02, 320 insertions) et `69f1c0cdc` (branche `garde/…`,
2026-09-03, **320 insertions, mêmes 2 fichiers**) sont le même travail commité deux fois ;
`69f1c0cdc` est dans l'historique de HEAD. **Duplication, pas contradiction.**

**La vraie collision est ailleurs** — `fix/rattrapage-fusion-2026-09-03` contre HEAD, sur les
modules partagés du tiroir de contrôle caisse. Les cinq fichiers que ce commit dit avoir été
« perdus » (`PosControlDrawer.vue`, `filesControle.js`, `fileCuisine.js`, `canalCommande.js`,
`compositionCommande.js`) sont **tous présents dans HEAD**, et HEAD est plus AVANCÉ :

```
git diff --numstat HEAD e91be973f
  0  63   resources/js/components/admin/pos/PosControlDrawer.vue
 14  17   resources/js/support/fileCuisine.js
 12  13   resources/js/support/filesControle.js
```

Lecture du hunk : HEAD importe les enums canoniques (`orderStatusEnum`, `paymentStatusEnum`,
`orderTypeEnum`, `posPaymentMethodEnum`) adossés à
`tests/Feature/Sentinels/EnumsJsPhpConvergenceSentinelTest.php` ; la branche recopie encore sept
constantes en dur (`STATUT_ACCEPT = 4`, `PAIEMENT_PAID = 5`, …). **Fusionner cette branche telle
quelle régresse HEAD** : le rang cuisine annoncé au comptoir redeviendrait figé, sans banc rouge
pour le dire. C'est exactement le motif « deux corrections justes du même défaut qui se
contredisent à la fusion ». À résoudre **hunk par hunk**, jamais `--theirs`/`--ours` au fichier.

Autres fichiers touchés par **plusieurs** branches non intégrées (points de conflit à venir) :

| Fichier | Branches |
|---|---|
| `routes/api.php` | `livraison/wizard-pages-prod`, `backend-email-dabord-sur-origine`, `voice-order/assist-v1` |
| `resources/js/components/admin/pos/PosComponent.vue` | `fix/rattrapage-fusion`, `voice-order/assist-v1`, `pos/caisse-kds-fidelite` |
| `PROJECT_BRAIN.md` | `livraison/wizard-pages-prod`, `pos/caisse-kds-fidelite`, `goal/roue-concours-saas` |
| `resources/js/languages/{fr,en}.json` | `livraison/wizard-pages-prod`, `fix/uber-order-fetch-v2` |
| `KitchenDisplaySystemComponent.vue` | `pos/caisse-kds-fidelite`, `fix/uber-order-fetch-v2` |
| `resources/css/pos-v5.css` | `fix/rattrapage-fusion`, `pos/caisse-kds-fidelite` |
| `resources/css/app.css` | `livraison/wizard-pages-prod`, `pos/caisse-kds-fidelite` |
| `app/Providers/EventServiceProvider.php` | `livraison/wizard-pages-prod`, `fix/uber-order-fetch-v2` |
| `.env.example` | `backend-email-dabord-sur-origine`, `voice-order/assist-v1` |

---

## § Zones gelées touchées hors HEAD

Les 15 chemins de CLAUDE.md §7 ont été testés un par un, contre les 10 branches en avance
(`git diff --numstat HEAD...<b> -- <fichier gelé>`).

**Résultat : ZÉRO ligne. Aucune branche non intégrée ne touche une zone gelée.**

Le risque zone gelée est donc **déjà dans HEAD**, pas devant : `docs/locks/LOCK_INCIDENT_CAISSE_SAUCE_2026-09-03.md`
couvre une modification de `app/Services/Pricing/PricingService.php` (gate propriétaire obtenue,
commit `6e2f038cd`), et `docs/locks/LOCK_FUSION_ZONE_GELEE_ALIGNEMENT_PRODUCTION_2026-09-02.md`
couvre trois fichiers gelés réalignés sur la production par la fusion `f0da0bc82`.

---

## § État de l'arbre partagé

- `ls .git/MERGE_HEAD` → **absent**. **Aucune fusion en cours.** (Contrairement à un incident
  antérieur consigné en mémoire, l'arbre n'est pas en état de fusion inachevée.)
- `git status --short | wc -l` → **49** : **23 modifiés** (` M`) et **27 non suivis** (`??`).
- Les 23 modifiés sont presque tous des **artefacts** : 15 PNG/JSON de captures
  (`reports/goal-caisse-controle-2026-09-02/captures-apres/`, `reports/supervision/2026-09-03/captures/`),
  5 CSV `reports/i18n/`, `reports/grok/JOURNAL.md`. Seuls **deux fichiers de code** sont modifiés :
  `tests/Feature/Grok/ComposerMerchantLiesTest.php` et `tests/js/productComposerEditor.spec.js`.
- Les 27 non suivis sont des rapports (`reports/audit/CODEX_*`, `reports/test-e2e/chef-round-6/`,
  `reports/planning/NEXT_QA_*`), deux docs `docs/grok/`, un plan, plus **deux fichiers de test
  nouveaux** : `tests/Feature/Grok/CashierCannotToggleInterrupteurTest.php` et
  `tests/Playwright/incident-cayenne-2026-09-03.spec.js`.
- `git worktree list` → **21 arbres de travail**. Deux sont vivants sur des branches de ce
  rapport : `…/scratchpad/livraison` sur `livraison/wizard-pages-prod` (6ea1b2efe) et
  `.claude/worktrees/voice-order-v1-2026-08-31` positionné, lui, sur
  `fix/caisse-sauces-pain-galette-2026-09-02` — un nom de dossier qui ne correspond plus à sa
  branche, source d'erreur pour la session qui l'occupe.

**Incidents récents ouverts** (`ls -t reports/`, `find … LOCK_*/FINDING*`) :
1. `reports/supervision/2026-09-03/FINDING-APP-ENV-STAGING.md` — **OUVERT** : la machine qui
   encaisse tourne en `APP_ENV=staging`, donc tous les gardes de boot NF525 (§8) sont inertes.
   Constat seul, rien modifié.
2. `reports/incidents/2026-09-03/CAISSE-COMMANDES-BLOQUEES.md` — clos : deux causes superposées,
   corrigées et vérifiées en production (`6e2f038cd`, `a91f95e2e`).
3. `docs/locks/LOCK_INCIDENT_CAISSE_SAUCE_2026-09-03.md` — clos, gate propriétaire obtenue.
