# Axes 18–19 — i18n & déploiement multi-branche

## 18 — i18n

- Messages validation mélangés FR / clés techniques selon couches (`pos_payment_method`, erreurs Laravel).
- UI POS / KDS : fichiers `lang/*` + `$t()` Vue — pas de couverture exhaustiva ce run (`F-I18N-001`).

## 19 — Déploiement

- **Branches** : `branch_id` sur commandes, Z, séquences — architecture multi-tenant logique.
- **TVA / signatures** : paramètres items + `ZReportService::sign` — dépend des données branch + `.env` `fiscal.*`.
- **Parité env** : `FiscalSecretProductionGuardTest` aide ; **pas** de preuve staging=prod identique (`F-DEP-001`).
- **Hardware** : profils kiosk / imprimante — hors validation code ici.

**Liens tracker :** F-I18N-001, F-DEP-001.
