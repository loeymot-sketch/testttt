# Playwright / E2E verification Report 009

## Date
2026-03-10

## Environment
- branch: main
- DB: SQLite In-Memory (Automated QA Script)
- Tool: PHPUnit Feature Tests `AntiGravityTest`

## Résumé du Cycle (Sprint 5.1)
L'utilisateur a corrigé l'assertion du test `T01` (retrait de la sous-clef `'data'`) et a replacé les settings de thème (`theme_favicon_logo`, `theme_logo`, `theme_footer_logo`) dans le seeder de la table globale `settings`, là où l'application s'attend vraisemblablement à les trouver.

**Résultat : 16 réussites, 2 échecs ! Le record est battu.**

## Passed (16)
✅ **T01**, T02, T03, T04, T07, T08, T09, T10, T11, T12, T13, T14, T18, T20, T22, T23.
- **T01 (Kiosk login)** marche parfaitement, l'API renvoie bien le token sans encapsulation `data`.
- Tout l'écosystème de permissions, de rôles, de pricing et de workflow (statuts de commande) fonctionne dans l'environnement SQLite.

## Failed & Errors (2)

1. 💥 **T05 (Kiosk cannot access admin)** & **T06 (Kiosk can create order)** :
   - Échecs Constants.
   - L'erreur sous-jacente est toujours le fameux : `Attempt to read property "faviconLogo" on null`.
   - **Diagnostic Final** : Même en ayant injecté `theme_favicon_logo` dans la table `settings`, l'API ou le layout renvoie inexorablement cette erreur `null`. Cela signifie que soit (1) L'helper ou la classe `ThemeSetting` ne charge *pas* les valeurs injectées (peut-être un système de cache ou un nom de table/modèle diffèrent en production), soit (2) La valeur `null` récupérée en DB fait crasher une méthode qui attend strictement une chaîne de caractère, sans être "Null-Safe".

## Suggested Next Tasks (For Claude Planning - Sprint 5)

Il est temps de poser la manette de tests et d'ouvrir le code source de l'application. On est à **16/18**, il ne reste stricto sensu qu'un seul et unique bug de framework.

1. **[Kimi] Fix `faviconLogo` Null Pointer (Le Boss Final)** : 
   - Cherchez dans le code backend (probablement `app/Http/Resources`, ou les classes gérant les Notifications/Emails/Reçus) toute référence à `faviconLogo` ou `theme_favicon_logo`.
   - Modifiez le code applicatif pour qu'il soit Null-Safe de manière défensive (ex: `$settings?->faviconLogo ?? ''`).
   - Alternativement, vérifiez comment `ThemeSetting` est instancié. Peut-être qu'un `Artisan::call('cache:clear')` manque dans le Setup des tests.
