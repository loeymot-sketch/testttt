# LOCK — tiroir-caisse : rendre visible l'échec du pont matériel après un encaissement CASH

**ID :** `LOCK_DRAWER_BRIDGE_VISIBILITY_2026-09-17`
**Date :** 2026-09-17
**Statut :** **DRAFT — en attente de contresignature propriétaire.** Le fichier gelé n'est PAS
committé (diff en attente dans l'arbre de travail). Rédigé dans le cadre de la clôture d'audit
demandée par le propriétaire (« termine ce qui n'est pas fini ou pas déployé »).
**Gate d'autorité :** aucune — c'est cette contresignature-ci qui en tient lieu (CLAUDE.md §7
seconde branche : « gate explicite owner »).

## §1 Identification

- **Mission source** : `DRAWER-BRIDGE-VISIBILITY-20260917` (agent `codex`, journal
  `reports/AGENT_ACTIVITY_LOG.md` 2026-09-17T09:48–09:49, marqué « done » côté agent mais jamais
  committé ni doté d'un LOCK — c'est le trou que ce document ferme).
- **Cycle** : hors cycle ACTIVE_CYCLE.md actif (ORDER-INTEGRITY est clos, celui-ci est une
  correction isolée trouvée pendant l'audit de clôture).

## §2 Fichier gelé ciblé et empreintes

| Fichier | Raison du gel (CLAUDE.md §7) | SHA-256 baseline (actuel/committé) | SHA-256 avec le patch en attente |
|---|---|---|---|
| `resources/js/components/admin/pos/PaymentComponent.vue` | « POS payment component, frozen per BRAIN §2 (V1 untouched protected file) » | `6e124048635c2af76b0c59955b025270bf46c4f62bdaa0f58f83d16fd6c97604` | `c99ac0efe44f501749dc5d64fc5f99a771c608facba2d096b7a1410e1882c567` |

Confirmé mécaniquement : `php artisan test --filter=FrozenZoneSha256BaselineSentinelTest` est
**rouge** sur ce seul fichier avec exactement ces deux empreintes (2026-09-17, cette session).

## §3 Justification

**Le problème** : dans `handleOrderSuccess`, un encaissement CASH ouvre le tiroir physique via
`openDrawer()` en avalant silencieusement tout rejet (`.catch(() => {})` / `catch (e) {}`). Un
paiement scellé peut donc réussir pendant qu'un tiroir hors ligne reste fermé, sans qu'aucun
signal n'atteigne le caissier — le tiroir se rouvrira au prochain succès, ou jamais si le pont
matériel reste en panne. C'est un défaut opérationnel silencieux, pas un défaut de prix.

**Pourquoi pas ailleurs** : la décision d'ouvrir le tiroir et son retour d'erreur n'existent qu'à
cet endroit précis de `handleOrderSuccess` (POS-9.1.12) ; aucun fichier adjacent non gelé n'a
accès à `submittedForm.pos_payment_method` à ce point du flux post-paiement. Le correctif ne peut
pas être déplacé sans dupliquer la logique de décision CASH.

## §4 Scope — chirurgical

- 1 méthode (`handleOrderSuccess`), 1 bloc `if`, 12 insertions / 5 suppressions.
- Aucun nouveau prix, aucune écriture fiscale, aucune nouvelle dépendance.
- `alertService` est déjà importé/utilisé ailleurs dans ce composant (pattern existant, pas une
  introduction).

```diff
 handleOrderSuccess: async function (orderResponse, submittedForm) {
-    // ouvre le tiroir, avale toute erreur en silence
+    // ouvre le tiroir, affiche une alerte visible si le pont matériel échoue,
+    // SANS jamais annuler le paiement déjà scellé
     if (submittedForm.pos_payment_method === this.posPaymentMethodEnum.CASH) {
         try {
-            Promise.resolve(openDrawer()).catch(() => {});
-        } catch (e) { /* defensive */ }
+            const drawerResult = await openDrawer();
+            if (!drawerResult || drawerResult.ok === false) {
+                alertService.error(this.$t('pos.cash_drawer_bridge_offline'));
+            }
+        } catch (_e) {
+            alertService.error(this.$t('pos.cash_drawer_bridge_offline'));
+        }
     }
```

## §5 Fichiers concernés

| Fichier | Type de changement |
|---|---|
| `resources/js/components/admin/pos/PaymentComponent.vue` | **gelé** — bloc CASH de `handleOrderSuccess` |
| `tests/js/posCashDrawerOpen.spec.js` | non gelé — bancs mis à jour, 6/6 verts (vérifié cette session) |
| `resources/js/languages/fr.json:442` / `en.json:439` | non gelé — clé `pos.cash_drawer_bridge_offline` déjà présente dans les deux locales |
| `tests/Feature/Sentinels/frozen-zone-sha256-baseline.json` | à mettre à jour avec la nouvelle empreinte, **dans le même commit** que le patch |

**Non touché** : aucun prix, aucun scellement de commande (`composition_snapshot`), aucune
chaîne fiscale, aucun autre composant POS.

## §6 Critères d'acceptation

- [x] `npx vitest run tests/js/posCashDrawerOpen.spec.js` → 6/6 verts (vérifié 2026-09-17).
- [ ] `php artisan test --filter=FrozenZoneSha256BaselineSentinelTest` → rouge avant mise à jour
      de la baseline (vérifié), **vert après** mise à jour dans le commit du patch.
- [ ] `npm run production` (ou `npm run dev`) → build sans erreur après le patch.
- [ ] Aucune régression sur les autres tests `PaymentComponent` existants (à relancer avant commit).

## §7 Rollback

1. **Code** : `git revert <sha-du-patch>` restaure le bloc `catch` silencieux d'origine et la
   baseline SHA-256 d'origine dans le même commit de revert.
2. **Données** : aucune — le patch ne touche ni base de données ni cache ni configuration.
3. **Frontend** : `npm run dev` après revert pour reconstruire le bundle POS.
4. **Notification** : si déployé puis annulé, prévenir les caissiers que l'alerte tiroir a
   disparu à nouveau (retour au comportement silencieux précédent).

## §8 Exécution

- Aucun sub-agent délégué requis — patch déjà écrit, ne reste que le commit sous LOCK signé.
- Vérification post-patch : cette session (Claude), avant tout push.

## §9 Contresignature propriétaire (gate humaine)

> **Rien n'est committé tant que cette section n'est pas remplie.**

- **Propriétaire** : ___________________
- **Décision** : [ ] APPROUVÉ  [ ] REJETÉ  [ ] À MODIFIER
- **Horodatage** : ___________________
- **Commentaires** : ___________________

Après APPROUVÉ : le patch + la mise à jour de `frozen-zone-sha256-baseline.json` sont committés
ensemble, avec ce LOCK cité dans le message de commit ; statut → `APPLIED` puis `CLOSED` une fois
`FrozenZoneSha256BaselineSentinelTest` revérifié vert.
