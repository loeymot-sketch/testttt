# SESSION_STATE — GOAL WEB ADVERSARIAL / UX / MOBILE
> Fichier de reprise : une session qui reprend LIT CECI EN PREMIER.

```json
{
  "goal": "plans/GOAL_WEB_ADVERSARIAL_UX_TOTAL_2026-08-05.md",
  "etat": "8 cycles menés. Cycle 8 INTERROMPU — limite d'usage hebdomadaire atteinte (réinitialisation 16h Europe/Paris).",
  "web_repo": "/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne",
  "web_head": "4c7262a — commits LOCAUX, AUCUN push",
  "backend_head": "0c8adb238 — commits LOCAUX, AUCUN push",
  "P0_sur_8_cycles": 0,

  "gates": {
    "sentinelle_web": "35/35 — dont 2 COMPORTEMENTALES prouvées capables de rougir",
    "nav_smoke_depot": "13/13, 0 erreur JS",
    "parcours_achat": "desktop 1440 + mobile 390, 0 erreur JS",
    "phpunit_frontend": "43/43",
    "phpunit_auth": "50/50",
    "phpunit_sync": "29/29"
  },

  "AVERTISSEMENT_CENTRAL": "Un « vert » de cette campagne ne prouve rien. Un procureur a ANNULÉ intégralement un correctif : la sentinelle est restée 35/35 VERTE. ~21 de ses 35 assertions sont des regex sur le TEXTE SOURCE. Trois correctifs ont été verts tout en étant MORTS (grep sur une URL inexistante renvoyant 405 ; règle CSS visant 3 sélecteurs absents du markup ; repli jamais exécuté car la valeur testée n'était jamais vide). NE JAMAIS CRÉDITER UN CORRECTIF SUR LA FOI D'UNE LIGNE DE CODE OU D'UN VERT.",

  "VERIFICATIONS_FAITES_A_LA_MAIN_cycle8": "Voir reports/goal-web-adversarial-2026-08-05/VERIFICATIONS-CYCLE-8.md — F-D (test qui APPELLE le service, prouvé capable de rougir), F-I (3 inscriptions -> 1 jeton, en base), F-F (sauce fantôme refusée + snapshot scellé correct, bout-en-bout), F-B/F-C (écran carte impayée observé au DOM : 0 QR, 0 ticket, 0 confetti, 0 total), F-A partiel (URL nettoyée + 2 vérifs comportementales de la sentinelle), F-H (format validé contre le vrai formateur).",

  "VERIFICATIONS_DUES": [
    "RESTENT NON EXERCÉS : F-E (garde R1 livraison sur les DEUX routes + reap janitor) et F-G (commande livraison -> les 2 listeners d'impression, octets ESC/POS). Tout le reste a été exercé à la main au cycle 8.",
    "F-E — garde R1 livraison : commande livraison carte impayée bloquée sur les DEUX routes, et reapée par le janitor.",
    "F-G — commande livraison → les 2 listeners d'impression déclenchés (octets ESC/POS)."
  ],

  "F_D_VERIFIE_COMPORTEMENTALEMENT": "tests/Feature/Frontend/WebCardOrderPaidPathReleasesAllOrderTypesTest.php APPELLE finalizePaidKioskOrder pour order_type 10, 5 et 20. Preuve qu'il rougit : restriction order_type réintroduite → ROUGE sur le type 20 (« Failed asserting that false is true ») ; restaurée → 3/3 verts.",

  "GATE_OWNER_prioritaire": [
    "NF525 — une fenêtre a existé entre mon correctif du cycle 6 et celui du cycle 7 où une commande carte web d'un order_type hors {TAKEAWAY, DELIVERY} pouvait être PAYÉE, rester PENDING, sans fiscal_sequence_no. UNIQUEMENT sur le dépôt local, RIEN N'EST DÉPLOYÉ. À arbitrer : vérifier si des commandes réelles sont concernées en production.",
    "Verrou de paiement SERVEUR absent : MolliePaymentController ne teste que payment_status, posé de façon ASYNCHRONE par le webhook. La seule protection anti-double-paiement est aujourd'hui du JavaScript client.",
    "Sauces Poivre/Burger : `php artisan menu:ensure-new-sauces --dry-run` = 56 variations manquantes EN LOCAL. À exécuter sur le VPS. Le web est désormais fail-loud, donc ces sauces sont INCOMMANDABLES tant que la donnée manque.",
    "Pré-commandes hors service : on peut commander « dès que prêt » à 14h alors que le service ouvre à 18h (décision métier ; porter isOpenNow dans le funnel créerait une jumelle de logique).",
    "Paliers de fidélité : site + CGV annoncent 4 rangs [0/500/1500/5000], l'API en publie 5 [100/250/500/1000/2000] que la borne affiche.",
    "Économie réelle du Menu complet (1,30 €) non affichée — vérifié que la borne n'affiche aucune économie, donc aucune parité à rompre."
  ],

  "P2_P3_OUVERTS": [
    "Lane PREPARED du janitor = code mort (son propre whereIn order_type la neutralise)",
    "Canal client absent pour la surface delivery → suivi temps réel mort",
    "Champ carte recouvert sous clavier ANDROID (scroll-margin sur l'ancêtre, pas sur l'iframe)",
    "Sonde comptoir tirée une seule fois → QR vivant si la caisse annule pendant la lecture",
    "« rien n'a été débité » affirmé sur une commande réellement encaissée (status=22 + ps=5)",
    "Sentinelle backend qui RÉPLIQUE la règle au lieu de l'appeler (et dont la réplique est périmée)",
    "Tout styles-mobile.css est inerte en PAYSAGE (37 media queries max-width ≤800px) — seul le correctif panier est hors media query",
    "config('order.web_stale_unpaid_ttl_minutes') et kiosk.stale_web_collect_ttl_minutes n'existent pas → env inertes",
    "401 sur la sonde → déconnexion silencieuse · fil d'ariane « Confirmé » sur écran annulé · pas de verrouillage du scroll derrière la modale"
  ],

  "MOTIF_DOMINANT_A_SURVEILLER": "Un correctif appliqué à la surface REGARDÉE, sans ses JUMELLES. Exemples de la campagne : 'unknown' propagé à une liste sur quatre · 'delivery' propagé à une garde sur trois · order_type « aligné » en EXCLUANT des deux côtés au lieu d'inclure · une garde R1 fermée sur une route et pas sur sa sœur. AVANT TOUT COMMIT : grep les jumelles.",

  "pieges_de_methode": [
    "Un banc qui ne sert pas EXACTEMENT le code audité produit des verdicts faux dans les deux sens (vérifier par shasum ; :8899 sert le dépôt, :8901 est un miroir à régénérer après CHAQUE modification d'index.html).",
    "document.body.scrollWidth MENT — mesurer documentElement + un scrollTo(500,0) puis relire scrollX.",
    "Les captures fullPage n'exécutent pas les révélations au scroll et écrasent les modales position:fixed.",
    "Une assertion de texte qui n'ignore pas les COMMENTAIRES échoue sur le commentaire citant la phrase supprimée.",
    "Un commentaire // en FIN de ligne dans un in_array() PHP avale la parenthèse fermante.",
    "Un commentaire JSX en tête d'une branche de ternaire casse la compilation.",
    "Un correctif qui supprime un blocage peut supprimer une PROTECTION non documentée (le 409 gênant ÉTAIT le verrou anti-double-paiement)."
  ],

  "environnement_local": {
    "site_depot": "http://127.0.0.1:8899/ (sert le dépôt tel quel)",
    "site_miroir": "http://127.0.0.1:8901/ (scratchpad, index.html est une COPIE — RÉGÉNÉRER après toute modification)",
    "api": "http://127.0.0.1:8766 (header X-API-Key = MIX_API_KEY du .env)",
    "playwright": "NODE_PATH=<testttt>/node_modules node <script>",
    "otp": "table `otps`, colonne `token` (la colonne `code` est l'indicatif téléphonique)",
    "panier_de_test": "Perrier 33cl (id 125) — le Coca-Cola 33cl est ÉPUISÉ, il bloque « Passer commande »"
  },

  "next_command": "Relancer le cycle 8 avec le mandat « EXERCER, pas lire » (voir VERIFICATIONS_DUES), puis un cycle 9 complet. La convergence exige DEUX cycles consécutifs à 0 P0/P1 — jamais atteint à ce jour."
}
```
