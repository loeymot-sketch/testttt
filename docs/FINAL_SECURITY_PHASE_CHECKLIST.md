# Dernière étape — Durcissement sécurité avant ouverture réelle

> **Décision owner (2026-07-15)** : tant que le système fonctionne en test réel,
> **tout ce document est DIFFÉRÉ** et se déroule en **TOUT DERNIER**, au moment
> de finaliser la sécurité (0 client réel avant ça → risque assumé).
>
> **Rien ici n'est requis pour tester.** C'est la check-list unique du go-live
> sécu : quand on y arrive, on la déroule de haut en bas, puis on ouvre.
>
> Ce qui a DÉJÀ été fait (n'est PAS à refaire) : le garde-fou go-live durci
> (`app:preflight-production` re-vérifie la chaîne + rejette les sentinelles,
> testé), le script de déploiement durci (`tools/deploy-lecayenne.sh`), et la
> documentation (ce fichier + registre + runbook). Il ne reste que l'**exécution**.

---

## A · Secrets forts (le « max de secret discret »)
> Détail complet + emplacements : **`docs/HANDOVER_SECRETS_REGISTRY.md` §1 et §3**.

```
[ ] FISCAL_AUDIT_SECRET    = openssl rand -hex 32   (permanent, secret manager, 1er boot DB propre)
[ ] FISCAL_Z_REPORT_SECRET = openssl rand -hex 32   (permanent, secret manager)
[ ] APP_KEY                = php artisan key:generate (une fois, si DB neuve)
[ ] DB_PASSWORD            = fort ; compte applicatif SANS privilège DROP
[ ] KIOSK_MACHINE_PASSWORD = fort unique (≠ kiosk123)         → voir §B
[ ] KIOSK_AUTO_LOGIN_SECRET (ou TRUSTED_IPS)                  → voir runbook borne
[ ] DAILY_BOOK_PIN         = 6 chiffres ≠ 2468
[ ] PUSHER / soketi        = vraies clés (BROADCAST_DRIVER ≠ null)
[ ] SMS provider (OTP)     = vraies clés
[ ] Archiver les anciens secrets post-rotation — NE JAMAIS détruire (6 ans NF525)
```

## B · Fermer le trou de sécurité borne
> Contexte : `kiosk123` est public (repo) + l'API borne est joignable de partout.
> Détail : **`docs/HANDOVER_SECRETS_REGISTRY.md` §2** + **`docs/runbooks/KIOSK_MACHINE_PROVISIONING.md`**.

```
[ ] Vérifier si le row kiosk-lecayenne existe sur la box (SELECT … FROM kiosk_machines)
[ ] Rotation mot de passe unique : foodking:ensure-kiosk-machine --password=<FORT> --force
[ ] Révoquer les tokens vivants : POST admin/kiosk-machine/logout/{id}  (sinon valides 8 h)
[ ] Choisir le chemin auto-login (machine_key OU IP) — JAMAIS une IP de LB dans TRUSTED_IPS
[ ] (Durcissement) re-keyer le throttle kiosk-login sur REMOTE_ADDR (RouteServiceProvider)
```

## C · Réconcilier la chaîne NF525 (workstream A — human gate)
> Détail + garde-fous anti-catastrophe : **`plans/GOAL_REMEDIATION_3_POINTS_STAGING_2026-07-15.md` §A**.
> ⚠ NF525 §10 : ne rien exécuter sans gel des writers + dump immuable d'abord.

```
[ ] GELER les writers (php artisan down / stop scheduler) + mysqldump --triggers immuable
[ ] Diagnostic read-only : verify-chain multi-row + Z + fiscal:verify-immutability-triggers
[ ] SI secret original récupérable → le poser dans .env → verify-chain vert (0 changement de données)
[ ] SINON → documenter (staging non-légal) ET garantir que la PROD démarre sur un genesis PROPRE
[ ] INTERDIT : re-signer l'historique (UPDATE) · TRUNCATE/DROP la chaîne · APP_ENV=local pour bypass
```

## D · Vérifier la topologie de service du VPS
> Le smoke du nouveau script s'auto-découvre, mais confirmer ce que sert la box.

```
[ ] Sur la box : ss -tlnp | grep -E ':(80|443|8766)'  ;  nginx -T | grep -n listen
[ ] Confirmer que ce n'est PAS un `php artisan serve` en prod (sinon → passer à php-fpm)
[ ] Déployer avec : bash tools/deploy-lecayenne.sh [SHA_RELU]
```

## E · Barrière finale (gate go-live)
```
[ ] php artisan config:cache && php artisan queue:restart
[ ] APP_ENV=production php artisan app:preflight-production --strict   →   DOIT sortir 0
```

---

## Definition of Done — sécurité
- Tous les secrets §A posés (forts, hors-repo), anciens secrets archivés.
- Borne : plus aucun `kiosk123` vivant ; tokens révoqués ; auto-login prouvé sur la vraie borne.
- Chaîne NF525 verte (ou posture staging documentée + prod genesis propre garanti).
- `preflight-production --strict` = exit 0.
- Déploiement via `tools/deploy-lecayenne.sh` avec smoke contenu vert.

**Tant qu'on est en test réel, on ne fait RIEN de cette liste — on la garde pour la fin.**

---
**Références** : `docs/HANDOVER_SECRETS_REGISTRY.md` · `docs/runbooks/KIOSK_MACHINE_PROVISIONING.md` · `plans/GOAL_REMEDIATION_3_POINTS_STAGING_2026-07-15.md` · `docs/FISCAL_SECRETS.md`.
**Créé** : 2026-07-15.
