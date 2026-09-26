# GOAL — ONB-11 EXPÉRIENCE COMMERÇANT TRANSVERSE
## FoodKing — Onboarding commerçant · une charte des motifs, un vocabulaire de commerçant, une accessibilité mesurée et une psychologie de la première heure — la « conscience UX » des treize autres GOAL

- **Slug** : `ONB11_EXPERIENCE_COMMERCANT_TRANSVERSE_20260826` · **Auteur** : Claude Code (chef de projet + rédacteur) · **Date** : 2026-08-26
- **Voie SYSTEM_MAP** : **TRANSVERSE** — audit lecture seule en parallèle de tout le programme ; corrections uniquement dans `layouts/backend/**`, `admin/components/**` (§6 partagé, sérialisé), `resources/css/app.css`, `fr.json` (blocs `label`/`menu`) ; **tout le reste est renvoyé** au GOAL propriétaire par fiche
- **HEAD** : `43b120c7d` · **Index parent** : `plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md` · **Rapport de mission** : `reports/audit/onboarding-commercant-2026-08-26/MISSION_ONB11_EXPERIENCE_COMMERCANT_TRANSVERSE.md`
- **Port de session** : **8811** · **Persona** : Nadia, première heure devant le Dashboard, seule, un mardi matin.

> **En cinq lignes.** Le problème, mesuré par morceaux le 26/08 : toasts en anglais (« Filiales Created Successfully. », « Articles Deleted Successfully. »), messages d'erreur anglais
> (« This price must be a number. », « The host resolves to a forbidden IP range… smtp.mailgun.org »), 80 permissions en anglais/jargon, « Filiales » et « Stuff » pour un
> mono-restaurant, quatre motifs d'écran (tiroir / page / modale / Blade externe), aucune aide contextuelle, aucune checklist de démarrage, colonnes hors cadre à 1366 px,
> 16 entrées + 11 sous-pages visibles sans hiérarchie d'usage — et une zone **non auditée en direct** (brief Z8 prêt). FINI = charte des motifs, glossaire commerçant appliqué,
> axe-core sans violation sérieuse sur les 25 pages visibles, checklist « Premier démarrage » spécifiée (construite par ONB-12), top 10 des frictions clos ou renvoyé (C1..C6).
> Premier geste : W0 puis exécuter le brief Z8 (chronomètre de la première heure) sur :8811.

# §0 — PRÉAMBULE

## §0.1 — Décision arbre de travail + PRÉ-VOL DE SESSION
- **Worktree dédié** `.claude/worktrees/onb11-ux`, branche `goal/onb11-ux-2026-08-26`, depuis **HEAD** ; en **vague A** comme audit (lecture seule), corrections en **vague B** après stabilisation de 01-10 (sinon conflits sur `fr.json`).
- Pré-vol : `.env` → `APP_URL=http://127.0.0.1:8811` ; `.env.testing` ; liens durs ; serveur 8811 ; `PLAYWRIGHT_BASE_URL` ; `@axe-core/playwright` présent (`node_modules/@axe-core/playwright` — vérifié `ls`).
- Base partagée : **lecture seule totale** en audit ; aucune écriture en base ; `safe-test.sh` pour Vitest/PHPUnit ciblés ; jamais `migrate:fresh`.
- ⚠️ `fr.json` (131 Ko) est un **registre partagé** : ce GOAL n'y écrit qu'en vague B, par blocs, après avoir relevé les clés ajoutées par 01-10 (append-coordination).
- Filet : `git branch backup/pre-onb11-2026-08-26`.

## §0.2 — Périmètre : DANS / HORS / voisins
| DANS | Fichiers POSSÉDÉS |
|---|---|
| S1 Charte des motifs | `docs/UX_CHARTE_BACKOFFICE.md` (À CRÉER), `resources/js/components/admin/components/**` (§6 partagé : `BreadcrumbComponent`, `buttons/`, `ErrorBoundary`, `LoadingComponent`, `LoadingContentComponent`, `MapComponent`, `MultiInputLanguageComponent`, `OrderDetailsComponent`, `OrderStatusComponent`, `pagination/`, `TableLimitComponent` — **sérialisé**), `resources/js/components/common/**`, `resources/css/app.css` |
| S2 Vocabulaire | `resources/js/languages/fr.json` (blocs `label.*`, `menu.*` — hors clés propres à un autre GOAL), `lang/fr/validation.php` (messages génériques), `docs/GLOSSAIRE_COMMERCANT.md` (À CRÉER), composant d'aide `admin/components/HelpHintComponent.vue` (À CRÉER) |
| S3 Accessibilité & tablette | `tests/js/a11y/**` (À CRÉER), `tests/e2e/admin-a11y-*.spec.js` (À CRÉER), `resources/css/app.css`, `layouts/backend/{BackendMenuComponent (hors visibilité → 05),BackendNavbarComponent}.vue` |
| S4 Psychologie de la première heure | `docs/UX_PREMIERE_HEURE.md` (À CRÉER : spécification de la checklist), ordre/regroupement du menu (proposition → 05), confirmations/annulations (charte) |

| HORS | Porté par |
|---|---|
| Tout composant de page (catalogue, réglages, équipe, rapports, stock, promos, équipement) | GOAL propriétaire — **fiche de renvoi** avec file:line + correctif proposé |
| Visibilité / ordre effectif du menu (`v1-hidden-modules.js`, `MenuComponent.vue`, `BackendMenuComponent.vue` visibilité) | ONB-05 |
| Construction de la checklist « Premier démarrage » | ONB-12 (ce GOAL la **spécifie**) |
| Titres FR des permissions | ONB-06 |
| Kiosk, POS, KDS (autres voies) | jamais — ce GOAL ne regarde que le back-office |

Zones à coordonner : `fr.json` (append par blocs), `admin/components/**` (importés par `PaymentComponent.vue:334` gelé → **jamais de changement d'API de composant**), `resources/css/app.css` (déclarer).

## §0.3 — Drapeaux d'expansion
SCOPE-1 gelé (`PaymentComponent.vue` importe `LoadingComponent` : tout changement de signature = STOP) · SCOPE-2 3 boucles · SCOPE-3 aucune migration · SCOPE-4 N/A · SCOPE-5 : corriger un composant de page d'un autre GOAL = STOP → fiche.

## §0.4 — Pipeline
`ultra-audit-profond` · `test-e2e` · `verify-before-report` · `chrome-devtools-mcp:a11y-debugging` (si disponible) · TDD sur les composants partagés. Non redécrit.

## §0.5 — Convergence et critères chiffrés
Rejets Axe 6 · **deux cycles consécutifs P0+P1 = 0 aux constats identiques** · instrument : une friction est un constat si elle est **reproduite par deux moyens** (capture lue + chronomètre / axe / DOM).

| # | Critère | Mesure | Seuil |
|---|---|---|---|
| C1 | Zéro anglais user-facing sur les 25 pages visibles | balayage des toasts, erreurs 422, libellés, titres (fixtures : 30 actions) | **0** |
| C2 | Charte des motifs appliquée | inventaire écran → motif ; écarts justifiés ou renvoyés | **100 %** inventorié, 0 écart non tranché |
| C3 | Accessibilité | axe-core sur 25 pages × 3 gabarits : 0 violation `critical`/`serious` ; navigation clavier de bout en bout sur 5 parcours ; cibles tactiles ≥ 44 px sur tablette | **0 / 5 / 100 %** |
| C4 | Première heure | chronomètre du parcours « identité → catalogue → équipe → équipement » avec un testeur naïf (persona) : chaque étape trouvée sans aide en < 60 s | **4/4** |
| C5 | Vocabulaire | glossaire commerçant ; renommages tranchés (G-VOCAB) ; aide « ? » sur 20 écrans clés | **20/20** |
| C6 | Top 10 frictions | chacune close (ce GOAL) ou renvoyée avec fiche acceptée | **10/10** |

## §0.6 — Base héritée
Vitest 3 644 · PHPUnit 5 194 · `fr.json` 131 250 octets · `admin/components/` 11 entrées · `tests/Feature/Settings/FrenchValidationMessagesAreTranslatedTest.php` (existant) · `tests/js/labelKeyParityFrontend.spec.js` (existant per mémoire 05/05 — vérifier `ls`) · `tests/js/posA11y.spec.js` (CAISSE) · `@axe-core/playwright` installé · mesures : Z2 (25 pages 0 libellé brut, sous-menu 1024 px sans défilement), Z7 (colonnes hors cadre à 1366 sur 3 listes), Z1/Z2 (toasts anglais), Z3 (permissions anglaises), mémoire « 92 % du FR = anglais littéral » (`backoffice_export_blob_permission_inerte_2026-08-12`, PIÈGE n°4 — à re-mesurer).

## §0.7 — Contradictions tranchées
- **C-CONST** (index) : G0.
- **C-NON-MESURÉ** — brief Z8 non exécuté : W1.
- **C-FR-92 %** — la mémoire du 12/08 affirme « 92 % du FR = anglais littéral » ; Z2 mesure « 0 libellé brut » et des toasts anglais ponctuels. Les deux peuvent être vrais (clé présente, valeur anglaise). Tranché : **mesurer** (script : valeurs de `fr.json` identiques à `en.json` ou contenant des mots anglais fréquents) avant tout chiffre.
- **C-VOIE** — ONB-11 est transverse mais la règle des voies interdit d'éditer les pages des autres. Tranché : **audit + charte + composants partagés** ici ; corrections de pages par fiches acceptées (le Jalonneur de chaque GOAL les traite) ; en vague B, ce GOAL peut exécuter les fiches **non prises** par leur propriétaire, une par une, après accord dans l'index.

## §0.8 — Le commerçant-type et ses questions
Nadia, mardi 9 h 12, première ouverture : 1. « Par où je commence ? » 2. « "Filiales", "Stuff", "SLA", "Netting" : c'est quoi ? » 3. « Pourquoi ce message est en anglais ? »
4. « Si je clique là, je casse quelque chose ? » 5. « Sur ma tablette, la liste dépasse : je fais comment ? »

# §1 — CARTE DU SYSTÈME (ancrages vérifiés)

| Sous-système | Maturité | Ancrage réel | Tests |
|---|---|---|---|
| S1 Motifs | **4 MOTIFS NON CHARTÉS** | tiroirs (catalogue `?create=1`/`/create`, filiale), pages (rapports), modales (attributs, bornes, imprimantes, TPE), Blade externe (`/admin/roue`, `/carnet`, `/m`) · `admin/components/*` (11) · `resources/css/app.css:320` (`.db-main` `h-screen overflow-auto`) | — |
| S2 Vocabulaire | **FR PARTIEL** | `fr.json` (131 Ko, bloc `menu` `:429`) · toasts « … Created/Updated/Deleted Successfully. » (Z1/Z2) · « This price must be a number. » (Z1) · message SMTP (Z7) · permissions (Z3) · « Filiales », « Stuff », « SLA », « Netting », « Tender » | `FrenchValidationMessagesAreTranslatedTest`, `labelKeyParityFrontend.spec.js` |
| S3 A11y / tablette | **NON MESURÉ** (sauf sous-menu 1024 OK, colonnes hors cadre 1366) | `@axe-core/playwright` · `layouts/backend/*` · captures Z7 01/03/06 | `posA11y.spec.js` (CAISSE, modèle) |
| S4 Première heure | **AUCUNE CHECKLIST** | `DashboardComponent.vue:30-46,133-188` (accès rapide 13 liens) · 16 entrées + 11 sous-pages (Z3 menu admin) | — |

**Sortie d'ancrage brute** : `wc -c fr.json` → 131 250 · `ls admin/components` → 11 · `ls node_modules/@axe-core` → `playwright` · `ls tests/e2e | wc -l` → 320 · menu admin mesuré (Z3 `browser_results.json`) → 28 entrées rendues (dont sections).

# §2 — ÉTAT MESURÉ / CONNU LE 2026-08-26
**Mesuré** : toasts anglais (Z1 `category_toasts`, Z2 `04-pass2.json`) ; « This price must be a number. » (Z1 capture 03) ; message SMTP anglais (Z7 capture 04) ; 44 titres de permissions anglais (Z3) ; colonnes Statut/Action hors cadre à 1366 (Z7 captures 01/03/06) ; sous-menu Réglages 11 onglets sans défilement à 1024 (Z2) ; 25 pages Réglages sans libellé brut (Z2) ; modale « Ajouter une borne » sans aucune aide (Z7 capture 02) ; modale Attribut **avec** aide (Z1 capture 05 — le bon exemple) ; PIN par défaut affiché (Z7).
**Non mesuré (W1, brief Z8)** : chronomètre de la première heure, axe-core, clavier, tablette 768×1024, temps de chargement, cohérence des icônes, recherche globale.

## §2bis — Top 10 des frictions attendues (hypothèses à confirmer ou réfuter en W1 — jamais un constat sans double preuve)
| # | Friction attendue | Où | Preuve partielle | Propriétaire pressenti |
|---|---|---|---|---|
| 1 | Premier écran = chiffres et produits d'un autre restaurant (Le Cayenne) pour un nouvel établissement | Dashboard | Z3 menu admin, Z1 aperçu filiales de démo | ONB-12 |
| 2 | Toasts et erreurs en anglais au milieu du français | catalogue, réglages, imprimantes | Z1, Z2, Z7 | ONB-02, 01, 10 (clés partagées : ici) |
| 3 | Vocabulaire technique (« Filiales », « Stuff », « SLA », « Netting », « Outbox », « Attribut ») | menu, permissions, widgets | Z0, Z3, Z7 | ONB-01, 06, 07, 10 ; glossaire ici |
| 4 | Cinq entrées pour le catalogue, cinq pour le stock | menu principal | Z0 §1 | ONB-02, 08, 05 |
| 5 | Modales sans aide (borne) vs modales avec aide (attribut) : incohérence | Réglages | Z7 capture 02 vs Z1 capture 05 | ONB-10 ; composant `HelpHintComponent` ici |
| 6 | Bouton qui « ne fait rien » (largeur 42 muette, `?create=1`, écrans de repli silencieux) | imprimantes, catalogue, composer | Z7, Z1 | ONB-10, 02, 03 |
| 7 | Listes qui débordent à 1366 px ; tablette non vérifiée | bornes, imprimantes, TPE | Z7 captures 01/03/06 | charte ici ; corrections par fiches |
| 8 | Rouge permanent non actionnable (État du système) | observabilité | Z7 capture 09 | ONB-10 |
| 9 | Aucune indication de « par où commencer » ; 16 entrées + 11 sous-pages à plat | menu | Z3 menu admin (28 lignes rendues) | spécification ici ; ONB-12, 05 |
| 10 | Peur de casser : suppressions sans phrase de conséquence, réglages sans « rétablir » | partout | à mesurer | charte ici ; ONB-05 |

## §2ter — Méthode du chronomètre de la première heure (W1, reproductible)
1. Compte Admin neuf sur une base **où la marque est neutre si ONB-12 est livré, sinon sur `:8811` en notant les biais Le Cayenne**. 2. Testeur naïf (agent « Psychologie commerçant » sans lecture préalable des GOAL) reçoit quatre consignes en langage courant : « mets ton nom et ton adresse », « ajoute un produit à 8,50 € avec une sauce au choix », « ajoute un caissier », « branche une imprimante ». 3. Pour chaque consigne : temps jusqu'au premier clic pertinent, nombre d'écrans visités, hésitations verbalisées, abandon éventuel, phrase « je ne comprends pas … ». 4. Chaque hésitation → capture lue → fiche de renvoi si reproduite par un second testeur (ROUGE). 5. Résultat = tableau consigne × (temps, écrans, hésitations, verdict OK/KO) ; seuil C4 = trouvé sans aide en < 60 s.

# §3 — SOUS-SYSTÈME 1 : LA CHARTE DES MOTIFS

**Tâches**
- **T-1.1.1** — Inventaire réel : pour les 25 pages visibles + 22 cachées : motif (tiroir / page / modale / Blade), bouton primaire, confirmation de suppression, annulation, état vide, état de chargement, pagination, toast — table MISSION §8.
  • test : (À CRÉER à `tests/js/sentinels/backofficePatternsInventory.spec.js` — cliquet : toute nouvelle page déclare son motif)
- **T-1.1.2** — `docs/UX_CHARTE_BACKOFFICE.md` : quand tiroir (création/édition courte), quand page (listes, rapports), quand modale (confirmation, création à 3 champs), jamais Blade externe pour un réglage ; suppression = confirmation + phrase de conséquence ; annulation toujours visible ; état vide = phrase + action.
- **T-1.1.3** — Composants partagés (`admin/components/**`, sérialisé) : `HelpHintComponent` (« ? »), `ConfirmDeleteComponent` (phrase de conséquence), `EmptyStateComponent` ; **sans changer** la signature de `LoadingComponent` (importé par `PaymentComponent.vue:334`, gelé).
  • test : (À CRÉER à `tests/js/sharedUxComponents.spec.js`) · non-régression : `npx vitest run tests/js/sentinels/`
**Acceptation** : C2 · 2 tests VERTS · charte commitée.

# §4 — SOUS-SYSTÈME 2 : LE VOCABULAIRE DU COMMERÇANT

**Tâches**
- **T-2.1.1** — Mesure : script comparant `fr.json` à `en.json` (valeurs identiques, mots anglais fréquents : `Successfully`, `Created`, `Please`, `Invalid`, `must be`) → liste des clés anglaises **avec le composant qui les affiche** ; idem `lang/fr/validation.php` (attributs non traduits : « order setup food preparation time », « site google map key » — mesurés Z2).
  • test : (À CRÉER à `tests/js/sentinels/frJsonNoEnglishValuesSentinel.spec.js`) + `FrenchValidationMessagesAreTranslatedTest.php` (existant, étendre aux attributs)
- **T-2.1.2** — Glossaire commerçant (`docs/GLOSSAIRE_COMMERCANT.md`) : Filiale → Point de vente / Mon établissement ; Stuff → Équipe salle ; Variante / Extra / Supplément / Attribut / Composant ; SLA → Retards ; Netting → Net ; Tender → Moyen de paiement ; Outbox → Événements en attente ; propositions **G-VOCAB**.
- **T-2.1.3** — Appliquer dans `fr.json` (blocs partagés) et proposer par fiche les clés propres aux pages (ONB-01/02/06/07/10) ; toasts génériques « Créé », « Modifié », « Supprimé » en FR.
  • test : sentinelle T-2.1.1 · C1
- **T-2.1.4** — Aide « ? » sur 20 écrans clés (contenu du glossaire) — insertion par fiches (composant fourni ici).
**Acceptation** : C1 = 0 · C5 · 2 tests VERTS · G-VOCAB tranché.

# §5 — SOUS-SYSTÈME 3 : ACCESSIBILITÉ & TABLETTE

**Tâches**
- **T-3.1.1** — axe-core sur 25 pages × 1366/1024/768 : rapport par page (violations `critical`/`serious`/`moderate`), captures lues ; cibles tactiles ; contraste (palette Cayenne `#F4501E` sur blanc : à mesurer).
  • test : (À CRÉER à `tests/e2e/admin-a11y-axe.spec.js`) · C3
- **T-3.1.2** — Clavier : 5 parcours (créer une catégorie, un employé, changer un réglage, lire un rapport, basculer une disponibilité) au clavier seul ; focus visible ; piège de focus dans les tiroirs/modales.
  • test : (À CRÉER à `tests/e2e/admin-keyboard-journeys.spec.js`)
- **T-3.1.3** — Tablette 768×1024 et 1024×768 : listes qui dépassent (Z7) → règle de charte (colonnes prioritaires, défilement interne, jamais de défilement horizontal de page) ; corrections dans `app.css` + fiches pour les listes concernées.
  • test : (À CRÉER à `tests/e2e/admin-tablet-no-horizontal-scroll.spec.js`)
**Acceptation** : C3 · 3 tests VERTS · question 5 = OUI.

# §6 — SOUS-SYSTÈME 4 : PSYCHOLOGIE DE LA PREMIÈRE HEURE

**Tâches**
- **T-4.1.1** — Chronomètre (brief Z8) : testeur naïf, 4 étapes, temps et hésitations consignés ; premier écran (widgets d'un autre restaurant ?) ; ce qui fait peur (boutons sans conséquence annoncée).
  • livrable : `recon/Z8_experience_commercant.md` · C4
- **T-4.1.2** — Spécification de la checklist « Premier démarrage » (`docs/UX_PREMIERE_HEURE.md`) : 7 étapes, ordre, critère de complétion mesurable par étape, écran cible (01/02/03/06/10), texte FR, persistance/dismiss — construite par ONB-12.
- **T-4.1.3** — Proposition d'ordre et de regroupement du menu par fréquence d'usage (Aujourd'hui / Vendre / Ma carte / Mon équipe / Mon matériel / Réglages) → G-MENU-ORDRE, exécution ONB-05.
- **T-4.1.4** — Confiance : chaque action destructive ou irréversible annonce sa conséquence (charte) ; chaque chiffre a son « ? » (ONB-07) ; chaque échec dit quoi faire (pattern « erreur = phrase + action »).
**Acceptation** : C4, C6 · spécification commitée · G-MENU-ORDRE tranché.

# §S — SCÉNARIOS ADVERSES OBLIGATOIRES (lecture seule : l'adversité porte sur la perception)
| Situation \ scénario | annulation | rechargement | double clic | deux onglets | rôle inférieur | données vides | volume | réseau coupé | conséquence annoncée | retour arrière | valeurs limites |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Tiroir de création | bouton visible ? perte de saisie annoncée ? | brouillon perdu → dit ? | bouton désactivé ? | — | toast « Permission requise » (mesuré) FR | état vide = phrase + action | — | message FR ? | — | — | nom long → coupure propre |
| Suppression | annulable | — | — | — | — | — | — | — | phrase de conséquence (`ConfirmDeleteComponent`) | archivage vs suppression dit | référencé → refus expliqué |
| Liste | — | filtres conservés | — | — | — | « aucun » ≠ tableau vide | 500 lignes : pagination, pas de défilement horizontal | — | — | — | 1366/1024/768 |
| Erreur serveur | — | — | — | — | — | — | — | 500 → phrase + action, jamais `SQLSTATE` (ONB-06/02/13) | — | — | — |
| Première heure | — | checklist reprise | — | — | — | Dashboard vide honnête | — | — | — | dismiss | — |

## §S.bis — Gabarit de fiche de renvoi (une par friction, dans MISSION §8 et dans le §8 du GOAL destinataire)
```
[P2] ONB-10 resources/js/components/admin/settings/Printers/PrintersComponent.vue:129-133 — Largeur « 42 (80 mm SAGA) » proposée puis refusée sans message
  friction     : le bouton « Enregistrer » ne fait rien ; aucune erreur affichée (capture recon/screens/Z7/05-…png lue ; pw-log visible_field_errors: [])
  second moyen : réponse réseau 422 POST /api/admin/printers (Rule::in([32,48]), PrinterRequest.php:57)
  correctif    : afficher errors.width_chars ; ajouter 42 à la règle si le moteur le gère, sinon retirer l'option
  charte       : §3 T-1.1.2 « toute soumission refusée affiche la raison sous le champ »
  statut       : émise le <date> → acceptée / refusée (motif) / corrigée (commit) — rejouée le <date>
```
Règles : une fiche = une friction ; deux preuves indépendantes ; file:line du propriétaire (jamais deviné) ; le Jalonneur du GOAL destinataire l'accepte ou la refuse par écrit ; ONB-11 rejoue après correction.

# §A — ARMÉE D'AGENTS
**UX/A11y** (rôle central) · **Psychologie commerçant** (rôle central : chronomètre, hésitations, peur) · Architecte (composants partagés sans casser `PaymentComponent`) · Sécurité (rien à écrire ; vérifie qu'aucune aide n'expose de secret) · Implémenteur unique (composants partagés, `fr.json` par blocs) · ROUGE (conteste chaque friction : reproduite deux fois ?) · QA visuel + ROUGE visuel · **Jalonneur**.
Disque `reports/test-e2e/ONB11_EXPERIENCE_COMMERCANT_TRANSVERSE/<round>/wave-<W>-<rôle>.json` ; contrat de constat ; fiches de renvoi au format `[P] <GOAL> <file:line> — friction / preuve / correctif`.

# §X — VAGUES DE CONVERGENCE
| Vague | Portée | Parallélisme | Bloquée par |
|---|---|---|---|
| **W0** | Pré-vol, filet, axe-core prêt | séquentiel | — |
| **W1** | **Reconnaissance Z8** (brief) : chronomètre, axe, clavier, tablette, mesure du FR ; livrable `recon/Z8_experience_commercant.md` + **top 10 des frictions** avec fiches | fan-out lecture seule (≤ 2 navigateurs) | — |
| **W2** | S1 charte + composants partagés (T-1.*) | séquentiel (`admin/components` sérialisé) | — |
| **W3** | S2 vocabulaire : mesure, glossaire (T-2.1.1, T-2.1.2) ; application dans `fr.json` **en vague B** (T-2.1.3) | séquentiel | G-VOCAB ; stabilisation de 01-10 pour `fr.json` |
| **W4** | S3 a11y & tablette (T-3.*) | séquentiel | — |
| **W5** | S4 première heure (T-4.*) | séquentiel | G-MENU-ORDRE (proposition) |
| **W6** | Convergence : deux cycles (axe + FR + captures), Vitest, Playwright a11y, fiches acceptées/refusées listées, BRAIN | séquentiel | — |
**§X.8** 6 points · **§X.9** STOP/`STUCK_*`/4 options · **§X.10** `wip`/`INTERRUPT_*`/BRAIN.

# §G — GATES PROPRIÉTAIRE
| Gate | Description | QUI | QUOI | OÙ | Statut |
|---|---|---|---|---|---|
| **G0** | Amendement constitutionnel (index) | Propriétaire | ligne | `CONSTITUTION.md` | EN ATTENTE — ne bloque pas |
| **G-VOCAB** | Renommages (Filiales → Points de vente/Mon établissement, Stuff → Équipe salle, SLA → Retards, Outbox → Événements en attente…) | Propriétaire | liste validée | `docs/GLOSSAIRE_COMMERCANT.md` + MISSION §6 | EN ATTENTE — bloque T-2.1.3 |
| **G-MENU-ORDRE** | Regroupement du menu par usage (exécution ONB-05) | Propriétaire | choix | MISSION §6 | EN ATTENTE — bloque T-4.1.3 (exécution) |
| **G-CHARTE** | Adoption de `docs/UX_CHARTE_BACKOFFICE.md` comme règle des futurs écrans | Propriétaire | signature | `docs/` | EN ATTENTE |

# §R — RÉFÉRENCES
`ultra-audit-profond` · `test-e2e` · `verify-before-report` · `chrome-devtools-mcp:a11y-debugging` · `frontend-design:frontend-design` · `CLAUDE.md §3bis` (palette, FR ADR-007), `§6` (visuel obligatoire) · `plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md` · `_FICHES_GOAL.md` (ONB-11) · `recon/_ZONES.md` (Z8) · `recon/Z1..Z7` (mesures dispersées) ·
mémoire `backoffice_export_blob_permission_inerte_2026-08-12` (« 92 % du FR ») · `plans/GOAL_UX_MOBILE_CAISSE_WEB_2026-08-06.md` · `plans/GOAL_WEB_ADVERSARIAL_UX_TOTAL_2026-08-05.md` · `tests/js/posA11y.spec.js` (modèle CAISSE).

# §F — RÈGLE FINALE
TERMINÉ quand et seulement quand : 1. 6 vagues closes ; 2. C1..C6 VRAIS ; 3. Vitest ≥ 3 644 + ≥ 7 tests créés VERTS, PHPUnit ≥ 5 194 ; 4. diff gelé 0 (`PaymentComponent.vue` intact malgré `admin/components`) ; 5. N/A fiscal ; 6. gates tranchés ; 7. charte + glossaire + spécification commités, BRAIN vrai ; 8. deux cycles identiques ; 9. **top 10 des frictions : 10/10 closes ou renvoyées avec fiche acceptée**.
**Interdit** : éditer un composant de page d'un autre GOAL · changer la signature d'un composant partagé importé par une zone gelée · écrire dans `fr.json` avant la vague B · déclarer une friction sans double preuve · approuver un gate.
> Le sens : mardi 9 h 12, Nadia sait par où commencer, lit tout en français, comprend chaque mot, et rien ne lui fait peur.
