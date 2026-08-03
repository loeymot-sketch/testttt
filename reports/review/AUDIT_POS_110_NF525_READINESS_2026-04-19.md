# NF525 — Checklist technique (verdict **code + tests**, pas juridique)

Légende ligne : **OK** = démontré dans dépôt | **PARTIEL** | **GAP**

| Exigence type NF525 (technique) | Verdict | Preuve / manque |
|----------------------------------|---------|------------------|
| Journal des opérations inaltérable côté appli | **OK** | `AuditLog` immuable + HMAC + tests chaîne |
| Horodatage cohérent (Z signé) | **PARTIEL** | `Carbon::now()` — dépendance timezone `.env` |
| Numérotation commandes monotone par branche | **OK** | `FiscalSequenceService` + tests |
| Rapport Z périodique signé, enchaîné | **OK** | `ZReportService::close` + signature + prev hash |
| Clôture Z rejouable sans doublon | **OK** | Pas de Z open twice + `close` lock |
| Séquence Z monotone | **PARTIEL** | UNIQUE + cache lock ; **PAS** `lockForUpdate` sur MAX open (`F-FISC-001`) |
| Archive export | **OK** | `FiscalArchiveCommand` + tests archive |
| Rapport X cohérent Z | **OK** | `XReportService` agrège via service Z |
| Secrets production non triviaux | **PARTIEL** | Test guard Z secret ; généraliser autres clés |
| Benchmark 10k séquences / charge | **GAP** | Non exigé par tests CI (`F-FISC-004`) |
| Traçabilité annulation / retour | **PARTIEL** | Audit RETURNED P3 ; remboursement partiel backlog |
| Intégrité soft-delete Order | **OK** | `restore()` bloqué avec justification NF525 |

### Verdict binaire (code)

- **READY_TECHNIQUE_INTERNe** : oui — pour démonstration architecture journal + Z + séquences + tests d’intégration.
- **READY_CERTIFICATION_NF525_ORGANISME** : **non** — requiert audit tiers, procédure exploitation, documentation exploitation, clôture légale.

### Roadmap remédiation (priorité)

1. Mettre à jour **BUSINESS_RULES** (doc).  
2. Décider **hard Z.open** (lock SQL ou séquence SQL).  
3. Table **`order_payments`** si exigence métier finance.  
4. **Étendre** tests secrets + perf rush.  
5. **Engagement** organisme certificateur.
