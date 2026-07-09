# 🎯 GOAL FABLE 5 — v2 : RE-VALIDATION ABSOLUE 10× + profondeur sur le non-couvert

> Tu es **Fable 5**, développeur senior ultra-fort sécurité/analyse/orchestration multi-agents.
> **Ceci est une v2** : un premier ultra-audit a déjà convergé (11/11 systèmes GREEN, suite 12→0, NF525
> OK, frozen 0). **Ta mission n'est PAS de refaire — c'est de NE PAS le croire sur parole** : re-valider
> INDÉPENDAMMENT ce qui est déclaré vert (jusqu'à **10× sous 10 angles**), et aller **PLUS PROFOND** là où
> la couverture a été légère. Tourne des jours s'il le faut. Ne clôs rien de partiel. Zéro doublage.

## §0 — LECTURE OBLIGATOIRE (chaîne, dans l'ordre)
1. Le plan v1 : `reports/handoff/GOAL_FABLE5_ULTRA_AUDIT_SYSTEME_PAR_SYSTEME_2026-07-02.md` — **disciplines,
   11 systèmes, méthodologie, orchestration, barre 1000 %, protocole e2e. TOUT reste valable, applique-le.**
2. `CONSTITUTION.md` + `CLAUDE.md` + `PROJECT_BRAIN.md §2` + `SYSTEM_MAP.md` + `SYNC_CONTRACT.md` + `PARALLEL_PROTOCOL.md`.
3. Mémoire longue `.claude/.../memory/MEMORY.md` + topics 2026-07.
4. **Les 2 audits déjà faits + leurs triages** (ton point de départ à CHALLENGER) :
   - `reports/ultra-review/2026-07-02/RAPPORT_ULTRA_REVIEW_COMPLET_2026-07-02.md` + `verify-findings.json`
   - `reports/ultra-review/2026-07-02/RAPPORT_VALIDATION_SYSTEME_PAR_SYSTEME_2026-07-02.md` + `HEAL_TRIAGE_2026-07-02.md`
   - Mon triage : `memory/triage_ultra_review_fable_plan_2026-07-02.md` (j'ai rattrapé un fix loyalty
     incomplet + 2 bugs Uber que le 1er audit avait ratés — **preuve que « GREEN » ne suffit pas**).

## §1 — DIRECTION IMMUABLE (ne PAS dévier — cf. v1 §1)
V1 LOCAL Le Cayenne, mono-poste, FR, 1 branche, TPE simulé assumé, 0 cloud. Auditer/valider, PAS redéfinir.

## §2 — DISCIPLINES (cf. v1 §2 — inchangées)
Verify-before-report (repro live) · adversaire refute-by-default · frozen §7 (LOCK+gate) · NF525 ·
**ZÉRO DOUBLAGE** · scope-minimal · evidence obligatoire. **AJOUT v2 : re-baseline AVANT tout** (une
session parallèle corrige en même temps ; ne JAMAIS re-corriger ce qui est déjà healé — re-vérifier, pas dupliquer).

## §3 — ÉTAT VÉRIFIÉ ACTUEL (ton point de départ, déjà cross-validé par moi)
- **Branche** `pos/category-first-caisse-2026-06-23`, **HEAD `61e9ea7b7`** (mes commits poussés).
- **Heals du 1er audit = NON committés dans le working-tree** (+ reliquats d'autres sessions + suppressions
  d'artefacts). Un `git add` scopé existe (cf. HEAL_TRIAGE). **Re-baseline d'abord** pour voir l'état réel.
- **Déjà corrigé + testé (NE PAS refaire)** : fuite loyalty PII (email **ET** phone, `wasRecentlyCreated`),
  webhook Uber (503+retry, dédup `transaction_id='uber:<id>'`), runbooks `queue:work --queue=high,default`,
  file d'encaissement robuste, impression ticket au pont, montant carte, page encaissement unifiée,
  12 échecs de tests healés (KioskQuote/F00x/WithoutGlobalScopes/Idempotency/VHtml/TpeSim/XReport).
- **Verdict des 2 audits** : GO V1 LOCAL, 0 P0/P1, blocage = **déploiement** (VPS + worker high), pas le code.

## §4 — CE QUE TU DOIS RE-VALIDER 10× (ne pas croire le « GREEN »)
Pour CHAQUE système A→K (cf. v1 §3), applique la boucle v1 §4, MAIS avec un **panel de re-validation à
10 angles** — une fonctionnalité n'est « 1000 % » que si elle résiste aux 10 :
1. correctness nominale · 2. chemin d'ÉCHEC (le + gros angle mort — cf. loyalty/Uber ratés) ·
3. sécurité/abus/énumération/PII · 4. concurrence/double-clic/idempotence · 5. intégrité data/NF525 ·
6. intersection cross-système · 7. reproductibilité (2+ runs identiques) · 8. dégradation (worker off,
pont off, Echo off) · 9. UI/UX (capture analysée) · 10. zéro-doublage (la fonction existe-t-elle 2×?).
> Si UN seul angle n'est pas couvert → **NON validé, reboucle**. Les critiques (paiement/fiscal/prix/sync/
> sécurité) exigent les 10 angles verts + repro stable + evidence.

## §5 — OÙ ALLER PLUS PROFOND (couverture légère dans les 2 audits)
Priorise ces zones sous-auditées (les audits précédents les ont effleurées ou déclarées « dormantes ») :
- **Site web standalone** (`/Users/1millnonstop/Downloads/web/` — React CDN, `api.js` câblé caisse) : flux
  guest-OTP, commandes réelles, images, promo, historique, fidélité — **e2e navigateur complet**.
- **Application mobile RN** (`mobile/`) : état réel, wireup, parité data (si testable).
- **Fidélité bout-en-bout** : register (public, corrigé) / check (auth) / OTP / points / redeem / Z-report —
  tous les chemins d'abus + la cohérence des points cross-surface.
- **Uber Eats go-live gates** (fondation inactive) : webhook (503/dédup corrigés — RE-vérifier), mapping
  menu (`config/uber_menu_map.php` vide → fallback LIKE risqué), monitoring `webhook_events failed`.
- **Angles morts dormants** (flag Fable, non couverts) : dine-in QR table, cash livreur, exports Excel,
  cron clôture-Z auto, legacy `/install` & `/payment`.
- **Intersections** (l'angle mort structurel) : borne→caisse→KDS→OSS→encaissement→fiscal ; ticket==écran
  (client ET cuisine) ; data d'affichage cohérente cross-surface ; **aucun doublage de fonctionnalité**.

## §6 — LES ANGLES MORTS PROUVÉS À NE PAS REPRODUIRE
1. **« GREEN » ≠ correct** : le 1er audit a déclaré le webhook Uber « bon pattern » sans auditer l'échec
   (200→commande payée perdue) ni l'idempotence (doublon) → 2 bugs réels ratés. Un fix loyalty « moitié de
   classe » (email sans phone) a laissé le vecteur principal ouvert. → **Toujours auditer les chemins
   d'échec + l'idempotence + TOUTES les branches d'un endpoint, pas le happy-path.**
2. **`kds_station` n'existe PAS** (colonne absente de `orders`) — 4× cité à tort. Le KDS filtre par
   `status∈{4,7,8}` ET `payment_status∈{5,15}` ET branche. KDS vide = routing/config/déploiement.
3. **Faux positifs** : re-prouve avant de reporter (le « Z-report omission » était faux — bucket Sans-TPE).

## §7 — DATA / CONVENTIONS (pour opérer)
- Serveur local `127.0.0.1:8766`. Login navigateur : `#formEmail`/`#formPassword`/`button[type=submit]`
  (interactions NATIVES). admin/pos mdp **`123456`** sur `foodking_e2e`. HTTP : header `x-api-key`=`config('app.api_key')`.
- DB test `foodking_e2e`, 45 items V1 (SSOT — ne JAMAIS inventer de produit). Cayenne+burgers = recette
  fixe ; multi-viandes = Méga/Terminator/Tacos L. Nettoyage tests : `Order::where(payment_status,15)->whereNull(fiscal_sequence_no)->delete()`.
- Config borne (KDS/encaissement) : `.env` VPS `KIOSK_MACHINE_USERNAME/PASSWORD` + `KIOSK_AUTO_LOGIN_SECRET`
  (== `?machine_key=` de l'URL) + `KIOSK_PAYMENT_ROUTE_ALL_TO_COUNTER=true` + `POS_WALKIN_ROUTE_TO_COUNTER=false   # ⚠️ CORRIGÉ 02-07 : FALSE — la caisse paie INLINE (déjà encaissée). true forçait TOUTE commande caisse dans « à encaisser » (bug owner). Seule la BORNE va en à-encaisser (via KIOSK_PAYMENT_ROUTE_ALL_TO_COUNTER).`.
- Suite : `php artisan test <fichier>` (un fichier à la fois est fiable). Gates : NF525 `fiscal:verify-chain --all`,
  frozen `FrozenZoneSha256BaselineSentinelTest`.

## §8 — ORCHESTRATION (max intelligence + surveillance — cf. v1 §8)
Fan-out massif par système × 10 angles ; panel adversaire ≥3 réfuteurs à lentilles distinctes ; **2
analystes de capture par surface (technique + UI/UX)** ; critic de complétude en fin de système ;
loop-until-dry (K tours sans finding réel). Journalise tout plafond (pas de troncature silencieuse).

## §9 — DÉLIVRABLES + BARRE (cf. v1 §9/§10)
Sous `reports/fable5-v2/<date>/` : structure + décomposition fonctionnelle + statut par fonctionnalité
(**nb d'angles sur 10 + nb de passes**) + findings (survivors/réfutés) + fix-log + registre anti-doublage
+ captures analysées + verdict GO/NO-GO par système + global. Mets à jour BRAIN + mémoire à chaque
convergence. Rien de committé sans gate owner (frozen) ; le reste scope-minimal, jamais de doublon.

## §10 — TEST-E2E EN BOUCLE JUSQU'À VALIDATION ABSOLUE
Chaîne de référence à re-prouver à CHAQUE cycle, sur borne ET caisse, espèces ET carte :
commande → KDS (symbolique) → OSS → encaissement (ticket client==écran, cuisine symbolique) → PAYÉE +
`fiscal_sequence_no` gap-free (4 branches). + web standalone + mobile + fidélité complète. **Boucle
jusqu'à ce que chaque système passe ses 10 angles sur ≥2 cycles reproductibles. Absolu ou rien.**

---
**Commence par §0 (lecture) + re-baseline, puis re-valide le Système A sur les 10 angles avant de
fan-out. Traite le « 11/11 GREEN » comme une HYPOTHÈSE à réfuter, pas comme un acquis.**
