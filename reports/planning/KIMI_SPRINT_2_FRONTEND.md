# KIMI PLAN — SPRINT 2 : FRONTEND STABILITY & MEMORY LEAKS
**Émetteur :** Claude (Architecte)
**Destinataire :** KIMI (Builder)
**Priorité :** 🟡 P1 — Impact stabilité des sessions POS/KDS sur longue durée
**Fichier de retour :** `reports/execution/latest.md`
**Prérequis :** Sprint 1-A et 1-B complétés

---

## Vue d'ensemble

Ce sprint corrige les **problèmes de stabilité frontend** qui provoquent des crashes ou ralentissements après plusieurs heures d'utilisation en production (rush midi + soir) :
1. Memory leaks Vue (event listeners non nettoyés)
2. setInterval non cleared → accumulation de threads fantômes
3. Pagination manquante sur KDS

---

## FIX-FRONT-01 : Memory Leak — KitchenDisplaySystem

**Fichier :** `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue`

### Localiser le problème

```bash
grep -n "addEventListener\|setInterval\|setTimeout\|echo\.\$on" \
    resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue
```

### Pattern à corriger

```javascript
// AVANT — Listener non nettoyé (memory leak)
mounted() {
    window.addEventListener('resize', this.handleResize);
    this.refreshInterval = setInterval(() => {
        this.fetchOrders();
    }, 10000);
    
    // Echo/Pusher listener non nettoyé
    window.Echo.channel('orders').listen('.order.status', (e) => {
        this.updateOrder(e);
    });
},
// → Quand le composant est détruit, le listener reste en mémoire !

// APRÈS — Nettoyage proper
mounted() {
    window.addEventListener('resize', this.handleResize);
    this.refreshInterval = setInterval(() => {
        this.fetchOrders();
    }, 10000);
    
    // Conserver une référence pour le cleanup
    this.echoChannel = window.Echo?.channel('orders');
    this.echoChannel?.listen('.order.status', (e) => {
        this.updateOrder(e);
    });
},

beforeUnmount() {  // Vue 3 : beforeUnmount | Vue 2 : beforeDestroy
    // Supprimer les event listeners
    window.removeEventListener('resize', this.handleResize);
    
    // Arrêter l'interval
    if (this.refreshInterval) {
        clearInterval(this.refreshInterval);
        this.refreshInterval = null;
    }
    
    // Quitter le channel Echo
    this.echoChannel?.stopListening('.order.status');
    window.Echo?.leaveChannel('orders');
},

data() {
    return {
        refreshInterval: null,  // Référence pour pouvoir le clear
        echoChannel: null,
        // ... autres données ...
    };
},
```

---

## FIX-FRONT-02 : Memory Leak — RealtimeReport

**Fichier :** `resources/js/components/admin/dashboard/RealtimeReportComponent.vue` (ou équivalent)

### Localiser les setInterval

```bash
grep -rn "setInterval\|clearInterval\|beforeUnmount\|beforeDestroy" \
    resources/js/components/admin/ --include="*.vue" | grep -v "clearInterval\|beforeUnmount\|beforeDestroy"
```

**Liste tous les fichiers avec setInterval mais sans clearInterval** — chacun est un memory leak.

### Règle de base à appliquer sur chaque composant trouvé

```javascript
// Règle : toujours stocker la référence dans data()
data() {
    return {
        pollInterval: null,  // Ajout de la référence
    };
},

mounted() {
    // Démarrer le polling
    this.pollInterval = setInterval(this.fetchData, 5000);
},

beforeUnmount() {
    // Nettoyer à la destruction
    clearInterval(this.pollInterval);
},
```

---

## FIX-FRONT-03 : Scroll Listener — TableNavBarComponent

**Fichier :** `resources/js/components/admin/diningTable/TableNavBarComponent.vue`

```bash
grep -n "addEventListener\|scroll\|beforeUnmount\|beforeDestroy" \
    resources/js/components/admin/diningTable/TableNavBarComponent.vue
```

```javascript
// Même pattern : stocker la fonction et la retirer dans beforeUnmount
data() {
    return {
        scrollHandler: null,
    };
},

mounted() {
    this.scrollHandler = this.handleScroll.bind(this);
    window.addEventListener('scroll', this.scrollHandler);
},

beforeUnmount() {
    window.removeEventListener('scroll', this.scrollHandler);
},
```

---

## FIX-FRONT-04 : Audit global de tous les composants Vue

### Script de détection automatique

```bash
# Trouver tous les Vue components qui ont addEventListener mais pas removeEventListener
echo "=== Components avec addEventListener sans removeEventListener ==="
for f in $(grep -rl "addEventListener" resources/js/components/ --include="*.vue"); do
    if ! grep -q "removeEventListener" "$f"; then
        echo "⚠️  LEAK: $f"
    fi
done

# Trouver tous les Vue components qui ont setInterval mais pas clearInterval
echo ""
echo "=== Components avec setInterval sans clearInterval ==="
for f in $(grep -rl "setInterval" resources/js/components/ --include="*.vue"); do
    if ! grep -q "clearInterval" "$f"; then
        echo "⚠️  LEAK: $f"
    fi
done
```

**KIMI doit lister tous les fichiers retournés par ce script et corriger chacun.**

---

## FIX-FRONT-05 : Ajouter pagination sur KDS

**Fichier :** `app/Services/KitchenDisplaySystemOrderService.php`

```bash
grep -n "get()\|all()" app/Services/KitchenDisplaySystemOrderService.php
```

```php
// AVANT — Charge TOUTES les commandes (dangereux en production)
return Order::where('branch_id', $branchId)
    ->whereIn('status', [...])
    ->get();

// APRÈS — Pagination + limite raisonnable pour un KDS
return Order::where('branch_id', $branchId)
    ->whereIn('status', [...])
    ->orderBy('created_at', 'asc')  // Plus vieilles d'abord (FIFO)
    ->limit(50)  // Max 50 commandes actives sur un KDS à la fois
    ->get();
```

---

## ✅ TESTS OBLIGATOIRES KIMI (Sprint 2)

### TEST-FRONT-01 : Détecter les leaks restants
```bash
# Après corrections, ce script doit retourner 0 fichiers
for f in $(grep -rl "addEventListener" resources/js/components/ --include="*.vue"); do
    if ! grep -q "removeEventListener" "$f"; then
        echo "❌ LEAK RESTANT: $f"
    fi
done

for f in $(grep -rl "setInterval" resources/js/components/ --include="*.vue"); do
    if ! grep -q "clearInterval" "$f"; then
        echo "❌ LEAK RESTANT: $f"
    fi
done

echo "Si aucune ligne n'est affichée → ✅ Tous les leaks sont fixés"
```

### TEST-FRONT-02 : Vérifier que beforeUnmount est présent
```bash
grep -rn "beforeUnmount\|beforeDestroy" resources/js/components/admin/kitchenDisplaySystem/ --include="*.vue"
# ATTENDU : Au moins 1 résultat

grep -rn "clearInterval" resources/js/components/admin/ --include="*.vue"
# ATTENDU : Au moins autant de clearInterval que de setInterval
```

### TEST-FRONT-03 : Test stabilité KDS (simulé)
```
1. Ouvrir /admin/kitchen-display-system dans le navigateur
2. Ouvrir DevTools → Memory → Heap Snapshot
3. Attendre 5 minutes (laisser le polling tourner)
4. Prendre un 2ème Heap Snapshot
5. Comparer : la mémoire ne doit pas augmenter significativement
6. Naviguer vers /admin/dashboard puis revenir sur KDS
7. Prendre un 3ème Heap Snapshot → même taille que le 2ème
```

---

## 📄 Auto-Audit KIMI

```bash
echo "=== Memory Leaks Restants ==="
for f in $(grep -rl "setInterval" resources/js/components/ --include="*.vue"); do
    if ! grep -q "clearInterval" "$f"; then
        echo "❌ $f"
    fi
done

echo "=== Pagination KDS ==="
grep -n "limit\|paginate" app/Services/KitchenDisplaySystemOrderService.php
# Doit afficher au moins une ligne
```
