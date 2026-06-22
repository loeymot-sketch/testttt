# ULTRA-AUDIT — Wizard Studio : surfaces CACHÉES / INDIRECTES / RÉACTIVES + sécurité / sync / gestion / historique
**Date:** 2026-06-14 · **Branch:** `goal/wizard-wysiwyg-builder-2026-06-14` · **Mode:** abusif (couverture maximale)
**Discipline:** READ-ONLY sur zones partagées (sync/stock = autre session) + frozen ; heal UNIQUEMENT mes fichiers non-frozen wizard-studio. NF525/frozen intouchables sans gate.

## Pourquoi (la demande développée)
W1 a livré l'aperçu live + heal UX. Mais une feature ne se juge pas que sur son chemin direct : il faut auditer **ce qui se déclenche EN RÉACTION** et **ce qui est INDIRECT/CACHÉ**. Monter le composant kiosk frozen dans l'admin + un endpoint qui projette un brouillon ouvrent des surfaces non évidentes : events, listeners, observers, websockets, timers, cache, audit-chain, IDOR, fuites cross-branch, DoS. On couvre TOUT.

## Dimensions d'audit (lanes adversaires)
1. **RÉACTIF FRONTEND** — que fait le `KioskWizardComponent` frozen monté en admin, hors clic ? Echo/WebSocketService subscriptions, `POST pricing/preview` (auth ? fréquence ?), analytics/consent, offline-queue, **idle-timeout qui pourrait NAVIGUER hors page**, timers/intervals/listeners non nettoyés (fuite mémoire au remount `previewNonce`), focus-trap global, beforeunload.
2. **RÉACTIF BACKEND (events/observers)** — `preview-projection` (GET) déclenche-t-il quoi que ce soit (ne DOIT rien) ? Le chemin publish composer fire `ComposerProfilePublished`/`ComposerProfileChanged` → propagation kiosk/POS/cache ? Model observers `ItemWizardProfile`/`ItemWizardStep` (saving/saved/deleted) → effets de bord cachés ? Invalidation cache MenuProjection ? Outbox/queue ?
3. **SÉCURITÉ / IDOR / authz (abusif)** — tenter d'abuser : prévisualiser le profil d'une AUTRE branche / d'un AUTRE item ; l'override `is_published=true` fuit-il dans un chemin caché (cache partagé, autre endpoint, menu projection) ? La route hors `wizard.per_item_profile_guard` ouvre-t-elle un accès non voulu ? mass-assignment, throttle/DoS (projection lourde répétée), exposition de prix/données non publiées, fuite de l'item représentatif (cross-branch). Sanctum/permission edge-cases.
4. **SYNCHRONISATION** — mon travail touche-t-il le bus sync (§6) ? Le composant monté s'abonne-t-il à `branch.{id}` (canal temps-réel) depuis l'admin → events fantômes / double-consommation / collision avec la session centrale ? L'aperçu doit être 100% passif côté sync.
5. **GESTION / HISTORIQUE / NF525** — l'aperçu ou le studio écrivent-ils `audit_logs` / `action_logs` / `domain_events` (ne devraient pas pour un GET) ? Interaction avec `item_wizard_step_versions` (immuable) ? Toute interaction chaîne NF525 / fiscal = doit être ZÉRO depuis l'aperçu.

## Protocole
- **Phase 1 Discover** : 5 lanes parallèles (read-only, file:line obligatoire) → findings candidats.
- **Phase 2 Adversarial-verify** : un sceptique par lane tente de RÉFUTER chaque finding en source primaire (drop les hallucinations — §3ter).
- **Phase 3 Completeness-critic** : qu'est-ce qui MANQUE (modalité non couverte, réaction non tracée).
- **Synthèse superviseur** (moi) : findings rankés P0–P3, réels vs réfutés, plan de heal.
- **Heal** : uniquement mes fichiers non-frozen ; tout finding en zone frozen/shared/sync = FLAG + gate, jamais auto-fix.

## Invariants de sortie
Convergence si : findings vérifiés file:line, réels healés + re-testés (Vitest/visual), réfutés documentés, frozen diff 0, zéro écriture sur DB op / zones autres sessions, gates listés.
</content>
