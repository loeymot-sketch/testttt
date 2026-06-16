# FoodKing fast-food — Avis + no-harm robustness hardening — CONVERGÉ
**Date:** 2026-06-16 · Branche `goal/wizard-wysiwyg-builder-2026-06-14` HEAD `289e9fb74` (LOCAL, push = owner gate).
Demande owner : avis + audit + petites corrections de structure/robustesse du système fast-food ACTUEL (Le Cayenne), **SANS rien casser** de la personnalisation, bien structuré pour le futur — pas de DB, pas de SaaS, boucle plan→audit→dispute→exécute→test-e2e preuve→dispute→validé→suivant.

## VERDICT : ✅ CONVERGÉ — système ACTUEL validé SOLIDE, 8 améliorations sûres livrées, 0 régression résiduelle.

## AVIS (la demande #1 de l'owner)
**Le système fast-food actuel est SOLIDE et bien structuré pour ses besoins actuels.** Audit adversaire des 5 systèmes :
- **BORNE** « production-grade » (idempotence session, file offline robuste, re-quote anti-mésfacturation NF525).
- **CAISSE** « la surface la moins risquée » (triple-défense concurrence, NF525 raw via triggers, 29/29 cash).
- **KDS+OSS** « inhabituellement durci » (release-rule SSOT, bump optimistic-lock, sync adaptatif).
- **CENTRAL + CORE partagé** « exceptionnellement durci » (outbox 4 voies, idempotence triple-défendue).
La discipline « zéro casse » a tenu : la dispute a **rejeté 3 propositions** (prémisse fausse / sur-ingénierie sur code paiement / casse une politique ops sentinelle). Aucun DB/SaaS/frozen justifié maintenant.

## PHASE 2 — 8 améliorations SÛRES (chacune validée preuve technique + interface, 9 commits `bd561591b`→`289e9fb74`)
| ID | Système | Correction (petite, comportement préservé) | Preuve |
|---|---|---|---|
| CENTRAL-01 | CENTRAL | supprimé `if/else env(DEMO)` mort (branches byte-identiques) | Item 9/9, no-op |
| BORNE-02 | BORNE | fallback `crypto.getRandomValues`→Math.random (seul site non gardé du repo) | Vitest 5/5 + kiosk 200 |
| CAISSE-S1 | CAISSE | formule cash-attendu dupliquée 3× → 1 méthode `expectedCashForSession` (anti-dérive) | drift-lock + **CashOverviewControllerTest 19/19** |
| KDS-01 | KDS | `v2OfflineSince` mort réactivé → bannière rouge OFFLINE V2 enfin atteignable | unit 3/3 + **preuve live :8766** (`.kds-banner--error` rendu) |
| OSS-01 | OSS | toast d'erreur du mur public gardé derrière `authBranchId()>0` | source-test 2/2 + chime 7/7 |
| CAISSE-S2 | CAISSE | try/catch + log + FAILURE sur la commande de purge planifiée | 2/2 (happy + échec) |
| KDS-02 | KDS | `catch(HttpException)` avant `catch(Exception)` (pas 403→422) dans 3 lectures KDS | 2/2 (403 réel) |
| CENTRAL-02 | CENTRAL | `Log::error` dans 2 endpoints PDF fiscaux (réponse byte-identique) | sentinelle EodPdf 6/6 |

## PHASE 3 — audit + test global + dispute finale (LA valeur de la discipline)
La dispute finale a **attrapé 1 VRAIE régression P1 dans MON heal CAISSE-S1** : en extrayant la formule, j'ai supprimé `$movementsSum` dans `CashOverviewController` qui avait DEUX consommateurs — `$expectedCash` (rerouté ✓) ET `cash_collected => round($movementsSum,2)` (RATÉ) → `cash_collected` affichait `0,00 €` (ou 500 en debug) pour tout tiroir ouvert.
- **Cause du raté** : j'ai lu seulement les lignes `$expectedCash`, pas tout `index()`, et j'ai lancé le test du SERVICE (CashDrawerServiceTest) mais PAS le test du CONTROLLER (CashOverviewControllerTest) qui couvrait exactement ça. Classique **test-vert mais UI-cassée**.
- **Heal** (`289e9fb74`) : re-source `cash_collected` (le Σ net signedAmount = valeur DIFFÉRENTE de expected_cash = opening+Σ). Restauration byte-identique. CashOverviewControllerTest **19/19** (les 2 tests régressés re-verts).
- **Re-vérif adversaire** (run réel 204k tokens) : `validated: true`, 0 finding → fix tient + balayage final propre.

## Preuves finales
- Build complet OK · **frozen diff 0** (15 fichiers) · NF525 intact (zéro chaîne touchée).
- Tests heals (par fichier) : Vitest **7/7** + Item **9/9** + CashDrawerService **18/18** + CashOverviewController **19/19** + PosPurge **2/2** + KDS-HttpException **2/2** + EodPdf **6/6**.
- Échecs pré-existants des suites cash/KDS = **baseline-identiques** (confirmés par stash — JAMAIS introduits par ce batch).

## Leçon meta (à retenir)
**Extraire/supprimer une variable → grep TOUS ses consommateurs + lancer le test du CONTROLLER, pas juste du service.** Mon « frozen 0 + test ciblé » a raté la régression ; c'est la **dispute adversaire finale** (PHASE 3) qui l'a attrapée. Le test-vert-sur-service ne prouve pas l'UI/controller. La boucle plan→exécute→**dispute**→corrige a fonctionné exactement comme l'owner l'a conçue.

**Système fast-food actuel : validé, plus robuste, intact, prêt pour évolution future progressive.** Reste = gates owner (push).
