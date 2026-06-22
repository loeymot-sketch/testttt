# Wizard Studio — Architecture claire & robuste (W0–W6) + plan de validation maximale
**Date:** 2026-06-15 · Branch `goal/wizard-wysiwyg-builder-2026-06-14`. NON-FROZEN copy alongside the form builder.

## 1. Vision
Un builder WYSIWYG « Shopify de la borne » : l'opérateur **VOIT** la borne (aperçu live du vrai wizard kiosk) et la **MODIFIE** (pages, règles, sources, images) en voyant le résultat instantanément. Tout pilote la MÊME donnée que les wizards frozen → rendu identique.

## 2. Couches (data flow)
```
Operator → WizardStudioComponent.vue (admin SPA, NON-FROZEN)
   ├─ READ  GET admin/composer/{item|category}/{id}/profile        → steps (édition)
   ├─ READ  GET admin/composer/categories/{id}/available-sources   → sources liables [W6]
   ├─ READ  GET admin/composer/profiles/{id}/preview-projection    → draftItem (aperçu)  [W1]
   └─ WRITE PUT admin/composer/profiles/{id} {steps,version}       → ComposerProfileService::update  [W2/W3/W6]
                                                                          │
   préviewProjection ─ ComposerProfileProjection::project(draft, item,'kiosk') ─ MÊME projecteur que le kiosk publié
                                                                          │ (si is_published)
   ComposerProfileChanged('updated') → InvalidateKioskMenuCacheOnCatalogChange + PersistCatalogChangedToOutbox
                                                                          ↓
                                          BORNE live : cache menu invalidé → re-fetch → voit la modif
```
- **Aperçu = vérité par construction** : monte le FROZEN `KioskWizardComponent` (read-only, onAddToCart=noop), nourri du draft via le MÊME `ComposerProfileProjection`. `is_published` forcé `true` côté réponse (jamais persisté) pour passer la gate du consumer frozen. Device-frame : `:deep` width 724 + height 1288 + `zoom .5` = cadre 362×644 déterministe.
- **Édition = bulk PUT** `ComposerProfileService::update` (delete-all + recreate steps, version++ , 409 si version stale). NF525 : `payloadForStep` n'émet AUCUN prix (le prix vit sur les constructs catalogue).

## 3. SYNCHRONISATION (vérifié source primaire — le point clé)
- `update()` fire `ComposerProfileChanged('updated')` **ssi `is_published`** (`ComposerProfileService.php:141-142`). Brouillon → aucun event (correct, non-live).
- `ComposerProfileChanged` → `[InvalidateKioskMenuCacheOnCatalogChange, PersistCatalogChangedToOutbox]` (EventServiceProvider). ⇒ éditer un profil **publié** dans le Studio invalide le cache menu kiosk → la borne re-fetch → propagation. **Réutilise le contrat sync existant, zéro nouveau canal, zéro collision avec la session centrale** (le Studio ne s'abonne à aucun canal temps-réel — vérifié W1 audit).
- **Point d'architecture à expliciter (UX/sync)** : le Studio édite DIRECTEMENT le profil (pas de séparation draft→publish comme le form builder). Sur un profil **publié**, chaque save est **live** (clients voient immédiatement, après invalidation cache). À auditer : est-ce le comportement voulu, ou faut-il un mode brouillon ? (candidat finding UX/sync).

## 4. Périmètre livré (autonome, non-gaté)
W0 scaffold · W1 aperçu live (convergé 4-rounds e2e) · W2 CRUD pages (drag/rename/del/add) · W3 règles (single/multi·req·min/max·repeat) · W4a image+desc backend (migration+projection) · W6 source-binding. Vitest 15/15 EXIT0, PHPUnit 6/6, frozen 0.

## 5. Gates owner (non auto-signables) — restants
G-MEDIA (upload W4b) · W5 prix catalogue (lane CENTRAL) · G-POS-COMPOSER (W7) · G-PUSH.

## 6. PLAN DE VALIDATION MAXIMALE (cette campagne — loop until all green)
Équipe adversariale multi-lanes (discover→verify sceptique→completeness), puis heal+re-test en boucle :
- **TECH** : bulk-PUT/409/hydrate/payloadForStep, migration sqlite-safe, projection thumb/desc, source endpoint, IDOR/authz, NF525, frozen 0.
- **SYNC** : propagation update→ComposerProfileChanged→cache (publié) ; aucun abonnement temps-réel parasite ; non-collision session centrale ; published-direct-edit live-ness.
- **UI** : qualité visuelle TOUS les états (liste éditable, éditeur règles, picker source, device-frame, responsive, a11y/contraste) — captures live analysées.
- **UX** : cohérence du flux édite→voir, états vide/erreur/conflit 409, modèle mental draft-vs-publié, libellés.
- **Critère de sortie** : 2 cycles consécutifs P0+P1=0 (set-equality), tout heal re-testé (Vitest/PHPUnit/visuel), frozen 0, sync prouvée.
</content>
