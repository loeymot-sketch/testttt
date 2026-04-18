# VISION KIOSK FINAL — Production-grade end-to-end — 2026-04-18

**Auteur.** Orchestrator Track A (Kiosk).
**But.** Lecture stratégique de ce qui manque pour que la borne FoodKing soit déployable en flotte (multi-branch, 24/7, hors-ligne partiel, hardware réel) au-delà du périmètre P9.1 → P9.6. Document vivant, mis à jour à chaque fin de vague.
**Horizon.** P9.7 → P9.10. Ne couvre pas V2 (IA, personnalisation prédictive, dark kitchen).

---

## 1. État des lieux (post P9.1 mergé, P9.2/4/5 verified, P9.3 en cours)

### Ce qui marche déjà (acquis robuste)

- **Pricing SSOT** (`PricingService` + `PricingRequest::forKiosk` + cross-item guard P9.5.6 + preview live P9.1.3).
- **Branch isolation** (machine scoped `KioskMachine::where('user_id', Auth::id())`, sanctum `kiosk:order` ability, idempotency `(branch_id, idempotency_key)` P9.5.4).
- **Order pipeline immutable** (`OrderStateMachine::apply` + `DB::afterCommit` + `allergens_snapshot` P9.5.1 + stale cleanup P9.5.3).
- **EventContract V1 figé**, outbox + dispatch retry + whitelist analytics (ops vs marketing à splitter en P9.6.1).
- **UX RGPD** (consent 3-cases opt-in, no-PII receipt persistence, QR receipt P9.4.5, loyalty via scan NFC/QR P9.4.6).
- **Hardware abstractions** présentes : `kioskHardware.js` (healthcheck debounce P9.4.11, haptic P9.4.7, TTS via `useKioskSpeech` P9.1.8).

### Ce qui reste fragile / incomplet

- **Offline queue** existe (`kioskOfflineQueue.js`) mais n'est consommée que partiellement : le wizard n'enqueue pas ses previews, l'admin audit log enqueue (P9.4.12) mais pas encore les events analytics critiques ni les commandes complètes hors-ligne.
- **Hardware réel** : bridge Electron non finalisé — caméra / imprimante thermique / TPE / buzzer / scan code-barres ne sont aujourd'hui que des stubs côté `kioskHardware.js`. Le contrat est posé, l'intégration native manque.
- **Kiosk lockdown** : aucun mécanisme Electron qui bloque Alt+Tab, Ctrl+Alt+Del, touches système, autofill, inspection dev tools, URL bar, gestes trackpad. Borne livrée "ouverte".
- **Déploiement multi-branch** : pas d'outillage pour provisionner N bornes (N × `KioskMachine` sanctum tokens, enrollment workflow, OTA update).
- **Telemetry terrain** : analytics backend existe, mais pas de tableau de bord opérationnel temps réel (latence TPE, taux d'échec paiement, panier moyen par branch, heures de pointe).
- **Wizard resilience** (en cours P9.3.12/13/14/15) couvre double-submit / resume / timeout / a11y — mais pas encore cart corruption recovery, ni paiement split, ni reprise après crash TPE milieu de paiement.

---

## 2. Phase 9.7 — i18n / a11y / PMR completeness

### Vision

Chaque interaction **EAA 2025 ready** (European Accessibility Act). Un utilisateur en fauteuil, malvoyant, ou avec troubles moteurs, fait une commande end-to-end sans aide humaine.

### Items proposés (à affiner au démarrage)

- **9.7.1** PMR selector étendu : `[role=radio], [role=checkbox], [role=option], [role=tab], [role=menuitem]` dans `tokens-pmr.css`.
- **9.7.2** Hit target min **64×64 px** sur CTAs wizard, `48×48 px` partout ailleurs (AA). Vérification axe-core runtime.
- **9.7.3** Règles `[dir="rtl"]` exhaustives (wizard steps, cart, confirmation, admin panel) — à ce jour RTL partiel.
- **9.7.4** Icônes emojis → SVG inline (fallback font-indépendant, kiosk sans Unicode emoji font garantie).
- **9.7.5** Audit tokens-aaa ratio contraste **7:1** via stylelint-a11y en CI.
- **9.7.6** **Lecteur d'écran mode dédié** — toggle dans drawer A11y qui bascule tout le wizard en navigation clavier linéaire + announcements verbeux (pas juste TTS événementiel).
- **9.7.7** **Mode grosse police** (token `--font-scale: 1.4`) activable sans casser les layouts — audit visuel + regressions.
- **9.7.8** **i18n fuzzy completeness** — coverage script : pour chaque clé utilisée, existe en fr/en/ar ; absence = build fail. À brancher sur la CI.

### Gates proposés

1. axe-core runtime sur les 10 écrans critiques : **0 violation AA**.
2. PMR selector vérifié sur 15+ rôles ARIA.
3. RTL smoke test Playwright sur wizard tacos complet.
4. i18n coverage = 100% sur clés utilisées (script de vérif CI).

---

## 3. Phase 9.8 — Tests E2E + CI green définitif

### Vision

**Un seul bouton vert en CI** garantit que toute la flotte peut merger. Playwright sur les 3 OS cibles (Linux Electron, macOS dev, Windows fallback), suite complète < 15 min.

### Items proposés

- **9.8.1** Flow happy-path complet Playwright : idle → catalog → wizard tacos → cart → promo → upsell → loyalty scan → payment card → waiting → confirmation. Screenshot per step + diff visual regression.
- **9.8.2** Flow safety : allergen alert wizard + produit 86 mid-wizard + Echo reconnect.
- **9.8.3** Flow crash-resume : F5 mid-wizard → reprise (couvre 9.3.13) ; F5 mid-payment → état ambigu géré explicitement.
- **9.8.4** Flow split-payment (cash + card) si métier confirme.
- **9.8.5** Feature test backend : `test_replayed_order_same_idempotency_key_returns_existing` (composite branch_id key).
- **9.8.6** Feature test : `test_cross_branch_events_never_leak_on_private_channel`.
- **9.8.7** Feature test : `test_dispatch_domain_events_job_retries_on_envelope_mismatch`.
- **9.8.8** Visual regression suite : snapshots des 5 UX P9.1 (reportés P9.1 §4.5).
- **9.8.9** Load test ciblé : 10 bornes simultanées sur 1 branch, 60 min, commande toutes les 30 s. Assertions : aucune deadlock, latence P95 < 2 s.

### Gates proposés

1. Suite Playwright verte sur Electron target.
2. Toutes les feature tests `Feature/OrderPipeline/` vertes sur MySQL CI.
3. Visual regression 0 diff (ou diff commenté avec delta justifié).
4. Load test P95 < 2 s.

---

## 4. Phase 9.9 — Différenciateurs compétitifs (optionnels)

### Vision

Convertir la borne de "capable" à "**premium**". Items à cost/benefit clair, priorisés par ROI estimé.

### Items proposés

- **9.9.1 Apple Pay / Google Pay** via bridge TPE (param `method='APPLE_PAY'`). McDonald's ne le fait pas en Europe → différentiateur direct.
- **9.9.2 Mode Turbo** : post-idle screen "Express" avec 3 combos bestseller → skip wizard → direct payment. KFC Express lane. Candidat mesure conversion.
- **9.9.3 Pairing app mobile FoodKing** (scan QR client → pre-fill loyalty + préférences + historique). Burger King royal perks.
- **9.9.4 Estimation temps de retrait dynamique** (fetch branch queue depth). Five Guys ne le fait pas.
- **9.9.5 Upsell personnalisé** (si app mobile paired) : "Vous prenez souvent un Ice Tea avec ce menu ?".
- **9.9.6 Mode "à emporter" vs "sur place" visuellement distinct** : couleur header + son distinct (tinkling bell pour sur place).
- **9.9.7 Split bill** : diviser l'addition en N parts égales ou libres — feature difficile à bien faire (touche PricingService cross-items). **SEQUENTIAL avec Track B, escalade humaine obligatoire.**
- **9.9.8 Prévente / pré-commande** : commander maintenant, retrait à X heures (fetch slots depuis `BranchOpeningHours`).
- **9.9.9 Mode sourd** (pour bruit ambiant élevé) : visuels renforcés + vibrations au lieu de sons.

### Gates proposés

1. A/B test infrastructure en place (feature flags via `kioskSettings.experiments`).
2. Chaque différenciateur dispatch `experiment_activated` event.
3. Chaque différenciateur est **toggle-able par branch** (pas de feature obligatoire flotte-wide).

---

## 5. Phase 9.10 — Build prod + flotte + telemetry terrain

### Vision

Une flotte de 50+ bornes déployées, **surveillables depuis un seul dashboard opérationnel**, mises à jour OTA sans intervention physique.

### 5.1 Packaging et déploiement

- **9.10.1 Build prod Electron** cible triplé : Linux (Debian AArch64 + AMD64), Windows (fallback dev). Signed binary + auto-update chainé (electron-updater ou `electron-forge`).
- **9.10.2 Enrollment multi-branch** : workflow admin "Ajouter borne" → génère QR d'enrollment → borne scan → provisionne sanctum `kiosk:order` token + `branch_id` + identifiant matériel. Aucune saisie manuelle.
- **9.10.3 Configuration par branch** centralisée : colors, logo, menus spéciaux, horaires, tax rate, devise. Fetch à chaque redémarrage + push Echo pour hot-reload.
- **9.10.4 Update OTA contrôlé** : canary (1 borne par branch → observer 24 h → rollout global). Rollback automatique sur taux de crash > 2%.

### 5.2 Kiosk lockdown

- **9.10.5 Electron hardening** : fullscreen forcé, devtools désactivés en prod, Alt+Tab / Ctrl+Alt+Del / Win key bloqués via register system hotkeys, URL bar absente, context menu off, autofill off, inspect off, cookies session-only, cache purge on startup.
- **9.10.6 Mode admin physique** : combinaison touche/geste (5 taps coin + mot de passe) → accès admin drawer sans quitter fullscreen.
- **9.10.7 Watchdog process** : si renderer freeze > 10 s → auto-restart. Log remonté telemetry.

### 5.3 Hardware réel

- **9.10.8 Bridge TPE certifié** : protocol Ingenico / Verifone natif Electron (node addon) + certification PSP locale. Fallback manuel (caissier saisit le code retour) si bridge offline.
- **9.10.9 Imprimante thermique** : ESC/POS, détection paper-out + buzzer, fallback email receipt si imprimante down.
- **9.10.10 Scan QR/NFC** : webcam API + lib zxing (QR) + Web NFC API ou bridge natif pour NFC.
- **9.10.11 Caméra customer** (optionnel) : prise photo pour reconnaissance loyalty (opt-in RGPD strict, consent-first).
- **9.10.12 Buzzer sonore** : signal attention errors + confirmation + nouvelle commande KDS (via bridge GPIO).

### 5.4 Offline resilience

- **9.10.13 Offline order queue** : si réseau KO, commande cash peut être enqueue localement (chiffrée), flush au retour réseau. UX : "Commande mise en attente — sera envoyée dès connexion retrouvée". PAS pour les cartes (TPE offline = décision métier).
- **9.10.14 Menu snapshot IDB** : déjà en place (5 min TTL), étendre à 24 h en fallback si `GET /menu` échoue.
- **9.10.15 Graceful degradation** : si Pusher / Echo down → polling `/menu` toutes les 60 s + banner discret "Mises à jour temps réel interrompues".

### 5.5 Telemetry terrain

- **9.10.16 Dashboard ops temps réel** : par branch, par borne — latence TPE, taux échec paiement, panier moyen, heures de pointe, healthcheck hardware. Refresh 10 s.
- **9.10.17 Alerting seuils** : webhook Slack/email sur `TPE latence > 5 s`, `échec paiement > 10%`, `healthcheck imprimante critical > 5 min`.
- **9.10.18 Trace corrélation** : order_id → analytics events → dashboard → logs Laravel → KDS. Un seul `correlation_id` traversant la stack.
- **9.10.19 Export RGPD** : données borne / utilisateur, anonymisation automatique, retention policy 90 jours sur events ops, 13 mois sur events comptables.
- **9.10.20 SLO explicites** : borne disponible 99.5% (downtime max 44 min/mois), commande réussie P95 < 45 s (idle → confirmation), TPE P95 < 8 s.

### 5.6 Rapport final

- **9.10.21 Rapport de clôture Phase 9** : état des 50 findings, 10 vagues, SLO atteints ou non, roadmap V2.

---

## 6. Questions produit ouvertes (escalade humaine requise)

Ces points nécessitent une décision métier avant cadrage technique :

1. **Split payment** (9.9.7) — oui ou non ? Si oui, comment partager TVA ventilée ?
2. **Prévente** (9.9.8) — quels créneaux, quelle limite, annulation / remboursement possible ?
3. **Reconnaissance photo loyalty** (9.10.11) — consent RGPD ok en France ?
4. **OTA update** (9.10.4) — qui approuve un rollout flotte-wide ? Workflow 4-eyes ?
5. **Lockdown Electron** (9.10.5) — quelle compatibilité avec maintenance technique sur site ?
6. **SLO 99.5%** (9.10.20) — contrat SLA à rédiger ; quelle pénalité si manqué ?
7. **Apple Pay fees** (9.9.1) — négociation PSP ; impact pricing ?

---

## 7. Priorisation suggérée (ROI estimé)

| Phase | Effort | Risque | Valeur | Ordre recommandé |
|---|---|---|---|---|
| P9.6 (analytics+observability+admin) | 2 j | Faible | **Haute** (visibilité ops immédiate) | **1** |
| P9.7 (i18n/a11y/PMR) | 2 j | Faible | Haute (conformité EAA 2025) | 2 |
| P9.8 (tests E2E + CI) | 2 j | Moyen (Playwright flaky) | **Haute** (confidence merge) | 3 |
| P9.10 Electron hardening + OTA (9.10.1→9.10.7) | 3 j | Moyen | **Critique** (pré-requis déploiement) | 4 |
| P9.10 hardware réel (9.10.8→9.10.12) | 5 j | Élevé (bridge natif) | Critique | 5 |
| P9.10 offline (9.10.13→9.10.15) | 2 j | Faible | Haute | 6 |
| P9.10 telemetry (9.10.16→9.10.20) | 2 j | Faible | Haute | 7 |
| P9.9 différenciateurs | 5 j (total) | Moyen | Variable | **Optionnel** — en parallèle de P9.10 si budget |

---

## 8. Principes directeurs

1. **Additive only sur shared zones** — toute touche `OrderService` / `PricingService` / `OrderStateMachine` → LOCK_A + BROADCAST Track B. Zero breaking change cross-track.
2. **SSOT server** sur tout ce qui engage l'argent (prix, taxes, discount, total).
3. **No-PII** sur tout ce qui persiste côté client (localStorage, IndexedDB, cache).
4. **Feature flags par branch** pour chaque différenciateur — pas de feature imposée flotte-wide sans A/B.
5. **Rollback-safe** : chaque commit atomique, chaque migration réversible, chaque OTA update revertable.
6. **Observability first** : aucun nouveau feature sans event + metric + SLO associés.
7. **EAA 2025 compliant** : chaque UX nouveau passe axe-core AA avant merge.

---

## 9. Historique des mises à jour de ce document

- **2026-04-18 — Création initiale.** Post-P9.5 verified / P9.3 démarrage. Orchestrator Track A. Snapshot de l'état des lieux + roadmap P9.7→P9.10. À enrichir en fin de chaque vague.
