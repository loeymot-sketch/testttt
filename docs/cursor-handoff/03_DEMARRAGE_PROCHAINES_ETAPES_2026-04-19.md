# Passation Cursor — Fichier 3/3 : démarrage nouvelle conversation + suite

> **Usage** : ouvrir **ce fichier en tête** dans la **nouvelle** session Cursor pour lancer
> le travail. Les fichiers **01** (contexte/paths) et **02** (historique/vision) servent de
> **mémoire** ; celui-ci est l’**actionnable**.

---

## 1. Message d’amorce (copier-coller dans le premier prompt du nouveau compte)

```
Tu reprends FoodKing kiosk. Worktree canon K-series :
/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt-kiosk-p93 (feat/kiosk-phase-9-3).

Lis dans l’ordre :
1) docs/cursor-handoff/01_CONTEXTE_MAX_RAPPORTS_PATHS_2026-04-19.md
2) docs/cursor-handoff/02_HISTORIQUE_CONVERSATION_VISION_2026-04-19.md
3) docs/cursor-handoff/03_DEMARRAGE_PROCHAINES_ETAPES_2026-04-19.md (ce fichier)

Puis charge tasks/k-hardening/K_TRACKER.md et reports/review/AUDIT_KIOSK_110_EXECUTIVE_2026-04-19.md sur le worktree kiosk-p93.

Objectif immédiat : [CHOISIR UNE LIGNE CI-DESSOUS §2].
```

*(Remplace la dernière ligne par une des options §2.)*

---

## 2. Pistes de travail prioritaires (post-alimentation)

### A. Remédiation audit 110 — P1 (ordre suggéré)

1. **AX12-02** — Propager `X-Correlation-ID` dans `PersistOrderCreatedToOutbox` (et listeners
   sœurs) au lieu de `Str::uuid()` seul.  
2. **AX4-04** — Bloquer paiement kiosk si `total` serveur absent (`KioskPaymentComponent.vue`).  
3. **AX11-01** — Aligner `NormalItemResource` / fiche item avec disponibilité **branche**
   comme le menu.  
4. **AX10-01** — Planifier CSP enforce + nonces (projet transverse).  
5. **AX14-01** — Playwright golden path : login → panier → preview → étape paiement (mock
   TPE acceptable).

**Preuves** : `reports/review/AUDIT_KIOSK_110_FINDINGS_TRACKER.md`.

### B. Backlog K-10.1 (produit / ops)

- UI admin timeline SLO (`ActionLog` catégorie `slo_evaluation`).  
- Renderer heatmap agrégée.  
- Job purge `ActionLog` / rétention.  
- Pusher **multi-tenant** par branche (ADR_K8 suite).  
- CSP **enforce** après période d’observation.

### C. Alignement des deux clones

- Porter le fix **`AllergensSeederTest`** (codes UE) de `testttt` vers `testttt-kiosk-p93`
  si les tests y divergent encore :  
  `testttt/tests/Feature/KioskPhase1/AllergensSeederTest.php`

### D. Vérification santé après merge / nouveau poste

```bash
cd /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt-kiosk-p93
./vendor/bin/phpunit tests/Feature
npx vitest run
```

---

## 3. Fichiers récents « à avoir sous la main » (kiosk-p93)

| Priorité | Fichier |
|----------|---------|
| 1 | `tasks/k-hardening/K_TRACKER.md` |
| 2 | `reports/review/AUDIT_KIOSK_110_EXECUTIVE_2026-04-19.md` |
| 3 | `reports/review/AUDIT_KIOSK_110_FINDINGS_TRACKER.md` |
| 4 | `reports/review/REPORT_K1_K10_GLOBAL_LOGIC_2026-04-19.md` |
| 5 | `reports/acceptance/ACCEPTANCE_KIOSK_FINAL_2026-04-19.md` |
| 6 | `tasks/k-hardening/ADR_K10_ACCEPTANCE_SCOPE_2026-04-19.md` |
| 7 | `app/Jobs/DispatchDomainEventsJob.php` (broadcast défaut) |
| 8 | `app/Http/Controllers/Frontend/KioskEventController.php` (PII payload vs context) |

---

## 4. Chemins absolus des trois fichiers de passation (ce repo `testttt`)

```
/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/docs/cursor-handoff/01_CONTEXTE_MAX_RAPPORTS_PATHS_2026-04-19.md
/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/docs/cursor-handoff/02_HISTORIQUE_CONVERSATION_VISION_2026-04-19.md
/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/docs/cursor-handoff/03_DEMARRAGE_PROCHAINES_ETAPES_2026-04-19.md
```

*(Une copie miroir existe déjà sous `testttt-kiosk-p93/docs/cursor-handoff/` ; les chemins
absolus §4 pointent ici vers le clone `testttt`.)*

---

## 5. État mental à transmettre au nouvel agent

- La série **K est close** au sens **K_TRACKER** ; le travail suivant est **qualité /
  remédiation audit**, **K-10.1**, ou **fusion** des branches.  
- Ne pas re-« inventer » les invariants : ils sont dans **01** + `AGENTS.md` / `.cursor/rules`.

---

*Fin fichier 3/3.*
