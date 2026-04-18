# AUDIT_MENU_AVAILABILITY_86_015 — Disponibilité & "86" (rupture)

## Meta
- **Priority** : P1
- **PRIMARY_MODEL** : Claude
- **TEST_STRATEGY** : `static-inspection`
- **DEPENDS_ON** : AUDIT_MENU_ITEM_CRUD_011
- **Estimation** : 0.5 j-h
- **Vague** : B5

## Contexte

"86" (terme pro) = item en rupture, temporairement indisponible. Task V1_MENU_86 référencée dans INDEX_V1. Risques : POS encaisse un item 86 (déception client cuisine), Kiosk ne met pas à jour en temps réel, écart entre stock réel et disponibilité affichée.

## Questions d'audit

1. L'indicateur 86 est-il une colonne booléenne `is_available` ou un statut enum (available / 86 / scheduled_unavailable) ?
2. La mise à jour disponibilité déclenche-t-elle un event `MenuItemAvailabilityChanged` (cf EventContract L39) ?
3. Le event contient-il au minimum `item_id` et `status` (required payload keys) ?
4. Les surfaces POS et Kiosk sont-elles abonnées au channel `private-branch.{id}` pour recevoir l'event en temps réel ?
5. Un item 86 est-il refusé serveur à la création de commande (pas juste grisé en UI) ?
6. Les disponibilités par créneau (ex "menu midi 11h-14h") sont-elles modélisées ou simulées via manipulation manuelle ?
7. L'historique des mises en 86 est-il tracé (qui, quand, pour quoi) pour l'analyse cuisine ?
8. Le stock par option (addon) peut-il être 86 indépendamment de l'item parent ?
9. Le retour à disponibilité déclenche-t-il également un event pour refresh client ?
10. Le KDS est-il notifié pour arrêter d'attendre un item 86 en préparation déjà lancée ?

## Scope

### SUBSYSTEMS_TOUCHED
- `app/Models/Item.php` (colonne disponibilité)
- `app/Http/Controllers/Admin/Item/*` (endpoint toggle 86)
- `app/Events/ItemAvailabilityChanged.php` ou `MenuItemAvailabilityChanged.php`
- Listeners correspondants (si outbox)
- Vues POS/Kiosk abonnées

## Invariants at Risk
- [x] Dispatch after DB commit
- [x] Synchronisation temps réel backbone
- [ ] Pricing (indirect)

## Fichiers à lire
1. `app/Models/Item.php` (attributs is_available ou status)
2. Controller toggle
3. `app/Events/*Availability*`
4. `app/Domain/Events/EventContract.php` (déjà lu : broadcast-as `ItemAvailabilityChanged`, type `EventType::MENU_ITEM_AVAILABILITY_CHANGED`)
5. Listeners abonnés Vue (KioskMenuComponent, POS menu)

## Grep patterns

```
grep -rn "is_available\|is_86\|availability\|unavailable" app/Models/Item.php
grep -rn "ItemAvailability\|MenuItemAvailability" app/Events/ app/Listeners/
grep -rn "toggle.*available\|mark86\|markUnavailable" app/Http/Controllers/
grep -rn "ItemAvailabilityChanged" resources/js/
grep -rn "schedule_available\|availability_window" app/Models/
```

## Evidence required
- Colonne(s) / statut de disponibilité.
- Existence du listener outbox + event broadcast.
- Validation serveur refusant un item 86 à la création commande.
- Abonnements Vue (POS + Kiosk) au channel + action reçue.

## Grille de verdict
- **PASS** : event conforme V1, refus serveur, surfaces abonnées et réactives, KDS notifié.
- **WARN** : surfaces rafraîchies via polling au lieu de Echo (dégradé mais acceptable V1).
- **BLOCKED** : POS/Kiosk encaissent des items 86, aucun event émis, pas de refus serveur.

## Livrable
`reports/review/AUDIT_MENU_AVAILABILITY_86_015_<DATE>.md`

## Status
- [x] Brief rédigé
- [ ] Plan approuvé
- [ ] Audit exécuté
- [ ] Rapport
- [ ] Tasks correctrices
- [ ] Closed
