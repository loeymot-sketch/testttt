# HANDOFF DÉPLOIEMENT — Borne page d'accueil (attract redesign)

**Date** : 2026-06-28
**Auteur** : session Claude Code (feature)
**Pour** : agent de déploiement
**Branche de travail** : `pos/category-first-caisse-2026-06-23` (HEAD `6855c4649` avant mes changements, NON committés)
**Type** : changement frontend visuel — écran idle/attract de la borne (`/kiosk/idle`)
**Frozen-zones touchées** : AUCUNE. **NF525** : non concerné. **Backend** : non concerné.

---

## 1. Ce qui a changé (résumé)

Refonte du visuel de la **première page de la borne** (écran d'accueil/attract) à partir
de l'import design owner « Le Cayenne - Borne Accueil ». Nouveau carrousel produit + identité
orange Cayenne. La logique métier (choix mode de commande, navigation, FR-lock, a11y) est
**inchangée** — seuls le template et le style du composant ont été refaits.

---

## 2. Fichiers à déployer

### A. Code source modifié (2 fichiers — à committer/pull)
| Fichier | Nature |
|---|---|
| `resources/js/components/frontend/kiosk/KioskIdleScreenComponent.vue` | Refonte template + `<style scoped>` + ajout logique carrousel dans `<script>`. NON-frozen. |
| `resources/views/master.blade.php` | +7 lignes : lien Google Fonts (Bricolage Grotesque + Hanken Grotesk) dans le bloc `@if (request()->is('kiosk*'))` existant. NON-frozen. |

Diff blade (intégral) :
```blade
@if (request()->is('kiosk*'))
    <link href="...Fraunces..." rel="stylesheet">
    {{-- Borne Accueil attract redesign 2026-06-28 --}}
    <link
        href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500..800&family=Hanken+Grotesk:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">
@endif
```

### B. Nouveaux assets statiques (10 fichiers, ~22 Mo — À DÉPLOYER, sinon images cassées)
Répertoire : **`public/images/kiosk-attract/`**
```
logo.png            (wordmark Le Cayenne, transparent)
terminator.png  double-cheese.png  cayenne.png  grill-burger.png
supreme.png     menu-maxi.png      bol-riz.png  bol-frites.png
chicken-sub.png  (présent, non référencé pour l'instant)
```
- Servis en chemins racine `/images/kiosk-attract/*` (pas de `asset()` helper) → marchent en local **et** via IP LAN.
- Ces fichiers ne sont PAS gitignorés → peuvent être committés normalement.

---

## 3. ⚠️ GOTCHA BUILD — à NE PAS rater

`KioskIdleScreenComponent` est un **import statique** dans `resources/js/router/modules/kioskRoutes.js`
→ il se compile dans **`public/js/app.js`**, **qui est GITIGNORÉ** (`/public/js/app.js` n'est pas versionné).

**Conséquence : le déploiement DOIT rebuild le frontend. Copier les bundles committés ne suffit pas** —
`app.js` n'est pas dans le repo et ne contiendra mes changements que s'il est régénéré.

### Commande de build (obligatoire)
```bash
npm ci          # ou npm install si lockfile déjà résolu
npm run production
```
- Lance Laravel Mix (`mix --production`) → régénère `public/js/app.js` (+ versioning `mix-manifest.json`).
- **Builder depuis le checkout principal**, PAS depuis un git worktree (sinon chemins `node_modules`
  pollués → tous les bundles diffèrent = bruit ; cf. règle projet connue).
- Node 18.x (testé v18.20.7).

---

## 4. Procédure de déploiement (ordre)

1. **Récupérer le code** : les 2 fichiers source (§2.A) + les assets (§2.B).
   - Si déploiement par git : committer les 2 sources + `public/images/kiosk-attract/` + les
     bundles tracked régénérés (`public/css/app.css`, `public/js/*` tracked, `public/mix-manifest.json`).
   - Note : `public/js/app.js` étant gitignoré, il sera produit par le build sur la cible (§3).
2. **Build frontend** sur la cible (ou en CI) : `npm run production` (§3).
3. **Vider les caches Laravel** (sinon le blade compilé sans les polices est servi) :
   ```bash
   php artisan view:clear
   php artisan config:clear
   # si prod utilise les caches : php artisan view:cache && php artisan config:cache
   ```
   ⚠️ NE PAS `composer dump-autoload` sur un serveur en cours (casse l'autoload stale — règle projet).
4. **Vérifier les assets servis** : `curl -I http://<host>/images/kiosk-attract/logo.png` → 200.
5. **Recharger / rebooter la borne** : c'est un SPA — une session Chrome déjà ouverte sur
   l'ancien écran ne se met PAS à jour toute seule. Reboot borne OU rechargement forcé (Ctrl+Shift+R).
   Au boot suivant elle charge le nouveau `app.js` (cache-busté par le hash du manifest).

### Cible
- V1 LOCAL : `APP_URL=http://127.0.0.1:8766` → la borne tape ce serveur (mono-poste).
- Si déploiement VPS/cloud (borne distante) : appliquer les mêmes étapes sur le serveur cible.
  Aucun secret/.env à modifier. Aucune migration DB. Aucun service à redémarrer hormis,
  éventuellement, le serveur web si opcache agressif.

---

## 5. Vérification post-déploiement (preuve attendue)

```bash
HTML=$(curl -s http://<host>/kiosk/idle)
echo "$HTML" | grep -oE "/js/app.js\?id=[a-f0-9]+"          # doit montrer un hash (cache-bust)
echo "$HTML" | grep -c "Bricolage+Grotesque"                # doit valoir 1 (polices présentes)
curl -s -o /dev/null -w "%{http_code}\n" http://<host>/images/kiosk-attract/terminator.png  # 200
```
Visuel attendu : fond orange Cayenne, logo wordmark « LE CAYENNE · TACOS·BURGERS·SANDWICHS·BOWLS »
en haut, bandeau « NOS INCONTOURNABLES », grande carte produit avec carrousel (8 produits, stamp
100% Halal, chip nom produit, dots), titre cyclique « Bienvenue ! Le Cayenne », badges, grosse pill
blanche « Touchez l'écran — pour commander ».

---

## 6. Tests déjà passés (attestation)

- Vitest CI : `kioskOrderTypeExplicit.spec.js` + `kioskFrLockImmutable.spec.js` → **12/12**.
- Sentinelle : `appBundleFreshnessSentinel.spec.js` → **3/3** (bundle cohérent avec la source).
- Build `npm run production` → OK.
- Capture Playwright live (1080×1920, contexte neuf) → rendu = design, **0 erreur JS**.
- Frozen-zones : diff **0**.

---

## 7. Rollback

Changement isolé et réversible :
```bash
git checkout -- resources/js/components/frontend/kiosk/KioskIdleScreenComponent.vue resources/views/master.blade.php
rm -rf public/images/kiosk-attract/
npm run production
php artisan view:clear && php artisan config:clear
```
Puis recharger la borne → ancien écran d'accueil restauré. Aucun impact data/fiscal.
