# FICHES DE RENVOI — ce qui a été trouvé hors de la voie où on l'a trouvé

> Règle du protocole §5 : on ne corrige jamais un fichier qui appartient à une autre voie.
> On émet une fiche, datée, avec la preuve, et le propriétaire corrige chez lui.
> Chaque fiche ci-dessous a été **vérifiée dans le code** avant d'être écrite.

---

## F-01 → **ONB-10** (équipement) · le téléphone du Cayenne est en dur dans le ticket

**Trouvé en** ONB-01 (identité de l'établissement).
**Fichier possédé par ONB-10** : `config/printing.php`.

```php
// config/printing.php:109
'phone' => env('RECEIPT_PHONE', '03 65 67 82 91'),
```

Le commentaire du fichier précise lui-même que cette valeur part **à la caisse (`OrderReceiptEscPosRenderer`) ET à la borne** (`master.blade` → `window.foodkingConfig.borneTicket.phone` → `bridge.js`).

**Conséquence pour un nouveau commerçant** : si sa filiale n'a pas de téléphone renseigné, son ticket affiche le numéro du Cayenne d'origine. Il donne le numéro d'un autre restaurant à ses clients.

**Ce qu'il faut** : le repli doit venir de la filiale (`branch->phone`), pas d'une constante. Si aucune source n'existe, ne rien imprimer plutôt qu'un faux numéro.

---

## F-02 → **ONB-05** (réglages) · la page des taxes est inatteignable au clic

**Trouvé en** ONB-02 (catalogue).
**Fichier possédé par ONB-05** : `resources/js/config/v1-hidden-modules.js`.

```js
// resources/js/config/v1-hidden-modules.js:39
'settings.tax',
```

**Conséquence** : un commerçant doit régler ses taux de TVA — c'est une obligation, pas un confort — et la page n'apparaît pas dans le menu. Elle n'est atteignable qu'en devinant l'URL `/admin/settings/taxes/list`.

**Aggravant mesuré** : sur 170 articles en base, 77 sont à 0 % de TVA. La page qui permettrait de corriger ça est celle qu'on a cachée.

**Ce qu'il faut** : `settings.tax` sort de la liste des modules cachés. C'est une décision qui relève du gate G-CACHE (arbitrage page par page des 22 entrées).

---

## F-03 → **ONB-10** (équipement) · `App\Enums\KdsStation` reste à créer

**Trouvé en** ONB-02. **Collision déclarée au protocole §5** : l'énumération est revendiquée par ONB-02 et ONB-10 ; ONB-10 la crée, ONB-02 s'y branche.

La colonne `items.kds_station` est un ENUM MySQL strict à quatre valeurs (`bar`, `cuisine_chaude`, `cuisine_froide`, `none`) — migration `add_kds_station_to_items`. La règle de validation acceptait n'importe quelle chaîne de 32 caractères ; elle est désormais alignée **en écrivant les quatre valeurs en clair** dans `ItemRequest`, pour ne pas créer l'énumération à la place d'ONB-10.

**Ce qu'il faut** : quand ONB-10 crée `App\Enums\KdsStation`, remplacer la liste littérale de `ItemRequest` par la constante. Une seule source, trois consommateurs.

---

## F-04 → **ONB-02** (catalogue), vague suivante · l'import Excel ne dit jamais ce qui a échoué

**Trouvé en** ONB-02 W1, non corrigé faute de temps dans la vague.

```php
// app/Http/Controllers/Admin/ItemController.php — import()
Excel::import(new ItemImport($request->file('file')), $request->file('file'));
return response('', 202);
```

Un `202` avec un **corps vide**. Si trois lignes sur cinquante échouent, le commerçant lit « accepté » et n'apprendra jamais lesquelles ni pourquoi.

**Ce qu'il faut** : un rapport d'import — nombre de lignes lues, créées, ignorées, et la liste des refus avec le motif. Et le gabarit fourni (`itemImportSample.xlsx`) est en anglais avec des catégories qui n'existent pas.

---

## F-05 → **ONB-06** (rôles) · un test de seeder de permissions échoue déjà

**Trouvé en** ONB-02 en lançant la suite Branch, **sans lien avec les modifications d'ONB-02**.

`tests/Feature/Seeders/RolePermissionSeederTest::test_branch_manager_receives_expected_permissions`
→ `SQLSTATE[23000]: UNIQUE constraint failed: permissions.name, permissions.guard_name`

Le seeder de permissions n'est pas idempotent : rejoué sur une base qui en contient déjà, il tente de réinsérer. Le test échoue **aussi en isolement**, donc ce n'est pas un problème d'ordre d'exécution.

**Ce qu'il faut** : `updateOrCreate` (ou `firstOrCreate`) sur `(name, guard_name)` plutôt qu'un `insert` en masse. C'est la voie d'ONB-06.

---

## F-06 → **ONB-12** (publication vierge) · la pollution de test est soudée à la chaîne fiscale

**Trouvé en** ONB-01, mesuré en base.

| Résidu | Ce à quoi il est lié |
|---|---|
| 5 filiales fictives (« Collier and Sons Branch »…) | **5 commandes, 11 `audit_logs`, 6 `z_reports`** |
| 26 taxes `AUDIT-*` | 52 articles |
| 58 articles de test | 42 lignes de commande |

`audit_logs` et `z_reports` sont append-only avec un trigger qui interdit le DELETE. **Ces déchets ne peuvent plus être retirés de `foodking_e2e`.**

**Conséquence pour ONB-12** : la « publication vierge » ne pourra JAMAIS se prouver sur cette base. La base dédiée n'est pas une commodité, c'est le seul instrument possible. Et l'interdit « aucune commande créée » du protocole cesse d'être une précaution : c'est un fait démontré.

**Visible à l'écran** : `E2E_PLAYWRIGHT_STUDIO_ITEM` à 1,00 € apparaît dans le catalogue entre « Sandwich Classique » et « Perrier 33cl ».

---

## F-07 → **propriétaire** · le LOCK de la borne n'est pas contresigné

**Trouvé en** inspectant l'arbre principal.

Parmi les 235 fichiers stagés de l'arbre principal, `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` (**zone gelée §7**) porte 53 lignes de modification. Elles référencent `LOCK_KIOSK_WIZARD_MODIFIER_DEPUIS_RECAP_2026-08-25`, et le document existe dans `docs/locks/`. Mais :

> **Contresignature propriétaire :** ☐ *(à cocher par le propriétaire — je ne signe pas à sa place)*

Le hook `pre-commit` du dépôt bloque le commit — **correctement**. Le contournement `--no-verify` est interdit par CLAUDE.md §3quater sans accord explicite.

**Ce qu'il faut** : soit la case est cochée, soit ce fichier sort du commit. Décision propriétaire, personne d'autre.

---

## F-08 → **ONB-13** (sécurité) · une garde d'upload appliquée à un chemin, oubliée sur l'autre

**Trouvé en** ONB-04, en cartographiant les deux motifs Vision existants.

Deux chemins d'upload de photo, la même brique, deux traitements :

```php
// app/Http/Controllers/Admin/PurchasingScanController.php:61  — facture
'photo' => ['required', 'file', 'max:12288', new \App\Rules\NoDangerousFileExtension()],

// app/Http/Controllers/Admin/UberPhotoCaptureController.php   — ticket Uber
'photos.*' => ['required', 'file', 'max:'.$maxKb],     // ← la règle manque
```

Le commentaire du fichier « propre » (`:54-56`) dit que `NoDangerousFileExtension` a été ajoutée par un correctif de sécurité visant les extensions `.pht`. Elle a été posée à un endroit et **pas à l'autre**.

**Gravité réelle, mesurée et non enflée** : les fichiers Uber sont stockés sur le disque `local` (`$file->store('uber-tickets', 'local')`, `:89`), **hors de `public/`**. Ils ne sont donc pas atteignables en HTTP : ce n'est **pas** une exécution de code à distance. C'est une **défense en profondeur manquante** — la garde existe, elle protège un chemin, elle a été oubliée sur le second.

**Ce qu'il faut** : appliquer la même règle aux deux. Le coût est d'une ligne ; l'écart, lui, est le genre qui devient une faille le jour où quelqu'un change le disque de stockage.

**À ne pas copier** : ONB-04 va créer un troisième chemin d'upload (la photo de la carte). Qu'il reprenne le motif de `PurchasingScanController`, jamais celui d'Uber.

---

## F-09 → **ONB-04** (à traiter dans sa propre vague) · aucun compteur de dépense IA

Grep exhaustif : **aucun compteur, aucun plafond, aucune trace** de ce qui est dépensé en appels Vision. Un commerçant qui photographie sa carte de 200 produits ne saura jamais ce que ça lui a coûté, et rien ne l'empêche de relancer cent fois.

**Ce qu'il faut** avant tout appel réel (gate G-IA) : un compteur par établissement, un plafond configurable, et le refus au-delà — pas une facture surprise.

---

## F-10 → **ONB-10** (borne) · la borne est la seule surface sans repli de scrutation

**Trouvé en** ONB-08, en mesurant la propagation d'une rupture aux quatre surfaces.

| Surface | Transport | Pire cas si le WebSocket se tait |
|---|---|---|
| Caisse | Echo **+ scrutation 30 s** | ≤ 30 s |
| KDS | Echo **+ scrutation 15 s** (5 s si déconnecté) | ≤ 15 s |
| **Borne** | Echo **seul** | **jusqu'à 5 minutes** |
| Web public | canal diffusé, aucun consommateur JS | sans objet en V1 |

```js
// resources/js/store/modules/kioskMenu.js:19
const CACHE_TTL_MS = 5 * 60 * 1000; // 5 minutes
```

En marche normale la borne reçoit un correctif direct par WebSocket : moins d'une seconde, c'est bon. Le problème est le **jour où le WebSocket se tait** — réseau du restaurant qui bronche, Soketi qui redémarre. La caisse et le KDS ont chacun leur filet ; la borne n'en a aucun. Elle continue de vendre pendant cinq minutes un produit qui n'existe plus, face au client, sans personne derrière pour rattraper.

**Ce que ça coûte** : un client paie un sandwich qu'on ne peut pas lui faire. Il faut le rembourser — et un remboursement, en NF525, c'est une contre-écriture, pas une annulation.

**Pourquoi je ne l'ai pas corrigé moi-même** : le critère du GOAL demande **moins de 10 secondes**. Baisser le délai du cache à 60 secondes serait un progrès de cinq fois — et ne satisferait toujours pas le critère. Un demi-correctif dans la voie d'un autre GOAL crée plus de confusion qu'il n'en résout.

**Proposition chiffrée, au choix du propriétaire :**
1. *Le moins cher* — détecter le silence du WebSocket (Echo expose son état) et invalider le cache à la reconnexion. Aucune requête supplémentaire en marche normale.
2. *Le plus simple* — une scrutation légère des seules disponibilités toutes les 10 secondes, sur le modèle du KDS. Une requête toutes les 10 s par borne, réponse minuscule.
3. *Le plus sûr* — les deux. C'est ce que fait déjà le KDS.

**Ce qui est bien fait et qu'il ne faut pas casser** : le cache serveur (60 s) est invalidé de façon synchrone dans la même requête et n'ajoute aucun délai ; les mouvements de stock sont en ajout-seul, garantis par un hook Eloquent qui lève une exception sur `updating` et `deleting` ; un « 86 » posé à la main est collant et gagne toujours sur la réactivation automatique. Rien de tout cela n'est à toucher.

---

## F-11 → **moi-même / toute session qui mesure** · le worker et Soketi tournent sur l'ARBRE PRINCIPAL

**Trouvé en** ONB-08.

Le worker de file d'attente et Soketi actuellement en marche sur cette machine ont leur répertoire de travail sur **l'arbre principal**, pas sur le worktree du programme. Toute mesure de propagation temps-réel faite ici mesurerait donc, en partie, le code de l'arbre principal.

**Ce qu'il faut** : avant toute mesure de synchronisation en direct, redémarrer le worker et Soketi **depuis le worktree**, ou déclarer explicitement que la mesure porte sur l'arbre principal. Sinon on croit mesurer son propre code et on mesure celui du voisin — exactement le genre d'instrument qui donne une réponse fausse avec assurance.

---

## F-12 → **ONB-13** (sécurité) · le mot de passe de messagerie part en clair au navigateur

**Trouvé en** ONB-13.

```php
// app/Http/Resources/MailResource.php:31
"mail_password"   => $this->info['mail_password'],
```

L'écran de réglages de messagerie renvoie le mot de passe SMTP **en clair** au navigateur. Il finit dans la mémoire de l'onglet, dans l'onglet Réseau des outils de développement, et dans tout journal de requêtes intermédiaire.

**Gravité mesurée, non enflée** : la route est réservée aux comptes qui ont le droit `settings`. Ce n'est donc pas une fuite publique. C'est un secret qui sort du serveur sans nécessité — et un mot de passe SMTP sert à envoyer du courrier au nom du restaurant.

**Pourquoi je ne l'ai pas corrigé tel quel** : masquer naïvement casserait l'enregistrement. Le formulaire renvoie ce qu'il a reçu ; s'il reçoit `••••••••`, il écrira `••••••••` dans le vrai mot de passe à la première sauvegarde. Le correctif complet demande **deux** gestes coordonnés — renvoyer un masque, ET ignorer le champ à l'écriture quand il vaut le masque. C'est un travail de la voie ONB-13, pas un remplacement d'une ligne.

Même motif à vérifier sur `GatewayOptionsResource.php:19`, qui renvoie `value` non masqué — utilisé par les passerelles de paiement **et** par la passerelle SMS.

---

## F-13 → **ONB-13** (sécurité) · 502 messages d'exception renvoyés au client

**Trouvé en** ONB-13, motif systémique.

502 occurrences de `getMessage()` renvoyées au client depuis `app/Http/Controllers`, dont 86 fichiers sous `Admin/`. `Handler.php` désinfecte correctement les `QueryException` — mais ce code est **mort** dès qu'un contrôleur attrape `Exception` en premier, ce qui est la norme dans ce projet.

Conséquence pour un commerçant : au lieu d'un message utile, il peut recevoir un nom de classe, un fragment de SQL, ou un chemin de fichier. Exemples sur routes sensibles : `MailController.php:39`, `LicenseController.php:39`, `InterrupteurController.php:83`, et treize occurrences dans `ItemController.php`.

**Ce qu'il faut** : un traitement uniforme — message métier au client, détail technique au journal. C'est une vague entière, pas un correctif ponctuel.

---

## F-14 → **ONB-13** · les 64 `authorize() => true` ne sont PAS un trou actif

**À contre-courant de ce qu'on pourrait croire, et c'est important de l'écrire.**

Sur environ 40 candidats à fort rayon d'explosion — messagerie, licence, passerelle SMS, OTP, changement de mot de passe, création de serveur/cuisinier/client, réglages de langue et de catalogue — **chacun a été vérifié un par un** : tous ont une garde compensatoire, soit un `permission:` dans le constructeur du contrôleur, soit un `can()` / `hasRole()` en ligne.

**Aucun n'est exploitable aujourd'hui.** Le risque réel est un **point de défaillance unique** : le jour où quelqu'un supprime la ligne de garde du contrôleur en pensant que la FormRequest protège, plus rien ne protège. C'est un risque de maintenance, pas une faille.

Le cliquet `RETURN_TRUE_BASELINE = 64` a donc toute sa raison d'être — mais il faut le lire pour ce qu'il est : un compteur de dette, pas un compteur de trous.

---

# AUDIT VISUEL — 2026-08-27, fait au navigateur sur :8800

> ONB-11 avait marqué cette partie « NON VÉRIFIÉ » : son agent n'avait pas accès au
> navigateur. Fait ici, écran par écran. **Deux défauts trouvés que la suite de tests
> ne voyait pas** — et corrigés le jour même.

## Ce qui était cassé, et ne l'est plus

| Écran | Ce que l'œil a vu | État |
|---|---|---|
| Tableau de bord | **331 alertes cuisine**, dont un ticket « en attente depuis 77 j 22 h » | ✅ corrigé — `1aac5c1c3` |
| Borne, accueil | **788 requêtes SQL** en 404 ms pour afficher le menu | ✅ corrigé — `94ea3b592` |
| Formulaire article | Taxe sans astérisque, libellé « TAXE (INCLUANT) », liste affichant `VAT-10%` | ✅ corrigé — `d7513cffa` |
| Autorisations | 80 permissions en anglais brut | ✅ corrigé — `3d8a99b0a` |
| Rôles | « POS Operator », « Waiter », « Stuff » | ✅ corrigé — `abad1cb6c` |
| Rapport des ventes | 0,00 € au-dessus d'une liste à 65,20 €, sans explication | ✅ corrigé — `87cebbdb3` |

## Ce qui est bon, et qu'il ne faut pas toucher

**La caisse** (`/admin/pos`) est le meilleur écran du produit. Tout est en français, les
états vides sont écrits pour un humain — « Aucune commande prête à livrer pour le
moment », « Aucun article. Sélectionnez un produit dans la grille » — les compteurs sont
vivants, les images chargent, et **une seule requête** est émise. Le bandeau rouge
« Article indisponible : Coca-Cola 33cl » prouve au passage que la propagation de
rupture d'ONB-08 fonctionne en conditions réelles.

**La borne d'accueil** est belle et entièrement en français : logo, carrousel produits,
« Bienvenue ! », « Touchez l'écran pour commander ».

Il n'y a rien à corriger sur ces deux-là, et c'est important de le dire : un audit qui
ne trouve que des défauts finit par en inventer.

## Ce qui reste, et qui appartient à ONB-12 (bloqué par G0)

La marque est **partout** sur les deux surfaces client :

- borne : le logo, le texte « LE CAYENNE », la baseline « TACOS · BURGERS · SANDWICHS ·
  BOWLS », et « Bienvenue ! Le Cayenne » ;
- caisse : « CAISSE LE CAYENNE » en dur en haut à gauche (`PosComponent.vue:80`).

Un autre restaurant qui installe ce logiciel verrait le nom d'un concurrent sur l'écran
que ses clients regardent. C'est l'objet même du gate **G0**.

## Deux mesures — dont une que je RETIRE

**RETIRÉ.** J'avais consigné « le tableau de bord met environ 18 secondes à s'afficher ».
**Cette mesure ne vaut rien**, et la laisser enverrait quelqu'un optimiser au mauvais
endroit. Vérifié ensuite : la coquille HTML répond en **36 millisecondes**, et chaque
point d'entrée du tableau de bord émet **une seule requête SQL** (balayage N+1 refait
après correctif : 1 requête pour les ventes, 1 pour les alertes, 1 pour les canaux).

Les 18 secondes venaient de mon instrument, pas du produit : je chronométrais depuis le
navigateur le rendu complet d'une application monopage volumineuse, dont une quinzaine
d'appels séquentiels, sur un `php artisan serve` qui **ne traite qu'une requête à la
fois**. C'est exactement le piège contre lequel ce programme met en garde depuis le
matin — mesurer avec un instrument dont on n'a pas vérifié qu'il mesure la bonne chose.

Une vraie mesure de lenteur du tableau de bord demanderait un serveur multi-processus et
un chronométrage côté serveur. Elle reste **à faire**, et je ne prétends pas l'avoir faite.

**MAINTENU.** Le tableau de bord affiche **deux fois le même chiffre sous deux noms** —
« Ventes du jour » et « Chiffre d'Affaires du Jour », plus « Commandes du jour » en
double. Constaté à l'écran, et c'est la confirmation visible des trois définitions
concurrentes relevées dans le code.

### Complément — écrans du personnel, vérifiés au navigateur

**Écran de cuisine** (`/admin/kitchen-display-system`) — propre. « Aucune commande en
cours / Les nouvelles commandes apparaîtront ici », avec une illustration. Neuf requêtes.
Synchronisation **incrémentale** (`sync?since=…`) plutôt qu'un rechargement complet :
c'est le bon motif pour un écran qui tourne toute la journée. Boutons en français :
Rupture, Historique, Afficher les noms.

**Écran d'appel client** (`/admin/order-status-screen`) — propre. Deux colonnes « En
préparation » / « Prêt », états vides écrits (« Aucune commande en préparation »),
**une seule requête**, typographie large adaptée à un affichage vu de loin, et un bouton
plein écran. Rien à corriger.

### Bilan de l'audit visuel

Huit surfaces majeures regardées : tableau de bord, borne d'accueil, caisse, écran de
cuisine, écran d'appel, catalogue, autorisations, rapport des ventes.

**Deux défauts sérieux trouvés** — les 331 alertes et les 788 requêtes — **et six écrans
innocentés**. Le rapport de un à trois est le bon signe : un audit qui trouverait un
défaut partout ne chercherait pas, il confirmerait ce qu'il est venu prouver.

Le produit est solide sur ses surfaces de service. Ce qui lui manque pour un NOUVEAU
commerçant n'est pas de la qualité d'exécution — c'est que la marque du premier client
est écrite dans le code, et c'est exactement l'objet du gate G0.
