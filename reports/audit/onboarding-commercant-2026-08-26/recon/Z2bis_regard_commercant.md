# Regard neuf — « Je m'appelle Chez Nadia, pas Le Cayenne. Où je change ça ? »

Session : jeu de rôle « patron de kebab qui vient d'acheter le logiciel ». Lecture
seule stricte — aucun formulaire enregistré. Connexion faite pour pouvoir
naviguer (compte trouvé dans `database/seeders/UserTableSeeder.php` :
`admin@lecayenne.fr` / `123456`, seul admin actif). Navigation réelle via
Chrome (MCP claude-in-chrome), captures de texte de page (`get_page_text`) et
lecture de code Vue/router **uniquement pour comprendre ce que l'écran
affichait**, jamais comme point de départ. Rien lu dans `plans/`, `GOAL_*`, ni
dans les rapports `Z2_*` / `Z2bis_*` déjà présents dans ce dossier — regard
volontairement non informé par les audits précédents.

Serveur : http://127.0.0.1:8800 (Laravel + Vue SPA, `<default-component />` —
curl seul ne montre rien du contenu, navigateur nécessaire).

---

## Ce que j'ai réellement vu, écran par écran

### 1. Connexion
`GET /login` → formulaire « Bon Retour », champs **Email** / **Mot De Passe**,
case **Se Souvenir De Moi**, lien **Mot De Passe Oublié**, bouton **Connexion**.
Rien à reprocher ici : clair, en français.

### 2. Tableau de bord
`GET /admin/dashboard` après connexion. Titre « Bon Après-Midi ! » / « Admin Le
Cayenne ». Le logo **« LE CAYENNE »** (mascotte homard/écrevisse orange) est en
haut à gauche partout — c'est la seule identité visuelle visible sur cet
écran, aucun raccourci « personnaliser mon restaurant » nulle part sur le
tableau de bord.

### 3. Entreprise — `GET /admin/settings/company`
**Chemin réel depuis le Dashboard : scroller la barre latérale sur ~28 items
avant de voir « Paramètres », puis 1 clic → j'atterris directement sur
« Entreprise ».** Rien dans le libellé « Paramètres » ni sa position (tout en
bas, sous Rapports, après Transactions, Chefs, Employés, Administrateurs...)
ne suggère « c'est ici que je mets le nom de mon resto ». Un patron pressé
abandonnerait avant d'arriver en bas de cette liste.

Champs (verbatim) : **NOM**, **EMAIL**, **TÉLÉPHONE**, **SITE WEB**, **VILLE**,
**ÉTAT**, **INDICATIF PAYS**, **CODE POSTAL**, **ADRESSE**. Valeurs pré-remplies :
« Le Cayenne », `contact@lecayenne.fr`, `+33600000000`, `https://lecayenne.fr`,
Paris, Île-de-France, France (FRA), 62110, « 437 Rue Élie Gruyelle ».

- **Friction vocabulaire** : le champ **« ÉTAT »** (label source `state`,
  vérifié `resources/js/languages/fr.json:1379` : `"state": "État"`) sert en
  réalité à saisir la **région** (« Île-de-France »). En français, « État »
  désigne un pays ou un statut, jamais une région administrative — un
  commerçant taperait « France » ou hésiterait, pensant à un statut de compte.
- Aucun aperçu, aucune explication de ce que « NOM » change concrètement
  (le nom sur le ticket ? sur la facture ? le nom de connexion ?).
- Pas de bouton Annuler ; le seul CTA est **Enregistrer** — j'ai arrêté là,
  lecture seule.

### 4. Site — `GET /admin/settings/site`
Accessible en 1 clic depuis le sous-menu Paramètres. Page technique, aucun
champ de marque. Libellés verbatim relevés : **FORMAT DE DATE**, **FORMAT
HORAIRE**, **FUSEAU HORAIRE PAR DÉFAUT**, **BRANCHE PAR DÉFAUT**, **LANGUE PAR
DÉFAUT**, **« PASSERELLE SMS PAR DÉFAUT »**, **« LIEN APPLICATION ANDROID »**,
**« IOS APPLICATION LIEN »** (ordre des mots anglais laissé tel quel — devrait
être « Lien application iOS »), **« CLÉ GOOGLE MAPS »**, **« CHIFFRES APRÈS LA
VIRGULE ( EX. : 0,00 ) »**, **« PASSERELLE DE PAIEMENT EN LIGNE »**,
**« DEBUG APPLICATION »** (mot anglais brut, aucune traduction), **« CONNEXION
INVITÉ »**, **« SÉLECTEUR DE LANGUE »**. Aucune conséquence expliquée nulle
part : un patron de kebab qui tombe sur « DEBUG APPLICATION : Activer /
Désactiver » ou « PASSERELLE SMS PAR DÉFAUT » n'a aucune idée de ce que ça
fait, et rien ne l'avertit qu'un mauvais réglage ici casse la caisse.

### 5. Marque / logo (« Thème ») — `GET /admin/settings/theme`
**Introuvable par navigation.** J'ai listé le sous-menu réel de Paramètres,
visible à l'écran (`get_page_text` sur `/admin/settings/company` et
`/admin/settings/site`) : **Entreprise, Site, Filiales, Bornes, Rapports Z
(NF525), Imprimantes, Terminaux De Paiement (TPE), Configuration Des
Commandes, Configuration Borne, Fidélité, Devises.** Aucune entrée
« Thème » / « Marque » / « Logo ». J'ai confirmé dans le code
(`resources/js/config/v1-hidden-modules.js`) que `'settings.theme'` fait
partie de `V1_HIDDEN_MENU_MODULES` — le commentaire du fichier dit lui-même :
« Restent accessibles par URL directe » — c'est-à-dire jamais par un clic.
En tapant l'URL à la main, la page existe et fonctionne : titre **Thème**,
champs **« LOGO (128PX,43PX) »**, **« ICÔNE FAVORITE (120PX,120PX) »**,
**« LOGO DU PIED DE PAGE (144PX,48PX) »**, avec le logo mascotte « LE CAYENNE »
déjà affiché dans les trois zones. Aucun champ de **couleur** nulle part
(confirmé en lisant `ThemeComponent.vue` en entier : seulement 3 champs
fichier, zéro color-picker) — la couleur orange de marque n'est changeable par
aucun écran admin, à aucune URL.
**Réponse à la question directrice : « Chez Nadia » ne peut PAS trouver cet
écran en cliquant. 0 clic possible. Il faut connaître l'URL exacte.**

### 6. Ticket (reçu de caisse)
Aucune page de personnalisation du contenu du ticket imprimé (en-tête, pied de
page, mentions) trouvée dans le routeur (`resources/js/router/modules/
settingRoutes.js`) ni dans le sous-menu. La seule page proche,
**Imprimantes** (`GET /admin/settings/printers`, visible et cliquable), ne
configure QUE la connexion réseau à l'imprimante — pas ce qui s'imprime.
Contenu réel vu à l'écran : deux imprimantes nommées **« PROC8 Kitchen »**
(station Cuisine (Chaud), IP `127.0.0.1:9101`) et **« SAGA Caisse (test) »**
(station Ticket Caisse, IP `127.0.0.1:9100`) — la mention **« (test) »** dans
le nom et l'IP `127.0.0.1` (poste local, pas une vraie imprimante réseau)
montrent que ce sont des données de test laissées dans la base, visibles
telles quelles par un vrai commerçant. Statut affiché pour les deux : **Archivé**.

### 7. Horaires — `GET /admin/settings/time-slots`
Même situation que Thème : **absent du sous-menu visible**, confirmé caché
dans `v1-hidden-modules.js` (`'settings.time-slots'`). Accessible seulement en
tapant l'URL. Une fois dessus : titre bien traduit **« Créneaux Horaires »**,
mais les 7 jours affichés sont en anglais brut, non traduits : **« Monday »,
« Tuesday », « Wednesday », « Thursday », « Friday », « Saturday », « Sunday »**
— chacun avec un seul bouton **« Ajouter »**, aucun horaire par défaut. Un
patron de kebab francophone tombe sur une page à moitié en anglais, vide, sans
explication.

### 8. TVA (« Taxes ») — `GET /admin/settings/taxes`
Même chemin caché que Thème/Horaires (`'settings.tax'` dans
`v1-hidden-modules.js`) : 0 clic possible, URL à deviner (et le mauvais nom
d'URL, `/admin/settings/tax`, renvoie une page 404 générale du site vitrine —
`Page Non Trouvée !` — pas même une 404 admin ; la bonne URL est
`/admin/settings/taxes`).
Une fois dessus, le contenu réel est alarmant : **53 lignes**, toutes nommées
**« AUDIT-KIOSK-MULTI TVA 0 <code hexadécimal> »** (ex. « AUDIT-KIOSK-MULTI
TVA 0 8A48E0 », code `AKM-8A48E0`), toutes à **0.00 %**, toutes au statut
**« Inactif »**. Ce sont clairement des résidus de tests automatisés, pas des
taux de TVA réels (aucun 5,5 %, 10 % ou 20 % visible sur les 10 premières
lignes). Un commerçant qui vient chercher « où je mets 10 % pour la
restauration sur place » tombe sur 53 entrées poubelle sans rapport.

### 9. Filiales (« Branches ») — `GET /admin/settings/branches/list`
**Écran visible et cliquable normalement** (contrairement aux 3 précédents),
donc c'est la première chose qu'un commerçant croise en explorant Paramètres.
Contenu réel : 6 lignes, dont **5 filiales fictives générées automatiquement**
avec des noms anglais à consonance d'entreprise US — **« Collier and Sons
Branch »**, **« Skiles-Johns Branch »**, **« Brekke, Kub and Reichert Branch »**,
**« Shields Inc Branch »**, **« Stiedemann and Sons Branch »** — sans aucune
valeur dans la colonne STATUT. Seule la 6ᵉ ligne, **« Le Cayenne
(principal)(Par défaut) »** (parenthèses collées sans espace), a un statut
**Actif**. Pour « Chez Nadia », voir 5 succursales inconnues aux noms
américains dans SON compte tout neuf est le signal le plus fort que quelque
chose ne va pas — « est-ce que mes données sont mélangées avec quelqu'un
d'autre ? ».

### 10. Détail annexe vérifié : téléphone mal formaté
Dans le menu profil (coin haut droit, visible sur chaque page admin), sous
« Admin Le Cayenne » : le numéro affiché est **« +330600000000 »** (13
chiffres, invalide) — vraisemblablement `+33` concaténé à `0600000000` sans
retirer le zéro initial. Vu deux fois via `get_page_text` (`/admin/settings/
company` et `/admin/settings/printers`).

---

## Les 8 frictions les plus fortes (classées par risque d'abandon)

1. **« Filiales » montre 5 succursales fictives aux noms anglais** (« Collier
   and Sons Branch », etc.) sans statut, mélangées à la vraie — `GET
   /admin/settings/branches/list`. Casse la confiance immédiatement : « mes
   données sont-elles partagées ? »
2. **TVA (« Taxes ») est invisible dans le menu ET pleine de déchets de test**
   — 53 lignes « AUDIT-KIOSK-MULTI TVA 0 <hex> » à 0,00 %, Inactif — `GET
   /admin/settings/taxes/list`. Impossible de trouver ni de comprendre où
   mettre son vrai taux.
3. **Marque/logo (« Thème ») introuvable par clic : 0 chemin de navigation.**
   Caché volontairement (`v1-hidden-modules.js`), accessible seulement en
   devinant `/admin/settings/theme` — `GET /admin/settings/theme`. Aucune
   personnalisation de couleur n'existe nulle part.
4. **Horaires : jours de la semaine non traduits « Monday », « Tuesday »…**
   sur une page elle-même invisible dans le menu — `GET
   /admin/settings/time-slots`.
5. **Aucune page pour personnaliser le contenu du ticket de caisse** (en-tête/
   pied de page) ; « Imprimantes » ne gère que l'IP réseau, et affiche
   « SAGA Caisse **(test)** » à `127.0.0.1:9100`, du texte de test resté visible
   — `GET /admin/settings/printers`.
6. **« Paramètres » est tout en bas d'une liste de ~28 items sans distinction
   visuelle**, après Chefs, Transactions, Rapport Articles — rien ne dit
   « c'est ici que je personnalise mon resto » — `GET /admin/dashboard`.
7. **Vocabulaire technique brut non traduit sur « Site »** : « DEBUG
   APPLICATION », « PASSERELLE SMS PAR DÉFAUT », « IOS APPLICATION LIEN »,
   « CLÉ GOOGLE MAPS » — aucune explication de conséquence, aucun aperçu —
   `GET /admin/settings/site`.
8. **Champ « ÉTAT » = en réalité la région** (« Île-de-France »), mot qui en
   français désigne un pays, pas une province — traduction brute de `"state":
   "État"` (`resources/js/languages/fr.json:1379`) — `GET
   /admin/settings/company`.

Note complémentaire (non classée ci-dessus, NON revérifiée en profondeur) :
« +330600000000 » affiché dans le menu profil sur toutes les pages admin —
format de téléphone invalide. NON VÉRIFIÉ au-delà de l'affichage (je n'ai pas
cherché la source du bug dans le code).
