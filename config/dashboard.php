<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Fenêtre des alertes SLA
    |--------------------------------------------------------------------------
    |
    | [GOAL CONSOLIDATION_V1_PRODUCTION_20260825 — T-5.3.3]
    |
    | `DashboardService::slaAlerts()` ne retenait que la borne HAUTE (« en préparation depuis
    | plus de 15 minutes ») et n'avait aucune borne basse. Mesure du 2026-08-25 sur la base de
    | développement : 344 commandes remontaient, dont les 344 avaient plus de 24 h, la plus
    | ancienne 75 jours. Le panneau alertait donc en permanence, sur rien.
    |
    | Une alerte SLA porte sur le service EN COURS. Au-delà de cette fenêtre, une commande figée
    | n'est pas un retard : c'est de la donnée morte, qui relève d'un nettoyage d'exploitation.
    | La borne est configurable parce que la durée d'un service est une décision d'exploitation,
    | pas une constante technique.
    |
    */

    'sla_alerts_window_hours' => (int) env('DASHBOARD_SLA_WINDOW_HOURS', 24),

    /*
    | Seuil au-delà duquel une commande en préparation devient une alerte.
    */
    'sla_alerts_threshold_minutes' => (int) env('DASHBOARD_SLA_THRESHOLD_MINUTES', 15),

];
