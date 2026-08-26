# AUDIT ADVERSE DES PROMPTS DE LANCEMENT — traçabilité (2026-08-26)

Quatorze agents adverses, un par GOAL, ont relu le prompt de lancement contre son GOAL, son rapport de mission et
**le code réel**. Consigne : trouver ce qui empêcherait la session d'aboutir — manques d'auto-suffisance, chemins qui
ne se résolvent pas, contradictions avec le GOAL. **Huit ont rendu leur verdict ; six ont été tués par la limite de
session** (ONB-09, 10, 11, 12, 13, 14) — c'est écrit dans leurs prompts et dans le §14 du protocole, ce n'est pas
caché. Verdict des huit : **8 × CORRIGER**, 0 × OK.

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

## Ce qui n'a pas été vérifié (dette assumée)

Les prompts **ONB-09, ONB-10, ONB-11, ONB-12, ONB-13 et ONB-14** n'ont pas été relus : les six agents sont morts sur
la limite de session. Leur prompt porte désormais un avertissement explicite (« Ce GOAL n'a PAS été relu par un
auditeur adverse : rigueur supplémentaire, signale tout écart entre le GOAL et le code réel avant de t'y fier »),
et les douze défauts systématiques du tableau ci-dessus leur sont **déjà** appliqués par le protocole.

Pour lever cette dette : relancer la vérification quand la limite sera réinitialisée —
`Workflow({scriptPath: '…/workflows/scripts/verifier-prompts-lancement-wf_8d04bd06-459.js', resumeFromRunId: 'wf_8d04bd06-459'})`
rejoue les huit résultats depuis le cache et n'exécute que les six manquants.
