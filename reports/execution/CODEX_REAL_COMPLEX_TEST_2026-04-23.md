# Test réel "tâches complexes" — Codex API delegation

Date : 2026-04-23
Endpoint : `https://subtp7eu3nc8.tokenclub.top/v1`
Runner : `npm run codex:complex` (mode streaming forcé suite aux 504 récurrents en non-stream sur prompts longs)

## Pourquoi ce test

Tu as demandé un test réel sur du complexe / créatif (pas du ping). J’ai créé deux missions FoodKing-réalistes, lancé chacune sur **gpt-5.4** ET **gpt-5.4-pro**, et inspecté les sorties.

## Missions

### 1) `T-COMPLEX-POS-PAYMENT`
- Objectif : composant Vue 3 `PosPaymentSplitDialog.vue` (split cash + carte) avec règle stricte cash + card === total au centime, props validés, emit `confirm` avec entiers en centimes + `txn_uuid`, aucun appel API, aucun calcul de prix front.
- Format demandé : SFC `.vue` complet, rien d’autre.
- Inputs : `missions/T-COMPLEX-POS-PAYMENT/input.json`

### 2) `T-COMPLEX-LARAVEL-JOB`
- Objectif : `App\Jobs\NotifyKitchenOnOrderConfirmed` — recharge Order avec `where('branch_id', ...)`, vérifie statut via enum `App\Enums\OrderStatus::CONFIRMED` (jamais string), broadcast sur canal privé `branch.{id}.kitchen`, gère `ModelNotFoundException`, `tries=3`, `backoff=30`.
- Format demandé : code PHP complet, rien d’autre.
- Inputs : `missions/T-COMPLEX-LARAVEL-JOB/input.json`

## Résultats bruts

| Mission | Modèle | Sortie | Taille | Statut |
|---|---|---|---|---|
| POS payment | gpt-5.4 | `output_gpt54.json` | 9 041 octets | ✅ |
| POS payment | gpt-5.4-pro | `output_gpt54pro.json` | 8 633 octets | ✅ (1 retry 504) |
| Laravel Job | gpt-5.4 | `output_gpt54.json` | 1 334 octets | ✅ |
| Laravel Job | gpt-5.4-pro | `output_gpt54pro.json` | 1 633 octets | ✅ |

## Audit qualitatif (invariants FoodKing)

### POS payment — gpt-5.4 (`missions/T-COMPLEX-POS-PAYMENT/output_gpt54.json`)
- ✅ SFC complet : `<template>` + `<script setup>` + `<style scoped>`
- ✅ Props : `total: Number` (validator `Number.isInteger && >= 0`), `currency: String`, `orderId: Number`
- ✅ `emit('confirm', { cash, card, txn_uuid })` avec entiers centimes
- ✅ Validation **au centime** via `Math.round(amount * 100)` puis `enteredTotalCents === props.total`
- ✅ Bouton Confirmer désactivé si invalide (`:disabled="!isValid"`)
- ✅ Aucun calcul fiscal / aucune string status / aucun appel API
- ✅ A11y : `role="dialog"`, `aria-modal`, `aria-labelledby`
- ✅ Format monétaire `Intl.NumberFormat` localisé
- ✅ Génération `txn_uuid` via `crypto.randomUUID()` (fallback)

### POS payment — gpt-5.4-pro (`missions/T-COMPLEX-POS-PAYMENT/output_gpt54pro.json`)
- ✅ Mêmes invariants couverts + ajout `v-model` (`modelValue`/`update:modelValue`)
- ✅ Sanitization input (gère `,` → `.`, double point, etc.)
- ✅ Slightly plus défensif sur le parsing

### Laravel Job — gpt-5.4
- ✅ Enum `App\Enums\OrderStatus::CONFIRMED` (pas de string)
- ✅ `where('branch_id', $this->branchId)->findOrFail($this->orderId)` (isolation explicite)
- ✅ `with('kitchenItems')`
- ✅ `public int $tries = 3; public int $backoff = 30;`
- ✅ `implements ShouldQueue`, traits `Queueable, SerializesModels, Dispatchable, InteractsWithQueue`
- ✅ Try/catch `ModelNotFoundException` → return propre
- ✅ Broadcast event `order.kitchen.notify` sur `PrivateChannel("branch.{id}.kitchen")`
- ✅ Aucune écriture BD, aucune logique de prix

### Laravel Job — gpt-5.4-pro
- ✅ Mêmes invariants + traits sur lignes séparées (PSR cleaner)
- ✅ Payload broadcast plus structuré (`order_id`, `branch_id`, `status->value`, `kitchen_items`)
- ✅ Cast enum→value au broadcast (utile pour clients front)

## Verdict

**OUI — le système fonctionne sur des tâches réellement complexes.**

- 4/4 sorties produites, **zéro vide**, **zéro malformée**.
- Les deux modèles respectent **tous les invariants explicites** (branch_id, enum OrderStatus, pas de prix front, cents entiers, no string status).
- gpt-5.4 et gpt-5.4-pro sont **utilisables tous les deux** comme moteur du sub-agent complex implementer.
- gpt-5.4-pro produit du code légèrement plus polish/défensif. gpt-5.4 est plus rapide (~17s vs ~23s sur le job Laravel).

## Conditions d’usage validées (corrections faites pendant ce test)

1. **Streaming activé par défaut** — le proxy renvoie `504 Gateway time-out` (HTML Cloudflare) sur les prompts longs en mode non-stream. Le streaming garde la connexion vivante et passe sans souci.
2. **Retry sur 504** — ajouté à `isRetry` dans `agents/codex.runner.mjs` (et fallback HTML→`makeApiError(504)` dans `doOneShot`).
3. **`CODEX_RAW_PROMPT=1`** — pour les missions où on veut le format de sortie libre (Vue/PHP brut), bypass le template JSON-strict du sub-agent.
4. **`CODEX_NO_NORMALIZE_M`** — déjà en place : règle le bug proxy sur la clé `m` top-level.

## Ce que ça change concrètement pour toi

- Les sub-agents Cursor "complex implementer" peuvent maintenant déléguer en confiance à `npm run codex:complex` — le workflow `run-cycle` Step 2 reste valable.
- Pour du code structuré "hors-template" (composants/jobs/migrations), utiliser `CODEX_RAW_PROMPT=1` avec un `input.json` qui décrit la sortie attendue (comme dans ces deux missions).
- Pour les sorties JSON-strict orchestration (plan, files_to_modify, …), utiliser le template par défaut `agents/codex.prompt.txt`.
- Garder `RETRY_MAX=5` minimum (`agents/codex.env.example`) car le proxy a des pics de 504/429.

## Fichiers générés / inspectables

```
missions/T-COMPLEX-POS-PAYMENT/
  input.json
  output_gpt54.json       (Vue SFC complet, 9KB)
  output_gpt54pro.json    (Vue SFC complet, 8.6KB)

missions/T-COMPLEX-LARAVEL-JOB/
  input.json
  output_gpt54.json       (PHP class, 1.3KB)
  output_gpt54pro.json    (PHP class, 1.6KB)

agents/codex.runner.mjs    (retry 504 + fallback HTML)
```
