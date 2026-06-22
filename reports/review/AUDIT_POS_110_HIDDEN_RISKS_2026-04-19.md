# Risques non évidents (hors audit « visible »)

1. **Double commande silencieuse** : idempotence **nouvelle clé** à chaque ouverture paiement — utilisateur clique vite « Payer » deux fois avec chemins parallèles → **deux UUID** → deux commandes facturées (`F-STATE-002`).

2. **Admin KDS omniscient** : erreur humaine — mauvaise branche cuisinée ; **pas** une faille ACL si rôle admin intentionnel.

3. **BUSINESS_RULES obsolète** : équipes croient « pas de stock » alors que le code **rejette** rupture — mauvais runbooks support (`F-SYNC-001`).

4. **Z.open sans SELECT FOR UPDATE sur MAX** : fenêtre théorique si lock cache contourné (multi-instance Redis mal configuré) — **P1 infra** plus que code.

5. **Tests fiscaux verts** : ne prouvent pas **état Redis** / désactivation cache / horloge serveur skew.

6. **RESTORE disabled** : restaurer DB backup ligne `orders` deleted **sans** passer par Eloquent peut contourner le modèle — **DBA discipline**.

7. **Pas d’order_payments** : reporting finance « par ligne TPE » requiert **reconstruction** depuis champs `orders` ou exports — dette analytics.

8. **Permissions fiscal via `can()`** : oubli d’un check sur **nouvelle** route fiscal = risque ; middleware explicite plus auditable.
