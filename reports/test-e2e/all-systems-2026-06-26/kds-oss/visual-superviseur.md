# KDS+OSS r1 — VISUEL (superviseur, live :8766, Admin authed)
## KDS /admin/kitchen-display-system
- OK: FR propre, empty-state cohérent (« Aucune commande en cours / Les nouvelles commandes apparaîtront ici »), badge « Mode admin centralisé : rafraîchissement automatique toutes les 60 s » (Admin branch 0 = poll-only, correct), avatar « 2,00 € » FR OK.
- [P2/P3] « Récemment servies » rend une durée BRUTE non humanisée : « il y a 9570 min », « il y a 13375 min » (= ~6,6 j / ~9,3 j). Classe du défaut connu « 8898 min »→« 6 j 4 h » via appService.humanizeMinutes(). Source: KitchenDisplaySystemComponent.vue (section recently-served). NON-frozen. lentille: cuisinier. reco: router via humanizeMinutes().
## OSS /admin/order-status-screen
- OK: FR propre, 2 colonnes « En préparation » / « Prêt », empty « — », bouton « Plein écran ».
- [P3] empty-state colonne = « — » (em-dash) ; un libellé type « Aucune commande » serait plus clair sur le mur client. À confronter à la vue PUBLIQUE (sans auth) qui est ce que voit le client. lentille: client-attente.
