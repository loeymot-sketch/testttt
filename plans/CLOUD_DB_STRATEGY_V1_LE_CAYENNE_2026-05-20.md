# 🌐 Stratégie Base de Données Cloud — V1 Le Cayenne
**Date**: 2026-05-20 · **Question owner**: "Comment notre BDD sera-t-elle déployée en cloud ? On utilise un service comme Supabase, ou quoi ?"
**Réponse de Claude** (pas d'action — déclenchement cloud par owner seulement, per memory `feedback_no_cloud_until_owner_initiates.md`)

## TL;DR — ma recommandation en 30 secondes

| Composant | Recommandation | Pourquoi |
|---|---|---|
| **Base de données primaire** | **MySQL 8.0 managed** chez fournisseur EU (Scaleway / OVH / AWS RDS Paris) | Notre code est 100% MySQL natif + NF525 + GDPR exigent EU |
| **PAS Supabase / PAS PostgreSQL** | ❌ | Migration MySQL→PostgreSQL = 3-6 semaines refactor + risque NF525 fiscal HMAC + triggers BEFORE DELETE |
| **WebSocket production** | **Pusher Cloud** ou **Ably** (managed) | Pas auto-héberger Soketi en prod — overhead ops trop élevé pour 1 resto V1 |
| **Stockage fichiers** | S3-compatible (Scaleway Object Storage / OVH Object Storage / AWS S3) | Reçus images, Z-reports archivés, backup quotidien |
| **Backup + rétention 6 ans** | Snapshot DB quotidien + cold storage (S3 Glacier) | NF525 mandatory 6 ans |
| **Architecture V1** | 1 MySQL primary, single-region (eu-west-3 Paris) | Le Cayenne = 1 resto, pas besoin de multi-region |
| **Architecture V2 SaaS multi-resto** | Primary + read-replicas per branch (later) | Pas pour V1 |

---

## Pourquoi PAS Supabase

Supabase est excellent pour des stacks JavaScript + PostgreSQL + Auth simple. **Ce n'est PAS le bon choix pour FoodKing V1** :

### Risques techniques

| Risque | Sévérité |
|---|---|
| **Migration MySQL → PostgreSQL** | 🔴 HIGH — 3-6 semaines refactor full DAL |
| **NF525 HMAC chain + triggers BEFORE DELETE** | 🔴 HIGH — Postgres SQL différent de MySQL pour triggers, RAISE EXCEPTION ≠ SIGNAL SQLSTATE |
| **Spatie Permissions sur PostgreSQL** | 🟡 MED — supporté mais pas testé sur notre code base |
| **`withTrashed()` + soft-delete** | 🟡 MED — semantics diffèrent |
| **Eloquent migrations diff PostgreSQL** | 🟡 MED — `enum` colonne handled différemment |
| **Cache::lock + lockForUpdate** | 🟡 MED — semantics PostgreSQL diffèrent ; nos triple-defense patterns testés sur MySQL |
| **Toutes nos sentinelles tests (3000+)** | 🟡 MED — devraient passer mais nécessitent rerun complet en PostgreSQL |

### Risques business

- **Verrouillage fournisseur** : Supabase = compagnie startup, pas autant garantie long-terme que AWS/Scaleway/OVH
- **Auth Supabase vs Sanctum** : on est sur Sanctum + Spatie permissions ; rebrancher sur Supabase Auth = inutile + perte d'audit log NF525
- **Cher pour usage simple** : Supabase facturé par row + storage ; pour 1 resto, surcoût vs managed MySQL

### Risque légal NF525

- NF525 = loi fiscale française. **Données fiscales doivent rester en EU** (RGPD + souveraineté française recommandée)
- Supabase déployé en US (Virginia par défaut) — il y a une option EU mais l'écosystème reste US-centric
- Choisir un fournisseur **français ou EU-natif** (Scaleway / OVH) réduit le risque de litige juridique en cas d'inspection fiscale

---

## Pourquoi MySQL Managed EU

### Conformité

| Critère | MySQL Managed EU |
|---|---|
| Code Laravel compatible 1:1 | ✅ |
| NF525 HMAC chain SQL works as-is | ✅ |
| Triggers BEFORE DELETE/UPDATE | ✅ |
| 6-year retention via snapshot + cold storage | ✅ |
| RGPD data residency EU | ✅ |
| Audit log structure | ✅ |
| Spatie Permissions | ✅ |
| BranchScope multi-tenant | ✅ |
| Soft deletes + withTrashed() | ✅ |
| Existing 3000+ tests | ✅ pass as-is |

### 3 options recommandées (du moins au plus cher)

| Provider | Service | Prix indicatif V1 | Avantages | Inconvénients |
|---|---|---|---|---|
| **Scaleway Database MySQL** | Production node | ~30-50 €/mois | French provider, GDPR-native, simple | Moins d'options HA que AWS |
| **OVHcloud Public Cloud DB** | MySQL managed | ~40-60 €/mois | French provider, support FR | UI moins moderne |
| **AWS RDS MySQL Paris** | db.t3.small + multi-AZ | ~50-80 €/mois | Standard industrie, écosystème vaste | Surdimensionné pour V1 |

**Ma préférence pour V1 Le Cayenne** : **Scaleway Database MySQL Production-1**
- Hébergé en France (Paris ou Amsterdam)
- ~30 €/mois pour V1 single-resto
- Snapshot quotidien automatique inclus
- Easy scale-up quand V2 SaaS arrive
- Support en français

---

## Architecture cloud V1 détaillée

```
┌────────────────────────────────────────────────────────────────┐
│  Le Cayenne — V1 Cloud Architecture (single-resto)             │
│                                                                 │
│  ┌──────────────────────────────────────────────────┐          │
│  │ Compute (Laravel app)                            │          │
│  │ ┌─────────────────┐  ┌─────────────────┐         │          │
│  │ │ Scaleway VPS    │  │ Scaleway VPS    │         │          │
│  │ │ App + queue worker (load-balanced)   │         │          │
│  │ └─────────────────┘  └─────────────────┘         │          │
│  │ Ou: AWS EC2 + ALB / OVH Public Cloud Instance    │          │
│  └──────────────────────────────────────────────────┘          │
│                          │                                      │
│           ┌──────────────┼──────────────┐                       │
│           ▼              ▼              ▼                       │
│  ┌──────────────┐ ┌──────────────┐ ┌──────────────┐            │
│  │ MySQL 8.0    │ │ Pusher Cloud │ │ Object Stor. │            │
│  │ Managed      │ │ ou Ably      │ │ S3 / SCW OS  │            │
│  │ + snapshot   │ │ (WebSocket)  │ │ (receipts +  │            │
│  │ daily        │ │              │ │ Z-archive)   │            │
│  │ + 6Y archive │ │              │ │              │            │
│  └──────────────┘ └──────────────┘ └──────────────┘            │
│                                                                 │
│  Tous les surfaces (POS, Kiosk, KDS, OSS, Admin) connectent    │
│  au même backend → MySQL est le SSOT.                          │
└────────────────────────────────────────────────────────────────┘
```

## Comment la synchronisation fonctionne en cloud

**MySQL est le SSOT** (Source Of Truth). Tous les systèmes lisent et écrivent dans la même DB.

**Synchronisation temps réel** = Pusher/Ably WebSocket + polling fallback :
- POS commit order → DB persist → Event dispatched → Outbox listener → broadcast via Pusher
- Pusher diffuse à KDS + OSS + Kiosk (selon channels)
- Si Pusher down → polling fallback (KDS poll every 60s)

**Synchronisation entre surfaces** :
- POS écrit → KDS lit (via broadcast OR poll)
- Kiosk écrit → KDS lit (via broadcast OR poll)
- KDS update status → POS + OSS lisent (via broadcast OR poll)
- Admin lit tout (BranchScope bypass pour branch_id=0)

**Synchronisation entre branches** (V2 SaaS, pas V1) :
- BranchScope global scope sur 21 models
- Branch A ne voit jamais Branch B
- Admin global voit tout
- Pour V1 single-resto Le Cayenne : 1 seule branche, scope est silencieux

---

## Quand déclencher la migration cloud

**PAS MAINTENANT** — per memory `feedback_no_cloud_until_owner_initiates.md` :
> "Owner archivé cloud/AWS/VPS/Phase D comme 'vision avant production'"

**Quand toi (owner) dis 'go production' avec ces critères atteints** :
- ✅ Tests manuels owner = green (5 problèmes Wave O fixés + autres trouvés)
- ✅ Vraies données métier saisies (menu Le Cayenne final, prix réels, TVA correctement configuré)
- ✅ Vraies clés Stripe (test → live)
- ✅ Vraies clés SenangPay (si pertinent — c'est plus Maroc/Tunisie ; pour France peut-être skip)
- ✅ Vraies coordonnées légales (SIREN, SIRET, TVA intra) — G-WEB-LEGAL-1
- ✅ TrustHosts whitelist domaine de production
- ✅ Certificat NF525 final dispo (ou en cours d'obtention)
- ✅ Décision sur fournisseur (Scaleway / OVH / AWS) et provisioning

---

## Mes 3 prochaines étapes recommandées quand tu dis "go cloud"

1. **Choix fournisseur** : tu décides Scaleway / OVH / AWS (ma recommandation : **Scaleway** pour ratio simplicité/prix/conformité)
2. **Provisioning DB managed** : créer instance + restore depuis dump local
3. **Provisioning compute** : VPS app + worker queue
4. **Pusher Cloud account** : créer + récupérer keys
5. **Object Storage** : créer bucket
6. **DNS + SSL** : pointer ton domaine sur le LB
7. **Migration** : `php artisan migrate` sur la prod DB managed
8. **Données initiales** : seeder Le Cayenne (menu + branche + admin user)
9. **Production smoke test** : owner manual test sur prod

---

## Récap question owner

**Q: Quel service utiliser ? Supabase ?**
**R: Non Supabase. MySQL managed chez fournisseur EU (recommandation: Scaleway). Notre stack est MySQL natif + NF525 + GDPR. Supabase = migration PostgreSQL = 3-6 semaines de risque inutile.**

**Q: Comment la synchro fonctionne en cloud ?**
**R: Identique à local. MySQL = SSOT. Pusher Cloud (ou Ably) = WebSocket temps réel. Polling fallback = redondance. Outbox = livraison garantie même si Pusher down.**

**Q: Quand déclencher la migration ?**
**R: Quand TU dis go production avec tests manuels green + vraies clés/données métier prêtes. Pas avant. Aucune action cloud tant que tu n'as pas explicitement déclenché.**

---

**Doc archivé pour référence cloud-deploy phase.**
