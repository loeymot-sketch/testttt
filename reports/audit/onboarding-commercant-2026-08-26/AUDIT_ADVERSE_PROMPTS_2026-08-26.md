# AUDIT ADVERSE DES PROMPTS DE LANCEMENT — traçabilité (2026-08-26 → 27)

Quatorze agents adverses, un par GOAL, ont relu le prompt de lancement contre son GOAL, son rapport de mission et
**le code réel**. Consigne : trouver ce qui empêcherait la session d'aboutir — manques d'auto-suffisance, chemins qui
ne se résolvent pas, contradictions avec le GOAL. Huit ont rendu leur verdict le **26/08** ; les six autres (ONB-09 à
ONB-14), tués par la limite de session ce jour-là, ont été relancés et ont rendu le **27/08**. Verdict final :
**14 × CORRIGER, 0 × OK** — aucun des quatorze prompts n'était lançable tel quel.

Les corrections sont désormais portées par `PROTOCOLE_SESSION.md` (loi commune, §3 pré-vol, §5 voies, §6 base et
tests, §8 preuves, §10 gates, §14 corrections par GOAL). Les prompts ont été réécrits courts (< 4 000 caractères).

---

## Ce qui était systématiquement faux (les huit rapports le disent)

| # | Défaut | Effet réel s'il n'était pas corrigé | Où c'est réglé |
|---|---|---|---|
| 1 | **Assets Mix absents d'un worktree neuf** (`public/mix-manifest.json`, `public/css/*`, `public/js/*` sont gitignorés ; `master.blade.php` appelle `mix('css/app.css')`) | *« Mix manifest not found »* → **500 sur toutes les pages** du port de la session : la session audite un serveur mort | Protocole §3, étape 6 |
| 2 | **`safe-test.sh` a `REPO` codé en dur puis `cd "$REPO"`** | La session lance les tests de **l'arbre principal** et **ne voit jamais** ceux qu'elle écrit ; en plus le script n'est pas dans le PATH | Protocole §6 |
| 3 | **`public/storage` absent** (lien créé par `storage:link`) | Logos, favicons, images produit en **404** → faux constat « le logo ne s'affiche pas » | Protocole §3, étape 7 |
| 4 | **`.env.testing` porte `foodking_test`, partagée par les 14 worktrees** | Deux sessions qui lancent `RefreshDatabase` en même temps → **rouges fantômes** | Protocole §3, étape 3 |
| 5 | **`E2E_BACKEND_AVAILABLE=1`** déclenche dans `global-setup.js` : `foodking:ensure-admin` (réécrit le mot de passe admin), 5 seeders, `permission:cache-reset` — **sur la base partagée** ; et exige `FOODKING_E2E_DEDICATED_DB=1` sinon il s'arrête | **Déconnecte les autres sessions** de la vague | Protocole §8 |
| 6 | **Chemins `recon/…` sans préfixe** | Aucun répertoire `recon/` à la racine : les chemins **ne se résolvent pas** | Protocole §4 (note) et §14 |
| 7 | **Fichiers cités qui ne sont dans aucun commit** — `config/dashboard.php` en tête (vérifié : `git log --all` → 0) | Le worktree créé depuis HEAD **ne les a pas** : la session mesure un produit qui n'est pas celui du propriétaire | Protocole §3, étape 8 + §14 ONB-07 |
| 8 | **« Un gate bloque SA vague »** alors que le premier geste demandé était dans une vague gatée | La session **s'arrête dès W0** alors que le GOAL dit l'inverse | Protocole §10 |
| 9 | **Collisions non déclarées entre sessions parallèles** | Deux sessions éditent le même fichier — ce que l'index interdit | Protocole §5 (table) |
| 10 | **Les 6 points de contrôle du Jalonneur n'étaient énumérés nulle part** | Un contrôle invocable mais pas applicable | Protocole §7, point 7 |
| 11 | **Console et réseau non exigés à chaque capture** | Une capture sans console ni réseau ne prouve rien | Protocole §8 |
| 12 | **Sérialisation du serveur de dev non dite** | Des blocages pris pour des défauts | Protocole §8 |

---

## Collisions trouvées entre sessions de la vague A

| Objet | Revendiqué par | Arbitrage retenu (protocole §5) |
|---|---|---|
| `App\Enums\KdsStation` (à créer) | ONB-02 et ONB-10 | le premier le crée, fiche à l'autre ; jamais deux versions |
| `resources/js/components/admin/items/CatalogHubComponent.vue` | ONB-02 et ONB-08 | coordination écrite avant toute édition |
| `config/dashboard.php` | ONB-05 et ONB-07 | **ONB-07 possède** ; ONB-05 expose par fiche |
| `config/printing.php` | ONB-05 et ONB-10 | **ONB-10 possède** ; ONB-05 expose par fiche |
| `resources/js/components/admin/observability/SystemHealthComponent.vue` | ONB-05 et ONB-10 | **ONB-10 possède** ; ONB-05 y branche sa page par fiche |

---

## Corrections propres à chaque GOAL relu

- **ONB-01** — W1 amputée dans le prompt (T-1.1.1 cartographie ticket et T-4.1.2 inventaire `.env` sautés) ;
  G-ID présenté comme bloquant toute la W2 alors qu'il ne bloque que T-1.3.2.
- **ONB-02** — voie incomplète (services, modèles, `TaxTableSeeder`, `itemRoutes.js` manquants) ; attestation NF525
  après W3 absente alors que le GOAL archive des taxes ; deux collisions non signalées.
- **ONB-03** — dépendance à ONB-02 perdue (elle n'était que dans le titre, hors du bloc) ; voie incomplète
  (`WizardAdvancedLauncherComponent.vue`, `itemRoutes.js`, `WizardProfileBranchScope.php`, bloc `fr.json`) ;
  périmètre du LOCK trop étroit face à ce que W5 exige de `config/menu.php` et `config/kiosk.php` ; interdit
  « jamais de commande » absent ; nettoyage `forceDelete` absent.
- **ONB-04** — le binding mock/réel se fait sur **`assistant.enabled`**, pas seulement sur `OPENAI_VISION_ENABLED` ;
  G-DATA bloque **W2 et W3** ; les trois fichiers de menu manquaient à la liste des interdits ; « tu proposes, tu ne
  tranches pas » avait disparu.
- **ONB-05** — `config/dashboard.php`, `config/printing.php` et `SystemHealthComponent.vue` revendiqués à tort ;
  G-CACHE présenté comme bloquant toute la session ; ancrages mesurés sur du non-commité à re-vérifier en W1.
- **ONB-06** — contradiction « ne modifie jamais un rôle seedé » vs T-2.1.3 (renommer « Stuff ») : nuancée
  (permissions en base intouchables pendant les essais ; renommage par seeder après G-ROLES) ; interdiction
  d'exécuter les seeders de permissions sur la base partagée ajoutée ; trois chemins `recon/` sans préfixe.
- **ONB-07** — le plus grave : `config/dashboard.php`, `SlaAlertesBorneBasseTest.php` et le correctif SLA **ne sont
  dans aucun commit** ; filtre de tests de W6 sans `Fiscal` alors que le GOAL crée la page Rapport X ; fusion des
  dossiers `Report/` → `Reports/` oubliée dans le découpage des vagues.
- **ONB-08** — W5 présentée comme libre alors que G-HUB-STOCK la bloque ; C3 porte sur **quatre** surfaces et non
  trois ; chemin du livrable W1 sans préfixe.

---

---

## Seconde vague — les six GOAL restants, relus le 2026-08-27

La dette est levée : `Workflow({scriptPath: '…/verifier-prompts-lancement-wf_8d04bd06-459.js', resumeFromRunId:
'wf_8d04bd06-459'})` a rejoué les huit verdicts depuis le cache et exécuté les six manquants. **14/14 rendus,
0 erreur, 14 × CORRIGER.** Les constats ci-dessous ont été **re-vérifiés à la main** dans le code avant d'être
portés au protocole §14 — c'est la règle CLAUDE.md §3ter, et deux des trois « faits » les plus graves étaient des
erreurs de MA rédaction, pas du produit.

| GOAL | Constat le plus lourd | Vérification refaite à la main |
|---|---|---|
| ONB-09 | Les trois drapeaux d'animation sont à **faux** ; une offre activée sans câblage du `PricingService` serait « affichée mais jamais facturée » | `config/pos.php:271`, `config/kiosk.php:70`, `config/features.php:27` → défauts `false` ✔ ; `OfferController.php:31-36` cité mot pour mot ✔ |
| ONB-09 | **Collision de zone gelée** : `PricingService` revendiqué par ONB-03 (G-PRIX) *et* ONB-09 (G-PRIX-COUPON) | §5 du protocole attribue le fichier à ONB-03 ✔ — arbitrage par fiche avant W3 |
| ONB-10 | C1 ne demandait qu'**un** des trois chemins de révocation | `KioskMachineService.php` : `destroy():108`, `changeStatus():147`, `logout():176` ✔ |
| ONB-10 | « Désactive le bypass » sans mode d'emploi → la voie naïve (`APP_ENV=production`) **tue le serveur au boot** | `config/kiosk.php:211` et `:326` = `env('APP_ENV') === 'local'` ✔ ; garde `AppServiceProvider:190` ✔ |
| ONB-11 | Le prompt ordonnait des vagues d'écriture pendant une phase déclarée « lecture seule totale », et un chronomètre qui écrit en base | contradiction interne au prompt ✔ ; ligne ONB-11 du §4 = « lecture seule » ✔ |
| ONB-12 | Le pré-vol §3 (dump, chaîne fiscale, `/login` 200) est **impossible sur une base vide** avant `migrate` | §3 étapes 9-11 ✔ ; le GOAL prévoyait un filet différent (branche + inventaire figé) ✔ |
| ONB-12 | « Zéro Cayenne » est une assertion **négative** qu'une page 500 satisfait trivialement | contrôle positif + grep qu'on fait rougir exprès, imposé (CLAUDE.md §3ter) |
| ONB-13 | Le cliquet hérité était **62**, le réel est **64** → la cible ≤ 55 demande 9 suppressions, pas 7 | `FormRequestAuthzDriftSentinelTest.php:67` = `RETURN_TRUE_BASELINE = 64` ✔ |
| ONB-13 | `security-review` lancé au pré-vol relit un **diff vide** | déplacé sur le diff des corrections (vague B) |
| ONB-14 | La « garde d'identité » que le prompt demandait de modifier **n'existe pas à HEAD** | `tests/Playwright/global-setup.js` = **64 lignes**, `FOODKING_E2E_DEDICATED_DB` : **0 occurrence** ✔ |
| ONB-14 | Le jumeau PHP serait parti sur **sqlite `:memory:`** et n'aurait prouvé aucun trigger NF525 | `phpunit.xml:68-69` ✔ |
| ONB-14 | `php artisan foodking:installer` **n'est dans aucun commit** — c'est un livrable de ONB-12, donc **ONB-12 ne peut pas être différé** | `app/Console/Commands/` ne contient que `FiscalInstallImmutabilityTriggersCommand.php` ✔ |

### Ce que cette seconde vague apprend

Les six prompts non relus portaient des défauts **d'une autre nature** que les huit premiers. La première vague avait
trouvé des défauts d'**environnement** (assets, `public/storage`, base de test, `safe-test.sh`) — communs à tous. La
seconde a trouvé des défauts d'**énoncé** : des faits que j'avais écrits sans les vérifier (un fichier de 167 lignes
qui n'en fait que 64, une commande qui n'existe pas, un cliquet à 62 au lieu de 64), et des ordres impossibles
(un pré-vol de base peuplée sur une base vide, une lecture seule qui doit écrire, une convergence « P0 = 0 » pour une
session sans droit de corriger). **Trois des quatorze prompts m'auraient fait mesurer un instrument imaginaire.**

C'est exactement le piège que CLAUDE.md §3ter décrit — « ai-je prouvé que mon instrument mesure quelque chose ? » —
appliqué cette fois non pas à un test, mais **au texte de la mission elle-même**.

### Note factuelle sur G0

`CONSTITUTION.md` ne porte à ce jour **aucune trace de G0**, et `docs/gates/GATE_LOG.md` non plus. Lancé tel quel,
ONB-12 s'arrête à sa première ligne — **c'est voulu** : il ne doit pas exister d'installation générique tant que le
propriétaire n'a pas réécrit la phrase constitutionnelle.
