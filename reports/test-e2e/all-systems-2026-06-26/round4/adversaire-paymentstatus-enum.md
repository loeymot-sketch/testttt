# Vérification adversaire — payment_status hors-enum (orders 9, 68)

## Candidate
[P3] foodking_e2e.orders / app/Enums/PaymentStatus.php:7-10 — payment_status 0 et 1 sur 2 commandes seed.

## Verdict : REFUTED

### Repro (confirmée mais inoffensive)
```
SELECT id,payment_status,status,order_type,fiscal_sequence_no,created_at
FROM orders WHERE payment_status NOT IN (5,10,15,20);
 id | payment_status | status | order_type | fiscal_sequence_no | created_at
  9 |              0 |      1 |         10 | NULL               | 2026-05-28 14:48:22
 68 |              1 |     13 |          2 | NULL               | 2026-05-28 18:02:22
```
- 2 lignes sur 2968. Toutes deux du **premier jour de seed** (2026-05-28).
- `fiscal_sequence_no = NULL` sur les deux → **jamais fiscalisées** : zéro impact NF525, n'entrent dans aucun Z.

### Pourquoi inoffensif V1-LOCAL
1. **Aucun chemin de code de prod ne compare payment_status à 0 ou 1.**
   `grep -rn payment_status app/ | grep -E '==0|==1|<5'` → 0 résultat. Le seul gate « payé » est `PAID=5` ; 0 et 1 (< 5) ne matchent jamais PAID → aucune commande traitée comme payée à tort. Aucune sous/sur-facturation, aucune fuite.
2. **Aucun seeder/factory courant n'écrit 0/1** : `OrderFactory`, `OrderTableSeeder*`, `KdsOrderTableSeeder` écrivent tous 10/5. Les 2 lignes 0/1 sont des inserts ad-hoc orphelins du jour 1, pas une régression du code actuel.
3. Pas d'argent, pas de fiscal, pas de fuite, pas de food-safety, pas d'UX réelle dégradée. Le candidat lui-même admet « Pas de bug code de prod prouvé ».

### Classement
Données de seed périmées (test-pollution), read-only, sans nuisance. Tombe dans le motif **test-data pollution déjà connu** (`catalog:clean-test-data`, projets borne 2026-06-22). N'est ni P0/P1 (pas NF525/argent/fuite) ni même un défaut de code reproductible. Pas un finding livrable.

### Reco
Si nettoyage souhaité un jour : `UPDATE orders SET payment_status=10 WHERE id IN (9,68)` (hors-scope read-only de cette session). Aucun heal code requis — l'enum et le code de prod sont sains.

### Frozen
Non concerné (aucun fichier frozen ; aucune modif).
