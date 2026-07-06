# FoodKing — Feuille de route de remédiation

> Partie 7/7 de l'audit forensique du 2026-07-06.
> Organisée par **priorité** et par **cause racine**. Effort : **S** (< 1 j), **M** (1-3 j), **L** (1-2 sem), **XL** (chantier). Chaque action référence les findings qu'elle neutralise.

## Principe directeur
Ne **pas** traiter les 216 findings un par un. Traiter les **5 causes racines** (rapport 03 §9) neutralise la majorité d'entre eux. La séquence ci-dessous est ordonnée pour **arrêter l'hémorragie d'abord** (P0), puis durcir structurellement (P1-P2), puis assainir (P3).

---

## 🔴 P0 — Urgent (arrêter la perte d'argent, les fuites et les secrets)
> Objectif : rendre le système **non catastrophique**. À faire avant tout déploiement.

| Action | Effort | Neutralise |
|---|:---:|---|
| **Révoquer/roter la clé de service GCP** côté Google ; supprimer `public/file/service-account-file.json` ; la relire depuis `storage/` ; purger l'historique Git | S | C31 |
| **Corriger la troncature Stripe** : `(int) round(((float)$order->total) * 100)` + gestion devises zéro-décimale + test 12,99 → 1299 | S | C24 |
| **Neutraliser l'Installer en prod** : middleware `abort(404)` si `storage/installed` existe, appliqué au groupe `/install`; remplacer `->send()` par une interruption réelle | S | C28, C29 |
| **Sortir la migration `emergency_purge`** du dossier `migrations/` (script one-shot hors `migrate`) ; interdire tout `TRUNCATE` non scopé | S | C17 |
| **`branch_id=0` ≠ admin** : baser la levée de `BranchScope` sur `hasRole('admin')`, jamais sur `branch_id==0` | M | C07, C12 |
| **Garde `role:admin` par défaut** sur le groupe `/api/admin`, puis affiner par permission | M | C09, + highs authz |
| **Vérifier le paiement côté PSP avant `PAID`** (retrieve/capture) ou webhook signé ; ne jamais dériver `payment_status` d'un champ client | M | C11, + highs paiement |
| **Retirer prix/remise/`delivery_charge`/identité du payload** sur endpoints table & kiosk ; recalcul serveur systématique, `min:0` | M | C01, C05-06, C10, C20-21, C26 |
| **Roter les identifiants** committés (borne `kiosk123`, admin `123456`, payloads) s'ils existent en prod ; `git rm` des payloads et résidus | S | secrets §05 |

## 🔴 P1 — Court terme (durcir la sécurité structurellement)
> Objectif : passer d'*opt-in* à *deny-by-default*.

| Action | Effort | Neutralise |
|---|:---:|---|
| **Token borne sur utilisateur de service dédié** (rôle `kiosk-machine`, zéro droit back-office, jamais `user_id=1`) | M | C15, C19, C27 |
| **Imposer les abilities Sanctum** : middleware `abilities:kiosk:order` sur les routes borne, **refus** des tokens kiosk sur `/api/admin` ; cesser d'émettre l'ability `*` | M | C15, + highs authz |
| **IDOR** : binder les endpoints publics sur un **token non énumérable** (déjà renvoyé à la création), jamais sur l'`id` entier ; vérifier `Auth::id()===$order->user_id` | M | C02, C08, C16 |
| **Autorisation des canaux broadcast** : lier à la borne authentifiée (id encodé dans le token), gater par rôle ; `0/null` = refus | S | C12, C30 |
| **Scellement fiscal structurel** : garde d'immutabilité au niveau **modèle** `Order` (hook `saving`/`updating`) refusant toute écriture sur commande scellée, hors avoir tracé | M | C03, C23 |
| **Fenêtre Z sans trou** : borne basse = `closed_at` du Z précédent ; interdire commande numérotée sans Z ouvert ; numéroter aussi kiosk/web/QR | M | C04, + highs db |
| **Atomicité argent** : `DB::transaction` + `lockForUpdate` sur `changeStatus`/annulations ; `UNIQUE(transactions.order_id,type)` ; throttle sur change-status | M | C25, + highs orders |
| **`branch_id=0` → vraie branche** pour les clients (ou colonne `is_customer`) ; scoper Transaction/Message via `order` | M | C22, + highs |

## 🟠 P2 — Moyen terme (fiabilité, synchronisation, portes qualité)

| Action | Effort | Neutralise |
|---|:---:|---|
| **Outbox atomique** : écrire l'outbox **dans la même transaction** que la mutation ; dispatcher après commit depuis la table, pas en best-effort | M | highs sync (`OrderService:1556`, `DispatchDomainEventsJob`) |
| **Resync WebSocket** à la reconnexion (rejouer depuis un curseur) + idempotence consommateur (dédup par `correlation_id`) | M | highs sync |
| **Contrat d'événements mono-sourcé** : importer et valider `eventContract.schema.json` côté front | S | sync |
| **Réparer la CI** : exécuter **Vitest** en CI (porte bloquante) ; corriger les assertions tolérantes (succès ET échec) ; remplir les tests d'invariants vacants (pricing SSOT, transitions, isolation) | L | highs tests |
| **KDS** : afficher les commandes POS chargées ; supprimer le `limit(50)` desc qui masque les anciennes ; unifier la source de branche | M | highs kds |
| **Durcir la remise POS** : dériver `discount` du store au submit ; resynchroniser à toute mutation de quantité ; recalcul serveur du palier d'autorisation | M | C13, highs pricing |
| **Ne pas exposer les erreurs brutes** : masquer `QueryException` ; retirer le token de `localStorage` (cookie httpOnly) | S | security/api |

## 🟢 P3 — Fond (dette, structure, socle)

| Action | Effort | Réf. |
|---|:---:|---|
| **Rangement du dépôt** : sortir reports/tasks/plans/_archive du produit, LFS/suppression des 227 Mo de binaires, renommer les dossiers à espaces, supprimer les `.docx` | L | rapport 02 |
| **Une seule implémentation kiosque** : désigner la source de vérité, archiver les 3 autres (dont le Flutter/Dart) hors dépôt | M | rapport 02 |
| **Corriger la documentation** : aligner `BUSINESS_RULES.md` / `AUTHZ_MATRIX.md` sur le code réel (aujourd'hui la doc est un piège) | M | rapport 03 (docsdrift) |
| **Migration socle** : PHP 8.1→8.3+ et Laravel 9→10→11 (+ Sanctum 4), en préprod, avec la CI durcie comme garde-fou | XL | rapport 01 |
| **Build** : laravel-mix → Vite ; trancher Tailwind vs Bootstrap ; ne plus committer les bundles compilés | L | rapport 01/02 |
| **Vuex → Pinia** (optionnel, à terme) | L | rapport 01 |

---

## Séquencement recommandé

```
Semaine 0  ██████ P0  → clé GCP, Stripe, Installer, purge, branch_id≠admin, PSP, payloads
Semaine 1-2 ████████ P1  → token borne, abilities, IDOR, broadcast, sceau modèle, atomicité
Semaine 3-5 ████████ P2  → outbox atomique, resync, CI (Vitest + invariants), KDS, remise POS
Semaine 6+  ██████████ P3  → rangement dépôt, 1 kiosque, docs, migration socle, build
```

## Critères de sortie du verdict `block`
Le verdict global ne devrait passer de `block` à `heal` que lorsque **tous les P0 sont livrés et prouvés par un test** (pas seulement corrigés), et qu'un **test d'invariant réel** (non vacant) couvre : (1) le backend recalcule tout prix, (2) aucune lecture cross-branch possible pour un client, (3) une commande scellée est immuable, (4) un paiement exige une preuve PSP. Tant que ces 4 tests n'existent pas et ne passent pas, considérer le socle comme non sûr.

---

*Les efforts sont indicatifs (analyse statique). Prioriser P0 dans l'ordre du tableau : chaque ligne P0 arrête une perte d'argent, une fuite ou une prise de contrôle active.*
