# Profil complet de l'application Le Cayenne — rapport de développement (2026-11-12)

**Branches et commits**
- Site / application (dépôt `lecayenne-web-deploy`, worktree `site-profil-2026-11-12`) : branche `app/profil-complet-2026-11-12`, commit `96fe53c` (base `5293d59`). Non poussée, à fusionner par le propriétaire de la branche principale.
- Backend `testttt` : commit `3324c427e` (par chemins, sans push).

**Ordre de déploiement : le backend d'abord.** Le site appelle 3 nouvelles routes (`/address/{id}/default`, `/order/{id}/reorder`, `/newsletter-consent`). En production elles répondent aujourd'hui 404 (vérifié en lecture seule). Si le site part avant le backend, ces trois boutons affichent une erreur au lieu de fonctionner.

---

## 1. Ce qui est fait

### Hub « Mon profil » (👤 « Mon compte » → onglet « Mon compte »), `loyalty-v2.jsx`
Il reprend la structure du précode `mobile/screens-main.jsx` (`ScreenProfile`) avec la charte du site (noir, orange, jaune, crème ; Anton pour les titres) :
- **En-tête** : avatar à initiales, prénom et nom, e-mail, téléphone. **« Modifier »** ouvre une vraie édition du prénom et du nom (`PUT /api/profile`), enregistrée en base. L'e-mail et le téléphone sont affichés sans pouvoir être modifiés ici : le serveur refuse ce changement à un client (W7-D D-003), et l'écran explique pourquoi.
- **Carte fidélité résumée** : solde, progression vers la prochaine réduction, **« Voir mon QR »** (le vrai QR signé par `POST /loyalty/qr`, dans une fenêtre), **« Historique des points »** (ouvre l'onglet Historique). Aucune dépense n'est affichée.
- **Six rubriques**, chacune réelle :
  - Mes commandes → la page des commandes ;
  - Mes adresses ;
  - Notifications ;
  - Paiement ;
  - Aide & contact : `tel:+33365678291`, itinéraire Google Maps ouvert dans le navigateur du système, `mailto:contact@lecayenne.fr`, horaires lus au serveur ;
  - Informations légales : CGV, confidentialité, mentions, cookies.
- **Se déconnecter** et **Supprimer mon compte** : le flux existant (`AccountDangerZone`) est conservé.
- Aucun allergène dans le profil.
- Les quatre onglets fidélité restent en place.

### Carnet d'adresses
- **Backend**
  - Nouvelle colonne `addresses.is_default` (migration ajout seul, idempotente).
  - Nouvelle route `POST /api/frontend/address/{id}/default`, en transaction : une seule adresse par défaut à la fois.
  - La première adresse d'un client devient son adresse par défaut. Si on supprime l'adresse par défaut, la plus récente prend le relais.
  - **Renommer** une adresse sans changer son texte ne rappelle pas le géocodeur. Les coordonnées envoyées par le client sont ignorées.
  - Les messages d'erreur sont en français.
  - **Correctif** : un compte Apple qui a déclaré un numéro (`contact_phone`) peut maintenant enregistrer une adresse. Avant, ce n'était possible ni dans le profil ni au paiement en livraison.
- **Écran** : lister, ajouter (Maison / Travail / Autre + complément), modifier, renommer, supprimer (avec confirmation), choisir l'adresse par défaut. Le serveur géocode chaque adresse (Base Adresse Nationale) ; le client ne saisit aucune coordonnée.
- **Au paiement en livraison** (`funnel.jsx`) : les adresses enregistrées sont proposées en un toucher, l'adresse par défaut en premier. Le devis du serveur reste seul juge du prix et de la zone. L'`address_id` de l'adresse choisie est réutilisé tel quel, complément compris.

### Notifications
- **« Commande prête »** (dans l'application uniquement) :
  - le réglage est mémorisé sur l'appareil (`lecayenne.notifPrefs.orders`) et respecté par `app-native.js` (`rappelerCommande` / `signalerCommandePrete`) ;
  - l'autorisation est lue au système (nouveau `LC.native.etatPermissionNotifs`) et affichée telle quelle ;
  - si le téléphone n'a pas encore répondu : bouton « Autoriser les notifications » ;
  - si elles sont refusées sur iPhone : « Ouvrir les réglages » (`app-settings:`, nouveau `LC.native.ouvrirReglagesApp`) ;
  - si elles sont refusées sur Android : le chemin exact est indiqué. Aucun bouton n'est proposé, car il n'existe pas de lien vers les réglages sans greffon dédié.
- **« Offres et nouveautés par e-mail »** : il existe un vrai consentement marketing côté serveur, c'est la liste `subscribers` qu'utilise l'admin « Abonnés › Envoyer un e-mail » (`SubscriberService::sendEmail`).
  - `GET/PUT /api/frontend/newsletter-consent` inscrit ou retire l'e-mail du compte.
  - Le réglage est décoché par défaut.
  - Sans e-mail sur le compte, l'écran le dit et ne propose pas d'interrupteur.
  - L'e-mail est aussi retiré de la liste à la suppression du compte (`DeactivateController`).
- **Sur le site** : il n'y a pas de notification d'application, l'écran le dit. Seules les offres par e-mail y sont proposées.

### Paiement
La ligne et l'écran affichent ce que le serveur publie à l'instant, avec la même règle que le tunnel de commande :
- comptoir : espèces ou carte ;
- livreur : seulement si la livraison est active ;
- en ligne : « carte ou Apple Pay » sur iPhone, « carte ou Google Pay » sur Android, ou « pas proposé en ce moment ».
- Aucune carte n'est enregistrée sur le compte.

### « Recommander » (Mes commandes)
- **Backend** : `GET /api/frontend/order/{id}/reorder`
  - Il ne rend que les commandes du client ; une commande d'autrui répond 403 sans rien révéler.
  - Il rend la composition exacte : variations, suppléments (libellé compris) et formule relue dans le `composition_snapshot`.
  - Le prix est celui d'aujourd'hui, calculé par `PricingService`. Le service est seulement appelé, en lecture seule, via `PricingPreviewService` ; aucune zone gelée n'est modifiée.
  - Un produit retiré, épuisé ou ajouté en caisse est signalé avec sa raison, et rien n'est renvoyé pour lui.
  - La note de l'ancienne commande n'est pas recopiée ; la note de chaque ligne l'est.
- **Écran**
  - Le résultat est affiché dans la carte, par exemple : « 2 articles ajoutés au panier, au prix d'aujourd'hui. « 5 Tenders » est épuisé pour le moment. Non ajouté. » + « Voir mon panier ».
  - `resolveLine` envoie la composition du serveur telle quelle ; le serveur recalcule tout à la commande (garde `expected_total`).
  - Avant, la fonction « Recommander » n'existait pas dans `orders.jsx` : il n'y avait que « Voir le menu ».

---

## 2. Preuves

**Environnement de banc (aucune écriture partagée)**
- Base privée `foodking_profil`, copiée de `foodking_e2e`.
- Backend privé `:8793`, lancé par variables d'environnement : `DB_DATABASE`, `MOLLIE_API_BASE=http://127.0.0.1:8982/v2`, `MOLLIE_API_KEY=test_…`, `MOLLIE_ENABLED`, `APP_MOBILE_ONLINE_PAYMENT`, cache Redis préfixé `profil8793_`.
- Miroir du site rebranché sur `:8793` (`:8994`). Miroir de la base `5293d59` pour la comparaison (`:8996`).
- Le `.env` partagé n'a été ni lu ni écrit. Aucun appel vers api.mollie.com. Production consultée en lecture seule uniquement.

| Contrôle | Résultat |
|---|---|
| PHPUnit `ProfilCompletAppTest` (adresses + isolation, recommander + isolation, offres e-mail, nom) | **12/12** (78 assertions) |
| PHPUnit régression `Address\|Profil\|Profile\|AccountDeletion\|Deactivate\|Subscriber\|KioskMachineToken\|FormRequestAuthzDrift\|DeliveryValidation\|Throttle` | **274 OK** (3 ignorés, préexistants) |
| Banc navigateur `tests-e2e/profil-complet-2026-11-12.spec.js` (application iOS 430×932 + Android + 320/375/1280) | **79/79** (serveur paiement coupé) et **79/79** (paiement activé) |
| Banc paiement `tests-e2e/paiement-dans-app-2026-11.spec.js` (faux Mollie, mode privé) | **53/53**, dont l'écran de paiement du site **identique** à l'arbre d'avant |
| `node --test tests-e2e/*.unit.mjs` | **63/63** |
| `tools/verify-app-behaviour.mjs` | **55/55** |
| `tools/release-gates.mjs --prod` | **9/9** |
| `check-asset-versions --check` / `compile-jsx --check` / `build-app-www --check` | verts |

Le banc profil clique sur chaque bouton et vérifie l'effet réel, en base ou côté natif :
- prénom enregistré ;
- deux adresses géocodées, puis « par défaut », renommage et suppression relus en base ;
- autorisation demandée au téléphone ;
- « Commande prête » coupé ⇒ aucun rappel programmé ;
- offres activées ⇒ abonné en base, désactivées ⇒ retiré ;
- `tel:` / `mailto:` / Google Maps dans le navigateur du système ;
- les 4 pages légales en 200 et la page CGV ouverte.

**« Recommander » jusqu'à la commande réelle.** Le banc passe la commande « recommandée » : le serveur l'accepte (23,00 €). En base, la composition est identique : viande 680, sauce supplémentaire 428, formule, « Boisson menu ». Le total est le même et l'ancienne « Note commande » est absente.

**Cibles et largeurs** : toutes les cibles du hub, du carnet et des réglages font au moins 44 px. Aucun débordement horizontal à 320 / 375 / 430 / 1280.
- Un défaut a été trouvé et corrigé pendant la vérification visuelle : à 320 px, le nom s'écrivait une lettre par ligne. « Modifier » passe désormais sous l'identité sur téléphone.
- Un contrôle de largeur du nom a été ajouté au banc.

**Captures lues** : `captures/` (33), `captures-paiement-active/` (paiement en ligne activé : `16-paiement-active-ios.png`, `26b-paiement-android-active.png`), `paiement/` (12).

**Échec rencontré puis expliqué** : sur le paiement, « Réessayer rouvre le même paiement Mollie » est tombé en échec, aussi sur l'arbre d'avant.
- Cause : mon premier serveur de banc tournait avec `CACHE_DRIVER=array`. Le rejeu par clé d'idempotence ne survit pas d'une requête à l'autre avec ce cache.
- Avec un cache persistant (Redis préfixé) : vert. Ce n'était pas un défaut du produit.

---

## 3. Activer Apple Pay / Google Pay en vrai — ce que le propriétaire doit faire

Aujourd'hui en production, `/api/frontend/app/config` répond `online_payment_enabled:false` et `web_online_payment_enabled:false`. **Les quatre conditions** suivantes sont exigées par `AppConfigController` et `MolliePaymentController` :

1. **Tableau de bord Mollie** (profil `pfl_Ymr3Tb6vvp`) → *Moyens de paiement* : activer **Cartes**, **Apple Pay** et **Google Pay**.
   - Pour Apple Pay sur la page hébergée de Mollie, aucun fichier de domaine n'est requis, puisque la page est servie par Mollie.
   - Le fichier `/.well-known/apple-developer-merchantid-domain-association` est **déjà en place** sur www.lecayenne.fr (HTTP 200 vérifié). Il ne sert qu'à la feuille Apple Pay du **site**.
2. **`.env` de production** (VPS) : `MOLLIE_ENABLED=true`, `MOLLIE_API_KEY=live_…` (clé du profil ci-dessus), `APP_MOBILE_ONLINE_PAYMENT=true`, puis `php artisan config:clear`.
   - `MOLLIE_API_BASE` doit être **absent** : la valeur par défaut est `https://api.mollie.com/v2`.
3. **Admin › Paramètres › Site › « Passerelle de paiement en ligne » → Activer** (réglage `site_online_payment_gateway`).
   - Cet interrupteur est nouveau depuis le 2026-11-10 et **n'est pas dans `app/PUBLICATION.md`**.
   - Sans lui, l'application et le site restent au comptoir, même avec la clé et le drapeau.
   - C'est aussi l'interrupteur d'urgence : le banc prouve qu'il coupe le paiement en ligne immédiatement, y compris quand l'écran de paiement est déjà ouvert.
4. **Essai sur un vrai iPhone et un vrai Android** : une petite commande réelle, puis remboursée depuis Mollie. Vérifier le retour automatique dans l'application, la commande payée et le ticket.
   - Ensuite, mettre à jour la fiche store et les notes d'examen (`app/PUBLICATION.md` §6 / §8).
   - À ce jour, ce n'est **pas prouvé** sur appareil réel : les bancs utilisent le faux Mollie.

---

## 4. Ce qui reste

- **Ordre de déploiement** : déployer le backend `3324c427e` avec sa migration **avant** le site / l'application.
- **Bancs historiques à mettre à jour** : `tests-e2e/app-natif-honnete-2026-09-30.spec.js` (§9) cherche l'interrupteur de notification sur la page « Mon compte ». Il est désormais dans « Notifications » : ce banc sera rouge tant que son sélecteur n'est pas mis à jour.
  - `e2e-stores-vague-A` reste compatible, car la carte « Zone dangereuse » garde la classe `lc-profile-card`.
- **Changer d'e-mail ou de téléphone** reste réservé à la caisse (décision serveur existante). Un compte Apple sans numéro peut corriger son nom, mais pas déclarer un numéro depuis le profil : il le fait au paiement, avec le flux existant.
- **« Ouvrir les réglages » sur Android** demanderait un greffon natif (par exemple `capacitor-native-settings`). Il n'est pas ajouté, pour éviter un nouveau binaire non éprouvé.
- **Base privée** : `foodking_profil` et les serveurs `:8793`, `:8994` et `:8996` sont à arrêter et supprimer après fusion. Dans cette base, les horaires ont été ouverts (08:00–04:00) et la passerelle activée, pour les besoins du banc.
