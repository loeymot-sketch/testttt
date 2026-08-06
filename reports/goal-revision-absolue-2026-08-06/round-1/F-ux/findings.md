# AUDIT F — UX & Psychologie caissier/cuisinier (mesures réelles)

**SHA** `a13e1e65672c9214a515fa6fd3a7e48a5abc4e4e` · branch `pos/category-first-caisse-2026-06-23` · 2026-08-06 ~06h20-06h50, serveur local 8000, login `pos@lecayenne.fr`. **Aucun code modifié, aucune commande créée** (modales ouvertes puis annulées ; panier local jamais soumis). Captures 1366×768 + 1920×1080 dans ce dossier ; mesures DOM brutes dans `measures-all.json`, `measures-run3-all.json`, `measures-run4-all.json`. KDS vide à cette heure (état réel du jour : 1 commande status 16) → cartes réelles analysées sur la capture de la veille `reports/goal-8axes-2026-08-05/wave5/kds-6cards-1366.png` + CSS `KdsOrderCard.vue` (sourcé).

## Parcours mesurés (clics/gestes réels)

| Action fréquente | Gestes @1920 | Gestes @1366 | Seuil ≤3 |
|---|---|---|---|
| Caissier — encaisser borne ESPÈCES exact (home) | 2 clics (Encaisser → Confirmer, montant prérempli `9,90`) | 2 clics + **1 scroll modale** (confirm y=987/vh=768) | ⚠️ scroll |
| Caissier — encaisser CB | 3 clics | 3 clics + 1 scroll | ⚠️ scroll |
| Caissier — vente comptoir 1 sandwich, espèces exact | **7 clics** (cat, produit, viande, sauce, Ajouter, Commande·total, Confirmer) | 7 clics + **2 scrolls** (grille y=785 ; pile panier finit y≈850) | ❌ |
| Caissier — commande téléphone | 5 clics + 2 saisies + 1 clic CTA | idem + 1 scroll (CTA 📞 y=759, coupé) | ❌ |
| Caissier — retrouver une commande | 1 clic (Suivi) + 1 saisie (recherche N°/nom, kanban filtres Caisse/Borne/En ligne) | idem | ✅ |
| Caissier — réimprimer (à encaisser) | **1 clic** depuis la home (🖨 Cuisine/Client) ; clôturée ≈3 via Historique (non vérifiable ce matin, file vide) | idem | ✅ |
| Cuisinier — bump | **1 clic** (« Prêt » ~127×50px) | idem | ✅ |
| Cuisinier — voir la suite | 1 clic ▶ (~56×90px) ; badge « +7 en attente » | idem | ✅ |
| Cuisinier — réimprimer | 1 clic (🖨 ~44×50) | idem | ✅ |
| Cuisinier — rush 10 cartes | 6 visibles + 1 clic ▶ = 2 vues | idem (6 cartes tiennent) | ✅ |

Le wizard impose viande **ET** sauce (`⚠️ Sélectionnez au moins une sauce`, `pos-04-panier-1366.png` run2) : 3 clics incompressibles/sandwich — justifié (qualité ticket cuisine), pas une « confirmation inutile ». Seule double-confirmation trouvée : vider le panier (2 clics) — justifiée. **Aucune confirmation parasite ne ralentit le rush** ; ce qui le ralentit est le **scroll**.

## P1 — mesurés

**P1-1 · Le bouton « Confirmer & Imprimer » de la modale d'encaissement est sous le fold — chaque encaissement.** `enc-02-modal-cash-1366.png`, `pos-09-home-collect-modal-1366.png`. Mesures : confirm y=1059 (page enc) / y=987 (home) pour vh=768 ; **même à 1920 : y=1071+52 > vh=1080** (page enc). Toggles impression y=1007-1019 également cachés. Contenu modale ≈1100px en 1 colonne (héro 44px + 5 tuiles modes 114/86px + input 54 + numpad + footer). L'action monétaire la plus fréquente du restaurant exige un scroll à l'aveugle, ×N commandes/rush.

**P1-2 · À 1366, la grille produits ET le CTA payer démarrent hors écran.** `pos-01-grille-categories-1366.png` : 1re tuile catégorie y=785 (vh=768, scrollY=0) — 0 tuile visible à froid ; l'écran est occupé par 9 chips nav + 2 files + bloc « COMMANDES WEB · 76 ». Pile panier (1 article) : ligne 157px + total y=642 + payer y=702 + 📞 y=759 + annuler y=821 → fin ≈850 > 768 (`measures-run4`) ; à 1920 tout tient (fin 957 < 1080). La vente comptoir — cœur du métier — coûte 2 scrolls par commande sur l'écran 1366.

**P1-3 · Signal allergène cuisine = le plus petit texte de la carte.** `KdsOrderCard.vue:677-691` : pill `⚠ ALLERGIE` **hauteur 20px, police 10px** (blanc sur #C2410C, contraste 5.18:1 OK). Comparaison : N° commande clamp(36-52px), lignes produit clamp(22-34px). À 10px (~2,5-2,8mm de cap), lisible à ~50cm — illisible debout à 1-2m. La ligne la plus critique en sécurité est la moins visible (mémoire projet : allergie presque avalée par un drop de segment le 2026-08-03).

## P2 — mesurés

**P2-1 · Encaissement en 1 clic sur le MAUVAIS mode.** Les 2 modales préselectionnent ESPÈCE (`cc_open_state` : CASH `is-active`, confirm `disabled:false` à l'ouverture ; POS checkout : cash actif, confirm actif avec montant vide → `pos_received_amount=null` = exact, `PaymentComponent.vue:311,702`). Client paie CB, caissier tape Confirmer → paiement enregistré ESPÈCES + tiroir ouvert ; correction = flux remboursement. Rendu-monnaie : montant reçu prérempli exact → si le client tend 20€, aucun rendu affiché sans ressaisie. (+) L'anti-double-clic existe (`:disabled` pendant submit, garde race 2 caissiers `PosCounterCollectModal.vue:731`).

**P2-2 · Badge d'origine FAUX dans la file d'encaissement.** `enc-01-file-1366.png` : 3 tickets badgés « Caisse » ; la modale du même N°A0009 dit « BORNE » (`enc-02`) ; DB : les 3 commandes = `source_surface: "kiosk"`. Le caissier attend le mauvais type de client ; confiance dans les badges érodée.

**P2-3 · Contraste famille orange marque = 3.2-3.5:1 sur les éléments monétaires** (seuil WCAG texte normal 4.5). Mesures : prix produits 15px fw800 **3.49** ; « Encaisser » file 16px **3.49** (110×40px) ; « Encaisser » home 12px **3.49** (89×32px) ; héro total modale 40px 3.25 (passe en « large text ») ; « + Ajouter une tranche » 3.23 ; mixte « Carte » 13px 3.21. Lumière variable comptoir → marge de lisibilité nulle. Gate owner : palette marque §3bis.

**P2-4 · Inversion de saillance : l'état ACTIF est le moins lisible.** Mode Espèces actif 3.49 vs inactifs 6.24 ; nav Encaissement active 3.49 vs inactives 16.61. L'état sélectionné demande plus d'effort de lecture — l'inverse de la hiérarchie attendue.

**P2-5 · Cibles sous 44px sur les actions rapides** (Fitts, gants/stress) : « Encaisser » home **89×32px / 12px** (l'action la plus fréquente du rush = la plus petite cible du flux) ; 🖨 home 59-67×32 ; **✎ éditer ligne panier 19.8×19.8px** ; qté/suppr 32.4×32.4 ; nav secondaire h=33 ; chips header h=36. En face : tuiles modes 231×114, numpad 90.8×50.4, CTA 329-401×50 — bien dimensionnés.

**P2-6 · Compteurs anxiogènes/faux pour le caissier.** Tracker : chip « ⏱ **476 en retard** » à côté de « 0 actives · 1 aujourd'hui » (`tracker-01-1366.png`) — dette historique affichée comme urgence du jour. POS home : « COMMANDES WEB · 76 » avec 76 « Accepter » permanents. 45+ éléments interactifs sur le 1er écran POS à 1366 (9 chips + 3 files + panier) : fatigue d'alarme — on apprend à ignorer les blocs urgents.

**P2-7 · `/admin/stock/rupture` redirige le caissier vers le dashboard** (`stock_url` run2 = `/admin/dashboard`, 2 tentatives SPA chaude, les 2 viewports — `stock-01-rupture-*.png` montrent le dashboard). Le vrai chemin caissier existe et est bon : bouton « Rupture » in-POS → panneau recherche + « En rupture » 1 clic/produit, 55 items (`stock-02-rupture-via-pos-1366.png`) — mais 55 items à plat sans regroupement catégorie, et le panneau s'ouvre par-dessus le wizard resté ouvert (empilement modal possible).

## P3

- Chrono KDS rogné à 1366 (connu, G-4 — `VERDICT_LISIBILITE.md` de la veille) ; résolution réelle écran cuisine à confirmer owner.
- Lisibilité 2m cuisine : lignes produit 34px ≈ 9.4mm → confort ~1m, reconnaissance ~1.9m (écran 24" 1920) ; N° 52px ≈ 14mm → ~2m limite. Abréviations « FRI | ALG » : rapides pour experts, toggle « Afficher les noms » présent (bon).
- Dashboard caissier affiche « Total ventes 39 945,13 € » global (info direction exposée au rôle caissier).
- OSS : « En préparation » blanc/cramoisi 7.11:1, « Prêt » foncé/vert — OK.

## Quick-wins (≤1h chacun, aucun frozen)
1. **Footer sticky** (Annuler/Confirmer) dans `PosCounterCollectModal.vue` (CSS `position:sticky; bottom:0`) → P1-1 éliminé aux 2 viewports.
2. **originBadge()** dans `EncaissementComponent.vue` : mapper `source_surface=kiosk` → « Borne » → P2-2.
3. **Pill allergène ≥16px / h≥32px** dans `KdsOrderCard.vue` (CSS seul) → P1-3.
4. Chip « en retard » : scope au jour (ou masquer si 0 actives) → P2-6.
5. ✎ édition ligne panier ≥44×44 + qté/suppr ≥40 (CSS) → P2-5 partiel.
6. Libellé dynamique du confirm : « Confirmer ESPÈCES — 9,90 € » (texte déjà calculé) → réduit P2-1 sans clic supplémentaire.
7. Bloc web home : replier par défaut au-delà de 5 + âge affiché → P2-6.

## Chantiers
- **Modale collect 2 colonnes** (modes | montant+numpad+confirm) pour tenir en 768px sans scroll (P1-1 racine).
- **Layout POS home 1366** : grille produits premier bloc visible, files en colonne latérale compacte (P1-2) — décision de hiérarchie owner.
- **Mode de paiement sans défaut** (choix explicite = +1 clic) OU garder défaut + libellé dynamique — arbitrage owner vitesse/erreur (P2-1).
- Ton orange texte ≥4.5:1 (ex. #C93E0E) pour prix/CTA texte — **gate palette owner**.
- Panneau Rupture : regrouper par catégorie + fermer le wizard avant ouverture.

## Positifs mesurés (à préserver)
Numpad 90.8×50.4 + montant prérempli exact (2 clics l'encaissement borne) ; multi-paiement correctement **gaté** (confirm désactivé tant que tranches ≠ total, `pos-07`) ; bump 1 clic 127×50 ; ◀▶ 56×90 + « +N en attente » ; recherche tracker 1 clic + filtres origine ; impression AVANT encaissement 1 clic ; reset panier 2 temps ; anti-double-submit partout.
