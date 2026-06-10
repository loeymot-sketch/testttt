# Wave W1-C4 — Audit UI/UX Cash Management + Floorplan (+ enquête AUTH)

**Date** 2026-06-11 · **App** http://127.0.0.1:8768 (DB jetable `foodking_e2e`) · **Viewport** 1440×900 fr-FR
**Scripts jetables** `tests/e2e/_w1-c4-{auth-floorplan,cash-cluster,cash-close,drawer-mode}.mjs`
**Screenshots** `reports/test-e2e/uiux-caisse-borne-2026-06-11/round1/shots-c4/` (18)

---

## VERDICT FLOORPLAN-AUTH-01 — RÉFUTÉ (n'est PAS un bug du floorplan)

**Hypothèse** : après visite de `/admin/pos/floorplan`, toute navigation admin redirige vers `/login` (token purgé par le floorplan).

**Résultat** : **REFUTÉ.** Le floorplan n'a aucune logique de révocation de token (grep `tokens()->delete` sur `FloorplanController.php` + `posFloorplan.js` = 0 hit ; le `state` endpoint ne renvoie que des données de tables). La redirection `/login` observée est causée par le **modèle single-session** combiné au **partage du compte `admin@lecayenne.fr` entre plusieurs agents/sessions parallèles** :

- `app/Http/Controllers/Auth/LoginController.php:155` → `$user->tokens()->where('name','auth_token')->delete();` : **chaque login révoque TOUS les `auth_token` antérieurs du même user** (comportement voulu, CLAUDE.md §9 « prevent token sprawl »).
- `resources/js/app.js:82-160` : l'intercepteur axios 401 → `router.push(auth.login)` (sur surface non-kiosk/non-public). C'est le mécanisme exact de la bascule vers `/login`.

**Preuves** :
1. **curl reproduction** (même user) : login → `T1` ; `GET /api/admin/default-access` avec T1 = **200** ; second login → `T2` ; T1 rejoué = **302** (révoqué), T2 = **200**. Confirme la révocation cross-session.
2. **Trace réseau** : dans la séquence floorplan, tous les appels du floorplan répondent **200** (`/floorplan/state`, `/walk-in-customer`, `authcheck`…). Les **401 apparaissent sur l'étape *suivante*** (`/admin/pos-orders`) — et même **avant** floorplan (sur `/admin/pos`) au 2ᵉ run → la bascule n'est PAS corrélée au floorplan mais au moment où un agent concurrent re-login `admin`.
3. **Disparition en changeant de compte** : rejoué avec un user dédié non partagé (`bm.t2admin@lecayenne.fr`, id 11), tout le cluster floorplan→cash-overview→sessions-report→POS→tiroir a tenu authentifié. DB : churn continu de `auth_token` pour user 1 (admin) émis par d'autres agents (tokens 2540→2764 en ~30 min).

**Sévérité** : **P3 / informational en V1 LOCAL.** En prod Le Cayenne (1 box, 1 caissier, 1 compte par appareil), la révocation single-session est le comportement de sécurité **désiré**, pas un bug. Le seul angle UX réel : si un caissier se connecte sur un 2ᵉ appareil (ou collision du refresh proactif 2h `pos-app.js:199`), le 1ᵉ appareil est silencieusement 401 → bounce `/login` **sans message d'explication** en plein service. Tradeoff connu/accepté. **Recommandation** : afficher un toast « Session ouverte ailleurs — reconnectez-vous » au lieu d'un bounce muet (au lieu de `router.push` direct sur 401 post-révocation).

> Note méthodo pour les autres agents W1 : **ne PAS utiliser `admin@lecayenne.fr`** pour des sessions Playwright longues sur cette box partagée — utiliser `bm.t2admin@lecayenne.fr` / `pos@lecayenne.fr` (mdp `123456`) pour éviter la révocation croisée.

---

## Cluster Cash Management — findings

### [P2] `/admin/pos/floorplan` (FloorplanComponent.vue) — page dine-in DÉSACTIVÉ : zone vide sans explication
- **reproduction** : `pos.pos_dine_in_enabled` défaut **0** (`SettingResource.php:124`, mandat `feedback_v1_dine_in_disabled_2026-05-06`). La route `/admin/pos/floorplan` (`posRoutes.js:25`) est **directement atteignable** sans garde sur le flag — `PosComponent` gate bien le CTA dine-in mais pas l'accès direct au floorplan.
- **evidence** : `c4-50-floorplan.png` → en-tête « Plan de salle / **0 tables** » puis **grande zone blanche brute**, aucun texte expliquant que le sur-place est désactivé en V1. Viole checklist §18 (DESIGN_REFERENCES) « état vide illustré + explication, jamais une zone blanche brute » et §4 anti-pattern.
- **recommendation** : soit garder la route derrière le flag `pos_dine_in_enabled` (redirect `/admin/pos`), soit afficher un état vide explicite (« Service à table désactivé en V1 — fonctionnalité à venir »). Pas de canvas blanc nu.

### [P3] FloorplanComponent.vue:9,46 — libellés anglais bruts sur surface FR
- **reproduction** : `grep` confirme `resources/js/components/admin/pos/FloorplanComponent.vue:9` = `<span>{{ tables.length }}</span> tables` (rendu « **0 tables** » visible `c4-50`) et `:46` = `{{ table.size || 0 }} **seats**` (latent, rendu si tables>0). Mots anglais codés en dur, non i18n.
- **evidence** : `c4-50-floorplan.png` (« 0 tables »). Viole DESIGN_REFERENCES §25 « FR partout, aucun libellé anglais résiduel ».
- **recommendation** : `$t('label.tables')` / `$t('label.seats')` (ou « places »). Cohérent avec le reste du composant déjà i18n (`$t('label.floorplan')`, `$t('label.free')`).

### [P3] Dialog tiroir (PosCashDrawerSessionDialog) — race d'hydratation : bouton « Caisse » montre brièvement « Ouvrir la caisse » alors qu'une session est OUVERTE
- **reproduction** : `openCashSessionDialog()` (`PosComponent.vue:2448`) ne re-dispatche PAS `loadCurrentSession` ; il passe `mode='auto'` → `resolveMode()` (`PosCashDrawerSessionDialog.vue:459`) lit `this.session` du store. Si on clique avant que le poll `/cash-drawer/sessions/current` ait hydraté le store, `session=null` → `mode='open'` (formulaire « Ouvrir la caisse ») bien qu'une session soit ouverte côté backend.
- **evidence** : `c4-35-drawer-state-unknown.png` (clic rapide → formulaire « Ouvrir la caisse / Aucune caisse ouverte ») VS `c4-41-caisse-btn-with-open-session.png` (après settle 9s → `mode=active` « Session active »). API `current` confirme la session #21 ouverte tout du long (curl 200 `{"id":21,...,"status":"open"}`). Donc bug de timing, pas de logique.
- **recommendation** : dans `openCashSessionDialog`, dispatcher `loadCurrentSession` AVANT d'ouvrir (ou afficher un loader tant que `current` n'a pas répondu), pour ne jamais proposer « ouvrir » sur une caisse déjà ouverte (risque opérateur : double-ouverture / confusion).

---

## Surfaces VALIDÉES ✅ (aucun défaut)

### ✅ `/admin/cash-overview` (« Vue Caisse Unifiée ») — `c4-10`
KPI clairs : GRAND TOTAL **186,60 €** (52 tx), CAISSE 7,00 € (1 tx), BORNE 179,80 € (51 tx), LIVREUR 0,00 € (0 tx). Format FR correct (virgule + espace insécable + €), chiffres alignés. Filtres DU/AU + SOURCE (`["Toutes les sources","Caisse","Borne","Livreur"]`) + MODE DE PAIEMENT, boutons Rechercher/Effacer. Panneau « Réconciliation caisse » (fond 50,00 €, encaissé 86,00 €, attendu tiroir 136,00 €). Répartition par mode (Espèces 32 · TR 10 · Carte 10). Palette Cayenne respectée. Pas de label brut.

### ✅ `/admin/cash-sessions-report` (« Rapport Caisses Quotidien ») — `c4-20`
Table groupée par jour (Sessions/Transactions/Total ouverture/clôture par groupe). Colonnes FR : Session, Filiale, Caissier, Ouverte le, Fermée le, Fond de caisse, Fonds final, **Écart** (surligné orange `2,00 €`), Transactions, Statut. Badges statut cohérents : **Ouverte** (bleu), **Réconciliée** (vert), **Fermée** (jaune). Montants FR. Filtres date (placeholder `jj/mm/aaaa`). État vide non testé (données présentes).

### ✅ Dialog tiroir — ouverture (`c4-31/32/33`)
« Ouvrir la caisse / Aucune caisse ouverte / Fond de caisse initial », affichage **50,00 €** (FR), incréments +5/+10/+20/+50 €, Effacer, input numérique, Annuler / « Ouvrir la caisse ». Ouverture réelle persistée DB (session #21, opening 50,00).

### ✅ Dialog tiroir — session active (`c4-35`)
« Session active » : Fond initial 50,00 €, Ouverte le 11/06 01:01, Mouvements 0, **Montant attendu 50,00 €**. Boutons « Voir les mouvements » / « Clôturer la caisse ». FR + montants OK.

### ✅ Dialog tiroir — mouvements (`c4-36`)
« Mouvements de caisse » → état vide propre « **Aucun mouvement enregistré.** » + Retour. Pas de zone blanche brute.

### ✅ Dialog tiroir — clôture + validation écart (`c4-37/38/39/40`)
« Clôturer la caisse » : Montant compté (incréments + input), **Montant attendu 50,00 €**, **Écart** recalculé live et coloré rouge (−50,00 € à 0 compté, −10,00 € à 40 compté). Champ « Raison de l'écart * » **obligatoire** : bouton « Valider la clôture » **disabled** tant que l'écart≠0 sans raison, hint « L'écart nécessite une raison. ». Clôture e2e réelle confirmée DB : session #21 → **status `reconciled`, closing 40,00, expected 50,00, variance −10,00, variance_reason « Test E2E — écart simulé »**. Validation NF525/métier correcte (pas de clôture avec écart non justifié).

---

## Compte par sévérité
| P0 | P1 | P2 | P3 |
|----|----|----|----|
| 0  | 0  | 1  | 3  |

(P3 = 2 findings cash/floorplan + 1 verdict AUTH informational)

## Top 3
1. **FLOORPLAN-AUTH-01 RÉFUTÉ** — bounce `/login` = single-session token-revocation (`LoginController.php:155`) sur compte `admin` partagé entre agents parallèles, PAS un bug floorplan. P3/info en V1 ; angle UX = bounce muet sur 401 post-révocation.
2. **[P2]** `/admin/pos/floorplan` route accessible alors que dine-in désactivé → canvas blanc « 0 tables » sans explication (garde flag manquante).
3. **[P3]** Dialog tiroir : race d'hydratation → « Ouvrir la caisse » proposé sur une caisse déjà ouverte si clic rapide (risque double-ouverture opérateur).

**Cash management = solide** : open/active/movements/close + validation d'écart obligatoire fonctionnent end-to-end (persistance DB prouvée), FR + montants conformes, aucun P0/P1.
