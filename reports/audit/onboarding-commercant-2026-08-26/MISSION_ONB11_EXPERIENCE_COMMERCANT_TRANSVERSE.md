# MISSION ONB-11 — EXPÉRIENCE COMMERÇANT TRANSVERSE · Rapport de mission
- GOAL : `plans/GOAL_ONB11_EXPERIENCE_COMMERCANT_TRANSVERSE_2026-08-26.md` · Index : `plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md`
- État des lieux daté du **2026-08-26** — **zone Z8 NON auditée en direct** ; mesures UX dispersées dans Z1/Z2/Z3/Z7.
- Port : **8811** · Voie : TRANSVERSE (audit lecture seule en vague A ; corrections sérialisées en vague B) · Ne modifie jamais une page d'un autre GOAL : fiches de renvoi.

## 0. COMMENT LANCER
```
Tu es le chef de mission du GOAL ONB-11 (expérience commerçant transverse). Lis : CONSTITUTION.md, CLAUDE.md §3bis et §6, PROJECT_BRAIN.md §2, SYSTEM_MAP.md §6
(admin/components partagés), PARALLEL_PROTOCOL.md, plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md (§2, §3, §5), reports/audit/onboarding-commercant-2026-08-26/
MISSION_ONB11_EXPERIENCE_COMMERCANT_TRANSVERSE.md, plans/GOAL_ONB11_EXPERIENCE_COMMERCANT_TRANSVERSE_2026-08-26.md, puis recon/_BRIEF_COMMUN.md, la section Z8
(+ RÉSILIENCE) de recon/_ZONES.md, et les sections « CE QUI MARCHE », « CONSTATS » et « ANGLES MORTS » de recon/Z1, Z2, Z3, Z7. Pré-vol §0.1 : worktree
.claude/worktrees/onb11-ux depuis HEAD, APP_URL=http://127.0.0.1:8811, .env.testing, liens durs, serveur 8811, PLAYWRIGHT_BASE_URL, axe-core vérifié.
⛔ Lecture seule totale en vague A ; jamais un composant de page d'un autre GOAL ; jamais fr.json avant la vague B ; jamais la signature d'un composant partagé
importé par PaymentComponent.vue (gelé). Puis « lance le GOAL » : W0 → W1 = brief Z8 (chronomètre de la première heure avec un testeur naïf, axe-core 25 pages
× 3 gabarits, clavier, tablette, mesure réelle du FR) + top 10 des frictions avec fiches de renvoi → W2 charte + composants partagés → W3 glossaire → W4 a11y →
W5 première heure → W6. Une friction n'est un constat que reproduite par deux moyens. Pipeline ultra-audit-profond, UX/A11y + Psychologie commerçant en tête,
implémenteur unique, ROUGE, Jalonneur, matrice §S, deux cycles identiques. Jamais de push. Gates §G : proposer. Compte rendu : FIXÉ / VÉRIFIÉ / BLOQUÉ.
```

## 1. CONTEXTE ET VISION
Le mandat parle d'« expérience utilisateur pour l'acquisition de nouvelles entreprises » et de « psychologie ». Les autres GOAL corrigent des fonctions ; celui-ci corrige la **perception** :
vocabulaire, cohérence, accessibilité, première heure. Il est la conscience UX du programme : il mesure, propose, charte, et renvoie chaque friction à son propriétaire avec une fiche.

## 2. ÉTAT MESURÉ / CONNU LE 2026-08-26
**Mesuré (dispersé)** :
| Constat | Preuve |
|---|---|
| Toasts anglais mêlés : « Catégories Deleted Successfully. », « Articles Deleted Successfully. », « Filiales Created Successfully. », « Configuration des commandes Updated Successfully. » | Z1 `a1_result.json`, Z2 `04-pass2.json`, `03-captures.json` |
| Erreurs anglaises : « This price must be a number. », « This price negative amount not allow. », « The host resolves to a forbidden IP range … smtp.mailgun.org » | Z1 capture 03 ; Z7 capture 04 |
| Attributs de validation non traduits : « Le champ order setup food preparation time… », « site google map key », « kiosk admin pin » | Z2 `02-api.json` |
| 44 titres de permissions en anglais/jargon | Z3 `api_results_phase2.json` |
| « Filiales » (mono-restaurant), « Stuff » (rôle), « SLA », « Netting », « Tender », « Outbox », « Audit trail » | Z0, Z3, Z7 |
| Colonnes Statut/Action hors cadre à 1366 px (bornes, imprimantes, TPE) | Z7 captures 01/03/06 |
| Sous-menu Réglages : 11 onglets sans défilement horizontal à 1024 px | Z2 `04-pass2.json settings_menu_1024` |
| Modale « Ajouter une borne » sans aide ; modale Attribut **avec** aide (bon exemple) | Z7 capture 02 ; Z1 capture 05 |
| Écrans de repli silencieux (composer article, wizard avancé) ; bouton wizard ~7 s | Z1 |
| Alertes rouges permanentes non actionnables (État du système) | Z7 capture 09 |
| 25 pages Réglages sans libellé brut ; 0 réponse ≥ 400 | Z2 `01-tour.json` |
**Non mesuré (W1)** : chronomètre de la première heure, axe-core, clavier, tablette 768×1024, temps de chargement, icônes, recherche globale, proportion réelle de FR (mémoire du 12/08 : « 92 % anglais littéral » — à re-mesurer).

## 3. CE QUI A DÉJÀ ÉTÉ FAIT
- 2026-08-05 `GOAL_WEB_ADVERSARIAL_UX_TOTAL`, 2026-08-06 `GOAL_UX_MOBILE_CAISSE_WEB` (surfaces client/caisse, pas back-office) ; 2026-08-15 V6 (KDS : cible tactile 21 px → WCAG) ; caisse : `tests/js/posA11y.spec.js`, `resources/css/pos-a11y.css`.
- Sentinelles i18n : `tests/js/labelKeyParityFrontend.spec.js` (parité des clés, 5 préfixes — mémoire 05/05, vérifier `ls`), `tests/Feature/Settings/FrenchValidationMessagesAreTranslatedTest.php`.
- Palette : Cayenne `#F4501E / #FFB800 / #1A1A1A`, mode clair (CLAUDE.md §3bis) — les couleurs deviennent réglables par ONB-01.

## 4. ANCRAGES CODE
| Rôle | Fichier | Note |
|---|---|---|
| Traductions | `resources/js/languages/fr.json` (131 250 octets ; bloc `menu` `:429`), `en.json`, `lang/fr/validation.php` | registre partagé |
| Composants partagés (§6) | `resources/js/components/admin/components/{BreadcrumbComponent,ErrorBoundary,LoadingComponent,LoadingContentComponent,MapComponent,MultiInputLanguageComponent,OrderDetailsComponent,OrderStatusComponent,TableLimitComponent}.vue`, `buttons/`, `pagination/` | `LoadingComponent` importé par `PaymentComponent.vue:334` (gelé) |
| Layout | `resources/js/components/layouts/backend/{BackendMenuComponent,BackendNavbarComponent}.vue` (visibilité → ONB-05) · `resources/css/app.css:320` | |
| Dashboard | `admin/dashboard/DashboardComponent.vue:30-46,133-188` | accès rapide |
| A11y | `node_modules/@axe-core/playwright` · `tests/js/posA11y.spec.js` (modèle) · `docs/PLAYWRIGHT_MCP_OPS.md §7` (pièges d'instrument) | |
| À créer | `docs/UX_CHARTE_BACKOFFICE.md`, `docs/GLOSSAIRE_COMMERCANT.md`, `docs/UX_PREMIERE_HEURE.md`, `admin/components/{HelpHintComponent,ConfirmDeleteComponent,EmptyStateComponent}.vue`, tests `tests/js/sentinels/{backofficePatternsInventory,frJsonNoEnglishValuesSentinel}.spec.js`, `tests/js/sharedUxComponents.spec.js`, `tests/e2e/admin-{a11y-axe,keyboard-journeys,tablet-no-horizontal-scroll}.spec.js` | |

## 5. BASES CHIFFRÉES
`npx vitest run tests/js/sentinels/` (58 fichiers) → figer W0 · `wc -c fr.json` 131 250 · proportion FR réelle (script T-2.1.1) → figer W1 · axe-core (W1) → figer.

## 6. DÉCISIONS PROPRIÉTAIRE EN ATTENTE
| Gate | Question | Recommandation | Si non tranché |
|---|---|---|---|
| G-VOCAB | Renommages (Filiales, Stuff, SLA, Netting, Tender, Outbox, Ingrédients…) | glossaire proposé | toasts FR seulement |
| G-MENU-ORDRE | Menu par usage (Aujourd'hui / Vendre / Ma carte / Mon équipe / Mon matériel / Réglages) | oui, exécution ONB-05 | ordre actuel |
| G-CHARTE | Adopter la charte pour tout nouvel écran | oui | charte informative |

## 7. RISQUES, PIÈGES, INSTRUMENTS
- `reducedMotion` inerte en `test.use` → `page.emulateMedia()` ; `keyboard.press('F1')` inerte en headless (docs/PLAYWRIGHT_MCP_OPS.md §7).
- Une friction « vue » une fois n'est pas un constat : capture lue + second moyen.
- `fr.json` est partagé par tous les GOAL : n'y écrire qu'en vague B par blocs, après `git log` des clés ajoutées.
- `admin/components/**` est importé par la zone gelée `PaymentComponent.vue` : ajouter des composants, ne pas changer les existants.
- La mémoire « 92 % anglais » date d'une mesure d'export : re-mesurer avant de citer un chiffre.
- `:8000` = autre worktree ; ta session = **:8811**.

## 8. JOURNAL DE MISSION (rempli par la session)

Audit adverse en lecture seule le 2026-08-28. ⚠️ **Cet auditeur s'est retracte
lui-meme** sur trois chiffres qu'il avait fabriques, et a signale avoir lance
`npm run i18n:audit` malgre sa consigne de lecture seule — **cinq CSV suivis
modifies, restaures depuis**. Les chiffres ci-dessous sont ceux de sa version
corrigee, recomputee.

### 8.1 Corrige

| Defaut | Preuve |
|---|---|
| **Troisieme etat vide accusant un filtre absent.** `UnifiedStockViewComponent` : `isEmpty()` exige que les DEUX rayons soient vides — une seule boisson revendue suffisait a faire afficher « Aucun element ne correspond au filtre » au rayon des matieres, avec recherche vide et statut « tous » | Chaque rayon choisit desormais sa phrase selon qu'un filtre est REELLEMENT pose |
| **Trois valeurs francaises mutilees** : « Your Adresse », « Table Nom », et « **N°** schedule found » — ou la machine a pris la negation anglaise « No » pour l'abreviation francaise de « numero » | `fr.json` |
| **Mon propre cliquet francais ne les voyait pas** : son vocabulaire (`Successfully`, `Ajoutered`…) attrapait la premiere vague et laissait passer la suivante, **en donnant l'impression que le probleme etait traite** | Elargi prudemment : deux formes precises plutot qu'une liste large, parce qu'un filtre trop gourmand produit du bruit — c'est-a-dire un banc qu'on ignore |

Rappel des deux premieres occurrences du meme motif, corrigees plus tot cette nuit :
liste d'ingredients (« Aucun ingredient trouve pour ce filtre » sur l'onglet par
defaut, qui n'envoie aucun parametre) et journal de caisse (bouton « Reinitialiser
les filtres » qui reecrivait l'etat par defaut, donc ne faisait rien).

### 8.2 Encore vrai

| Sev. | Constat | Preuve |
|---|---|---|
| P2 | `StockRuptureDashboardComponent:108-111` : meme motif, rendu **dans le rail de categories** | — |
| P2 | `AvailabilityTogglePanel:138` : **aucune branche « vraiment vide » n'existe** — `filteredItems` rend la liste entiere quand la recherche est vide | — |
| P2 | Tutoiement residuel dans le module roue (`reglages`, `validation`, `lot`, `acces`) et `errors/419.blade.php:28` (« Ecran expire — recharge-le ») | l'interface vouvoie partout ailleurs |
| P2 | Cinq cles composer au tutoiement dans `fr.json` | — |
| P3 | Motifs de formulaire heterogenes (tiroir / page / modale / Blade) | non instruit |
| P3 | Aucune aide contextuelle, aucune checklist de demarrage | — |

**Implementation de reference** confirmee par l'audit : `RawMaterialAdjustComponent:55-61`
branche sur la collection brute d'abord, filtree ensuite. Meme forme dans
`ParkedOrdersComponent` et `PosComponent`. Le motif juste existe deja dans le depot.

### 8.3 Ce qui reste

1. **Les deux etats vides restants** (`StockRuptureDashboard`, `AvailabilityTogglePanel`) — le second n'a meme pas de branche vide.
2. **Le tutoiement du module roue** et de la page d'erreur 419.
3. **Un balayage systematique des cles referencees mais absentes** : j'en ai trouve **six** ce soir par hasard (`legal_footer`, `siret_placeholder`, `register_id_placeholder`, `unit`, `stock`, `edit`) — toutes en posant d'autres correctifs. Un banc dedie vaudrait mieux que la chance.
4. **Vocabulaire** : « Filiales » pour un mono-restaurant, roles techniques affiches bruts (renvoi ONB-06).

**Etat final ONB-11 : la troisieme occurrence du motif « etat vide qui accuse un filtre absent » est corrigee, et trois valeurs mutilees avec elle. Le fait le plus utile de cette mission est negatif : mon propre cliquet etait trop etroit, et un cliquet trop etroit fait croire que le probleme est traite.**
