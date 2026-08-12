# La roue — accès caisse, probabilité pilotable, vitrine et accès client

**Date** : 2026-08-12
**Demande du propriétaire** : « améliore la roue qui tourne en accès admin caisse et tablette
affichage et probabilité, et accès client » + « imprime même logo sur ticket de caisse, ça fait
classe au lieu du nom simple Le Cayenne, mais le logo ».
**Décision de cadrage** : le propriétaire a explicitement choisi **tout d'un bloc** (les 5 axes en
une seule passe), contre ma recommandation de découper en 3 lots. Sa décision est prise. Atténuation
retenue : **commits de jalon par phase** (`checkpoint-commit`) pour qu'une interruption ne perde rien.

---

## 1. Ce qui existe déjà — À NE PAS RECONSTRUIRE

Vérifié dans le code, pas supposé.

| Brique | Où | État |
|---|---|---|
| 5 écrans de la roue | `routes/web.php:160-207` → `/admin/roue`, `-validation`, `-lot`, `-borne`, `-reglages` | En service |
| Porte des écrans | `app/Http/Middleware/EnsureWheelAccess.php` | Session web `pos` **ou** code maison (`WHEEL_PIN`), fail-closed |
| Réglages sans déploiement | `WheelSettingsService` (`GROUP = 'wheel'`) + `/admin/roue-reglages` | Liens, temps d'attente, minimum d'achat, **poids et quantité par lot** |
| Filtre « lot réellement donnable » | `WheelService::lotTirable()` (L192-216) | Plafond du jour, quantité de campagne, rupture, interrupteurs de remise |
| Lots publiés | `WheelService::publicSegments()` (L114-134) | **Retire aujourd'hui les lots épuisés** — comportement que le propriétaire fait inverser (§3.1) |
| Lots sur lesquels la vitrine s'arrête | `WheelService::spinnableKeys()` (L154-169) | Exclut déjà les lots à poids nul (le Terminator) |
| Bilan d'exploitation | `WheelReportService` + panneau sur `/admin/roue` | Tours, cadeaux remis, valeur offerte, codes |
| **Logo raster sur ticket** | `EscPosCommandBuilder::rasterImage()` (L155) | **Existe et tourne en production** sur le ticket promo |
| **QR sur ticket** | `EscPosCommandBuilder::qrCode()` (L269) | Idem |
| Patron de référence impression | `PromoFlyerEscPosRenderer:163` (QR), `:299-318` (logo en cache + repli) | À réemployer tel quel |
| Roue du client à N secteurs | `lecayenne-web-deploy/Site lecayenne/roue.html:558,689` | `segments.length` générique — **supporte déjà un nombre variable de lots** |
| Roue de secours du client | `roue.html:771` | Neutre (`'?'` × 6) — aucun produit périmé à maintenir |

**Conséquence** : « montrer les vrais restants / retirer les épuisés sur la tablette » était déjà
livré le 12/08. Il n'est pas au périmètre — il en est **retiré** (voir §3.1, le propriétaire l'inverse).

---

## 2. GD, matériel, dépôts

- **GD est présent** (`imagecreatefromstring` disponible) — la conversion du logo en trame 1 bit est possible. Imagick est absent, on ne s'en sert pas.
- **Aucune bibliothèque ESC/POS tierce** : tout passe par `EscPosCommandBuilder`, maison.
- **Largeur du ticket pilotée par config** : `printing.receipt.width_chars` (42 pour la SAGA du comptoir).
- **Deux dépôts** : le backend (ici) et le site client `lecayenne-web-deploy/Site lecayenne/` (Vercel). Le site **exécute `compiled/*.js`, pas les `.jsx`** → toute modification du site exige une recompilation, sinon le correctif est inactif.

---

## 3. Les deux décisions du propriétaire qui INVERSENT une décision antérieure

Surfacées ici parce que CLAUDE.md §12 interdit de les appliquer en silence.

### 3.1 « On affiche toujours tous les produits, même épuisés — on ne le dit jamais »

**Décision antérieure** (10 et 12/08, de ma main) : un lot épuisé, en rupture ou au plafond du jour
**disparaît** de la roue. Motif écrit : « une roue qui montre ce qu'elle ne donne pas mène le client
en bateau ».

**Décision du propriétaire, confirmée explicitement** : la roue montre **toujours** les produits,
sur la tablette **et** sur le téléphone du client. Aucun compteur, aucune mention « épuisé ».

**Résolution — et pourquoi elle n'est pas un mensonge** : le mécanisme demandé existe déjà, c'est
exactement le traitement du Terminator. On sépare **ce qui est dessiné** de **ce qui peut être
désigné gagnant** :

- `publicSegments()` cesse de filtrer par `lotTirable()` → tous les lots réglés sont dessinés, partout.
- `spinnableKeys()` (vitrine) et `draw()` (tirage réel) continuent d'appliquer `lotTirable()` **sans
  aucun changement** → l'aiguille ne s'arrête jamais sur un lot indisponible, et le tirage ne le donne jamais.
- Le repli « si rien n'est tirable, on republie tout » de `publicSegments()` devient **sans objet** :
  il n'y a plus de filtre à replier. Le refus honnête du serveur au moment du tour reste la seule
  frontière, et il est déjà en place.

**Effet de bord à surveiller en test** : le commentaire de `publicSegments()` L124-127 documente une
peur (« une roue vide ferait afficher sa liste de secours ») qui disparaît avec le filtre. Le
commentaire doit être réécrit, pas laissé à mentir sur le code.

### 3.2 « Je choisis quels produits sont sur la roue »

**Décision antérieure** : `WheelSettingsService::prizeOverrides()` documente qu'on NE donne PAS à
régler le produit de coût, le type et le libellé — « un libellé modifiable, c'est une roue qui promet
*Big Cayenne* et sort une boisson ; un produit de coût modifiable, c'est l'inventaire d'un autre
article qui dérive ».

**Résolution** : la crainte visait un **libellé libre découplé du produit**. La demande du
propriétaire est plus sûre que ça : il **pioche une ligne de la table `items`**, et le libellé
comme le `cost_item_id` en sont **dérivés ensemble**. Ils ne peuvent structurellement plus diverger,
ce qui supprime le mode de défaillance redouté. Le libellé reste non-saisissable en texte libre.

---

## 4. Design

### AXE A — Accès admin & caisse

#### A1 · Onglet « Roue » dans la barre caisse
`resources/js/components/admin/pos/CaisseSecondaryNav.vue` (119 l., **non gelé**). 5ᵉ entrée après
Encaissement / Suivi commandes / Historique / Écran de statut. Ce n'est **pas** un `router-link` : la
roue est une page Blade hors SPA, donc un `<a href="/admin/roue" target="_blank" rel="noopener">`.
Rendu conditionné à la permission `pos`.

#### A2 · Entrée « La roue » au menu latéral admin
`resources/js/components/layouts/backend/BackendMenuComponent.vue` (**non gelé**, mais **déjà modifié
dans l'arbre de travail** — relire le diff avant de toucher, ne pas écraser le travail en cours).
Même cible, même garde.

#### A3 · Bouton dans le wizard caisse — **ZONE GELÉE §7, sous LOCK**
`public/js/pos-wizard.js` est frozen (« design parfait selon owner »). Le propriétaire a donné son
accord explicite en séance. Un **document LOCK** est néanmoins écrit avant la première ligne, selon
la doctrine du projet.

Périmètre du LOCK, volontairement minuscule :
- un bouton dans la barre d'outils existante, action unique `window.open('/admin/roue', '_blank')` ;
- **aucune** modification de la logique de commande, de paiement, de remise, de rendu du ticket ;
- diff visé **< 15 lignes**, contigu, `git checkout public/js/pos-wizard.js` comme rollback ;
- triple-vert exigé avant de considérer la porte refermée (voir §6).

#### A4 · Fin du code PIN depuis une caisse déjà connectée — LA PASSE SIGNÉE

**Le fond du problème**, déjà documenté dans `EnsureWheelAccess` : la garde par défaut est `sanctum`,
`LoginController` détruit la session web et rend un jeton Bearer, et **une navigation de document ne
porte jamais d'en-tête `Authorization`**. C'est la cause du P0 du 10/08 et la raison d'être du PIN.

**Solution — un chemin de plus, aucun chemin retiré** :

1. `POST /api/admin/wheel/screen-pass` — sous `auth:sanctum` + `can:pos`. La SPA a le jeton Bearer,
   donc cet appel-là fonctionne. Rend une **URL signée** (`URL::temporarySignedRoute`), **60 s**,
   portant l'écran demandé.
2. `GET /admin/roue/passe` — vérifie la signature (`signed` middleware), **consomme la passe une
   seule fois** (verrou de cache sur l'identifiant de la passe), pose `wheel_unlocked_at` en session,
   redirige vers l'écran.
3. Le **PIN reste intact** : c'est le chemin de la tablette nue, sans session SPA. Le fail-closed
   d'origine est conservé.

**Exposition, dite franchement** : une passe qui fuite ouvre les écrans 60 s, une fois. Le PIN
partagé, lui, vaut 4 h, est le même pour tout le monde et ne change jamais. Le chemin ajouté est le
**moins** exposé des deux.

**Piège connu à ne pas rejouer** : `$user->can('pos')` sur la garde `web` ne trouve rien — les
permissions de ce projet sont enregistrées sous `sanctum`. `EnsureWheelAccess::habilite()` cherche
déjà sur les deux gardes ; la nouvelle route doit faire pareil ou réemployer cette méthode.

### AXE B — Probabilité pilotable

#### B1 · Choisir les produits (le morceau le plus risqué)
Les réglages portent désormais **la liste entière** des lots, et non plus seulement deux surcharges :
`key`, `label`, `item_id`, `type`, `weight`, `quantity`, `daily_cap`. `config/wheel.php:segments`
devient le **semis** utilisé quand rien n'est enregistré — jamais une seconde source de vérité.

Écran (`/admin/roue-reglages`) : un sélecteur qui pioche dans la table `items` (SSOT du menu,
CLAUDE.md §3bis — **jamais un produit inventé**), plus « ajouter un lot » / « retirer ce lot ».

Contraintes dures :
- **4 à 8 secteurs.** En dessous ce n'est plus une roue ; au-dessus les libellés cessent d'être
  lisibles. La géométrie de cette roue a déjà coûté un défaut (« le retournement des libellés se
  calcule sur l'angle ABSOLU », 10/08).
- **`label` et `cost_item_id` viennent de la même ligne `items`** (§3.2). Aucun texte libre.
- **Retirer un lot ne doit jamais casser un lot déjà gagné et non remis.** Vérifié :
  `wheel_spins` fige déjà `prize_key`, `prize_label`, `prize_type`, `prize_value` en clair
  (migration `2026_08_09_000001`, L43-45), et l'écran de remise lit bien `$spin->prize_label`.

  **MAIS — trou que ce chantier ouvrirait, trouvé en relecture :**
  `WheelDeliveryService::costItemId()` (L383-385) résout le produit à décrémenter en **relisant
  `segments()` par clé AU MOMENT DE LA REMISE**. Si l'exploitant retire un lot de la roue entre le
  tour et la remise, la clé n'existe plus dans les segments → la méthode rend `null` → **le stock
  n'est pas décrémenté**. C'est exactement le trou du 10/08 (« cadeau remis, stock inchangé »),
  ré-ouvert par une porte neuve — le motif « jumeau oublié », 8ᵉ occurrence sur ce domaine.

  **Correctif exigé, et il fait partie de B1, pas d'un backlog** : figer `cost_item_id` **sur le
  tour** au moment du tirage, comme `prize_label` l'est déjà. `costItemId()` préfère alors la valeur
  gelée du tour, et ne retombe sur la lecture des segments que pour les tours antérieurs à la
  migration. Une migration ajoute la colonne ; les tours existants gardent le comportement actuel.

  Test explicite exigé : gagner un lot, **retirer ce lot de la roue**, puis le remettre au client →
  le stock DOIT bouger, et l'avertissement « cadeau sans décrément » du bilan DOIT rester à zéro.
- Le type reste `free_item` par défaut ; `points` et `coupon_*` restent pris en charge par le moteur
  mais ne sont pas proposés à la saisie dans cette passe.

#### B2 · Plafond du jour réglable
Une colonne de plus dans le tableau existant. `daily_cap` est **déjà** lu par `lotTirable()` — le
travail est validation + persistance + libellé, pas moteur.

#### B3 · Simulateur « sur 100 tours »
Panneau qui affiche, **sans enregistrer** : la répartition attendue et la **valeur offerte
prévisionnelle**. Calculé **côté serveur** avec la vraie règle de tirage — dupliquer la
normalisation des poids dans le navigateur créerait un jumeau qui dérive.

**Honnêteté du chiffre, déjà tranchée le 10/08 et non renégociable** : la table `items` ne porte que
`price`. Il n'existe **aucun prix d'achat** dans cette base. On annonce donc « valeur offerte » =
chiffre d'affaires abandonné. Appeler ça un coût serait inventer une marge.

#### B4 · Bouton « nouvelle campagne »
Bascule `campaign_key` (aujourd'hui figée dans `WHEEL_CAMPAIGN`) vers le magasin de réglages. Remet
les compteurs de quantité à zéro **et** rouvre le droit de jouer à tous ceux qui ont déjà joué.
Confirmation obligatoire portant la phrase exacte de ce que ça fait — c'est irréversible du point de
vue du client.

### AXE C — Tablette du comptoir

`resources/views/admin/wheel/borne.blade.php` (707 l.).

- **C1 — hors périmètre** : déjà livré, et **inversé** par le propriétaire (§3.1).
- **C2 · Derniers gagnants.** Bandeau qui défile. Les comptes créés par la roue sont des invités
  clés par téléphone : **le prénom n'est pas garanti**. Donc « Il y a 4 min — un Tiramisu gagné »
  quand il manque. **Jamais de numéro, jamais de nom complet.** La tablette est une page rendue par
  le serveur derrière le code de la roue — les derniers gagnants ne sortent **pas** par une API publique.
- **C3 · Tenir un service entier.** Wake Lock API contre la mise en veille ; reprise du QR après
  coupure réseau avec repli **visible** (« reconnexion… ») au lieu d'un QR mort — aujourd'hui une
  coupure peut laisser la tablette sur un jeton périmé et le client scanne dans le vide.
- **C4 · Refonte visuelle.** Photos des produits sur les pastilles de l'acte 2. **Contrainte tenue du
  10/08, non négociable** : aucune boîte de hauteur devinée, aucun calque posé par-dessus un autre.
  Chaque bloc occupe une ligne ; c'est la roue qui cède la place, jamais le texte qui dit ce qu'on gagne.

### AXE D — Accès client

- **D1 · Ouverture au public.** `WHEEL_ENABLED=true` + déploiement. **Décision du propriétaire, en
  dernier**, une fois le reste prouvé.
- **D2 · QR de la roue sur le ticket de caisse.** Décision du propriétaire : **tous les tickets, sans
  condition de montant**. Volume borné par la règle existante « un tour par numéro et par campagne ».
  **Garde automatique** : on n'imprime pas un QR vers un jeu fermé — si `wheel.enabled` est faux (et
  hors clé d'aperçu), aucun QR n'est imprimé. Imprimer un chemin vers un 404 serait pire que ne rien
  imprimer. Interrupteur de configuration pour l'éteindre sans déploiement.
- **D3 · Lien direct depuis le site.** Contrepartie choisie : **le même parcours qu'au comptoir** —
  avis Google, abonnement, temps d'attente contrôlé par le serveur. Le lot reste à retirer **au
  comptoir** avec le minimum de commande : le client revient physiquement. Aucun assouplissement des
  gardes existantes.
- **D4 · Page « mon lot ».** Le client retape son numéro, revoit son lot et sa date de fin de
  validité. **Débit limité serré** : c'est un endpoint qui répond « ce numéro a gagné » — sans limite,
  on balaye des numéros pour savoir qui joue. Aucune session émise (piège déjà payé le 10/08 :
  « réclamer avec le numéro d'un autre donnerait sa session »).

### AXE E — Logo sur le ticket de caisse

`app/Services/Hardware/OrderReceiptEscPosRenderer.php:60-90` (en-tête établissement). **Non gelé**
(§7 ne liste que les services fiscaux, `BranchScope`, `IdempotencyKeyMiddleware`, `PricingService`,
`OrderStateMachine`).

Réemploi **exact** du chemin éprouvé du ticket promo :
- `EscPosCommandBuilder::rasterImage($path, $dots)` ;
- mise en cache par `md5(chemin|points|mtime)` comme `PromoFlyerEscPosRenderer::logoBytes()` ;
- **repli sur l'en-tête texte** si le logo est absent, illisible ou si le cache est indisponible — un
  ticket doit toujours sortir.

Configuration : chemin du fichier, largeur en points (défaut prudent, calé sur la largeur physique),
interrupteur. Le logo se pose **au-dessus** de l'en-tête : il ne remplace ni ne déplace **aucune**
mention fiscale.

**Réserve due au propriétaire** : un logo en couleur devient de la bouillie en thermique 1 bit. Un
**aperçu PNG de ce que l'imprimante va réellement sortir** lui est montré avant de poser le logo sur
le vrai ticket. S'il est illisible, on garde le texte — « ça fait classe » est le but ; un pâté gris
ne fait pas classe.

---

## 5. Zones gelées et portes humaines

| Élément | Statut | Porte |
|---|---|---|
| `public/js/pos-wizard.js` | **GELÉ §7** | **LOCK écrit** + accord donné en séance |
| `PaymentComponent.vue`, `PosV5TrancheRow.vue` | GELÉ §7 | **Non touchés** |
| Services fiscaux, `BranchScope`, `PricingService`, `OrderStateMachine` | GELÉ §7 | **Non touchés** |
| `OrderReceiptEscPosRenderer.php` | Non gelé | Mentions NF525 intactes, vérifiées par test |
| `BackendMenuComponent.vue` | Non gelé, **modifié dans l'arbre de travail** | Relire le diff, ne rien écraser |
| `WHEEL_ENABLED=true` + déploiement | — | **Décision du propriétaire, en dernier** |

---

## 6. Invariants à ne pas casser

1. **Les poids ne sortent jamais vers le navigateur.** `/wheel/config` publie des libellés et un
   résultat déjà tranché. Une roue dont le navigateur choisit le segment se gagne aux outils de
   développement en dix secondes. Le simulateur (B3) calcule **côté serveur**.
2. **`lotTirable()` reste la seule définition** de « ce lot peut-il être donné ici et maintenant ».
   La séparation demandée en §3.1 porte sur ce qui est **dessiné**, pas sur ce qui est **donné**.
   Motif « jumeau oublié », déjà payé quatre fois sur ce domaine : en changeant une garde, chercher
   qui d'autre répond à la même question.
3. **Le stock suit le cadeau.** `WheelDeliveryService::recordCost()` décrémente via
   `recordManualOutflow()` (motif `manual_out`, clé `wheel-gift-<spin_id>`). Changer le produit d'un
   lot change le produit décrémenté — c'est voulu, et c'est ce qui rend B1 sûr.
4. **NF525** : un cadeau n'est pas une vente. Il ne touche ni le tiroir, ni le Z, ni la chaîne
   fiscale. Le logo et le QR sur le ticket sont cosmétiques et ne déplacent aucune mention légale.
5. **Isolation de caisse** : `WheelSpin` est sous `BranchScope`. Les nouveaux endpoints ne le
   contournent pas.
6. **`element.hidden` n'a aucun effet** si une règle d'auteur pose un `display`. Toujours écrire
   `.classe[hidden]{display:none}` quand on pose un `display` d'auteur (défaut payé le 10/08).

---

## 7. Preuves exigées avant de rendre le travail

- PHPUnit : `Wheel`, `Pos`, `Loyalty`, `Hardware` (ticket), `Fiscal` — verts, comptes exacts.
- Vitest : composants POS touchés.
- **Zones gelées : `git diff --stat` = 0 ligne**, sauf `pos-wizard.js` sous LOCK, dont le diff est
  affiché ligne à ligne.
- `php artisan fiscal:verify-chain --all` — CHAIN OK.
- **Captures analysées** (Read, pas seulement prises) : barre caisse, menu admin, `/admin/roue`,
  `/admin/roue-reglages` (avec le tableau des lots et le simulateur), `/admin/roue-borne` en paysage
  **et** en portrait, page client.
- **Aperçu PNG du ticket** tel que l'imprimante le rendra (logo tramé 1 bit + QR).
- **Tests de mutation** sur les gardes neuves (passe signée, débit de « mon lot », garde du QR sur
  jeu fermé). Une mutation survivante accuse souvent le test, pas le code.
- Vérification explicite : **régler la roue après un gain non remis**, puis remettre ce lot.

---

## 8. Hors périmètre (dit explicitement)

- Écran d'historique des points pour le gérant (reste du 12/08).
- L'écran admin « Clients » qui affiche 0 point (10 composants) — reste masqué.
- Le crédit de points de la roue qui n'écrit rien dans `loyalty_transactions` (13 P2 en backlog).
- Élargir la liste des pilotes de cache du garde-fou de production (UNI-03, backlog cloud).
- Le déploiement lui-même : il reste une décision du propriétaire, prise après les preuves.
