# W5 E2E LIVE — CENTRAL + UI/UX — 2026-07-02

## Surfaces capturées (admin, serveur live :8766, 0 erreur console sauf notées)
- **Dashboard** (`central-dashboard.png`) : accès rapides staff, KPIs (Total ventes 38 182,72 €,
  2761 commandes, 59 articles), Suivi direct jour (0 aujourd'hui = normal), Alertes SLA, Répartition
  canal, **Audit Trail NF525** (lignes HMAC réelles). FR + branding Cayenne. Vitrine client = staff-only.
- **Catalogue / Catalog Studio** (`central-catalogue.png`) : **13 catégories / 59 articles**, images +
  prix + badges Actif. Détail : Sandwichs 5, Tacos Signature **0** (vide), Galette 2, Sandwich Classique
  2, Burgers 7, Tacos 3, Bols 8, Frites 6, Suppléments **10** (modificateurs), Desserts 3, Boissons 8,
  Menu enfant 2, **Technique (interne — upsell) 3**. → Le « 59 » du dashboard = catalogue complet ;
  le menu client vendable ≈ 45-48 (hors catégorie vide + suppléments + interne). **Doc-drift
  CONSTITUTION « 45 items » = bénin** (référence au menu client, pas au catalogue total).

## Verdict UI/UX (surfaces testées)
- **Layout** : intact sur toutes les surfaces, responsive, pas de débordement, pas de raw-label
  (`Label.X` / `kiosk.foo` / `0undefined`) observé.
- **i18n FR** : résolu partout (0 clé brute), ADR-007 respecté.
- **Branding** : palette Cayenne (orange #F4501E / dark) cohérente ; kiosk noir/orange/jaune attract.
- **a11y (bon)** : pavé numérique paiement labellisé (group + « Effacer le dernier chiffre »/« tout ») ;
  tooltips explicatifs sur actions caisse ; radios order-type avec libellés.
- **Empty states** : cohérents (« Aucune commande prête à livrer », « Aucun article… », OSS colonne
  Prêt vide avec placeholder).
- **Cohérence** : le flux caisse category-first est clair et rapide (9 catégories → produits → wizard).

## Points UI/UX relevés (non-bloquants)
- **P3** : incohérence format devise wizard POS frozen `€7.90` vs reste FR `7,90 €` (frozen → doc-only).
- **P3** : modal encaissement libellé « Commande **Borne** » même pour une commande caisse (composant
  `PosCounterCollectModal` mutualisé) — cf. cross-surface-sync.md OBS-UX-1.
- **Bon point** : labels « simulation » honnêtes sur TPE/tiroir (transparence CONSTITUTION §2).
