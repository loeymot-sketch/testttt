# Purge données fantômes seed — 2026-07-16 (FISCAL/MGMT terrain heal)

## Contexte
Audit terrain multi-agents (`wwxyrqgpf`) + investigation directe : **12 items Faker** (noms
lorem-ipsum « quia recusandae », « repellendus eius »… + `RJ-REF-DetTest`) et **15 taxes FIXED
corrompues** (`type=5`, noms absurdes « TVA 67% »/« TVA 97% », ids 16-30) polluaient la DB dev.

Chaque item Faker référençait une des taxes FIXED corrompues → si un tel item était encaissé, une
« taxe fixe » de 20 (montant, pas %) aurait faussé le prix (montant fixe > prix ligne). **MAIS** :
preuve DB live — **0 de ces 12 items n'était affiché dans une catégorie active** (borne/caisse) ; le
vrai menu Le Cayenne utilise `tax_id` 1/3 (`type=10` %). Pollution **isolée**, jamais servie.

## Action (env=local, réversible)
```
Items 127,128,129,132,133,134,135,136,138,139,140,144 → soft-delete (deleted_at)  [12]
Taxes 16-30 (type=5 corrompues)                        → status=1 (inactif)        [15]
```
- **Menu réel INCHANGÉ** : 51 items (catégorie active) avant = 51 après. Vérifié.
- 0 item Faker actif restant. 387 tests Menu/Catalog/Pricing/ItemResource verts après purge.

## Réversibilité
- Items : `Item::withoutGlobalScopes()->whereIn('id',[127,128,129,132,133,134,135,136,138,139,140,144])->restore();`
- Taxes : jamais hard-deletées (les orders historiques gardent leur référence) ; réactivation =
  `status=5` si un jour légitimes (elles ne le sont pas — ce sont des seed Faker).

## Note prod
En prod, cette purge = migration/commande gated owner. Ici (V1 LOCAL dev), nettoyage d'hygiène seed
délégué (owner « c'est toi qui gères ça »). Le garde métier idéal (refuser l'assignation d'une taxe
`type=FIXED` à un item de menu V1, 100% TVA %) reste un durcissement backlog — non requis tant que
`PricingService` (frozen §7) traite un défaut null comme 0 (inoffensif) et que le picker n'expose plus
ces taxes (désormais inactives).
