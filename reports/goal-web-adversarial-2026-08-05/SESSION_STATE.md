# SESSION_STATE — GOAL WEB ADVERSARIAL/UX/MOBILE
> Fichier de reprise : une session qui reprend LIT CECI EN PREMIER.

```json
{
  "goal": "plans/GOAL_WEB_ADVERSARIAL_UX_TOTAL_2026-08-05.md",
  "wave": "W7 — cycle adversarial 2 en cours",
  "cycle": 2,
  "web_repo": "/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne",
  "web_head": "0b6556f (4 commits locaux, NON poussés)",
  "cycle2_procureur_diff_web": "RÉGRESSION DÉTECTÉE dans MES correctifs, puis CORRIGÉE (0b6556f) : (1) P1 la clé Mollie par-tentative rouvrait une fenêtre de DOUBLE DÉBIT — la clé stable faisait office de verrou « un seul paiement par commande » et le backend n'en a aucun → verrou synchrone par référence posé avant le premier await ; (2) P2 le retry après refus créait une 2e commande (purge sessionStorage) → cache mémoire par (panier, mode) ; (3) P2 la flèche « Retour au panier » ne rouvrait pas le panier",
  "cycle2_procureur_fidelite": "AUCUNE RÉGRESSION — sentinelle 4/4, suite Loyalty 46/46, API live confirme min_redeem_points=100 avec réglage DB=50 ; 1 P2 trouvé et CORRIGÉ (repli hors-ligne data/loyalty.js encore à 50) ; restes P2/P3 backend listés ci-dessous",
  "sauce_dry_run_local": "`php artisan menu:ensure-new-sauces --dry-run` = 56 variations MANQUANTES en local — à exécuter sur le VPS pour savoir si la substitution silencieuse de sauce est LIVE en prod",
  "backend_note": "dépôt modifié EN PARALLÈLE par une autre session — mon correctif fidélité est dans l'historique (048aa2637), sentinelle verte au HEAD courant",
  "gates": {
    "playwright_parcours_achat": "desktop 1440 + mobile 390 — 0 erreur JS, article réellement ajouté, récap 10,80 €",
    "champs_sous_16px_mobile": "0 (était 4 sur le compte, 1 ailleurs)",
    "cibles_tactiles_hors_norme_mobile": "12 (était 15) — les 12 restantes = liens texte de pied de page, exemptés WCAG 2.5.5",
    "phpunit_loyalty": "46/46 OK",
    "sentinelle_plancher_fidelite": "4/4 OK"
  },
  "open_P0": [],
  "open_P1_owner_gate": [
    "BACKEND SANS VERROU DE PAIEMENT (mis au jour par le cycle 2) : MolliePaymentController::checkout ne teste que `payment_status`, or PAID n'est posé que par le webhook de façon ASYNCHRONE — deux requêtes concurrentes voient toutes deux UNPAID et créent chacune un paiement réel. Aujourd'hui la seule protection est côté CLIENT (clé d'idempotence + verrou synchrone). Un verrou serveur (Cache::lock sur l'order, ou refus si un paiement `open` existe déjà) est la vraie défense. NON APPLIQUÉ : chemin paiement + dépôt backend modifié en parallèle par une autre session",
    "G-W5 : commande carte web diffusée en caisse/cuisine AVANT paiement (FrontendOrderService.php:250) — refermer le gate exige d'activer le chemin web-payé de finalizePaidKioskOrder + allocation fiscale NF525",
    "Sauces Poivre/Burger absentes des variations backend en PROD ? exécuter `php artisan menu:ensure-new-sauces --dry-run` sur le VPS (56 manquantes en local) — sinon substitution SILENCIEUSE de sauce",
    "Gate horaires : commande « dès que prêt ~15-20 min » possible à 14h alors que le service ouvre à 18h — décision métier"
  ],
  "open_P1_P2_a_traiter": [
    "Ticket de confirmation délivré sur les chemins refused/pending sans sonde serveur (le polling n'existe que sur le retour ?order=)",
    "Retour 3DS : conclusion après ~12s de sondage, écran ticket pour une commande qui peut passer CANCELED juste après",
    "URL ?order= rejouée : vide le panier AVANT de vérifier que la commande appartient à la session",
    "Déconnexion web ne révoque pas le token côté serveur (30 j résiduels)",
    "Borne : /loyalty/check renvoie un discount_value NON snappé → DiscountCalculator (sans règle de multiple) accepte un redeem de 150 pts que le web et la caisse refusent. Aucune perte d'argent (débit proportionnel au centime), mais inéquité borne↔web sur la règle annoncée",
    "LoyaltyController::redeem — message « Minimum X points requis » utilise le réglage BRUT, pas le plancher effectif (inatteignable avec la config prod, incohérent sinon)",
    "Paliers : l'API publie tiers [100,250,500,1000,2000] (affichés par la borne) vs statuts web/CGV [500,1500,5000] — deux échelles pour un même client",
    "Wizard : Menu complet +2,50 € sans afficher l'économie réelle de 1,30 € (1,90+1,90=3,80). NON APPLIQUÉ volontairement : ajouter le badge côté web seul romprait la parité d'affichage avec la borne — décision owner"
  ],
  "next_command": "Relire les 2 rapports de procureurs (diff web + cohérence fidélité), corriger ce qu'ils confirment, puis relancer un cycle adversarial complet 6 agents pour atteindre le critère « 2 cycles consécutifs à 0 P0/P1 »",
  "evidence_dir": "reports/goal-web-adversarial-2026-08-05/ + captures dans le scratchpad de session",
  "environnement_local": {
    "site": "http://127.0.0.1:8901/ (miroir scratchpad pointant vers l'API locale)",
    "api": "http://127.0.0.1:8766 (header X-API-Key = MIX_API_KEY du .env)",
    "playwright": "NODE_PATH=<testttt>/node_modules node <script>"
  }
}
```
