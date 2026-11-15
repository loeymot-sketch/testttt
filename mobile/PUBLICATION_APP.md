# Publier l'app mobile Le Cayenne (`mobile/`) — 2026-11-14

Ce dossier est l'app que le propriétaire a désignée comme « l'application réelle » le 2026-11-14.
Audit superviseur complet (captures écran par écran, relecture adverse en 3 tours) :
`testttt/reports/test-e2e/app-mobile-supervision-2026-11-14/`.

## 1. Ce qui est prêt

- **Carte = serveur.** `api/catalog-sync.js` relit `/api/frontend/item?branch_id=1` à chaque ouverture et au
  retour au premier plan : nom, prix, rupture (« Épuisé »), boissons de menu en rupture masquées. Un produit
  inconnu du serveur est retiré. `data/menu.js` ne sert plus que de repli hors ligne et de structure des assistants.
- **Options = profil de composition publié.** L'assistant n'affiche que les viandes / sauces / suppléments /
  crudités du profil serveur du produit (`LC.mobileApi.optionNames`) ; la commande n'envoie que ces choix ; une
  option inconnue arrête la commande avec un message (jamais d'abandon silencieux).
- **Cuisine.** Consignes au format lu par `FrontendOrderService::instructionWebAssainie` : une rubrique par ligne
  (`Boisson menu: …`, `Sauce frites : …`, `[Note : …]`).
- **Horaires = serveur** (`/api/frontend/horaires`).
- **Navigation sans compte** (App Store 5.1.1(v)) : le numéro n'est demandé qu'au moment de commander ou pour
  Commandes / Profil / Fidélité.
- **Aucune fausse fonction** : promo locale, cloche, favoris, Wallet, carte plastique, faux QR retirés ; recherche
  réelle ; liens légaux réels (`/legal/privacy.html`, `/legal/cgv.html`, `/legal/mentions.html`) ; suppression de
  compte réelle.
- **Allergènes** : plus de liste devinée — renvoi vers l'équipe tant que les fiches ne sont pas saisies.

## 2. Construire le paquet publiable

```bash
# depuis la racine du worktree, Node ≥ 20
node mobile/tools/build-www.mjs --prod --out mobile/www \
  LC_API_KEY=<clé publique du client web, la même que le site>   # variable d'environnement
# LC_API_BASE=https://vps-418872ac.vps.ovh.net par défaut
```

Le script compile le JSX à l'avance (plus de Babel dans le téléphone), embarque React 18.3.1 production
(`mobile/vendor/react/`), injecte l'API de production et ne copie que les images utilisées (~30 Mo).
Vérifié le 2026-11-14 contre la production (lecture seule) : démarrage sans erreur, carte « live », Bol Riz et
Glace affichés « Épuisé » comme en caisse.

## 3. Emballage natif (Capacitor) — décision du propriétaire AVANT toute signature

L'app des stores déjà publiée (site emballé, `lecayenne-web-deploy/Site lecayenne/app/`) utilise
**`fr.lecayenne.app`**. Deux voies :

1. **Remplacer l'app actuelle** : mettre `mobile/www` comme `webDir` du projet Capacitor existant
   (`app/capacitor.config.*`), même identifiant, nouvelle version. Les clients existants reçoivent cette app en
   mise à jour. Prérequis : origine `https://localhost` déjà autorisée par le CORS du serveur ; garder le
   correctif de barre d'état (`styles.xml`) noté dans l'audit de l'app actuelle.
2. **Nouvelle fiche** : nouvel identifiant (ex. `fr.lecayenne.commande`), nouveau projet Capacitor, nouvelle
   fiche App Store / Play — deux apps coexistent.

Dans les deux cas : `npx cap sync`, icônes/écran de démarrage, signature (Xcode / clé Play) par le propriétaire.

### Barre d'état, encoche et barre d'accueil (2026-11-15)

L'app règle elle-même ses marges à partir de ce que dit le téléphone (jetons `--lc-*` de `styles.css`,
détail dans `README.md`). Elle a été mesurée dans les deux réglages possibles de l'enveloppe native :

- **l'app dessine sous la barre d'état** (réglage conseillé : la bande prend la couleur de l'écran) —
  iOS `ios.contentInset: "never"`, `StatusBar.overlaysWebView: true`, icônes sombres ; Android : affichage
  bord à bord par défaut de Capacitor 8, `SystemBars` laissé sur `insetsHandling: "css"` (il pose
  `--safe-area-inset-*`, lues par l'app sur les Android où `env()` vaut 0) ;
- **l'enveloppe réserve déjà la barre d'état** (réglage actuel du projet `fr.lecayenne.app` :
  `contentInset: "always"`, `overlaysWebView: false`) — l'app reçoit alors une marge nulle et se cale dessus ;
  la bande du haut garde la couleur de fond de l'enveloppe (`#0A0A0A`, donc noire).

Mesuré au banc (`tools/banc-marges/`) sur 6 profils — iPhone à encoche, petit iPhone, Android à gestes,
Android à 3 boutons, Android ancien, sans marge — source et paquet construit : aucun texte ni bouton dans
une marge. **Reste à confirmer sur un vrai iPhone et un vrai Android** après `npx cap sync` (le banc simule
les marges ; il ne remplace pas l'appareil).

## 4. Reste à faire (hors code de l'app)

- Fiches allergènes réelles à saisir côté restaurant (le serveur n'en a que pour 1 produit sur 72).
- « Glace » illustrée par un pot Ben & Jerry's : confirmer que c'est bien le produit vendu.
- Polices Google chargées en ligne (repli système hors ligne) : à embarquer si souhaité.
