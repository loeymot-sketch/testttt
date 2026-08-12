# Deep audit de raisonnement — 4 agents adversariaux (2026-08-06)

**Anchor** : HEAD `70da30673` (déployé `522f74a74`) — audit épinglé au code COMMITTÉ car une **session parallèle édite l'arbre en direct** (HEAD a bougé `70da306`→`a13e1e656` « Apple/Google Pay » pendant l'audit ; WIP non-committé sur Mollie). Preuves : DB-safe `vendor/bin/phpunit` (sqlite :memory:) + `npx vitest` + code-trace + serveur :8000. verify-before-report sur chaque finding.

## VERDICT : cœurs money / fiscal / cross-surface = SAINS (0 P0/P1). Résidus = CUISSON (déployé, session parallèle), tous LATENTS (pas de casse live aujourd'hui).

---

## R2 — Money-path : SOUND, 0 P0/P1/P2
Tout tracé + réfuté : pricing SSOT (aucune divergence affiché↔quote ; options indispo = **422**, jamais droppées/gratuites) · loyalty earn/clawback/redeem symétrique+idempotent+clampé (le seul self-cancel sans clawback est **inatteignable** pour une commande awarded : seuil PREPARING < award à PREPARED) · refund tiroir-correct, triple-bloqué, pas de double-out. **Apple/Google Pay (commit parallèle `a13e1e656`) audité au vol : montant = total scellé inchangé, méthode whitelistée, fiscal/webhook intacts, frozen 0, test 10/10.** 22 fichiers money verts. P3 by-design : web `expected_total` optionnel (non-fiscal, kiosk scellé) ; partial-refund claw full points (défavorable CLIENT, backlog V1.0.2).

## R3 — Fiscal/NF525 : SOUND
- **CUISSON banner = kitchen-ticket + KDS SEULEMENT, NON-fiscal** (jamais sur `renderClientTicket` qui porte fiscal_sequence_no/TVA/mentions légales ; lecture read-only du snapshot). **Fiscal-risk RÉFUTÉ.**
- Séquence fiscale gap-free + monotone (`next()` ne persiste rien → allocate-then-fail = pas de gap ; alloc à l'encaissement pas à la création pour ne pas brûler un n°). Uniqueness DB fail-closed.
- Z-report/refund cohérent (mirror counter-entry, aucune vente n'échappe au Z).
- **TAMPER (30/06) = artefact LÉGACY DORMANT, PAS une vuln live** : réutilisation de n° par hard-delete AVANT le trigger d'immutabilité ; la chaîne re-signe proprement après → signing sain. **VÉRIFIÉ LIVE VPS : triggers d'immutabilité 10/10 présents, `orders_no_delete_when_fiscalized` PRESENT → la SOURCE est fermée en prod.** Go-live carte = procès-verbal du break légacy + segment fiscal frais (décision owner/comptable, PAS un fix code).

## R4 — Cross-surface : SOUND, 0 P0/P1/P2
Mes fixes récents RÉFUTÉS-comme-sains : gateway-refund `isRefunded` **cohérent** avec le board-release (une commande POS+CASH à REFUNDED porte TOUJOURS un statut terminal → exclue partout pareil ; les refunds gateway sont carte/web, exclus par le prédicat paiement) · plancher zombie advance-order présent sur les **6** chemins (OSS×2, KDS×2, KdsSync, +WaitEstimate) · `OrderPaymentStatusChanged` push = coalescing airtight (`_fetchInFlight`+`_refetchQueued`), pas de race. 72/72 verts.

---

## R1 — CUISSON engine : « source unique » à moitié-vrai + bugs LATENTS

**« SOURCE UNIQUE écran/ticket/stock » = à moitié faux** : ticket (`OrderReceiptEscPosRenderer:270`) + stock (`RawMaterialConsumptionService:358`→`MeatMaterialResolver`) partagent UN moteur PHP (`MeatPortionCalculator`). L'**écran** tourne un JUMEAU JS ré-implémenté à la main (`kdsSymbolic.js:599-785`). Le commentaire « un seul calcul, jamais trois » (`KdsOrderCard.vue:422`) est littéralement faux : 2 codebases.

- **[Structurel] JUMEAU PHP↔JS sans verrou de parité + asymétrie d'INPUT.** Arithmétique byte-identique AUJOURD'HUI, mais aucun fixture partagé ne les lie (2 suites disjointes à chaînes attendues dupliquées → dérive CI-invisible = le défaut #1 du projet). Pire : JS `readVariations/readExtras` retombe sur `item_variations/item_extras` (colonnes legacy) ; PHP `forLine` lit le **snapshot SEUL** (pas de fallback, `:118`). → un payload à `snapshot.lines` VIDE mais colonnes legacy peuplées : **écran montre la viande, ticket/stock non.** LATENT (le snapshot est scellé peuplé à la création sur le chemin standard ; R4 a confirmé la carte V2 reçoit `composition_snapshot`). **Reco : fixture golden PHP↔JS partagée (pattern `extraViandeNames`).**
- **[Latent] Inflation d'arrondi sur viande COMPOSÉE en produit MULTI-slot → sur-décrément stock.** VÉRIFIÉ par code : `symbolesPour` split « Mixte »→`K P` en parts 0.5 (`:304`) ; `ajoute` fait `(int)round()` PAR AJOUT (`:359`) → 0.5+0.5 = round(0.5)=1 puis round(1.5)=2. Méga Mixte+Mixte 2-slot = 4 (nominal 2). Stock sur-décrémente, food-cost gonflé, fausse rupture. **NON REACHABLE dans le menu V1** : « Mixte » n'existe QUE sur Cayenne #22 + Galette #24, tous deux MONO-slot (`parViande=2` = correct). Fire le jour où l'owner ajoute « Mixte » à un produit multi-slot (Tacos L/Méga/Terminator). **Reco : arrondir UNE fois au rendu/consume, pas par `ajoute`.**
- **[Latent] Viande NON MAPPÉE → décrément stock ZÉRO.** Une viande absente de `SYMBOLE_VERS_MATIERE` (ex. nouveau « Merguez ») → fallback 3-lettres « Mer » → non mappé → décrémente RIEN, ET `matieresReprises` exclut toute la catégorie viande → la ligne viande-défaut de la fiche est droppée aussi. Net : zéro stock consommé, surfacé seulement en `skipped[]`/logs. Fire à l'ajout owner d'une viande sans éditer les 3 tables (`MEAT_TABLE`, `SYMBOLE_VERS_MATIERE`, `VIANDES_PILOTEES`). **Reco : garde fail-loud sur symbole non-mappé.**
- **[P3] `portionsFrites` (JS) ne fait pas le fallback `readAddons`** → menu-frites silencieusement 0 sur payload snapshot-less (marche aujourd'hui via le wiring V2 ; latent si on repointe le board).
- **[P3] Tri d'affichage** PHP `<=>` (byte) vs JS `localeCompare` — cosmétique (ASCII → identique ; pieces/stock order-independent).

**RÉFUTÉ (R1)** : double-décrément stock (gardé par `matieresReprises`+`VIANDES_PILOTEES`) · divergence nom-item (les 3 résolvent `Item.name` via la relation) · déterminisme (pur, `uksort` total) · reversal/idempotence (`REVERSAL_SOURCE_TYPE`+`reversalExists`+`withTrashed`).

---

## Synthèse + reco
Les **cœurs argent/fiscal/synchro sont SAINS** — le durcissement des mois précédents tient. Les résidus sont **tous dans le moteur CUISSON déployé** (travail de la session parallèle) et **tous LATENTS** (fire sur un futur changement de menu/config, pas de casse aujourd'hui). Le plus haut ROI : **fixture golden PHP↔JS** (verrou anti-dérive du jumeau = défaut #1 du projet), puis l'arrondi-une-fois + la garde symbole-non-mappé — **avant** que l'owner touche au menu.

**⚠️ Coordination** : ces findings sont dans le code ACTIF de la session parallèle (qui édite l'arbre en direct). NE PAS healer sans coordination (conflit garanti). À trancher owner : qui corrige (session cuisson) et quand.
