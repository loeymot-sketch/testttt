<?php

return [

    /*
    |---------------------------------------------------------------------------
    | [ETIQUETTES 2026-10-19 · owner] Dictée vocale des étiquettes DLC
    |---------------------------------------------------------------------------
    |
    | « Une salade iceberg, tomates, oignons, poivrons coupés » → quatre produits du catalogue
    | pré-sélectionnés, prêts à imprimer d'un appui. Même clé et même fournisseur que le lecteur de
    | tickets Uber et de factures (`services.openai` : en production, OpenRouter + un modèle Gemini) :
    | une seule clé à fournir.
    |
    | UN SEUL APPEL : l'audio (WAV) et le catalogue partent ensemble dans une requête de chat ; le
    | modèle rend la phrase entendue ET les produits reconnus. Pas d'endpoint « transcription »
    | (OpenRouter n'en a pas) : le modèle doit donc accepter l'audio en entrée (Gemini Flash, ou
    | `gpt-4o-audio-preview` si le fournisseur est OpenAI direct).
    |
    | Fichier de configuration NEUF (jamais une clé ajoutée à un fichier que la production modifie
    | en local) : sans fichier réel, la variable d'environnement ne serait jamais lue.
    |
    | Sans clé : la dictée est indisponible (jamais une transcription inventée). Le rapprochement
    | d'un texte déjà écrit reste possible, avec le moteur local.
    */

    // Interrupteur d'urgence. Actif par défaut dès qu'une clé existe.
    'enabled' => (bool) env('LABEL_VOICE_ENABLED', true),

    // Modèle qui écoute et rapproche. Vide → `services.openai.model` (celui de la lecture des tickets).
    'model' => (string) env('LABEL_VOICE_MODEL', ''),

    'timeout' => (int) env('LABEL_VOICE_TIMEOUT', 40),

    // Durée maximale d'un enregistrement côté tablette (secondes) et poids maximal reçu (ko).
    // 40 s en WAV 16 kHz mono ≈ 1,3 Mo.
    'max_seconds' => (int) env('LABEL_VOICE_MAX_SECONDS', 40),
    'max_kb' => (int) env('LABEL_VOICE_MAX_KB', 4096),

    // Étiquettes par produit au plus (même plafond que l'impression).
    'max_copies' => 20,

    // Produits transmis au modèle (le catalogue d'un restaurant en compte quelques centaines).
    'max_catalogue' => 600,
];
