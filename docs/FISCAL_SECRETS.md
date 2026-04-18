# Fiscal Secrets — Rotation & Runbook

**Scope** : les deux secrets HMAC qui signent la chaîne d'audit NF525
et les Z-reports. Ce document est à jour au 2026-04-18 (phase POS-9
hardening — H.3.9).

## 1 · Identifiants et configuration

| Variable `.env` | Config Laravel | Consommateur | Rôle |
|---|---|---|---|
| `FISCAL_AUDIT_SECRET` | `config('fiscal.audit_secret')` | `AuditLogService::computeHash()` | Clé HMAC-SHA256 chaînée sur `audit_logs` (INSERT-only) |
| `FISCAL_Z_REPORT_SECRET` | `config('fiscal.z_report_secret')` | `ZReportService::sign()` | Clé HMAC-SHA256 sur la clôture Z (canonicalisée UTC ISO-8601) |

### Garde production (POS-9-H.2.1)

`config/fiscal.php` impose deux règles en `APP_ENV=production` :

1. **Aucune valeur sentinelle de dev** — les chaînes listées dans
   `config('fiscal.dev_sentinels')` (notamment les valeurs historiques
   `unit-test-secret`, `dev`, `changeme`, `secret`, `test`) sont
   **refusées** au boot. Toute tentative d'écriture dans
   `AuditLogService::write()` ou `ZReportService::{open,close}()` lève
   une `RuntimeException` avant même d'allouer un `sequence_no`.
2. **Longueur minimale** — `config('fiscal.min_secret_length')` = 32
   caractères. Un secret plus court est rejeté de la même façon.

En environnement de test / staging (`APP_ENV=testing|local|staging`)
les deux gardes sont désactivées pour ne pas casser la CI, mais les
valeurs par défaut en `config/fiscal.php` sont volontairement `''`
(chaîne vide) pour que même un staging mal configuré hurle au boot.

---

## 2 · Rotation d'un secret

Les deux secrets sont **indépendants** : la rotation de
`FISCAL_AUDIT_SECRET` n'invalide pas les Z-reports existants, et
inversement. Elles peuvent être effectuées en fenêtre séparée.

### ⚠ Avertissement fondamental

La chaîne `audit_logs` est INSERT-only et chaque row signe la
précédente. **Faire tourner le secret casse la vérification de chaîne
pour toutes les rows signées avec l'ancienne clé.** C'est volontaire —
sans cela, un attaquant qui vole le secret peut réécrire l'historique.
La bonne stratégie de rotation est donc :

1. **Ne jamais** re-signer l'historique. Les anciennes rows restent
   signées avec l'ancien secret, qui doit être archivé hors-ligne pour
   permettre la vérification offline ultérieure.
2. **Tracer** le changement dans `audit_logs` elle-même : le dernier
   événement avant rotation doit être un `audit.key_rotation` avec la
   date et le hash prefix de la nouvelle clé.

### Checklist — `FISCAL_AUDIT_SECRET`

```
[ ] 1. Geler toute écriture sensible (désactiver les endpoints POS
       mutateurs pendant la fenêtre de maintenance — 2-3 min suffisent).
[ ] 2. Émettre manuellement l'événement final avec l'ancienne clé :
         php artisan fiscal:audit-keyrotation-prepare
       (ou via AuditLogService::write(['action' => 'audit.key_rotation',
        'payload' => ['reason' => '...']]))
[ ] 3. Exporter le dernier `current_hash` par branche pour preuve :
         php artisan fiscal:audit-chain-head > /secure/chain-heads-YYYYMMDD.txt
[ ] 4. Générer le nouveau secret (au moins 32 caractères, entropie
       forte — recommandé `openssl rand -hex 32`).
[ ] 5. Mettre à jour `.env` et le secret manager (AWS SM / Vault / ...).
[ ] 6. Déployer et redémarrer php-fpm / queue workers.
[ ] 7. Vérifier que AuditLogService::write() fonctionne (un event de
       contrôle `audit.key_rotated`).
[ ] 8. Archiver l'ancien secret dans un stockage froid chiffré avec la
       date et les hash heads exportés. Ne jamais le détruire —
       l'obligation NF525 de vérifier les 6 ans d'historique l'exige.
```

### Checklist — `FISCAL_Z_REPORT_SECRET`

```
[ ] 1. Attendre la clôture du Z courant de chaque branche active.
       (Un Z en cours serait signé avec l'ancienne clé puis la chaîne
        ZReport.prev_hash pointerait sur un HMAC que la nouvelle clé
        ne peut plus reproduire — accepté par design, mais ajoute une
        discontinuité.)
[ ] 2. Exporter les signatures des derniers Z fermés par branche :
         SELECT branch_id, MAX(sequence_no) AS seq, signature
         FROM z_reports WHERE status='closed' GROUP BY branch_id;
[ ] 3. Générer le nouveau secret (`openssl rand -hex 32`).
[ ] 4. Mettre à jour `.env` + secret manager + redéployer.
[ ] 5. Pour chaque branche, ouvrir le Z suivant : prev_hash sera bien
       la signature exportée au point 2 ; le nouveau Z sera signé
       avec le nouveau secret. La discontinuité est documentée dans
       la note de fermeture du Z précédent.
[ ] 6. Archiver l'ancien secret en stockage froid chiffré.
```

---

## 3 · Génération d'un secret

**Requis** : ≥ 32 caractères, entropie forte, jamais réutilisé.

```bash
# Option 1 — OpenSSL (recommandée)
openssl rand -hex 32

# Option 2 — /dev/urandom
head -c 32 /dev/urandom | base64

# NE PAS utiliser — trop faible
echo "my-fiscal-secret"   # sentinel dev, rejetée en prod
date +%s | sha256sum       # entropie prévisible
```

---

## 4 · Stockage

| Environnement | Stockage |
|---|---|
| Dev local | `.env` (jamais committé — `.env.example` documente la variable sans valeur) |
| Staging | Variable d'env du container ; secret manager si disponible |
| Production | AWS Secrets Manager / HashiCorp Vault / Google Secret Manager ; jamais dans le repo, jamais dans un dump de config |

Les secrets archivés (post-rotation) vivent dans le même secret
manager, avec une convention de nommage `fiscal-audit-secret-v{N}` /
`fiscal-z-secret-v{N}` et un tag `archived` + date de rotation.

---

## 5 · Vérification hors-ligne (audit NF525)

Pour rejouer la chaîne sur un export offline (6 ans plus tard) :

```bash
php artisan fiscal:audit-verify --branch={id} --secret-history=/secure/secrets.json
# secrets.json format:
#   [{"from_date":"2026-04-18","until_date":"2026-09-01","secret":"..."}]
```

(commande prévue dans `FiscalArchiveCommand` ; à livrer en POS-9.5/9.6)

---

## 6 · Incident — soupçon de fuite

1. Désactiver immédiatement les endpoints `/admin/fiscal/z-report/*`
   (flip le flag `pos_fiscal_enabled` à `false` côté admin).
2. Suivre la checklist de rotation ci-dessus.
3. Auditer les N dernières rows de `audit_logs` et `z_reports` pour
   détecter un pattern d'écriture anormal. Le log channel `fiscal`
   (400 jours de rétention) conserve les breadcrumbs
   `audit_log.write`, `z_report.open`, `z_report.close` — croiser
   `branch_id`, `user_id`, `hash_prefix` avec Sentry / SIEM.
4. Consigner l'incident dans `audit_logs` (action `security.incident`)
   AVEC l'ancienne clé, puis rotate.

---

**Dernière mise à jour** : 2026-04-18 (POS-9-H.3.9).
**Propriétaire** : équipe Backend/Sécurité FoodKing.
**Références** : `config/fiscal.php`, `AuditLogService.php`,
`ZReportService.php`, `FiscalSecretProductionGuardTest.php`.
