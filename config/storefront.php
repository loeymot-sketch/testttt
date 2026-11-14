<?php

/**
 * [APP HYBRIDE W2a · 2026-11-03 · décision propriétaire] VITRINE DISTANTE.
 *
 * Ce que le propriétaire change pendant les fêtes SANS nouvelle version de l'application :
 * bandeaux, offres flash (qui pointent vers un code promo), couleurs. La livraison on/off
 * N'EST PAS dupliquée ici : elle reste l'interrupteur `order_setup_delivery`
 * (App\Services\Delivery\DeliveryAvailability), que la vitrine se contente de relayer.
 *
 * Règle Apple 2.5.2 : le document publié contient des RÉGLAGES, jamais du code. D'où :
 *   · couleurs = liste blanche de jetons, valeurs `#RRGGBB` seulement (aucune CSS libre, aucune URL) ;
 *   · liens de bouton = routes INTERNES de l'application (`#menu`, `#loyalty`, `#menu/<produit>`), jamais une adresse web ;
 *   · textes sans balise (`<` et `>` refusés).
 * Prix : la vitrine n'en affiche aucun (PricingService reste la seule source) ; une offre pointe vers un code promo.
 */
return [
    // Jetons de couleur acceptés, dans l'ordre d'affichage de l'écran d'administration.
    'theme_tokens' => [
        'primary'     => 'Couleur principale (boutons)',
        'on_primary'  => 'Texte sur la couleur principale',
        'accent'      => "Couleur d'accent (décor)",
        'background'  => 'Fond des écrans',
        'surface'     => 'Fond des cartes',
        'text'        => 'Texte courant',
        'banner_bg'   => 'Fond du bandeau',
        'banner_text' => 'Texte du bandeau',
    ],

    // Paires texte / fond contrôlées (WCAG 2.1 AA, texte normal : 4,5:1 minimum).
    'contrast_pairs' => [
        ['text', 'background'],
        ['text', 'surface'],
        ['on_primary', 'primary'],
        ['banner_text', 'banner_bg'],
    ],
    'min_contrast' => 4.5,

    // [RED cycle 3 · P2-3 2026-11-03] Couples RÉELS du site, contre une couleur FIXE de ses feuilles de style :
    //   · texte BLANC fixe peint sur `primary` (boutons, récap du panier : styles-v4.css:108, styles.css:474…) — seuil 3:1,
    //     niveau actuel de l'orange de la marque (3,11:1), sinon le préréglage lui-même serait refusé ;
    //   · `accent` lu comme TEXTE sur fond noir (tuile de catégorie active, carte fidélité) — 4,5:1.
    // [jeton, couleur fixe, seuil, phrase]
    'fixed_contrast' => [
        ['primary', '#FFFFFF', 3.0, 'le texte blanc que l\'application écrit sur cette couleur (boutons, récapitulatif du panier)'],
        ['accent', '#0A0A0A', 4.5, 'le fond noir sur lequel l\'application écrit cette couleur (catégorie choisie, carte fidélité)'],
    ],

    // Couleurs actuelles du site (styles.css : --orange, --ink, --yellow, --cream, --paper) — proposées en préréglage.
    'default_theme' => [
        'primary'     => '#FF5A1F',
        'on_primary'  => '#0A0A0A',
        'accent'      => '#FFD93D',
        'background'  => '#FAF7F2',
        'surface'     => '#FFFFFF',
        'text'        => '#0A0A0A',
        'banner_bg'   => '#0A0A0A',
        'banner_text' => '#FFD93D',
    ],

    // Routes internes de l'application vers lesquelles un bouton de bandeau peut mener (routes « # » du site, racine.jsx).
    // `menu/<produit>` est accepté en plus (fiche d'un produit). Paiement, confirmation, suivi : exclus (ils exigent un état).
    'link_routes' => [
        'home'    => 'Accueil',
        'menu'    => 'La carte',
        'loyalty' => 'Fidélité',
    ],

    'max_banners' => 20,

    // [T-3.1.1 2026-11-10] Thèmes de couleurs programmés (« design d'un jour » : fêtes, Noël…). Chacun porte un début, une
    // fin, une priorité et un jeu complet de couleurs soumis aux mêmes règles de contraste que le thème de base.
    'max_scheduled_themes' => 12,

    // Messages de livraison par défaut (l'administrateur peut les remplacer dans la vitrine).
    'delivery_messages' => [
        'on'  => 'Livraison disponible.',
        'off' => 'La livraison est momentanément suspendue. Vous pouvez commander à emporter.',
    ],

    // Historique gardé à l'écran (la table, elle, garde tout).
    'history_shown' => 15,
];
