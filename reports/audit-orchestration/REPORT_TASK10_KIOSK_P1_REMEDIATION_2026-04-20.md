# T10 — Audit kiosk 110 % — 5 P1 remédiation

**Date.** 2026-04-20  
**Racine.** `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt`  
**Verdict global.** **PASS** (audit) — 1 fixed (AX4-04), 4 open (AX12-02, AX11-01, AX10-01, AX14-01).  
**Note.** `reports/review/AUDIT_KIOSK_110_FINDINGS_TRACKER.md` référencé par la tâche mais **absent** de ce dépôt.

## Synthèse

| ID | Statut | Fichier(s) clé | Action proposée | Effort |
|----|--------|----------------|-----------------|--------|
| AX12-02 | **open** | `app/Listeners/PersistOrderCreatedToOutbox.php` | Remplacer `Str::uuid()` par l'ID de corrélation requête (`X-Correlation-ID`/`Log::sharedContext`), gérer cas async/queue. | M |
| AX4-04 | **fixed** | `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue` | Garde-fou maintenu (T06b) : total serveur obligatoire en ligne ; tests régression API sans `total`. | S |
| AX11-01 | **open** | `app/Http/Resources/NormalItemResource.php` ; `app/Services/Kiosk/KioskMenuService.php` | Aligner `is_available` fiche item sur `item_branch_availability` (comme `projectItems`). | M–L |
| AX10-01 | **open** | (aucun fichier app) ; cf. `reports/review/VERIFY_12_SECURITY_2026-04-20.md` | Cycle P12 SECURITY HEADERS : middleware CSP avec nonces, Permissions-Policy, COOP/CORP, HSTS proxy. | L (transverse) |
| AX14-01 | **open** | `tests/e2e/03-kiosk-wizard.spec.js` (smoke seul) | Spec Playwright golden-path : login → menu → panier → preview → paiement (mock TPE/API). | L |

## Détail par finding

### AX12-02 — Corrélation outbox
`correlation_id` reste UUID local, pas le `X-Correlation-ID` HTTP.

```33:34:/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Listeners/PersistOrderCreatedToOutbox.php
            'correlation_id' => (string) Str::uuid(),
            'occurred_at' => now(),
```

### AX4-04 — Total paiement / fallback
**Corrigé en ligne** par T06b : total invalide → exception, plus de repli silencieux sur `cartTotal` (sauf commande offline explicite).

```293:304:/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/components/frontend/kiosk/KioskPaymentComponent.vue
        const rawTotal = res?.data?.data?.total ?? res?.data?.data?.order_amount;
        let total;
        if (isOfflineId) {
          total = this.cartTotal;
        } else {
          const n = rawTotal != null && rawTotal !== '' ? Number(rawTotal) : NaN;
          if (!Number.isFinite(n)) {
            throw new Error(this.$t('kiosk.pay_screen.invalid_order_response'));
          }
          total = n;
        }
```

### AX11-01 — `is_available` global vs branche
`NormalItemResource` expose le **flag global**, pas la disponibilité branche.

```53:57:/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Http/Resources/NormalItemResource.php
        // Source de vérité : flag global `Item.is_available` (scope POS/kiosk/web).
        // La disponibilité par branche (`ItemBranchAvailability`) est gérée séparément
        // par `KioskMenuService::projectItems` pour les projections kiosk par branche.
        $isAvailable = $this->is_available === null ? true : (bool) $this->is_available;
```

```256:281:/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Services/Kiosk/KioskMenuService.php
            $avail = $availability->get($item->id);
            $isAvailable = $avail ? (bool) $avail->is_available : true;
            ...
                'is_available'       => $isAvailable && (bool) ($item->is_available ?? true),
```

### AX10-01 — CSP applicative
Pas de `config/csp.php`, pas de middleware CSP repéré dans `app/Http/Middleware`. `VERIFY_12_SECURITY_2026-04-20.md` §V6 : aucune en-tête `Content-Security-Policy` côté app. Plan dédié **P12_SECURITY_HEADERS**.

### AX14-01 — Playwright golden path kiosk paiement
Pas de `tests/e2e/kiosk/golden-path*.spec.ts`. Le seul E2E kiosk est un smoke (`tests/e2e/03-kiosk-wizard.spec.js`) sans flux paiement / mock TPE.

## Tableau finding → état actuel

| Finding | État |
|---------|------|
| AX12-02 | **open** — `Str::uuid()` toujours utilisé pour `correlation_id` outbox. |
| AX4-04 | **fixed** — total serveur requis en ligne ; `cartTotal` réservé au flux offline. |
| AX11-01 | **open** — `NormalItemResource` global ; menu kiosk branche via `KioskMenuService`. |
| AX10-01 | **open** — pas de CSP en app ; plan P12. |
| AX14-01 | **open** — pas de golden path Playwright kiosk → paiement. |

## Décision

**T10 PASS audit** : 5 P1 statuts + preuves + plans documentés ; 1 fixed, 4 ouverts en backlog.
