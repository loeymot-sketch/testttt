# /ultraplan Wave Polish — V1 Le Cayenne
**Date** : 2026-05-21 · **HEAD** : `ce09a44e9` · **Mode** : plan-only (zéro action)

---

## 0. TL;DR pour owner

1. **Mon Phase Z était wrong-tier.** Tu avais raison de demander un adversarial. 3 agents indépendants convergent sur ce verdict.
2. **Vrai prochain step = "Wave Polish"** : 12-15 petites dysfonctions isolées, scope-minimal, hors zone structurelle/paiement/NF525.
3. **Surprise non-vue précédemment** : **EN i18n catalog a 112 keys manquantes, AR en a 263**. Cash Overview admin (Wave X X4 livré aujourd'hui) **inutilisable en anglais** — page entière affiche `label.grand_total`, `label.source_borne`, etc. en raw. C'est un P0 que les audits Wave X ont raté car ils ont tous tourné en FR.
4. **Plan structuré en 3 vagues α/β/γ**, chaque item ≤2h, chaque vague ~1 jour de focus.
5. **1 décision à prendre par toi** avant que je lance autonomous : tu valides après chaque vague OU tu me laisses enchaîner α→β→γ et tu valides à la fin ?

---

## 1. Disputes contre Phase Z (3 agents convergent)

### Z1 — Owner Soak Test 5 jours
**Verdict** : KEEP, mais **NE BLOQUE PAS** les polish items. Tu peux faire le soak test EN PARALLÈLE de la Wave Polish (Polish = code dev, Soak = toi qui utilises). Donc Z1 n'est pas une "phase" → c'est un **mode continu** qui démarre quand tu décides.

### Z2 — laravel-websockets self-hosted
**Verdict** : **KILL / DEFER**. 
- Polling fallback déjà implémenté (`PosOrdersTrackerComponent.vue:690` 15s tick + comment line 2592 "polling fallback handles it")
- Wave P mesures cross-system latency : **kiosk→KDS 5.7s, OSS removal 6.1s** — humainement acceptable pour fast-food
- Ajouter `laravel-websockets` = process OS à monitorer + risque récurrence bug Sanctum-wildcard channel-auth (Wave J)
- Mantra "no useless complexity V1" violé
- **Re-considérer SI** tu observes une plainte réelle de latence en soak test (sinon : never)

### Z3 — DB hardening + 6-year backup
**Verdict** : **SURTOUT DÉJÀ FAIT** — orchestrateur a sur-décrit.
- `database/migrations/2026_04_22_000002_create_audit_logs_table.php:86-150` installe **déjà** BEFORE UPDATE + BEFORE DELETE triggers (NF525 compliance code-level OK)
- `scripts/db/backup.sh` **existe déjà**
- `storage/backups/` contient **déjà** des backups réels
- Ce qui manque vraiment = **1 ligne crontab + 1 `find -delete` rotation** = 30 minutes, pas une "phase"
- **Reclassé** comme item dans Wave Polish β (POL-15)

### Z4 — Hardware integration (TPE + drawer + printer)
**Verdict** : **DEFER** jusqu'à ce que tu nommes le matériel.
- Sans modèle TPE, il n'y a rien à coder
- Pas actionnable maintenant
- Tu peux travailler en parallèle de Wave Polish à décider quel matériel acheter / utiliser

### Z5 — HTTPS LAN
**Verdict** : **KILL pour V1 mono-machine**. 
- Si tout tourne sur `127.0.0.1` (1 PC qui sert kiosk + POS + KDS via tabs), pas de surface MITM
- Seul cas où Z5 devient utile : tu fais une vraie config multi-device LAN (kiosk = tablette séparée connectée au serveur via WiFi). À ce moment-là, c'est 1h de Nginx setup + cert.
- **Conditionnel** au moment où tu décides multi-device

### Z6 — Shadow operation 2 semaines parallèle papier
**Verdict** : **CEREMONIAL**. Z1 soak (toi qui utilises 4-6h/jour) trouve les mêmes bugs sans le double bookkeeping. Si tu veux malgré tout valider chiffres : 1 jour avec ticket de caisse imprimé compared to Z-report jour J = suffit.

---

## 2. SURPRISE majeure non-surfacée

### EN i18n catalog : 112 keys missing → Cash Overview admin inutilisable en EN

**Evidence** (dysfunction hunter §1) :
- `resources/js/languages/en.json` — entière section Cash Overview manque
- Au moment où owner switch lang→EN sur `/admin/cash-overview`, la page affiche :
  - `label.grand_total` au lieu de "Grand total"
  - `label.source_borne` au lieu de "Kiosk"
  - `label.source_caisse` au lieu de "POS Direct"
  - `label.source_livreur` au lieu de "Delivery"
  - `label.mode_cash` / `label.mode_card` / `label.mode_mobile` / `label.mode_ticket`
  - `label.cash_collected_today` / `label.expected_in_drawer` / `label.cash_drawer_count_pending_note`
  - ... et 110+ autres

**Pourquoi rate ?** Les /test-e2e Wave X ont tous tourné avec `?lang=fr` par défaut. L'i18n leak est zero-cost en FR (toutes les keys présentes), mais 100% leak en EN.

### AR catalog : 263 keys missing
Same problem, magnifié. Arabe est important pour Le Cayenne (clientèle bilingue selon tes propres handoffs Claude Design).

### Wave Y `{seconds}` placeholder fix : FR-only
Le toast rate-limit qu'on a fixé aujourd'hui via Wave Y (commit `2e2400724`) n'a paramétré `{seconds}` que dans `fr.json` ; `en.json` + `ar.json` toujours hardcoded "30s" alors que le code lit le vrai header. Donc bug Wave Y still partial.

### POS PosComponent hardcoded FR strings (11 occurrences)
Lines `810` + `1110-1236` ont des chaînes hardcoded en FR (ex : "Aucune commande borne en attente.", "↻ Actualiser", "✓ Encaisser", "Allergenes:" mal-orthographié). Pas de `$t()` wrapper → rendent mal en EN/AR.

---

## 3. THE PLAN — Wave Polish (3 sous-vagues séquentielles)

### Wave POL-α (Day 1, ~3-4h) — i18n & accessibility quick wins

| # | ID | Item | Files | Effort | Risque |
|---|----|------|-------|--------|--------|
| α1 | POL-01 | Toast Retry-After lit vrai header en EN+AR (compléter Wave Y) | `en.json`, `ar.json` | XS 15min | 0 |
| α2 | GAP-01 | EN catalog : ajouter 112 keys (Cash Overview + Sessions + Promo + TPE admin) | `en.json` | M 1.5h | 0 |
| α3 | GAP-02 | AR catalog : ajouter 263 keys | `ar.json` | M 2h | 0 |
| α4 | POL-02 | aria-labels batch (Cash Overview aggregate cards + Sort columns + KDS history close) | `CashOverviewComponent.vue:78-99`, `KdsHistoryDrawer.vue` | XS 30min | 0 |
| α5 | POL-03 | KDS status badge contrast 3:1 → 4.5:1 WCAG AA | `KdsHistoryDrawer.vue` border-left CSS | XS 20min | 0 |

**Sortie Wave α** : 5 commits clean, `/admin/cash-overview` utilisable dans les 3 langues, KDS WCAG AA, 0 frozen-zone touch. Score V1 LOCAL 8.7 → ~9.0.

### Wave POL-β (Day 2, ~3-4h) — UX polish + filter completion

| # | ID | Item | Files | Effort | Risque |
|---|----|------|-------|--------|--------|
| β1 | POL-04 | `formatMoneyEuro` global sur Cash Overview (aggregates + chips + table rows) | `CashOverviewComponent.vue` | S 1h | 0 |
| β2 | POL-05 | Cash Overview empty-state illustration + copy ≥20 chars + reset CTA | `CashOverviewComponent.vue` | S 1h | 0 |
| β3 | POL-06 | POS shortcut panels empty-state copy ("Aucune commande prête à livrer pour le moment") | `PosComponent.vue:1110-1236` | S 45min | 0 |
| β4 | POL-07 | URL sync filters Cash Overview (`router.push({ query })` + watcher) | `CashOverviewComponent.vue` | M 1h | 0 |
| β5 | POL-08 | `mode=other` filter silent no-op fix (display un message ou désactive l'option) | `CashOverviewComponent.vue` filter logic | XS 30min | 0 |

**Sortie Wave β** : Cash Overview full-featured + POS shortcuts polished. Score 9.0 → ~9.2.

### Wave POL-γ (Day 3, ~3-4h) — POS PosComponent string i18n + finalisation

| # | ID | Item | Files | Effort | Risque |
|---|----|------|-------|--------|--------|
| γ1 | POL-09 | POS PosComponent 11 hardcoded FR strings → `$t()` keys | `PosComponent.vue:810, 1110-1236` + i18n catalogs | M 1.5h | 1 (testid impact possible — re-run sentinels) |
| γ2 | POL-10 | Counter-collect modal numpad below-fold sur small viewport (responsive media query) | `PosCounterCollectModal.vue` | S 45min | 0 |
| γ3 | POL-11 | KDS history drawer focus-visible ring sur trigger button après close | `KitchenDisplaySystemComponent.vue` trigger button | XS 20min | 0 |
| γ4 | POL-12 | Counter-collect modal close button aria-label vérification (Wave X A-004 — verify ou close) | `PosCounterCollectModal.vue:53` | XS 10min | 0 |
| γ5 | POL-13 | Cron backup rotation + retention `30j local + 12 mois archive` (1 ligne crontab + script) | `scripts/db/backup.sh` + crontab | S 30min | 1 (test restore drill required) |
| γ6 | POL-14 | Wave Polish convergence /test-e2e final round (3 langues FR/EN/AR) | spec | M 1h | 0 |

**Sortie Wave γ** : V1 LOCAL **production-ready 3 langues complet** + backup automatisé. Score 9.2 → ~9.5.

---

## 4. Ce qu'on NE FAIT PAS pendant Wave Polish (anti-items)

| Truc tentant | Pourquoi PAS |
|---|---|
| Cloud / multi-tenant SaaS | Owner mandate explicit `feedback_no_cloud_until_owner_initiates` |
| Refactor architecture Vue 2 → Vue 3 | Pas de valeur produit V1 ; risque énorme |
| WebSocket self-hosted (Z2) | Polling fonctionne, 5.7s mesuré OK fast-food ; mantra no-complexity |
| Hardware TPE/drawer/printer (Z4) | Bloqué info matériel ; déférer jusqu'à décision owner |
| HTTPS LAN (Z5) | Pas nécessaire si 1 PC ; conditionnel multi-device |
| Shadow op papier 2 semaines (Z6) | Ceremony ; Z1 soak suffit |
| Multi-tranche split counter-collect | Backlog V1.0.2 (LOCK NF525 requis) |
| KDS revert PREPARED→PREPARING | Backlog V1.0.2 (LOCK frozen-zone requis) |
| Cash drawer count input feature | Backlog V1.0.2 (nouvelle feature, hors V1) |
| Toucher PaymentComponent.vue | Frozen §7 ABSOLU |
| Toucher OrderStateMachine | Frozen §7 ABSOLU |
| Toucher Pricing/Fiscal services | NF525 ABSOLU |
| Toucher BranchScope/Idempotency middleware | Frozen §7 + locked sentinels |
| Toucher kiosk components | Frozen §7 ABSOLU |
| Toucher pos-wizard.js/css | Frozen §7 ABSOLU |

---

## 5. Sequencing & dependencies

```
Wave POL-α (i18n catalogs + a11y/contrast)        Day 1, ~3-4h
    │
    ├─→ Owner manual verify Wave α (visual EN+AR + KDS contrast)
    │       │
    │       └─→ Owner gate : "α landed clean, go β?"
    │
Wave POL-β (Cash Overview polish + URL sync)        Day 2, ~3-4h
    │
    ├─→ Owner manual verify Wave β
    │       │
    │       └─→ Owner gate : "β landed clean, go γ?"
    │
Wave POL-γ (POS i18n + responsive + cron backup)    Day 3, ~3-4h
    │
    └─→ Wave Polish convergence /test-e2e round across FR/EN/AR
            │
            └─→ Final report : V1 LOCAL "polished" production-ready

Parallel (continuous, owner-driven) : Z1 owner soak test démarré dès aujourd'hui
```

Total wall-clock estimé : **3 jours focus dev** + ton soak test continu en parallèle.

---

## 6. Owner gates / blocker info needed

### Gate 1 (à décider MAINTENANT — bloque le lancement) :
> **Tu veux que j'exécute α → β → γ en autonomie complète avec rapport unique à la fin, OU tu valides après CHAQUE vague visuellement (α landed → toi testes → tu dis "go β") ?**

**Mode autonomous** : plus rapide (~3 jours total). Tu reçois 1 rapport final + verify global.
**Mode gated** : plus sûr (~5-7 jours total). Tu verify visuellement entre chaque vague.

Recommandation orchestrateur : **gated** parce que l'i18n EN/AR (Wave α) mérite verify visuel par toi avant de continuer (tu connais ta clientèle bilingue mieux qu'aucun agent).

### Info à fournir plus tard (non-bloquant Wave Polish) :
- Modèle TPE / drawer / imprimante (pour Wave Z4 future, peut-être plus tard)
- Topologie réseau finale (1 PC vs multi-device LAN) — pour décider Z5

---

## 7. Risk register (Wave Polish)

| Risk | Mitigation |
|---|---|
| AR translation quality (263 keys, je ne suis pas locuteur natif) | Utiliser fallback "best-effort" + flag owner-review obligatoire en Wave α end |
| POS i18n changes (γ1) cassent les testids existants | Wave Polish convergence test re-run tous sentinels (γ6) avant declaration done |
| Cron backup rotation pourrait supprimer un backup en cours | Restore drill obligatoire en γ5 avant deploy crontab |
| Wave POL-α landed mais owner désaccord style traductions EN/AR | Gated mode permet rollback rapide ; Mode autonomous = revert le commit α si désaccord |

---

## 8. Comparaison Phase Z (mort) vs Wave Polish (vivant)

| Métrique | Phase Z | Wave Polish |
|----------|---------|-------------|
| Durée wall-clock | 4-6 semaines | 3 jours focus |
| Risque structurel | Moyen-haut (WebSocket new infra, hardware integration) | Très faible (0 frozen-zone, isolé) |
| Valeur opérationnelle | Indirecte (préparation prod) | Directe (Cash Overview EN/AR utilisable, POS polished) |
| Alignement owner mandate "small one by one" | NON | OUI |
| Réversibilité | Difficile (infra installed = à désinstaller) | Trivial (chaque commit isolable) |
| Frontière scope | Floue | Nette (V1.0.2 backlog déjà documenté + nouveaux i18n surfacés) |

---

## 9. Critical insight (le truc important)

**L'i18n EN/AR gap est un P0 silencieux**. Ton Cash Overview admin livré aujourd'hui (Wave X X4) fonctionne PARFAITEMENT en FR mais est **inutilisable en EN** (raw labels partout). Si Le Cayenne sert une clientèle FR+AR (memory : `feedback_kiosk_wizard_not_protected` + handoff Claude Design "Le Cayenne sert clientèle bilingue FR + AR"), le staff anglophone ou les clients qui auraient accès aux écrans en EN voient des "label.grand_total" partout = pas pro.

C'est exactement le genre de **petite dysfunction structurelle silencieuse** que ton mandat "small simple functions one by one" vise à attraper.

Sans Wave Polish, ton V1 LOCAL n'est pas vraiment 3 langues — c'est 1 langue (FR) plus 2 broken langues (EN partial, AR très partial).

---

## 10. Fichiers de support déjà écrits

- `reports/dispute-phase-z-2026-05-21.md` (DISPUTER agent)
- `reports/dysfunction-hunt-2026-05-21.md` (HUNTER agent, 78 items)
- `reports/strategic-second-opinion-2026-05-21.md` (STRATEGIC agent)
- Ce fichier `reports/ULTRAPLAN_WAVE_POLISH_2026-05-21.md` (synthesis)

**Aucune action prise.** Aucun commit. Aucun fichier code touché. Pure planning.

---

**EOF — En attente de ta réponse à Gate 1 pour démarrer.**
