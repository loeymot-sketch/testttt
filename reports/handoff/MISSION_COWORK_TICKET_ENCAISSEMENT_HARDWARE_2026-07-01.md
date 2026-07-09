# MISSION COWORK — Ticket d'encaissement + pavé numérique : installer sur le hardware

> Branche `pos/category-first-caisse-2026-06-23`, HEAD **`2b7c22879`**.
> Corrige 3 problèmes owner à l'encaissement des commandes borne en caisse. **Prérequis : redéployer.**

## Ce qui a été corrigé (code, poussé)
| Problème owner | Cause | Fix (`2b7c22879`) |
|---|---|---|
| **Ticket imprimé ≠ écran / sans détails** | `onCounterCollectConfirmed` n'imprimait **RIEN** (le bouton « Confirmer & Imprimer ticket » était mensonger). Ce que l'owner voyait imprimé venait d'un autre chemin (ancien / fallback). | À l'encaissement, on récupère les **octets ESC/POS du serveur** (endpoint `escpos`, **même rendu SSOT que l'aperçu écran**) et on les POST au **pont local `127.0.0.1:9100/raw`** → le SAGA imprime le **ticket CLIENT** correct. |
| **Pas d'impression du ticket client à l'encaissement** | idem | idem — le ticket client s'imprime maintenant automatiquement à l'encaissement. |
| **Le clavier Windows surgit quand on tape le montant** | l'input « montant reçu » était focusable (pas `readonly`) → toucher le champ ouvrait le clavier Windows. | input `readonly` + `inputmode="none"` → **plus de clavier Windows** ; saisie via le **pavé numérique de l'app** (déjà présent : 1-9, 0, 00, virgule, C, retour). |

**Preuve e2e (local)** : encaissement borne → `POST counter-collect/{id}/confirm` → `GET orders/{id}/escpos` → `POST pont/raw`. Modal : input `readonly`+`inputmode=none` + numpad 20 touches (capturé). 36 sentinelles vertes.

---

## ÉTAPE 1 — Déployer
```bash
ssh lecayenne && cd /var/www/lecayenne
sudo LECAYENNE_BRANCH=pos/category-first-caisse-2026-06-23 bash scripts/deploy/deploy.sh
php artisan config:clear && php artisan cache:clear && php artisan view:clear
git rev-parse --short HEAD   # doit == 2b7c22879 (ou +)
```
Sur les machines (caisse + borne) : **hard reload Chrome** (Ctrl+Shift+R) + désenregistrer les service workers (sinon ancien bundle).

## ÉTAPE 2 — CONFIG HARDWARE INDISPENSABLE (sinon le ticket ne s'imprime pas)
Le fix imprime via le **pont ESC/POS local**. Il faut donc, sur la machine caisse :
1. **Pont local actif** : `http://127.0.0.1:9100/health` → « UP » et accepte `POST /raw` (le cowork l'a déjà vu UP).
2. **Chrome lancé avec le flag** : `--disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks`
   (sinon Chrome bloque l'appel page-HTTPS → 127.0.0.1 → le ticket ne part pas au pont).
3. **`.env` VPS** : `PRINT_DRIVER=windows_raw` + imprimante déclarée (`php artisan pos:setup-receipt-printer "<NOM_SAGA>"`).
4. **Largeur** : `\App\Models\Printer::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->update(['width_chars'=>32]);` (58mm).

## ÉTAPE 3 — TESTS E2E RÉELS (sur la vraie caisse)
1. **Encaisser une commande borne en ESPÈCES** :
   - Cliquer « Encaisser » sur une commande borne → le modal s'ouvre.
   - ⭐ **Taper le montant sur le PAVÉ de l'app** (pas de clavier Windows qui surgit — vérifier).
   - « Confirmer & Imprimer ticket » → ✅ **le ticket CLIENT s'imprime** (resto, adresse, produits, TVA, total),
     **identique à l'aperçu écran** (format thermique 32 col, pas de coupure).
2. **Encaisser une commande borne par CARTE** : sélectionner Carte → Confirmer → ticket client imprimé.
3. **Comparer** : le ticket imprimé DOIT contenir les mêmes détails que l'aperçu écran caisse. Si le ticket
   sort vide/différent → le pont n'est pas joignable (étape 2.1/2.2) ou `width_chars` faux (étape 2.4).

## ÉTAPE 4 — À RAPPORTER (photos)
- Pavé numérique utilisé, **aucun clavier Windows** qui surgit.
- Ticket client imprimé à l'encaissement (espèces + carte) — photo du ticket physique.
- Ticket imprimé == détails de l'aperçu écran.

## STABILITÉ LONG-TERME
- **Règle d'or** (rappel) : jamais de patch manuel sur le VPS → toujours git → `deploy.sh` (rebuild complet).
- Le pont d'impression doit démarrer automatiquement (service/tâche planifiée) au boot de la caisse.
- Le flag Chrome doit être dans le raccourci de démarrage (sinon perdu au reboot).
- Après chaque future mise à jour : `deploy.sh` + hard reload Chrome. Rien d'autre à toucher.

> Si un jour le ticket ne s'imprime plus : 90% du temps = le **pont local est tombé** (relancer) ou le
> **flag Chrome manque** (raccourci). Le code, lui, envoie toujours les bons octets au pont.
