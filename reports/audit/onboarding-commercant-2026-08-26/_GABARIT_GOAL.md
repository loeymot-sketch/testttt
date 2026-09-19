# GABARIT OBLIGATOIRE — GOAL « Onboarding commerçant » (2026-08-26)

> Ce gabarit est la LOI des rédacteurs. Un GOAL qui s'en écarte est rejeté par la contestation ROUGE.
> Il condense la compétence `ultra-architect-planify` (Axes 1-10) + les règles FoodKing (CLAUDE.md, CONSTITUTION,
> PARALLEL_PROTOCOL) + les exigences du propriétaire du 2026-08-26 : « chaque objectif ultra-riche, discipliné,
> boucle jusqu'à validation complète, scénarios au-delà du premier degré (annulation, effets indirects, retour
> arrière), à la place du commerçant, agents adverses / de jalonnement / spécialisés (sécurité, UI, UX, psychologie) ».

## Contraintes de forme (mesurées par le relecteur)
- Fichier : `plans/GOAL_ONB<nn>_<SLUG>_2026-08-26.md` · **30-40 Ko** (`wc -c`), rejet au-delà de 45 Ko, rejet sous 24 Ko.
- Langue : français, accents corrects, aucune phrase anglaise user-facing. Tables Markdown, pas de prose molle.
- **ANCRAGE-D'ABORD** : chaque chemin cité provient d'un `find`/`grep`/`ls`/`Read` réellement exécuté sur l'arbre
  principal `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt` (HEAD `43b120c7d`), ou porte `(À CRÉER)`.
  La §1 reproduit la SORTIE BRUTE des commandes d'ancrage (comptes de fichiers, lignes clés).
- **Chaque acceptation nomme un chemin de test** existant (vérifié `ls`) ou `(À CRÉER à <chemin>)`. « Les tests passent » = rejet.
- Aucune zone gelée (CLAUDE.md §7) modifiée sans `lock-plan` + gate. Liste canonique : `memory/reference_frozen_zones.md`.
- Aucun produit inventé (SSOT = table `items`), aucune palette inventée, aucune route devinée.

## Structure imposée (titres exacts, dans cet ordre)

```
# GOAL — ONB-<nn> <TITRE EN MAJUSCULES>
## FoodKing — Onboarding commerçant · <sous-titre : le problème en une ligne>

- **Slug** : `ONB<nn>_<SLUG>_20260826` · **Auteur** : Claude Code (chef de projet + rédacteur) · **Date** : 2026-08-26
- **HEAD** : `43b120c7d` · **Branche de base** : `pos/category-first-caisse-2026-06-23`
- **Voie SYSTEM_MAP** : <BORNE|CAISSE|KDS+OSS|WEB+APP|CENTRAL|TRANSVERSE (lecture-seule puis sérialisé)>
- **Index parent** : `plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md` · **Rapport de mission** : `reports/audit/onboarding-commercant-2026-08-26/MISSION_ONB<nn>_<SLUG>.md`
- **Persona** : <le commerçant-type de ce GOAL, une ligne>

> **En cinq lignes** : le problème réel · la preuve qu'il existe (mesure du 2026-08-26) · ce que « FINI » veut dire
> (chiffres) · ce que ce GOAL ne fait PAS · le premier geste de la session.

# §0 — PRÉAMBULE
## §0.1 — Décision arbre de travail + PRÉ-VOL DE SESSION
## §0.2 — Périmètre : DANS / HORS / voisins
## §0.3 — Drapeaux d'expansion de périmètre
## §0.4 — Pipeline par tâche (référence unique)
## §0.5 — Critères de convergence et règles de rejet
## §0.6 — Base de référence héritée (chiffres à ne pas dégrader)
## §0.7 — Contradictions détectées et tranchées (CLAUDE.md §12)
## §0.8 — Le commerçant-type et ses questions
# §1 — CARTE DU SYSTÈME (ancrages vérifiés — sortie brute)
# §2 — ÉTAT MESURÉ LE 2026-08-26 (ce qui marche · constats · angles morts)
# §3 — SOUS-SYSTÈME 1 : <nom>
## Sub 1.1 — <nom>  (Ancrages / Tâches / Acceptation)
## Sub 1.2 — …
# §4 — SOUS-SYSTÈME 2 : <nom>
# §5 — SOUS-SYSTÈME 3 : <nom>
# (§6 — SOUS-SYSTÈME 4 : optionnel)
# §S — SCÉNARIOS ADVERSES OBLIGATOIRES (au-delà du premier degré)
# §A — ARMÉE D'AGENTS : spécialistes, jalonnement, disputes
# §X — VAGUES DE CONVERGENCE (+ §X.8 point de contrôle · §X.9 échec de convergence · §X.10 interruption)
# §G — GATES PROPRIÉTAIRE (QUI / QUOI / OÙ)
# §R — RÉFÉRENCES
# §F — RÈGLE FINALE
```

## Contenu attendu par section

### §0.1 Décision arbre de travail + PRÉ-VOL DE SESSION (copier tel quel, puis compléter)
- Décision : **worktree dédié** `.claude/worktrees/onb<nn>-<slug>` sur branche `goal/onb<nn>-<slug>-2026-08-26`,
  créé DEPUIS HEAD (`git worktree add … HEAD`), jamais depuis `origin/main` (2 485 commits de retard : un worktree
  « fresh » auditerait du code qui n'existe plus — PARALLEL_PROTOCOL règle 7).
- Pré-vol obligatoire du worktree : copier `.env` et changer `APP_URL` vers le **port attribué par l'index** ;
  copier `.env.testing` (ignoré par git : sans lui ~336 rouges fantômes) ; `vendor/` et `node_modules/` par **liens
  durs** (`rsync -a --link-dest`) — ⛔ **jamais de symlink `vendor/`** (`__DIR__` résout vers l'autre arbre : les
  modifications du worktree ne sont jamais exécutées) ; vérifier par `ReflectionClass::getFileName()` ;
  `php artisan serve --host=127.0.0.1 --port=<port>` ; `PLAYWRIGHT_BASE_URL=http://127.0.0.1:<port>`.
- Base de données **partagée** (`foodking_e2e`) entre sessions parallèles : préfixe obligatoire `GOAL-ONB<nn>` sur
  toute entité créée ; ⛔ jamais `migrate:fresh`, jamais `php artisan test` nu — uniquement
  `bash ~/.claude/skills/brain/scripts/safe-test.sh --phpunit "<Filtre>"`.
- Filet : `git branch backup/pre-onb<nn>-2026-08-26` + dump SQL avant la première écriture.
- Git : jamais `git add .`/`-A`, fichiers nommés un par un ; jamais push/force/`--no-verify` ; commit par vague.

### §0.2 Périmètre
Table DANS (sous-systèmes) · table HORS (déclarée pour ne pas l'oublier, avec le GOAL voisin qui la porte) ·
**fichiers POSSÉDÉS** (voie) · **fichiers INTERDITS** (autres voies, zones gelées) · **zones à coordonner**
(`routes/api.php`, `router/index.js`, `store/index.js`, `fr.json`, `BackendMenuComponent.vue`, `MenuComponent.vue`,
`v1-hidden-modules.js`, `DatabaseSeeder.php` — append-coordination : déclarer chaque ligne ajoutée dans le rapport).

### §0.3 Drapeaux d'expansion
SCOPE-1 zone gelée · SCOPE-2 3 boucles de soin · SCOPE-3 migration de schéma non prévue · SCOPE-4 NF525 hors ajout ·
SCOPE-5 franchissement de voie (fichier d'un autre GOAL) → STOP + remontée.

### §0.4 Pipeline
Chaque tâche via `ultra-audit-profond` ; zone gelée → `lock-plan` ; page → `test-e2e` ; constats → `verify-before-report` ;
TDD rouge d'abord ; `systematic-debugging` avant tout correctif. Ne pas redécrire.

### §0.5 Convergence
Table des déclencheurs de rejet (étiquette brute, casse de mise en page, erreur console, diff gelé, P0 non traité,
test rouge non documenté, acceptation sans chemin, « ça marche presque », NF525 hors ajout, deux cycles différents) +
**Convergence = deux cycles consécutifs avec P0+P1 = 0 ET ensembles de constats identiques**. Ajouter 3 à 6
**critères chiffrés propres au GOAL** (C1..Cn : mesure, seuil, commande de re-mesure).

### §0.6 Base héritée
PHPUnit **5 194 passés** (2026-08-25, ⚠️ 4 862 la veille : écart non expliqué, ne pas l'inventer) · Vitest **445 fichiers /
3 644 passés / 0 échec** · zone gelée **0 ligne** · NF525 `audit_logs` 8 119 en ajout seul, `z_reports` 33 ·
FormRequest `RETURN_TRUE_BASELINE = 62` · + les compteurs propres à la zone (table `items` 59 actifs, etc.).

### §0.7 Contradictions
Au minimum : **C-CONST** — `CONSTITUTION.md §1` dit « logiciel PERSONNEL de Le Cayenne, PAS un SaaS » ; le propriétaire
demande le 2026-08-26 un logiciel **livrable à un nouvel établissement, configurable à 100 % sans développeur**.
Résolution retenue par l'index : **paramétrer (sortir la marque du code vers la donnée) ≠ multi-tenant** ; ce GOAL fait
le premier, jamais le second ; la réécriture de la phrase constitutionnelle est le gate **G0** de l'index, tranché par le
propriétaire, pas par un agent. Ajouter les contradictions propres à la zone (doc vs code, réglage annoncé vs absent).

### §0.8 Commerçant-type
Persona (ex. « Nadia, kebab-burger à Lyon, 1 caisse, 1 borne, 2 cuisiniers, pas de service informatique ») et ses
5 questions concrètes auxquelles le GOAL doit répondre par OUI prouvé.

### §1 Carte
Table : sous-système · maturité mesurée · ancrage réel (fichiers) · tests existants (comptes réels) + bloc « sortie
d'ancrage brute ».

### §2 État mesuré
Reprendre le rapport de mission : CE QUI MARCHE (preuves), CONSTATS P0→P3 (résumé, renvoi aux captures), ANGLES
MORTS, « CAYENNE » EN DUR. Ne pas recopier les 1 500 mots : 20-40 lignes denses + renvoi.

### §3..§6 Sous-systèmes — forme littérale
```
## Sub x.y — <nom>
**Ancrages** : <fichier:ligne> · <fichier:ligne>
**Hérité (déjà vert, ne pas refaire)** : …
**Tâches**
- **T-x.y.1** — <verbe d'action> <quoi> — <condition de succès mesurable>
  • ancrage : <fichier:ligne>
  • test : <chemin existant> OU (À CRÉER à <chemin>)
  • visuel : http://127.0.0.1:<port>/<route> (si frontend)
  • au-delà du premier degré : <annulation | rechargement | double-clic | deux onglets | rôle inférieur | effet borne/caisse/KDS | retour arrière>
- **T-x.y.2** — …
**Acceptation** : <tests nommés VERTS> · <critère chiffré> · <captures lues> · <zéro étiquette brute / erreur console>
```
3 à 5 tâches par sous-système, 3 à 4 sous-systèmes. Toute tâche qui exige une décision propriétaire renvoie à §G.

### §S Scénarios adverses obligatoires (matrice)
Lignes = fonctionnalités du GOAL ; colonnes = annulation à mi-chemin · rechargement pendant l'enregistrement ·
double soumission · deux onglets/deux caissiers · rôle inférieur (API directe) · données vides · volume (×100) ·
réseau coupé/worker arrêté · effet sur borne / caisse / KDS / ticket · retour arrière (suppression, dé-publication,
restauration) · valeur limite (0, négatif, 255+, accents, emoji, injection). Chaque case = test nommé ou « N/A motivé ».

### §A Armée d'agents
Rôles : Architecte · Sécurité · UX/A11y · **Psychologie commerçant** (charge cognitive, vocabulaire, peur de casser,
confiance dans les chiffres) · DBA · SRE/Synchro · Implémenteur (jamais deux en parallèle) · ROUGE (réfute) ·
QA visuel · ROUGE visuel · **Jalonneur** (à chaque point de contrôle : relit les 6 points, refuse la vague si un
« non »). Matrice de déclenchement par type de tâche. Discipline : 5 spécialistes lecture seule en UN message ;
contestation ROUGE après implémentation, avant tout « fini » ; chaque agent écrit sur disque
`reports/test-e2e/ONB<nn>_<SLUG>/<round>/wave-<W>-<rôle>.json` ; contrat de constat `[P0..P3] file:line — titre /
reproduction / preuve / recommandation` ; P0/P1 sans file:line + reproduction = rejeté.

### §X Vagues
W0 pré-vol (filet, bases, gates) → W1 reconnaissance ciblée (rejouer §2, mesurer) → W2..Wn-1 sous-systèmes →
Wn convergence (deux cycles identiques, suite complète via le garde DB, diff gelé 0, NF525, BRAIN §2/§3).
Table vague · portée · parallélisme · bloquée par. §X.8 point de contrôle 6 points ; §X.9 échec de convergence
(STOP, analyse, `STUCK_*.md`, 4 options, attendre) ; §X.10 interruption (`wip(<vague>)`, `INTERRUPT_*.md`, BRAIN).

### §G Gates
Table Gate · Description · QUI · QUOI · OÙ · Statut. Toujours **G0** (amendement constitutionnel, porté par l'index) ;
les gates propres (choix produit, zone gelée, migration, dépense API IA, données réelles).

### §R Références
Compétences, mémoire projet, docs, rapports amont (dont les fichiers `recon/Z*.md` et le rapport de mission).

### §F Règle finale
Liste numérotée « TERMINÉ quand et seulement quand » (vagues closes, tests ≥ base, critères C1..Cn, diff gelé 0,
NF525 ajout seul, gates tranchés, BRAIN vrai, deux cycles identiques) + « Interdit de bout en bout » + une phrase
de sens du point de vue du commerçant.
