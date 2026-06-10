# GOAL — CLIENTS NEXT-BEST : du « convergé » au « publié » (app mobile + site web)

**Date:** 2026-06-10 · **Statut:** ⏸️ PLAN-ONLY (attend `lance le GOAL` owner) · **Auteur:** Claude superviseur (ultraplan local, /ultraplan cloud indisponible)
**Fondation:** CLIENTS convergé aujourd'hui (mobile `heal/mobile-update-2026-06-10` adversaire EXHAUSTED 20/20×2 ; web `/Downloads/web main=cc59bbe` axe 0 y c. espace authentifié, 18/18) + `reports/review/STRUCTURE_REVIEW_2026-06-10.md` (lire en premier — collision T-COMPO-2/LOCK-W6 + release/v1 incomplète).

## §0 — THÈSE SUPERVISEUR
Les deux clients sont **techniquement convergés mais invisibles au monde** : 0 push, 0 hébergement web, 0 packaging mobile, 2 systèmes de fidélité divergents au redeem, 29 placeholders légaux. Le « meilleur ensuite » n'est PAS d'ajouter des features : c'est **unifier (fidélité), légaliser (LCEN), publier (PWA + hébergement), préparer le câblage V2** — dans cet ordre. Mandats intacts : standalone NO-wireup V1 · palette mobile NOIR/ORANGE/JAUNE/BLANC (#FF5A1F, jamais #F4501E) · SSOT miroirs 41+4 owner-locked · 0 push sans owner.

## §1 — C-1 : FIDÉLITÉ UNIFIÉE (le seul vrai défaut produit restant)
Constat (convergence d'aujourd'hui) : earn aligné 1 pt/€ ✅ ; **redeem divergent** — mobile : 100 pts = 1 €, min 100 ; web : « dès 200 pts », paliers 500/1500/5000 absents du mobile. Un client verrait deux règles selon la surface.
- T-1.1 Page de décision owner (1 écran HTML local, pattern Wave-Polish Q1-Q14) : option A = modèle mobile partout (continu 100 pts=1 €) · B = paliers web partout · C = hybride (continu + bonus paliers). Recommandation superviseur : **A** (simple, déjà testé bout-en-bout mobile, trivial à porter web).
- T-1.2 Implémentation cross-produit du choix + invariant `pointsFor`/redeem partagé (copier le helper canonique mobile vers web `data/loyalty.js` — ATTENTION : exclusion historique loyalty.js du port prodready, ne reprendre QUE le redeem) ; wording FR identique.
  • anchors : mobile `mobile/data/loyalty.js` + écrans LoyaltyQR/WizardRedeem ; web `/Downloads/web/data/loyalty.js` + page fidélité (espace authentifié)
  • acceptance : `(À CRÉER) mobile/tests/node/loyalty-redeem-parity.test.mjs` + assertion miroir côté web spec ; e2e : même solde → même € affiché sur les 2 produits (captures)
- T-1.3 Divulgation historique : aligner l'aperçu/ledger si le barème change (cascade type F7 — re-baseliner les asserts AVANT gate, leçon P0 du GOAL clients).

## §2 — C-2 : LCEN / LÉGAL → workflow owner-data (GATE-PUBLISH-1)
29 placeholders « À COMPLÉTER » sur le web (5 pages légales dont allergens.html FIC 1169/2011 déjà structurées) ; mobile a ses écrans RGPD.
- T-2.1 Inventaire exact des 29 champs → UNE page de saisie owner (HTML local, formulaire → JSON) listant : raison sociale (E.DELICE SAS + SIRET/TVA déjà connus du backend `foodking:set-branch-legal`), hébergeur, directeur de publication, médiation, etc. Pré-remplir ce qui est déjà dans le repo (SIRET 10417050100019, TVA FR19104170501).
- T-2.2 Script d'injection JSON→pages légales (web + mentions mobile) + re-run axe/spec.
  • acceptance : 0 « À COMPLÉTER » restant OU liste résiduelle explicite signée owner ; spec web 18/18 re-vert.

## §3 — C-3 : PUBLIER (distribution V1 sans wireup)
- T-3.1 **Web → hébergement** : le site est statique (HTML/CSS/JS + localStorage). Cible recommandée : vhost nginx sur l'OVH existant (`commande.lecayenne.fr` ou sous-chemin), TLS Let's Encrypt, déploiement rsync versionné. Préparer le playbook (pattern Ansible du repo) — **exécution = gate owner (DNS + go)**.
- T-3.2 **Mobile → PWA installable** : manifest.json (icônes, NOIR/ORANGE, standalone), service-worker offline-first (assets + menu miroir), bouton « Installer l'app », test Lighthouse PWA ≥ 90. C'est la distribution V1 honnête d'un prototype standalone ; wrapper natif (Capacitor) = V2, décision owner.
  • acceptance : Lighthouse PWA + installation réelle testée (Chrome mobile emulé 390×844) + offline = menu navigable, panier local OK
- T-3.3 Versionner/pousser : tags `clients-v1.0` sur les 2 repos — **push = gate owner PUSH-1**.

## §4 — C-4 : WIREUP-PREP V2 (préparer, ne PAS câbler)
- T-4.1 Doc de mapping mécanique miroir→API : `data/menu.js` ↔ `MenuProjectionService` (kiosk channel), `composer_profile` (pattern hardcodé accepté), commandes localStorage ↔ `FrontendOrderController`, loyalty ↔ backend points. Lister les deltas de nommage owner-locked (Tacos M 6,90/L 8,90 vs DB Tacos 8,50/Big 11,50) → tableau de correspondance à trancher AVANT tout câblage.
- T-4.2 Gate data owner : DB locale `foodking` étrangère (63 items) = incident à résoudre côté backend (hors scope clients, bloque seulement la preuve NF525 locale).

## §5 — VALIDATION (inchangée, la triade qui marche)
Chaque wave : pilote e2e (mobile :8097 viewport 390×844 verrouillé / web :8096 390+1280) + captures analysées + **adversaire par cycle** (leçon du jour : auditer les états AUTHENTIFIÉS/gated) + 2 cycles propres identiques. Specs : suites existantes (audit.spec 8 blocs + massive-audit + web 18) étendues par tâche, jamais remplacées.

## §X — ORDRE & DÉPENDANCES
**Pré-requis structure (hors scope clients, AUTRE session)** : release/v1 doit absorber `heal/mobile-update-2026-06-10` + `goal/cms-gestion-2026-06-10-spine` (cf. STRUCTURE_REVIEW §2 — collision T-COMPO-2). Les waves C-1→C-3 s'exécutent sur les lignes clients canoniques (mobile-update + web main) sans dépendre de la release.
Ordre : C-1 (décision owner T-1.1 en tête) → C-2 → C-3 → C-4 (doc). Owner gates groupés : redeem (T-1.1) · LCEN data (T-2.1) · DNS/hébergement (T-3.1) · PUSH (T-3.3).

## §F — DONE
Un client voit LA MÊME fidélité partout ; les pages légales sont complètes ou explicitement signées ; le site est servi en HTTPS sur un domaine réel ; l'app s'installe (PWA) et marche offline ; le câblage V2 est documenté table-par-table ; le tout re-validé par la triade avec adversaire épuisé ×2 cycles. **PLAN-ONLY — attend `lance le GOAL`.**
