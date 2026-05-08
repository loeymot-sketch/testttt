# ADR — Kiosk Design Cycle 2026-05-08
**Status :** Accepted (owner-validated `2026-05-08`)
**Branche :** `claude/blissful-mclean-c915c2`
**Auteur :** Claude orchestrateur (mode design execution senior)
**Reviewers :** 4 sub-agents YC GStack ultra-review

> ADR = Architecture Decision Record. Documente les décisions structurantes avec leur **contexte, alternatives considérées, conséquences**.

---

## ADR-001 — V1x-3 Cart image responsive : Option A (clamp safe) vs Option B (upscale 50%)

### Contexte
Brief original demandait `width: 96px height: 96px border-radius: 50%` sur `.kiosk-cart-item-image`. Audit du code révèle que la classe est `.kiosk-cart-item-img` (pas `-image`), taille actuelle 64×64 (pas 96), border-radius 10px (pas 50%). Implication : `clamp(96px, 7vw, 144px)` = upscale 50% baseline = **redesign**, pas simple responsivité.

### Décision
**Option A safe** : `clamp(64px, 4.7vw, 96px)`. 1080p portrait reste 64×64 (inchangé), 4K scale → ~96px.

### Alternatives rejetées
- **Option B** (`clamp(96px, 7vw, 144px)`) : touche visuel 1080p (upscale 50%). Re-validation visuelle nécessaire. Risk regression layout `.kiosk-cart-item-info`.
- **Option C** (Option B + `border-radius: 50%`) : pattern circulaire app-style food delivery. Encore plus visuel divergent. Re-validation visuelle.

### Conséquences
- ✅ Zero régression visuelle 1080p portrait (cible déploiement kiosk borne)
- ✅ Responsive 4K landscape (~96px en orientation landscape ou très grands écrans)
- ⚠️ **Footnote orientation** : 1920×1080 landscape rend ~90px. Si déploiement landscape envisagé, re-validation visuelle requise.

### Doctrine déploiement
**Kiosk borne = portrait 1080×1920 strict.** Tests `KioskCartRestyle.spec.js` assertions sur portrait. Landscape = scope V2 si demande métier.

---

## ADR-002 — V1x-6 Cart aria-label : Decision B extensive (3 templates)

### Contexte
Texte panier (variations + extras + instruction) tronqué visuellement par CSS `text-overflow: ellipsis`. Le DOM contient le texte complet → screen readers déjà accessibles. Mais ajouter `:title` (QA desktop tooltip) et `:aria-label` (redondance défensive) améliore UX.

### Décision
**Decision B extensive** : 3 templates `:title` + `:aria-label` :
1. `.kiosk-cart-item-name` (ligne 135) — `displayCartItemName(item)`
2. `.kiosk-cart-item-selections` (ligne 150-156) — `getItemSelectionSummary(item)` (méthode existante l.434-462, pas modifiée)
3. `.kiosk-cart-item-note` (ligne 158) — `displayCartInstruction(item)` (méthode existante)

### Alternatives rejetées
- **Decision A minimale** : seulement sur `.kiosk-cart-item-selections`. Risk de manquer le name truncation (rare mais possible) + instruction utilisateur.
- **Decision C concat full** : computed `fullSelectionsTextWithInstruction(item)` qui concat tout. Cohérence sémantique mais redondant + crée nouveau computed (touche `<script>` du composant frozen).

### Conséquences
- ✅ Zéro modif `<script>` section du Cart (frozen-zone preservée)
- ✅ A11y exhaustif : screen reader lit name + selections + note séparément
- ✅ Tooltip QA desktop sur les 3 surfaces
- ✅ Pattern réplicable BACKLOG : `FrontendCartComponent`, `TableCartComponent`, `PaymentComponent` admin

---

## ADR-003 — V2-4 Voice flag default OFF (vs spec original `?? true`)

### Contexte
V2-4 voice ordering CTA additif sur `KioskIdleScreenComponent`. Spec original prévoyait `isVoiceFeatureEnabled = settings.kiosk_voice_ordering_enabled ?? true` (default ON si pas de setting).

### Décision
**Default OFF** : `isVoiceFeatureEnabled = false` initialement, override par `loadSettings()` si `data.kiosk_voice_ordering_enabled === true` côté serveur.

### Alternatives rejetées
- **Default ON** : risque de captation micro non-voulue dès le déploiement, sans owner explicit opt-in. RGPD article 7 (consent) implique action explicite owner.

### Conséquences
- ✅ Safe rollout : aucune borne ne capture micro tant que owner n'a pas activé.
- ✅ Activation via Vuex `kioskSettings.voiceOrderingEnabled` ou backend `kiosk_voice_ordering_enabled`.
- ✅ Pre-flight consent dialog (Wave A4) ajoute une seconde couche défensive RGPD.
- ⚠️ Onboarding : owner doit explicitement activer le flag pour tester en staging puis prod.

### Pre-flight consent (Wave A4 — privacy par défaut)
Au-delà du flag, `KioskMicConsentDialog` impose un click explicite user à chaque session avant `SpeechRecognition.start()`. Flag `hasGrantedThisSession` SESSION-SCOPED (pas localStorage — kiosk public space ≠ tracking persistent légal RGPD).

---

## ADR-004 — V2-5 Themes propagation : pull-at-boot (vs push real-time Pusher)

### Contexte
Theme manager admin permet à l'owner de switcher le thème saisonnier per-branche (`branches.active_theme`). Question : comment propager le changement aux kiosks en session ?

### Décision
**Pull-at-boot** : `kioskThemeManager.initialize(branchId)` exécuté au DOMContentLoaded fetch `GET admin/kiosk-theme/{branchId}`. Pas de push real-time Pusher.

Note UI pour l'admin : "le thème prendra effet sur les bornes au prochain redémarrage ou rafraîchissement de session."

### Alternatives rejetées
- **Push real-time via Pusher** sur changement `branches.active_theme` :
  - Pro : effet immédiat sur bornes en session.
  - Con : nécessite domain_event `BranchThemeChanged` + listener Pusher + handler frontend qui re-pose `data-kiosk-theme` attribute. Complexité ~2j-agent. Last-write-wins risk si plusieurs admins.
  - **Rejeté** car effet visuel ne justifie pas la complexité (changement de thème = action rare, owner accepte le délai reboot).
- **Polling périodique** : 1× toutes les 30min. Coût réseau négligeable mais latence ressentie.
  - **Rejeté** au profit du pull-at-boot simple et déterministe.

### Conséquences
- ✅ Architecture simple : 0 listener Pusher additif.
- ✅ Last-write-wins acceptable (deux admins switchent simultanément = aucun overwrite destructif, toutes les valeurs sont valides whitelist).
- ⚠️ Délai jusqu'à effet : kiosk borne reboot quotidien (kiosk-mode self-restart) → délai max ~24h. Acceptable pour seasonal themes.
- 🔵 BACKLOG V2 : si owner demande effet immédiat (ex: campagne marketing flash), Phase 3 ajoutera Pusher push.

---

## ADR-005 — V2-2 POC drag-drop : standalone admin route vs intégration wizard

### Contexte
V2-2 drag-drop ingrédients propose une UX alternative au wizard kiosk classique. `KioskWizardComponent.vue` (1659 LOC) est OWNER-FROZEN.

### Décision
**Phase A POC standalone admin route** : `/kiosk/burger-builder-poc` admin-toggle uniquement. PAS d'intégration wizard frozen dans cette PR. **Phase B integration → owner gate explicit requis** (BACKLOG).

### Alternatives rejetées
- **Intégration wizard direct** via slot ou `<component :is>` :
  - Pro : démo end-to-end immédiate.
  - Con : touche frozen-zone sans gate. CLAUDE.md priorité #2 "Architecture is more important than local convenience" violée.
  - **Rejeté** : Phase B requiert décision owner après démo POC live.
- **POC dans une feature branch séparée** :
  - Pro : iso totale.
  - Con : duplication setup, sub-PR review difficile, risque drift.
  - **Rejeté** : standalone admin route dans la même branche est plus traçable.

### Conséquences
- ✅ POC executable owner peut tester `/kiosk/burger-builder-poc` après merge.
- ✅ Frozen-zone wizard intact 100%.
- ⏸️ Phase B (effort 2j-agent) en BACKLOG owner-decision avec plan détaillé `plans/PLAN_DESIGN_V2_2_DRAG_DROP_WIZARD_2026-05-08.md`.
- 🔵 Risque sunk cost limité : si Phase B refusée, le POC reste un outil admin de démo + tests vitest 16/16 servent comme reference pour future v2.

---

## ADR-006 — Service strategies bind via interface (pas concrete class)

### Contexte
V2-3 upsell recommendations utilise pattern Strategy : `RuleBasedStrategy` + `MlPlaceholderStrategy` implémentent `UpsellRecommendationService` interface. Comment binder dans Laravel container ?

### Décision
**Bind interface → strategy concrete** dans `AppServiceProvider::register()` :
```php
$this->app->bind(UpsellRecommendationService::class, function ($app) {
    $strategy = config('recommendation.strategy', 'rule_based');
    return match ($strategy) {
        'ml_placeholder' => $app->make(MlPlaceholderStrategy::class),
        default => $app->make(RuleBasedStrategy::class),
    };
});
```

Container : `bind` (pas `singleton`) → nouvelle instance par injection. Strategy reste stateless (Wave A3 confirmé pure-parameter pattern, pas `$this->cache*`).

### Alternatives rejetées
- **Singleton** : économie mémoire mais risque cross-request leak si strategy devient stateful (anti-pattern guard).
  - **Rejeté** : `bind` est plus safe par défaut.
- **Inject concrete class direct** (pas interface) :
  - **Rejeté** : violerait DI principle. Strategy switch via config impossible sans modifier code.

### Conséquences
- ✅ Strategy switch via env `RECOMMENDATION_STRATEGY=rule_based|ml_placeholder` sans déploiement code.
- ✅ Tests : container binding testé via `test_container_binds_strategy_from_config`.
- ✅ Defense in depth : `match()` explicite vs dynamic class (cf. `UpsellPreviewController`).

---

## §X — Décisions transverses

| Décision | Référence | Statut |
|---|---|---|
| Frozen-zones discipline 24/24 | CLAUDE.md non-négociable #8 | ✅ Respecté tous commits |
| TDD strict pour items avec logique | CLAUDE.md priorité #11 | ✅ Tests avant code (CSAT, themes, voice) |
| Backend = source of truth pricing | CLAUDE.md priorité #1 | ✅ RuleBasedStrategy lit Item::price DB |
| Branch isolation strict | CLAUDE.md priorité #2 | ✅ BranchScope global + tests cross-branch |
| Defense in depth dynamic class | CLAUDE.md priorité #3 | ✅ `match()` vs `Str::studly` |
| RGPD privacy pre-flight | CLAUDE.md priorité #7 | ✅ A4 KioskMicConsentDialog session-scoped |
| Observability Pusher ACK | CLAUDE.md priorité #11 | ✅ A2 fix `/api/api/...` 404 |

---

## §Y — Decisions BACKLOG (post-merge owner)

| Décision | Effort | Trigger |
|---|---|---|
| ADR Phase B V2-2 wizard integration | 2j-agent | Owner gate après démo POC |
| ADR Phase B V2-3 kiosk surface upsell carousel | 1j-agent | Owner gate |
| ADR Phase B V2-4 voice intent parsing wizard | 2-3j-agent | Owner gate |
| ADR Phase 3 V2-5 Pusher push real-time themes | 0.5-1j-agent | Owner business case |
| ADR i18n AR full coverage (vs partial fallback FR) | 1-2j-agent | Owner décide priorité marché AR |

---

## §Z — Status

[x] ADR-001 V1x-3 Option A
[x] ADR-002 V1x-6 Decision B
[x] ADR-003 V2-4 Voice flag default OFF
[x] ADR-004 V2-5 Pull-at-boot themes
[x] ADR-005 V2-2 POC standalone
[x] ADR-006 Strategies bind interface
[x] Owner-validated 2026-05-08
[ ] BACKLOG ADR Phase B post-merge
