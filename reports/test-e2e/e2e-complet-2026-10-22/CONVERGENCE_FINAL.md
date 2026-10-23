# Audit e2e-complet-2026-10-22 — site, application des stores, caisse

Statut : **convergence atteinte sur l'ensemble BLOQUANT** (P0/P1 `open` de disposition `fix` = 0 aux tours 2 et 3).
**Ce n'est PAS « tout est vert »** : des P0/P1 `owner_gate` / `other_session` restent ouverts (section « Ce qui reste », 1er bloc).
Code final : `e6d25ede0`. Rien n'a été poussé ni déployé.

## Verdict par tour
| Tour | Bloquants `fix` (P0/P1) | Verdict | Ce qui a été fait |
|---|---|---|---|
| 1 (capture + revue adverse) | 5 (B-001, B-002, B-003, C-101, C-102) | AMBER | 3 vagues + 3 superviseurs |
| 2 (rejeu + revue adverse du delta) | 0 | GREEN | correctifs rejoués ; superviseur a trouvé 11 défauts `fix` P2/P3 dans nos propres correctifs |
| 3 (rejeu final, code `e6d25ede0`) | 0 | GREEN | PHPUnit complet, Vitest complet, 11 specs Playwright |

Règle d'égalité d'ensemble (CONVERGENCE_RULES) : **respectée sur l'ensemble bloquant** (vide aux deux tours). L'ensemble complet des constats ouverts a RÉTRÉCI entre les tours 2 et 3 parce que nous avons corrigé ADV2-001/002/003/004/014 (fermetures, pas des intermittences) ; aucun constat nouveau n'est apparu au tour 3.

## Résultats du tour 3 (code `e6d25ede0`)
- **PHPUnit complet** : 7 118 tests en 19 tranches (SQLite mémoire). **3 échecs, tous antérieurs et hors périmètre**, identiques au tour 1 :
  `WithoutGlobalScopesAuditSentinelTest` ×2 (`ReviewAccountCommand`, commit `5a466a5a3`, autre session) et `UberOrderMapperNoteTest` (note Uber Eats).
- **Vitest complet** : 5 808 tests, 5 805 verts après correction de la sonde sans `expect` (sentinelle anti-preuve-vacante) ; 3 ignorés.
- **Playwright** : A-etats 6/6 · A-base-M2 7/7 · A-r2-ferme-422 6/6 · B-fermetures 5/5 · planche-tournees 3/3 · B-livraison 2/2 · B-caisse-tiroir-ventes 5/5 · C-r2-c101 3/3 · C-contrat-api 4/4 · C-natif 7/7.
  **Rouge assumé** : `C-captures-r2` (vraies assertions) échoue sur **C-007** (zones tactiles < 44 px, P2 ouvert, dans le site) — un test qui mesure un défaut connu, pas un faux rouge.
- **NF525** : `fiscal:verify-chain --all` CHAIN OK sur 7 branches avant, pendant et après. Zones gelées : diff vide ; `public/js/pos-wizard.js` identique à HEAD (voir incident).

## Correctifs livrés (commits, chacun avec un test qui échoue sans lui)
| Constat | Sév. | Cause racine | Commit |
|---|---|---|---|
| B-001 | P1 | annuler une commande d'un arrêt Uber supprimait l'arrêt en laissant la course active et facturée | `64c4a4f81` |
| B-002 | P1 | course renvoyée/annulée après prise du sac jamais signalée (la doc disait l'inverse) → alerte rouge | `64c4a4f81` |
| B-003 | P1 | appel réseau Uber dans une transaction → verrou par commande + 2 transactions courtes | `64c4a4f81` |
| B-004/005/006 | P2 | statut qui recule, transition refusée muette, relevé Uber non borné | `64c4a4f81` |
| C-101 | P1 | « (and 1 more error) » en anglais dans un message français (`lang/fr.json` absent) | `69db7b2ba` |
| C-102 | P1 | rapport de capture inexact (rectificatif ajouté) | `23d8cf7dc` |
| R2-01 / R2-02 | P2/P3 | refus Uber hors de vue ; en-tête collant masquant des boutons | `a4c72f9a7` |
| ADV2-001/002/003/004/014 | P2/P3 | statut inconnu écrasait « sac pris » ; annulation ordinaire dépendait de la table Uber ; `release()` en transaction ; annulation « déjà faite » ; verrou non testé | `f79b26e50` |
Bascule Uber / nos livreurs elle-même (fonctionnalité) : `64c4a4f81`. Sonde : `e6d25ede0`.

## Intégrité inter-surfaces prouvée
- Site : récapitulatif wizard = panier = POST = `orders.total` = Σ `order_items` = ressource caisse (commandes 8657 : 6,00 € ; 8659 : 4,90 € ; 8714 : 6,00 € ; Cayenne 10,80 € jusqu'au POST).
- Caisse : panier = ticket = `orders.total` = ligne cuisine (espèces #8656/#8748 ; carte #8658/#8749) ; tiroir 105,40 = 105,40 (écart 0,00) ; vente au X puis au Z-28 ; temps réel : trame `OrderCreated` reçue 358 ms après la vente.
- Application : commande depuis la coque Android ET iOS = 7,40 € = panier = base ; suppression de compte (anonymisation, jetons révoqués, commande conservée).

## Ce qui reste (transparence totale)
**P0/P1 ouverts hors de portée du correcteur (décision propriétaire ou code d'une autre session) :**
- **C-001 (P0), C-002, C-004, C-005, C-006 (P1)** — dépôt du site/app : deux lignes divergentes (HEAD…origin/main = 3 / 25). L'état de travail a le **pont natif mort** (Apple sans effet, tuiles « bientôt », `SCHEDULE_EXACT_ALARM`) = **rejet App Store quasi certain** ; `origin/main` a le pont réparé mais pas la livraison ni les suppléments recommandés. Le site public sert un 3ᵉ état (29/09). **Aucune publication aux stores possible en l'état.** Décision « aucune publication avant réunion des chantiers » déjà au ledger.
- **A-001 (P1)** — le site laisse commander quand le service est fermé (« SERVICE FERMÉ » à l'accueil, tunnel « Dès que prêt ~10-15 min », serveur accepte : commande 8714 à 02:13). Aucun écran ne lit `service_ouvert`. Correctif côté site, fichiers non commités d'une autre session.
- **A-002 (P1)** — fiches Cayenne / Cheese Burger : « Gluten » seul (cheddar absent) ; il faut la matrice allergènes du propriétaire.
- **A-003 / A-004 (P1, connus)** — wizard Cayenne impose 2 viandes, serveur 1 (message maintenant lisible mais non actionnable) ; numéro de retrait absent ; confirmation de livraison « Tu paies sur place ».
- **Décisions propriétaire en attente** : plafond de 24 cartes en cuisine ; remise désactivée (kill-switch) ; titre-restaurant sur le site ; départ livreur borné à 45 min ; etc. (cf. `verif-globale-2026-10-21/RAPPORT_FINAL.md`).

**P2/P3 `fix` ouverts (non bloquants, listés dans les `*-findings.json`)** : zones tactiles 28 px et recherche 40 px (C-007), ombre du tiroir (C-008), téléphone coupé à 360 px (C-009), panier vide en crème sur blanc sur l'accueil de l'app (C-104, 1,07:1), barre d'état illisible (C-103), jeton Apple non révoqué à la suppression de compte (C-106), tournée passée toujours « à venir » (B-003/B-010), toast de refus coupé, plafond de 300 du suivi des commandes (B-014 : ce sont les plus RÉCENTES qui disparaissent), Rapport X en JSON brut (B-013), contrastes WCAG AA du bouton orange (3,12:1), modale Tournées sans piège à focus ni Échap, etc.

**Non couvert (honnêtement)** : Mollie (clé live), vraie connexion Apple/Google, iOS réel (Xcode absent), production (aucun appel), commande programmée, comportement réel d'Uber (compte sans le droit `eats.deliveries`), annulation réelle d'une commande à arrêt Uber (PHPUnit seulement).

## Instruments : ce qui a failli produire de faux verdicts (CLAUDE.md §3ter)
- Fausse alerte « ASAP → lendemain » : comparaison au jour **UTC** au lieu de Paris — **rétractée**, prouvée par SELECT.
- Test de débordement `scrollWidth > innerWidth` aveugle en émulation mobile (`overflow-x: clip`) — corrigé, conclusion inchangée.
- Faux pont natif qui répondait à `addListener` (fabriquait un `App.exitApp`), journal perdu au rechargement — corrigés.
- Limiteurs partagés (429 OTP / inscription) entre vagues parallèles ; sessions de tiroir fermées par SQL par une vague (signalé, aucune écriture SQL au tour 2).
- `C-captures` n'avait que 4 `expect` : de vraies assertions ajoutées et prouvées mordantes.

## Incidents de l'orchestrateur (déclarés)
1. **Suppression par erreur de `public/js/pos-wizard.js` (zone gelée)** en purgeant des « bundles périmés » ; restauré par `git checkout` (sha256 identique à HEAD, `git ls-files -d` vide, servi 200). Absent quelques minutes. Note de mémoire écrite.
2. Un script de relance PHPUnit réutilisait le nom d'un script d'une vague : quelques specs de la vague B ont tourné par erreur (données `E2E-CAISSE-`, pas de Z).
3. Un superviseur a lancé un `ssh lecayenne true` vers la production (aucune lecture ni écriture, aucune requête HTTP).
4. Disque à 100 % pendant quelques minutes (émulateur Android de la vague C : +3 Go ; autres processus). Rien de supprimé hors bundles gitignorés.

## Mandat propriétaire
> « pas de retour avant validation — si l'agent adversaire trouve un truc, on recorrige, on refait un test complet jusqu'à tout vert. Pas de limite. »

Fait pour tout ce qui est corrigeable dans cet arbre : 5 P1 bloquants fermés, 11 défauts trouvés dans nos propres correctifs traités ou listés, 3 tours. **Non fait, et impossible sans vous** : arbitrer la ligne de publication du site/app, la matrice allergènes, le service fermé côté site.
