# PR-05 — `/menu` renvoie un 404 serveur (doublon d'assets)

**Gravité (mandat owner)** : P3 (cosmétique — vitrine éteinte volontairement).
**Risque d'exécution** : NUL (verdict : laisser).

---

## §1 — Problématique + cause racine
`GET /menu` → 404 du serveur (pas la SPA). Cause : le dossier physique `public/menu/le-cayenne-v2/` masque la route Vue `frontend.menu` côté serveur (le serveur statique sert le dossier avant le routage). La vitrine étant éteinte (`staff_only_mode=true`), `/menu` inaccessible = but atteint, mais via un 404 au lieu d'un redirect propre.

## §2 — ⚔️ Découverte adversariale (corrige ma crainte initiale)
- **`public/menu/le-cayenne-v2/` est un DOUBLON** : les **86 fichiers existent à l'identique dans `public/images/menu/`** (271 fichiers), et `config/menu_images.php:30 base_path => 'images/menu'` lit depuis `images/menu`, **pas** depuis `menu/le-cayenne-v2`.
- **0 référence** au dossier v2 : aucune en code (PHP/JS/Vue/CSS/Blade), aucune en DB (table `items` **sans colonne image** ; Spatie MediaLibrary, `media` 0 ligne cayenne), aucune en config. → contrairement à ma crainte « 86 images catalogue », **ce dossier est un orphelin supprimable**.
- **Prod AUSSI 404/403** (pas dev-only) : nginx `try_files $uri $uri/` matche le dossier → 403 ; apache `!-d` saute la réécriture. Donc cosmétique partout (vitrine morte).

## §3 — Fichiers concernés (vérifiés)
- `public/menu/le-cayenne-v2/` (86 PNG, **doublon orphelin**) — seul objet d'une éventuelle option B
- `public/images/menu/` (271 PNG) — **LOAD-BEARING** (source catalogue réelle) → NE PAS toucher
- `config/menu_images.php:30` (base_path = images/menu)
- `resources/js/router/modules/frontendRoutes.js:26` (route SPA `frontend.menu`, jamais atteinte)
- `deploy/ansible/templates/nginx-foodking.conf.j2:53` (option C prod)

## §4 — Solution + raisonnement fort (verdict : A)
- **Option A — LAISSER (recommandé)** : vitrine morte, pas de lien nav vers `/menu`, personne n'y va. Le 404/403 est invisible en exploitation. **Risque nul.**
- **Option C (si un jour souhaité)** : 1 ligne web-server prod `location = /menu { return 301 /login; }` — **zéro asset déplacé, zéro code, zéro DB**, prod-only.
- **Option B (déconseillée)** : renommer `public/menu/le-cayenne-v2/` → blast radius = **0 référence** (juste 86 déplacements de fichiers doublons) mais plus de churn que C pour le même gain cosmétique.

## §5 — Effets négatifs (par option)
- A : aucun.
- C : redirect prod seulement (ne corrige pas le dev `artisan serve` — une route Laravel ne peut jamais battre un dossier `public/` existant sous le serveur intégré).
- B : déplacer 86 fichiers ; risque résiduel si un asset externe pointait dessus (vérifié : aucun).

## §6 — Ajustements
Aucun requis pour A. Pour C : tester le redirect en staging cloud avant prod.

## §7 — NE PAS toucher / RESPECTER
- ❌ **`public/images/menu/` (271 PNG)** = source catalogue LIVE → ne jamais déplacer/renommer.
- ✅ `public/menu/le-cayenne-v2/` = doublon non load-bearing (le seul que B toucherait).
- Frozen/NF525 : aucun impact.

## §8 — Acceptation + rollback
- **Accept (A)** : documenter le verdict ; aucun changement. (C : `/menu`→301 `/login` en prod ; images catalogue intactes.)
- **Rollback** : n/a (A = statu quo).
