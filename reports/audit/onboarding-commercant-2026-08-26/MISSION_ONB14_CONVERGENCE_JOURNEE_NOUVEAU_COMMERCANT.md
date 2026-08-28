# MISSION ONB-14 — CONVERGENCE « JOURNÉE D'UN NOUVEAU COMMERÇANT » · Rapport de mission
- GOAL : `plans/GOAL_ONB14_CONVERGENCE_JOURNEE_NOUVEAU_COMMERCANT_2026-08-26.md` · Index : `plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md`
- État des lieux daté du **2026-08-26** — **dernier GOAL (vague D)** : ne démarre qu'après la vague C
- Port : **8814** · Base **dédiée** `foodking_onb14` · Voie : TRANSVERSE, **aucun code produit**

## 0. COMMENT LANCER
```
Tu es le chef de mission du GOAL ONB-14 (convergence : journée d'un nouveau commerçant). AVANT TOUT : vérifie dans plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md
et PROJECT_BRAIN.md §2 que la vague C est close (ONB-01..13 fusionnés dans HEAD, ou différés par écrit — gate G-DIFF) ; sinon STOP. Lis : CONSTITUTION.md, CLAUDE.md §3ter,
§6, §8, §13, SYNC_CONTRACT.md, docs/PLAYWRIGHT_MCP_OPS.md §7, PROJECT_BRAIN.md §2, l'index (§2, §3, §5, §7), reports/audit/onboarding-commercant-2026-08-26/
MISSION_ONB14_CONVERGENCE_JOURNEE_NOUVEAU_COMMERCANT.md, plans/GOAL_ONB14_CONVERGENCE_JOURNEE_NOUVEAU_COMMERCANT_2026-08-26.md, tests/e2e/boucle-quotidienne.spec.js,
tests/Feature/BoucleQuotidienneTest.php, tests/Playwright/global-setup.js, tests/e2e/helpers/*, tous les MISSION_ONB*.md §8 (fiches ouvertes). Pré-vol §0.1 : worktree
.claude/worktrees/onb14-convergence depuis le HEAD de fin de vague C, .env avec APP_URL=http://127.0.0.1:8814 ET DB_DATABASE=foodking_onb14 (créée vide, installée par
`php artisan foodking:installer --etablissement="Chez Nadia"` de ONB-12, dump état zéro), garde d'identité Playwright autorisant 8814 (1 ligne, déclarée), worker dédié,
soketi ou repli scrutation, chaîne NF525 attestée. ⛔ Aucun fichier produit modifié ici ; jamais la base partagée ni :8766 ; jamais une commande supprimée ; jamais un
sélecteur inventé. Puis « lance le GOAL » : W1 (données Nadia + spec + jumeau PHP MySQL) → W2 cycle 1 → registre des renvois → corrections par les sessions
propriétaires → cycles suivants → deux cycles identiques → clôture (BRAIN, SYSTEM_MAP, ligne G0 contresignée, G-PUSH). QA visuel + ROUGE visuel + Jalonneur + Fiscal ;
aucun implémenteur. Compte rendu : FIXÉ (rien ici) / VÉRIFIÉ (12 étapes × 2 cycles) / BLOQUÉ (renvois ouverts).
```

## 1. CONTEXTE ET VISION
Le programme existe pour une preuve : un établissement **qui n'est pas Le Cayenne** se règle, vend, cuisine, encaisse, clôture et lit ses chiffres, sans développeur. Ce GOAL joue cette
journée sur une installation vierge, deux fois à l'identique, et renvoie chaque échec à son propriétaire. Il clôt le programme (rapport final, BRAIN, CONSTITUTION si G0, étiquette).

## 2. ÉTAT CONNU LE 2026-08-26
| Fait | Preuve |
|---|---|
| Boucle quotidienne **Le Cayenne** verte : `tests/e2e/boucle-quotidienne.spec.js` 4/4 (navigateur réel) + `tests/Feature/BoucleQuotidienneTest.php` L0-L7 + L5bis (5 canaux) | BRAIN §4 (15/08) |
| Garde d'identité Playwright : `:8000` rejeté, `:8766` accepté (`global-setup.js:64-113`) ; comptes `:126-151` | code |
| Helpers réutilisables : `admin-auth.js` (`loginAdmin`, `X-API-KEY`), `login.js` (`#formEmail`, `#formPassword`, `loginAsPosOperator`, `loginAsChefOperator`, `loginAsKiosk`), `kiosk-auth.js`, `kiosk-order.js` (`resolveSimpleOrderableItem`), `place-order.js`, `idempotency-key.js`, `process-audit.js`, `sync-journey-trace.js`, `central-management-selectors.js` | `ls tests/e2e/helpers` (17) |
| Dette de dérive du harnais : sélecteurs morts 23, routes mortes 1, idempotence 0, specs KDS V1 14 (cliquets) | BRAIN 25/08 |
| Pièges d'instrument : `reducedMotion` inerte, `F1-F12` inertes, produit inexistant, serveur mono-requête, `:8000` ≠ `:8766` | `docs/PLAYWRIGHT_MCP_OPS.md §7`, CLAUDE.md §3ter |
| Aucune journée « autre établissement » jamais jouée ; installation vierge inexistante avant ONB-12 | Z0 §8 |

## 3. CE QUI A DÉJÀ ÉTÉ FAIT
- 2026-08-15 V3 : boucle quotidienne + jumeau PHP + purge de 91 specs vacantes + sentinelle `noVacuousSpecSentinel` ; V7 convergence (diff gelé 0, chaîne OK).
- 2026-08-25 : vagues D/F E2E rejouées, causes réelles documentées (`VAGUE_D_CAUSES_REELLES_2026-08-25.md`), 18 specs sans `X-Idempotency-Key` corrigées, garde d'identité posée.
- Règle de convergence (`test-e2e`) : deux cycles consécutifs aux constats identiques.

## 4. ANCRAGES CODE (lecture)
| Rôle | Fichier | Note |
|---|---|---|
| Modèle de journée | `tests/e2e/boucle-quotidienne.spec.js`, `tests/Feature/BoucleQuotidienneTest.php` | à ne pas modifier |
| Harnais | `tests/Playwright/global-setup.js:64-113,126-151` · `playwright.config.js:12-41` (`PLAYWRIGHT_BASE_URL`) | 1 ligne déclarée pour 8814 |
| Helpers | `tests/e2e/helpers/{admin-auth,login,kiosk-auth,kiosk-order,place-order,idempotency-key,process-audit,sync-journey-trace,central-management-selectors}.js` | réutilisés tels quels |
| Fiscal | `php artisan fiscal:verify-chain --all` · `z_reports`, `audit_logs` · `FiscalInstallImmutabilityTriggersCommand` (installation) | MySQL requis |
| Impression | récepteur `nc -l 9100` ; `OrderReceiptEscPosRenderer` (lecture) | preuve par les octets |
| À créer | `tests/e2e/helpers/onboarding-journee.js`, `tests/e2e/onboarding-journee-nouveau-commercant.spec.js`, `tests/Feature/Onboarding/JourneeNouveauCommercantTest.php`, `CONVERGENCE_ONB14_<n>.md`, `REGISTRE_RENVOIS_ONB14.md`, `RAPPORT_FINAL_PROGRAMME.md` | |

## 5. BASES CHIFFRÉES
À l'ouverture de la vague D (à figer W0) : PHPUnit / Vitest / chaîne NF525 de `foodking_e2e` (référence) ; état zéro de `foodking_onb14` (dump) ; liste des GOAL fusionnés / différés (G-DIFF).

## 6. DÉCISIONS PROPRIÉTAIRE EN ATTENTE
| Gate | Question | Recommandation | Si non tranché |
|---|---|---|---|
| G-DIFF | Quels GOAL sont différés à l'ouverture de la vague D ? (chaque étape dépendante devient « documentée non prouvée ») | liste explicite | W0 bloquée |
| G-DATA | Base dédiée `foodking_onb14` | oui | W0 bloquée |
| G0 | Ligne constitutionnelle (écrite ici après contreseing) | oui | clôture partielle |
| G-PUSH | Étiquette + poussée | après deux cycles identiques | pas de poussée |

## 7. RISQUES, PIÈGES, INSTRUMENTS
- Un seul cycle vert ne vaut rien (garde anti-flaky) ; deux cycles aux constats **différents** = non convergé.
- Les corrections se font dans les sessions propriétaires : ce GOAL attend, fusionne, rejoue — jamais de « petit fix » ici.
- Chaque commande de la journée est réelle sur `foodking_onb14` : rejouer = restaurer le dump état zéro, pas supprimer.
- Sélecteurs : uniquement des `data-testid` existants (23 sélecteurs morts en mémoire) ; sinon fiche au propriétaire.
- `reducedMotion` → `page.emulateMedia()` ; pas de `keyboard.press('F1')` ; 1 navigateur ; timeouts 60 s ; `resolveSimpleOrderableItem` pour tout article.
- KDS V2 par défaut : `data-kds-order-card` (V1) est mort — viser les sélecteurs V2 (`VAGUE_D_CAUSES_REELLES_2026-08-25.md`).
- `:8000` = autre worktree ; `:8766` = arbre principal ; ta session = **:8814**, base **`foodking_onb14`**.

## 8. JOURNAL DE MISSION (rempli par la session)

### 8.1 ÉTAT : **BLOQUÉ** — dépend d'ONB-12, lui-même bloqué par G0

Cette mission demande un parcours de bout en bout — **installation vierge** → identité
→ catalogue → wizard → équipe → équipement → commande borne → KDS → encaissement → Z →
rapports, deux cycles identiques.

Son premier mot est « installation vierge », et c'est précisément ce qu'ONB-12 ne peut
pas produire tant que **G0** n'est pas signé. Lancer la convergence sur une base qui
contient le menu, les rôles et les résidus de tests de Le Cayenne ne prouverait pas ce
que cette mission existe pour prouver.

`§14` du programme interdit de la différer en la vidant de sa substance : mieux vaut
la dire bloquée que la déclarer verte sur un parcours qui n'est pas le sien.

### 8.2 Ce qui est déjà prouvé, et qui servira le jour venu

Le parcours n'est pas prouvé **de bout en bout**, mais plusieurs de ses maillons le
sont désormais individuellement, avec des bancs qui mordent :

| Étape | Preuve |
|---|---|
| Identité fiscale enregistrable, relue, **non effacée** au second enregistrement, et complète (N° de caisse) | `IdentiteFiscaleSurvitAUnSecondEnregistrementTest`, `leFormulaireFilialePorteLIdentiteFiscaleComplete.spec.js` |
| Catalogue depuis zéro : import aller-retour, catégories, allergènes, canaux | `LaCarteExporteeSeReimporteTest`, `LesCategoriesExporteesSeReimportentTest`, `UnCommercantPeutDeclarerSesAllergenesTest` |
| Ingrédients déclarables | `UnCommercantPeutDeclarerSesIngredientsTest` |
| Équipe : téléphone obligatoire dit comme tel, pas de boucle de redirection | `UnChampObligatoireEnBaseLEstAussiDansLaRegleTest`, `aucuneBoucleDeRedirectionSurUnRoleSansDroit.spec.js` |
| Équipement : l'écran ne propose plus une largeur que le serveur refuse | `LEcranNeProposePasCeQueLeServeurRefuseTest` |
| Réception de factures : conversion d'unités juste, refus lisible | `UneFactureEnKilosNeCreditePasDesGrammesTest`, `LeRefusDeReceptionEstLisibleParLeCommercantTest` |
| Rapports : écran et export s'accordent, prédicat appelé et non recopié | `LEcranEtLExportDonnentLeMemeTotalTest`, `LesChiffresDesRapportsSontJustesTest` |

### 8.3 Ce qui manque encore au parcours, indépendamment de G0

- **Les horaires d'ouverture n'existent nulle part** — ni table, ni route, ni écran
  (ONB-01). Un établissement ne peut pas déclarer quand il ouvre.
- **Les frais de livraison ne sont configurables nulle part**, alors que
  `DeliveryFeeService` les lit.
- **L'imprimante réelle reste indéclarable** (adresse LAN refusée) et **la borne
  déclarée n'est pas celle qui se connecte** (ONB-10).
- **Le wizard de catégorie n'est appliqué nulle part** (ONB-03, gelé).

Ces quatre-là bloqueraient la convergence même avec G0 signé. Les nommer maintenant
évite de découvrir le mur en fin de parcours.

**État final ONB-14 : BLOQUÉ, en cascade. Sept maillons du parcours sont désormais prouvés individuellement ; quatre manques structurels sont identifiés à l'avance, dont deux exigent du neuf et un une signature.**
