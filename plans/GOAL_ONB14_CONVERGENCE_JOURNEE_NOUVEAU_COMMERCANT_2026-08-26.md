# GOAL — ONB-14 CONVERGENCE « JOURNÉE D'UN NOUVEAU COMMERÇANT »
## FoodKing — Onboarding commerçant · la preuve de bout en bout, sur une installation vierge, qu'un établissement qui n'est PAS Le Cayenne se règle, vend, cuisine, encaisse, clôture et lit ses chiffres — deux cycles consécutifs aux constats identiques, sans qu'un développeur ait touché un fichier

- **Slug** : `ONB14_CONVERGENCE_JOURNEE_NOUVEAU_COMMERCANT_20260826` · **Auteur** : Claude Code (chef de projet + rédacteur) · **Date** : 2026-08-26
- **Voie SYSTEM_MAP** : **TRANSVERSE, aucun code produit** — (À CRÉER) `tests/e2e/onboarding-journee-*.spec.js`, `tests/Feature/Onboarding/JourneeNouveauCommercantTest.php`, `reports/audit/onboarding-commercant-2026-08-26/CONVERGENCE_*.md`, `reports/test-e2e/ONB14_*/**` ; chaque échec est **renvoyé** au GOAL propriétaire par fiche
- **HEAD** : `43b120c7d` · **Index parent** : `plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md` · **Rapport de mission** : `reports/audit/onboarding-commercant-2026-08-26/MISSION_ONB14_CONVERGENCE_JOURNEE_NOUVEAU_COMMERCANT.md`
- **Port de session** : **8814** · **Base dédiée** `foodking_onb14` (installation vierge de ONB-12) · **Vague D** : dernier · **Persona** : Nadia, jour 1 d'ouverture, avec son intégrateur au téléphone.

> **En cinq lignes.** Rien ne prouve aujourd'hui le parcours complet d'un établissement autre que Le Cayenne. Les briques existent (`tests/e2e/boucle-quotidienne.spec.js` 4/4 vert et
> son jumeau `tests/Feature/BoucleQuotidienneTest.php` L0-L7, 15/08 ; 320 specs E2E ; garde d'identité Playwright ; résolveur de fixtures), mais elles tournent sur les données Le Cayenne.
> FINI = un scénario « journée » scripté (navigateur réel + jumeau PHP) sur `foodking_onb14` avec des données « Chez Nadia » 100 % différentes (noms, prix, règles de wizard), qui passe
> **deux cycles consécutifs aux constats identiques**, chaque échec ayant été renvoyé, corrigé par son GOAL et rejoué ; puis la clôture du programme (rapport, BRAIN, CONSTITUTION si G0,
> SYSTEM_MAP, étiquette G-PUSH). Premier geste : W0 = installation vierge par ONB-12 sur `foodking_onb14`, ligne de base NF525 attestée.

# §0 — PRÉAMBULE

## §0.1 — Décision arbre de travail + PRÉ-VOL DE SESSION
- **Worktree dédié** `.claude/worktrees/onb14-convergence`, branche `goal/onb14-convergence-2026-08-26`, depuis le **HEAD de fin de vague C** (tous les GOAL 01-13 fusionnés ou explicitement différés par écrit dans l'index).
- Pré-vol : `.env` → `APP_URL=http://127.0.0.1:8814`, **`DB_DATABASE=foodking_onb14`** (créée vide, installée par `foodking:installer` de ONB-12 — G-DATA) ; `.env.testing` ; liens durs ; serveur 8814 ; `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8814` (garde d'identité `tests/Playwright/global-setup.js:64-113` : elle **doit** accepter 8814 et refuser 8766/8000) ; `QUEUE` : worker dédié `--queue=high,default` sur cette base seulement ; soketi local ou repli scrutation (documenté `SYNC_CONTRACT.md`).
- ⛔ **Jamais** la base partagée `foodking_e2e` ; jamais `:8766` ; jamais `migrate:fresh` ailleurs que sur `foodking_onb14` ; les commandes créées ici sont de **vraies** commandes fiscales de cette base (chaîne NF525 propre, attestée avant/après) — aucune ne doit être supprimée (annulation = statut, jamais `DELETE`).
- Filet : `git branch backup/pre-onb14-2026-08-26` + dump `foodking_onb14` après installation (état zéro rejouable).

## §0.2 — Périmètre : DANS / HORS / voisins
| DANS | Fichiers POSSÉDÉS |
|---|---|
| S1 Scénario « journée » | `tests/e2e/onboarding-journee-nouveau-commercant.spec.js` (À CRÉER), `tests/e2e/helpers/onboarding-journee.js` (À CRÉER : données « Chez Nadia »), `tests/Feature/Onboarding/JourneeNouveauCommercantTest.php` (À CRÉER, jumeau PHP sur MySQL `foodking_onb14`) |
| S2 Boucle de convergence | `reports/audit/onboarding-commercant-2026-08-26/CONVERGENCE_ONB14_<cycle>.md`, `reports/test-e2e/ONB14_CONVERGENCE/<cycle>/**` (captures lues, réseau, console, DB) |
| S3 Registre des renvois | `reports/audit/onboarding-commercant-2026-08-26/REGISTRE_RENVOIS_ONB14.md` (À CRÉER : constat → GOAL → statut → preuve de clôture) |
| S4 Clôture du programme | `reports/audit/onboarding-commercant-2026-08-26/RAPPORT_FINAL_PROGRAMME.md` (À CRÉER), mises à jour `PROJECT_BRAIN.md §2/§3/§4/§6/§7`, `SYSTEM_MAP.md` (sous-voies CENTRAL), `CONSTITUTION.md` (**uniquement** la ligne G0 déjà contresignée), étiquette (G-PUSH) |

| HORS | Porté par |
|---|---|
| **Tout fichier produit** (`app/`, `resources/js/`, `config/`, `database/`, `routes/`) | GOAL propriétaire — fiche de renvoi, rejeu après correction |
| Harnais E2E général (320 specs, cliquets de dérive : sélecteurs morts 23, routes 1, idempotence 0, KDS V2 14) | GOAL CONSOLIDATION W2 (harnais) — ce GOAL n'écrit que **ses** specs, avec le résolveur partagé |
| Installation vierge, socle | ONB-12 (ce GOAL l'**exécute**) |
| `CONSTITUTION.md` au-delà de la ligne G0 | propriétaire |

Zones à coordonner : `tests/e2e/helpers/*` (réutiliser `admin-auth.js`, `login.js`, `kiosk-auth.js`, `kiosk-order.js`, `place-order.js`, `idempotency-key.js`, `process-audit.js`, `sync-journey-trace.js` — **sans les modifier**), `tests/Playwright/global-setup.js` (garde d'identité : port 8814 à autoriser = 1 ligne, déclarée).

## §0.3 — Drapeaux d'expansion
SCOPE-1 gelé (aucune raison) · SCOPE-2 3 boucles **par constat** puis renvoi · SCOPE-3 aucune migration · SCOPE-4 NF525 : chaîne de `foodking_onb14` attestée avant/après chaque cycle ; toute commande est réelle ; jamais de suppression · SCOPE-5 : corriger un fichier produit « pour que ça passe » = STOP → fiche.

## §0.4 — Pipeline
`test-e2e` (boucle visuelle adverse, règle de convergence) · `verify-before-report` · `ultra-audit-profond` pour la rédaction des specs. Non redécrit.

## §0.5 — Convergence et critères chiffrés
**Convergence = deux cycles consécutifs complets avec P0+P1 = 0 ET ensembles de constats identiques** (règle `test-e2e`). Un cycle = la journée entière, rejouée depuis l'état zéro (dump), navigateur réel + jumeau PHP, captures **lues**, console/réseau propres.

| # | Critère | Mesure | Seuil |
|---|---|---|---|
| C1 | Installation vierge | état zéro : 0 article, 0 borne, 0 texte « Cayenne » sur 12 pages (grep DOM), chaîne NF525 initialisée | **VRAI** |
| C2 | Journée complète | 12 étapes (§3) passent au navigateur ET en PHP | **12/12** |
| C3 | Zéro développeur | 0 fichier édité pendant le cycle (`git status` propre hors rapports), 0 commande artisan hors installation/worker | **0** |
| C4 | Chiffres vrais | Z du jour = rapport des ventes = widgets = SUM SQL (ONB-07) sur la journée jouée | **écart 0,00** |
| C5 | Prix vrais | total borne = total caisse = ticket = snapshot pour la composition « Nadia » (ONB-03) | **identiques** |
| C6 | Deux cycles identiques | constats du cycle N = cycle N+1 (ensembles), P0+P1 = 0 | **VRAI** |
| C7 | Registre des renvois | 100 % des constats renvoyés portent une preuve de clôture ou un différé propriétaire | **100 %** |

## §0.6 — Base héritée
`tests/e2e/boucle-quotidienne.spec.js` (4/4 vert, navigateur réel) + `tests/Feature/BoucleQuotidienneTest.php` (L0-L7 + L5bis, 5 canaux, 15/08) · `tests/Playwright/global-setup.js` (garde d'identité `:64-113`, comptes `:126-151`) · `tests/e2e/helpers/` (17 fichiers) · `tests/e2e/` = 320 specs · cliquets de dérive (BRAIN 25/08) · `docs/PLAYWRIGHT_MCP_OPS.md §7` (pièges : `reducedMotion`, `F1-F12`, produit inexistant) · `reports/audit/VAGUE_D_CAUSES_REELLES_2026-08-25.md` (sélecteurs KDS V2) · PHPUnit 5 194 · Vitest 3 644 · NF525 8 119 ajout seul.

## §0.7 — Contradictions tranchées
- **C-CONST** — ce GOAL **clôt** l'amendement G0 (écriture de la ligne contresignée dans `CONSTITUTION.md`, rien d'autre).
- **C-DONNÉES** — la boucle quotidienne existante prouve **Le Cayenne** ; l'index exige un établissement **différent**. Tranché : données « Chez Nadia » (6 catégories, 18 articles, 2 profils de wizard à règles, 3 employés, 1 borne, 1 imprimante virtuelle `nc -l 9100`) définies dans `helpers/onboarding-journee.js`, jamais un produit Le Cayenne.
- **C-HARNAIS** — le harnais E2E a une dette de dérive (BRAIN 25/08) ; ce GOAL n'attend pas sa résolution : ses specs sont **neuves**, avec le résolveur de fixtures partagé et des `data-testid` **existants** (sinon fiche au propriétaire, jamais un sélecteur inventé — leçon des 23 sélecteurs morts).
- **C-FISCAL** — « commande test » n'existe pas (NF525) : les commandes de la journée sont réelles sur `foodking_onb14`, base **jetable** et rejouable depuis le dump ; la chaîne y est attestée.

## §0.8 — Le commerçant-type et ses questions
Nadia, jour 1 : 1. « Ma borne vend ma carte, pas celle d'un autre ? » 2. « Le ticket porte mon SIRET ? » 3. « La cuisine voit ma commande, sur le bon poste ? » 4. « Le soir, le Z et le rapport disent la même chose ? » 5. « Personne n'a dû ouvrir un fichier pour que ça marche ? »

# §1 — CARTE DU SYSTÈME (ancrages vérifiés)

| Sous-système | Maturité | Ancrage réel | Tests |
|---|---|---|---|
| S1 Scénario | **MODÈLE EXISTANT (Cayenne)** | `tests/e2e/boucle-quotidienne.spec.js` · `tests/Feature/BoucleQuotidienneTest.php` · helpers `admin-auth.js` (`loginAdmin`, `X-API-KEY`), `login.js` (`#formEmail`, `#formPassword`, `loginAsPosOperator`, `loginAsChefOperator`, `loginAsKiosk`), `kiosk-auth.js`, `kiosk-order.js` (`resolveSimpleOrderableItem`), `place-order.js`, `idempotency-key.js`, `process-audit.js`, `sync-journey-trace.js` | 4/4 + L0-L7 |
| S2 Convergence | **RÈGLE ÉTABLIE** | `test-e2e` (deux cycles identiques), `docs/PLAYWRIGHT_MCP_OPS.md §7`, garde d'identité `global-setup.js:64-113` | — |
| S3 Renvois | **INEXISTANT** | — | (À CRÉER) |
| S4 Clôture | **PROTOCOLE DÉFINI** | `PROJECT_BRAIN.md §2/§3/§4/§6/§7`, `SYSTEM_MAP.md`, `CONSTITUTION.md §1`, `ultra-architect-planify` Axe 10 étape 6 | — |

**Sortie d'ancrage brute** : `ls tests/e2e | grep -i "boucle\|journee"` → `boucle-quotidienne.spec.js` · `ls tests/Feature | grep -i boucle` → `BoucleQuotidienneTest.php` · `ls tests/e2e/helpers` → 17 fichiers · `ls tests/e2e | wc -l` → 320 · `grep -n "8766\|8000" tests/Playwright/global-setup.js` → `:64-113` (garde) · comptes `:126-151`.

# §2 — ÉTAT CONNU LE 2026-08-26
Aucune journée « autre établissement » n'a jamais été jouée. La boucle quotidienne Le Cayenne est verte (15/08). Le harnais porte une dette de dérive mesurée (25/08) et des pièges d'instrument documentés. Les GOAL 01-13 sont écrits, non exécutés : ce GOAL ne peut démarrer qu'en **vague D**.

# §3 — SOUS-SYSTÈME 1 : LE SCÉNARIO « JOURNÉE » (12 étapes, deux jumeaux)

**Données « Chez Nadia »** (`helpers/onboarding-journee.js`) : identité (nom, SIRET 14 chiffres valide, TVA intra, adresse Lyon, horaires lun-sam 11:00-14:30 / 18:00-22:30, fermé dimanche, logo neutre, couleur `#0055FF`) ; 6 catégories ; 18 articles (dont « Kebab Nadia » 8,50 € avec règles : sauce 1 incluse puis 0,50 €, viande obligatoire choix unique, boisson formule +2 €) ; taxes 10 % ; 3 employés (Gérant, Caissier Sami, Cuisine Léa) ; 1 borne ; 1 imprimante `127.0.0.1:9100` (récepteur `nc`) ; 1 TPE simulé.

| Étape | Ce que fait Nadia (navigateur) | Preuve PHP (jumeau) | GOAL prouvé |
|---|---|---|---|
| E0 | Installation vierge (`foodking:installer --etablissement="Chez Nadia"`) ; premier login, mot de passe imposé | état zéro, chaîne NF525 initialisée | 12 |
| E1 | Checklist « Premier démarrage » 0/7 → Identité : nom, SIRET, TVA, adresse, horaires, logo, couleur | `branches`/`settings` relus ; ticket de test rendu porte SIRET | 12, 01 |
| E2 | Carte : 6 catégories, 18 articles (import Excel de 12 + 6 à la main), taxe 10 %, stations, canaux | `items` 18, `tax_id` 10 %, `kds_station ≠ none` | 02 |
| E3 | Personnalisation : profil « Kebab Nadia » (règles) publié ; aperçu 9,50 € pour 2 sauces + boisson | devis backend = 9,50 € sur 3 surfaces | 03 |
| E4 | (optionnel) Assistant : « ajoute la sauce blanche à tous les kebabs » → plan → confirmation | 6 articles modifiés, journal | 04 |
| E5 | Équipe : Gérant, Sami (Caissier), Léa (Cuisine) ; Sami ne voit pas les rapports (API 403) | matrice rôles | 06 |
| E6 | Réglages : tolérance de caisse 5 €, remise manuelle OFF, promo borne ON ; journal « Nadia a changé… » | `settings` + `settings_audit` | 05, 13 |
| E7 | Équipement : borne installée par lien, imprimante `127.0.0.1:9100` acceptée, test-print reçu par `nc`, TPE « Simulation » | jeton borne, octets ESC/POS | 10 |
| E8 | Stock : « pain » en rupture → borne masque les kebabs en < 10 s ; remise en dispo | API menu | 08 |
| E9 | Promo : code `BIENVENUE` -10 % actif sur la borne ; devis borne = commit | devis/commit | 09 |
| E10 | Vente : Sami ouvre la caisse ; client commande à la borne « Kebab Nadia » 2 sauces + boisson + code → paiement au comptoir → KDS poste chaud (Léa) bump → OSS « prêt » → ticket imprimé (SIRET, TVA, prix 9,50 - 10 %) | `orders` 1, snapshot avec règle, `audit_logs` +N, octets imprimante | CAISSE/BORNE/KDS + 03/01 |
| E11 | Soir : fermeture de caisse (écart 0), Z du jour, rapport des ventes, widgets, export Excel — tous égaux | Z = rapport = widget = SQL | 07 |
| E12 | Lendemain : rapport X, journal des modifications, checklist 7/7, dashboard sans « Cayenne » | grep DOM 12 pages | 12, 13, 07 |

**Tâches**
- **T-1.1.1** — Écrire `helpers/onboarding-journee.js` (données) + `onboarding-journee-nouveau-commercant.spec.js` (12 étapes, captures à chaque étape, console/réseau collectés, sélecteurs **existants** seulement).
  • test : le spec lui-même · ⚠️ pièges : `page.emulateMedia()` pour reducedMotion, pas de `F1-F12`, serveur mono-requête (1 navigateur), timeouts 60 s.
- **T-1.1.2** — Jumeau `JourneeNouveauCommercantTest.php` (MySQL `foodking_onb14`, **pas** sqlite : triggers NF525 réels) : mêmes 12 étapes par API/services, assertions DB.
- **T-1.1.3** — Preuves par le contenu : ticket (octets ESC/POS décodés), Z (`z_reports`), snapshot (`composition_snapshot`), journal (`settings_audit`), chaîne (`fiscal:verify-chain --all`).
**Acceptation** : C1..C5 sur un premier cycle (même rouge) · constats consignés au contrat.

## §3bis — Données « Chez Nadia » (contrat de fixture, `helpers/onboarding-journee.js`)
| Domaine | Valeurs (jamais un produit ou un texte Le Cayenne) |
|---|---|
| Identité | « Chez Nadia » · SIRET `73282932000074` (clé de Luhn valide) · TVA `FR32732829320` · 12 rue des Capucins, 69001 Lyon · `04 78 00 00 01` · `contact@cheznadia.test` · logo PNG neutre 512×512 · couleur `#0055FF` · horaires lun-sam 11:00-14:30 / 18:00-22:30, dimanche fermé · fermeture exceptionnelle 15 août |
| Catégories (6) | Kebabs · Burgers · Assiettes · Accompagnements · Boissons · Desserts (tri 1..6, canaux borne + caisse) |
| Articles (18) | 3 kebabs (8,50 / 9,50 / 10,50), 4 burgers (7,00 → 10,00), 3 assiettes (11,00 → 13,00), 3 accompagnements (2,50 → 3,50), 3 boissons (1,50 → 2,50), 2 desserts (2,50 / 3,00) — TVA 10 % (boissons alcoolisées : aucune) ; stations : kebabs/burgers/assiettes → `cuisine_chaude`, accompagnements → `cuisine_chaude`, boissons → `bar`, desserts → `cuisine_froide` |
| Règles de wizard (2 profils) | « Kebab Nadia » : Sauce (5 choix, `included` 1 puis 0,50 €, max 2) · Viande (3 choix, `paid` 0 €, min 1 max 1) · Boisson formule (3 choix, `paid` +2,00 €, max 1) · Suppléments (4 choix, `paid` 1,00 €, max 4) — composition témoin : 2 sauces + boisson = 8,50 + 0,50 + 2,00 = **11,00 €** ; « Burger » : Cuisson (`free`, max 1), Sauce (`included` 1) |
| Promo | code `BIENVENUE` -10 %, borne + caisse, minimum 10 €, valide 30 jours — composition témoin → **9,90 €** |
| Équipe | Gérant `gerant@cheznadia.test` · Sami (Caissier) · Léa (Cuisine) — mots de passe ≥ 12 caractères générés |
| Équipement | 1 borne « Borne entrée » (lien d'installation) · 1 imprimante « Comptoir » `127.0.0.1:9100` largeur 48 (récepteur `nc -l 9100`) · 1 TPE « Simulation » |
| Stock | matière « Pain kebab » stock 20, seuil bas 5 ; rupture jouée à l'étape E8 |
| Journée (E10) | 3 commandes : borne (Kebab Nadia composition témoin + code), comptoir (burger + boisson), téléphone (assiette) ; 1 annulation avant paiement (statut, jamais `DELETE`) |
| Attendus E11 | Z du jour = rapport = widgets = SQL : total TTC des 2 commandes payées, 1 annulée exclue ; écart de caisse 0,00 |

## §3ter — Gabarit du rapport de cycle (`CONVERGENCE_ONB14_<N>.md`)
```
# Cycle N — <date> — HEAD <sha> — état zéro restauré depuis <dump>
| Étape | Navigateur (capture lue) | Jumeau PHP | Console/réseau | Verdict |
| E0..E12 | … | … | 0 erreur / n | PASS / FAIL |
## Constats (contrat) : [P0|P1|P2|P3] <GOAL propriétaire> <file:line> — titre / reproduction / preuve / recommandation
## Chaîne NF525 : count avant / après, MAX(current_hash) — ajout seul ✔/✘
## Comparaison avec le cycle N-1 : ensembles de constats identiques ✔/✘ — P0+P1 = n
## Renvois émis (→ REGISTRE_RENVOIS_ONB14.md) : …
```

# §4 — SOUS-SYSTÈME 2 : LA BOUCLE DE CONVERGENCE

**Tâches**
- **T-2.1.1** — Cycle N : restaurer le dump état zéro → jouer les 12 étapes (navigateur + PHP) → constats `[P0..P3] file:line — titre / reproduction / preuve / recommandation` (**file:line du GOAL propriétaire**, pas d'invention) → `CONVERGENCE_ONB14_<N>.md`.
- **T-2.1.2** — Chaque constat P0/P1 → fiche de renvoi (S3) → correction par le GOAL propriétaire dans **sa** session/worktree → fusion → nouveau HEAD → cycle N+1.
- **T-2.1.3** — Arrêt : deux cycles consécutifs, ensembles de constats identiques, P0+P1 = 0 ; garde anti-flaky (un cycle différent = on reboucle).
  • livrable : `CONVERGENCE_ONB14_FINAL.md` · C6
**Acceptation** : C6 VRAI · captures des deux cycles lues.

# §5 — SOUS-SYSTÈME 3 : LE REGISTRE DES RENVOIS

**Tâches**
- **T-3.1.1** — `REGISTRE_RENVOIS_ONB14.md` : colonnes constat · sévérité · GOAL · fiche · statut (ouvert / corrigé / différé propriétaire) · preuve de clôture (commit, test, capture) · cycle de rejeu.
- **T-3.1.2** — Aucun constat ne se ferme « par disparition » : une preuve de clôture ou un différé signé.
**Acceptation** : C7 = 100 %.

# §6 — SOUS-SYSTÈME 4 : CLÔTURE DU PROGRAMME

**Tâches**
- **T-4.1.1** — `RAPPORT_FINAL_PROGRAMME.md` : 14 GOAL × statut (convergé / différé) × preuves × gates ; chiffres finaux (tests, chaîne, cycles) ; ce qui reste au propriétaire.
- **T-4.1.2** — `PROJECT_BRAIN.md` §2 (HEAD, état), §3 (résumé), §4 (suite), §6 (décisions : G0, règle par étape, IA propose/humain valide, journal distinct), §7 (nouveaux domaines prouvés) ; `SYSTEM_MAP.md` (sous-voies CENTRAL du programme) ; `CONSTITUTION.md` : ligne G0 contresignée (rien d'autre).
- **T-4.1.3** — Étiquette `v1.1.0-onboarding-commercant` et poussée : **G-PUSH** — jamais sans accord écrit.
**Acceptation** : documents commités · G-PUSH tranché.

# §S — SCÉNARIOS ADVERSES OBLIGATOIRES (la journée elle-même est adverse : chaque étape est rejouée avec ces variantes au cycle 2)
| Étape \ variante | annulation à mi-chemin | rechargement | double clic | deux écrans (borne + caisse) | rôle inférieur | données vides | volume | réseau/worker coupé | effet croisé | retour arrière |
|---|---|---|---|---|---|---|---|---|---|---|
| E1-E2 identité/carte | tiroir annulé → 0 écriture | brouillon | 1 écriture | — | Sami 403 | catégorie vide | 18 articles | — | ticket/borne | supprimer un article référencé → refus |
| E3 règles | dé-publier | conflit de version | — | — | `catalog.publish` | étape sans choix → refus | 10 étapes | — | 3 surfaces = 9,50 € | version précédente |
| E7 équipement | — | — | — | borne supprimée → 401 en < 1 s | `pos@` 403 | — | — | imprimante coupée → message FR | KDS reçoit quand même | réactiver |
| E8-E9 stock/promo | — | — | idempotent | rupture pendant un panier borne → refus au commit FR | — | — | — | worker coupé → propagation synchrone ? | devis = commit | remise en dispo |
| E10 vente | panier abandonné | borne rechargée → panier ? | double paiement refusé (idempotence) | Sami encaisse pendant que Léa bump | Léa ne peut pas encaisser | — | 3 commandes | soketi coupé → scrutation | OSS « prêt », ticket | annulation = statut, jamais `DELETE` |
| E11 clôture | — | — | Z idempotent | — | `pos-manage-fiscal` | jour sans vente | — | — | Z = rapport = widget | rapport X |

# §A — ARMÉE D'AGENTS
**QA visuel + ROUGE visuel** (rôle central : chaque étape capturée, lue, contestée) · **Jalonneur** (refuse un cycle au premier « non ») · SRE/Synchro (worker, soketi, scrutation) · Fiscal (chaîne avant/après, snapshot) · Psychologie commerçant (Nadia « joue » la journée : hésitations consignées) · Architecte (renvoi au bon propriétaire, file:line réel) · **aucun implémenteur** (les corrections se font dans les sessions propriétaires).
Disque `reports/test-e2e/ONB14_CONVERGENCE/<cycle>/wave-<étape>-<rôle>.json` ; contrat de constat avec file:line du propriétaire.

# §X — VAGUES DE CONVERGENCE
| Vague | Portée | Parallélisme | Bloquée par |
|---|---|---|---|
| **W0** | Pré-vol : base `foodking_onb14` installée par ONB-12, dump état zéro, garde d'identité 8814, worker/soketi, chaîne attestée | séquentiel | **vague C close** (01-13 fusionnés ou différés par écrit), G-DATA |
| **W1** | Scénario : données Nadia, spec, jumeau PHP (T-1.*) | séquentiel | — |
| **W2** | Cycle 1 (T-2.1.1) → registre (T-3.*) → renvois | 1 navigateur | — |
| **W3** | Corrections par les propriétaires (hors de cette session) → fusion → cycle 2 … cycle N | séquentiel | sessions propriétaires |
| **W4** | Deux cycles identiques → `CONVERGENCE_ONB14_FINAL.md` | séquentiel | — |
| **W5** | Clôture (T-4.*) | séquentiel | **G0** (ligne), **G-PUSH** |
**§X.8** 6 points · **§X.9** : un constat non corrigé après 3 rejeux → différé propriétaire écrit, jamais silencieux · **§X.10** `INTERRUPT_*` avec le numéro de cycle et l'étape.

# §G — GATES PROPRIÉTAIRE
| Gate | Description | QUI | QUOI | OÙ | Statut |
|---|---|---|---|---|---|
| **G0** | Ligne constitutionnelle (écrite ici après contreseing) | Propriétaire | contreseing | `CONSTITUTION.md §1` | EN ATTENTE — bloque W5 |
| **G-DATA** | Base dédiée `foodking_onb14` | Propriétaire | accord | `docs/gates/GATE_LOG.md` | EN ATTENTE — bloque W0 |
| **G-DIFF** | Liste des GOAL différés (non fusionnés) à l'ouverture de la vague D — chaque étape dépendante devient « documentée non prouvée » | Propriétaire | liste signée | index §2 + MISSION §6 | EN ATTENTE — bloque W0 |
| **G-PUSH** | Étiquette `v1.1.0-onboarding-commercant` + poussée | Propriétaire | accord explicite | commit + BRAIN §2 | EN ATTENTE — bloque T-4.1.3 |

# §R — RÉFÉRENCES
`test-e2e` · `verify-before-report` · `ultra-architect-planify` (Axe 10, étape 6) · `docs/PLAYWRIGHT_MCP_OPS.md §7` · `SYNC_CONTRACT.md` · `CLAUDE.md §3ter, §6, §8, §13` · `plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md §7` · `_FICHES_GOAL.md` (ONB-14) · tous les `MISSION_ONB*.md §8` (fiches) ·
`tests/e2e/boucle-quotidienne.spec.js` · `tests/Feature/BoucleQuotidienneTest.php` · `tests/Playwright/global-setup.js` · `tests/e2e/helpers/*` · `reports/audit/VAGUE_D_CAUSES_REELLES_2026-08-25.md` · `plans/GOAL_CONSOLIDATION_V1_PRODUCTION_2026-08-25.md` (S4 harnais, W7 convergence).

# §F — RÈGLE FINALE
Ce GOAL — et le programme — est **TERMINÉ** quand et seulement quand : 1. C1..C7 VRAIS ; 2. **deux cycles consécutifs aux constats identiques, P0+P1 = 0** ; 3. 0 fichier produit édité par cette session ; 4. chaîne NF525 de `foodking_onb14` attestée en ajout seul à chaque cycle, PHPUnit ≥ 5 194 + tests du programme, Vitest ≥ 3 644, diff gelé 0 sur tout le programme hors LOCK contresignés ; 5. registre des renvois 100 % clos ou différé ; 6. BRAIN / SYSTEM_MAP / CONSTITUTION (G0) vrais ; 7. G-PUSH tranché.
**Interdit** : corriger un fichier produit ici · utiliser la base partagée ou `:8766` · supprimer une commande · inventer un sélecteur · déclarer convergé sur un seul cycle · pousser sans G-PUSH.
> Le sens : le 1er jour de « Chez Nadia », la borne vend sa carte à ses prix, la cuisine voit son kebab au bon poste, le ticket porte son SIRET, le Z et le rapport disent le même nombre — et personne n'a ouvert un fichier.
