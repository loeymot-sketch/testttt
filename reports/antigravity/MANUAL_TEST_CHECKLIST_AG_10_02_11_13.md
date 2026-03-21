# Checklist Tests Manuels E2E — AG-10, AG-02, AG-11, AG-13

**Contexte :** Sprint 24 — Validation POS post-migrations (crudités atomiques, prix addons)  
**Date :** 2026-03-10  
**Agent :** Anti-Gravity ou humain  
**Serveur :** http://127.0.0.1:8000

---

## Prérequis

- [ ] Serveur Laravel démarré : `php artisan serve`
- [ ] Migrations exécutées : `php artisan migrate --force`
- [ ] Menu seedé : `php artisan db:seed --class=MenuSeeder`
- [ ] Build Vue à jour : `npm run dev`

**Credentials :**
- Admin : `admin@example.com` / `123456`
- Ou : `admin@lecayenne-henin-beaumont.fr` / `123456` (si configuré)

---

## AG-10 : Prix Menu, Frites, Boisson dans le wizard

**Objectif :** Vérifier que les addons Menu, Frites, Boisson affichent les bons prix (3€, 2€, 2€).

### Étape 1 — Ouvrir le POS et un item wizard

1. Se connecter en admin
2. Naviguer vers `/admin/pos`
3. Cliquer sur un item Tacos (ex. « Tacos L (2 Viandes) »)
4. Compléter le wizard jusqu’à l’étape **Formule / Menu** (étape 5)

### Étape 2 — Vérifier les prix affichés

| Addon | Prix attendu | Prix affiché | OK |
|-------|--------------|--------------|--------|
| Menu Complet | 3,00 € | _____ | ⬜ |
| Frites (seules) | 2,00 € | _____ | ⬜ |
| Boisson | 2,00 € | _____ | ⬜ |

**Assertion :**
- ✅ Menu Complet = 3,00 €
- ✅ Frites = 2,00 €
- ✅ Boisson = 2,00 €

**Résultat :** ⬜ PASS / ⬜ FAIL

---

## AG-02 : Garnitures atomiques (Salade, Tomate, Oignon)

**Objectif :** Les garnitures sont atomiques (Salade, Tomate, Oignon), pas de choix « Complet ».

### Étape 1 — Ouvrir le wizard d’un Tacos

1. POS → Cliquer sur « Tacos L (2 Viandes) »
2. Aller à l’étape **Garnitures** (étape 3)

### Étape 2 — Vérifier les options

| Option attendue | Visible | Pas de "Complet" |
|-----------------|---------|------------------|
| Salade | ⬜ | ⬜ |
| Tomate | ⬜ | ⬜ |
| Oignon | ⬜ | ⬜ |

**Assertion :**
- ✅ Salade, Tomate, Oignon présents (cases à cocher distinctes)
- ❌ Pas de choix unique « Complet » ou « Garnitures complètes »

**Résultat :** ⬜ PASS / ⬜ FAIL

---

## AG-11 : Paiement Cash → commande créée sans erreur 500

**Objectif :** Une commande payée en Cash est créée sans erreur serveur.

### Étape 1 — Créer une commande

1. POS → Ajouter un item simple (ex. Frites ou Boisson) ou un Tacos complet
2. Renseigner Token : `TEST-AG11`
3. Type : Takeaway ou Dine-in
4. Cliquer « Confirmer » / « Valider »

### Étape 2 — Payer en Cash

1. Sélectionner **Cash** comme mode de paiement
2. Valider le paiement

### Étape 3 — Vérifier

- [ ] Pas d’erreur 500
- [ ] Message de succès affiché
- [ ] Commande visible dans la liste des commandes

**Assertion :**
- ✅ HTTP 200 (pas 500)
- ✅ Commande créée et visible

**Résultat :** ⬜ PASS / ⬜ FAIL

---

## AG-13 : KDS reçoit la commande en temps réel (Pusher)

**Objectif :** Le Kitchen Display System reçoit la nouvelle commande via Pusher.

### Étape 1 — Préparer

1. Ouvrir un onglet : `/admin/kitchen-display-system`
2. Garder cet onglet visible

### Étape 2 — Créer une commande depuis le POS

1. Dans un autre onglet : `/admin/pos`
2. Créer une commande (item + token + type)
3. Payer (Cash ou autre)
4. Noter l’heure de validation

### Étape 3 — Vérifier le KDS

- [ ] La nouvelle commande apparaît sur le KDS sans rafraîchir
- [ ] Délai < 5 secondes

**Assertion :**
- ✅ Commande visible sur le KDS en temps réel (Pusher)
- ❌ Pas besoin de F5 pour voir la commande

**Note :** Si Pusher n’est pas configuré (`.env`), ce test peut échouer. Vérifier `PUSHER_APP_KEY`, etc.

**Résultat :** ⬜ PASS / ⬜ FAIL / ⬜ SKIP (Pusher non configuré)

---

## Grille de synthèse

| Test | Résultat | Notes |
|------|----------|-------|
| AG-10 | ⬜ | Prix Menu/Frites/Boisson |
| AG-02 | ⬜ | Garnitures atomiques |
| AG-11 | ⬜ | Paiement Cash |
| AG-13 | ⬜ | KDS temps réel |

---

## Rapport à produire

Après exécution, documenter dans `reports/antigravity/` en suivant le format de `workflows/report-format.md` :

- Chaque test : PASS/FAIL + captures si pertinent
- Fichier suggéré : `reports/antigravity/E2E_POS_AG_10_02_11_13_YYYYMMDD.md`
