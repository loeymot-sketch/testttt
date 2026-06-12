# WAVE F — JUGEMENT DESIGN/UX GLOBAL — Round 1 dispute 2026-06-12

> Agent GSTACK MAIN (Architect+Tester+A11y+SRE). Mission : re-juger les captures c2 du GOAL
> UI/UX caisse+borne 2026-06-11 avec un œil DESIGN pur + 6 captures fraîches + DESIGN_GAP_ANALYSIS.md.
> Grilles : DESIGN_SYSTEM_POLICY_2026-06-10.md (normatif) + DESIGN_REFERENCES_2026-06-11.md (annexe).
> Je CAPTURE et OBSERVE — le verdict sévérité appartient à l'adversaire.

## Statut : TERMINÉ (rapport incrémental complété 2026-06-12 ~02:50)

## 0. Setup
- App :8768 OK (HTTP 200 /kiosk/idle), DB foodking_e2e jetable.
- Captures existantes re-jugées : `reports/test-e2e/uiux-caisse-borne-2026-06-11/convergence/c2/*.png` (25 états) + `c2/flow/*.png` (14 états).
- Captures fraîches : 6 états (borne idle/catalogue/panier, caisse POS/encaissement/show) → quartet PNG+DOM+console+network dans ce dossier.

## 1. Re-jugement captures existantes (œil design pur)

### c2/kiosk-idle.png + c2/kiosk-categories.png
- **ANOMALIE ARTEFACT** : `c2/kiosk-categories.png` est IDENTIQUE à `kiosk-idle.png` (écran idle, pas la grille catégories). La capture c2 « categories » a photographié la redirection deep-route → /kiosk/idle, pas l'état nommé. Couverture c2 statique surestimée (le vrai état est dans flow/f01).
- **Design idle** : écran à DOMINANTE SOMBRE (fond brun/noir + grande ellipse floue centrale) alors que la policy borne = light-mode 100% (POLICY §1 ; REF §3 #10 « jamais d'écran à dominante sombre »). L'ellipse floue centrale ressemble à une image héro manquante/sur-floutée — rend l'écran vide, n'« appétise » pas (vs BK Sizzle : grandes photos appétence, fond clair).
- Texte « CHOISISSEZ UNE OPTION POUR COMMENCER » minuscule, gris sur sombre (contraste + corps <14px équiv. — REF §3 #8).
- 1 seule option « À emporter » dans une petite carte blanche + un CTA rond orange isolé sans label — l'affordance du rond orange est ambiguë (icône seule).

### flow/f01-categories.png (Desserts)
- Rail catégories gauche : vignettes ~64px avec libellés ~8-10px équiv. — illisibles debout à 60-90cm (REF §3 #8 corps ≥20px). Le rail est dense, images write-only.
- Tuiles produit : ratio image constant, badge VÉGÉTARIEN vert, prix orange marque (gate contraste connu — pas re-compté). Hiérarchie nom>badge>desc>prix OK.
- Footer : bande noire « Abandonner ma commande » + bouton droite grisé — le CTA d'abandon (destructif) occupe la position thumb-zone la plus accessible, alors que « voir panier/continuer » devrait y dominer (McDo : panier ancré bas toujours visible).
- Bandeau promo « -5,00 € code BORNEAUDIT5 » en chip discret haut-gauche : OK.
- Intégrité prix : tuile Glace **3,80 €** (vérifié par crop zoom — PAS 5,80) = panier f03 3,80 € = show f11 3,80 €. COHÉRENT.

### flow/f02-wizard-step.png (Chicken Burger, étape crudités) [FROZEN observé]
- Stepper haut : icônes rondes + libellés « QUELLE VIANDE ? » ~9px équiv. — sous le plancher 14px, faible debout.
- ~55% de l'écran = vide (dégradé pêche) entre le contenu (haut) et le footer — verticalité 1080×1920 non exploitée ; les cartes d'options pourraient être plus grandes (touch 80px+ visé).
- Footer 3 boutons : « Abandonner l'article » / « Précédent » / « Suivant » + Total 6,90 € — bonne ancre basse, Retour visible (REF #5 OK). « Suivant » orange = 1 CTA primaire OK.
- Cartes option sélectionnées : fond pêche + check orange + « AVEC » orange — cohérent.

### flow/f03-cart.png (panier 22 articles)
- Lignes claires (vignette/nom/prix unitaire/stepper/total ligne orange à droite, chiffres alignés droite — REF #12 OK).
- Icône poubelle par ligne ~24-28px équiv. en coin de carte — cible tactile < 48px (REF #1), collée au bord.
- Pictos allergènes uniquement sur Tiramisu (Gluten/Lait/Œufs) — incohérence d'affichage si les autres desserts en ont aussi (à vérifier DB).
- Bandeau « À emporter » pleine largeur orange = très dominant pour une info de mode ; concurrence le futur CTA payer.

### flow/f04-loyalty-input.png
- **2 messages d'erreur rate-limit simultanés et redondants** : toast haut-droite « Trop de requêtes — patientez 27s avant de réessayer. » + bandeau inline « Trop de tentatives, patientez quelques secondes. » Deux formulations différentes pour le même état = bruit.
- **2 CTA pleins concurrents** : « Vérifier mon code » (orange plein) + « Pas encore membre ? S'inscrire » (jaune plein dégradé) — REF #3 : 1 seul CTA primaire/écran. Le jaune plein attire autant que l'orange.
- Carte centrée bas, ~40% haut d'écran vide (dégradé) — déséquilibre vertical portrait récurrent.
- Pavé numérique : touches larges >80px, bon.

### flow/f06-upsell.png [FROZEN observé]
- Images cassées Boisson Seule/Frites Seules (gate DATA connu — pas re-compté) ; MAIS design : la tuile sans image = bloc gris brut avec alt-text brut « Boisson Seule » + icône broken — aucun fallback illustré (REF #18 jamais de zone blanche brute).
- **~70% de l'écran vide** entre les 3 tuiles (haut) et le refus (tout en bas) — l'écran upsell n'est pas composé pour le portrait.
- « Non merci, continuer sans (28s) » : refus ghost pleine largeur + compteur — refus PLUS visible que l'ajout (petits ronds « + ») : pas un dark pattern (bon), mais asymétrie inverse vs REF #16 (boutons de même poids).

### flow/f07-payment.png (paiement à la caisse)
- **Le bloc info « TOTAL À RÉGLER : 39,90 € » a exactement le même traitement visuel que le bouton « Confirmer ma commande »** (mêmes rect arrondis orange pleins, empilés) — l'info ressemble à un CTA, le CTA ne se distingue plus (affordance brouillée).
- **Texte du CTA « Confirmer ma commande » aligné à GAUCHE** dans le bouton (le bloc total au-dessus est centré) — incohérence d'alignement flagrante sur l'écran le plus critique.
- CTA non ancré en bas (flotte à ~60% hauteur) — REF #4.
- **Aucun bouton Retour/Annuler visible** sur l'écran de paiement — REF #5 (cul-de-sac apparent ; à re-vérifier au DOM frais).
- Titre « PAIEMENT À LA CAISSE » + sous-titre « Veuillez payer à la caisse » = redondance de copy.

### flow/f08-cash-instruction.png
- Bonne hiérarchie : #A0001 énorme orange, montant, « J'AI COMPRIS » ancré bas pleine largeur, compte à rebours « Retour à l'accueil dans 42 s » visible (REF #21 OK). Meilleur écran du flux borne.
- ~35% haut vide (déséquilibre récurrent).

### flow/f09-pos-ticket.png (capture basse résolution ~360px — re-capturé frais en §2)
- Topologie 3 zones POS : file borne gauche / grille centre / ticket droite — conforme Toast bipanneau + queue.
- Toast vert « Article ajouté au panier » haut-droite.

### flow/f10-encaisse-modal.png (encaissement borne, caisse)
- **Icônes = EMOJI** (💶 espèces, 📱 mobile, 🎫 TR…) dans les tuiles de paiement, mélangées aux icônes vectorielles du shell — iconographie disparate, rendu OS-dépendant, lecture « prototype » vs Square/Toast (pictos système cohérents).
- Boutons « Annuler / ✓ Confirmer & Imprimer ticket » placés AU MILIEU du pavé numérique (des touches keypad encore visibles SOUS les boutons, coupées par le bas du modal) — le modal déborde de la hauteur 900px sans footer sticky (à confirmer frais ; REF Toast : action bar verrouillée en bas).
- Tuile « Terminal (manuel) — SumUp – saisir la référence » : libellé technique côté caisse OK métier, mais hiérarchie tuiles inégale (Espèces a un sous-texte « Ouvre le tiroir (simulation) » — le mot « (simulation) » FUIT dans l'UI de prod).
- Fond : « CAISSE LE CAYENNE / Commande rapide / Filiale #1 » — « Filiale #1 » = jargon back-office sur l'écran caisse (FR attendu : « Restaurant » ou rien, V1 mono-site).
- Badge « BORNE » orange à côté du n° : bon (canal visible, pattern Olo).

### flow/f11-pos-orders-show-borne-attente.png (admin show)
- **Hiérarchie des numéros inversée** : « N° Commande: #1106264512 · N°A0001 » — l'ID interne 10 chiffres domine en gros/orange, le numéro métier A0001 (celui que le client présente) est en petit. Le gérant scanne A0001.
- **Terminologie ticket/facture incohérente** : bouton « Imprimer La Facture » (admin show) vs « Confirmer & Imprimer ticket » (modal POS) pour le même artefact NF525.
- **Title Case anglophone systémique hors-PaymentComponent** : sidebar « Tableau De Bord », « Commandes Caisse », « Vue Caisse Unifiée », « Caisse Livreur », « Imprimer La Facture » — typographie FR = « Tableau de bord ». (Le gate connu ne couvre que PaymentComponent frozen ; ici c'est le shell admin non-frozen.)
- Deux contrôles juxtaposés « À Encaisser ▾ » + « Accepter ▾ » (selects status) sans libellé de groupe — ambigus (lequel fait quoi ?).
- Intégrité : Sous-Total 39,90 € = f07 = f08 ; Remise 0,00 € ; items 3,80/0,90 € cohérents panier f03.

### flow/f12-historique-datepicker.png
- Datepicker FR OK (lu-di, raccourcis Aujourd'hui/Ce mois…).
- Colonne MONTANT : valeurs alignées à GAUCHE (« 39,90 € », « 7,50 € ») — REF #12 : prix alignés droite chiffres tabulaires. Gap tableau.
- Format « 11:31, 11-06-2026 » : heure AVANT date + tirets (tirets = gate connu) — l'ordre heure-première reste un choix non-FR à juger.
- Grammaire des statuts mixte : « Accepter » (infinitif, gate connu) vs « Annulée » (participe) vs « En attente / En préparation » (état) — incohérence de système au-delà du seul « Accepter ».
- Donnée seed « RED-UNREL-1 » comme N° commande (DB jetable, pas un défaut UI).

### c2/pos-main.png
- **Intégrité numérique VÉRIFIÉE PAR CROP** : pill header « À encaisser (50) » = panneau « À ENCAISSER BORNE (50) » ✓ COHÉRENT (ma première lecture basse-résolution « 10 » était FAUSSE — crop /tmp/f-design-crops/c2-pos-pill.png le prouve ; même cohérence frais 56=56 en §2bis). Panneau : 4 lignes + « Voir plus (46) » = 50 ✓.
- **Icônes EMOJI dans les boutons header POS** (crop pill) : 🖥 « À encaisser », 📋 « Suivi commandes », 🖥 « Écran client » — emoji-comme-icône sur la barre d'action principale caisse (même famille que modal encaissement/panier borne).
- « Filiale Le Cayenne (Principal) » (header) + « Filiale #1 » (bloc caisse) = jargon multi-tenant sur V1 mono-restaurant ; sélecteur de filiale mort pour le gérant.
- « PRÊT À LIVRER (0) » : vocabulaire livraison sur le panneau des commandes borne prêtes.
- Rail catégories : libellés tronqués à ~10 caractères (« Sandwich… », « Bols Gourm… », « Toutes les … ») — toutes les catégories illisibles.
- Tuiles produits « Frites Seules »/« Boisson Seule » à image cassée (gate DATA connu) = blocs gris à alt-text brut, aucun fallback.
- Mélange de styles de boutons sur la barre d'actions (pill plein / outline / ghost icône) — densité OK mais système visuel hétérogène.

### c2/encaissement.png
- Grille de cartes par commande avec badge « Borne · attente 23h35m » rouge + « Encaisser » orange ×12+ répétés — fonctionnel ; bandeau « Total en attente d'encaissement : 247,60 € ».
- Basse résolution dans l'artefact c2 (~360px) — re-capturé frais en §2.

### c2/kiosk-cart-empty.png
- **Icône = EMOJI caddie de supermarché** (🛒 rendu plateforme) pour un panier de restaurant — iconographie générique hors-brand, incohérente avec le reste (icônes vectorielles). Même famille de défaut que les emojis du modal encaissement.
- CTA « Ajouter des articles » pleine largeur orange mais positionné à ~57% de hauteur, ~40% de vide en dessous — l'état vide n'est pas composé pour le portrait (CTA devrait être ancré bas ou le bloc centré).

### c2/kiosk-error-payment-refused.png — POSITIF (baseline)
- Meilleure exécution du set : hiérarchie icône X rouge / titre / explication / 3 actions à poids visuels DÉCROISSANTS corrects (orange plein → blanc → outline rouge destructif). Récupération claire (REF #19/#20 OK). À ériger en pattern de référence interne.

### c2/cash-overview.png (Vue Caisse Unifiée)
- Intégrité interne ✓ : fond 50,00 € + espèces session 86,00 € = attendu tiroir 136,00 € ✓.
- **Confusion de scope** : KPI « GRAND TOTAL 0,00 € / 0 tx » (filtre 11/06) alors que la réconciliation affiche 86,00 € encaissés (session ouverte 22:46 la veille) — le gérant lit « 0 € » et « 136 € attendus » sur le même écran sans explication de période.
- **Copy « (à venir) » dans l'UI de prod** : « Pour calculer l'écart, saisir le comptage physique du tiroir (à venir). » — annonce de feature manquante en production.
- Carte KPI « LIVREUR » sur V1 sans activité livreur — bruit conceptuel.
- « Réinitialiser Les Filtres » — Title Case systémique encore (« Les »).
- (Tutoiement empty-state = gate connu, pas re-compté.)

### ANOMALIE ARTEFACTUELLE TRANSVERSE c2 (intégrité de la convergence d'hier)
MD5 + manifest `_manifest.txt` prouvent que la couverture statique c2 borne est largement DUPLIQUÉE :
- `kiosk-payment.png` = `kiosk-upsell.png` = `kiosk-loyalty.png` = `kiosk-cart-empty.png` (MD5 identique `b3ac7571…` — 4 fichiers = le MÊME écran panier vide, redirect /kiosk/cart).
- `kiosk-categories/products-sandwich/products-tacos/confirmation/admin/login` → tous final=/kiosk/idle (6 fichiers ≈ écran idle).
- Le manifest note honnêtement les redirects, MAIS : **l'écran CONFIRMATION borne (succès post-paiement) n'existe dans AUCUNE capture** (le flow f01-f14 se termine sur cash-instruction, route Plan B) ; **la grille produits Tacos/Sandwichs jamais capturée** (flow = Desserts uniquement). États visuellement NON validés hier : confirmation, products-sandwich, products-tacos, upsell réel hors flow, payment hors flow.

## 2. Captures fraîches quartet (PNG + .dom.html + .console.txt + .network.txt)

### F-01-borne-idle (1080×1920)
- Identique à hier : dominante sombre + ellipse floue centrale (cf. §1 kiosk-idle). Console/network : uniquement le 401 one-shot `/api/login` boot (gate connu).

### F-02-borne-catalogue-sandwichs (cat=1 — grille JAMAIS capturée hier) ⚠ NOUVEAU
- **Les 3 SKU d'upsell ouvrent la catégorie client** : « BOISSON SEULE » et « FRITES SEULES » (tuiles à image CASSÉE, blanc + icône broken) + « MENU (FRITES + BOISSON) » sont les 3 PREMIÈRES tuiles de la catégorie « Sandwich Cayenne », AVANT les vrais sandwichs. Le client borne voit d'abord 2 tuiles blanches cassées décrites « Upsell item » (anglais). [Image cassée + description EN = gate DATA connu ; le POSITIONNEMENT premier + badge dans la grille client = dimension merchandising distincte, jamais capturée hier.]
- **Badge « Nouveau » (jaune) sur les 3 SKU d'upsell** — données de mise en avant erronées sur des SKU techniques.
- **Tuile « Menu (Frites + Boisson) » avec BORDURE ORANGE** unique (état "featured"?) alors que les tuiles sœurs n'en ont pas — état visuel disparate inexpliqué.
- Badges à grammaire mixte : « Nouveau » (état) vs « Personnaliser » (verbe impératif) — un badge décrit, l'autre ordonne.
- **Troncature mi-mot** : « Sandwich signature avec sauce Cayenne maison. Choix de viande + crud... » — « crud... » (lecture malheureuse en FR) ; ellipse sans frontière de mot.
- Eyebrow « NOS / Sandwich Cayenne » : « NOS » pluriel hardcodé + nom de catégorie singulier = « Nos Sandwich Cayenne » agrammatical.
- Intégrité : 5 produits, prix tuiles 2,00/2,00/3,00/7,00/9,50 € (DOM = rendu ✓).

### F-03-borne-panier (Coca-Cola 33cl + Capri-Sun)
- Intégrité : 2 × 1,50 € → Sous-total 3,00 € = Total 3,00 € = CTA « Valider ma commande 3,00 € » ✓ (3 affichages cohérents).
- **~45% de vide blanc** entre les lignes panier (haut) et le bloc totaux/CTA (bas) — composition portrait non travaillée (récurrence du défaut f02/f04/f06/f07).
- Bandeau « À emporter » : EMOJI 🥡 boîte takeout (3e usage d'emoji-comme-icône : caddie panier vide, 💶 modal caisse, 🥡 ici).
- Placeholder promo en ALL-CAPS « SAISIR UN CODE PROMO... » vs casse normale partout ailleurs.
- Poubelles de ligne ~36px coin de carte (cible <48px), pencil d'édition ~32px.
- Positif : CTA principal ancré bas pleine largeur avec prix en chip — bon pattern ; « + Ajouter des articles » secondaire correct ; bandeau fidélité discret correct.
- Console/network : uniquement le 401 boot connu.

### F-04/F-05/F-06 caisse (1440×900) — capturées en FIN de mission (après vagues A/B). §2bis :

### F-04a-caisse-session-overlay (« Ouvrir la caisse ») ⚠ état jamais capturé hier
- Connexion bm.t2admin → POS gaté par overlay « Ouvrir la caisse » (« Aucune caisse ouverte »).
- **Double saisie du fond de caisse** : grand champ formaté « 50,00 € » (chips +5/+10/+20/+50/Effacer) **ET un second input texte brut « 50 » juste en dessous** — deux champs pour la même valeur visibles simultanément, affordance confuse (lequel fait foi ?).
- Modal propre par ailleurs (titre eyebrow « CAISSE », Annuler/Ouvrir la caisse).

### F-05-caisse-encaissement-modal (= modal « Session active » — run 1)
- Cards FOND DE CAISSE INITIAL 50,00 € / OUVERTE LE 12/06 02:39 / MOUVEMENTS 0 / MONTANT ATTENDU 50,00 € (50+0=50 ✓).
- **« Clôturer la caisse » = CTA rouge foncé pleine largeur, action LA plus proéminente** d'un modal d'information consulté en routine — l'action destructive (clôture Z) est le primaire visuel ; « Voir les mouvements » (consultation) est en ghost au-dessus. Inversion de hiérarchie risquée opérationnellement.

### F-04-caisse-pos (fresh, session ouverte)
- Intégrité : pill « À encaisser 56 » = « À ENCAISSER BORNE (56) » = 4 lignes + « Voir plus (52) » ✓✓.
- Pill header avec EMOJI 🖥 (idem c2). « Filiale #1 », « PRÊT À LIVRER (0) », libellés catégories tronqués, tuiles images cassées Frites/Boisson Seules (gates DATA) — tout identique à hier = pas de régression, pas d'amélioration.
- Console : WebSocket ws://127.0.0.1:6001 failed (soketi down sur harnais :8768 — pattern SYNC-WS-01 connu, fallback polling) — répété sur TOUTES les pages caisse.

### F-05b-caisse-encaissement-vrai-modal (drawer « Commandes borne — à encaisser »)
- Clic pill → drawer latéral droit : cards par commande (N° A0011 / 1× Eau Plate 50cl / timer 01:46 / Détail / ✓ Encaisser / Annuler).
- **MÊME ACTION, DEUX COULEURS SUR LE MÊME ÉCRAN** : « Encaisser » VERT plein dans le drawer, « Encaisser » ORANGE plein dans le panneau « À ENCAISSER BORNE » visible derrière (1 seul screenshot prouve les deux) — le système de couleur action n'est pas normé (orange=marque/primaire vs vert=validation utilisés interchangeablement).
- En-tête drawer avec emoji 📟 + « Historique » chip + « Actualiser » bouton bas — bouton refresh manuel = aveu du polling (pas de live sync).
- Intégrité drawer : A0011 1,00 € / A0012 3,80 € / A0013 7,00 € / A0014 8,50 € = mêmes montants que le panneau derrière ✓.

### F-06-caisse-show (commande 4522 / A0005 fraîche)
- **« Instruction: COCA-COLA 33CL »** — champ instruction auto-rempli avec le NOM DU PRODUIT EN MAJUSCULES affiché au gérant = bruit de donnée template (une « instruction » qui n'instruit rien) ; à masquer si redondant.
- **3e libellé walk-in** : « Passager » (Informations Client) vs « Client passage » (ticket POS/historique) vs « Client Borne » (c2 f11) — trois noms pour le même concept selon la surface.
- « N° Commande: #1206264522· N°A0005 » — point médian collé au numéro (espacement typographique cassé), ID interne 10 chiffres toujours dominant vs A0005 métier.
- « Référence interne: 1 » exposé brut.
- Badge « En Préparation » (Title Case) ; boutons « Imprimer La Facture » (Title Case + terminologie ticket/facture incohérente), « Rembourser » avec icône emoji 💸.
- Intégrité : 3 × Coca-Cola 33cl → ligne 4,50 € = Sous-Total 4,50 € = Total 4,50 €, Remise 0,00 € ✓. Type paiement Espèces, date 12/06/2026 à 02:33 OK.

### Incident de capture documenté (honnêteté artefact)
Run 2 caisse : login silencieusement retombé sur /login → 3 captures = page de connexion (supprimées, F-04 re-capturé proprement au run 3). La page login elle-même montre le Title Case systémique : « Bon Retour », « Mot De Passe », « Se Souvenir De Moi », « Mot De Passe Oublié ».

## 3. Anomalies suspectées (synthèse — evidence = PNG/DOM cités, verdict sévérité = adversaire)
1. **Couverture convergence d'hier surestimée** : 10/25 captures statiques c2 borne = redirects idle/cart (MD5 identiques pour 4) ; écran CONFIRMATION borne jamais capturé nulle part ; grilles produits Sandwichs/Tacos jamais capturées. Evidence : §1 (MD5 + _manifest.txt).
2. **Idle borne à dominante sombre** vs mandat light-mode 100% + ellipse floue type image manquante. Evidence : F-01, c2/kiosk-idle.
3. **SKU upsell en tête de catégorie client avec badge « Nouveau » + tuiles cassées + « Upsell item » EN** (placement/merchandising = au-delà du gate image-DATA). Evidence : F-02.
4. **Système d'action bicolore non normé** : Encaisser vert vs orange même écran. Evidence : F-05b.
5. **« Clôturer la caisse » = primaire visuel du modal session**. Evidence : F-05.
6. **Double input fond de caisse**. Evidence : F-04a.
7. **Emoji-comme-icônes transverse** (caddie panier vide, 🥡 à emporter, 💶/📱/🎫 paiement, 🖥/📋 header POS, 📟 drawer, 💸 Rembourser). Evidence : F-03, F-04, F-05b, F-06, c2/kiosk-cart-empty, flow/f10.
8. **Composition portrait borne : 40-70% de vide** sur wizard/upsell/loyalty/paiement/panier. Evidence : f02, f04, f06, f07, F-03.
9. **Écran f07 paiement : bloc total stylé comme le CTA + texte CTA aligné à gauche + pas de Retour visible**. Evidence : flow/f07.
10. **Hiérarchie numéro inversée (ID interne 10 chiffres > A000x métier) + « · » collé**. Evidence : F-06, flow/f11.
11. **Title Case systémique FR** (login, sidebar, breadcrumbs, boutons, badges) au-delà du gate PaymentComponent. Evidence : F-06, f11, f12, c2/cash-overview, login run-2.
12. **Terminologie incohérente** : ticket vs facture ; Passager vs Client passage vs Client Borne ; grammaire badges (Nouveau/Personnaliser) ; grammaire statuts (Accepter/Annulée/En attente). Evidence : F-06, f11, f12, F-02.
13. **« (à venir) » + « (simulation) » + « Filiale #1 » fuites d'état interne en UI prod**. Evidence : c2/cash-overview, flow/f10, F-04.
14. **Cash-overview : GRAND TOTAL 0,00 € vs tiroir attendu 136,00 € sans explication de période session**. Evidence : c2/cash-overview.
15. **Double message d'erreur rate-limit loyalty** (toast + inline, formulations divergentes). Evidence : flow/f04.
16. **Troncatures** : catégories POS ~10 chars ; « + crud... » mi-mot borne ; « NOS » + catégorie singulier. Evidence : F-02, c2/pos-main.
17. **Montants alignés à gauche colonne MONTANT historique**. Evidence : flow/f12.
18. WS :6001 down sur le harnais → polling + bouton « Actualiser » manuel (SYNC-WS-01 connu, env). Evidence : consoles F-04/05b/06.

## 4. Intégrité numérique relevée (chiffre par chiffre)
- Borne panier frais : 1,50 + 1,50 = Sous-total 3,00 € = Total 3,00 € = CTA « Valider ma commande 3,00 € » ✓ (F-03).
- Borne flux hier : tuile Glace 3,80 € (crop-vérifié, lecture « 5,80 » réfutée) = panier 3,80 € = show 3,80 € ✓ ; total 39,90 € identique sur f07 (payment) = f08 (cash-instruction) = f11 (show Sous-Total/Total, Remise 0,00) ✓.
- POS hier : pill 50 (crop-vérifié, lecture « 10 » réfutée) = panneau (50) = 4 + 46 ✓.
- POS frais : pill 56 = panneau (56) = 4 + 52 ✓ ; drawer A0011 1,00/A0012 3,80/A0013 7,00/A0014 8,50 € = panneau ✓.
- Session caisse : fond 50,00 + mouvements 0 = attendu 50,00 € ✓ (F-05) ; cash-overview : 50,00 + 86,00 = 136,00 € ✓ (c2).
- Show frais : 3 × 1,50 = 4,50 € ligne = Sous-Total = Total, Remise 0,00 ✓ (F-06).
- Catalogue Sandwich Cayenne : 5 produits, 2,00/2,00/3,00/7,00/9,50 € (DOM=rendu) ✓ (F-02).
- AUCUNE incohérence numérique détectée — les 2 suspicions initiales (Glace 5,80 ; pill 10) ont été RÉFUTÉES par crop avant report.

## 5. Verdict de couverture
- 20 captures existantes re-jugées (c2 + flow) + 8 états frais quartetés (3 borne 1080×1920 + 5 caisse 1440×900).
- Livrable design : `DESIGN_GAP_ANALYSIS.md` (même dossier) — top 15 gaps classés impact gérant/client.
- Statut artefacts : 8×4 fichiers quartet + ce rapport + gap analysis. Scripts jetables `tests/e2e/_d1-F-{lib,borne,caisse,caisse2,caisse3}.mjs`.
