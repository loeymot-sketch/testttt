# FoodKing — Carte des versions & dette technique

> Partie 1/7 de l'audit forensique du 2026-07-06.
> Source : `composer.json` / `composer.lock` / `package.json` / `package-lock.json`. Versions **verrouillées** lues dans les `.lock`.

## 0. Synthèse

Le socle applicatif tourne sur des versions **en fin de vie (EOL)** : Laravel 9 et PHP 8.1 ne reçoivent plus de correctifs de sécurité à la date de l'audit. Ce n'est pas un détail de confort : **tout CVE découvert sur ce socle ne sera jamais patché en amont**, et la plateforme manipule des paiements et des données clients. C'est le premier risque de fond du projet, transverse à tous les systèmes.

| Couche | Version verrouillée | Statut (au 2026-07) | Risque |
|---|---|---|---|
| **PHP** | `^8.1.0` | 🔴 **EOL** (support sécurité terminé ~déc. 2025) | Élevé |
| **Laravel** | `v9.52.21` | 🔴 **EOL** (correctifs sécurité terminés ~fév. 2024) | Élevé |
| Laravel Sanctum | `^3.0` | 🟠 lié à L9 | Moyen |
| Stripe PHP SDK | `^10.11` | 🟠 **très en retard** (plusieurs majeures de retard) | Moyen |
| Vue | `3.5.31` | 🟢 récent | Faible |
| Vuex | `^4.1` | 🟠 mode maintenance (Pinia recommandé) | Faible/Moyen |
| Build : laravel-mix | `^6.0` (webpack) | 🟠 legacy (Vite = standard Laravel) | Moyen |
| CSS : Tailwind **+** Bootstrap | `3.4.1` **+** `5.2.1` | 🟠 double framework | Moyen |
| PHPUnit / Vitest / Playwright | `9.5` / `1.6` / `1.58` | 🟢 OK | Faible |

---

## 1. Socle backend

### 1.1 🔴 PHP `^8.1` — fin de vie
- **Support actif** terminé fin 2023 ; **support sécurité** terminé **décembre 2025**. À la date de l'audit (juillet 2026), **PHP 8.1 ne reçoit plus aucun correctif**.
- Impact : aucune protection amont contre les futurs CVE du runtime ; incompatibilité croissante avec les libs modernes ; recrutement plus difficile.
- Recommandation : planifier une montée vers **PHP 8.3+** (idéalement 8.4), conjointement à la montée de Laravel.

### 1.2 🔴 Laravel `v9.52.21` — fin de vie
- Laravel 9 : correctifs de bugs terminés mi-2023, **correctifs de sécurité terminés février 2024**. Le dépôt est verrouillé sur la **dernière** 9.x (`9.52.21`), donc rien de plus à tirer sur cette branche.
- `composer.json` déclare `"minimum-stability": "dev"` → **smell** : autorise l'installation de versions de développement non stables de n'importe quelle dépendance. À repasser à `stable` sauf besoin explicite.
- Recommandation : chantier de migration **L9 → L10 → L11** (par paliers). Impacte Sanctum (→ v4), les signatures de méthodes du framework, et le bundler.

### 1.3 SDK de paiement en retard
- **`stripe/stripe-php ^10.11`** : plusieurs versions majeures de retard par rapport à l'actuel. Les SDK de paiement évoluent vite (API versions, sécurité, méthodes de capture). À aligner sur une version récente **après** avoir vérifié la version d'API Stripe utilisée côté compte.
- `srmklive/paypal ~3.0` et `razorpay/razorpay ^2.8` : à réévaluer, moins critiques mais dans le même flux argent.
- ⚠️ La présence de **trois** PSP (Stripe, PayPal, Razorpay) + `easypaisa` (config présente) multiplie la surface de flux paiement à sécuriser — voir rapport **Invariants (03)**, invariant « intégrité paiement ».

### 1.4 Dépendances backend notables (saines)
`predis ^3.4`, `guzzle ^7.2`, `spatie/laravel-permission ^5.6`, `spatie/laravel-medialibrary ^10.5`, `pusher/pusher-php-server ^7.2`, `maatwebsite/excel ^3.1`, `barryvdh/laravel-dompdf ^3.0` — cohérentes avec l'ère L9. `spatie/permission` monterait en v6 avec Laravel 10+.

- ⚠️ **`dipokhalder/laravel-env-editor ^1.0`** : édition du fichier `.env` via interface. Combiné au module `Installer`, c'est un vecteur de **RCE de configuration** si exposé en prod → traité et vérifié dans le rapport **Sécurité (05)** et **Red Team**.

---

## 2. Socle frontend

### 2.1 Vue 3.5 — sain, mais Vuex vieillissant
- **Vue `3.5.31`** : récent, aucun souci.
- **Vuex `^4.1`** : fonctionnel mais en **mode maintenance** ; l'écosystème Vue recommande **Pinia**. Non urgent, mais la persistance via `vuex-persistedstate` mérite un audit sécurité (tokens/panier en `localStorage`) → rapport **Frontend (dans 04/06)**.

### 2.2 🟠 Bundler legacy : laravel-mix / webpack
- `webpack.mix.js` + `laravel-mix ^6.0`, **aucun `vite.config.*`**. Depuis Laravel 9.19, **Vite est le bundler par défaut**. Rester sur mix = builds plus lents, HMR daté, et un écart croissant avec la doc et l'écosystème.
- Recommandation : migration vers Vite (chantier isolé, testé).

### 2.3 🟠 Double framework CSS : Tailwind + Bootstrap
- **Tailwind `3.4.1`** *et* **Bootstrap `5.2.1`** sont tous deux déclarés. Deux paradigmes CSS concurrents → poids inutile, conflits de styles, incohérence visuelle, et confusion pour les contributeurs.
- Recommandation : trancher pour **un seul** (Tailwind, cohérent avec l'ambition UX « Splash-level » du projet) et retirer l'autre progressivement.

### 2.4 Dépendances front notables
`laravel-echo 2.3` + `pusher-js 8.4` (temps réel, cohérent avec soketi), `firebase 9.18` (FCM), `dompurify 3.4` (bon signe pour l'anti-XSS — reste à vérifier qu'il est réellement appliqué partout où `v-html` est utilisé), `idb-keyval` (offline kiosque), `axios 1.1`.

---

## 3. Tests & CI (versions)
- **PHPUnit `^9.5`**, **Vitest `^1.6`**, **Playwright `^1.58`** : versions correctes et cohérentes entre elles. La *couverture réelle* et le caractère bloquant de la CI sont évalués dans le rapport **Tests & CI (dans 06)**.

---

## 4. Recommandations priorisées (dette de version)

| Prio | Action | Effort | Pourquoi |
|---|---|---|---|
| **P0** | Repasser `minimum-stability` à `stable` | S | Évite d'embarquer des versions dev instables |
| **P0** | Auditer/roter les secrets PSP + monter Stripe SDK | M | Flux argent, SDK très en retard |
| **P1** | Migration **PHP 8.1 → 8.3+** | L | Runtime EOL, plus de patchs sécurité |
| **P1** | Migration **Laravel 9 → 10 → 11** (+ Sanctum 4) | XL | Framework EOL, cœur du risque |
| **P2** | laravel-mix → **Vite** | M | Bundler legacy |
| **P2** | Trancher **Tailwind vs Bootstrap** | M | Double CSS, dette UX |
| **P3** | Vuex → **Pinia** | L | Fin de maintenance à terme |

> Note : P1 (PHP) et P1 (Laravel) sont **couplés** et doivent être menés comme un seul chantier de socle, sur un environnement de préproduction, avec la suite de tests comme garde-fou (dont la fiabilité est elle-même auditée dans le rapport 06).

---

*Les versions sont lues dans les fichiers de verrouillage ; les dates d'EOL sont des faits publics de cycle de vie PHP/Laravel. Aucune dépendance n'a été installée (audit statique).*
