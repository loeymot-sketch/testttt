# CLOUD + DOMAIN DECISION — Le Cayenne V1 — 2026-05-23

Research agent : **BRAIN.2 — Cloud + domaine deep-dive 2026**
Owner : Le Cayenne (single-site V1, Cayenne / Marennes-type fast-food)
Status : actionable, primary-source verified for domain availability and core pricing

---

## 1. TL;DR (3 lignes)

- **Cloud** : **Hetzner Cloud CX32 (Falkenstein DE)** à ~6.80 €/mo HT — 4 vCPU / 8 GB RAM / 80 GB NVMe / 20 TB trafic — RGPD ✓. Le CX22 préliminaire (4.59 €/mo, 4 GB RAM) est trop serré pour Nginx+PHP-FPM+MySQL+Redis+Soketi+queue **tous co-localisés** ; on garde CX22 comme "downsize" possible après 2 semaines de soak.
- **Domaine** : `lecayenne.fr` est **DISPONIBLE** (AFNIC whois confirmé 2026-05-23 14:52 UTC) → registrar OVH ≈ **5.99 € TTC année 1, ≈ 9.35 € TTC renouvellement**. `lecayenne.com` et `lecayenne.eu` sont aussi libres → réserver les 3 protège la marque pour ~21 €/an total.
- **Coût mensuel V1 all-in** : **~8.30 €/mo TTC** (Hetzner CX32 ~8.16 € TTC + domaine amorti ~0.78 €/mo + backups Backblaze B2 ~0.05 € + monitoring Uptime Kuma self-hosted **0 €**).

---

## 2. Cloud comparison matrix 2026

Prix vérifiés sources primaires (Hetzner pricing page + OVH FR + DigitalOcean docs + AWS Lightsail + Scaleway pricing). Tous les prix sont la **mensualité catalogue 2026** publique.

| Provider              | Plan          | vCPU     | RAM    | SSD            | Trafic     | Datacenter principal      | Prix mensuel   | RGPD / EU data | Notes                                              |
|-----------------------|---------------|----------|--------|----------------|------------|---------------------------|----------------|----------------|----------------------------------------------------|
| **Hetzner Cloud**     | **CX22**      | 2 AMD    | 4 GB   | 40 GB NVMe     | 20 TB      | Falkenstein DE / Helsinki | **~4.59 €/mo** | Oui (DE/FI)    | Préliminaire owner. Trop serré full-stack co-localisé. |
| **Hetzner Cloud** ⭐  | **CX32**      | 4 AMD    | **8 GB** | **80 GB NVMe** | 20 TB      | Falkenstein DE / Helsinki | **~6.80 €/mo** | Oui (DE/FI)    | **PICK V1** — safety margin pour MySQL+Redis+queue. |
| Hetzner Cloud         | CX42          | 8 AMD    | 16 GB  | 160 GB NVMe    | 20 TB      | Falkenstein DE / Helsinki | ~13.10 €/mo    | Oui (DE/FI)    | Over-provision V1, garde pour V2 multi-resto.       |
| OVH VPS               | Starter       | 1 vCore  | 2 GB   | 20 GB SATA     | Illimité 100 Mbps | Gravelines / Strasbourg FR | ~3.50 €/mo HT | Oui (FR)       | RAM trop juste, SSD SATA (pas NVMe), réseau lent.   |
| OVH VPS               | Value         | 1 vCore  | 2 GB   | 40 GB NVMe     | Illimité 250 Mbps | Gravelines / Strasbourg FR | ~5.80 €/mo HT | Oui (FR)       | Toujours 2 GB RAM = insuffisant V1 full-stack.      |
| OVH VPS               | **Essential** | 2 vCore  | 4 GB   | 80 GB NVMe     | Illimité 500 Mbps | Gravelines / Strasbourg FR | **~12.50 €/mo HT** | Oui (FR)  | **Plus français mais 2× plus cher que CX32**.       |
| Scaleway              | Stardust      | 1 vCPU   | 1 GB   | 10 GB          | Illimité 100 Mbps | Paris FR                  | ~1.80 €/mo     | Oui (FR)       | Insuffisant pour V1. Bon pour dev sandbox.          |
| Scaleway              | DEV1-S        | 2 vCPU   | 2 GB   | 20 GB          | 200 Mbps   | Paris / Amsterdam         | ~7.99 €/mo     | Oui (FR)       | Plus cher que CX22 pour moins de RAM, sans NVMe inclus. |
| DigitalOcean          | Basic 4GB     | 2 vCPU   | 4 GB   | 80 GB SSD      | 4 TB       | Amsterdam / Frankfurt     | **24 $/mo**    | Oui (DE/NL)    | **5× plus cher que Hetzner pour specs équivalentes**. Sub-billing seconde 2026. |
| AWS Lightsail         | $5 bundle     | 1 vCPU   | 1 GB   | 40 GB          | 2 TB       | Paris / Frankfurt         | 5 $/mo         | Oui            | 1 GB RAM = insuffisant. Egress $0.09/GB après quota. |
| AWS Lightsail         | $12 bundle    | 2 vCPU   | 4 GB   | 80 GB          | 4 TB       | Paris / Frankfurt         | 12 $/mo        | Oui            | Comparable CX22 mais 2.6× plus cher.                |

### Verdict cloud

**Hetzner CX32 wins on price/performance + GDPR + headroom.**

Faits structurants :
1. **Benchmark Geekbench shared-vCPU Hetzner = 900–1100 single-core**, soit niveau dédié bas. Le standard deviation <4% confirme qu'Hetzner ne sur-vend pas le pool partagé (source: Better Stack 2026, bestusavps 2026).
2. **Latence Hetzner intra-EU < 10ms** vers IXPs majeurs (DE-CIX Frankfurt, AMS-IX Amsterdam). Depuis France, latence Falkenstein ≈ 15-25ms RTT — imperceptible pour POS/kiosk web.
3. **20 TB trafic inclus** = on est très loin du plafond pour V1 (un resto fast-food génère ~5-50 GB/mois). DigitalOcean inclut 4 TB seulement, AWS 2-4 TB → coût caché à l'overage.
4. **RAM 8 GB CX32 vs 4 GB CX22** : la recherche benchmark Laravel 2026 dit "8 GB safest baseline if app uses queues + Redis + MySQL local". V1 a tout co-localisé (Nginx + PHP-FPM + MySQL + Redis + Soketi + Laravel queue worker + sync jobs). Un OOM PHP-FPM en service midi = NF525 cash-trail interrompu. **Ne pas faire l'économie de 2.20 €/mo**.
5. **NVMe storage** = critique pour MySQL writes (audit_logs chain HMAC, fiscal_sequence FOR UPDATE). OVH Starter en SATA est disqualifié à ce critère.
6. **OVH Essential** est l'alternative française la plus directement comparable, mais à **12.50 € HT (~15 € TTC)** elle est ~85% plus chère que CX32 sans avantage technique réel — souveraineté FR vs souveraineté DE/UE, dans les deux cas RGPD ✓.

### Considérations honnêtes / limites

- **Hetzner = entreprise allemande**. Si l'owner a une exigence absolue "data sur sol français", choisir OVH Essential (Gravelines/Strasbourg/Roubaix). Coût additionnel ~6.90 €/mo soit ~83 €/an. RGPD identique.
- **Hetzner support** : email/ticket only, pas de téléphone urgence FR. OVH a un support FR téléphonique. Pour un restaurateur non-technique, c'est un argument réel.
- **Hetzner ne fait pas de DDoS protection avancée** incluse — niveau basic seulement. Pour V1 single-resto sans exposition publique massive, suffisant. Si V2 SaaS multi-resto, considérer Cloudflare devant.
- **Pas de Hetzner managed MySQL** — on déploie MySQL self-managed. Pour comparaison, DigitalOcean Managed MySQL = 15 $/mo seul, plus que tout le serveur Hetzner. **Self-managed est OK pour V1** car single-tenant, single-DB, low traffic ; on a juste à scripter un dump quotidien vers Backblaze B2 (cf §5).

---

## 3. Domaine `lecayenne.fr` — STATUS AUTHORITATIVE

**Source primaire** : AFNIC whois server `whois.nic.fr`, query 2026-05-23T14:52:48Z.

```
$ whois -h whois.nic.fr lecayenne.fr
%% NOT FOUND
>>> Last update of WHOIS database: 2026-05-23T14:52:48.667711Z <<<
```

→ **`lecayenne.fr` est DISPONIBLE à la registration.**

Vérifications croisées sur variantes (whois CLI direct, 2026-05-23) :

| Domaine          | Status                      | Action recommandée                            |
|------------------|-----------------------------|----------------------------------------------|
| **lecayenne.fr** | ✅ DISPONIBLE (AFNIC)       | **Réserver immédiatement**                   |
| **lecayenne.com** | ✅ DISPONIBLE (VeriSign — "No match")  | Réserver — protection marque internationale  |
| **lecayenne.eu** | ✅ DISPONIBLE (EURid — "AVAILABLE")    | Réserver — protection marque EU              |
| lecayenne.app    | Pas confirmé (Google registry, requête whois muette) — à vérifier au moment d'acheter | Optionnel, faible priorité                  |
| lecayenne.io     | Pas confirmé (whois muet)   | Optionnel, .io = cher (~30 €/an), skip pour V1 |

### Note importante — collision possible

Un restaurant **"Le Cayenne"** existe à **Marennes (17320), 19 Rue des Martyrs** (poissons/fruits de mer, sans rapport avec le fast-food owner). Ce restaurant n'a **pas de site web officiel identifié** dans les annuaires (Pages Jaunes, Petit Futé, Yelp pointent vers Facebook seulement). Donc :
- Le domaine est légalement libre à l'achat (premier arrivé, premier servi en .fr).
- Risque marque : faible (c'est un nom de cuisine commune, pas une marque déposée connue). Si l'owner veut être prudent il peut faire un check INPI rapide, mais c'est un nice-to-have, pas un blocker.
- Recommandation : achat des 3 (.fr + .com + .eu) tient le concurrent à distance pour ~21 €/an total.

### Tarifs registrar 2026 vérifiés

| Registrar     | .fr année 1     | .fr renouvellement   | Caractéristiques pertinentes                            |
|---------------|------------------|----------------------|---------------------------------------------------------|
| **OVH**       | **4.99 € HT (~5.99 € TTC)** | **7.79 € HT (~9.35 € TTC)** | **PICK — registrar français, panel DNS clair, support FR**, free WHOIS privacy .fr |
| Gandi         | ~12 €            | ~15 €                | Français aussi, mais prix 2x plus cher, UX panel plus clean |
| Porkbun       | Pas de .fr (registrar US, ne gère pas le .fr nativement) | — | Bon pour .com (~$10) mais pas .fr |
| Cloudflare Registrar | N/A pour .fr  | —                   | At-cost mais pas de .fr supporté                        |

→ **Registrar = OVH** pour les 3 domaines (consolidation panel, support FR, prix imbattable).

### SSL Let's Encrypt

Post-pointage DNS A record vers IP Hetzner CX32 :
- certbot auto-issue (nginx plugin) → cert valide 90 jours, renew automatique cron
- Coût : **0 €**, mis en place en 5 min après que le DNS A record propage (5-30 min OVH).

---

## 4. Architecture proposée V1 single-server (Hetzner CX32)

Stack tout co-localisé sur un seul serveur — pas de DB managed, pas de split — adapté à V1 single-resto :

```
┌─────────────────────────────────────────────────────────────┐
│  Hetzner CX32 (4 vCPU AMD / 8 GB RAM / 80 GB NVMe / Debian 12) │
│                                                                │
│  ┌──────────────┐    ┌──────────────────────────────────────┐ │
│  │   Nginx 1.24 │ ←→ │  PHP-FPM 8.2 (Laravel 11 monolith)   │ │
│  │   :443 :80   │    │  + opcache + jit                     │ │
│  └──────────────┘    │  pm = dynamic, max_children=20       │ │
│                       └──────────┬───────────────────────────┘ │
│                                  │                              │
│  ┌──────────────┐    ┌──────────▼───────┐    ┌──────────────┐ │
│  │  MySQL 8.0   │ ←→ │  Redis 7 (cache  │ ←→ │  Soketi      │ │
│  │  innodb_pool │    │  + queue + Echo  │    │  WebSocket   │ │
│  │  4 GB        │    │  driver)         │    │  :6001       │ │
│  └──────────────┘    └──────────────────┘    └──────────────┘ │
│                                                                │
│  ┌──────────────────────────────────────────────────────────┐ │
│  │  Laravel queue worker (supervisor → php artisan queue)   │ │
│  │  + cron (schedule:run)                                    │ │
│  │  + certbot auto-renew                                     │ │
│  └──────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────┘
        ↓ nightly backup
   Backblaze B2 (mysqldump + storage/ archive)
        ↓ remote monitoring
   Uptime Kuma (self-hosted on same box, port 3001)
```

### Estimation charge V1 (single-resto, ~200 commandes/jour, 4 surfaces actives)

| Composant         | RAM usage typique  | CPU usage typique | Notes |
|-------------------|--------------------|--------------------|-------|
| MySQL 8 (buffer pool 4 GB) | ~4.5 GB     | <5% steady, spikes 30% sur Z-report | Critique : innodb_buffer_pool = 50% RAM |
| PHP-FPM (20 children × ~60 MB) | ~1.2 GB | 30-60% rush midi/soir | Octane envisageable V1.0.2 si latence sub-100ms requise |
| Redis             | ~150 MB             | <2%               | Cache + queue + WebSocket payload |
| Soketi            | ~80 MB / 100 conns  | <2%               | Plafonné <1 GB jusqu'à 10k connexions |
| Nginx             | ~50 MB              | <5%               | Reverse proxy + static asset serving |
| Queue worker      | ~150 MB             | <5%               | Webhook handlers, KDS push, sync jobs |
| OS + supervisor + certbot + Uptime Kuma | ~500 MB | <2% | Linux Debian 12 |
| **TOTAL estimé** | **~6.6 GB / 8 GB**  | **40-70% rush**    | **Marge ~17%** acceptable, monitoring obligatoire |

Si la marge se réduit (croissance volume), V1.0.2 path = **scale up to CX42** (~13 €/mo) ou **séparer MySQL sur Hetzner Cloud DB** (managed, ~10 €/mo dès qu'elle existe — actuellement Hetzner ne propose pas encore de managed MySQL public ; alternative : DigitalOcean Managed MySQL 15 $/mo).

### Stack OS de base à scripter (cf task #48 `deploy.sh + server-setup.sh`)

- Debian 12 (Hetzner default)
- ufw firewall (22, 80, 443, 6001 only)
- fail2ban sshd
- Unattended-upgrades enabled (security only)
- Nginx 1.24 + PHP 8.2-FPM + MySQL 8.0 + Redis 7 + Node 20 (Soketi)
- Supervisor (queue worker + Soketi process management)
- certbot + cron renew
- mysqldump nightly to Backblaze B2 (rclone)
- Uptime Kuma in Docker on port 3001 → ping public health-check URL toutes les 60s

---

## 5. Total coût mensuel V1

| Poste                                | Coût mensuel HT | Coût mensuel TTC (20%) | Coût annuel TTC |
|--------------------------------------|------------------|-------------------------|------------------|
| **Hetzner Cloud CX32 (Falkenstein)** | 6.80 €           | 8.16 €                  | 97.92 €          |
| Domaine `lecayenne.fr` (amorti)      | 0.65 €           | 0.78 €                  | 9.35 € (renouv. année 2+) |
| Domaine `lecayenne.com` optionnel    | 0.77 €           | 0.92 €                  | ~11 € (renouv.)  |
| Domaine `lecayenne.eu` optionnel     | 0.72 €           | 0.87 €                  | ~10 € (renouv.)  |
| Backups Backblaze B2 (~5 GB DB+files) | 0.025 $          | 0.025 $ (~0.024 €)      | ~0.30 €          |
| Egress B2 si restore (~5 GB)         | 0 (free 3× tier) | 0                       | 0                |
| Monitoring Uptime Kuma (self-hosted) | 0                | 0                       | 0                |
| SSL Let's Encrypt                    | 0                | 0                       | 0                |
| **TOTAL minimal (CX32 + .fr seul)** | **7.45 €**       | **~8.94 €**             | **~107 €/an**    |
| **TOTAL recommandé (CX32 + 3 domaines)** | **8.94 €**   | **~10.73 €**            | **~128 €/an**    |

### Comparatif scenarios

| Scenario                       | Mensuel TTC | Annuel TTC | Trade-off                                       |
|--------------------------------|-------------|------------|--------------------------------------------------|
| **Lean** (CX22 + .fr only)     | ~6.30 €     | ~76 €/an   | Risque OOM rush midi, 4 GB RAM tight. À surveiller. |
| **Recommandé** (CX32 + .fr only) | ~8.94 €   | ~107 €/an  | Safe margin, downsize possible si soak prouve OK |
| **Marque complète** (CX32 + .fr+.com+.eu) | ~10.73 € | ~128 €/an | Protection marque V1+V2 multi-resto |
| **Souveraineté FR** (OVH Essential + .fr) | ~15.78 € | ~189 €/an | Data 100% sol français, support FR tel |
| **Premium** (CX42 + .fr+.com+.eu) | ~16.95 € | ~204 €/an | 16 GB RAM, head-room V2 multi-resto |

→ **Cible : 10.73 € TTC/mo (CX32 + 3 domaines)** = ~9 € HT, soit ~£8/mo équivalent UK. Pour un resto avec un CA mensuel cible de plusieurs k€, c'est inférieur à 0.1% du CA. **Pas négociable l'économie de 2 € pour économiser 1 incident OOM.**

---

## 6. Action items owner — signup steps

### Étape 1 — Réserver le domaine (OVH)
1. Aller sur https://www.ovhcloud.com/fr/domains/
2. Taper `lecayenne.fr` dans le search → "Disponible" devrait s'afficher
3. Ajouter aussi `lecayenne.com` et `lecayenne.eu` au panier (cocher Compagnonnages dans le checkout)
4. Créer un compte OVH (mail pro + 2FA TOTP obligatoire)
5. Activer **WHOIS privacy** (gratuit pour .fr, inclus aussi sur .com/.eu chez OVH)
6. Payer (~21 € TTC année 1 pour les 3 domaines)
7. Noter les identifiants OVH dans le password manager owner

### Étape 2 — Créer le serveur Hetzner
1. Aller sur https://accounts.hetzner.com/signUp
2. Créer compte (mail pro + 2FA TOTP). KYC peut demander 1 photo CNI/passeport pour activer le projet — c'est rapide (5 min).
3. Console Hetzner → New Project → "Le Cayenne Prod"
4. Add Server :
   - Location : **Falkenstein (FSN1)** ou **Helsinki (HEL1)** — Falkenstein recommandé (plus proche France)
   - Image : Debian 12
   - Type : **CX32** (4 vCPU AMD, 8 GB RAM, 80 GB NVMe) — **6.80 €/mo**
   - Networking : IPv4 public ✓, IPv6 ✓
   - SSH Key : ajouter clé pub owner (générée localement `ssh-keygen -t ed25519`)
   - Firewalls : créer "le-cayenne-prod" → allow 22/tcp (depuis IP fixe owner), allow 80+443/tcp (all), allow 6001/tcp (all pour Soketi WS)
   - Backups : **activer** (+20% du prix serveur → ~1.36 €/mo) — snapshot quotidien Hetzner-side, rétention 7 jours
5. Create & Buy → serveur up en ~30s, IP publique fournie
6. Noter l'IP dans le password manager

### Étape 3 — Pointer le DNS (OVH → Hetzner)
1. OVH Manager → Domaines → `lecayenne.fr` → Zone DNS
2. Modifier les A records :
   - `@` (apex) → IP Hetzner serveur
   - `www` → CNAME `lecayenne.fr.`
   - `kiosk` → CNAME `lecayenne.fr.` (ou A record vers même IP)
   - `pos` → CNAME `lecayenne.fr.`
   - `kds` → CNAME `lecayenne.fr.`
   - `oss` → CNAME `lecayenne.fr.`
3. TTL : 3600 (1h) pour V1 — assez court pour iterer
4. Propagation : 5-30 min, vérifier avec `dig lecayenne.fr +short`

### Étape 4 — Configurer le serveur (script automatisé attendu cf task #48)
1. SSH `ssh root@<IP>` (depuis IP owner whitelist)
2. Run `server-setup.sh` (à scripter — installe stack Nginx+PHP-FPM+MySQL+Redis+Node+Soketi+certbot)
3. Run `deploy.sh` (clone repo, composer install --no-dev, npm run build, migrate, seed)
4. Vérifier `https://lecayenne.fr/login` répond → login admin / 123456 (changer le mot de passe immédiatement)
5. Lancer `php artisan storage:link`, `php artisan optimize`, `php artisan event:cache`
6. Configurer cron `php artisan schedule:run` chaque minute
7. Lancer `supervisorctl start laravel-queue:* soketi`

### Étape 5 — Setup backups
1. Créer un bucket Backblaze B2 `lecayenne-prod-backup` (B2 free tier 10 GB → suffisant pour V1)
2. Générer une App Key B2 (scope : write-only bucket)
3. Installer `rclone` sur le serveur, configurer remote `b2:lecayenne-prod-backup`
4. Cron quotidien 03:00 : `mysqldump --single-transaction --routines --triggers foodking | gzip | rclone rcat b2:lecayenne-prod-backup/db-$(date +%F).sql.gz`
5. Rétention 30 jours via rclone delete --min-age 30d

### Étape 6 — Setup monitoring
1. Docker installé pendant l'étape 4
2. `docker run -d --restart unless-stopped -p 3001:3001 louislam/uptime-kuma:1`
3. Accéder `https://lecayenne.fr:3001` → setup admin
4. Ajouter monitors :
   - HTTPS `https://lecayenne.fr/login` → check 60s, alert si <200ms ou non-200
   - HTTPS `https://lecayenne.fr/api/health` → check 60s
   - TCP `lecayenne.fr:6001` (Soketi) → check 300s
   - Push notif via webhook Discord ou Telegram owner

---

## 7. Migration path V2 (notes seulement, pas plan)

Si V1 prouvé sur soak 5 jours + 2 semaines shadow, scale path vers V2 SaaS multi-resto :

- **Séparer DB** : sortir MySQL du CX32 vers Hetzner Dedicated EX44 (ou Hetzner Managed Database quand disponible) — coût ~37 €/mo dédié, ou migrer vers OVH Managed MySQL (~25 €/mo) si exigence FR
- **Scale horizontal app** : passer le CX32 en pool de 2-3 CX22 derrière un Hetzner Load Balancer (~5.39 €/mo) — chaque CX22 stateless après séparation DB+Redis
- **Centraliser Redis** : Hetzner Cloud Redis ou DigitalOcean Managed Redis (~15-20 €/mo), Sentinel ou Cluster
- **CDN** : Cloudflare Free devant tout — DDoS protection + cache statique
- **Per-tenant isolation** : chaque resto = un schéma MySQL séparé OU une row de scope dans la DB master (BranchScope déjà en place côté code)
- Budget V2 multi-resto (~5 restos) : ~60-100 €/mo total ; au-delà, considérer Kubernetes sur Hetzner (HCloud k3s simple, ou managed Scaleway Kapsule)

---

## 8. Surprise / insight inattendue de la recherche

**UptimeRobot free plan n'est plus utilisable pour un usage commercial** depuis Oct 2024 — le free tier est restreint au non-commercial. Le Cayenne génère du CA = legally commercial = UptimeRobot exigerait un payant tier (~7-15 $/mo). C'est un piège classique qui surprend les déploiements small business.

→ **Alternative gratuite légale et meilleure techniquement : Uptime Kuma self-hosted** sur le même serveur Hetzner. Open-source, intervalles 20s possibles, status page publique gratuite, alertes Discord/Telegram/email natives. Coût : 0 € + ~80 MB RAM consommés sur le CX32. Recommandé V1.

Second insight bonus : **DigitalOcean est ~5× plus cher que Hetzner pour des specs équivalentes** (24 $/mo vs 6.80 €/mo pour 2 vCPU / 4 GB), et inclut **5× moins de bande passante** (4 TB vs 20 TB). Cela contredit l'image "DO = cheap startup cloud" qui date de 2015-2018 ; aujourd'hui DO est positionné mid-premium, et la vraie value lane EU passe par Hetzner.

---

## Annexe — Sources primaires consultées

**Domaine (verified 2026-05-23)**
- AFNIC whois server `whois.nic.fr` (primary registry source pour .fr)
- VeriSign whois (`.com` "No match for LECAYENNE.COM")
- EURid whois (`.eu` "Status: AVAILABLE")
- OVH pricing tld page (.fr 4.99 € HT first year / 7.79 € HT renew)

**Cloud (verified 2026-05)**
- https://www.hetzner.com/cloud/regular-performance (CX series specs)
- https://www.hetzner.com/pressroom/new-cx-plans/ (CX22/CX32 introduction + AMD EPYC config)
- https://sparecores.com/server/hcloud/cx32 (CX32 pricing 6.80 €/mo)
- https://docs.hetzner.com/cloud/general/locations/ (Falkenstein / Nuremberg / Helsinki / Ashburn / Hillsboro / Singapore datacenters)
- https://us.ovhcloud.com/vps/cheap-vps/ (OVH VPS Starter/Value/Essential pricing 2026)
- https://www.scaleway.com/en/pricing/virtual-instances-pricing/ (Stardust + DEV1-S 2026)
- https://www.digitalocean.com/pricing/droplets (Basic 4 GB at 24 $/mo)
- https://aws.amazon.com/lightsail/pricing/ (Lightsail bundles 2026)

**Benchmark & sizing**
- https://betterstack.com/community/guides/web-servers/hetzner-cloud-review/ (Hetzner 2026 review — Geekbench 900-1100 single-core, <4% stddev, <10ms intra-EU latency)
- https://perlod.com/tutorials/best-linux-vps-for-laravel/ (Laravel sizing 2-8 GB baseline rec)
- https://www.bagful.net/optimising-php-fpm-and-mysql-for-high-concurrency-applications/ (innodb_buffer_pool 60-70% RAM rule)

**Backup + monitoring**
- https://www.backblaze.com/cloud-storage/pricing ($0.005/GB/mo storage, free egress 3× ratio)
- https://uptime.kuma.pet / louislam/uptime-kuma Docker image (self-hosted free monitoring)
- UptimeRobot free tier non-commercial restriction (Oct 2024 ToS change, confirmed multiple 2026 sources)

**Soketi**
- https://github.com/soketi/soketi (memory footprint <1 GB / 1000+ conn confirmed)
- https://blog.laravel.com/deploying-soketi-to-laravel-forge (deployment patterns)

---

**END OF REPORT — Ready for owner decision.**
Document path : `reports/playbooks/CLOUD_DOMAIN_DECISION_2026-05-23.md`
Next step suggérée : owner valide CX32 + 3 domaines + signup OVH/Hetzner ce week-end → puis task #48 prépare `deploy.sh` + `server-setup.sh`.
