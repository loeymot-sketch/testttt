# LOCK_FISCAL_VERIFYCHAIN_AGILITE_SECRETS — vérification tolérante aux secrets connus

> Autorisation d'override de zone gelée. Contrat entre Owner (gate humaine), Claude
> (diagnostic + implémenteur) et la discipline safety-check.

## §1. Identification
- **LOCK ID** : `LOCK_FISCAL_VERIFYCHAIN_AGILITE_SECRETS`
- **Créé** : 2026-08-08
- **Statut** : `APPROVED` — l'owner a choisi « Corrige sous LOCK » après présentation du
  diagnostic mesuré et de la portée exacte du changement.
- **Diagnostic source** : `reports/fiscal/DIAGNOSTIC_TAMPER_AUDIT_LOGS_2026-08-08.md`

## §2. Fichier gelé ciblé
| Path | Pourquoi gelé | Portée du changement |
|---|---|---|
| `app/Services/Fiscal/AuditLogService.php` | CLAUDE.md §7 — append-only, NF525-critique | `verifyChain()` **uniquement** (lecture seule). `computeHash()`, `canonicalise()`, `write()` et le chemin de SIGNATURE : **INTOUCHÉS**. |

## §3. Justification — mesurée, pas supposée

`php artisan fiscal:verify-chain --all` annonce `TAMPER: audit_logs.id=56` depuis le
2026-06-30. L'alarme est traitée depuis comme « anomalie connue et gatée », c'est-à-dire
ignorée. Recalcul des **783** signatures, en lecture seule :

| Constat | Valeur |
|---|---|
| Ruptures de chaînage `prev_hash` | **0** |
| Trous d'id (suppression) | **0** |
| Reproduites avec le secret **de leur branche** | 360 |
| Reproduites avec le secret **par défaut** | **423** |
| **Irréductibles (ni l'un ni l'autre)** | **0** ← aucune altération |

Toutes les lignes portent `branch_id = 1`. Les 423 vont de l'id **56 à 549**
(2026-06-30 → 2026-08-03) ; les **234** entrées postérieures sont toutes valides, donc le
chemin de signature actuel est **déjà cohérent** — aucun correctif de signature n'est requis,
contrairement à ce que je proposais initialement.

**Origine probable** (documentée comme hypothèse, pas comme fait) : aucun commit ne touche
`AuditLogService.php` ni `config/fiscal.php` entre le 28/07 et le 05/08 ; l'alignement du
03/08 vient donc d'un changement d'ENVIRONNEMENT — l'apparition ou la modification de
`FISCAL_AUDIT_SECRET_BRANCH_1` sur le VPS, qui fait basculer `secretFor(1)` de la valeur par
défaut vers l'override. Les lignes signées avant ce basculement ne peuvent plus être
reproduites avec l'override seul.

**Pourquoi ce n'est pas cosmétique** : l'intérêt d'une chaîne signée est de PROUVER la
non-altération à un tiers lors d'un contrôle NF525. Pour 423 lignes sur 783, l'outil officiel
du projet est aujourd'hui incapable de fournir cette preuve — et il annonce « TAMPER », ce qui
est faux. Une alarme fiscale qui crie au loup finit ignorée : c'est le cas depuis six semaines.
Même motif que la sentinelle `F013` corrigée le 07/08 (fenêtre fixe de 5000 caractères).

## §4. Scope — chirurgical, lecture seule
1. `verifyChain()` accepte une ligne si sa signature se reproduit avec le secret de sa branche
   **OU** avec le secret par défaut (agilité de clé — même principe que la rotation de clé
   d'API du 08/08).
2. `verifyChain()` ne s'arrête plus à la PREMIÈRE ligne fautive et sait distinguer
   « irréductible » (= suspect) de « reproductible avec un autre secret connu »
   (= incohérence interne). L'ancienne signature de retour est **conservée** pour ne casser
   aucun appelant.
3. **AUCUN changement** de `computeHash`, `canonicalise`, `secretFor`, `write`, ni de la
   sélection du secret à la SIGNATURE.

## §5. Ce que ce LOCK n'autorise PAS — explicitement
- ⛔ **Re-signer les 423 lignes.** La table est append-only ; les réécrire serait exactement
  l'altération que la chaîne existe pour exclure. Aucune écriture, jamais.
- ⛔ Accepter une signature qui ne se reproduit avec **aucun** secret connu : cela reste une
  ALTÉRATION et doit rester détecté. C'est la propriété la plus importante du correctif, et
  elle est prouvée par mutation (§6).
- ⛔ Toucher `ZReportService` ou `FiscalSequenceService`.

## §6. Acceptance (binaire)
- [ ] `php -l app/Services/Fiscal/AuditLogService.php` → exit 0
- [ ] Nouvelle suite `tests/Feature/Fiscal/AuditChainSecretAgilityTest.php` :
      secret de branche accepté · secret par défaut accepté · **payload modifié = DÉTECTÉ** ·
      `current_hash` modifié = DÉTECTÉ · `prev_hash` cassé = DÉTECTÉ · secret inconnu = DÉTECTÉ
- [ ] **MUTATION** : retirer la détection d'altération ⇒ les tests de détection ROUGISSENT
- [ ] Suites fiscales existantes vertes (Fiscal, Sentinels, Security)
- [ ] Sur la PRODUCTION, en lecture seule : **783/783 vérifiables, 0 altération**
- [ ] Frozen-zone diff limité au seul fichier de ce LOCK

## §7. Rollback
`git revert <sha>` — le patch est isolé dans son commit et ne touche qu'une méthode de
lecture. Aucun état persistant modifié, donc aucun rollback de données.

## §8. Marqueur scope
Commentaire au point de patch : `// [LOCK_FISCAL_VERIFYCHAIN_AGILITE_SECRETS 2026-08-08]`

## §9. Owner sign-off (gate humaine)
- **Preuve de directive** : l'owner a listé cette porte parmi celles à résoudre
  (« je veux résoudre et validé », puis « raisonne et prends les meilleurs choix »), et a
  choisi explicitement **« Corrige sous LOCK »** après lecture de la portée : vérification
  tolérante aux deux secrets, signature inchangée, aucune ligne re-signée.
- **Contresign** : ☑ choix explicite de l'owner le 2026-08-08.
