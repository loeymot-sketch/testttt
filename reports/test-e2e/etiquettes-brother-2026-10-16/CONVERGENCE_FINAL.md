# test-e2e — Étiquettes Brother QL-820NWB — CONVERGENCE (2026-10-16)

Équipe GStack (capture + correctifs) face à 3 superviseurs adversariaux indépendants (commandes, DLC, imprimante),
priorité VISUELLE, 30+ états par tour (paysage + portrait), artefacts : PNG + DOM + texte + console + réseau + sondes
(débordement, cibles < 44 px, clés i18n brutes). Spec de capture : `tests/Playwright/etiquettes-supervision-2026-10-16.spec.js`
(les images ne sont pas versionnées : la spec les régénère). Spec fonctionnelle : `etiquettes-brother-2026-10-16.spec.js`.

| Tour | P0 | P1 | P2 | P3 | Verdict |
|---|---|---|---|---|---|
| 1 | 0 | **4** | 23 | 9 | AMBER — corrigé |
| 2 | 0 | 0 | 4+2+2 | — | GREEN (3/3) |
| 3 | 0 | 0 | 2 (restants, corrigés ensuite) | 14 | GREEN (3/3) |

**Convergence : tours 2 et 3 consécutifs à P0+P1 = 0.**

## P1 du tour 1 (tous corrigés)
- ORD-001 réimprimer UNE étiquette marquait toute la commande « imprimée » (colonne `partial`, assertion E2E).
- PR-001 bouton « rouleau détecté » proposé capot ouvert (jamais pendant une panne).
- DLC-001 « demain » affiché pour une DLC à +2 jours (calcul en jours calendaires, « après-demain »).
- DLC-002 récapitulatif de « liste du jour » ne montrait que le dernier produit (un seul récapitulatif de lot).

## Fonctions « smart » ajoutées
Rouleau détecté en un appui · titres de panne explicites · alarme sonore + bandeau quand l'automatique est bloqué · âge des
commandes avec urgence · À imprimer / Déjà imprimées · réimpression d'une seule étiquette · produits les plus utilisés en tête ·
recherche + « Ajouter » · « À surveiller » (lots échus / du jour / demain / après-demain) · « Jeté ✓ » avec annulation ·
liste du jour avec − par produit · exemples d'initialisation (préremplissage seulement).

## Hors périmètre / résiduel déclaré
- Sondes `127.0.0.1:9100/9101/6001` (caisse, cuisine, temps réel) : autres modules, pas ce chantier.
- Barre de debug Laravel (environnement de dev uniquement).
- Aucun état « jamais connectée » capturé (coverage gap signalé par le superviseur imprimante) : à vérifier sur la machine.
- Verdict physique (sens du papier, appairage Bluetooth Android) : uniquement sur l'imprimante réelle.
