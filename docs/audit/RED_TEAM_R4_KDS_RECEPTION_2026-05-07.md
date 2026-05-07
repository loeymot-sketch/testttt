# RED TEAM R4 — KDS RÉCEPTION + STATUS TRANSITIONS

**Date** : 2026-05-07
**Cible** : KDS (Kitchen Display System) — réception commandes + transitions PENDING → ACCEPT → PREPARING → PREPARED
**Mission** : challenger le verdict "FoodKing V1 PRODUCTION-READY" sur le KDS suite au passage MEGA-C blue team. Reproduire la rigueur R1/R2/R3.
**Spec** : `tests/e2e/red-team-r4-kds-reception-2026-05-07.spec.js` (17 tests, 1 worker, mode serial)
**Artefacts** :
- `tests/e2e/screenshots/red-team-r4-kds-2026-05-07/findings.json`
- `tests/e2e/screenshots/red-team-r4-kds-2026-05-07/dom-snapshots.json`
- `tests/e2e/screenshots/red-team-r4-kds-2026-05-07/status-transitions-trace.json`
- `tests/e2e/screenshots/red-team-r4-kds-2026-05-07/R4-06-a11y.json`
- 13 PNG durables `step-*.png`
- `INDEX.md`

**Résultat global** : 17/17 PASS. 1 finding **P1 STATIC** (bug réel, non bloquant V1), 6 findings **P2** (UX/a11y), 10 OK.

---

## 1. Méthodologie adversaire

Suite à un cadrage advisor avant exécution, la stratégie a été ajustée :

- **Static cites** pour invariants déjà verrouillés par le code (KD4, KD10, KD11, KD12 partiel) — éviter de runtime-tester un truc que le harness ne peut pas refuter.
- **Runtime probes** pour ce que la machine peut réellement prouver : a11y axe, race condition concurrente, propagation cross-surface POS→KDS via polling fallback, multi-items, branch isolation cross-branch via order forgé.
- **Limitations honnêtes** déclarées en tête du spec :
  1. `laravel-websockets`/Reverb non démarrés → propagation Pusher live = NON-VALIDABLE runtime, on mesure le polling fallback (5s) à la place.
  2. `QUEUE_CONNECTION=database`, aucun queue:work détecté → jobs non drainés, mais `OrderStatusChanged` est `ShouldBroadcastNow` direct (pas via job).
  3. KD8 heartbeat WS : impossible de simuler disconnect runtime sans serveur réel ; static cite + banner probe seuls.
  4. KD12 cross-branch : seul `branch_id=1` existe dans le seed (vérifié via tinker `\App\Models\Branch::count() === 1`). Cross-branch teste donc en **forgeant** un order `branch_id=99` puis en vérifiant que chef (branch=1) ne peut pas le toucher.
  5. Audio autoplay bloqué sans user gesture : on probe `audio.currentTime` + invocation `play()`, pas la sortie audio réelle.
  6. Single-line tinker via `spawnSync` (pas `execSync`) pour préserver les `$vars` PHP face à l'expansion shell.

---

## 2. Bilan par scénario

| ID    | Cible | Type     | Sév | Verdict |
|-------|-------|----------|-----|---------|
| R4-01 | KD10 rollback READY→IN_PREP | STATIC | OK  | Réfuté par construction — 3 verrous convergents |
| R4-02 | KD4 double-clic idempotence | STATIC | OK  | Lock optimiste + 409 garanti |
| R4-03 | KD11 audit log présent | STATIC | OK  | `recordTransition()` écrit pour toute transition forward |
| R4-04 | KD12 branch isolation (statique) | STATIC | OK  | 2 verrous (list + changeStatus) |
| R4-05 | Surface KDS chargée | RUNTIME | OK  | 0 fatal, 0 erreur console critique, 0 page error |
| R4-06 | a11y axe-core WCAG 2.0/2.1 A+AA | RUNTIME | OK  | **0 violations** axe (vs 5 max en R1/R2 hors POS sentinels) |
| R4-07 | KD1 role="article" / KD2 aria-live | RUNTIME | P2  | 8 cartes sans `role="article"` ; **0 live region** dédiée aux transitions de statut |
| R4-08 | KD5 sound silence sur churn ±1 | STATIC+RUNTIME | **P1** | **Bug réel** dans le watcher length-based |
| R4-09 | KD4 race 409 sur double POST | RUNTIME | OK  | **Confirmé runtime** : codes=[202, 409], DB status=7, audit transition row écrite |
| R4-10 | KD6 annulation post-acceptation | RUNTIME | OK  | Ticket retiré du KDS en ≤7s après cancel via OrderStateMachine |
| R4-11 | KD9 in-flight item 86 (rupture) | RUNTIME | P2  | KDS reçoit l'event mais n'affiche **aucun badge OOS** sur les lignes en cours |
| R4-12 | ws-reconnect-banner / KD8 heartbeat | RUNTIME | OK  | Banner mode secours présent (wsConnected=false, polling fallback actif) |
| R4-13 | KD3 focus management clavier | RUNTIME | P2  | Pas de skip-link / shortcut clavier pour traverser les tickets |
| R4-14 | KD7 édition POS post-acceptation | STATIC | P2  | **Pas d'endpoint pos-order/edit-items** + KDS n'écoute **pas** OrderItemsChanged |
| R4-15 | KD12 isolation cross-branch | RUNTIME | OK  | Chef (branch=1) ↔ order branch=99 → HTTP 404 (BranchScope avant route binding) |
| R4-16 | Multi-articles 8 items | RUNTIME | P2  | 8 items rendus mais accordéon **collapsed par défaut** (height=0) |
| R4-17 | Synthèse + INDEX.md | OK | OK | 17 findings persistés |

---

## 3. Top 5 failles P0/P1 (du plus critique au moins)

### P1 — KD5 : sound silencieux quand +1 entre / -1 sort simultanément
**Localisation** : `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:921-929`

```js
watch: {
  orders(newVal, oldVal) {
    if (!this._kdsOrdersHydrated || oldVal === undefined) return;
    if (newVal.length > oldVal.length) { this.playKdsNewOrderSound(); }
  },
},
```

**Bug** : le watcher déclenche le chime UNIQUEMENT si `newVal.length > oldVal.length`. En heure de pointe, si 1 nouvelle commande ACCEPT entre **ET** 1 commande PREPARED sort du board "all orders" actif (cf. `_isVisibleInCurrentBoard:1300-1307` qui exclut PREPARED par défaut), `length` reste stable ⇒ **silence**. Le chef ne sait pas qu'une commande vient d'arriver.

**Impact métier** : commande oubliée en heure de pointe = ticket en retard, expérience client dégradée. Probable en pratique (rush 12h/19h).

**Correctif suggéré** : comparer un Set d'IDs entre `oldVal` et `newVal` (`newVal.filter(o => !oldIds.has(o.id))`) plutôt que la longueur.

**Pourquoi P1 et pas P0** : on n'a pas pu prouver runtime que le scénario "+1/-1 simultané" se produit avec une probabilité élevée (le `_debouncedRefresh` 300ms + le polling 5s/60s peuvent étaler les events) ; **la condition existe au niveau du code et persistera tant que le watcher reste length-based**.

---

### P2 — KD7 : édition POS post-envoi cuisine non synchronisée KDS
**Localisation** : `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:1261-1275`

Le KDS écoute :
- `OrderStatusChanged`
- `OrderCreated`
- `OrderPaidAtCounter`
- `ItemAvailabilityChanged`
- `OrderTableChanged`

**Mais PAS `OrderItemsChanged` / `OrderEdited`**. Et grep `routes/api.php` ne montre **aucun endpoint** `pos-order/edit-items` qui dispatche un event.

**Impact** : si le caissier corrige une ligne (qty, addon, instruction) après envoi cuisine **sans changer le statut**, le chef reste sur le snapshot d'origine pendant ≤60s (polling normal) ou ≤5s (mode dégradé). Confusion serveur possible.

**Question blue team** : est-ce un trou volontaire (V1 ne supporte pas l'édition post-envoi) ou un manque ?

---

### P2 — KD11 RUPTURE in-flight non distinguée côté KDS (lien R3-F3)
**Localisation** : `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:1264-1268`

```js
{ broadcastAs: 'ItemAvailabilityChanged', handler: () => { this._debouncedRefresh(); } },
```

Le KDS reçoit bien le signal de rupture, mais le handler **ne fait que rafraîchir la liste**. Il n'y a pas de badge OOS / ruptur / 86 sur les lignes d'items déjà acceptées avec cet item. Probe DOM : `has_oos_class_in_dom=false`.

**Impact** : si un item passe en rupture après acceptation (situation R3-F3), le chef peut continuer à le préparer sans savoir qu'un client suivant ne pourra plus en commander, et ne sait pas si la ligne en cours doit être maintenue ou substituée. R3-F3 a déjà ouvert un plan dédié.

---

### P2 — KD1 + KD2 : a11y des cartes / pas de live region statut
**Localisation** : Vue:204, 328, 451, 580 (cartes par lane) + multiple

- Les cartes de commandes (Sur place / En ligne / À emporter / Borne) n'ont **pas** `role="article"` ni `aria-labelledby` pointant vers le titre `#{order_serial_no}`.
- Les transitions de statut (ACCEPT→PREPARING→PREPARED) n'ont **aucune live region** dédiée. Un lecteur d'écran ne saura pas qu'une commande vient de changer d'état.
- **Note importante** : axe-core a renvoyé **0 violations**. La déficience est **structurelle** (manque de sémantique riche) et hors du périmètre WCAG mécanique (axe n'oblige pas à utiliser `role="article"` quand le DOM est navigable autrement).

---

### P2 — KD3 : focus management clavier inter-tickets
**Localisation** : Vue (board principal) — pas de focus trap, pas de shortcut clavier

Modal allergens a un focus trap dédié (Vue:1064-1095) ; le board principal n'a **aucun mécanisme clavier** pour traverser les cartes ou activer rapidement "Start Preparing" / "Mark Done" sans souris. Pour une station cuisine en milieu humide, accessibilité clavier est utile (gants, écran tactile défaillant).

---

## 4. Top 10 questions au blue team

1. **KD5 watcher length-based** : avez-vous mesuré la fréquence réelle du scénario +1/-1 simultané en prod ? Si oui, données ? Si non, accepte-t-on le P1 résiduel pour V1 ou patch immédiat ?
2. **KD7 OrderItemsChanged** : est-ce un trou volontaire pour V1 (édition post-envoi non supportée) ? Ou doit-on prévoir le broadcast + listener KDS ?
3. **KD11 in-flight 86** : R3-F3 plan dédié — quel est le delivery date envisagé ? Le bandeau "item OOS" doit-il être au niveau ligne ou au niveau ticket ?
4. **KD1 `role="article"`** : axe-core ne l'exige pas mais c'est un standard d'usage ARIA. Acceptez-vous d'ajouter `role="article"` + `aria-labelledby` aux 4 lanes ?
5. **KD2 live region transitions** : faut-il une `<div aria-live="polite">` séparée annonçant "Order #1234 ready" à chaque transition ? Coût UI minimal, gain a11y net.
6. **KD3 shortcuts clavier** : `j/k` pour naviguer, `Enter` pour avancer le statut, `Esc` pour fermer modal — pertinent pour V1 ou réservé V2 ?
7. **KD8 heartbeat WS** : sans serveur Pusher démarré, peut-on tester en CI un scénario disconnect/reconnect ? Y a-t-il un mock harness ?
8. **KD16 accordéon collapsed** : les 8 items d'un ticket sont rendus dans `<div style="height: 0px">` cf. Vue:245. Le chef doit cliquer chevron pour les voir. Est-ce intentionnel pour le board "récap" ou un bug d'affichage par défaut ?
9. **KD15 BranchScope 404** : la BranchScope retourne 404 (mieux que 403 — pas de leak) avant l'abort 403 explicite dans `KitchenDisplaySystemOrderService:158-161`. Le second contrôle est-il du défensif redondant ou y a-t-il un chemin où le scope est bypassé ?
10. **KD audit_log correlation** : `recordTransition` extrait `correlation_id` depuis `request()?->header('X-Correlation-ID')`. Le frontend KDS envoie-t-il bien ce header sur chaque `change-status` ? Ou la chaîne de causalité est-elle cassée pour les actions chef ?

---

## 5. Verdict adversaire

### **HEAL** (pas BLOCK, pas PROD-READY non plus)

Le KDS est **substantiellement plus solide** que ce que la rigueur R1/R2/R3 a trouvé sur POS / kiosk / rupture stock. Les invariants critiques sont **tous verrouillés par construction** :

- **Forward-only state machine** (KitchenReleaseRule + OrderStateMachine + KdsOrderStatusRequest) — 3 verrous convergents, runtime 409 confirmé.
- **Branch isolation** double-couche (BranchScope global + abort 403 explicite) — runtime 404 sur cross-branch confirmé.
- **Audit log** (`OrderStatusTransition`) écrit pour toute transition forward avec `correlation_id`.
- **0 violations a11y** axe-core (vs 1 P1 résiduel sur POS / kiosk).
- **0 erreur console / page error** sur surface KDS chargée.

**MAIS**, 1 P1 statique réel (KD5 sound silence) et 4 P2 (a11y richer, accordéon par défaut, KD7 édition non sync, KD11 in-flight) doivent être tranchés avant de claim PROD-READY :

- KD5 est le plus probable en pratique (pas un bug théorique : la condition `length` existe dans le code et le scénario +1/-1 est statistiquement attendu en heure de pointe).
- KD7 est un trou de cohérence de donnée si le POS supporte l'édition post-envoi (à clarifier scope V1).

### Comparaison avec R1/R2/R3

| Surface | Findings P0 | Findings P1 | Findings P2 |
|---------|-------------|-------------|-------------|
| R1 POS  | 1-2         | 4-5         | plusieurs   |
| R2 Kiosk| 1           | 3           | plusieurs   |
| R3 Rupture | 0 (P0 réfuté harness) | 1 réel | plusieurs |
| **R4 KDS** | **0** | **1** | **6** |

R4 confirme que le KDS est la surface la mieux verrouillée des 4. **MEGA-C n'a PAS hallucinated** son verdict structurel (transitions, isolation, audit, a11y axe), MAIS a manqué le KD5 length-based watcher — exactement le type de bug runtime que les sentinels structurels ratent.

**Recommandation** : patch KD5 (15-30 min, watcher → ID-based diff) + plan dédié KD7+KD11 (cohérence édition + 86 in-flight) → upgrade verdict à PROD-READY après healing.

---

## 6. Limitations honnêtes

1. **Pas de serveur Pusher / Reverb en local** ⇒ propagation broadcast `OrderStatusChanged` non testée live. La latence Pusher → KDS render n'est PAS mesurée. Mesuré uniquement : polling fallback 5s.
2. **Pas de queue:work** ⇒ jobs `SendOrderMail/Sms/Push` non drainés. `OrderStatusChanged` est `ShouldBroadcastNow` direct donc ce point n'invalide pas R4-09 / R4-10, mais mail/sms/push ne sont pas vérifiés.
3. **Single-branch dataset** ⇒ KD12 cross-branch testé via order forgé `branch_id=99` (pas de user branch=2). Si plusieurs vrais users sur branches différentes existaient, on testerait l'isolation visuelle (chef branch=2 ne voit pas commande branch=1 dans `list()`).
4. **R4-08 KD5 = static-confirmed**, pas runtime-confirmed. Reproduire le scénario +1/-1 simultané en E2E exigerait d'orchestrer 2 mutations concurrentes sur 2 commandes différentes du même branch ⇒ faisable mais coûteux ; le bug code-level est suffisant pour P1.
5. **Audio autoplay** : `el.play()` peut être bloqué par browser policy sans user gesture. Probe `audio.currentTime` après watcher trigger ne prouve pas que le chef entendra le son ; il prouve juste que la fonction est appelée.
6. **R4-13 focus probe** : `0 action buttons trouvés` parce que la base n'avait pas de tickets ACCEPT/PREPARING actifs au moment du test (les ordres seedés sont nettoyés en cleanup). Pour mesurer focus inter-tickets en pratique, il faudrait keep-alive un ticket pendant le test.
7. **R4-12 ws probe** : `wsConnected=false` car pas de serveur Pusher, **donc** banner mode secours s'affiche (comportement attendu). Mais on n'a pas pu tester le toggle `connected → disconnected → reconnected` en runtime.

---

## 7. Conclusion

R4 confirme la solidité structurelle du KDS (transitions, isolation, audit, a11y axe) mais détecte un **bug runtime probable (KD5 sound silence)** que les sentinels statiques de MEGA-C ont raté — exactement le pattern R1/R2/R3 a déjà identifié sur les autres surfaces.

**Verdict adversaire** : HEAL. Patch KD5 (15-30 min) + plan KD7+KD11 → PROD-READY.

**Sentinel oubliée** à ajouter : un test runtime qui crée une commande ACCEPT, en avance une autre vers PREPARED, et vérifie qu'**une chime est jouée** quand `length` est stable mais qu'un nouvel ID apparaît. Cette sentinel garantit que le watcher ne régressera pas.
