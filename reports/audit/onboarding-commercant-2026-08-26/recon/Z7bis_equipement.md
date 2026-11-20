# Z7bis — Équipement & opérations, vérification ciblée (W1 ONB-10) — 2026-08-27

> Lecture seule absolue. Aucune écriture, aucune requête mutante. Confirme/complète `recon/Z7_equipement_ops.md`
> par lecture directe du code sur le worktree `.claude/worktrees/goal-onboarding-commercant-2026-08-26` (serveur :8800).
> Question directrice : « Je branche ma borne et mon imprimante. Est-ce que ça marche, et comment je le sais ? »

## 1. LA RÉVOCATION — les trois chemins Dashboard, vérifiés ligne par ligne

Fichier lu intégralement : `app/Services/KioskMachineService.php` (198 lignes). Aucun `use` de `PersonalAccessToken`,
`DeviceTokenService`, ni référence à `personal_access_tokens` dans tout le fichier. Le contrôleur appelant
(`app/Http/Controllers/Admin/KioskMachineController.php`) ne touche pas non plus aux jetons (grep confirmé : 0 occurrence).

| Chemin Dashboard | file:line | Que fait le code | Verdict |
|---|---|---|---|
| **Supprimer une borne** | `KioskMachineService.php:108-129` (`destroy`) | Notification push Firebase (best-effort) puis `$kioskMachine->delete()` — supprime la ligne `kiosk_machines`, ne touche à aucune ligne `personal_access_tokens` | **NE RÉVOQUE PAS** |
| **Désactiver une borne** (changement de statut) | `KioskMachineService.php:147-171` (`changeStatus`) | `$kioskMachine->update(['status' => ...])` + notification push Firebase | **NE RÉVOQUE PAS** |
| **« Déconnecter » depuis le Dashboard** | `KioskMachineService.php:176-197` (`logout`) | Notification push Firebase + `$kioskMachine->update(['is_login' => Ask::NO])` — un simple booléen d'affichage | **NE RÉVOQUE PAS** |

AUCUNE RÉVOCATION dans les trois chemins. Le jeton Sanctum créé au login de la borne (`kiosk-token`, ability
`kiosk:order`, `device_id = 'kiosk-<id>'`, TTL `sanctum.expiration` = 480 min) reste valide jusqu'à expiration
naturelle (8 h) quel que soit ce que fait l'admin dans le Dashboard.

**Seul chemin qui révoque réellement** : `POST /api/auth/kiosk-logout` (`KioskMachineLoginController.php:134-170`), un
endpoint appelé PAR LA BORNE ELLE-MÊME avec son propre jeton courant (`$request->user()->currentAccessToken()`) —
`$current->delete()` à la ligne 163. Ce n'est PAS un des trois chemins Dashboard : une borne volée, éteinte de force ou
dont le réseau est coupé n'appellera jamais cet endpoint, donc son jeton continue de fonctionner jusqu'à 8 h.
Confirme exactement le P1 déjà mesuré par Z7 (`api-5-results.json` : borne supprimée id=85, `personal_access_tokens`
pour `device_id='kiosk-85'` = 1 avant purge manuelle).

Création du jeton (pour référence) : `KioskMachineLoginController.php:112-123`, via
`App\Services\Auth\DeviceTokenService::issueForDevice(...)`.

## 2. L'IMPRESSION — ce que prouve réellement le test

- Route : `routes/api.php:1336` → `PrinterController::testPrint` (`app/Http/Controllers/Admin/PrinterController.php:77-85`),
  gardée `permission:pos` (`:19`).
- `testPrint()` appelle `EscPosPrinterService::testPrint()` (`app/Services/Hardware/EscPosPrinterService.php:50-79`) qui
  construit une trame ESC/POS réelle puis délègue à `sendRaw()` (`:16-45`), qui appelle
  `$this->transport->send($bytes, [...])`.
- Le transport injecté dépend de `printing.bypass.enabled` : `app/Providers/AppServiceProvider.php:34-40` bind
  `NullPrinterTransport` si `env('APP_ENV')==='testing'` OU `config('printing.bypass.enabled')` est vrai.
- **`NullPrinterTransport::send()`** (`app/Services/Hardware/PrinterTransport/NullPrinterTransport.php:9-17`) ne fait
  RIEN — pas de socket, pas d'octet réseau. Il stocke la trame en mémoire (`$this->sent[]`, propriété PHP jamais
  exposée à l'appelant) et retourne **`true` inconditionnellement**, quel que soit `host`/`port`, même vers un hôte
  inexistant.
- **`TcpPrinterTransport::send()`** (`app/Services/Hardware/PrinterTransport/TcpPrinterTransport.php:9-44`) — le
  transport réel (bypass OFF) — fait un vrai `fsockopen()` + `fwrite()` et ne renvoie `true` que si tous les octets
  sont écrits ; c'est un vrai signal (mais uniquement TCP-accepté, pas confirmation papier imprimé).
- **État vérifié sur ce worktree** : `.env:82` → `PRINTING_BYPASS_MODE=true`. Donc sur ce serveur (:8800 comme :8766),
  cliquer « Tester l'impression » sur N'IMPORTE QUELLE imprimante — même une adresse qui n'existe pas — renvoie
  `HTTP 200 {"ok": true, "printer_id": N}`. **Le bouton ne prouve rien ici : ni octet envoyé, ni papier sorti.**
  `PRINTING_BYPASS_MODE=true` est bloqué au boot en production (`AppServiceProvider.php:233-236`, RuntimeException),
  donc ce menteur est structurellement impossible en prod — mais actif par défaut en dev/staging/démo, exactement
  l'environnement où un commerçant testerait sa borne avant ouverture.
- Il n'existe qu'un seul bouton de test (`test-print`), aucune preuve d'octets réellement reçus n'est exposée au
  commerçant (pas de compteur, pas de hash, pas de capture réseau affichée côté UI) — même en mode réel (bypass OFF),
  le commerçant voit seulement « ok » ou une erreur 502, jamais la confirmation que le papier est sorti.

## 3. LA BORNE — parcours d'installation, écran par écran

Un seul écran Dashboard concerné : `/admin/settings/kiosk-machines/list` (`KioskMachineListComponent.vue`) + sa
modale unique de création (`KioskMachineCreateComponent.vue`, 214 lignes, lue intégralement).

**Étapes réelles (1 écran, pas d'assistant multi-écrans)** :
1. Ouvrir « Bornes » → bouton « Ajouter une borne » → une SEULE modale (pas de wizard par étapes) avec 6 champs :
   Utilisateur (select), ID Machine (texte libre), Nom d'utilisateur (texte), Mot de passe (texte), Établissement
   (select), Statut (radio Actif/Inactif) — `KioskMachineCreateComponent.vue:14-92`.
2. « Enregistrer » → `POST kiosk-machine/` (`KioskMachineController::store` → `KioskMachineService::store`,
   `:58-77`) crée la ligne `kiosk_machines` avec le mot de passe bcrypt. **Aucun secret d'appairage généré**, aucun
   lien, aucun QR code, aucune instruction affichée dans la modale (vérifié ligne par ligne : le formulaire se
   ferme, un toast de succès générique s'affiche, `KioskMachineCreateComponent.vue:183-212`).
3. Le lien entre « ce qu'on vient de créer dans le Dashboard » et « ce qui se connecte réellement sur la tablette »
   n'existe PAS dans l'UI. La borne physique s'authentifie soit :
   - via `/kiosk/login` avec le username/password saisis manuellement sur l'appareil (aucun écran ne les affiche
     après création — le mot de passe n'est jamais relu, cf. Z7 §2, confirmé aussi côté `show()` :134-142 qui
     renvoie l'objet Eloquent tel quel mais la ressource API `KioskMachineResource` — non relue ici — filtre déjà
     selon Z7) ;
   - via l'auto-login serveur (`config/kiosk.php:266-283`) qui EN LOCAL ignore totalement la borne créée : si
     `KIOSK_MACHINE_USERNAME`/`KIOSK_MACHINE_PASSWORD` sont absents du `.env`, le code retombe sur l'identité fixe
     `kiosk-lecayenne` / `kiosk123` (`:273-280`), la même pour toutes les bornes, alignée sur
     `KioskMachineTableSeeder` — **pas sur la ligne qu'on vient de créer dans le Dashboard**.
   - Hors `APP_ENV=local` (staging/prod), `docs/KIOSK_DEPLOYMENT.md:15-24` confirme : les credentials machine sont
     **une seule paire globale** dans `.env` (`KIOSK_MACHINE_USERNAME=kiosk-lecayenne`), pas par-borne, et leur
     rotation se fait par `php artisan kiosk:rotate` — un accès serveur, pas un écran Dashboard.

**Résumé** : 1 écran (liste) + 1 modale (1 étape) pour créer la ligne en base ; 0 étape Dashboard pour relier cette
ligne à un appareil physique ; le pontage réel passe par une variable d'environnement unique et partagée
(`.env`), jamais par un secret par-borne visible ou transmis depuis l'écran.

## 4. LE TPE — terminaux configurables et écran

- Un seul écran : `/admin/settings/payment-terminals` (`PaymentTerminalsComponent.vue`), avec un formulaire de
  création/modification unique (pas d'écran séparé par type de terminal).
- Passerelles proposées dans le `<select>` (`PaymentTerminalsComponent.vue:90-98`) : **Ingenico, Verifone, Stripe,
  Senangpay, Manual** — 5 options codées en dur dans le template.
- Ces 5 options correspondent exactement à `PaymentTerminal::GATEWAY_TYPES` (`app/Models/PaymentTerminal.php:38-44`) :
  `stripe, senangpay, ingenico, verifone, manual`. **`simulation` n'existe dans aucune des deux listes.**
- Validation serveur : `PaymentTerminalRequest.php:31` → `'gateway_type' => ['required', 'string',
  Rule::in(PaymentTerminal::GATEWAY_TYPES)]` — confirme que `gateway_type=simulation` est rejeté 422, exactement
  comme mesuré par Z7 `[34]`.
- Le seul TPE réel de Le Cayenne (`payment_terminals` id 1, `gateway_type=simulation` en base — c'est donc une valeur
  qui EXISTE en base mais n'est PAS dans `GATEWAY_TYPES`, un TPE créé hors formulaire/seed) ouvre une modale
  « Modifier » dont le `<select>` n'a pas d'option correspondant à sa valeur réelle → passerelle affichée vide
  (capture Z7 07). Aucun texte de la modale ni de la liste n'indique que ce terminal est simulé.

## 5. LA SANTÉ — `/api/admin/.../system-health`

Il existe **deux endpoits** nommés `system-health`, tous deux sous le groupe `Route::prefix('admin')` ouvert à
`routes/api.php:378` avec middleware `['installed', 'apiKey', 'auth:sanctum', 'block_kiosk_token_admin',
'localization', 'throttle:admin-mutation']` — donc **aucun des deux n'est joignable sans authentification** (jeton
Sanctum valide requis dans les deux cas).

| Endpoint | file:line route | Contrôleur | Middleware additionnel (au-delà du groupe `admin`) | Portée réelle |
|---|---|---|---|---|
| `GET /api/admin/pos/system-health` | `routes/api.php:1158-1160` | `Admin\PosSystemHealthController` | `permission:pos`, `throttle:pos-order-update` | Réservé aux comptes avec la permission `pos` |
| `GET /api/admin/observability/system-health` | `routes/api.php:1664` | `SyncOverviewController::systemHealth` | **AUCUN** — le constructeur (`SyncOverviewController.php:47-64`) applique `permission:kitchen-display-system` uniquement à `index`/`clientMetrics`, et `role:Admin\|Tenant Admin` uniquement aux 3 actions `outbox*`. `systemHealth` n'est listé dans AUCUN des deux `->only(...)`. `AdminController::__construct()` (parent) est vide. | **N'importe quel compte authentifié non-kiosk** (Chef, Waiter, DeliveryBoy, Employee — sans permission ni rôle particulier) peut lire cet endpoint |

Ce que `systemHealth()` mesure vraiment (`SyncOverviewController.php:600-660`, lu intégralement) : relit le cache
`healthz:last` (mêmes contrôles que `/api/healthz`), calcule l'âge du dernier fichier de sauvegarde dans
`storage/backups/db-daily/*.gz` (par `filemtime`), et l'âge du dernier battement `scheduler:last_tick` en cache —
puis assemble un `verdict` (`ok`/`attention`) et une liste d'alertes en français. C'est exactement l'écran « État du
système » du menu (recon Z7 §2.1, `menus` id 33). Rien n'y est inventé, mais c'est un résumé passif : il ne relance
ni le worker ni le planificateur, ne référence aucun bouton d'action.

**Correction/précision par rapport à la formulation de la mission** : ni l'un ni l'autre n'est exposé **sans
authentification** (les deux sont derrière `auth:sanctum`). L'endpoint réellement exposé sans authentification est
un troisième, différent, déjà repéré par Z7 : `GET /api/health` (`routes/api.php:144`, aucun middleware) — voir
`app/Http/Controllers/HealthController.php:14-37` : gardé uniquement par `assertFullHealthIpAllowed()` (:76-89) qui
ne s'applique QUE si `HEALTH_IPS_ALLOWED` est renseigné ; vérifié `.env.testing:162` → vide. Donc `/api/health`
(db/redis/queue/broadcast, version app) est bien public sur ce worktree, mais ce n'est pas la même route que
`/api/admin/.../system-health`. L'écart nouveau que ce Z7bis ajoute par rapport à Z7 : l'endpoint « État du système »
du Dashboard (`/api/admin/observability/system-health`) est authentifié mais **non permission-gated** — un trou
d'autorisation plus fin que « pas d'auth du tout », à traiter dans la fiche G-OUTBOX/ONB-13.

## Rappel — aucune donnée touchée
Aucune requête d'écriture exécutée pendant cette vérification (uniquement `Read`/`grep`/`find` sur le code source).
Les 14 bornes, 3 imprimantes, 2 TPE existants en base n'ont pas été consultés via API ni modifiés.
