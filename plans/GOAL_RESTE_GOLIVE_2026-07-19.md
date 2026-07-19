# GOAL — Ce qui RESTE vers l'ouverture réelle (go-live hardening) · 2026-07-19

> **Ultra-plan du reste**, ancré dans l'état réel (règle anti-fiction). Le déploiement staging
> est FAIT et validé (backend VPS `19d2bf8e` + web Vercel `7149d70`, money-path 0 drop, honnêteté
> nettoyée, CORS durci). Ce GOAL couvre le passage **staging validé → ouverture réelle**.
> Owner a dit : « les clés API en dernier ». Ce plan met donc les secrets à la fin (Vagues 5-6).

## §0 — Préambule
- **Scope** : tout ce qui sépare « ça marche en test » de « on ouvre avec de vrais clients + vrai TPE ».
- **NE PAS refaire** : ce plan ne duplique pas les docs existants — il les ORCHESTRE :
  - `docs/FINAL_SECURITY_PHASE_CHECKLIST.md` (check-list unique go-live, §A-E)
  - `docs/HANDOVER_SECRETS_REGISTRY.md` (secrets S1-S11 : noms/emplacements/rôles/action)
  - `plans/GOAL_REMEDIATION_3_POINTS_STAGING_2026-07-15.md` §A (garde-fous chaîne NF525)
- **Barrière de convergence unique** : `APP_ENV=production php artisan app:preflight-production --strict`
  passe VERT (exit 0). Ce gate re-vérifie chaîne NF525 + secrets faibles + boot vars
  (`app/Console/Commands/PreflightProductionCommand.php` : 20+ checks, l.46-66).
- **Working tree** : propre (tout commité/poussé). Rien à stasher.
- **Discipline** : NF525 §10 — rien d'irréversible sur la chaîne sans gel des writers + dump immuable
  d'abord. Frozen zones intouchables sans LOCK. Secrets JAMAIS commités.

## §1 — Carte d'état (fait vs reste)
| Domaine | État | Preuve / ancre |
|---|---|---|
| Deploy backend+web | ✅ LIVE staging | `19d2bf8e` / `7149d70`, ce cycle |
| Money-path 0 drop (tous produits) | ✅ prouvé | order #171 + audit composables |
| Honnêteté web | ✅ nettoyé | trophées/fausses cmd/perks/créneaux |
| CORS + deploy durci | ✅ | `deploy-lecayenne.sh` FRONTEND_WEB_DOMAIN+smoke |
| Cross-surface caisse (data) | ✅ prouvé requête | `/web-orders/pending` → #171 |
| **Cross-surface caisse (UI)** | ⏳ en cours | besoin login admin owner (Vague 0) |
| **Purge data test** | ❌ reste | REGISTRE_FINAL P2-j/n (186 cmd + Faker) |
| **Chaîne NF525 (TAMPER historique)** | ❌ reste | verify-chain VPS = TAMPER 1/1 branche |
| **Secrets forts** | ❌ reste (owner) | HANDOVER §1 S1-S11 |
| **Trou borne kiosk123** | ❌ reste (owner) | HANDOVER §2, seeder l.50 |
| **Boot prod (TPE réel, cron, soketi)** | ❌ reste | preflight checkPosSimulationHardware… |
| **Décisions produit/fiscal** | ❌ reste (owner) | REGISTRE_FINAL l.76 |

---

## §2 — Vagues d'exécution

### Vague 0 — Vérif visuelle cross-surface caisse/KDS (en cours)
**But** : voir la commande web dans l'UI caisse → Accepter → KDS → suivi client.
**Gate** : login admin VPS (OWNER — saisie mdp interdite côté agent).
**Étapes** (une fois connecté) : panneau « Commandes web » → carte #190726171 → Détail (Tacos L + 2 sauces)
→ Accepter → bascule cuisine (`/kds`) → écran suivi client passe Reçu→Cuisine.
**Acceptance** : capture écran caisse montrant #190726171 ; KDS montrant le ticket ; 0 erreur console.
**Note** : déjà prouvé au niveau requête (`web-orders.pending` renvoie #171) — cette vague = preuve VISUELLE.

### Vague 1 — Hygiène data (purge test) · Claude prépare, owner gate la purge prod
**Ancre** : `REGISTRE_FINAL.md` P2-j (items/cats Faker `RJ-Dcat*`, « Aliquam » ACTIFS éligibles borne/upsell)
+ P2-n (commandes/zombies test) + item vu ce cycle `CENTRAL-CAT-VIS Burger Test` (id 83) + order test #171.
**Tasks** :
- T1.1 Claude : script de DIAGNOSTIC read-only listant les enregistrements de test (items Faker, cats test,
  commandes de test non-fiscalisées) — AUCUNE suppression. Sortie = inventaire chiffré.
  → test : `(à créer) tests/Feature/Data/TestDataInventoryTest.php` OU commande `foodking:audit-test-data --dry-run`.
- T1.2 OWNER GATE : valider la liste, puis Claude exécute la purge des NON-fiscalisés uniquement
  (⛔ jamais toucher un enregistrement à `fiscal_sequence_no` non-null = NF525 → passe par Vague 2).
**Acceptance** : borne/upsell ne proposent plus d'items de test ; `preflight checkMenuCount` cohérent (45 items).
**Checkpoint** : frozen diff 0 ; chaîne NF525 inchangée (purge ne touche aucun fiscalisé).

### Vague 2 — Chaîne NF525 : repartir propre (Workstream A) · **HUMAN GATE lourd**
**Ancre racine** (`REGISTRE_FINAL.md:12`) : `next()=MAX(fiscal_sequence_no)+1` sur `orders` (table supprimable) ;
les triggers d'immutabilité couvraient audit_logs/z_reports/order_payments mais **PAS `orders`** →
réutilisation de séquence après delete (`seq 2579` revendiqué par 6 orders, `2068` par 5…). TAMPER gravé.
**Déjà fait ce mois** : trigger `orders_no_delete_when_fiscalized` DÉPLOYÉ (10/10 triggers sur le VPS) →
**bloque toute réutilisation FUTURE**. Reste le **TAMPER HISTORIQUE** (données de test pré-trigger).
**Tasks** (garde-fous : `GOAL_REMEDIATION_3_POINTS_STAGING_2026-07-15.md` §A) :
- T2.1 Diagnostic read-only : `fiscal:verify-chain --all` multi-row + `fiscal:verify-immutability-triggers`
  + inventaire des seq dupliquées. (Claude, read-only.)
- T2.2 OWNER GATE (décision) : le staging actuel garde-t-il l'historique de test (accepté, 0 client réel) OU
  repart-on sur une **DB fiscale PROPRE** au moment du vrai go-live ? Recommandation : **DB fiscale neuve au
  go-live** (les seq de test n'ont aucune valeur légale ; S1/S2 posés au 1er boot d'une chaîne propre).
- T2.3 Si « repartir propre » : procédure = geler les writers → dump immuable → initialiser une chaîne
  vierge avec S1/S2 forts → 1er order réel = seq 1. (⛔ INTERDIT : UPDATE l'historique, TRUNCATE/DROP la
  chaîne vivante, `APP_ENV=local` pour bypass — cf. checklist §C l.54.)
**Acceptance** : `checkFiscalVerifyChain` + `checkFiscalChainIntact` VERTS au preflight (sur la DB de prod).
**Checkpoint** : escalade humaine obligatoire AVANT toute action ; monotone-counter dédié envisagé (remplacer
MAX+1 par table séquence) — à décider avec l'owner (le trigger suffit-il, ou veut-on la ceinture+bretelles ?).

### Vague 3 — Topologie de service VPS (`checklist §D`)
**But** : que rien ne meure au reboot / sous charge.
**Tasks** (Claude diagnostique, owner valide les changements système) :
- T3.1 Vérifier php-fpm + nginx + **soketi (temps-réel)** + **worker queue** + **scheduler cron** tournent
  sous un process manager (systemd/supervisor) et survivent au reboot. (`preflight checkSchedulerInstalled`,
  `checkQueueConnection`, `checkBroadcastDriver`.)
- T3.2 Poser les vraies clés temps-réel S9 (Pusher/soketi) → `checkBroadcastDriver` ≠ null.
**Acceptance** : reboot simulé → tous services remontent ; un event borne→KDS passe en <2 s.

### Vague 4 — Fermer le trou borne (`checklist §B`, HANDOVER §2) · owner pose le mdp
**Ancre** : `kiosk123` PUBLIC (`database/seeders/KioskMachineTableSeeder.php:50`), API `/api/auth/kiosk-login`
joignable toute IP avec x-api-key public → robinet à tokens `kiosk:order`.
**Tasks** :
- T4.1 `php artisan foodking:ensure-kiosk-machine --username=kiosk-lecayenne --password=<FORT> --branch-id=1 --force`
- T4.2 **Révoquer les tokens vivants** (`admin/kiosk-machine/logout/{id}`) — une rotation seule les laisse 8 h.
- T4.3 Choisir le chemin auto-login S7 (secret hex OU IP/CIDR borne — **jamais** une IP de LB).
**Acceptance** : `kiosk123` ne s'authentifie plus ; borne réelle re-login OK avec le nouveau secret.

### Vague 5 — Secrets forts (`checklist §A`, HANDOVER §1 S1-S11) · **owner pose les valeurs**
> Owner : « les api en dernier » → cette vague vient tard.
**Tasks** (Claude prépare commandes+runbook ; OWNER génère/pose les valeurs hors-repo) :
- S1/S2 `FISCAL_AUDIT_SECRET` / `FISCAL_Z_REPORT_SECRET` : `openssl rand -hex 32`, posés au 1er boot de la
  chaîne propre (Vague 2). S3 `APP_KEY` : `key:generate` une fois. S4 `DB_PASSWORD` fort (compte sans DROP).
- **S11 `API_KEY`** (le `change-me-…-local-dev` faible d'aujourd'hui) : clé forte, alignée **des DEUX côtés**
  (VPS `.env` + `index.html` meta `api-key` web) → redeploy web. (C'est un identifiant client public, donc
  la mitigation = clé non-devinable + rate-limit, pas un secret d'autorisation — cf. HANDOVER S11.)
- **S10 clés SMS** : poser le provider → OTP par vrai SMS (aujourd'hui lu en table `otps`).
**Acceptance** : `preflight checkFiscalSecrets` VERT (aucune sentinelle/valeur faible détectée).

### Vague 6 — Décisions produit/fiscal (`REGISTRE_FINAL:76`) · **owner tranche**
Chaque item = une décision, pas du code d'abord :
- **Boissons TVA 10 % vs 5,5 %** (décision fiscale) — le menu est en 10 % partout ; confirmer le taux légal
  des boissons (sur place vs à emporter) avec le comptable.
- **Auto-accept web COD** — aujourd'hui la commande web attend l'accept caisse (Plan B). Garder le filet
  manuel OU auto-accepter à emporter ? (impacte le flux cuisine.)
- **POS_SIMULATION_HARDWARE=false** — bascule au branchement du **vrai TPE** (`checkPosSimulationHardware`
  CRITICAL en prod). Jusque-là on reste en simu (mandat owner).
- Backlog frozen/archi (non bloquant ouverture) : P2-c ZReport TVA livraison, P2-r FrontendOrder/Order,
  P2-d FK tables fiscales — chacun sous LOCK si touché.

### Vague 7 — Gate final (`checklist §E`)
- `APP_ENV=production php artisan app:preflight-production --strict` → **exit 0**.
- E2e complet sur config prod : borne→KDS→OSS, web→caisse→KDS→suivi, encaissement, clôture Z.
- Tag `v1.0.0-golive-ready` + `deploy-lecayenne.sh` sur la SHA relue.

### Vague 8 (optionnelle, non bloquante) — Polish
Code mort `account-v2.jsx` (forgot/socialNotice) · WS 86 INSTANTANÉ (soketi public, le polling assure déjà) ·
suppression order test #171 (via caisse, Vague 0).

---

## §G — Owner gates (WHO / WHAT / WHERE)
| Gate | Description | WHO | WHAT (artefact) | WHERE | Vague |
|---|---|---|---|---|---|
| G0 | Login admin VPS pour le tour | Owner (physique) | session admin ouverte | navigateur | 0 |
| G1 | Valider la purge data test | Owner | liste validée | inventaire T1.1 | 1 |
| G2 | Repartir sur chaîne fiscale propre ? | Owner (décision) | go/no-go + timing | ce doc §2 V2 | 2 |
| G3 | Mot de passe borne fort | Owner | valeur (hors-repo) | secret manager / `.env` | 4 |
| G4 | Valeurs des secrets S1-S11 | Owner | valeurs fortes | secret manager | 5 |
| G5 | Taux TVA boissons + auto-accept web | Owner (+ comptable) | décisions | §2 V6 | 6 |
| G6 | Branchement vrai TPE | Owner (physique) | TPE câblé banque | box resto | 6 |
| G7 | Go/no-go ouverture | Owner | preflight vert + signature | tag `v1.0.0` | 7 |

## §R — Références (ne pas dupliquer)
`docs/FINAL_SECURITY_PHASE_CHECKLIST.md` · `docs/HANDOVER_SECRETS_REGISTRY.md` ·
`plans/GOAL_REMEDIATION_3_POINTS_STAGING_2026-07-15.md` §A · `reports/goal-intelligence-2026-07-18/REGISTRE_FINAL.md` ·
`app/Console/Commands/PreflightProductionCommand.php` · `CLAUDE.md` §7 (frozen) §8 (NF525) §10 (gates).

## §F — Definition of Done
Ouverture réelle possible quand : preflight `--strict` VERT · secrets forts posés (hors-repo, anciens archivés) ·
trou borne fermé · chaîne NF525 vérifiable+intègre sur la DB de prod · vrai TPE câblé (ou simu assumée
documentée) · services survivent au reboot · e2e prod complet vert · décisions fiscales tranchées.
**Ce que Claude peut faire seul** : Vagues 0 (guidage), 1 (diagnostic), 2.1 (diagnostic), 3.1 (diagnostic),
4 (exécution sur mdp fourni), 5 (commandes/runbook), 7 (preflight+e2e). **Ce qui exige l'owner** : G0-G7.
