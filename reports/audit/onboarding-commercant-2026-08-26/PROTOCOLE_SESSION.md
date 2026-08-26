# PROTOCOLE DE SESSION — programme « Onboarding commerçant »
## La loi commune aux 14 GOAL · version 2026-08-26 (corrigée par 8 audits adverses)

> **Tu viens de recevoir un prompt court. Ce fichier est le premier des trois qu'il t'ordonne de lire.**
> Tu le lis EN ENTIER, tu le mémorises, et tu l'appliques pendant toute la mission sans qu'on te le rappelle.
>
> **Priorité en cas de conflit** : ce protocole prime sur le GOAL et sur le rapport de mission pour tout ce qui
> touche à l'**instrument** (pré-vol, environnement, tests, ports, base de données, preuves, git). Le GOAL prime
> pour le **contenu métier** (tâches, critères d'acceptation, gates, périmètre). Le §14 liste les points précis où
> ce protocole corrige un GOAL : ils ont été vérifiés dans le code réel, ils ne se discutent pas.

---

# §1 — LE PROGRAMME EN DIX LIGNES

Le propriétaire veut que FoodKing soit livrable à un **nouvel établissement** qui règle **tout** depuis son
Dashboard, sans développeur. Quatorze GOAL couvrent l'identité, le catalogue, la personnalisation à règles de prix,
l'extraction de menu par IA, les réglages, l'équipe, les rapports, le stock, l'animation commerciale, l'équipement,
l'expérience, l'installation vierge, la sécurité, et la preuve de bout en bout.

Cela contredit `CONSTITUTION.md §1` (« logiciel personnel de Le Cayenne, PAS un SaaS »). La contradiction est
tranchée par le gate **G0**, qui appartient au propriétaire : **paramétrer** (sortir la marque et les règles du code
vers la donnée) **n'est pas** du multi-tenant (plusieurs établissements vivants dans une base) — le second reste V2.
Tant que G0 n'est pas écrit dans `CONSTITUTION.md`, aucune session ne remonte « multi-marque » comme P0/P1 bloquant
et aucune ne touche `CONSTITUTION.md`. Seuls ONB-12 et la clôture de ONB-14 sont bloqués par G0.

---

# §2 — CONDITION D'ENTRÉE (avant même le pré-vol)

Vérifie que les trois fichiers de ta mission existent dans l'arbre courant. **S'il en manque un, ARRÊTE** et dis :
« la branche du programme n'est pas fusionnée ». La commande est `git merge goal/onboarding-commercant-2026-08-26`
depuis l'arbre principal `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt`. Sans elle, ni le GOAL, ni
le rapport de mission, ni le dossier `reports/audit/onboarding-commercant-2026-08-26/recon/` n'existent — et un
worktree créé depuis HEAD ne les contiendra pas davantage.

---

# §3 — PRÉ-VOL (W0) — douze étapes, dans l'ordre, avant toute autre chose

Remplace `<nn>`, `<slug>` et `<PORT>` par les valeurs de ta ligne du §4. `MAIN` =
`/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt`.

1. **Worktree depuis HEAD** — `git worktree add .claude/worktrees/onb<nn>-<slug> -b goal/onb<nn>-<slug>-2026-08-26 HEAD`
   puis `EnterWorktree` sur ce chemin. ⛔ **Jamais** depuis `origin/main` : il a **2 485 commits de retard**, tu
   auditerais du code qui n'existe plus. (Le réglage `worktree.baseRef = fresh` de `~/.claude/settings.json` fait
   exactement cette erreur si tu utilises `EnterWorktree(name:)` — crée-le à la main.)
2. **`.env`** — `cp $MAIN/.env .env`, puis change **uniquement** `APP_URL=http://127.0.0.1:<PORT>`.
   `DB_DATABASE` reste `foodking_e2e` (base de travail partagée), sauf ONB-12 et ONB-14 (§4).
3. **`.env.testing`** — `cp $MAIN/.env.testing .env.testing`, puis change `DB_DATABASE=foodking_test_onb<nn>` et crée
   cette base vide. ⚠️ Le fichier d'origine porte `foodking_test`, **partagée par les 14 worktrees** : deux sessions
   qui lancent `RefreshDatabase` en même temps produisent des rouges fantômes que tu passeras des heures à chasser.
   ⚠️ `.env.testing` est *gitignoré* : sans lui, ~336 tests rouges qui n'existent pas.
4. **Dépendances en liens durs** — `rsync -a --link-dest=$MAIN/vendor/ $MAIN/vendor/ vendor/` et pareil pour
   `node_modules/` (ou `cp -al`). ⛔ **Jamais un symlink `vendor/`** : `__DIR__` résoudrait vers l'arbre principal et
   **ton code ne serait jamais exécuté** — une suite verte n'y prouverait rien.
5. **Preuve que le code exécuté est le tien** —
   `php artisan tinker --execute='echo (new ReflectionClass(<une classe de ta voie>))->getFileName();'`
   doit afficher un chemin **dans ton worktree**. Si ce n'est pas le cas, reprends l'étape 4.
6. **Assets (sinon toutes tes pages renvoient 500)** — `public/mix-manifest.json`, `public/css/*` et `public/js/*`
   sont **gitignorés** : un worktree neuf ne contient que `pos-wizard.js` et `version-beacon.js`, et
   `resources/views/master.blade.php` appelle `mix('css/app.css')` → *« Mix manifest not found »* sur **toutes** les
   pages. Copie-les depuis l'arbre principal (`cp $MAIN/public/mix-manifest.json public/` puis `cp -R $MAIN/public/css
   $MAIN/public/js public/`) **ou** construis-les (`npx mix`). Après **chaque** modification d'un `.vue` ou d'un
   `.css`, **reconstruis avant de lire une capture**, sinon tu juges l'ancien bundle.
7. **Médias** — `ln -s $MAIN/storage/app/public public/storage`. Sans ce lien, logos, favicons et images produit
   renvoient 404 et tu signaleras un faux défaut « le logo ne s'affiche pas ».
8. **Fichiers non commités dont tu as besoin** — l'arbre principal porte des dizaines de fichiers **modifiés ou non
   suivis** ; certains fichiers cités par ton GOAL **n'existent dans aucun commit** (`config/dashboard.php` en est un,
   vérifié). Ton worktree, créé depuis HEAD, ne les a pas. En W0 : pour chaque fichier de ta voie, vérifie son
   existence ; s'il manque, copie-le explicitement depuis l'arbre principal **et déclare cette copie** dans le
   journal §8 de ton rapport de mission (c'est une dette, pas un acquis).
9. **Serveur** — `php artisan serve --host=127.0.0.1 --port=<PORT>` puis `export PLAYWRIGHT_BASE_URL=http://127.0.0.1:<PORT>`.
   Vérifie `curl -o /dev/null -w '%{http_code}' http://127.0.0.1:<PORT>/login` → **200** (pas 500 : sinon reviens à
   l'étape 6).
10. **Filet** — `git branch backup/pre-onb<nn>-2026-08-26` (sans changer de branche) + `mysqldump` des tables que ta
    voie va toucher, dans `reports/audit/onboarding-commercant-2026-08-26/`.
11. **Bases chiffrées** — exécute les compteurs du §0.6 de ton GOAL **avant** toute modification et écris-les dans le
    journal §8 : nombre de tests par dossier, volumes en base, chaîne fiscale
    (`php artisan fiscal:verify-chain --all` → CHAIN OK, `count(*)` et `MAX(current_hash)` de `audit_logs`).
    Une vague qui dégrade un de ces chiffres est en échec.
12. **Déclaration** — écris dans le journal §8 : port, worktree, branche, base de travail, base de test, date, et la
    liste des fichiers copiés à l'étape 8.

---

# §4 — LA TABLE DES SESSIONS (ta ligne, et celles des autres)

| GOAL | Port | Worktree `onb<nn>-<slug>` | Préfixe DB | Base de travail | Reconnaissance à lire |
|---|---|---|---|---|---|
| ONB-01 Identité | 8801 | `onb01-identite` | `GOAL-ONB01` | `foodking_e2e` | `recon/Z2_profil_reglages.md` |
| ONB-02 Catalogue | 8802 | `onb02-catalogue` | `GOAL-ONB02` | `foodking_e2e` | `recon/Z1_catalogue_wizard.md`, `recon/Z0_modele_catalogue_wizard_reglages.md` §A-B |
| ONB-03 Wizard/prix | 8803 | `onb03-wizard` | `GOAL-ONB03` | `foodking_e2e` | `recon/Z0_modele_catalogue_wizard_reglages.md` §A.2-A.3, `recon/Z1_catalogue_wizard.md` |
| ONB-04 IA/assistant | 8804 | `onb04-assistant` | `GOAL-ONB04-<lot>` | `foodking_e2e` | `recon/Z0_carte_dashboard.md` §9, `recon/Z0_modele_catalogue_wizard_reglages.md` §F |
| ONB-05 Réglages | 8805 | `onb05-reglages` | `GOAL-ONB05` | `foodking_e2e` | `recon/Z2_profil_reglages.md`, `recon/Z7_equipement_ops.md` §3-4 |
| ONB-06 Équipe/rôles | 8806 | `onb06-equipe` | `GOAL-ONB06` | `foodking_e2e` | `recon/Z3_utilisateurs_rbac.md` |
| ONB-07 Rapports | 8807 | `onb07-rapports` | `GOAL-ONB07` | `foodking_e2e` | `recon/_ZONES.md` § Z4 (brief à exécuter) |
| ONB-08 Stock | 8808 | `onb08-stock` | `GOAL-ONB08` | `foodking_e2e` | `recon/_ZONES.md` § Z5 (brief à exécuter) |
| ONB-09 Animation | 8809 | `onb09-animation` | `GOAL-ONB09` | `foodking_e2e` | `recon/_ZONES.md` § Z6 (brief à exécuter) |
| ONB-10 Équipement | 8810 | `onb10-equipement` | `GOAL-ONB10` | `foodking_e2e` | `recon/Z7_equipement_ops.md` (intégral) |
| ONB-11 Expérience | 8811 | `onb11-ux` | *(lecture seule)* | `foodking_e2e` | `recon/_ZONES.md` § Z8 (brief à exécuter) |
| ONB-12 Installation | 8812 | `onb12-vierge` | — | **`foodking_onb12` dédiée, vide** | les §5 « Cayenne en dur » de Z1, Z2, Z3, Z7 |
| ONB-13 Sécurité | 8813 | `onb13-securite` | *(lecture + usines)* | `foodking_e2e` | les §3 « Constats » de Z1, Z2, Z3, Z7 |
| ONB-14 Convergence | 8814 | `onb14-convergence` | — | **`foodking_onb14` dédiée** | tous les `recon/`, tous les `MISSION_*` §8 |

**Tous les chemins `recon/…` de cette table sont préfixés par `reports/audit/onboarding-commercant-2026-08-26/`.**
Il n'existe **aucun** répertoire `recon/` à la racine : un chemin écrit sans ce préfixe ne se résout pas.

`:8766` = arbre principal (référence, jamais modifié par une session de GOAL). `:8000` = worktree périmé
`goal-caisse-vision-2026-08-24`, **15 356 lignes d'écart** : aucune session ne l'audite.
⚠️ `recon/_BRIEF_COMMUN.md` vise `:8766` parce qu'il décrit la reconnaissance du 26/08 : quand tu exécutes un brief,
**remplace la cible par TON port**.

---

# §5 — VOIES, PROPRIÉTÉ, COLLISIONS

**Règle** : tu ne modifies que les fichiers listés au **§0.2 de ton GOAL**. Tout autre fichier — même pour une
correction évidente — devient une **fiche de renvoi** écrite dans le §8 de ton rapport de mission, adressée au GOAL
propriétaire, au format :

```
[P1] ONB-10 resources/js/components/admin/settings/Printers/PrintersComponent.vue:129-133 — titre
  constat      : ce qui est faux, avec la preuve
  second moyen : la seconde preuve indépendante
  correctif    : la proposition de portée minimale
  statut       : émise le <date> → acceptée / refusée (motif) / corrigée (commit) → rejouée le <date>
```

**Propriétaires exclusifs** (aucune autre session n'y touche) :
- **ONB-05** : `resources/js/config/v1-hidden-modules.js`, `resources/js/components/admin/settings/MenuComponent.vue`,
  `resources/js/components/layouts/backend/BackendMenuComponent.vue` (visibilité du menu). Toute demande de dé-cachage
  ou de renommage d'entrée passe par une fiche à ONB-05.
- **ONB-03** : `app/Services/Pricing/PricingService.php`, et **seulement sous LOCK contresigné**.

**Collisions connues de la vague A — à trancher par fiche AVANT d'écrire** :

| Objet | Revendiqué par | Règle |
|---|---|---|
| `App\Enums\KdsStation` (à créer) | ONB-02 (`ItemRequest`) et ONB-10 (postes de cuisine) | le premier qui arrive le crée et envoie une fiche à l'autre ; jamais deux versions |
| `resources/js/components/admin/items/CatalogHubComponent.vue` | ONB-02 (onglet Catalogue) et ONB-08 (onglet Stock) | coordination écrite dans §8 avant toute édition |
| `config/dashboard.php` | ONB-05 (clés de réglage) et ONB-07 (fenêtre SLA) | **ONB-07 possède le fichier** ; ONB-05 l'expose comme réglage par fiche |
| `config/printing.php` | ONB-05 (clés) et ONB-10 (imprimantes) | **ONB-10 possède le fichier** ; ONB-05 expose par fiche |
| `resources/js/components/admin/observability/SystemHealthComponent.vue` | ONB-05 (interrupteurs) et ONB-10 (santé) | **ONB-10 possède l'écran** ; ONB-05 y branche sa page par fiche |
| `resources/js/languages/fr.json` | tout le monde | chacun n'ajoute que **ses** clés, dans le bloc de sa zone ; ONB-11 n'y écrit qu'en vague B |
| `routes/api.php`, `router/index.js`, `store/index.js`, `webpack.mix.js`, `DatabaseSeeder.php` | tout le monde | registres : déclare chaque ligne ajoutée dans le §8 ; si deux sessions ajoutent dans la même vague, la seconde rebase |

**Zones gelées** (`CLAUDE.md §7`) : `PricingService`, `OrderStateMachine`, `BranchScope`, `IdempotencyKeyMiddleware`,
les trois services `app/Services/Fiscal/*`, `PaymentComponent.vue`, `PosV5TrancheRow.vue`, le trio kiosk
(`KioskWizard`/`KioskApp`/`KioskUpsell`), et **strictement intouchables** : `public/js/pos-wizard.js`,
`public/css/pos-wizard.css`, `resources/views/admin-pos-v4.blade.php`. Toute ligne = `lock-plan` + contreseing.

---

# §6 — BASE DE DONNÉES ET TESTS

**Base de travail partagée.** `foodking_e2e` est utilisée en même temps par jusqu'à huit sessions.
- Préfixe **obligatoire** `GOAL-ONB<nn>` sur toute entité que tu crées.
- Nettoyage **définitif** en fin de vague : `forceDelete` (+ `model_has_roles`, `personal_access_tokens`, médias) et
  **preuve en base** (`SELECT COUNT(*) … LIKE 'GOAL-ONB<nn>%'` = 0). Un enregistrement seulement *soft*-supprimé
  garde ses clés uniques (slug, e-mail) et fait échouer la vague suivante.
- Réglages : note la valeur **avant**, restaure-la **après**, vérifie en base. Ne laisse jamais un interrupteur à une
  valeur d'essai.
- ⛔ `migrate:fresh`, `db:seed`, `db:wipe`, `MenuTruncateTableSeeder`, `menu:reset-le-cayenne` — **jamais** sur une base
  existante. (ONB-12 et ONB-14 travaillent sur une base dédiée et y sont autorisés.)
- ⛔ **Aucune commande, aucune session de caisse, aucun Z** créés « pour avoir des données » : ce sont des écritures
  fiscales NF525 irréversibles sur une base partagée. Utilise les usines de tests. Exceptions déclarées : ONB-14 (base
  dédiée) et l'étape « commande test » de ONB-12 (gate).

**Tests — le piège de l'outil.** `~/.claude/skills/brain/scripts/safe-test.sh` contient
`REPO="/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt"` puis `cd "$REPO"` : **lancé depuis ton
worktree, il exécute les tests de l'arbre principal** et ne verra jamais ceux que tu écris. Il n'est pas non plus dans
le PATH. Donc :
1. `bash ~/.claude/skills/brain/scripts/safe-test.sh --check` → garde de sécurité (vérifie que `.env.testing` ne
   pointe pas sur une base opérationnelle). **Toujours** avant la première exécution.
2. Puis, **depuis ton worktree** : `vendor/bin/phpunit --filter="<le filtre de ton GOAL>"`.
3. Prouve, en citant le chemin d'un test joué, qu'il vient bien de **ton** arbre.
⛔ `php artisan test` nu : il peut migrer la base des autres sessions.

**Vitest** : `npx vitest run <chemins>` depuis ton worktree (node_modules lié à l'étape 4).

---

# §7 — DISCIPLINE D'EXÉCUTION

**Boucle par tâche** (`ultra-audit-profond`, non redécrite ici) :
1. **Cinq spécialistes en lecture seule, dépêchés dans UN SEUL message** (fan-out parallèle) : Architecte, Sécurité,
   UX/A11y, **Psychologie commerçant**, DBA ou SRE selon la matrice §A de ton GOAL. Aucun n'écrit.
2. Synthèse depuis leurs rapports écrits sur disque
   (`reports/test-e2e/ONB<nn>_<SLUG>/<round>/wave-<W>-<rôle>.json`), jamais depuis la mémoire de la conversation.
3. **TDD** : le test rouge d'abord, nommé, qui échoue pour la bonne raison.
4. **Un seul implémenteur à la fois.** Jamais deux en parallèle (conflit d'écriture).
5. **ROUGE** : un agent adverse dont le seul but est de **réfuter** le correctif. Il passe **après** l'implémentation
   et **avant** tout « c'est fini ».
6. **QA visuel + ROUGE visuel** en parallèle si la tâche touche une interface : le second **conteste** les captures du
   premier, indépendamment.
7. **Jalonneur** en fin de vague : il applique les **six points de contrôle**, et un seul « non » suffit à refuser la
   vague —
   1. toutes les tâches de la vague PASSENT, ou échouent avec un motif **écrit** et assumé ;
   2. diff des zones gelées sur la plage de la vague = **0 ligne** (`git diff --stat` sur la liste `CLAUDE.md §7`) ;
   3. chaîne NF525 inchangée ou **en ajout seul** si la vague a touché au fiscal ;
   4. barrière visuelle déclenchée pour toute tâche d'interface : captures **lues et analysées**, pas seulement prises ;
   5. contestation ROUGE faite ; tout P0/P1 nouveau soigné ou différé **avec motif écrit** ;
   6. `PROJECT_BRAIN.md §2/§3` et le journal §8 du rapport de mission à jour, commits nommés.
8. **Trois boucles de soin maximum** sur le même amas de problèmes. À la troisième, tu **STOPPES**, tu écris
   `reports/test-e2e/ONB<nn>_<SLUG>/STUCK_<vague>_<horodatage>.md` avec l'analyse de cause, et tu remontes quatre
   options : accepter en documentant · pivot d'architecture · différer · gate humain. **Tu ne choisis pas à ma place.**

**Interruption** (limite d'usage, fin de session) : commit `wip(<vague>): partiel jusqu'à T-x.y.z` avec les fichiers
nommés, manifeste `INTERRUPT_<vague>_<horodatage>.md` (dernier commit vert, tâche en cours, tâche suivante), mise à
jour de `PROJECT_BRAIN.md §2`. À la reprise : relire le manifeste, `git status`, rejouer la dernière tâche en fumée.

**Matrice des scénarios adverses (§S de ton GOAL) — obligatoire, pas optionnelle.** Pour chaque fonction livrée :
annulation à mi-chemin · rechargement pendant l'enregistrement · double soumission · deux onglets · rôle inférieur en
appel API **direct** · données vides · volume (×100) · réseau ou worker coupé · effet sur borne / caisse / KDS /
ticket / rapports · retour arrière · valeurs limites (0, négatif, 255+, accents, emoji, injection). Chaque case = un
test nommé ou un « N/A » **motivé**.

---

# §8 — TEST RÉEL SUR LE WEB

- **Navigateur réel sur TON port**, jamais `:8766`, jamais `:8000`. Une seule page à la fois : `php artisan serve`
  sert **une requête à la fois** ; deux navigateurs en parallèle produisent des blocages que tu prendrais pour des
  défauts. Timeouts 60 s, `waitUntil:'domcontentloaded'` puis attentes explicites.
- **Chaque capture est LUE** (outil Read) et analysée. Une capture prise et non regardée ne prouve rien.
- **Chaque capture est accompagnée de la console et du réseau** (messages d'erreur, réponses ≥ 400). Une capture sans
  console ni réseau ne vaut pas preuve. Une erreur console = rejet de la tâche.
- **Tout constat P0/P1 exige DEUX moyens indépendants** (DOM + API, ou capture + réseau, ou API + SQL) **et** une
  étape de reproduction exacte. Sinon il n'est pas écrit.
- **Playwright** : `PLAYWRIGHT_BASE_URL=http://127.0.0.1:<PORT>` suffit. ⛔ **Ne mets JAMAIS `E2E_BACKEND_AVAILABLE=1`**
  sans `FOODKING_E2E_DEDICATED_DB=1` : `tests/Playwright/global-setup.js` exécute alors `foodking:ensure-admin`
  (il réécrit le mot de passe admin et ressuscite un compte supprimé), cinq seeders et un `permission:cache-reset`
  **sur la base partagée** — ce qui déconnecte les autres sessions de la vague. Prépare tes données toi-même.
- **Preuve par le contenu, jamais par le code de retour** : « déployé » ne se prouve pas par un `git push`,
  « imprimé » ne se prouve pas par un 202 (`PRINTING_BYPASS_MODE=true` en local répond « ok » vers un hôte
  inexistant : utilise un récepteur `nc -l 9100` et compte les octets), « la page marche » ne se prouve pas par un 200,
  « le prix est bon » ne se prouve pas par l'affichage mais par le devis backend.

**Pièges d'instrument déjà payés par ce projet** (`CLAUDE.md §3ter`, `docs/PLAYWRIGHT_MCP_OPS.md §7`) :
`test.use({reducedMotion})` est **inerte** → `page.emulateMedia()` · `keyboard.press('F1'..'F12')` est **inerte** en
headless → test de composant · chercher un produit **absent du menu** ne prouve rien sur la recherche
(`SELECT name FROM items WHERE deleted_at IS NULL AND status = 5` d'abord) · un widget à 0 € peut être un **faux-vide**
(regarde la requête réseau) · le KDS rend la **V2** par défaut : `data-kds-order-card` (V1) est un sélecteur **mort** ·
**23 sélecteurs** cherchés par des specs ne sont posés par aucun fichier produit : n'invente jamais un `data-testid`,
utilise l'existant ou demande-le par fiche · sur cinq relevés d'un audit précédent, le premier chiffre a été faux
**cinq fois** : seul le **test négatif** (casser exprès pour voir le test rougir) prouve qu'une sentinelle mord.

---

# §9 — CONVERGENCE ET RÈGLES DE REJET

**Convergence = deux cycles consécutifs avec P0+P1 = 0 ET ensembles de constats IDENTIQUES.** Un seul cycle vert ne
vaut rien (garde anti-instabilité) ; deux cycles aux constats différents = on reboucle.

Rejet immédiat de la tâche ou de la vague, sans discussion :
étiquette brute à l'écran (`kiosk.X`, `label.foo`, `0undefined`) · erreur console · casse de mise en page sur un
gabarit testé · une ligne de diff en zone gelée · un P0 non traité · un test rouge non documenté · une acceptation qui
ne nomme pas un chemin de test · un message technique visible (`SQLSTATE`, trace, HTML PHP) · un message en anglais
face à l'utilisateur · « ça marche presque » / « c'est suffisant » · chaîne NF525 modifiée autrement qu'en ajout.

En plus, les critères chiffrés **C1..Cn du §0.5 de ton GOAL** doivent être VRAIS, **mesurés**, et écrits dans le
journal §8. « Production-parfait, ou bloqué » : il n'y a pas d'état intermédiaire livrable.

---

# §10 — GATES (décisions du propriétaire)

**Tu proposes, tu ne tranches jamais.** Un gate ne peut être approuvé ni par un agent, ni par un test.

**Granularité** : un gate en attente bloque **la tâche** qu'il conditionne, **pas** la vague entière, **pas** la
session. Tu continues tout ce qui n'en dépend pas, tu écris la proposition (options chiffrées + recommandation) dans
le §6 du rapport de mission, et tu signales le blocage dans ton compte rendu. **Tu ne t'arrêtes jamais complètement
parce qu'un gate est ouvert** — sauf les deux cas nommés : ONB-12 sans G0, ONB-14 sans la clôture de la vague C.

**G0** (constitution) est porté par l'index et par le propriétaire seul.

---

# §11 — GIT ET MÉMOIRE

- Fichiers **nommés un par un**. ⛔ `git add .`, ⛔ `git add -A` (risque de secrets, `CLAUDE.md §3quater`).
- Un commit par vague, message en français qui dit ce qui est **prouvé**, pas ce qui est tenté.
- ⛔ **Jamais** `git push`, jamais `--force`, jamais `--no-verify`. La poussée est un gate propriétaire.
- ⛔ Ne commite jamais `.env*` (sauf `.env.example`), une clé, un dump de base, un fichier généré par un export.
- À chaque fin de vague : `PROJECT_BRAIN.md` §2 (état) et §3 (ce qui a été fait) + **journal §8 de ton rapport de
  mission** (date, vague, tâche, action, preuve, verdict, commit). Le journal est ta mémoire : une session qui reprend
  après une coupure lit le journal, pas la conversation.
- Les fiches de renvoi émises et reçues vivent aussi dans le §8.

---

# §12 — COMPTE RENDU (à chaque fin de vague et en fin de mission)

Trois sections, rien d'autre :
- **FIXÉ** — une ligne par correctif, en français, ce que le commerçant peut faire maintenant qu'il ne pouvait pas.
- **VÉRIFIÉ** — les comptes de tests, et **comment** c'est prouvé (test nommé, capture lue, requête SQL, octets reçus).
- **BLOQUÉ** — ce qu'il faut du propriétaire, en une phrase par gate.

⛔ Pas de journal brut, pas de diff fichier par fichier, pas de récit d'étapes. Le détail vit dans le §8 du rapport de
mission ; le compte rendu est fait pour être lu en trente secondes.

---

# §13 — INTERDITS ABSOLUS (la liste unique)

1. Créer le worktree depuis `origin/main`. 2. Symlinker `vendor/`. 3. `migrate:fresh` / `db:seed` / `db:wipe` sur une
base existante. 4. `php artisan test` nu. 5. Créer une commande, une session de caisse ou un Z sur la base partagée.
6. Toucher une zone gelée sans LOCK contresigné. 7. Éditer un fichier hors de ta voie (fiche de renvoi à la place).
8. `E2E_BACKEND_AVAILABLE=1` sans base dédiée. 9. Approuver un gate à la place du propriétaire. 10. `git push`,
`--force`, `--no-verify`, `git add .`/`-A`. 11. Déclarer vert ce qui n'a pas été mesuré, ou une capture non lue.
12. Inventer un produit, une route, un sélecteur ou un chiffre — la table `items` est la seule source des produits.
13. Supprimer une donnée, un seeder ou une commande existants (déplacer, jamais supprimer). 14. Auditer `:8766` ou
`:8000` depuis une session de GOAL.

---

# §14 — CORRECTIONS DU 2026-08-26 QUI PRIMENT SUR LES GOAL

Huit audits adverses ont relu les prompts et les GOAL contre le code réel. Ce qui suit est **vérifié** et corrige le
GOAL concerné. (Les six autres GOAL — ONB-09 à ONB-14 — n'ont pas pu être relus : la limite de session a tué les
agents. Applique-leur les règles générales de ce protocole avec la même rigueur, et signale tout écart.)

**Toutes sessions**
- Les chemins `recon/…` cités dans les GOAL sans préfixe se lisent
  `reports/audit/onboarding-commercant-2026-08-26/recon/…`.
- `safe-test.sh` mesure l'arbre principal : voir §6.
- Un worktree neuf n'a ni assets, ni `public/storage` : voir §3, étapes 6 et 7.
- `.env.testing` doit pointer une base par session : voir §3, étape 3.
- Les scripts de reconnaissance du 26/08 sont dans `/Users/1millnonstop/.claude/jobs/06c6b42a/tmp/recon/Z<n>/`
  (dossier de travail, pas dans le dépôt) : s'ils ont disparu, reconstruis-les depuis le rapport `recon/Z<n>_*.md`.

**ONB-01** — Termine **toute** la W1 avant W2 : la cartographie « champ d'identité → surface » (T-1.1.1) et
l'inventaire des écritures `.env` (T-4.1.2) en font partie, et l'acceptation de Sub 1.1 en dépend. **G-ID ne bloque
que T-1.3.2** (la forme de l'écran composite) : T-1.1.\*, T-1.2.\* et le reste de W2 avancent sans lui.

**ONB-02** — Ta voie inclut aussi `app/Services/{Item,ItemVariation,ItemExtra,ItemAddon,ItemAttribute,ItemCategory,
ItemCategoryHierarchy}Service.php`, `app/Models/{Item,ItemVariation,ItemExtra,ItemAddon}.php`,
`database/seeders/TaxTableSeeder.php` et `resources/js/router/modules/itemRoutes.js`. Atteste la chaîne NF525 avant de
commencer **et après W3** (tu archives des taxes). Collisions : `KdsStation` avec ONB-10, `CatalogHubComponent.vue`
avec ONB-08 (§5).

**ONB-03** — Ne crée ton worktree que depuis un HEAD qui contient ONB-02 (le GOAL le dit : « W0 bloquée par ONB-02
stabilisé »). Ta voie inclut aussi `admin/demo/WizardAdvancedLauncherComponent.vue`,
`resources/js/router/modules/itemRoutes.js`, `app/Models/Scopes/WizardProfileBranchScope.php` et le bloc
`label.composer_*` de `fr.json`. Le LOCK G-PRIX porte sur `PricingService.php` **et** sur ce que W5 exige de
`config/menu.php` / `config/kiosk.php` : borne-le explicitement dans le LOCK. ⛔ Ne passe **jamais** de commande pour
vérifier un prix : le devis suffit.

**ONB-04** — Le binding mock/réel se fait sur **`assistant.enabled`** (ton `config/assistant.php`), pas seulement sur
`OPENAI_VISION_ENABLED` : mets les deux à faux et **prouve par un test** que le conteneur résout
`MockMenuExtractionService`. **G-DATA bloque W2 ET W3** (les deux migrations). Ta voie exclut explicitement les trois
fichiers de menu (ONB-05).

**ONB-05** — `config/dashboard.php` appartient à **ONB-07** ; `config/printing.php` et `SystemHealthComponent.vue`
appartiennent à **ONB-10** : passe par des fiches. Ton GOAL t'accorde aussi les clés de `config/printing.php` : c'est
une erreur, elle est corrigée ici. **G-CACHE bloque W4**, pas le reste. La reconnaissance Z2 a été faite sur
« HEAD + non commité » : en W1, vérifie dans ton worktree l'existence de chaque fichier et de chaque ligne cités.

**ONB-06** — Nuance sur l'interdit : tu ne modifies jamais les **permissions en base** d'un rôle seedé pendant tes
essais ; en revanche le **renommage de « Stuff »** et la création des rôles socle sont des tâches légitimes (T-2.1.3),
via seeder, **après G-ROLES**. ⛔ N'exécute jamais `db:seed --class=PermissionTableSeeder` (ni les seeders de rôles)
sur la base partagée : ils réécrivent les permissions des huit sessions.

**ONB-07** — ⚠️ **Le plus important** : `config/dashboard.php`, `tests/Feature/Dashboard/SlaAlertesBorneBasseTest.php`
et le correctif SLA de `DashboardService` **ne sont dans aucun commit** — ils n'existent que dans l'arbre principal non
commité. Ton worktree depuis HEAD ne les aura pas. En W0 : copie-les explicitement et déclare-les (§3, étape 8), sinon
tu mesureras un tableau de bord qui n'est pas celui du propriétaire. Le filtre de tests de W6 doit inclure `Fiscal`
(tu crées la page Rapport X). Ta W2 inclut la fusion des dossiers `tests/Feature/Report/` → `Reports/`.

**ONB-08** — **W5 (le hub à trois onglets) est bloquée par G-HUB-STOCK** : ne la commence pas sans la décision. Le
critère C3 porte sur **quatre** surfaces (borne, caisse, KDS, projection web), pas trois. Le livrable de W1 s'écrit
dans `reports/audit/onboarding-commercant-2026-08-26/recon/Z5_stock_ingredients.md`, captures dans `…/recon/screens/Z5/`.

---

# §15 — CHECKLIST DE DÉMARRAGE (recopie-la et coche-la dans ton journal §8)

- [ ] Les trois fichiers de ma mission existent (sinon : fusionner la branche, §2)
- [ ] Protocole lu en entier · GOAL lu en entier · rapport de mission lu en entier
- [ ] Worktree créé **depuis HEAD** · `.env` au bon port · `.env.testing` sur ma propre base de test
- [ ] `vendor/` et `node_modules/` en liens durs · `ReflectionClass` pointe dans mon worktree
- [ ] Assets présents · `/login` renvoie **200** · `public/storage` lié
- [ ] Fichiers non commités dont j'ai besoin : copiés et déclarés
- [ ] Filet posé (branche + dump) · bases chiffrées figées · chaîne NF525 attestée
- [ ] Je peux citer sans relire : mon port, ma voie, mes cinq interdits, la règle de convergence, la règle des gates
- [ ] Premier geste identifié (W1 de mon GOAL) et lancé
