# Le Cayenne — Architecture des comptes (production cloud OVH)
**2026-06-06 · Superviseur cleanup · host `vps-418872ac.vps.ovh.net`**

Owner directive (/goal) : *« supprime tous les comptes test ; il n'y aura pas 21 comptes, seulement 3 en réalité — patron / caisse / admin ; rends l'architecture claire »*.

---

## Modèle de comptes FINAL (après purge)

L'architecture production = **3 comptes humains** + **1 client-système** + **1 borne-machine**. Rien d'autre.

| # | Identifiant | Type / Rôle | Surface | Mot de passe |
|---|---|---|---|---|
| 1 | `admin@lecayenne.fr` | **Admin** (humain — patron/back-office) | `/admin/*` : dashboard, catalogue, réglages, rapports, encaissement | défini (changer au 1er login) |
| 2 | `pos@lecayenne.fr` | **Caisse** (humain — POS Operator) | `/admin/pos` (caisse), suivi commandes | ⚠️ mdp dev hérité — à définir |
| 3 | `chef@lecayenne.fr` | **Cuisine** (humain — Chef) | `/admin/kitchen-display-system` (KDS) | ⚠️ mdp dev hérité — à définir |
| — | `walkingcustomer@example.com` | *Client-système* (Customer, **pas un login humain**) | défaut « Client passage » des commandes caisse | n/a (jamais utilisé pour login) |
| — | borne `kiosk-lecayenne` (table `KioskMachine`, id 1) | **Borne** (machine, pas un User) | `/kiosk/*` auto-login | `Cayenne-Borne-ab0b9cc9` (fort) |

### Mapping aux 5 systèmes
- **POS / Caisse** → `pos@lecayenne.fr`
- **KDS / Cuisine** → `chef@lecayenne.fr`
- **Borne** → machine `kiosk-lecayenne` (auto-login IP-gated — voir activation ci-dessous)
- **Admin / Back-office** → `admin@lecayenne.fr`
- **OSS (suivi client)** → écran public, pas de compte dédié

> Note : OSS + KDS en session **admin (branch 0)** tournent en *polling* par design ; le **push temps-réel `private-branch.1`** s'active pour les comptes **staff branch-1** (pos/chef).

---

## Ce qui a été SUPPRIMÉ (purge 2026-06-06)

Backup complet AVANT : `/root/lecayenne-backups/pre-account-cleanup-20260606-024712.sql.gz` (36K).

- **18 comptes User test** : 10× `@stress.local`, 6× `@soak.local`, 1× `livreur.e2e@lecayenne.test`, 1× `bm.t2admin@lecayenne.fr` (Branch Manager test).
- **7 bornes-machines dev** (stress/soak `kiosk_machines` id 2-8).
- Pivots de rôles/permissions associés + 1 pivot orphelin → 0 restant.

Sécurité de la purge : vérifié **0 commande, 0 audit_log, 0 session caisse, 0 mouvement stock, 0 token** ne référençaient ces comptes (tables transactionnelles vides). **Chaîne NF525 `CHAIN OK`** avant ET après. `items=45`, `orders=0` (caisse propre intacte).

État final prouvé : `USERS=4`, `KIOSK_MACHINES=1`, `orphan_pivots=0`.

---

## RESTE (owner / autorisation)
1. **Mots de passe `pos@` + `chef@`** : encore les mdp dev hérités → à définir en valeurs connues (1 commande chacun) pour exploitation + pour vérifier le push temps-réel (login chef → `subscribed:true`).
2. **Borne** : affiche « non configurée » jusqu'à ce que l'IP publique du device borne soit ajoutée → `KIOSK_AUTO_LOGIN_TRUSTED_IPS=<ip>` + `config:cache`.
3. Durcissement host (hors périmètre comptes) : ufw, désactivation SSH password-auth, rotation mdp exposés chat.
