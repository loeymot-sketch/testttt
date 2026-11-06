# VAGUE E — CUISINE, ÉCRAN CLIENT ET ROUE — phase CAPTURE

- **Date** : 2026-08-25, 04:05–04:08 (Europe/Paris)
- **Spec** : `tests/e2e/audit-supervisor-waveE.spec.js` (5 tests, **5 passed**, mode série, 1 worker)
- **Artefacts** : `tests/e2e/__screenshots__/test-e2e-waveE/` — quartet complet par état
  (`.png` / `.dom.html` / `.console.json` / `.network.json`) + `observations.json` (texte extrait,
  brut, machine-lisible)
- **Serveur** : `http://127.0.0.1:8000` (déjà en ligne, non redémarré) — sert bien le worktree
  `goal/caisse-vision-2026-08-24`
- **Aucun code produit n'a été modifié.** Seuls le spec, les artefacts et ce rapport ont été écrits.

---

## 0. Garde d'environnement (obligatoire avant toute capture)

La panne signalée (worktree `vendor/` amputé de 1 244 fichiers, dont
`thecodingmachine/safe/lib/special_cases.php`) faisait rendre **HTTP 200 avec un corps ne contenant
qu'un `Warning: require … Failed to open stream`**. Une sonde de statut seule aurait menti.

Deux vérifications ont été posées :

1. **Dans le spec** — `allerVerifie()` relit le HTML après CHAQUE `goto` et **lève** si le corps
   porte `Warning: require`, `Fatal error`, `Failed to open stream` ou `Uncaught Error:`.
   Aucune capture de cette campagne n'a déclenché la garde.
2. **Chaîne d'actifs servie** — `GET /js/manifest.js` pointe sur `admin-kds.36ca7422.js`
   (le paquet **avec** le correctif `kdsExtraDisplayName`, construit le 24/08 à 20:58), et non sur
   `admin-kds.be8ed859.js` (paquet du 24/08 06:28, sans le correctif). Vérifié à la main :
   `curl /js/manifest.js | grep -o 36ca7422` → 1 occurrence, `be8ed859` → 0.
   **Les captures portent donc bien sur le code corrigé.**

Toutes les captures d'un premier passage antérieur ont été supprimées et refaites après réparation.

---

## 1. EXTRAS EN CUISINE — le point critique (état 2)

Commande semée : `AUDE-EXTRAS-1` (id 6738), 1 × item 22 « Cayenne », instantané NF525
`composition_snapshot.extras = [{extra_id:1, extra_name:"Salade", quantity:1},
{extra_id:2, extra_name:"Cheddar", quantity:2}]`, `status=7 (PREPARING)`, `branch_id=1`.

### 1.1 Écran cuisine V2 — **le défaut par défaut en production**

Fichier : `02-kds-extras-instantane-v2-defaut.png` / `.dom.html`

**Texte EXACT rendu par la carte cuisine** (`innerText` de l'élément `.kds-card`, sauts de ligne
conservés) :

```
[B]
EN COURS
CAISSE
N°6738
ATTENTE
00:16
1×
CAY | S
⭐ Cheddar ×2
Prêt
```

Découpage par ligne typée du gabarit V2 :

| classe CSS de la ligne | texte exact |
|---|---|
| `kds-line--symbolic-main` | `1× CAY \| S` |
| `kds-line--supplement`    | `⭐ Cheddar ×2` |

Classe de la carte : `kds-card kds-card--has-supplements`.

**Verdict sur le symptôme visé** :

- **Aucune virgule vide.** Le motif `Extras: , , ,` (regex `/Extras\s*:\s*(,\s*)+/i`) est
  **absent de toute la page** — `motifVirgulesVidesDansLaPage: false`.
- **« Cheddar » est écrit en toutes lettres**, avec sa quantité : `⭐ Cheddar ×2`.
- **« Salade » n'apparaît PAS en toutes lettres sur la carte V2.** Attendu : oui, et ce n'est
  pas le bogue réparé — c'est le rendu **symbolique** voulu. `kdsSymbolic.js` replie une garniture
  gratuite reconnue dans le créneau « crudités » de la ligne 1
  (`CRUDITE_TABLE = [[/salade/,'S'], …]`, `buildSymbolic()` ligne ~466 : `if (cs && price <= 0)
  crud.add(cs)`). Le `S` après `CAY |` **EST** la Salade. L'instantané semé ne porte ni
  `unit_price` ni `line_total`, donc `price = 0` → repli crudité.
  Le mot complet est lisible **une seule fois** : dans le panneau « Afficher les noms ».

Fichier : `02b-kds-legende-symboles.png` — la légende dépliée donne bien, colonne « Crudités » :
`S → Salade`, `T → Tomate`, `O → Oignon`, `O̲ → Oignons cuits`.
**Observation (non corrigée)** : ouvrir cette légende pousse la grille vers le bas et **coupe la
carte** — sur la capture, `1× CAY | S` est tronqué à mi-hauteur par le bandeau « Prêt » et la ligne
`⭐ Cheddar ×2` **disparaît entièrement du champ visible**. L'écran qui explique le symbole masque
donc le supplément qu'il devait aider à lire.

### 1.2 Écran cuisine hérité (`?v2=0`) — **le chemin réparé, littéralement vert**

Fichier : `03b-kds-herite-v2-0.png` / `.dom.html`

C'est ce gabarit qui porte le libellé « Extras: » et la méthode corrigée `kdsExtraDisplayName`
(`KitchenDisplaySystemComponent.vue:2860-2865` — `extra.extra_name || extra.name || extra.item_name`).

**Texte EXACT rendu**, blocs relevés dans l'ordre du DOM :

```
Extras: Cheddar                ← commande voisine réelle (borne CF921)
Extras: Salade, Cheddar        ← AUDE-EXTRAS-1, planche « Préparations » (items board)
Extras: Salade, Cheddar        ← AUDE-EXTRAS-1, carte de la colonne « À emporter »
Extras: Cheddar                ← commande voisine réelle (borne CF921)
```

Visuellement confirmé sur la capture, panneau gauche « Préparations » :

```
Cayenne                                    1
Extras: Salade, Cheddar
```

`motifVirgulesVides: false`. **Les deux noms sortent, séparés par une virgule, sans entrée vide.**

### 1.3 Conclusion de la section

Le défaut « `Extras: , , ,` » **n'est reproductible sur aucune des deux dispositions**.
Le correctif est vérifié **là où le symptôme vivait** (gabarit hérité + planche d'articles).
En V2 (défaut de production) le rendu est symbolique par conception : « Cheddar » en clair,
« Salade » repliée en `S`, sans perte d'information mais avec une **dépendance à la légende**,
elle-même gênée par le défaut de mise en page décrit ci-dessus.

---

## 2. Tableau état par état

| # | État | Fichier(s) | Attendu | Observé |
|---|---|---|---|---|
| 1 | `/kds` au chargement | `01-kds-chargement.*` | La grille cuisine se charge, barre unique, pas de page blanche | **OK.** HTTP 200, redirection SPA vers `/admin/kitchen-display-system`, grille V2 active, `[data-testid="kds-toolbar"]` présent, 1 carte (`N°CF921`, commande réelle de la base), corps non vide (450 kB de HTML) |
| 2 | `/kds` avec extras d'instantané | `02-kds-extras-instantane-v2-defaut.*`, `02b-kds-legende-symboles.*` | « Salade » et « Cheddar », pas de virgules vides | **Voir §1.** Carte trouvée (`N°6738`), `⭐ Cheddar ×2` en clair, `Salade` repliée en `S` (rendu symbolique), **aucune virgule vide** |
| 3 | Bascule V2 | `03a-kds-v2-explicite.*`, `03b-kds-herite-v2-0.*` | Un drapeau doit permettre la bascule | **Le drapeau existe.** `?v2=1` / `?v2=0` (précédence : URL > `localStorage kds.v2_enabled` > `window.FK_KDS_V2_DEFAULT_ENABLED` > `true`). **V2 est le DÉFAUT** (`config/kds.php` `v2_default_enabled=true`). `?v2=1` → grille V2, 2 cartes, pas de planche d'articles. `?v2=0` → grille V2 absente, planche `#item-order` présente, colonnes Sur place / En ligne / À emporter / Borne. Les deux en HTTP 200, aucune erreur JS de page |
| 4 | `/admin/order-status-screen` | `04a-ecran-client-sans-commande-eligible.*`, `04b-ecran-client-avec-commande.*` | Le mur client se charge | **OK, deux états.** 4a : « En préparation → Aucune commande en préparation », « Prêt → Aucune commande prête ». La commande POS semée n'y figure pas — **par conception** : `OrderStatusScreenOrderService:45-63` est fail-closed sur `order_type ∈ {KIOSK, TAKEAWAY}` **et** (`token` ou `queue_number` non nul). 4b : après semis d'une commande éligible (`AUDE-EXTRAS-OSS`, à emporter, `queue_number=777`), le mur affiche **`N°777`** sous « En préparation ». Aucun libellé brut (`label.x`), aucun `undefined`/`NaN`/`[object Object]` |
| 5 | 6 pages « roue » | `05-A-*` (6), `05-B-*` (6) | Une capture chacune | **12 captures.** Voir §3 |

---

## 3. Les 6 pages « roue » — deux passes

### Passe A — accès direct par URL, rien de déverrouillé (`05-A-*`)

C'est ce que voit un poste qui tape l'adresse après une connexion normale à la SPA.
La session Playwright est authentifiée par **jeton Bearer** ; `EnsureWheelAccess` cherche d'abord
une **session web** habilitée `pos` — que `LoginController` détruit — puis le code de la maison.

| Page | HTTP | URL finale | Observé |
|---|---|---|---|
| `/admin/roue` | 200 | `/admin/roue` | Écran « La roue » + champ **CODE** + bouton **Ouvrir**. Pas de redirection |
| `/admin/roue-validation` | 200 | `/admin/roue` | **Redirigée** vers l'accueil, bandeau « Entre le code pour ouvrir les écrans de la roue. » |
| `/admin/roue-borne` | 200 | `/admin/roue` | idem |
| `/admin/roue-lot` | 200 | `/admin/roue` | idem |
| `/admin/roue-historique` | 200 | `/admin/roue` | idem |
| `/admin/roue-reglages` | 200 | `/admin/roue` | idem |

**Une passe d'accès EST exigée. Elle n'a pas été forcée** : la redirection est propre (une page
HTML avec le champ du code, jamais un JSON `unauthenticated`), ce qui est exactement le
comportement documenté par `EnsureWheelAccess::refus()`.

### Passe B — le chemin d'accès prévu par le produit (`05-B-*`)

Le code de la maison a été saisi **dans le formulaire officiel de `/admin/roue`**
(`WHEEL_PIN` lu depuis l'environnement). Ce n'est pas un contournement : c'est le chemin 2 que
le middleware décrit. Les 6 écrans se sont ensuite ouverts.

| Page | HTTP | Titre | Observé |
|---|---|---|---|
| `/admin/roue` | 200 | « La roue — Le Cayenne » | Tableau de bord : 5 tuiles nommées (Débloquer un tour / Remettre un lot / Écran vitrine / Réglages / Historique) + bloc « OÙ EN EST LE JEU » (fermé au public, lien d'avis de secours, Instagram/Snapchat non renseignés) |
| `/admin/roue-validation` | 200 | « Roue — valider un tour » | Carte « VALIDER UN TOUR DE ROUE », 3 conditions numérotées, gros CTA « VALIDER — il peut tourner », lien « Remettre un lot gagné → » |
| `/admin/roue-borne` | 200 | « Le Cayenne — Tourne la roue » | Roue dessinée (7 segments), colonne « À GAGNER », QR code, tunnel Scanne → Tourne → Gagne. **Deux vignettes cassées** : voir §4 |
| `/admin/roue-lot` | 200 | « Roue — remettre un lot » | Deux recherches (code `ROUE-FLZ5EN` en exemple, téléphone), lien retour |
| `/admin/roue-historique` | 200 | « Roue — historique » | Filtres Aujourd'hui / 7 j / 30 j / 90 j ; 5 lignes réelles (19/08/2026), colonnes QUAND / LOT / CLIENT / CODE / ÉTAT / REMIS ; téléphones masqués (`Sarah …`, `Audit …9901`) ; second tableau « PARCOURS COMMENCÉS ET NON TERMINÉS » (1 ligne) |
| `/admin/roue-reglages` | 200 | « Roue — réglages » | Bandeau « Aucun lien n'est de TOI pour l'instant » + diagnostic ligne à ligne (Avis Google = secours, Instagram absent, Snapchat absent, Facebook = valeur livrée), champ lien d'avis, case « Obligatoire pour tourner » |

Sur les 6 : **aucun libellé brut** (`label.*` / `message.*`), **aucun** `undefined` / `NaN` /
`[object Object]`.

---

## 4. Observations relevées (non corrigées — phase capture)

| # | Surface | Fait mesuré | Preuve |
|---|---|---|---|
| E-O1 | `/kds` V2 | Ouvrir « Afficher les noms » (légende des symboles) pousse la grille et **tronque la carte** : `1× CAY \| S` coupé à mi-hauteur, `⭐ Cheddar ×2` hors champ. La légende qui explique `S = Salade` cache le supplément | `02b-kds-legende-symboles.png` |
| E-O2 | `/admin/roue-borne` | **2 images de lot en 404** : `/storage/8/coca.png` (Boisson) et `/storage/7/frites.png` (Frites). Vignettes blanches dans « À GAGNER » **et** segments sans image sur la roue, face client | `05-B-roue-borne.network.json`, `.console.json`, `.png` |
| E-O3 | `/kds` hérité | La même commande est étiquetée **`CAISSE`** en V2 et **`N° Commande: En Ligne`** sur la carte héritée (`source_surface='pos'`, `order_type=POS`). Les deux dispositions ne nomment pas la même source | `02-*.png` vs `03b-*.png` |
| E-O4 | toutes surfaces admin | 404 récurrent sur `/storage/1/english.png` (drapeau de langue de l'en-tête), présent sur chaque capture admin | tous les `*.network.json` |
| E-O5 | `/admin/order-status-screen` | Le mur **client** affiche l'en-tête admin complet (logo, « Tableau De Bord », « Bonjour Admin Le Cayenne », e-mail, `237,80 €`, « Déconnexion ») au-dessus des colonnes | `04b-ecran-client-avec-commande.png` |
| E-O6 | toutes surfaces | La **Laravel Debugbar** est affichée en bas de chaque capture (Request / Timeline / Queries). Bruit d'environnement de développement, pas un défaut produit ; signalé pour que le superviseur ne le lise pas comme du contenu | toutes les `*.png` |
| E-O7 | `/kds` | `ERR_CONNECTION_REFUSED` répété en console : le serveur de diffusion (soketi/Reverb, port 6001) n'est pas lancé ici. La cuisine bascule en scrutation — comportement de repli attendu en dev | `01/02/03/04-*.console.json` |
| E-O8 | environnement | Des commandes d'AUTRES vagues parallèles sont visibles sur la cuisine pendant la campagne (bandeau « ANNULÉES — RETIRER DU PASSE (2) », `N°A0034 … « Wave E canonical afterAll cleanup »`). Le nombre de cartes varie d'une capture à l'autre pour cette raison, pas à cause d'un défaut | `01-*.png`, `02-*.png` |

Aucune erreur JavaScript de page (`pageerror`) n'a été capturée sur aucune des 12 captures
applicatives. Les seules entrées `error` de console sont les 404 d'images ci-dessus et le
WebSocket de diffusion absent.

---

## 5. Semis et nettoyage

- Préfixe **exclusif** `AUDE-` sur `order_serial_no` :
  - `AUDE-EXTRAS-1` — POS, `source_surface='pos'`, `status=7`, instantané Salade/Cheddar
  - `AUDE-EXTRAS-OSS` — TAKEAWAY, `source_surface='kiosk'`, `queue_number='777'`, même instantané
    (nécessaire : le mur client est fail-closed sur KIOSK/TAKEAWAY, une commande POS n'y paraît
    jamais)
- Nettoyage exécuté **au début** (`NETTOYE=0`) **et à la fin** (`NETTOYE=2`) de la campagne :
  `DELETE FROM order_items` puis `orders WHERE order_serial_no LIKE 'AUDE-%'`.
  **Aucune ligne `AUDE-` ne subsiste.**
