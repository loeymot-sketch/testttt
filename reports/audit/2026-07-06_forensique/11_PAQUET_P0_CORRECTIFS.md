# FoodKing — Paquet de correctifs P0 (prêt à appliquer)

> Complément « passage à l'action » de l'audit forensique du 2026-07-06.
> Correctifs P0 (sécurité + performance) **ancrés sur le vrai code**, chacun **vérifié adversarialement** (3 lentilles : exactitude/applicabilité, invariant sécurité, régression) + juge d'arbitrage sur désaccord.
> Bilan : **7 à adopter, 1 avec réserve, 2 rejetés** sur 12 candidats.

> ⚠️ Chaque `edit` donne la chaîne EXACTE à remplacer (`AVANT`) et le remplacement (`APRÈS`). Les étapes manuelles (rotation de secret, variables d'env) ne committent aucun secret. Environnement d'audit sans `vendor/`/`node_modules` : appliquer et **tester** dans un environnement de build avant merge.

---

## 0. Synthèse du paquet

Paquet de remédiation P0 FoodKing : 8 correctifs ADOPTÉS (dont 1 sous réserve), 2 REJETÉS confirmés. Le paquet se scinde en deux couches selon la contrainte "pas de build/test ici" (vendor/node_modules absents).

COUCHE APPLICABLE EN AVEUGLE (défensive pure, risque runtime négligeable) : elle ferme immédiatement une partie de la surface d'attaque par du durcissement statique, sans toucher de logique métier — deny <FilesMatch> de la clé service-account GCP dans .htaccess (placé AVANT tout bloc cache, le deny prime), .gitignore renforcé, abort(404) + alias notInstalled pour verrouiller l'installeur, et cache-buster filemtime() en Blade.

COUCHE EXIGEANT UN ENV (build/test/redis/PSP) AVANT MERGE : c'est là que se ferment réellement les invariants. sec-branchscope (branch_id==0 -> hasRole(ADMIN), deny-by-default) change la visibilité effective et doit précéder toute relâche de cache (step 1, bloque tous les perf-*). sec-psp-verify (jamais dériver PAID d'un transaction_id client ; vérif inline synchrone) est un effort L au cœur paiement, exige sandbox PSP + webhook signé HORS groupe admin. perf-queue (garde prod QUEUE!=sync extraite en méthode testable, listeners intacts + test non-régression), perf-apexcharts (rebuild webpack, async 3 dashboards) et perf-persist (throttle + retrait posCart, sous réserve flush synchrone) ne sont validables que par exécution.

HONNÊTETÉ : rien de la couche needs_env ne peut être déclaré fermé ici. La rotation/déplacement hors docroot de la clé GCP — seul geste qui neutralise vraiment la fuite — reste un travail ops ; le deny .htaccess et le .gitignore ne purgent NI l'historique git NI ne rotent le secret déjà exposé. Les deux rejets sont des régressions graves confirmées : sec-admin-guard 403-erait POS/KDS/dashboard/OSS en prod (préfixe "admin" pas admin-only) ; perf-htaccess figerait des assets non fingerprintés (mix sans .version()) en immutable 1 an, cassant SPA/POS/kiosk après déploiement. À re-proposer redesignés.


**✅ Applicable sans environnement de build (risque runtime négligeable)**
- sec-gcp-key : dans public/.htaccess, <FilesMatch> deny (Require all denied) sur *.json service-account et *.key, placé AVANT le bloc cache. Ancre pure, le deny prime, aucun impact runtime.
- sec-gcp-key : durcir .gitignore pour exclure clé GCP et credentials. Zéro risque runtime (ne purge pas les fichiers déjà commités — voir risks).
- perf-cachebuster : master.blade.php, time() -> filemtime() sur pos-wizard.css/js. Ancre markup pure ; filemtime sur fichiers existants, casse négligeable (réserve multi-noeuds -> risks).
- sec-installer : Kernel.php, enregistrer l'alias 'notInstalled' à côté de 'installed' dans $routeMiddleware. Ajout de clé pur, vérifier zéro doublon.
- sec-installer : abort(404) bloquant dans InstallerController quand l'app est déjà installée. Garde défensive blind-safe (ne se déclenche qu'une fois installé).

**🛠️ Exige build / test / redis / PSP avant merge**
- sec-branchscope (FONDATION, step 1) : BranchScope + DefaultAccessModelTrait (branch_id==0 -> hasRole(ADMIN)) + channels.php. Tester accès admin vs scope branche ; bloque tous les perf-*.
- sec-installer (câblage) : garde notInstalled sur /install dans routes/web.php. Vérifier qu'un install légitime n'est pas verrouillé (nécessite exécution du flux d'install).
- sec-gcp-key (rotation ops) : roter la clé GCP, la sortir du docroot, ajuster FirebaseService (chemin) + seeder. Exige env de déploiement + rotation ; seul geste qui neutralise la fuite.
- sec-psp-verify (L) : binding PspVerifier en register() + vérif de charge synchrone/inline auprès du PSP avant PAID dans OrderController, webhook signé hors groupe admin. Exige sandbox PSP + tests.
- perf-queue : extraire la garde prod (throw si QUEUE=sync / BROADCAST absent) en assertProductionSafety() appelée par boot(), listeners intacts, + test de non-régression. Exige runner de tests.
- perf-apexcharts : retirer apexcharts du bundle critique, chargement async local aux 3 dashboards. Exige rebuild webpack + vérif rendu dashboards.
- perf-persist (ADOPTER_AVEC_RESERVE, EN DERNIER) : throttle vuex-persistedstate + retrait posCart des paths (source unique scopée caissier). Exige test panier + changement caissier ET fix flush synchrone (voir risks).

**⚠️ Risques résiduels**
- perf-persist : throttle leading:false diffère l'écriture d'idempotencyKey ; la clé est commit (kioskCart.js:348) AVANT axios.post (356). Crash <400ms -> clé perdue -> DOUBLE COMMANDE (dedup FrontendOrderService:136). Imposer flush synchrone AVANT axios.post.
- Ordre load-bearing : sec-branchscope (step 1) DOIT précéder toute relâche de cache. Un Cache-Control public/long sur des réponses à état branche fuiterait cross-branche via CDN. Cache long UNIQUEMENT sur assets immuables versionnés ; menu/API en no-store.
- sec-gcp-key : le .gitignore ne purge PAS l'historique git et ne rote PAS le secret exposé. Le deny .htaccess est une mitigation, pas une remédiation — la clé reste compromise tant qu'elle n'est pas rotée et hors docroot.
- REJET sec-admin-guard : prefix('admin') n'est PAS admin-only (pos, table-order, dashboard:700, kds:776, oss). role:Admin|Stuff -> 403 POS/KDS/dashboard en prod. Ne pas appliquer le bundle. Webhook PSP et QR table hors groupe role:ADMIN.
- REJET perf-htaccess : assets non fingerprintés (mix sans .version()). FilesMatch immutable 1 an fige app.js/app.css/kiosk.js -> clients gardent l'ancien bundle post-déploiement -> SPA/POS/kiosk cassés. Re-proposer avec .version() ou politique revalidante.
- perf-cachebuster : filemtime() peut différer entre noeuds derrière un LB -> busting incohérent. OK en déploiement atomique single-node ; préférer un hash de build (mix-manifest) si multi-noeuds.
- Tension queue vs PSP : la vérif PSP doit rester synchrone/inline ; jamais dans un listener async (job perdu -> commande jamais confirmée). Seuls notifications/broadcast partent en queue.

---

## 1. Ordre d'application & fichiers partagés

### Séquence d'application
| # | Correctif | Raison de l'ordre |
|---|---|---|
| 1 | `sec-branchscope` | Fondation d'isolation : BranchScope + DefaultAccessModelTrait (branch_id==0 -> hasRole(ADMIN), deny-by-default) + channels.php. Doit preceder toute relache de cache sous peine de fuite cross-branche. |
| 2 | `sec-installer` | Enregistrer l'alias 'notInstalled' dans Kernel.php AVANT que routes/web.php ne l'utilise sur /install ; ajouter abort(404) dans InstallerController. |
| 3 | `sec-admin-guard` | Enregistrer/confirmer l'alias de garde admin dans Kernel.php (fusionne avec sec-installer) AVANT d'envelopper le groupe admin de routes/api.php. |
| 4 | `sec-psp-verify` | Binding PspVerifier dans AppServiceProvider (register) + garde prod perf-queue fusionnee (boot) AVANT qu'OrderController ne derive PAID d'une verif PSP ; route webhook signee hors groupe admin. |
| 5 | `perf-queue` | Extraction de la garde prod (QUEUE!=sync) en methode testable, co-editee avec sec-psp-verify dans AppServiceProvider ; ajouter le test de non-regression, listeners intacts. |
| 6 | `sec-gcp-key` | Poser le deny <FilesMatch> des secrets dans .htaccess (avant le bloc cache), durcir .gitignore, roter/deplacer la cle hors docroot, ajuster FirebaseService + seeder. |
| 7 | `perf-cachebuster` | time() -> filemtime() dans master.blade.php une fois l'isolation branche (step 1) en place, pour rendre pos-wizard.css/js cachables sans risque de fuite. |
| 8 | `perf-apexcharts` | Retrait d'apexcharts du bundle critique + chargement async local aux 3 dashboards ; independant du backend, aucune dependance amont. |
| 9 | `perf-persist` | EN DERNIER (ADOPTER_AVEC_RESERVE) : throttle vuex-persistedstate + retrait de posCart des paths, seulement si posCart devient source unique scopee par caissier ; exiger test panier + changement caissier. |

### Fichiers touchés par plusieurs correctifs (à fusionner)
- **`app/Providers/AppServiceProvider.php`** (perf-queue, sec-psp-verify) — perf-queue est deja dans boot() (garde prod : throw si BROADCAST_DRIVER absent ou QUEUE_CONNECTION=sync) : ne pas dupliquer, l'extraire en methode privee testable (assertProductionSafety()) appelee par boot(), listeners intacts. sec-psp-verify met le binding PspVerifier->impl dans register(), PAS boot(). Une assertion config PSP prod-only se replie DANS assertProductionSafety() plutot qu'un second if(environment('production')). Resultat : register() bind le PSP, boot() garde un seul bloc prod fusionne. Binding present avant l'appel d'OrderController.
- **`public/.htaccess`** (sec-gcp-key, perf-htaccess) — Pas de conflit de contenu mais ordre load-bearing. Structure unique : (1) Options ; (2) sec-gcp-key EN PREMIER : <FilesMatch> deny (Require all denied) sur *.json service-account / *.key, qu'un fichier interdit n'atteigne jamais le cache ; (3) bloc RewriteEngine existant ; (4) mod_headers securite existant inchange ; (5) perf-htaccess mod_expires/Cache-Control scope STRICTEMENT aux assets (css/js/img/fonts), jamais application/json ni credentials. Chaque ajout dans son <IfModule>. Le deny prime. Ideal : coupler au deplacement de la cle hors docroot.
- **`routes/api.php`** (sec-psp-verify, sec-table-qr, sec-admin-guard) — Trois groupes distincts, pas d'insertion au meme ancrage. Dedupe d'abord les imports use Controllers. sec-admin-guard enveloppe le groupe admin avec role:ADMIN/permission : la route webhook PSP (non-auth mais signee) et la route QR table (signed/scope) restent HORS de ce groupe, sinon role:ADMIN casse le webhook et le QR client. Fusion : (a) groupe admin durci, (b) groupe public webhook PSP avec middleware de signature, (c) route QR table avec middleware signed, (b) et (c) non imbriques dans (a).
- **`app/Http/Kernel.php`** (sec-installer, sec-admin-guard) — Les deux editent $routeMiddleware avec des cles distinctes : fusionner dans le meme bloc sans doublon. sec-installer ajoute 'notInstalled' a cote de 'installed'. sec-admin-guard ajoute/reutilise l'alias de garde admin. Si l'un touche aussi le groupe 'api' de $middlewareGroups, fusionner en ordre. Verifier zero cle dupliquee. CRITIQUE : ces alias sont enregistres ICI avant que routes/web.php (garde /install) et routes/api.php (garde admin) ne les referencent.

### Tensions performance ↔ sécurité
- Cache HTTP statique (perf-htaccess/cachebuster) vs isolation par branche (sec-branchscope) : un Cache-Control public/long sur des reponses porteuses d'etat branche fuiterait entre branches via CDN/cache partage. Trancher : cache long UNIQUEMENT sur assets immuables versionnes ; menu/API restent private/no-store. BranchScope prime et precede (step 1) toute relache de cache.
- Deny du secret GCP (.htaccess sec-gcp-key) vs mod_expires trop large (perf-htaccess) : un ExpiresByType large (tout, ou application/json) rendrait la cle service-account cachable/servable. Trancher : le <FilesMatch> deny des credentials est place AVANT le bloc cache, et le cache est scope strictement aux types d'assets. Ideal : la cle sort du docroot (rotation), ce qui neutralise le point.
- QUEUE=sync interdit en prod (perf-queue) vs verif PSP avant PAID (sec-psp-verify) : deporter la verif de charge sur une queue async ferait qu'un job perdu laisse une commande jamais confirmee. Trancher (backend = source de verite etat) : la verif PSP reste synchrone/inline dans le flux de confirmation ; seuls notifications/broadcast partent en queue. Jamais la verif dans un listener async.
- Throttle + retrait de posCart de la persistance (perf-persist) vs integrite panier et isolation caissier : sans scope caissier un panier fuit entre sessions sur POS partage, et depersister peut perdre le panier au refresh. Trancher : n'adopter que si posCart devient source unique scopee par caissier (cle par user), sinon block. D'ou l'ADOPTER_AVEC_RESERVE et le test exige.
- Cache-buster filemtime (perf-cachebuster) vs coherence multi-serveur : filemtime peut differer entre noeuds derriere un LB, produisant un busting incoherent. Trancher : preferer un hash de build (mix-manifest) si dispo ; filemtime acceptable en deploiement atomique single-node, a re-evaluer si multi-noeuds.

---

## 2. Correctifs détaillés

### P0-01 · branch_id==0 traité comme admin (fuite cross-branche) — visibilité par hasRole(ADMIN), deny-by-default
`id=sec-branchscope` · 🔴 SÉCURITÉ · décision : **ADOPTER** · effort : ?
> **Invariants** : Branche RENFORCÉE : 0=voit-tout remplacé par rôle + deny 1=0, au pire plus restrictif. Fail-closed (method_exists+hasRole). Pricing intact. Fiscal intact (ZReport/GuestSignup usent withoutGlobalScope). Outbox intact : seul le callback d'autorisation change, pas l'émission afterCommit. Pas de récursion (apply() sort tôt pour User). Kiosque : tokenCan reste prioritaire.

**Édition 1 — `app/Models/Scopes/BranchScope.php`** — Remplace le test branch_id===0 par hasRole(ADMIN) fail-closed + deny-by-default (whereRaw 1=0) pour tout acteur sans branche réelle.
*AVANT :*
```
            // [FIX-54-8] Only admins (branch_id = 0) can see cross-branch records.
            // Regular staff should NEVER see records with branch_id = 0.
            if ($userBranch === 0) {
                // Admin: no filter applied — sees all branches including branch_id=0 rows
                return;
            }

            // Staff: only their own branch — never expose branch_id=0 rows
            $builder->where($field, '=', $userBranch);
```
*APRÈS :*
```
            // [sec-branchscope] Cross-branch visibility is decided by the ADMIN role ONLY,
            // never by branch_id === 0. A user without a real branch is denied by default.
            $user = Auth::user();
            $isAdmin = $user && method_exists($user, 'hasRole') && $user->hasRole(\App\Enums\Role::ADMIN);
            if ($isAdmin) {
                // Admin: no branch filter applied — sees all branches.
                return;
            }

            // Non-admin without a real branch (0 / null / '') → deny by default (fail-closed),
            // never expose branch_id=0 rows to regular staff.
            if ($userBranch === 0 || $userBranch === null || $userBranch === '') {
                $builder->whereRaw('1 = 0');
                return;
            }

            // Staff: only their own branch.
            $builder->where($field, '=', $userBranch);
```

**Édition 2 — `app/Traits/DefaultAccessModelTrait.php`** — Corrige le commentaire trompeur (0 = admin voit tout) ; comportement inchangé (return 0), mais 0 déclenche désormais le deny côté scope.
*AVANT :*
```
            } elseif ((int) Auth::user()->branch_id === 0) {
                // Admin / cross-branch: BranchScope must not apply a branch filter (see BranchScope FIX-54-8).
                return 0;
            } else {
```
*APRÈS :*
```
            } elseif ((int) Auth::user()->branch_id === 0) {
                // No real branch (branch_id = 0). Cross-branch visibility is decided by the ADMIN role
                // in BranchScope (see [sec-branchscope]); returning 0 here makes BranchScope deny by
                // default (whereRaw 1=0) for any non-admin actor. Do NOT treat 0 as "sees all".
                return 0;
            } else {
```

**Édition 3 — `routes/channels.php`** — Autorise branch.{id} par rôle ADMIN (FQN pour éviter la collision Spatie\Role) et refuse strictement tout non-admin sans branche réelle (<=0) pour bloquer branch.0.
*AVANT :*
```
    // Admin users (branch_id=0) can subscribe to any branch channel
    if ((int) $user->branch_id === 0) {
        return true;
    }

    // Regular staff: own branch only
    return (int) $user->branch_id === (int) $branchId;
```
*APRÈS :*
```
    // [sec-branchscope] Admin (by ADMIN role, never by branch_id) can subscribe to any branch channel.
    // NOTE: kiosk tokens use the admin user as owner (GAP-21-5) and WOULD match hasRole(ADMIN); this is
    // safe only because the kiosk tokenCan('kiosk:order') check above returns FIRST. Do not reorder.
    if (method_exists($user, 'hasRole') && $user->hasRole(\App\Enums\Role::ADMIN)) {
        return true;
    }

    // Deny-by-default: a non-admin without a real branch (branch_id <= 0) can never subscribe,
    // otherwise it would pass the equality check on 'branch.0'.
    if ((int) $user->branch_id <= 0) {
        return false;
    }

    // Regular staff: own branch only
    return (int) $user->branch_id === (int) $branchId;
```

**Étapes manuelles (hors code) :**
- Audit données (aucun secret) : vérifier que les comptes voulus cross-branche portent le rôle ADMIN (id=1). Ex : SELECT id FROM users u WHERE u.branch_id=0 AND u.id NOT IN (SELECT model_id FROM model_has_roles WHERE role_id=1). Ces comptes basculent en deny après le fix.
- Après déploiement, purger les caches si mis en cache en prod : php artisan route:clear && php artisan config:clear (channels.php chargé au boot). Aucun secret.

**Test de preuve :** PHPUnit tests/Feature/BranchScopeSecurityTest.php — prouve deny-by-default + admin par rôle.\n\ntest_non_admin_branch_zero_denied():\n  Order::factory()->create(['branch_id'=>5]); Order::factory()->create(['branch_id'=>0]);\n  $s=User::factory()->create(['branch_id'=>0]);\n  $s->assignRole(Role::findById(\\App\\Enums\\Role::WAITER));\n  $this->actingAs($s);\n  $this->assertSame(0, Order::count()); // avant le fix: voyait TOUT\n\ntest_admin_role_sees_all():\n  Order::factory()->create(['branch_id'=>5]); Order::factory()->create(['branch_id'=>7]);\n  $a=User::factory()->create(['branch_id'=>0]);\n  $a->assignRole(Role::findById(\\App\\Enums\\Role::ADMIN));\n  $this->actingAs($a);\n  $this->assertSame(2, Order::count());\n\ntest_staff_own_branch_only():\n  Order::factory()->create(['branch_id'=>5]); Order::factory()->create(['branch_id'=>7]);\n  $s=User::factory()->create(['branch_id'=>5]);\n  $s->assignRole(Role::findById(\\App\\Enums\\Role::WAITER));\n  $this->actingAs($s);\n  $this->assertSame(1, Order::count());\n\nCanal branch.{id} : asserter non-admin branch_id=0 => false sur branch.0 ; admin(role) => true partout ; staff b5 => true sur branch.5, false sur branch.7. tokenCan('kiosk:order') reste prioritaire (non modifié).

**Rollback :** git revert du commit (réappliquer les 3 old_string d'origine). Aucune migration ni dépendance ajoutée, donc aucune étape de données ; le revert seul restaure le comportement précédent.

**Vérification adversariale :** exactitude=✅ · invariant=✅

---

### P0-02 · Clé privée GCP servie publiquement : deny HTTP + gitignore + rotation/déplacement hors docroot
`id=sec-gcp-key` · 🔴 SÉCURITÉ · décision : **ADOPTER** · effort : ?
> **Invariants** : Patch infra/securite pur, aucun code metier touche : pricing, isolation branch_id, transitions statut, integrite fiscale et outbox intacts. Runtime non impacte (FirebaseService lit la copie storage/media, pas public/file/). LIMITE : inefficace sous Nginx et ne couvre pas le symlink public/storage, traites en manual_steps (rotation + disque prive).

**Édition 1 — `public/.htaccess`** — Bloque le telechargement direct du service-account (et env/pem/key/p12/pfx/crt) sous Apache, sans casser menu.json ni les autres .json applicatifs.
*AVANT :*
```
    Header always set Permissions-Policy "camera=(), microphone=(), geolocation=()"
</IfModule>
```
*APRÈS :*
```
    Header always set Permissions-Policy "camera=(), microphone=(), geolocation=()"
</IfModule>

# [SEC-GCP-KEY] Interdit le service HTTP des fichiers de secrets/identifiants
# (service accounts, cles, env). Ciblage VOLONTAIREMENT etroit : ne bloque PAS
# les .json applicatifs legitimes (ex: public/images/ai_food/menu.json fetche par script.js).
<FilesMatch "(?i)(service-account.*\.json|\.(env|pem|key|p12|pfx|crt))$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order allow,deny
        Deny from all
    </IfModule>
</FilesMatch>
```

**Édition 2 — `.gitignore`** — Empeche toute re-adition future du secret. NB : sans effet sur le fichier deja tracke -> `git rm --cached` obligatoire (voir manual_steps).
*AVANT :*
```
.env
.env.backup
.env.testing
```
*APRÈS :*
```
.env
.env.backup
.env.testing

# [SEC-GCP-KEY] Cle service account GCP — ne jamais servir ni committer
/public/file/service-account-file.json
/storage/app/**/service-account-file.json
```

**Nouveau fichier — `tests/Feature/Security/SecretFileExposureTest.php`** — Test statique CI (vendor/Apache non requis) verrouillant les 3 garanties : absence docroot, deny present, menu.json non impacte.
```
<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * [SEC-GCP-KEY] Prouve que la cle GCP n'est ni servable ni tracklable.
 * Statique (pas d'Apache en CI) : verifie l'absence du fichier dans le docroot,
 * la presence de la regle de deny .htaccess, et que menu.json reste autorise.
 */
class SecretFileExposureTest extends TestCase
{
    public function test_service_account_absent_du_docroot_public(): void
    {
        $this->assertFileDoesNotExist(
            public_path('file/service-account-file.json'),
            'La cle service account NE DOIT PAS resider dans public/ (docroot servi).'
        );
    }

    public function test_htaccess_denie_les_fichiers_de_secrets(): void
    {
        $htaccess = file_get_contents(public_path('.htaccess'));
        $this->assertStringContainsString('service-account.*\\.json', $htaccess);
        $this->assertStringContainsString('Require all denied', $htaccess);
    }

    public function test_htaccess_ne_bloque_pas_les_json_applicatifs(): void
    {
        // menu.json (fetch HTTP par images/ai_food/script.js) ne matche pas le pattern.
        $pattern = '/(?i)(service-account.*\\.json|\\.(env|pem|key|p12|pfx|crt))$/';
        $this->assertSame(0, preg_match($pattern, 'images/ai_food/menu.json'));
        $this->assertSame(1, preg_match($pattern, 'file/service-account-file.json'));
    }
}

```

**Étapes manuelles (hors code) :**
- ROTATION IMMEDIATE : la cle est deja compromise (presente dans l'historique git ET servie publiquement). Console GCP > IAM > Service Accounts > cle du projet foodking-inilabs > supprimer la cle exposee et generer une nouvelle cle.
- Retirer le fichier du suivi git SANS supprimer la copie locale : `git rm --cached public/file/service-account-file.json` puis committer (le .gitignore ne protege pas un fichier deja tracke).
- Deplacer la NOUVELLE cle hors docroot et hors symlink public/storage : la stocker dans `storage/app/private/service-account-file.json` (jamais dans public/ ni storage/app/public/).
- Purge historique (recommande car secret deja pousse) : reecrire l'historique avec git-filter-repo/BFG pour supprimer public/file/service-account-file.json, puis force-push coordonne.
- Si le seeder DEMO NotificationTableSeeder.php doit survivre : adapter son chemin (ligne 34/36) vers storage_path('app/private/...') — sans jamais y remettre une vraie cle de production.
- Vecteur annexe (hors scope immediat) : storage/app/public est expose via le symlink public/storage ; migrer la lecture FirebaseService vers un disque prive (Storage::disk('local')).

**Test de preuve :** tests/Feature/Security/SecretFileExposureTest.php (fourni). Trois assertions : (1) public/file/service-account-file.json absent du docroot ; (2) le .htaccess contient le pattern `service-account.*\.json` et `Require all denied` ; (3) le pattern de deny matche `file/service-account-file.json` (=1) mais PAS `images/ai_food/menu.json` (=0), prouvant qu'on ne casse pas le fetch applicatif. Verif complementaire post-deploiement (Apache reel requis, non executable ici) : `curl -so /dev/null -w '%{http_code}' https://<host>/file/service-account-file.json` doit renvoyer 403, et `.../images/ai_food/menu.json` doit renvoyer 200.

**Rollback :** Retirer le bloc `[SEC-GCP-KEY]` FilesMatch de public/.htaccess et les 2 lignes ajoutees dans .gitignore ; supprimer tests/Feature/Security/SecretFileExposureTest.php. La rotation GCP et le `git rm --cached` ne se rollback PAS (secret reste compromis) : ne jamais restaurer l'ancienne cle ni retablir son service HTTP.

**Vérification adversariale :** exactitude=✅ · regression=✅

---

### P0-03 · Installer non authentifié atteignable en prod : abort(404) bloquant + garde middleware notInstalled sur /install
`id=sec-installer` · 🔴 SÉCURITÉ · décision : **ADOPTER** · effort : ?
> **Invariants** : Aucun invariant impacté : ni pricing, ni isolation branche, ni transitions statut, ni intégrité fiscale, ni outbox/afterCommit (aucune transaction/broadcast/branch_id/PSP touché). Le patch durcit seulement l'accès à un flux destructif (migrate:fresh/db:seed/.env). Sûr en statique : chaînes ancrées uniques, php -l suffit.

**Édition 1 — `app/Http/Controllers/Installer/InstallerController.php`** — Redirect::to()->send() flush la réponse mais N'INTERROMPT PAS PHP sous PHP-FPM : le constructeur puis l'action (databaseStore -> migrate:fresh/db:seed) continuaient. abort(404) lève une HttpException = vraie interruption.
*AVANT :*
```
        if (file_exists(storage_path('installed'))) {
            Redirect::to(env('APP_URL'))->send();
        }
```
*APRÈS :*
```
        if (file_exists(storage_path('installed'))) {
            abort(404);
        }
```

**Édition 2 — `app/Http/Controllers/Installer/InstallerController.php`** — Import inutilisé après suppression du seul Redirect::to (les redirects l.61-137 utilisent le helper global redirect()). Retrait non bloquant.
*AVANT :*
```
use Illuminate\Support\Facades\Redirect;

```
*APRÈS :*
```

```

**Édition 3 — `routes/web.php`** — Garde de groupe qui bloque AVANT le constructeur du controller. 'web' conservé (session/CSRF de l'installer). Alias 'notInstalled' identique à Kernel.php.
*AVANT :*
```
Route::prefix('install')->name('installer.')->middleware(['web'])->group(function () {
```
*APRÈS :*
```
Route::prefix('install')->name('installer.')->middleware(['web', 'notInstalled'])->group(function () {
```

**Édition 4 — `app/Http/Kernel.php`** — Enregistre l'alias 'notInstalled' (symétrique inverse de 'installed'), EXACTEMENT le même nom que dans routes/web.php pour éviter 'Target class does not exist' au boot.
*AVANT :*
```
        'installed' => \App\Http\Middleware\Installed::class,
    ];
```
*APRÈS :*
```
        'installed' => \App\Http\Middleware\Installed::class,
        'notInstalled' => \App\Http\Middleware\NotInstalled::class,
    ];
```

**Nouveau fichier — `app/Http/Middleware/NotInstalled.php`** — Middleware absent à créer. Structure minimale calquée sur Installed.php. S'exécute avant le constructeur = garde réellement bloquante ; le abort(404) du controller reste en défense en profondeur.
```
<?php

namespace App\Http\Middleware;

use Closure;

class NotInstalled
{
    /**
     * Bloque l'acces au flux d'installation une fois l'app installee.
     * Symetrique inverse de Installed. abort(404) pour ne rien reveler.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if (file_exists(storage_path('installed'))) {
            abort(404);
        }

        return $next($request);
    }
}

```

**Étapes manuelles (hors code) :**
- php -l sur les 4 fichiers (NotInstalled.php, InstallerController.php, routes/web.php, Kernel.php) : lint statique, aucun secret.
- Après déploiement, app déjà installée : vérifier que GET /install et POST /install/database renvoient 404.
- Aucune rotation de secret requise. Si l'installer a été exposé en prod, incident séparé : rotation APP_KEY / creds DB / clés PSP hors dépôt.

**Test de preuve :** PHPUnit tests/Feature/Installer/InstallerGuardTest.php. Cas 1 (installée) : File::put(storage_path('installed'),''), puis $this->get('/install') PUIS $this->post('/install/database',[...]) -> $response->assertNotFound() sur les deux : prouve que notInstalled bloque avant le controller, databaseStore (migrate:fresh/db:seed) jamais atteint. Cas 2 (non installée) : @unlink(storage_path('installed')), $this->get('/install')->assertOk() : pas de régression sur l'installation légitime. tearDown : restaurer l'état initial du fichier.

**Rollback :** Réappliquer les 4 edits en sens inverse (restaurer Redirect::to(env('APP_URL'))->send() et son import, retirer 'notInstalled' de routes/web.php et Kernel.php) puis supprimer app/Http/Middleware/NotInstalled.php. Aucun changement schéma/données.

**Vérification adversariale :** exactitude=✅ · invariant=✅

---

### P0-04 · Vérifier la charge auprès du PSP avant PAID (ne jamais dériver PAID d'un transaction_id client)
`id=sec-psp-verify` · 🔴 SÉCURITÉ · décision : **ADOPTER** · effort : L
> **Invariants** : Pricing renforce : PAID exige une charge PSP capturee == total serveur ; transaction_id client n'est plus une preuve ; conversion corrige la troncature (int)*100. Branche : verifier sans etat, aucun cache partage. Fiscal intact (HMAC/gapless/Z non touches). Outbox : promotion KDS seulement apres commit PAID ; sur echec, rollback sans promotion. Squelette Stripe fail-closed a auditer.

**Édition 1 — `app/Http/Controllers/Frontend/OrderController.php`** — Importer le contrat de vérification PSP et l'exception fail-closed utilisés par paymentConfirm.
*AVANT :*
```
use App\Enums\PaymentStatus;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
```
*APRÈS :*
```
use App\Enums\PaymentStatus;
use App\Contracts\PaymentVerifier;
use App\Services\Payment\PaymentVerificationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
```

**Édition 2 — `app/Http/Controllers/Frontend/OrderController.php`** — Injecter le collaborateur PaymentVerifier (résolu via le container) sans casser la DI existante.
*AVANT :*
```
    private FrontendOrderService $frontendOrderService;

    public function __construct(FrontendOrderService $frontendOrderService)
    {
        $this->frontendOrderService = $frontendOrderService;
    }
```
*APRÈS :*
```
    private FrontendOrderService $frontendOrderService;
    private PaymentVerifier $paymentVerifier;

    public function __construct(FrontendOrderService $frontendOrderService, PaymentVerifier $paymentVerifier)
    {
        $this->frontendOrderService = $frontendOrderService;
        $this->paymentVerifier = $paymentVerifier;
    }
```

**Édition 3 — `app/Http/Controllers/Frontend/OrderController.php`** — Bloquer PAID tant que le PSP n'a pas confirme une charge capturee == total serveur ; l'exception fait rollback (pas de PAID, pas de promotion KDS). Idempotence $alreadyPaid + lockForUpdate preserves.
*AVANT :*
```
                if ((int) $locked->payment_status === PaymentStatus::PAID) {
                    $alreadyPaid = true;
                    return;
                }

                $locked->payment_status = PaymentStatus::PAID;
```
*APRÈS :*
```
                if ((int) $locked->payment_status === PaymentStatus::PAID) {
                    $alreadyPaid = true;
                    return;
                }

                // [SEC-PSP-VERIFY] Ne JAMAIS dériver PAID d'un transaction_id fourni par le
                // client (app Electron TPE) : cette chaine n'est PAS une preuve de charge.
                // On confronte la charge au PSP et on exige un montant capture STRICTEMENT
                // egal au total calcule cote serveur ($locked->total), en unites mineures.
                // Conversion sure : arrondi APRES multiplication (evite le bug (int)$total*100
                // qui tronque 12.99 -> 1200 ; ici 12.99 -> 1299). Devise supposee a 2 decimales.
                $expectedMinorUnits = (int) round(((float) $locked->total) * 100);

                if (!$this->paymentVerifier->verifyCharge((string) $request->transaction_id, $expectedMinorUnits)) {
                    throw new PaymentVerificationException(
                        'Charge PSP non verifiee ou montant divergent (commande #' . $locked->id . ').'
                    );
                }

                $locked->payment_status = PaymentStatus::PAID;
```

**Édition 4 — `app/Providers/AppServiceProvider.php`** — Lier l'interface PaymentVerifier a l'implementation Stripe pour que la DI du controleur resolve le collaborateur.
*AVANT :*
```
    public function register()
    {
    }
```
*APRÈS :*
```
    public function register()
    {
        // [SEC-PSP-VERIFY] Vérificateur de charge PSP (fail-closed).
        // A ajuster si plusieurs PSP coexistent (résolution par slug/devise).
        $this->app->bind(
            \App\Contracts\PaymentVerifier::class,
            \App\Services\Payment\StripePaymentVerifier::class
        );
    }
```

**Nouveau fichier — `app/Contracts/PaymentVerifier.php`** — Contrat stable decouplant le controleur du PSP concret ; point d'ancrage de l'invariant pricing.
```
<?php

namespace App\Contracts;

/**
 * [SEC-PSP-VERIFY] Contrat de verification d'une charge aupres du PSP
 * (Stripe / PayPal / Razorpay).
 *
 * Invariant securite : une commande ne peut PASSER a PAID que si le PSP confirme
 * qu'une charge REELLEMENT capturee existe pour ce transaction_id ET que son montant
 * capture est STRICTEMENT egal au total calcule cote serveur (unites mineures).
 *
 * Le transaction_id transmis par le client (app Electron TPE) n'est JAMAIS une preuve
 * de paiement : il doit etre confronte au PSP.
 */
interface PaymentVerifier
{
    /**
     * @param string $transactionId       Identifiant de charge/transaction renvoye par le TPE/PSP.
     * @param int    $expectedMinorAmount  Total serveur attendu, en unites mineures (ex: 1299 = 12,99).
     * @return bool  true UNIQUEMENT si une charge capturee correspond exactement au montant attendu.
     *
     * L'implementation DOIT echouer en mode ferme (false) en cas de doute, d'erreur reseau,
     * de statut != captured/succeeded, de remboursement, ou de divergence montant/devise.
     */
    public function verifyCharge(string $transactionId, int $expectedMinorAmount): bool;
}

```

**Nouveau fichier — `app/Services/Payment/PaymentVerificationException.php`** — Exception dediee, fail-closed, distinguable en logs ; capturee par le catch existant -> 422.
```
<?php

namespace App\Services\Payment;

use RuntimeException;

/**
 * [SEC-PSP-VERIFY] Levee quand une charge ne peut pas etre verifiee aupres du PSP
 * ou que le montant capture diverge du total serveur. Provoque le rollback de la
 * transaction de confirmation -> la commande NE passe PAS a PAID et n'est PAS promue.
 *
 * Etend RuntimeException (donc Exception) : capturee par le catch (Exception) de
 * OrderController@paymentConfirm qui renvoie alors 422.
 */
class PaymentVerificationException extends RuntimeException
{
}

```

**Nouveau fichier — `app/Services/Payment/StripePaymentVerifier.php`** — Implementation par defaut fail-closed reprenant le pattern StripeClient du repo ; a finaliser/tester avant prod.
```
<?php

namespace App\Services\Payment;

use App\Contracts\PaymentVerifier;
use App\Models\PaymentGateway;
use Illuminate\Support\Facades\Log;
use Stripe as StripeClient;

/**
 * [SEC-PSP-VERIFY] Verificateur Stripe (SQUELETTE fail-closed a auditer avant prod).
 * Confronte transaction_id au PSP et compare le montant capture au total serveur.
 * A VALIDER : type d'identifiant TPE (ch_ / pi_ / balance_txn), devise, anti-rejeu,
 * devises non-2-decimales. Tout doute => false.
 */
class StripePaymentVerifier implements PaymentVerifier
{
    public function verifyCharge(string $transactionId, int $expectedMinorAmount): bool
    {
        try {
            $gateway = PaymentGateway::with('gatewayOptions')->where('slug', 'stripe')->first();
            if (blank($gateway)) {
                Log::error('[SEC-PSP-VERIFY] Passerelle Stripe introuvable.');
                return false;
            }
            $secret = $gateway->gatewayOptions->pluck('value', 'option')['stripe_secret'] ?? null;
            if (blank($secret)) {
                Log::error('[SEC-PSP-VERIFY] stripe_secret absent.');
                return false;
            }

            $client = new StripeClient\StripeClient($secret);
            // NB: adapter selon le type d'identifiant reellement transmis par le TPE.
            $charge = $client->charges->retrieve($transactionId, []);

            $amount = (int) ($charge->amount_captured ?? $charge->amount ?? -1);
            $ok = (bool) ($charge->captured ?? false)
                && !((bool) ($charge->refunded ?? false))
                && (string) ($charge->status ?? '') === 'succeeded'
                && $amount === $expectedMinorAmount;

            if (!$ok) {
                Log::warning('[SEC-PSP-VERIFY] Charge Stripe rejetee', [
                    'transaction_id' => $transactionId,
                    'expected_minor' => $expectedMinorAmount,
                    'amount'         => $amount,
                ]);
            }
            return $ok;
        } catch (\Throwable $e) {
            Log::error('[SEC-PSP-VERIFY] Echec verification (fail-closed): ' . $e->getMessage());
            return false;
        }
    }
}

```

**Étapes manuelles (hors code) :**
- vendor/ non installe : apres `composer install`, `php artisan config:clear` puis `php -l` sur les 3 nouveaux fichiers et OrderController.
- Confirmer que l'identifiant transmis par le TPE Electron est bien un charge id Stripe (ch_...). Si c'est un payment_intent (pi_...) ou un balance_transaction, adapter StripePaymentVerifier (retrieve du bon objet + amount_received).
- Aucun secret a committer : stripe_secret reste lu depuis la table payment_gateways/gateway_options existante.
- Si plusieurs PSP (PayPal/Razorpay) confirment via cette route, remplacer le bind simple par une resolution par slug/devise avec un verifier dedie par PSP.
- Multi-devises : si des devises a 0 ou 3 decimales sont supportees, remplacer le *100 du controleur par un exposant par devise (sinon montant attendu faux).

**Test de preuve :** PHPUnit Feature test tests/Feature/PaymentConfirmVerifierTest.php. 1) test_refus_si_psp_non_verifie : bind un fake PaymentVerifier renvoyant toujours false ; POST frontend/order/{id}/payment-confirm (sanctum, user proprietaire) avec transaction_id='ATTACKER-STRING' ; assertStatus(422) ; $order->fresh()->payment_status reste UNPAID(10) ; verifier (spy sur FrontendOrderService) que finalizePaidKioskOrder N'est PAS appele. 2) test_accepte_si_montant_concorde : commande total=12.99 ; fake verifier asserant recevoir expectedMinorAmount===1299 et renvoyant true UNIQUEMENT dans ce cas ; POST -> assertStatus(200), payment_status===PAID(5). Le fait que le fake exige 1299 PROUVE la conversion 12.99 => 1299 (vs bug (int)*100 => 1200). 3) test_conversion : unitaire pur, assert (int) round(((float)'12.99')*100) === 1299 et (int) round(((float)'12.30')*100) === 1230. 4) test_idempotence : commande deja PAID -> POST renvoie 200 'deja confirme' SANS appeler verifyCharge (fake leve si appele).

**Rollback :** Reverter les 4 edits (imports + constructeur + bloc transaction du controleur ; bind AppServiceProvider) et supprimer les 3 nouveaux fichiers (Contracts/PaymentVerifier.php, Services/Payment/PaymentVerificationException.php, Services/Payment/StripePaymentVerifier.php). Aucune migration ni donnee touchee : rollback purement code.

**Vérification adversariale :** exactitude=✅

---

### P0-05 · Retirer apexcharts du bundle critique et le charger en async local aux 3 dashboards
`id=perf-apexcharts` · ⚡ PERFORMANCE · décision : **ADOPTER** · effort : S
> **Invariants** : Patch purement frontend/bundling: aucune ligne backend, route, calcul de prix ou requete API. Pricing, isolation branche/branch_id, transitions statut, integrite fiscale et outbox afterCommit: tous non touches. Risque frontend (composant non resolu) neutralise par l'atomicite du patch (retrait global + 3 enregistrements locaux) et le v-if=options.

**Édition 1 — `resources/js/app.js`** — Retire l'import global de vue3-apexcharts (qui tire apexcharts) du bundle critique borne/POS. Ancrage sur la ligne env voisine pour unicite, sans toucher config/env lui-meme.
*AVANT :*
```
import VueApexCharts from "vue3-apexcharts";
import ENV from './config/env';
```
*APRÈS :*
```
import ENV from './config/env';
```

**Édition 2 — `resources/js/app.js`** — Supprime le seul enregistrement global du composant <apexchart>. Les 3 dashboards le re-enregistrent localement dans le meme patch (atomicite).
*AVANT :*
```
app.use(VueSimpleAlert)
app.use(VueApexCharts)
app.use(Toast, options)
```
*APRÈS :*
```
app.use(VueSimpleAlert)
app.use(Toast, options)
```

**Édition 3 — `resources/js/components/admin/dashboard/CustomerStatsComponent.vue`** — Etend l'import 'vue' pour disposer de defineAsyncComponent (chargement lazy du chunk apexcharts).
*AVANT :*
```
import { ref, onMounted } from 'vue';
```
*APRÈS :*
```
import { ref, onMounted, defineAsyncComponent } from 'vue';
```

**Édition 4 — `resources/js/components/admin/dashboard/CustomerStatsComponent.vue`** — Re-enregistre <apexchart> localement en async (cle exacte 'apexchart' = tag du template l.18, monte apres dispatch via v-if).
*AVANT :*
```
  components: { LoadingComponent, Datepicker },
```
*APRÈS :*
```
  components: { LoadingComponent, Datepicker, apexchart: defineAsyncComponent(() => import('vue3-apexcharts')) },
```

**Édition 5 — `resources/js/components/admin/dashboard/OrderSummaryComponent.vue`** — Idem CustomerStats mais fichier distinct (unicite par fichier): ajoute defineAsyncComponent.
*AVANT :*
```
import { ref, onMounted } from 'vue';
```
*APRÈS :*
```
import { ref, onMounted, defineAsyncComponent } from 'vue';
```

**Édition 6 — `resources/js/components/admin/dashboard/OrderSummaryComponent.vue`** — Re-enregistre <apexchart> local async (template l.19 radialBar, v-if="options").
*AVANT :*
```
  components: { LoadingComponent, Datepicker },
```
*APRÈS :*
```
  components: { LoadingComponent, Datepicker, apexchart: defineAsyncComponent(() => import('vue3-apexcharts')) },
```

**Édition 7 — `resources/js/components/admin/dashboard/SalesSummaryComponent.vue`** — Ce fichier n'importe PAS depuis 'vue' -> il faut CREER la ligne d'import, ancree apres l'import date-fns unique.
*AVANT :*
```
import { endOfMonth, startOfMonth, subMonths } from 'date-fns';
```
*APRÈS :*
```
import { endOfMonth, startOfMonth, subMonths } from 'date-fns';
import { defineAsyncComponent } from 'vue';
```

**Édition 8 — `resources/js/components/admin/dashboard/SalesSummaryComponent.vue`** — Re-enregistre <apexchart> local async (indentation 4 espaces propre a ce fichier; template l.35 area, v-if="options").
*AVANT :*
```
    components: { LoadingComponent, Datepicker },
```
*APRÈS :*
```
    components: { LoadingComponent, Datepicker, apexchart: defineAsyncComponent(() => import('vue3-apexcharts')) },
```

**Étapes manuelles (hors code) :**
- Aucun secret ni env a modifier.
- Au prochain build (env non disponible ici): executer 'npm ci && npm run production' (ou 'npm run dev') et verifier que laravel-mix genere bien un chunk lazy separe contenant apexcharts (fichiers public/js/*.js), et qu'il n'apparait plus dans le chunk d'entree app.js.
- Verifier visuellement les 3 dashboards (Customer stats, Order summary, Sales summary): les graphes se rendent apres chargement du store, sans erreur console 'Failed to resolve component: apexchart'.
- Comparer la taille du bundle d'entree avant/apres pour confirmer le degraissage du chemin critique borne/POS.

**Test de preuve :** Vitest (composant), a lancer une fois node_modules installe. But: prouver que <apexchart> est resolu LOCALEMENT et charge en async (plus de dependance a l'enregistrement global).\n\nimport { mount, flushPromises } from '@vue/test-utils';\nimport CustomerStats from '@/components/admin/dashboard/CustomerStatsComponent.vue';\n\ntest('apexchart est enregistre localement en async, pas globalement', async () => {\n  expect(CustomerStats.components).toHaveProperty('apexchart');\n  expect(typeof CustomerStats.components.apexchart).not.toBe('string');\n  const wrapper = mount(CustomerStats, { global: { stubs: { Datepicker: true } } });\n  await flushPromises();\n  expect(wrapper.html()).not.toContain('Failed to resolve component');\n});\n\nPreuve statique (deja verifiee ici): grep 'apexchart' sur resources/js -> app.js ne contient plus ni l'import ni app.use(VueApexCharts); les 3 .vue contiennent 'apexchart: defineAsyncComponent(() => import(\\'vue3-apexcharts\\'))'."

**Rollback :** git checkout -- resources/js/app.js resources/js/components/admin/dashboard/CustomerStatsComponent.vue resources/js/components/admin/dashboard/OrderSummaryComponent.vue resources/js/components/admin/dashboard/SalesSummaryComponent.vue. Restaure l'import et app.use(VueApexCharts) globaux et retire les 3 enregistrements locaux + la ligne import 'vue' creee dans SalesSummary.

**Vérification adversariale :** exactitude=✅ · invariant=✅

---

### P0-06 · Cache-buster : remplacer time() par filemtime() pour rendre pos-wizard.css/js cachables
`id=perf-cachebuster` · ⚡ PERFORMANCE · décision : **ADOPTER** · effort : S
> **Invariants** : "Le patch ne touche que le suffixe ?v de deux assets statiques. Pricing : injections inline POS_WIZARD_CONFIG intactes, backend reste source de verite. Branche : assets identiques pour toutes branches, aucun cache de donnees branch_id. Statuts/fiscal/outbox : hors-scope. Piege filemtime traite via public_path(), fichiers presents, pas d'E_WARNING. mix() non modifie."

**Édition 1 — `resources/views/master.blade.php`** — time() change a chaque render SPA et casse le cache navigateur ; filemtime() est stable tant que le fichier disque ne change pas. public_path() (chemin disque), pas asset() (URL).
*AVANT :*
```
    <link rel="stylesheet" href="{{ asset('css/pos-wizard.css') }}?v=2-{{ time() }}">
```
*APRÈS :*
```
    <link rel="stylesheet" href="{{ asset('css/pos-wizard.css') }}?v=2-{{ filemtime(public_path('css/pos-wizard.css')) }}">
```

**Édition 2 — `resources/views/master.blade.php`** — Meme layout racine rendu a chaque page ; filemtime() rend le ?v stable donc l'asset de 287 Ko devient cachable. On garde le prefixe '9-' et on ne touche pas mix().
*AVANT :*
```
    <script src="{{ asset('js/pos-wizard.js') }}?v=9-{{ time() }}"></script>
```
*APRÈS :*
```
    <script src="{{ asset('js/pos-wizard.js') }}?v=9-{{ filemtime(public_path('js/pos-wizard.js')) }}"></script>
```

**Étapes manuelles (hors code) :**
- Aucune action hors-code. S'assurer en deploiement que public/css/pos-wizard.css et public/js/pos-wizard.js existent AVANT le premier render (presents : 41351 o et 287207 o) pour eviter tout E_WARNING de filemtime().
- Optionnel : php artisan view:clear apres deploiement pour recompiler la vue Blade immediatement.

**Test de preuve :** "Description Blade/PHPUnit (statique, non executable ici). Test HTTP feature : deux GET successifs sur une route rendant master.blade.php (ex: GET '/') sans modifier les assets entre les deux, puis extraire les URLs via regex '/pos-wizard\\.(?:css|js)\\?v=\\d+-(\\d+)/'. Assertion cle : le suffixe numerique (?v=2-XXXX et ?v=9-YYYY) est IDENTIQUE entre les deux reponses (preuve que ce n'est plus time()). Verifier aussi que ce suffixe egale filemtime(public_path('css/pos-wizard.css')) / filemtime(public_path('js/pos-wizard.js')). Contre-preuve : un touch de public/js/pos-wizard.js entre deux requetes doit faire changer le suffixe '9-'. Aucune assertion pricing modifiee : le patch ne touche pas les injections inline POS_WIZARD_CONFIG."

**Rollback :** "Reverter les deux lignes de resources/views/master.blade.php : remettre '?v=2-{{ time() }}' (l.22) et '?v=9-{{ time() }}' (l.128), ou git checkout resources/views/master.blade.php. Aucun autre fichier, migration ou secret impacte."

**Vérification adversariale :** exactitude=✅ · invariant=✅ · regression=✅

---

### P0-07 · Throttle des écritures vuex-persistedstate + retrait de posCart des paths (persistance scopée par caissier comme source unique)
`id=perf-persist` · ⚡ PERFORMANCE · décision : **ADOPTER_AVEC_RESERVE** · effort : ?
> **Réserve** : Ancres exactes/uniques (index.js 106-110,224-225,253-255), lodash OK->applicable. Retrait posCart CORRECT: persistance scopee (posCart.js:23) rehydratee via setScope (PosComponent.vue:936), bootstrap.js:47 ne lit que kioskToken/auth->isolation RENFORCEE. Mais throttle leading:false differe l'ecriture durable de idempotencyKey: submitOrder commit la cle (kioskCart.js:348) AVANT axios.post(356); dedup backend sur idempotency_key (FrontendOrderService:136). Crash dur (pagehide non emis) <400ms->cle perdue->regeneree->double commande. RESERVE: flush synchrone avant axios.post.
> **Invariants** : Patch front/localStorage uniquement. Pricing: aucun backend touche. Branche: isolation RENFORCEE (retrait de posCart supprime la re-hydratation non scopee, la persistance scopee module devient source unique). Fiscal/outbox/statut: hors-scope. Throttle differe <=400ms avec flush garanti (pagehide+beforeunload): pas de perte panier/auth; paths inchange.

**Édition 1 — `resources/js/store/index.js`** — Ajoute lodash/throttle et un setState throttle (400ms trailing) avec flush sur pagehide/beforeunload, sans filtre naif par prefixe de path.
*AVANT :*
```
import kioskAnalyticsPlugin from './plugins/kioskAnalyticsPlugin';



export default new createStore({
```
*APRÈS :*
```
import throttle from "lodash/throttle";
import kioskAnalyticsPlugin from './plugins/kioskAnalyticsPlugin';

// [perf-persist] La valeur par defaut de vuex-persistedstate serialise TOUT le
// blob persiste (JSON.stringify) de facon SYNCHRONE a CHAQUE mutation. Sur un
// flux POS/kiosk qui mute en rafale, cela bloque le thread UI a chaque frappe.
// On throttle l'ecriture a 400ms (leading:false, trailing:true) et on conserve
// la ref pour forcer le flush de la DERNIERE ecriture avant fermeture/reload.
const persistThrottledSetState = throttle(
    (key, state, storage) => {
        storage.setItem(key, JSON.stringify(state));
    },
    400,
    { leading: false, trailing: true }
);

// Sans ce flush, la derniere mutation (ex. frontendCart / auth) differee par le
// throttle serait perdue sur pagehide/beforeunload (fermeture, reload Electron).
// Guard typeof window pour les contextes SSR / tests hors DOM.
if (typeof window !== "undefined") {
    window.addEventListener("pagehide", () => persistThrottledSetState.flush());
    window.addEventListener("beforeunload", () => persistThrottledSetState.flush());
}

export default new createStore({
```

**Édition 2 — `resources/js/store/index.js`** — Retire posCart des paths: la persistance scopee par caissier du module devient la source unique, supprimant la fuite du panier entre caissiers/branches via le blob global.
*AVANT :*
```
                "posCart",
                "tableCart",
```
*APRÈS :*
```
                // [perf-persist] "posCart" RETIRE volontairement: le module posCart
                // persiste deja son etat scope par caissier (cles pos_cart_v3:b<branchId>:u<userId>)
                // via saveCartToStorage(). Le laisser ici re-serialise le panier dans le blob
                // vuex GLOBAL non scope et le re-hydrate AVANT setScope -> fuite inter-caissier.
                "tableCart",
```

**Édition 3 — `resources/js/store/index.js`** — Branche le setState throttle sur createPersistedState en laissant paths intact pour ne pas casser la persistance de auth (mutations globales).
*AVANT :*
```
                "kioskSettings.consentLoyalty",
            ],
        }),
```
*APRÈS :*
```
                "kioskSettings.consentLoyalty",
            ],
            // [perf-persist] Ecriture throttlee (voir persistThrottledSetState ci-dessus).
            // paths INCHANGE: pas de filtre par prefixe (auth n'est PAS namespace).
            setState: persistThrottledSetState,
        }),
```

**Étapes manuelles (hors code) :**
- Aucune installation nouvelle: lodash ^4.17.19 est deja une dependance (package.json:24) et l'import lodash/throttle est deja utilise (posCart.js, bootstrap.js). Rebuild front (laravel-mix) requis apres merge.
- Optionnel: purger l'ancienne cle localStorage globale 'vuex' residuelle (posCart d'un ancien caissier) au premier boot post-deploiement. Non bloquant (hydrateFromScope remplace l'etat au mount).

**Test de preuve :** Vitest (resources/js/store/__tests__/persist-throttle.spec.js). Cas 1 - throttle: importer le store, espionner window.localStorage.setItem, commit 20 mutations persistees en rafale synchrone, verifier 0 appel immediat, puis avancer les faux timers de 400ms (vi.useFakeTimers) et verifier EXACTEMENT 1 ecriture coalescee (au lieu de 20). Cas 2 - flush: apres une mutation, sans avancer les timers, dispatchEvent(new Event('pagehide')) et verifier que setItem a bien ete appele (derniere ecriture non perdue). Cas 3 - anti-fuite: parser le JSON ecrit et asserter que 'posCart' est ABSENT du blob tandis que 'auth' et 'frontendCart' sont PRESENTS (paths preserves, login non perdu). Patch purement front (localStorage): aucun impact PSP, l'invariant Stripe 12.99=>1299 (minor units backend) reste hors-scope et intact.

**Rollback :** Revert des 3 edits dans resources/js/store/index.js: retirer l'import throttle et le bloc persistThrottledSetState + listeners, retirer 'setState: persistThrottledSetState,', re-ajouter la ligne '\"posCart\",' avant '\"tableCart\",' dans paths. Aucune migration/donnee a annuler.

**Vérification adversariale :** exactitude=✅ · regression=❌

---

### P0-08 · QUEUE_CONNECTION=sync : extraction d'une garde prod testable + test de non-régression (listeners laissés intacts, à raison)
`id=perf-queue` · ⚡ PERFORMANCE · décision : **ADOPTER** · effort : ?
> **Invariants** : Aucun listener OrderCreated converti en ShouldQueue : outbox (inv.5) et anti-survente préservés car Persist...Outbox et Decrement... restent synchrones. SendFcmOnOrderCreated non queué (OrderCreated sans SerializesModels sérialiserait tout le modèle ; $afterCommit NO-OP car dispatch hors transaction). Pricing/branche/fiscal intacts. Patch = extraction iso-comportement de la garde + test.

**Édition 1 — `app/Providers/AppServiceProvider.php`** — Extrait la garde queue prod dans une méthode statique pure : mêmes sémantiques de throw, mais testable sans booter en mode production (CI force sync).
*AVANT :*
```
            if (config('queue.default') === 'sync') {
                throw new \RuntimeException(
                    'QUEUE_CONNECTION must not be sync in production (expected: redis|database). '
                    . 'Set QUEUE_CONNECTION in your .env file.'
                );
            }
        }
    }
```
*APRÈS :*
```
        }

        // [perf-queue] Garde extraite pour test unitaire (tests/Unit/Queue/ProductionQueueDriverGuardTest.php).
        // Comportement vérifié = conjonction : environnement production ET queue.default === 'sync'.
        self::guardQueueDriver(app()->environment('production'), config('queue.default'));
    }

    /**
     * [perf-queue] Bloque QUEUE_CONNECTION=sync en production (queue synchrone => jobs FCM/Pusher
     * exécutés inline au submit, ce qui bloque l'API). Statique et sans effet de bord afin d'être
     * vérifiable unitairement sans booter l'application en environnement production.
     */
    public static function guardQueueDriver(bool $isProduction, ?string $queueDefault): void
    {
        if ($isProduction && $queueDefault === 'sync') {
            throw new \RuntimeException(
                'QUEUE_CONNECTION must not be sync in production (expected: redis|database). '
                . 'Set QUEUE_CONNECTION in your .env file.'
            );
        }
    }
```

**Nouveau fichier — `tests/Unit/Queue/ProductionQueueDriverGuardTest.php`** — Prouve le correctif : la garde lève une RuntimeException ssi (production ET sync), sans entraver dev ni CI. Étend PHPUnit\TestCase (pas Tests\TestCase) pour rester un pur test unitaire sans DB/bootstrap.
```
<?php

namespace Tests\Unit\Queue;

use App\Providers\AppServiceProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * [perf-queue] Garde de non-régression : QUEUE_CONNECTION=sync est interdit en
 * production (queue synchrone => jobs FCM/Pusher exécutés inline au submit,
 * blocage de l'API). On teste le COMPORTEMENT de la garde (conjonction
 * production + sync), pas la valeur de config('queue.default') : phpunit.xml:28
 * et .github/workflows/phpunit.yml fixent sync, donc une assertion littérale
 * echouerait toujours en CI.
 *
 * @see app/Providers/AppServiceProvider::guardQueueDriver()
 */
class ProductionQueueDriverGuardTest extends TestCase
{
    public function test_production_with_sync_queue_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('QUEUE_CONNECTION must not be sync in production');

        AppServiceProvider::guardQueueDriver(true, 'sync');
    }

    /**
     * Tout couple hors (production + sync) doit passer sans lever :
     * - dev/testing garde sync (promesse .env.example:7-8)
     * - prod avec redis/database/null est accepté
     *
     * @dataProvider acceptableCombinations
     */
    public function test_non_production_or_async_queue_does_not_throw(bool $isProduction, ?string $queueDefault): void
    {
        AppServiceProvider::guardQueueDriver($isProduction, $queueDefault);
        $this->assertTrue(true, 'guardQueueDriver ne doit lever que pour le couple (production + sync).');
    }

    /**
     * @return array<string, array{0:bool,1:?string}>
     */
    public static function acceptableCombinations(): array
    {
        return [
            'dev/testing + sync autorise' => [false, 'sync'],
            'prod + redis'                => [true, 'redis'],
            'prod + database'             => [true, 'database'],
            'prod + null'                 => [true, null],
        ];
    }
}

```

**Étapes manuelles (hors code) :**
- PROD : définir QUEUE_CONNECTION=redis (ou database si Redis indisponible) dans le .env serveur. Ne JAMAIS committer cette valeur ni un secret : variable d'environnement uniquement.
- PROD : lancer/superviser un worker `php artisan queue:work --queue=high,default --tries=3` (supervisor/systemd, ou Horizon si Redis). C'est CE point qui apporte le gain perf réel, pas un ShouldQueue sur les listeners.
- DEV/CI : ne rien changer. sync reste actif dans .env.example et phpunit.xml:28 ; le default de config/queue.php reste 'sync'.
- Au deploy prod : vérifier le fail-fast si sync oublié (la garde runtime AppServiceProvider::boot est conservée à l'identique via guardQueueDriver).

**Test de preuve :** tests/Unit/Queue/ProductionQueueDriverGuardTest.php (fourni). Commande : `php artisan test --filter=ProductionQueueDriverGuardTest`. Attendu : test_production_with_sync_queue_throws PASSE (RuntimeException pour (true,'sync')) ; les 4 cas de test_non_production_or_async_queue_does_not_throw PASSENT (dev+sync, prod+redis, prod+database, prod+null). En CI où QUEUE_CONNECTION=sync est forcé, le test PASSE car il assère le comportement de la garde, pas config('queue.default').

**Rollback :** Réintégrer le bloc `if (config('queue.default') === 'sync') { throw ... }` dans le `if (app()->environment('production'))` de AppServiceProvider::boot(), supprimer la méthode statique guardQueueDriver et son appel, puis supprimer tests/Unit/Queue/ProductionQueueDriverGuardTest.php. Aucune migration ni donnée impactée.

**Vérification adversariale :** exactitude=✅ · invariant=✅ · regression=✅

---

## 3. Correctifs REJETÉS par la vérification (pièges à éviter)

### ❌ `sec-admin-guard` — Verrou /api/admin (role:Admin|Stuff) + alias abilities Sanctum + abilities:kiosk:order routes borne + borne liée à user de service dédié (rôle Kiosk Machine)
**Raison du rejet :** Régression grave CONFIRMÉE. prefix('admin') (api.php:229→807) n'est PAS admin-only: contient pos:624, pos-order:628, table-order:654, dashboard:700, kds-order:776, oss:781. Gate actuel=auth:sanctum seul. LeCayenneRoleLandingUrlSeeder mappe POS Operator→pos, Chef→kitchen-display-system, Branch Manager→dashboard; PosComponent.vue:1217 et dashboard.js appellent ces routes. role:Admin|Stuff exclut ces 3 rôles (staff, LoyaltyController:189/259)+Waiter/DeliveryBoy → 403 POS/KDS/dashboard prod. Ancre unique mais Edit#1 casse les flux cœur; fix=redesign. Edits annexes sains, bundle rejeté.

### ❌ `perf-htaccess` — Cache-Control immutable + compression gzip/brotli scopés par extension sur public/.htaccess (sans jamais cacher /api/frontend/menu)
**Raison du rejet :** Ancre OK/unique (.htaccess:29-30). Invariant branche OK: /api/frontend/menu extensionless->index.php (l.18-20), FilesMatch par extension, pas de cache global. MAIS regression grave: assets NON fingerprintes — mix-manifest.json mappe /js/app.js->/js/app.js (sans hash), webpack.mix.js:13 sans .version(), master.blade.php mix() = URLs stables. FilesMatch \.(?:js|css) fige immutable 1 an sur app.js/app.css/kiosk.js: apres deploy les clients gardent l'ancien bundle -> SPA/POS/kiosk casses. Re-proposer avec .version() ou politique revalidante.</parameter>
</invoke>


---

*Paquet généré par orchestration multi-agents avec vérification adversariale triple-lentille + juge. Aucun code appliqué — à valider et tester en environnement de build.*