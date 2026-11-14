# Gate brief — Mobile App Store readiness — 2026-10-16

## Statut

**PENDING OWNER DECISION — ne pas auto-approuver.**

Cette fiche ouvre le périmètre nécessaire pour passer de la web app mobile
testable à une application iPhone signable. Elle ne constitue pas une
autorisation de modifier le backend, le paiement, l'authentification, la
fidélité ou le packaging natif.

## Décisions nécessaires du propriétaire

- Bundle ID et Team ID Apple Developer.
- Choix du wrapper natif (Capacitor recommandé pour cette base web) ou
  réécriture native.
- URL HTTPS publique de politique de confidentialité et coordonnées de
  support/review.
- Politique exacte de fidélité : désactivation, suppression, délai de 30 jours,
  points et historique, réauthentification.
- Autorisation d'activer Mollie en environnement de test puis production, avec
  secrets fournis hors dépôt.
- Images de marque manquantes pour Hawaï, Fuze Tea, Perrier et les autres
  boissons sans asset exact dans le pack actuel.

## Evidence

Voir `reports/planning/QA_APPSTORE_READINESS_2026-10-16.md` et le report de
validation fidélité `reports/execution/MOBILE-EXCELLENCE-PHASE15-LOYALTY-REACTIVATION-20261016/report.md`.

## Écart catalogue boissons à résoudre avant de déclarer la parité complète

Le contrôle local `node mobile/tests/mobile-catalog-parity.spec.js` confirme
les 15 boissons actuellement canoniques, leurs noms et leurs prix. Il signale
encore trois visuels neutres : Orangina, Hawaï et Perrier. La recherche des
assets réels du site et de la borne ne trouve pas de packshot exact pour Hawaï
ou Perrier ; les fichiers `generated_*` présents dans le dépôt sont des
illustrations/générations et ne doivent pas être présentés comme des produits
réels.

La liste métier demandée contient en plus des références qui ne sont pas dans
la fixture canonique actuelle (`reports/goal-web-app-sync/catalog-canonical.json`)
ou dont le libellé reste ambigu : Fusty/Fuze Tea, Oasis cassis, Schweppes
agrume, Coca bouteille et Lipton sans parfum. La mobile ne doit pas inventer
ces SKU, prix ou photos. Pour fermer cet écart, il faut fournir la liste
canonique validée (nom exact, format, prix, disponibilité) et les photos
réelles, puis synchroniser la source borne/site avant de remapper l’app.

État vérifié le 2026-10-16 :

| Élément | Preuve | État |
|---|---|---|
| Sandwichs/tacos/galettes/catégories | SHA identiques aux WebP `public/images/menu/thumbs` | PASS |
| Boissons canoniques | 15 noms/prix concordants | PASS |
| Hawaï/Perrier/Orangina | absence de packshot exact dans les sources disponibles | NEEDS_ASSETS |
| Références supplémentaires demandées | absentes ou ambiguës dans la fixture canonique | OWNER_CATALOG_DECISION |

## Invariants protégés

- Prix serveur SSOT ; aucune tarification frontend inventée.
- Aucun changement `OrderStatus`, `branch_id`, dispatch ou service de commande.
- Aucun secret de paiement dans le dépôt.
- Aucun fichier de zone gelée modifié par ce brief.
