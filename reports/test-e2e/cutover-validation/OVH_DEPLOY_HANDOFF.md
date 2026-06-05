# OVH DEPLOY HANDOFF — Le Cayenne V1 → cloud (décisions owner 2026-06-05)

Décisions actées : **CI/CD auto-deploy sur push GitHub** · **nœud d'impression local** · **owner provisionne le VPS + donne l'accès SSH ; Claude installe/déploie**.
Référence step-by-step : `scripts/deploy/README_DEPLOY.md` (6 phases, ~80 min, transposable Hetzner→OVH sans modif notable — scripts génériques Ubuntu).

## A. Ce que TU fais / me fournis (les blockers physiques)

| # | Action owner | Détail | Me donne |
|---|---|---|---|
| 1 | **Commander le VPS OVH** | « VPS » (PAS hébergement mutualisé). Ubuntu 24.04 LTS, **≥ 4 Go RAM** (idéal 8 Go / 4 vCore), 40–80 Go SSD, datacenter France (Gravelines/Roubaix). Active les **snapshots OVH**. | IP publique du VPS |
| 2 | **Accès SSH** | Crée une clé : `ssh-keygen -t ed25519 -f ~/.ssh/lecayenne_prod` ; charge la `.pub` dans OVH (ou sur le VPS) ; teste `ssh root@<IP>`. | la clé / l'accès `root` (ou `deploy`) |
| 3 | **Domaine + DNS** | Domaine chez OVH (ou existant). Record **A** `lecayenne.fr` → IP, **A** `www` → IP, TTL 300. | nom de domaine + accès console DNS |
| 4 | **GitHub** | Repo `Kossay20/foodking-web` : ajoute une **Deploy key** lecture-seule (clé du VPS) OU un PAT `repo:read`. | confirmation |
| 5 | **Secrets GitHub Actions** (pour le CI/CD) | Settings → Secrets → Actions : `DEPLOY_HOST`(IP), `DEPLOY_USER`(deploy), `DEPLOY_SSH_KEY`(clé privée du deploy), `DEPLOY_KNOWN_HOSTS`(`ssh-keyscan -t ed25519 <IP>`). | je te donne les valeurs exactes |
| 6 | **Nœud d'impression** | 1 PC/mini-PC qui RESTE au resto, sur le même réseau que l'imprimante (Windows ou Linux). | quelle machine + IP imprimante |
| 7 | **Décisions/secrets** | mot de passe MySQL fort, email pour le certificat SSL. | les valeurs (hors git) |

> Légal (SIRET/TVA E.DELICE) = **déjà fait**. Les secrets fiscaux NF525 (`FISCAL_AUDIT_SECRET`/`FISCAL_Z_REPORT_SECRET`) seront générés **une seule fois** au 1er deploy et **jamais** régénérés (sinon la chaîne entière est invalidée).

## B. Ce que JE fais dès que j'ai l'accès (1er déploiement, ~80 min)

1. `server-setup.sh` → installe PHP 8.4 / MySQL 8 / Redis / Nginx / Supervisor / Soketi / Certbot / firewall.
2. `.env` production rempli + 5 boot-guards NF525 actifs (le boot REFUSE de démarrer si mal configuré).
3. `deploy.sh` → backup auto → composer `--no-dev` → build des bundles → `migrate --force` → `config:cache` → boot dry-run → `fiscal:verify-chain` (doit être CHAIN OK).
4. Nginx + **HTTPS Let's Encrypt** (cadenas vert, renouvellement auto).
5. Supervisor + Soketi (workers de file + WebSocket temps-réel) + crons (clôture Z, backup quotidien).
6. Firewall + monitoring (UptimeRobot ping `/api/health`).
7. Drill backup + restore (NF525) avant la 1re caisse.

## C. CI/CD — « push GitHub → déploiement auto »

- **Fichier prêt** : `.github/workflows/deploy-production.yml` (créé, **dormant**).
- **Déclencheur** : push sur la branche **`production`** (à créer) ou bouton manuel. Pas chaque commit sur `main` → évite qu'un commit cassé parte en plein service.
- **Flux** : tu merges `main` → `production` → l'Action se connecte au VPS en SSH → `deploy.sh` → `fiscal:verify-chain` → health-check `/api/health`. Échec = déploiement stoppé.
- **Garde-fou conseillé** : GitHub → Environments → `production` → « Required reviewers » = toi (validation 1 clic avant chaque mise en prod).
- **1 push = tout le système à jour** (POS+Borne+KDS+OSS+Admin = 1 seule appli). Les écrans (navigateurs au resto) prennent la nouvelle version au rechargement ; la synchro temps-réel garde tout aligné.

## D. Nœud d'impression (le SEUL vrai sujet cloud)

- Le cloud ne joint pas l'imprimante locale (LAN privé). Le backend met les tickets dans une file `print_jobs` ; un **agent local** au resto les récupère (token-auth) et imprime en ESC/POS.
- **Déjà construit** sur la branche `massive-e2e-0604-wt` : `tools/print-agent/` (TCP ESC/POS + unit systemd + package install Windows/Linux), backend `print_jobs` outbox + E2E complet encaissement→file→agent→ticket (durci round-3 adversarial). **Tâche : merger cette branche + finaliser l'install sur le mini-PC du resto.**
- TPE = SumUp manuel → aucun souci cloud. Écrans = navigateurs → marchent via internet.

## E. Prochaines étapes (ordre)
1. **Toi** : commande le VPS OVH (A.1–A.2) + domaine/DNS (A.3) → donne-moi IP + accès.
2. **Moi** : 1er déploiement (B) + wire le CI/CD (C) + merge/finalise le nœud d'impression (D).
3. **Toi** : crée la branche `production` + mets les secrets Actions (A.5) → à partir de là, mises à jour = push.
4. **Ensemble** : drill backup/restore + `fiscal:verify-chain` CHAIN OK → **ouverture prod**.

> Rappel sécurité déjà appliqué : merger `tests/CreatesApplication.php` (DEVDB-GUARD) sur `main` avant tout — empêche qu'un run de tests efface une vraie DB (incident 2026-06-05).
