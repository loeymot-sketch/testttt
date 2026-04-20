# Passation Cursor — Fichier 3/3 : DÉMARRER ICI (nouvelle conversation)

**Usage :** ouvrir ce fichier **en premier** dans le nouveau chat Cursor (même racine projet), puis dire à l’agent :  
*« Lis `docs/cursor-handoff/03_DEMARRAGE_NOUVELLE_SESSION.md` et les deux autres fichiers du même dossier, puis continue le travail. »*

---

## 1. Ordre de lecture recommandé

1. **Ce fichier** (`03_…`) — direction et prochain pas.  
2. `docs/cursor-handoff/02_HISTORIQUE_CONVERSATION_ET_VISION.md` — quoi / pourquoi.  
3. `docs/cursor-handoff/01_CONTEXTE_ET_ALIMENTATIONS_COMPLET.md` — preuves, chemins, liste des rapports.

---

## 2. État du dépôt au dernier point connu

- **Branche :** `feat/ton-sujet` (poussée jusqu’au moins `c00a8cd61` pour P10).  
- **À faire systématiquement :** `git status`, `git log -5`, vérifier **diffs locaux** (voir fichier 1 §6 : PosComponent kiosk cash, Loyalty test, KioskMachineRequest).

---

## 3. Rapports & docs « à garder sous la main »

| Priorité | Fichier |
|----------|---------|
| Synthèse audit | `reports/review/AUDIT_POS_110_EXECUTIVE_2026-04-19.md` |
| Findings traçables | `reports/review/AUDIT_POS_110_FINDINGS_TRACKER.md` |
| NF525 verdict code | `reports/review/AUDIT_POS_110_NF525_READINESS_2026-04-19.md` |
| Synthèse cycles P | `reports/review/REPORT_GLOBAL_P_IMPLÉMENTATIONS_2026-04-19.md` |
| Règles métier | `docs/BUSINESS_RULES.md` (mettre à jour vs dispo si travail doc) |

---

## 4. Pistes de travail post-passation (priorisées)

**Court terme (produit / robustesse)**  
1. Trancher **Z.open** : renforcer monotonie `sequence_no` (ex. `lockForUpdate` ou séquence SQL) — finding `F-FISC-001`.  
2. Réduire risque **double commande** POS : idempotence **stable** jusqu’à succès / annulation — `F-STATE-002`.  
3. Mettre à jour **`docs/BUSINESS_RULES.md`** (stock / disponibilité) — `F-SYNC-001`.

**Moyen terme (données / finance)**  
4. Évaluer table **`order_payments`** si split multi-tender requis — `F-PAY-001`.  
5. Poursuivre **remboursement partiel** (backlog P3).

**Qualité**  
6. Couverture PHPUnit **fiscale** + stress (optionnel hors CI) — `F-FISC-004`, `F-PERF-001`.

**Front récent (utilisateur)**  
7. Finaliser / tester **panneau kiosk cash** dans `PosComponent.vue` (expansion détails, testids Playwright si besoin).

---

## 5. Règles Cursor à respecter dans la nouvelle session

- Lire `.cursor/rules/` pertinents (`global`, `scope`, `safety`, `architecture`, `playwright` si E2E).  
- **`AGENTS.md`** si orchestration multi-agents FoodKing.  
- Éviter **Pint massif** sur `OrderService.php` sans périmètre (note session historique).

---

## 6. Commande de santé rapide

```bash
cd /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
git status
./vendor/bin/phpunit tests/Feature/Fiscal/ --no-coverage 2>&1 | tail -20
```

---

## 7. Chemins absolus des trois fichiers de passation

```
/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/docs/cursor-handoff/01_CONTEXTE_ET_ALIMENTATIONS_COMPLET.md
/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/docs/cursor-handoff/02_HISTORIQUE_CONVERSATION_ET_VISION.md
/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/docs/cursor-handoff/03_DEMARRAGE_NOUVELLE_SESSION.md
```

---

*Bonne reprise sur le nouveau compte Cursor — même clone / même workspace.*
