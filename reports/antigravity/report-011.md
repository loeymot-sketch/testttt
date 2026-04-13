# Playwright / E2E verification Report 011

## Date
2026-03-10

## Environment
- branch: main
- DB: SQLite In-Memory (Automated QA Script)
- Tool: PHPUnit Feature Tests `AntiGravityTest`

## Résumé du Cycle (Sprint 6.1)
L'utilisateur a enrichi le Factory SQLite en renseignant le champ `group` pour chaque setting inséré (`site`, `order_setup`, `company`, `theme`). C'était une excellente intuition pour s'assurer que le modèle de réglages (Settings Model) les charge bien en mémoire.

**Résultat : 16 réussites, 2 échecs (Inchangé).**

## Passed (16)
✅ T01, T02, T03, T04, T07, T08, T09, T10, T11, T12, T13, T14, T18, T20, T22, T23.
- Les tests d'autorisations et workflows complets sont verrouillés de façon stable.

## Failed (2) - Le Fantôme Applicatif
1. 💥 **T05 (Kiosk cannot access admin)** & **T06 (Kiosk can create order)** :
   - Échecs Constants.
   - Les traces du Deep Audit remontent vers le cœur de l'application. Ajouter le `group` aux injections SQLite confirme que le bug n'est *pas un problème de base de données*. La propriété `faviconLogo` manque à l'appel.

   - **Diagnostic Explicite pour Claude/Kimi** : 
     L'application FoodKing utilise une classe ou un Singleton (souvent `app(ThemeSetting::class)` ou un helper global) qui est initialisé au Boot du framework APRES ou AVANT nos migrations. Dans l'environnement de Test SQLite :
     1. Le helper charge le cache ou la structure par défaut, qui se trouve être un objet `null` ou array non instancié.
     2. Plus loin (dans les Controllers, Resources, Notifications), du code PHP essaie de faire `$theme->faviconLogo`.
     3. Cela renvoie `Attempt to read property "faviconLogo" on null`.

## Action REQUISE (For Claude Planning)
**Il ne faut plus ajuster le test. Le test a fait son job : débusquer un Null Pointer qui crasherait en production.**

1. **[Kimi] Localiser la faille PHP**: Chercher `faviconLogo` dans les fichiers `.php` (hors tests).
2. **[Kimi] Appliquer le Null-Safe**: Remplacer l'appel fatal (ex: `app(ThemeSetting::class)->faviconLogo`) par la version robuste (`app(ThemeSetting::class)?->faviconLogo`).
