# Orchestration NEXT — V1 Le Cayenne post Wave Polish Final

**Date** : 2026-05-21
**HEAD état actuel** : `b8ce23851` (post convergence GREEN)
**Owner mode** : décide la suite des opérations

---

## 🗺️ Vue d'ensemble — chronologie complète V1 → Production

```
   AUJOURD'HUI                  CETTE SEMAINE              SEMAINES 2-3
   ──────────────               ──────────────              ─────────────
   ▶ G1 manual verify           ▶ G3 Z1 soak test 5j       ▶ G4 Z2 hardware
   ▶ G2 cleanup/V1.0.2          (ouvrir Le Cayenne          (après owner décide TPE)
                                comme un resto)             ─────────────
                                                            SEMAINES 4-5
                                                            ▶ G5 Z6 shadow op
                                                            ▶ Go-live total
```

Branche actuelle restera `heal/cms-pr1-quickwins-2026-05-18` jusqu'à merge vers main décision owner.

---

## GROUPE 1 — MANUAL VERIFY OWNER (aujourd'hui, ~20 min total)

**Critère go/no-go V1 LOCAL** : tous les 6 passent. Si même un seul fail → heal cycle (G2.1) avant Z1 soak.

### G1.1 — Cash Overview (5 min)
**URL** : http://127.0.0.1:8000/admin/cash-overview

Checklist :
- [ ] Mount initial → comptes les € visibles, doit y en avoir partout (cards agrégat + chips méthode + lignes table + bande réconciliation)
- [ ] Filtre Source = Borne → URL change vers `?source=borne` + table se filtre
- [ ] F5 (recharger page) → filtre Borne toujours appliqué (preuve URL sync marche)
- [ ] Clique "Réinitialiser" → URL nettoyée + toutes lignes reviennent
- [ ] Force filtre date hier-seulement (probablement 0 transactions) → empty state visible avec illustration SVG + texte ≥20 chars + bouton "Réinitialiser les filtres"
- [ ] Ouvre dropdown "Mode paiement" → vérifier que l'option "Autre" est absente

**Critère succès** : 6/6 checkboxes verts. Critère échec : 1+ ne match pas → noter quel point + dispatch G2.1.

### G1.2 — POS shortcuts (5 min)
**URL** : http://127.0.0.1:8000/admin/pos

Checklist :
- [ ] Mount sans aucune commande → 2 panneaux visibles avec texte italique gris centré "Aucune commande prête à livrer pour le moment." / idem pour borne
- [ ] Sous chaque panneau, timestamp visible "Mis à jour à l'instant"
- [ ] Attendre 6s, regarder le timestamp → doit indiquer "il y a 6s" ou "à l'instant" (poll re-stamps every 5s donc reste "à l'instant" si Echo OK)
- [ ] Via tinker ou kiosk place 1 commande TAKEAWAY → wait ≤10s → "Prêt à livrer" panel auto-affiche la commande (preuve Echo/poll)
- [ ] Clique "Livré" sur la ligne → row disparaît immédiat (1s max)

**Critère succès** : 5/5 verts.

### G1.3 — Q9-S1 cross-surface sync (3 min) ⭐ TEST CLE
**Setup** : 2 navigateurs ou 2 onglets côte à côte
- Tab 1 : http://127.0.0.1:8000/admin/stock/rupture (ou /admin/stock-rupture-dashboard)
- Tab 2 : http://127.0.0.1:8000/kiosk/idle

Checklist :
- [ ] Tab 2 kiosk → démarrer commande → arriver à l'étape "Choix sauces" (burger ou tacos)
- [ ] Vérifier Algérienne (ou n'importe quelle sauce) visible et sélectionnable
- [ ] Tab 1 admin → toggle Algérienne OFF
- [ ] Tab 2 kiosk → reload (F5 ou Cmd+R) sur l'étape sauces
- [ ] Compter le temps : Algérienne doit disparaître **≤5 secondes** après le reload (était 0-60s avant Q9-S1 fix)

**Critère succès** : ΔT ≤ 5s mesuré au chrono.
**Critère échec** : >5s = défaut Q9-S1 fix → dispatch G2.1 immédiat.

### G1.4 — KDS Historique drawer (3 min)
**URL** : http://127.0.0.1:8000/kds

Checklist :
- [ ] Page mount → pill "Historique du jour" visible en haut à droite (FR translated, pas raw `label.kds_history_button`)
- [ ] Click pill → drawer slide-in depuis la droite
- [ ] Drawer header lit "Historique du jour (N)" pas `label.kds_history_title (N)`
- [ ] Chaque ligne : badge statut "PRÊT" / "LIVRÉ" / "EN LIVRAISON" (uppercase OK via CSS), pas `LABEL.KDS_STATE_*`
- [ ] Appuyer Esc → drawer close
- [ ] Aucun bouton "Renvoyer" / "Recall" / "Annuler" sur les lignes (V1 read-only strict)

**Critère succès** : 6/6 verts.

### G1.5 — Backup automatique (DEMAIN 2026-05-22 matin, 2 min)
**Quand** : après 03:00 le 22 mai 2026

Checklist :
- [ ] `ls -lt storage/backups/db-daily/` doit montrer un fichier `.sql.gz` daté du 22 mai
- [ ] `tail -50 storage/logs/backup.log` doit contenir "Backup completed successfully" + size + sha256
- [ ] Si AUCUN fichier nouveau → le cron n'a pas tourné → tu dois ajouter la ligne crontab :
  ```
  * * * * * cd /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt && php artisan schedule:run >> /dev/null 2>&1
  ```
  Voir détail dans `scripts/db/CRONTAB_SETUP.md`

**Critère succès** : backup quotidien automatique fonctionne sans intervention.

### G1.6 — NF525 chain spot-check (1 min)
**Commande** : `php artisan fiscal:verify-chain`

À refaire à chaque fin de journée pendant Z1 soak test pour confirmer chain holds en condition réelle continue.

**Critère succès** : sortie contient "CHAIN OK".

---

## GROUPE 2 — POST MANUAL VERIFY (selon résultats G1)

### G2.1 — Healing cycle conditionnel
**SI** une G1.X fail → dispatch un GStack heal agent scope-minimal avec :
- Description exacte du défaut observé (quel test, quel comportement, quel attendu)
- Capture écran si possible
- Constraint : pas de frozen-zone, pas de NF525, scope ≤30 LOC

Discipline LOOP §5 étape 7 — max 3 heal cycles auto-correct, après quoi escalate.

### G2.2 — V1.0.2 backlog officiel
**Si** G1 all GREEN → cleanup `PROJECT_BRAIN.md` §4 NEXT TO DO avec la liste officielle V1.0.2 (~16 items déjà documentés). Commit dédié `docs(brain-v1.0.2-backlog)`.

---

## GROUPE 3 — Z1 OWNER SOAK TEST (5 jours, ~22-30h cumul usage)

**Objectif** : test le plus haut-ratio coût-valeur possible. Tu utilises Le Cayenne 4-6h/jour pendant 5 jours en condition de service réel — mais sans clients payants. Toi + K + amis/famille passent commandes.

### Setup Day 0 (avant de démarrer)
- [ ] Tous G1 verts (sinon corrige avant)
- [ ] G1.5 backup confirmé tourne
- [ ] Crée le fichier log : `reports/soak-test-2026-05-22.md` avec template :
  ```markdown
  # Soak Test Z1 — Le Cayenne 2026-05-22 à 2026-05-26
  ## Day 1 ...
  ## Day 2 ...
  ## Findings list (numbered)
  ## End-of-week verdict
  ```

### Day 1-5 (~5 jours)
Use Le Cayenne **comme si c'était live** :
- Borne kiosk : passe vraies commandes via le kiosk (burger, tacos, bols, frites, etc.)
- Caisse POS : encaisse les commandes borne + crée des POS-direct sales
- Cuisine KDS : bump les commandes
- OSS : regarde comme client
- Admin : navigue dashboard, vérifie Cash Overview, gère Stock

Mesures minimum quotidien :
- Combien de commandes traitées
- Combien de frictions observées (avec date + heure + écran + ce qui a déconné)
- État NF525 chain en fin de journée (`fiscal:verify-chain`)
- Cash Overview vs réalité physique (si tu remplis vraiment un tiroir)

### Day 5 (vendredi) — Review
- Compter total frictions trouvées
- Trier P0 (bloquant) / P1 (gênant) / P2 (cosmétique)
- Si P0+P1 = 0 → V1 LOCAL réellement validé, prêt pour G4 hardware
- Si P0+P1 > 0 → cycle heal scope-minimal avant G4

**Critère go vers G4** : 5 jours soak + 0 P0 + ≤2 P1 cosmétiques tolérables.

---

## GROUPE 4 — Z2 HARDWARE INTEGRATION (~2 semaines, après G3 + décision TPE)

**🚧 BLOQUEUR INFO** : tu dois d'abord choisir le matériel.

### G4.0 — Décision matérielle (toi, 1-3 jours de recherche)
- TPE : Senangpay (intégration custom existante en code) ? Stripe Terminal (SDK propre, plus cher) ? autre PSP français ?
- Tiroir-caisse : USB Star / Epson / EVOLIS ? (compatibilité POS)
- Imprimante : Star TSP-100 / Epson TM-T20 / autre ESC/POS standard ?

Recommandation orchestrateur : **Senangpay** parce que code partial existe (`app/Senangpay/...`) et c'est français. Mais SI Stripe Terminal est plus stable + adoption France, le surcoût SDK est justifié.

### G4.1 — TPE integration (~5 jours)
- Acheter le TPE en mode sandbox (€0 transactions)
- Wire le SDK dans `app/Services/Payment/` (existant scaffolding)
- E2E test : 20 vraies transactions sandbox (cash + carte + ticket + mobile)
- Switch `POS_SIMULATION_HARDWARE=false`
- Vérifier que `confirmCounterPayment` route le bon SDK

### G4.2 — Drawer + printer (~3 jours)
- Wire USB drawer kicker (souvent via imprimante)
- Wire ESC/POS receipt printer
- Test cycle ouvert caisse → vente → drawer kick → ticket imprimé
- Vérifier Z-report imprimé en fin de journée

### G4.3 — E2E hardware cycle Wave Z4
- Spec Playwright + manual + adversarial review identique aux waves précédentes
- Convergence GREEN required avant G5

**Critère go vers G5** : 0 P0 sur TPE/drawer/printer + 20 sandbox transactions success + Z-report imprimé correct.

---

## GROUPE 5 — Z6 SHADOW OPERATION (~2 semaines, après G4)

**Objectif** : période dry-run avec VRAIS clients en parallèle d'un système papier backup pour valider que le système ne perd rien sous trafic réel.

### Setup
- Tout est live, vrais clients, vraies transactions cash + carte
- MAIS : caissier garde un carnet papier "double-check"
- Chaque commande notée à la main (numéro + montant + mode)
- Fin de journée : compare Z-report système vs décompte papier

### Daily process
- Matin : `php artisan fiscal:verify-chain` → CHAIN OK obligatoire avant ouverture
- Soir : Z-report système + compte papier, écrits dans `reports/shadow-op-week-N.md`
- Si écart > 0 → STOP système jusqu'à investigation

### Critère go vers production totale
- 2 semaines consécutives 0 écart entre Z-report et papier
- Tous les jours `fiscal:verify-chain` CHAIN OK
- Caissier confirme expérience fluide

---

## GROUPE 6 — V1.0.2 BACKLOG (parallèle, no schedule)

À planifier au fur et à mesure entre les groupes ou après G5 :

| ID | Priorité | Description | LOC est. |
|----|----------|-------------|----------|
| BL-01 | P2 | A-001 PosCounterCollectModal MONTANT REÇU € consistency | ~5 LOC |
| BL-02 | P2 | B-002 multi-tab Echo broadcast race OSS | ~20 LOC (design + listener) |
| BL-03 | P1-CI | Q2 AllergenCoverageSentinel @group exclude block dans phpunit.xml | 3 LOC |
| BL-04 | P1 | Q3 RescueCommand silent-loss widen 5→12 (design analysis required) | ~10 LOC + design doc |
| BL-05 | P2 | 5 Playwright stress spec defects (ou retire le spec) | rework spec |
| BL-06 | feature | Multi-tranche split counter-collect (NF525 LOCK obligatoire) | ~80 LOC + LOCK doc |
| BL-07 | feature | KDS revert PREPARED→PREPARING (frozen-zone LOCK + Chef role) | ~60 LOC + LOCK doc |
| BL-08 | feature | Cash drawer count input feature (débloque vraie écart math) | ~120 LOC |
| BL-09 | feature | Dashboard redesign (handed off Claude Design) | dépend Claude Design |
| BL-10 | feature | Multilangues EN/AR catalog completion | gros chantier |
| BL-11 | infra | Cron crontab line si pas wired | 1 ligne |
| BL-12 | infra | Backup encryption gpg (optionnel) | ~30 LOC |

---

## GROUPE 7 — DÉFINITIVEMENT DEFERRED (pas avant owner explicit go)

Ces items sont en attente d'une décision explicite owner — NE PAS proposer / lancer sans go :
- Cloud / SaaS multi-tenant (mandate `no_cloud_until_owner_initiates`)
- HTTPS LAN + reverse proxy (single-machine V1)
- WebSocket self-hosted (laravel-websockets) (polling suffit)
- Monitoring sophistiqué (Sentry/Datadog) (logs Laravel suffisent V1)
- Refactor architecture Vue 2 → Vue 3 (risque énorme, pas de valeur V1)
- Multi-resto multi-branch (V2 SaaS scope)
- Loyalty card hardware RFID (V2 feature)

---

## 📋 Owner action items (par ordre de priorité)

### ⚡ AUJOURD'HUI
1. **Run G1.1 → G1.4** (16 min total) — manual verify post Wave Polish Final
2. Reporter dans le chat ce qui a marché / pas marché
3. Si défauts → on dispatch G2.1 heal scope-minimal

### 📅 DEMAIN MATIN
4. **G1.5** — verifier backup tourne (2 min)

### 📅 CETTE SEMAINE
5. **G2.2** — cleanup BRAIN V1.0.2 backlog (rapide après G1 all green)
6. **G3 Z1 soak test** — démarre dès que G1 all green

### 🗓️ DÉCISION INDÉPENDANTE
7. **G4.0** — choisir TPE/drawer/printer (peut commencer en parallèle de G3)

---

## 🎯 Critère unique go-live total V1 LOCAL Le Cayenne

**TOUTES les conditions ensemble** :
- ✅ G1.1-G1.6 all GREEN (manual verify)
- ✅ G3 Z1 soak 5j 0 P0
- ✅ G4 hardware integrated + sandbox 20+ transactions OK
- ✅ G5 shadow op 2 semaines 0 écart Z-report vs papier
- ✅ NF525 chain CHAIN OK chaque jour 14 jours consécutifs
- ✅ Backup auto + restore drill verified
- ✅ Owner manual sign-off

Sans une seule de ces conditions → ne pas passer en production live commercial.

---

## 📊 État actuel résumé

| Catégorie | État |
|-----------|------|
| Code V1 features | ✅ 14/14 Q1-Q14 owner decisions delivered |
| Cross-surface sync | ✅ Q9-S1 fix ΔT 0-60s → ~1s empirique |
| Stress test | ✅ 50 orders/3 concurrency/7s artisan PASS |
| Backup automation | ✅ Laravel cmd + restore drill PASS |
| Frozen-zone discipline | ✅ 0 violations sur 12+ commits |
| NF525 chain integrity | ✅ Bit-identical pre+post (62→62) |
| Convergence test-e2e | ✅ Round 1 GREEN, P0+P1=0 |
| Owner manual verify | 🟡 Pending G1.1-G1.6 |
| Backup actually run | 🟡 Demain matin G1.5 verify |
| Soak test 5 days | ⚪ Not started, ready to launch |
| Hardware integration | ⚪ Bloqué décision TPE owner |
| Shadow operation | ⚪ Depends Z1+Z2 complete |
| Production go-live | ⚪ Depends all above + sign-off |

Légende : ✅ done · 🟡 pending owner action · ⚪ not started

---

*Generated by orchestrator Claude Opus 4.7 (1M context) · post Wave Polish Final convergence · branch heal/cms-pr1-quickwins-2026-05-18 HEAD b8ce23851 · 2026-05-21*
