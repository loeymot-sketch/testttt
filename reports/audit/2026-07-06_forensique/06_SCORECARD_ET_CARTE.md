# FoodKing — Scorecard & carte des systèmes

> Partie 6/7 de l'audit forensique du 2026-07-06.
> Scores = moyenne des lentilles (logique / sécurité / archi-sync) par système, barème 0-10 sévère. Verdict selon la doctrine `CLAUDE.md §8`.

## 1. Scorecard des 13 systèmes

| Système | Score | Verdict | État |
|---|:---:|:---:|---|
| **authz** — Isolation branche & habilitations | **2.5** | 🔴 block | `branch_id=0` = admin, scope opt-in, tokens `*` |
| **pricing** — Prix & règles métier | **3.0** | 🔴 block | Client dicte remise/frais sur endpoints non auth |
| **api** — Surface API & validation | **3.0** | 🔴 block | IDOR, endpoints admin sans permission |
| **security** — Sécurité & secrets | **3.0** | 🔴 block | Clé GCP publique, Installer, x-api-key statique |
| **kiosk** — Kiosque / borne | **3.2** | 🔴 block | Token = admin id=1, paiement sur commande rejetée |
| **structure** — Structure du dépôt | **3.2** | 🟠 heal | 4 impl. kiosque, 227 Mo binaires, migration purge |
| **db** — Base de données & intégrité | **3.7** | 🔴 block | Trous fenêtre Z, scope 5 modèles, softdelete court-circuité |
| **docsdrift** — Cohérence docs↔code | **3.7** | 🔴 block | La doc affirme l'inverse du code (piège actif) |
| **orders** — Commandes & fiscal | **3.8** | 🔴 block | Scellement Z incomplet, override terminal, best-effort |
| **posadmin** — Frontend POS/Admin | **4.5** | 🟠 heal | Remise fantôme, token en localStorage, cart non scopé |
| **tests** — Tests & CI | **4.5** | 🟠 heal | Vitest hors CI, tests d'invariants vacants, asserts tolérants |
| **sync** — Synchronisation & temps réel | **4.7** | 🔴 block | Outbox non atomique, perte silencieuse d'events, pas de resync |
| **kds** — KDS / OSS / tables | **4.7** | 🔴 block | Commandes POS invisibles, `limit(50)`, cross-branch |

**Score global pondéré ≈ 3.5 / 10 — Verdict global : `block`.**
10 systèmes sur 13 en `block`, 3 en `heal`, **0 en `continue`**. Aucun système n'est sain en l'état.

---

## 2. Carte des systèmes & graphe de dépendances

Les 13 systèmes forment un graphe dense centré sur **orders** (le hub) et **authz** (la garde transverse). Les invariants violés traversent ces arêtes — c'est pourquoi un défaut d'`authz` ou de `pricing` se propage à presque tout.

```
                         ┌─────────────┐
              ┌──────────►│   ORDERS    │◄───────────┐
              │          └──┬───┬───┬──┘             │
              │             │   │   │                │
       ┌──────┴────┐   ┌────▼┐ ┌▼──────┐   ┌─────────┴──┐
       │  PRICING  │◄─►│ DB  │ │PAYMENTS│  │    KDS     │
       └──────┬────┘   └──┬──┘ └───┬────┘   └─────┬──────┘
              │           │        │              │
              ▼           ▼        ▼              ▼
       ┌──────────────────────────────────────────────┐
       │                   AUTHZ                        │  ← garde transverse
       │        (branch_id, Sanctum, spatie)           │     (défaillante)
       └───┬──────────┬──────────┬───────────┬─────────┘
           │          │          │           │
      ┌────▼───┐ ┌────▼───┐ ┌────▼────┐ ┌───▼──────┐
      │  API   │ │ KIOSK  │ │  SYNC   │ │ SECURITY │
      └────────┘ └────┬───┘ └────┬────┘ └────┬─────┘
                      │          │           │
                 ┌────▼───┐ ┌────▼─────┐ ┌───▼────────┐
                 │POSADMIN│ │NOTIF/FCM │ │ INSTALLER  │
                 └────────┘ └──────────┘ └────────────┘

  Support transverse : TESTS · STRUCTURE · DOCSDRIFT (touchent tous les nœuds)
```

**Dépendances déclarées par les auditeurs (arêtes sortantes principales)** :
- `orders` → pricing, payments, kiosk, authz, db, notifications *(le hub)*
- `authz` → orders, kiosk, api, security, db *(la garde de tout)*
- `pricing` → orders, authz, db, payments, security
- `kiosk` → authz, orders, pricing, payments, api, security, sync *(le plus couplé)*
- `sync` → orders, kiosk, kds, notifications, authz
- `db` → orders, pricing, payments, sync, security, structure
- `security` → orders, authz, api, pricing, payments, notifications, installer

> Lecture : **`authz` et `pricing` sont des points d'articulation**. Leurs défauts (branch_id=0, remise client) ne sont pas locaux — ils se propagent à orders → kds → sync → posadmin. C'est la justification structurelle de traiter les 5 causes racines (rapport 03 §9) **avant** les findings isolés.

---

## 3. Fiches système (rôle · point faible · dépendances)

**orders** *(hub commandes+fiscal)* — Machine d'états, séquence fiscale, Z-report, audit HMAC. Point faible : le sceau Z ne couvre que `destroy()`. Dépend de pricing/payments/db/authz.

**pricing** *(SSOT prix)* — `PricingService`/`DiscountCalculator`, coupons, TVA, frais. Point faible : le SSOT est recalculé puis **court-circuité** selon la surface ; les endpoints non auth honorent les montants client.

**authz** *(garde transverse)* — `BranchScope`, Sanctum, spatie. Point faible : `branch_id=0` surchargé (client = admin), abilities non imposées, `role:admin` absent du groupe admin.

**api** *(surface HTTP)* — `routes/api.php` (1008 l.), FormRequests, contrôleurs. Point faible : IDOR par binding entier sur endpoints publics, permissions déléguées ad hoc aux services (oubliées).

**security** *(transverse)* — Secrets, Installer, CORS, headers. Point faible : clé GCP publique, Installer atteignable, clé API statique non constant-time.

**sync** *(temps réel)* — Outbox, `DispatchDomainEventsJob`, soketi/Echo, contrat d'events. Point faible : dispatch **après** commit dans un `try/catch` avalant l'erreur, pas de resync WebSocket, contrat non mono-sourcé.

**posadmin** *(front POS/admin)* — Vuex, composants admin, impression. Point faible : remise « fantôme » périmée envoyée au backend, token en `localStorage`, `posCart` non scopé.

**kiosk** *(borne)* — `kiosk*.js`, auth machine, snapshot menu. Point faible : token = admin id=1, paiement encaissé sur commande rejetée, **triple/quadruple implémentation**.

**kds** *(cuisine/OSS/tables)* — `KitchenDisplaySystemOrderService`, écrans cuisine. Point faible : commandes POS chargées mais jamais affichées, `limit(50)` desc masque les anciennes, change-status cross-branch.

**db** *(intégrité)* — 105 migrations, modèles, softdeletes. Point faible : fenêtres d'agrégation Z trouées, `BranchScope` sur 5 modèles, softdelete court-circuité dans le Z, drift sqlite/MySQL non testé.

**tests** *(portes qualité)* — PHPUnit/Vitest/Playwright, CI. Point faible : **Vitest jamais lancé en CI**, tests d'invariants vacants, assertions acceptant succès ET échec (fausse assurance).

**structure** *(hygiène)* — Arbre du dépôt, build. Point faible : 4 impl. kiosque, 227 Mo binaires sans LFS, bundles committés servis sans build, migration destructrice. → détail rapport 02.

**docsdrift** *(cohérence)* — Confrontation docs↔code. Point faible : `BUSINESS_RULES.md` et `AUTHZ_MATRIX.md` **affirment l'inverse du code** (disponibilité « non implémentée » alors qu'elle existe ; permission `pos-apply-discount` fantôme) → la doc est un **piège actif**.

---

## 4. Forces réelles à préserver

Malgré les scores, l'audit a relevé de vraies fondations :
- ✅ **Intention architecturale forte** : outbox, machine d'états dédiée (`OrderStateMachine`), séquence fiscale, audit HMAC, `BranchScope`, contrat d'événements — les bons *patterns* sont **présents**. Le problème est leur **application incomplète**, pas leur absence.
- ✅ **`PricingService` centralisé** existe et recalcule (le défaut est qu'on le contourne, pas qu'il manque).
- ✅ **Documentation abondante** dans `docs/` (même si sa fiabilité est à corriger).
- ✅ **Ossature Laravel/Vue propre** et modulaire.
- ✅ **Nombreux tests** existent (le défaut est la CI et la qualité des assertions, pas le volume).

> Conclusion : le projet n'est pas à réécrire — il est à **durcir**. Les *patterns* de sécurité/fiscalité sont là mais *opt-in* ; les rendre *deny-by-default* et *structurels* (contraintes DB, gardes de modèle, middleware par défaut) neutralise la majorité des findings.
