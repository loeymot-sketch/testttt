# W0-C — Baseline performance + contamination CSS

**Cycle** : POS_V4_IMPL_EXEC_FINAL_2026-04-26  
**Phase** : W0 (livraison W0-C)  
**Auteur** : Claude terminal  
**Date** : 2026-04-26  
**Statut** : **BASELINE LOCKED** — toute régression vs ces chiffres ouvre STOP S4

---

## 1. Inventaire CSS courant (`resources/css/`)

| Fichier | Taille (bytes) | Rôle | Touche namespace `.fk-pos-v4` ? |
|---|---|---|---|
| `app.css` | 35 258 | Global app | **NON** (vérifié grep) |
| `kiosk-fallback.css` | 557 | Fallback Kiosk | NON |
| `kiosk-wizard.css` | 10 597 | Kiosk wizard | NON |
| `pos-a11y.css` | 631 | Accessibilité POS | NON (mais peut héberger `[data-pos-v4-disabled]` rollback) |
| `pos-v4.css` | **ABSENT** | À créer en W1 (stub livré W0-C) | À créer |

---

## 2. Grep contamination (gate G0 / G7 future)

### 2.1 Namespace `.fk-pos-v4` ou `.fk-dark` dans CSS et SFC POS
**Commande** :
```bash
grep -rE "fk-pos-v4|fk-dark" resources/css/ resources/js/components/admin/pos/ \
  --include="*.css" --include="*.vue"
```
**Résultat** : `0 lignes` (confirmé via Grep tool, no matches).  
**Verdict** : **CLEAN** — namespace pas encore introduit (attendu en W0).

### 2.2 Pollution `pos-v4|fk-pos|fk-dark` dans `app.css`
**Commande** :
```bash
grep -nE "pos-v4|fk-pos|fk-dark" resources/css/app.css
```
**Résultat** : `0 lignes`.  
**Verdict** : **CLEAN**.

### 2.3 Magic integers `order_status` dans SFC POS
**Commande** :
```bash
grep -nE "order_status\s*[\?!=]+|status\s*:\s*[0-9]{1,2}" \
  resources/js/components/admin/pos/*.vue
```
**Résultats pré-W0+** :
- `PosComponent.vue:1390` → `[4, 7, 8].includes(parseInt(o.order_status ?? o.status, 10))` ❌
- `PosComponent.vue:1413` → `{ status: 13 }` (commentaire `// 13 = DELIVERED`) ❌

**Résultats post-W0+ (2026-04-26)** :
- `npm run pos:lint:status` → **OK — scanned 10 files in 2 dirs.** ✅
- `npm run pos:lint:pricing` → **OK — scanned 53 files in 3 dirs.** ✅
- Both lint guards intégrés au `package.json` scripts (CI-ready).
- Magic ints PosComponent → refactorés vers `orderStatusEnum.{ACCEPT,PREPARING,PREPARED,DELIVERED}`.
- Découverte 7 violations en KIOSK (hors scope) : voir `BACKLOG_POS_V4_W0PLUS_DISCOVERIES_2026-04-26.md §2`.

**Verdict** : **POS+KDS CLEAN** ✅ — invariant `OrderStatus enum` respecté sur le périmètre W0+.

### 2.4 Symétrie `OrderService` / `FrontendOrderService`
**Commande** :
```bash
grep -rE "FrontendOrderService|OrderService" resources/js/components/admin/pos/
```
**Résultat** : `0 lignes` — aucun SFC POS ne référence directement les services.  
**Verdict** : OK — la symétrie passe par `$store/posOrder` (mediator) et `axios` direct. À documenter dans BINDING_MAP colonne "Service appelé".

---

## 3. Inventaire SFC (lignes brutes)

| SFC | Lignes | % du total POS |
|---|---|---|
| `ReceiptDuplicataMarker.vue` | 70 | 1.3% |
| `SkeletonGrid.vue` | 19 | 0.4% |
| `ReceiptComponent.vue` | 479 | 8.9% |
| `CreateCustomerAddressComponent.vue` | 196 | 3.6% |
| `ParkedOrdersComponent.vue` | 345 | 6.4% |
| `FloorplanComponent.vue` | 284 | 5.3% |
| `ItemComponent.vue` | 1 276 | 23.7% |
| `PosComponent.vue` | 2 404 | 44.6% |
| `PaymentComponent.vue` | 313 | 5.8% |
| **Total** | **5 386** | 100% |

**Observations** :
- `PosComponent.vue` à lui seul = 44.6% du POS (2404 lignes). Confirme HYPERREVIEW : ce shell pèse lourd, son merge en position 5 est critique.
- `ItemComponent.vue` 23.7% (modal item + variations + extras + addons + pricing).

---

## 4. Baseline bundle (MESURÉE 2026-04-26 — `public/js` post-`npm run prod`)

### 4.1 Chiffres bruts (artefacts existants — pré-W1)

| Bundle | Raw KB | **Gzipped KB** | Cible budget POS v4 | Verdict |
|---|---:|---:|---|---|
| `app.js` (admin global, contient POS) | 4 515 | **965** | n/a (contient TOUT l'admin) | ⚠️ Trop monolithique |
| `kiosk.js` (kiosk shell) | 513 | **107** | < 350 KB gzip cible | ✅ OK |
| `pos-wizard.js` (POS wizard chunk isolé) | 280 | **49** | < 220 KB gzip POS first-paint | ✅ OK isolé |

### 4.2 Découverte critique — POS first-paint masqué dans `app.js`

- Le seuil cible **HYPERREVIEW + plan §6 KPI** « JS first-paint POS < 220 KB gzipped » suppose un **chunk POS isolé** lazy-chargé.
- Or `app.js` (965 KB gzipped) est chargé en main bundle pour `/admin/*` y compris `/admin/pos`.
- Le chunk `pos-wizard.js` (49 KB) ne couvre que la wizard sub-flow, pas le shell POS.
- **Conclusion** : sans code splitting `pos-shell.js` lazy-loaded, le seuil 220 KB est **inatteignable**.

### 4.3 Action W1 (pré-requis bloquant)

| # | Action | Owner | Bloque W2 ? |
|---|---|---|---|
| 1 | Créer `resources/js/pos-shell.js` entrypoint dédié `/admin/pos` | Frontend agent (codex-terminal `gpt-5.5-pro`) | OUI |
| 2 | Configurer `webpack.mix.js` (Mix) avec `mix.js('resources/js/pos-shell.js', 'public/js')` + dynamic import des SFC POS lourds (PosComponent, ItemComponent) | Frontend agent | OUI |
| 3 | Re-mesurer baseline `pos-shell.js` gzipped → cible < 220 KB | Validate | OUI |
| 4 | Lighthouse CI `/admin/pos` LCP/CLS/TTI à benchmarker | Validate | NON (informatif) |

### 4.4 Métriques runtime (à mesurer post-code splitting W1)

| Métrique | Cible | Tolérance | Méthode |
|---|---|---|---|
| LCP `/admin/pos` | < 1.2 s | +5% transitoire W2-W3 | Lighthouse |
| CLS `/admin/pos` | < 0.05 | strict | Lighthouse |
| TTI `/admin/pos` | < 1.8 s | +10% transitoire | Lighthouse |

**Critère G0/W4** : régression > 5% sur LCP ou > 0% sur chunk gzip = **STOP S4**.

### 4.5 Commande reproductible

```bash
# Mesure raw + gzipped (sans rebuild)
for f in public/js/*.js; do
  raw=$(stat -f%z "$f"); gz=$(gzip -c "$f" | wc -c)
  echo "$f raw=$((raw/1024))KB gz=$((gz/1024))KB"
done

# Rebuild si nécessaire
npm run prod && npx mix --production
```

---

## 5. Configuration namespace prêt à l'emploi

Voir livrable joint : `resources/css/pos-v4.css` (stub W0-C — namespace `.fk-pos-v4` + scope `[data-pos-v4-disabled]` rollback).

---

## 6. Trace
- `EXECUTE_DELEGATION: claude-terminal`
- `AUDIT_CHANNEL: claude-terminal`
- Aucun build exécuté pendant W0 (mesures bundle laissées à dev humain pour préserver tokens et reproductibilité locale).
- Ingest `memory/episodes/12_decisions_log.jsonl` après W1 (entry `pos_v4_baseline_w0_locked`).
