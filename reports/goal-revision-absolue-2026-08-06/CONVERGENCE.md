# RÉVISION ABSOLUE du système de caisse — rapport de convergence (2026-08-06)

**Mandat owner** : revue de la structure globale, de tout ce qui manque/à améliorer sur les pages de gestion directes ET indirectes, commandes, stock, synchronisation, logique, puis sécurité, UX/psychologie caissier + cuisinier, gestion des accès — en boucle avec vérifications réelles sur le web jusqu'à validation.

**Méthode** : 6 auditeurs parallèles en LECTURE SEULE (structure · logique · stock · synchro · sécurité · UX), chacun avec obligation de preuve (`fichier:ligne`, test exécuté, ou mesure sur capture). Puis heal TDD par cluster, puis boucle de vérification.

## Résultat des 6 audits

| Dimension | P0 | P1 | Verdict |
|---|---|---|---|
| A · Structure & pages (31 surfaces, 60 captures) | 0 | 2 | Cœur caisse sain ; 2 APIs sans page |
| B · Logique commandes (15 séquences jouées) | 0 | 4 | Concurrence/fiscal SAIN ; 4 « jumeaux oubliés » monétaires |
| C · Stock (76 tests DB-safe) | 0 | 0 | Décrément/re-crédit prouvés sur tous les chemins |
| D · Synchro (13 events × 8 surfaces) | 0 | 0 | Cœur couvert ; P2 documentaires |
| E · Sécurité & accès (matrice empirique HTTP) | **1** | 1 | RBAC métier sain ; 1 P0 hors code + 2 fuites d'index |
| F · UX/psychologie (mesures sur captures) | 0 | 3 | 3 défauts mesurés, chiffrés |

## Ce qui a été corrigé (11 défauts, tous prouvés avant/après)

**Argent & fidélité (4 P1 — reproduits par test, puis verrouillés par 6 sentinelles)**
1. Remboursement d'une vente en paiement mixte : **28,02 € rendus pour 20,01 € encaissés** (avoir crédité du total + sortie tiroir de la part espèces). Désormais compensé PAR TRANCHE — avoir = part non-espèces, tiroir = part espèces, somme = total au centime. Sens inverse (dominante espèces) : la part carte n'était compensée nulle part → corrigée aussi.
2. Borne préparée puis annulée au comptoir : **les points fidélité gagnés restaient acquis** sur une vente jamais payée (exploit « faire préparer puis faire annuler »). 3ᵉ jumeau du clawback aligné sur les deux autres.
3. **Commande LIVRAISON du site invisible en caisse** : personne ne pouvait l'accepter, et le nettoyage automatique l'annulait après TTL = perte silencieuse. `web ≡ delivery` propagé aux 2 voies de visibilité.
4. Encaissement mixte à dominante espèces : **38,02 € au tiroir pour 13,01 € réels** (tranche + total comptés). Le hook post-commit ne s'arme plus jamais en multi-tender.

**Pages de gestion manquantes (2 P1)**
5. **Rapports Z NF525** : API complète (liste/détail/PDF/rapport X) sans aucune page — les PDF légaux n'étaient atteignables qu'en ligne de commande. Page créée (27 Z réels + PDF + rapport X), vérifiée en capture.
6. **Imprimantes** : CRUD + test d'impression sans UI (configuration artisan-only). Page créée (2 imprimantes réelles, bouton test par station), vérifiée en capture. La page TPE, orpheline, est réintégrée au menu.

**Sécurité (2 fuites)**
7. `setting/kiosk-machine` index exposait **l'identifiant de connexion de la borne** à un compte staff SANS aucune permission (200 + `kiosk-secret-login` prouvé).
8. `payment-terminals` index exposait **le numéro de série TPE + la grille de commissions**. Les deux étaient mal classés « non-PII » dans l'allowlist gelée — retirés, gatés, sentinelle 4/4 (le caissier garde l'accès dont la modale a besoin).

**UX mesurée (3 défauts + 1 état vide)**
9. Bouton « Confirmer & Imprimer » de l'encaissement **hors écran à chaque encaissement** (mesuré y=1059 pour une hauteur de 768 — et y=1071 même en 1080). Footer collant → mesuré **676/768** après correction.
10. Pastille **ALLERGIE = le plus petit texte de la carte KDS** (10 px, illisible à 2 m) alors que le numéro fait 36-52 px → 17 px / 32 px de haut, verrouillé par sentinelle.
11. La modale d'encaissement annonçait **« BORNE » en dur** quelle que soit l'origine (contredisant le badge de la liste) → origine réelle ; téléphone et livraison n'avaient pas de libellé → ajoutés.
12. Écran client : état vide réduit à un tiret → phrase explicite (« Aucune commande en préparation / prête »).

**+ 1 test défectueux corrigé** : le test du repli d'impression navigateur assertait AVANT la fin du pipeline devenu asynchrone (échec 3/3 reproductible, **prouvé antérieur à ce round** via worktree sur le commit parent).

## Convergence
- **Trois cycles consécutifs identiques** : cycle 1 (`9b7ef6bca`), cycle 2 (`c41128f70`), cycle final (`a99a867ed`) — PHPUnit **729 tests / 11 domaines, 0 échec** · vitest **2772 verts, 0 échec** · Playwright R3 vert. Arbre gelé pendant chaque cycle.
- **Vérification RÉELLE web** : 10 surfaces de gestion (caisse, encaissement, suivi, historique, stock/rupture, KDS, écran client, rapports Z, imprimantes, TPE) — **0 erreur console, 0 HTTP ≥ 400, aucune page vide/éjectée**, en compte admin ET en compte caissier (métier accessible, réglages refusés sans éjection).
- Frozen-zones : **aucune touchée** ce round. NF525 : lecture seule, chaîne intacte.

## Ce qui reste — et qui n'est pas de mon ressort

**P0 OWNER — clés AWS dans l'historique git.** `git show 9b1e741f4:.env` renvoie encore `AWS_ACCESS_KEY_ID` + secret + `APP_KEY` + secrets fiscaux ; l'objet est atteignable depuis **22 branches distantes dont `origin/production`**. Le fichier a été retiré du suivi en juillet, mais **l'historique n'a jamais été réécrit**. Seule action qui neutralise réellement : **révoquer/rotationner ces clés dans la console AWS** (la réécriture d'historique est secondaire et destructive — à planifier). Tracé comme en attente dans le BRAIN depuis 2026-07-07.

**P2 structurels signalés, non traités (décision métier)**
- 86 (rupture) **jamais propagé vers Uber Eats** — l'API menu Uber n'est pas câblée ; une commande Uber n'a aucune garde de disponibilité.
- **Aucun inventaire physique** (comptage réel vs théorique) : `RawMaterialStockService::adjust()` existe mais n'a aucun appelant ; rien pour `stock_levels`.
- Registre repas/pertes limité aux 50 derniers dans une modale, sans page ni export.
- Rapports/observabilité outbox : page existante mais orpheline de la nav.
- « Comptage tiroir (à venir) » affiché dans la Vue Caisse Unifiée alors que l'API de rapprochement existe.
- SYNC_CONTRACT.md **faux sur 4 axes** (4 events documentés vs 13 réels ; cadence KDS 15 s vs 60 s annoncés) ; 2 events émis sans aucun abonné client ; node-pin soketi absent du template supervisor.
- Worker mort = visible seulement via la pastille caisse (1-3 min en service, jamais en heures creuses) ; KDS/OSS ne l'affichent jamais.
- UX : layout caisse à 1366 (grille produits sous la ligne de flottaison, 2 défilements par vente) et ton orange à 3.2-3.5:1 sur les montants — les deux touchent la hiérarchie/palette, donc arbitrage owner.
