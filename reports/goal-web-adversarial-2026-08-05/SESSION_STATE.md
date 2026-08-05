# SESSION_STATE — GOAL WEB ADVERSARIAL / UX / MOBILE
> Fichier de reprise : une session qui reprend LIT CECI EN PREMIER.

```json
{
  "goal": "plans/GOAL_WEB_ADVERSARIAL_UX_TOTAL_2026-08-05.md",
  "wave": "W7 — cycle adversarial 4 EN COURS (4 agents)",
  "cycle": 4,
  "web_repo": "/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne",
  "web_head": "b6f1fda — commits LOCAUX, AUCUN push",
  "backend_head_note": "b5a8922d3 (correctif coupon). ⚠️ Dépôt backend ET dépôt web modifiés EN PARALLÈLE par une autre session (travaux « 8 axes ») : mes fichiers stagés ont été absorbés par ses commits à plusieurs reprises — contenu vérifié présent dans HEAD à chaque fois. Coordination owner requise.",
  "gates": {
    "sentinelle_audit": "27/27 (13 invariants de source + 10 des cycles 3-4 + 4 mesurés au navigateur)",
    "nav_smoke_depot": "13/13, 0 erreur JS",
    "parcours_achat_reel": "desktop 1440 + mobile 390 — article ajouté, récap 10,80 €, 0 erreur JS",
    "phpunit_loyalty": "46/46",
    "phpunit_sentinelle_plancher": "4/4",
    "phpunit_coupon_killswitch": "2/2",
    "phpunit_kiosk_frontend": "10/10"
  },
  "P0_sur_les_4_cycles": 0,
  "P1_fermes": [
    "Annuler l'inscription laissait un état connecté fantôme (3 fermetures vérifiées en base)",
    "Cul-de-sac « carte refusée » : les deux issues proposées renvoyaient un 409",
    "Fenêtre de DOUBLE DÉBIT rouverte par ma propre clé par-tentative → verrou synchrone (prouvé : 4 clics = 1 commande)",
    "Retry après refus créait une 2e commande → cache mémoire de la clé",
    "Ticket + QR délivrés pour une commande ANNULÉE (4xx avalé en repli comptoir)",
    "?order= étranger détruisait un paiement 3DS en cours",
    "« Se déconnecter » ne révoquait rien côté serveur (token vivant 30 j, prouvé au curl)",
    "Toute erreur OTP maquillée en « Code incorrect », même un code JUSTE bloqué par le débit",
    "Ticket « en attente » figé à vie sans aucune sonde serveur",
    "Deux onglets = deux commandes réelles (32,40 € pour un panier)",
    "Coupon promis à l'écran puis refusé au dernier clic (kill-switch backend)",
    "Modale compte INACCESSIBLE en 1366×768 (bouton hors écran, focus piégé)",
    "Viande surplus facturée à l'écran mais absente du payload (seul chemin fail-silent restant)",
    "Mon correctif du délai était du CODE MORT (slotTime pré-amorcé) → ticket promettant 12 min pour 30-35 réels"
  ],
  "open_owner_gate": [
    "G-W5 : commande carte web diffusée en caisse/cuisine AVANT paiement — refermer le gate exige d'activer le chemin web-payé de finalizePaidKioskOrder + allocation fiscale NF525",
    "VERROU DE PAIEMENT SERVEUR ABSENT : MolliePaymentController ne teste que payment_status, posé de façon ASYNCHRONE par le webhook. La seule protection anti-double-paiement est aujourd'hui du JavaScript client",
    "Sauces Poivre/Burger : `php artisan menu:ensure-new-sauces --dry-run` = 56 variations manquantes EN LOCAL — à exécuter sur le VPS",
    "Pré-commandes hors service : on peut commander « dès que prêt » à 14h alors que le service ouvre à 18h (décision métier ; porter isOpenNow dans le funnel créerait une jumelle de logique)",
    "Paliers de statut : site + CGV annoncent 4 rangs [0/500/1500/5000], l'API en publie 5 [100/250/500/1000/2000] que la borne affiche",
    "Afficher l'économie réelle du Menu complet (1,30 €) — vérifié : la borne n'affiche aucune économie, donc aucune parité à rompre"
  ],
  "open_P2_P3": [
    "Écran OTP annonçant un destinataire du code potentiellement faux, voire inexistant",
    "Throttle OTP par IP → verrouillage collectif derrière un NAT",
    "Compte créé dès la 4e touche : fermer n'annule plus rien, aucune affordance de suppression",
    "Onglet « Connexion » exigeant prénom + nom, identique à l'inscription",
    "Pastille d'avatar affichant les 2 derniers chiffres du téléphone (confondue avec un compteur)",
    "Panier vide sans bouton de sortie · double croix dans la recherche · allergènes à 10px (sécurité alimentaire) · pas de page 404 de marque · format de date « 03:58, 05-08-2026 »",
    "Dénominateur du wizard qui grandit en cours de route (1/6 → 8/8)"
  ],
  "pieges_de_methode_a_ne_pas_refaire": [
    "Un banc de test qui ne sert pas EXACTEMENT le code audité produit des verdicts faux dans les deux sens : mon miroir liait tout en symlink SAUF index.html (copie) — mes modifications n'étaient pas testées. Vérifier par `diff <(curl -s <url>) <fichier>`.",
    "`document.body.scrollWidth` MENT (le tiroir panier hors écran le gonfle) : le seul test valable est documentElement.scrollWidth vs clientWidth PLUS un scrollTo(500,0) suivi de la relecture de scrollX.",
    "Les captures `fullPage` n'exécutent pas les révélations au scroll et écrasent les modales position:fixed.",
    "Une assertion de texte qui ne retire pas les COMMENTAIRES échoue sur le commentaire qui cite la phrase supprimée.",
    "Un correctif qui supprime un blocage peut supprimer une PROTECTION non documentée : le 409 gênant ÉTAIT le verrou anti-double-paiement."
  ],
  "next_command": "Lire les 4 rapports du cycle 4, corriger ce qu'ils confirment, puis relancer un cycle 5 : la convergence exige DEUX cycles consécutifs à 0 P0/P1.",
  "environnement_local": {
    "site_miroir": "http://127.0.0.1:8901/ (scratchpad/site-local — index.html est une COPIE, à RÉGÉNÉRER après toute modification d'index.html)",
    "api": "http://127.0.0.1:8766 (header X-API-Key = MIX_API_KEY du .env)",
    "playwright": "NODE_PATH=<testttt>/node_modules node <script>",
    "otp": "table `otps`, colonne `token` (la colonne `code` est l'indicatif téléphonique)"
  }
}
```
