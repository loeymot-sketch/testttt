# Verrou V1 — Sur place (dine-in) DÉSACTIVÉ

**Date** : 2026-05-06
**Décision** : utilisateur (TristaOdette596@outlook.com)
**Scope** : POS + borne kiosk + KDS — toute la chaîne FoodKing V1

## Décision

Pour la **V1 actuelle**, l'option **"Sur place" / "Dine-in" est DÉSACTIVÉE**. Toutes les commandes sont par défaut **À emporter (TAKEAWAY)** ou **Livraison (DELIVERY)**. Pas de plan de salle / sélection de table en V1.

**Raison** : focus produit sur fast-food à emporter / livraison. Le mode service à table sera réintroduit dans une **V1.5+** quand le besoin métier réel apparaîtra (nouvelle franchise avec service en salle).

## État technique

✅ **Le verrou est DÉJÀ en place** dans le code :

- Feature flag : `pos.dine_in_enabled` (default `false` côté Laravel config)
- Côté frontend Vue : `PosComponent.vue` lit `pos_dine_in_enabled` depuis settings store et conditionne l'affichage du toggle "Sur place" + table selector via le computed `dineInEnabled` (lignes 1220-1226)
- Lignes concernées :
  - `resources/js/components/admin/pos/PosComponent.vue:298` — commentaire " 'Sur place / À emporter' pattern"
  - `resources/js/components/admin/pos/PosComponent.vue:305-321` — radio dine-in conditionnel
  - `resources/js/components/admin/pos/PosComponent.vue:425-431` — table selector conditionnel
  - `resources/js/components/admin/pos/PosComponent.vue:2258-2267` — restore parked order dine-in seulement si `dineInEnabled`
- Côté DB : aucune entrée dans `settings` table pour la clé `pos_dine_in_enabled` → default `false` s'applique

✅ **Captures cycles 1-5 confirment** : le segmented control affiche **uniquement "À emporter / Livraison"** (visible dans `02-surface-load-initial.png`, `06-categories-strip-after-click-2nd.png`, etc.).

## Comment réactiver plus tard (V1.5+)

Au moment voulu, **1 ligne suffit** pour réactiver :

```sql
INSERT INTO settings (group, key, value, created_at, updated_at)
VALUES ('pos', 'pos_dine_in_enabled', '1', NOW(), NOW());
```

Ou via interface admin Settings si elle expose cette clé. Aucune modification de code requise — la structure backend (table `dining_tables`, `FloorplanController`, état Vue, etc.) est déjà en place.

## Conséquences pour audit cycle 6+

- **Floorplan / Plan de salle** est **HORS SCOPE V1**. La page `/admin/pos/floorplan` charge OK (validé cycle 5) mais ne sera pas exposée dans le menu admin.
- Les findings cycle 5 deep flows P1-1 "Dine-In Floorplan cross-branch vulnérabilité" sont **REPORTÉS V1.5+**. Quand on ré-active dine-in, on devra :
  - Tester floorplan cross-branch (test absent aujourd'hui)
  - Ajouter UX table selector au checkout
  - Auditer race condition `release` + `assign` simultanés
- Les routes API `/api/pos/floorplan/*` restent disponibles (pas de désactivation backend) mais ne sont pas appelées depuis le frontend tant que flag `false`.

## Tests

- **Sentinel à ajouter** (cycle 6) : `tests/Feature/Sentinels/DineInDisabledV1Sentinel.php`
  - Vérifier que le segmented control POS expose **uniquement** TAKEAWAY + DELIVERY (pas DINING_TABLE) quand flag false
  - Vérifier que `POST /api/admin/pos` avec `order_type=DINING_TABLE` est **rejeté** quand flag false (à confirmer côté `PosOrderRequest` validation)

## Mémoire utilisateur

Cette décision est sauvegardée dans la feedback memory persistante :
- `~/.claude/projects/-Users-.../memory/feedback_v1_dine_in_disabled_2026-05-06.md`
- Index : `MEMORY.md`

**À ne pas réactiver** sans nouvelle décision utilisateur explicite.
