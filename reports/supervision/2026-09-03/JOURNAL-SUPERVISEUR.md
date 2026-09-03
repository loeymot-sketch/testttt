# Journal du superviseur — 2026-09-03

## Ce que j'ai vérifié moi-même, sans déléguer

### 03h20 — Deux compteurs « à encaisser » à 400 px l'un de l'autre : CONTRÔLÉ, PAS UN DÉFAUT

En lisant la capture `02-tiroir-a-encaisser.png` du chantier caisse, le panneau de gauche
annonce « À ENCAISSER — COMPTOIR (5) » pendant que l'onglet du tiroir annonce **3**. C'est
exactement la forme d'un défaut déjà rencontré sur ce produit (« 0 à encaisser » et
« 2 à encaisser » à quarante pixels d'écart, corrigé le 28 août).

Vérification faite avant de crier au défaut :

- le panneau de gauche est la file d'encaissement **sans filtre de date** — l'endpoint n'en a
  pas et trie du plus ancien d'abord ;
- le tiroir est borné au **jour de service**, et annonce séparément les plus anciennes en pied
  (`pos-control-older-pending`, `PosControlDrawer.vue:245-255`), datées, avec un lien vers la
  page d'encaissement ;
- 3 du jour + 2 d'avant = les 5 du panneau. Ce n'est pas une contradiction, c'est une
  séparation délibérée et documentée (`PosComponent.vue:3039-3049`) ;
- et elle est **tenue par un banc** : `tests/js/posControlDrawer.spec.js:101,142,149,153` —
  bandeau présent à 584, absent à 0, et placé APRÈS les cartes du jour.

Le bandeau n'apparaît pas sur la capture, très probablement sous la ligne de flottaison du
tiroir. Ce n'est pas une preuve d'absence : le banc, lui, prouve la présence.

**Conclusion : rien à corriger.** Consigné parce qu'un futur lecteur de cette capture se posera
la même question, et qu'il vaut mieux qu'il trouve la réponse ici que de rouvrir le sujet.

### 03h05 — Chaîne NF525 relevée avant les correctifs d'ordre audit/mutation

CHAIN OK sur les 6 branches actives, 8 705 lignes, `max_id=8760`.
Détail dans `CHAINE-NF525-AVANT.txt`.

### 02h55 — Reconnaissance production, lecture seule

Le « dump de 20 octets » n'existe pas en production : c'est un fichier LOCAL d'un octet.
PHP 8.1 contre un script d'installation qui exige 8.4 : confirmé.
Une sauvegarde de sécurité **vide** trouvée, prise avant une synchro le 30 août.
Détail dans `G7-RECONNAISSANCE-PRODUCTION.md`.

### 02h50 — Point de décision G4 tranché

Trois surfaces lisaient le même dossier avec deux motifs. Décision et preuve dans
`G4-RAPPORT.md`, section « Complément superviseur ».

## Décisions d'ordonnancement

L'arbre est partagé avec d'autres sessions. Les chantiers ont été lancés par **domaines de
fichiers disjoints**, jamais deux qui écrivent au même endroit :

| Chantier | Domaine réservé |
|---|---|
| G1 caisse | `components/admin/pos/**`, `PosOrderController`, modules de files |
| G2 outbox | `SyncOverviewController`, `OutboxOverviewComponent` |
| G3 interrupteurs | `InterrupteurController`, `InterrupteurService`, zone texte de `SystemHealthComponent` |
| G4 sauvegarde | `Support/Backup/**`, `Commands/Backup/**`, `HealthController` |
| G5 dashboard | `components/admin/dashboard/**` |
| G8 vitrine | autre dépôt |

Aucune suite complète n'a été lancée pendant ce temps : deux autres sessions testaient sur le
même arbre, et un rouge issu d'une base partagée n'appartient à personne.

### 02h45 — Contrôles transverses (G6/T6.4, partiels)

| Contrôle | Résultat |
|---|---|
| `npm run pos:lint:status` | **OK** — 38 fichiers |
| `npm run i18n:audit` | 80 fichiers analysés, **0 en échec**. `de: 88` et `bn: 85` clés manquantes — langues hors périmètre V1 LOCAL FR |
| `npm run pos:lint:pricing` | **FAIL — 5 violations, TOUTES ANTÉRIEURES à cette mission** |

Les 5 violations de prix ont été vérifiées présentes au HEAD d'ouverture `28cd79d5a`, par
extraction de l'arbre d'alors :

- `PosComponent.vue:6287` — bloc autorisé dont la **signature est en retard de presque quatre
  mois** (`signoff-pending — date_limit: 2026-05-10`). Ce n'est pas un défaut de code : c'est un
  garde-fou qui attend une signature humaine depuis mai et que personne ne relance ;
- `KioskWizardComponent.vue` (3 blocs) — **zone gelée**, aucune retouche possible sans LOCK
  contresigné ;
- `PosCounterCollectModal.vue:562` — arithmétique de prix côté écran.

**Aucune n'est de cette mission.** Elles sont portées à la connaissance du propriétaire plutôt
que corrigées en douce : la première demande une signature, les trois suivantes un LOCK, et la
dernière une décision d'architecture (déporter le calcul au serveur).

### 02h40 — Une erreur d'attribution, de mon fait

Le commit `39fffecad` (caisse) a emporté six clés `fr.json` qui appartenaient au chantier
tableau de bord : j'ai commité le fichier pendant qu'un autre agent y écrivait. Les clés sont
justes et uniques, rien n'est cassé, mais le commit qui les porte n'est pas le bon.

C'est exactement le risque de l'arbre partagé, et je l'ai pris en commitant un fichier
transversal (`fr.json`) au lieu d'attendre. La règle à retenir : **un fichier de traduction est
partagé par tous les chantiers — il se commite en dernier, jamais avec un lot.**

Consigné dans le message du commit `bf66d8fab` plutôt que dissimulé.
