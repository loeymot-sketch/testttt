# LOCK — intégrité prix / POS multi-sauces

**ID :** `LOCK_ORDER_INTEGRITY_KDS_LOYALTY_2026-09-16`
**Date :** 2026-09-16
**Statut :** autorisé par le gate propriétaire existant, sans nouvelle auto-approbation.
**Gate d’autorité :** `docs/gates/GATE_ORDER-INTEGRITY-KDS-LOYALTY-20260914_2026-09-14.md`

## Autorisation déjà enregistrée

Le gate ci-dessus est marqué **Approved — option 1** par le propriétaire le 2026-09-14. Sa portée cite explicitement les surfaces POS gelées, le pricing/scellement serveur, le supplément libre fiscalisé et la vérification UX critique. Ce LOCK ne crée donc aucune nouvelle autorisation : il consigne les deux empreintes exigées par la sentinelle SHA-256 pour ce périmètre déjà approuvé.

## Fichiers gelés et empreintes

| Fichier | SHA-256 avant | SHA-256 après | Raison bornée |
|---|---|---|---|
| `app/Services/Pricing/PricingService.php` | `e8e53af65600535b5fde98bc5f4ed323fa22389ce756a552af93e9848a905900` | `d84902fd4d7a6f6e7e6d345c61170e078ef39d5402a7b530049c0a44a0280a61` | Ligne POS libre validée côté serveur, TTC/fiscalisée, scellée et isolée par branche. |
| `public/js/pos-wizard.js` | `fb35ddb0c3f0a51903734b1fb6eaa00d100fb4053eff776bdca59a679a09e52a` | `d4be19d72976155d5a001bc7075e7cb5983209b4fbc2917f2524df0cfde6aa9d` | Fermeture différée limitée à son instance pour ne pas détruire une édition immédiatement rouverte de deux sauces. |

La baseline `tests/Feature/Sentinels/frozen-zone-sha256-baseline.json` doit accompagner exactement ces changements.

## Invariants vérifiés

- Le navigateur ne calcule ni n’impose un prix : le supplément est repris, borné et taxé par `PricingService`.
- La ligne libre exige une branche POS valide et rejoint le snapshot immuable de commande.
- La fermeture du wizard ne modifie aucune donnée métier ; elle évite uniquement que le minuteur d’une instance fermée supprime une nouvelle instance.
- Le test Chromium réel garde Andalouse + Algérienne et `7,90 €` après réouverture et confirmation.

## Validation et rollback

- `FrozenZoneSha256BaselineSentinelTest` et `WithoutGlobalScopesAuditSentinelTest` doivent passer après cette trace.
- Le rollback rétablit les deux fichiers et les deux valeurs SHA dans le même commit ; aucune donnée existante ni commande scellée n’est réécrite.
