# GOAL WEB — AUDIT ADVERSARIAL + UX/PSYCHO + MOBILE · Rapport cycle 1
**Date** : 2026-08-05 · **Plan** : `plans/GOAL_WEB_ADVERSARIAL_UX_TOTAL_2026-08-05.md`
**Web** : `lecayenne-web-deploy/Site lecayenne` — `e15bb42` → `37e0fd6` (2 commits, **non poussés**)
**Backend** : correctif fidélité en historique (`048aa2637`) — sentinelle verte au HEAD courant

⚠️ **Le dépôt backend est modifié EN PARALLÈLE par une autre session** (HEAD a avancé de
plusieurs commits pendant la mission ; mes fichiers stagés ont été balayés dans son commit).
Contenu intact et vérifié, mais `PROJECT_BRAIN.md §2` n'a **pas** été mis à jour par moi pour
ne pas écraser son état. À arbitrer par l'owner (cf. `PARALLEL_PROTOCOL.md`).

---

## 1. Dispositif

6 auditeurs adversariaux en parallèle, chacun **épinglé au SHA**, lecture seule, avec contrat
de preuve : tout finding exige `fichier:ligne` + code cité + **commande de reproduction
exécutée**, sinon marqué SPÉCULATIF. Puis vérification personnelle (rôle procureur) des
findings les plus lourds, puis parcours d'achat **réel en navigateur** (desktop 1440 + mobile
390) : menu → fiche → wizard 7 étapes → récap 10,80 € → panier → checkout.

| Agent | Terrain | P0 | P1 | P2 | P3 |
|---|---|---|---|---|---|
| A1 — L'ABANDONNEUR | chemins d'annulation/abandon | 0 | 4 | 4 | 2 |
| A2 — LE COMPTABLE | money-path affiché/facturé/scellé | 0 | 1 | 0 | 3 |
| A3 — L'INTRUS | takeover compte/commande | 0 | 0 | 1 | 2 |
| A4 — LE PSYCHOLOGUE | UX, prix, ton, upsells | 3 forts | 5 moyens | 3 faibles | — |
| A5 — LE POUCE | mobile réel | 1 bloquant | 6 gênants | 3 cosmétiques | — |
| A6 — LE COMPTEUR | fidélité & points | 0 | 2 | 3 | 2 |

**Aucun P0 sur les 6 axes.** Le cœur argent est architecturalement verrouillé (garde
`expected_total` + 422 + rollback, scellement fail-loud, 38/38 items en parité prix front↔DB),
et les takeovers de compte du 2026-08-04 sont fermés sur tous les chemins de mint de token.

---

## 2. Les deux plaintes de l'owner : reproduites, expliquées, corrigées

### « J'annule l'enregistrement et ça se connecte bizarrement » — CORRIGÉ
Le code OTP validé posait le token Sanctum (30 jours) dès la 4ᵉ touche, mais l'état `isAuth`
n'était levé **que** par le bouton « Commencer à commander ». Fermer par la croix, Escape ou
le fond laissait donc : compte réellement créé, token valide, en-tête affichant
« Se connecter », checkout proposant de se connecter — et un simple rechargement basculait
tout seul en « connecté ». L'UI se resynchronise désormais sur la seule source de vérité.

### « J'annule le paiement et la commande passe quand même »
Deux causes distinctes, une corrigée, une **escaladée** :

1. **Cul-de-sac « carte refusée » — CORRIGÉ.** Les deux issues que le message proposait au
   client étaient fermées par un 409 d'idempotence : réessayer avec une carte corrigée
   (nouveau `card_token` sous une clé stable par commande) et basculer sur « Payer sur place »
   (même clé, `payment_method` différent) renvoyaient tous deux `IDEMPOTENCY_KEY_CONFLICT` —
   le second affichant le message technique anglais brut au client. Les clés sont désormais
   scopées à la **tentative** et au **mode de paiement** ; la protection anti-double-débit du
   2026-08-03 est intégralement conservée (même jeton = même clé).

2. **Commande carte web diffusée en caisse/cuisine AVANT paiement — ESCALADE OWNER.**
   Le garde-fou anti-« ghost order » n'existe que pour la borne
   (`FrontendOrderService.php:250`). Une commande web carte est donc poussée en caisse dès sa
   création ; si le client ferme l'onglet pendant le 3-D Secure, elle n'est annulée qu'à
   l'arrivée du webhook `expired` — et **jamais** si le caissier l'a acceptée entre-temps.
   **Je n'ai pas corrigé ce point** : refermer le gate à la création couperait définitivement
   ces commandes de la cuisine, car pour une commande web pure le chemin « payé »
   (`finalizePaidKioskOrder`) **no-ope** — le code marque explicitement son élargissement comme
   **point d'activation owner G-W5**, avec allocation fiscale à la clé. Improviser un chemin
   fiscal ici serait une violation directe de la discipline NF525.

---

## 3. Corrections livrées (13)

**Honnêteté** — la FAQ jurait « Pas de débit en ligne » alors que Mollie est actif (signalé le
2026-07-29, jamais corrigé) · « Tes points expirent au bout de 12 mois » alors qu'aucune
expiration n'existe en code · CGV renvoyant vers des « avantages » de statut inexistants ·
seuil fidélité affiché à trois valeurs différentes selon la page → le backend publie
maintenant le plancher **effectif** (premier multiple du taux), sentinelle TDD 4 verts.

**UX/psycho** — la flèche « retour » du 1ᵉʳ upsell propulsait vers le **paiement** au lieu du
panier · « Non merci » en gris 13 px face à un bouton orange.

**Mobile (mesuré)** — zoom automatique iOS sur **tous** les champs du checkout : 4→0 puis 1→0
champs sous 16 px, sur tous les écrans · cibles tactiles hors norme 15→12 (les 12 restantes
sont des liens texte de pied de page, exemptés WCAG 2.5.5) · chaînage du scroll contenu.

**Data** — sauces Poivre et Burger pointant vers des `.webp` inexistants : **404 prouvés en
navigateur** à chaque ouverture de l'étape sauce → repointées sur les SVG existants.

---

## 4. Ce que j'ai RÉFUTÉ (et pourquoi c'est important)

- **Débordement horizontal du checkout mobile** : `body.scrollWidth` = 406 px pour 390 px de
  viewport. Vérification : `documentElement.scrollWidth` = 390 = `clientWidth`,
  `overflow-x: hidden` actif sur `html` et `body`, et un `scrollTo(500,0)` laisse `scrollX` à 0.
  L'utilisateur ne peut pas scroller : artefact de mesure du tiroir panier hors écran,
  correctement clippé. **Non remonté comme défaut.**
- **Mes clics de navigation mobile en échec** : la nav mobile est un burger `☰`, pas des liens
  texte. Défaut de mon script, pas du site.
- J'ai aussi annulé **mon propre correctif** qui renommait le bouton d'upsell « Passer au
  paiement » : sur les étapes non finales il passe à l'upsell suivant — j'allais introduire un
  mensonge en corrigeant une ambiguïté.

---

## 5. Reste ouvert

**Gate owner** — (1) G-W5 : diffusion des commandes carte web avant paiement (§2.2) ;
(2) sauces Poivre/Burger absentes des variations backend : `php artisan menu:ensure-new-sauces
--dry-run` annonce 56 variations manquantes **en local** — à exécuter sur le VPS ; si ≠ 0, une
sauce demandée peut être **silencieusement substituée** par la première de la liste (écart
0 €, mais le client reçoit une autre sauce) ; (3) gate horaires : on peut commander « dès que
prêt ~15-20 min » à 14 h alors que le service ouvre à 18 h — décision métier (pré-commande
autorisée ou non).

**P1/P2 A1 non traités** — ticket de confirmation délivré sur les chemins `refused`/`pending`
sans aucune sonde serveur (le polling n'existe que sur le retour `?order=`) ; retour 3DS qui
conclut après 12 s ; une URL `?order=` rejouée vide le panier avant toute vérification.

**P2 A3** — la déconnexion web ne révoque pas le token côté serveur (30 j de validité résiduelle).

---

## 6. Cycle 2 — procureurs lancés contre MES propres correctifs

**Procureur « cohérence fidélité » : AUCUNE RÉGRESSION.**
Sentinelle 4/4, suite `tests/Feature/Loyalty` 46/46, API locale confirmée en direct
(`min_redeem_points: 100` avec réglage DB à 50). Les 4 consommateurs identifiés (web, borne,
mobile RN, admin) absorbent la nouvelle valeur — la borne est même **améliorée** (elle montrait
l'offre à un client de 50-99 points qui se faisait ensuite refuser). L'admin continue de lire
le réglage brut, ce qui est correct puisqu'il l'édite.
Il a trouvé **1 P2 réel, corrigé dans la foulée** : le repli hors-ligne `data/loyalty.js`
annonçait encore 50. Restes documentés en §5.

**Procureur « diff web » : RÉGRESSION DÉTECTÉE DANS MES PROPRES CORRECTIFS — corrigée (`0b6556f`).**
C'est le résultat le plus important du cycle 2. Mon correctif du cul-de-sac « carte refusée »
avait **rouvert une fenêtre de double débit** : la clé Mollie stable par commande faisait, par
accident, office de verrou « un seul paiement par commande » (la 2ᵉ requête était rejetée en
409) ; en la scopant à la tentative, les deux requêtes passent — et le backend n'a **aucun**
verrou, `payment_status` n'étant basculé à PAID que par le webhook, de façon asynchrone. La
fenêtre était grande ouverte car `setSubmitting(true)` n'était posé qu'**après** l'aller-retour
réseau de création du jeton carte, et `submitting` est un état React (pas à jour pour un second
clic dans le même tick). → verrou **synchrone** par référence, posé avant le premier `await`.
Deuxième trou : le retry après refus créait une **deuxième commande** en caisse (la purge de
`lc.funnel.idem` après succès rendait la clé neuve à chaque soumission) → cache mémoire par
(panier, mode de paiement). Troisième : ma flèche « Retour au panier » ne rouvrait pas le panier.

**Leçon** : un correctif qui supprime un blocage peut supprimer avec lui une protection non
documentée. Ici le 409 gênant *était* le verrou anti-double-paiement.

**Gates finaux** — sentinelle de navigation officielle du dépôt (`tests-e2e/nav-smoke.local.js`) :
**13/13 verts, 0 erreur JS**. Parcours d'achat réel desktop + mobile : article ajouté, récap à
10,80 € (7,40 + 0,90 + 2,50), 0 erreur JS.

---

## 7. Cycle 3 — lancé sur le code CORRIGÉ (5 agents)

Axes couverts : chemins d'abandon (sur le code corrigé + séquences plus tordues) · **cycle de
vie du compte EXERCÉ EN VRAI dans le navigateur** (le trou du cycle 1, qui n'avait analysé que
le code) · **inspection page par page exhaustive** aux deux viewports, pages légales et états
vides/erreur inclus · intégrité argent et paiement après remaniement des clés d'idempotence ·
UX/psycho + cohérence fidélité finale.

Travaux menés en parallèle par moi, hors périmètre des agents :

- **Locale FR (`3bf175c`)** — aucun message backend anglais ne peut plus atteindre l'écran.
  Deux cas vécus : « Idempotency key reused with different payload. » et « The first name
  field is required » affichés tels quels. Vérifié avant d'agir qu'aucun code ne branche sur
  le *contenu* du message (grep = 0), donc aucune logique ne casse.
- **Sentinelle durable (`e7b0581`)** — `tests-e2e/garde-audit-2026-08-05.regression.js`,
  **17/17 verts** : 13 invariants de source + 4 mesurés au navigateur mobile. Deux précautions
  apprises pendant l'audit y sont encodées : les assertions de texte ignorent les commentaires
  (la sentinelle échouait au départ sur le commentaire citant la phrase supprimée), et le
  débordement se mesure sur `documentElement` + un `scrollTo` réel, pas sur `body.scrollWidth`.

**Non traité volontairement — gate owner** : porter `isOpenNow()` dans le funnel pour ne plus
annoncer un délai immédiat quand le service est fermé. La fonction vit dans `screens.jsx` et
n'est pas exportée ; la dupliquer créerait une **jumelle de logique métier**, exactement le
motif de divergence qui a déjà mordu ce projet. Et la question de fond — accepter ou non les
pré-commandes hors service — est une décision métier, pas technique.

**Convergence** : le critère « deux cycles consécutifs à 0 P0/P1 » sera évaluable à l'issue du
cycle 3.
