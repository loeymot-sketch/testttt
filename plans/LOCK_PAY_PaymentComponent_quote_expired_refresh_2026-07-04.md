# LOCK Plan — PaymentComponent.vue : fix « Order quote expired » au paiement caisse

**Date** : 2026-07-04
**Frozen file** : `resources/js/components/admin/pos/PaymentComponent.vue`
**Frozen reason** : CLAUDE.md §7 « POS payment component, frozen per BRAIN §2 (V1 untouched protected file) »
**Owner decision** : demande explicite owner 2026-07-04 — « actuellement lors de payement donc en essai sur la caisse, ça me fait un erreur : order quote expired ! Corrige-moi ce problème » = gate owner satisfait (bug bloquant l'encaissement en LIVE).
**Note hook** : ce fichier n'est PAS dans la liste hardcodée du pre-commit hook (§Block 5) ; il est protégé par le sentinel `FrozenZoneSha256BaselineSentinelTest` (baseline SHA mise à jour dans le même commit).

## Symptôme (owner, LIVE caisse)

À l'étape paiement sur `/admin/pos-v4`, l'encaissement échoue avec **« Order quote expired »** (HTTP 410). Bloque toute vente.

## Cause racine (verify-before-report, file:line)

1. **`app/Services/Order/OrderQuoteService.php:79-82`** — dès qu'une requête `/admin/pos/quote` porte un `quote_token`, le service la route vers `resolveReplay()` (chemin « replay du même devis »), sinon vers `findOpenQuote()` (réutilise/forge).
2. **`OrderQuoteService.php:421-423`** — `resolveReplay()` lève **410 « Order quote expired »** dès que `$quote->isExpired()` (TTL = `config('quote.ttl_seconds', 300)` = 300 s).
3. **`PaymentComponent.vue` `refreshQuote()`** postait le `form` COMPLET, `quote_token`/`quote_signature` inclus. Or un token peut traîner dans le form et être **déjà périmé** au moment du confirm :
   - devis initial ouvert puis caissier lent (> 5 min),
   - **commande garée restaurée** (snapshot porte l'ancien token),
   - tentative de confirm précédente (le patch a écrit le token dans le form),
   - timer keepalive (ligne 472, mode `multi` uniquement).
   → `refreshQuote` (censé RAFRAÎCHIR) réexpédiait le vieux token → `resolveReplay` → **410**. Le « refresh » ne rafraîchissait donc jamais : ni `resolveReplay` ni `findOpenQuote` ne prolongent `expires_at`.

**Contrat backend volontaire (à NE PAS casser)** : `tests/Feature/QuoteExpirationTest.php:26` `test_expired_quote_replay_is_rejected` assert **410** pour un REPLAY explicite d'un devis périmé. C'est intentionnel → le backend reste inchangé. Le bug est purement côté frontend (mauvaise intention exprimée : « replay » au lieu de « donne-moi un devis courant »).

## Changement appliqué (scope-minimal, 1 méthode)

`refreshQuote()` retire `quote_token` + `quote_signature` de la copie envoyée :

```diff
 refreshQuote: function (form) {
+    const freshRequest = { ...(form || {}) };
+    delete freshRequest.quote_token;
+    delete freshRequest.quote_signature;
-    return axios.post('admin/pos/quote', form).then((res) => {
+    return axios.post('admin/pos/quote', freshRequest).then((res) => {
```

Effet serveur : requête SANS token → `findOpenQuote()` **réutilise** un devis ouvert de même intention (< 300 s) OU en **forge un neuf** (périmé/inexistant) → renvoie toujours un token/signature **frais, non expirés**. Ceux-ci sont ensuite portés par `saveForm` → `sealForCommit` (devis valide à la milliseconde près). `.then()` inchangé : le retour utilise `quotePatch` (valeurs fraîches), pas `freshRequest`.

## Analyse de risque

| Scénario | Avant | Après |
|---|---|---|
| Paiement `discount=0` (courant) | 410 si token périmé | devis neuf → OK. **Identique** si token frais/absent (intention `0==0`, intent_hash inchangé) |
| Manuel discount ≠ 0 | comportement pré-existant (hors périmètre — le POST `save` strip déjà `discount`, inchangé par ce fix) | idem |
| Contrat backend `QuoteExpirationTest` (410 replay + commit) | vert | **vert** (backend non touché) |
| Idempotence preview | replay-par-token | `findOpenQuote(intent_hash)` — même intention → même devis ouvert ; intention changée → devis neuf (plus correct que 401 « intent mismatch ») |
| NF525 / fiscal | aucun (le fiscal est alloué à l'encaissement, pas au devis) | aucun |
| Sentinel emits `paymentComponentEmitsJsdocList` | — | inchangé (aucun emit modifié) |

## Preuves (triple-vert)

- `tests/js/posPaymentComponentContract.spec.js` — **4/4 vert** (+1 assertion de régression : `refreshQuote` strip bien le token, poste `freshRequest` et jamais `form`).
- `tests/Feature/QuoteExpirationTest.php` — **2/2 vert** (contrat backend 410 intact).
- `tests/Feature/Sentinels/FrozenZoneSha256BaselineSentinelTest.php` — **vert** après MAJ baseline SHA `50fa96…` → `6bfaad…`.
- Bundle `public/js/pos-app.js` rebuild `npm run prod` (webpack compiled successfully).

## Déploiement

Le fix doit atteindre le VPS (le poste caisse charge `pos-app.js` depuis le serveur). Push → `tools/deploy-vps.sh` (git pull + `npm run prod` côté VPS) → recharger `/admin/pos-v4`.

## Statut

**APPLIQUÉ** — owner gate = demande explicite 2026-07-04 (bug LIVE bloquant). Baseline SHA MAJ. Réversible par `git revert` (1 commit).
