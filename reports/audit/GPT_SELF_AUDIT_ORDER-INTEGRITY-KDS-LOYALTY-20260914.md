# GPT Self-Audit — ORDER-INTEGRITY-KDS-LOYALTY-20260914

## Invariants

- PASS — aucune logique de prix ou taxe ajoutée au navigateur.
- PASS — aucune commande scellée, migration historique ou dispatch modifié.
- PASS — fidélité : un conflit ou payload incomplet reste rendu comme erreur, sans transition vers un compte vide.
- PASS — les placeholders e-mail Vue I18n échappent `@` ; l'ouverture du formulaire d'inscription ne peut plus déclencher `Invalid linked format` et vider l'écran.
- PASS — symbole KDS PHP/JS aligné (`HH`, `X`).
- PASS — restauration POS accepte les deux formes de snapshot et conserve les noms d'extras.
- PASS — le chemin legacy `FrontendOrderService` ne perd plus les addons de menu : prix et snapshot
  utilisent les identifiants catalogue et le ratio serveur `menu_*`, en miroir du `PricingService`.

## Validation et risques résiduels

- PASS — un devis POS est obtenu côté serveur avant l'ouverture de paiement; les totaux client sont remplacés par le devis signé.
- PASS — une ligne libre POS est contrôlée par le serveur, TTC, taxée, scellée, rattachée à la branche et imprimable; un commit quote→commande est testé.
- PASS — le validateur d'attributs respecte les étapes actives d'un composer publié et n'exige plus un choix masqué à la Borne.
- PASS — Chromium local : six parcours passent sans échec. Le supplément est transmis et le panier `7,90 €` égale le devis serveur signé `7,90 €`; l'inscription affiche le solde après une réponse contractuelle contrôlée, sans erreur de page; le POS ajoute `Olives` à `1,25 €`; POS et KDS se rendent authentifiés ; un round-trip réel Cayenne conserve Andalouse + Algérienne, ferme la modale après l'édition et garde son prix `7,90 €` (sans troisième sauce générique fantôme).
- PASS — 59 tests ciblés de l'état navigateur et 50 tests PHP couvrent la restauration des deux sauces POS, le supplément libre, l'état fidélité sans page blanche, les libellés commande, le rendu KDS et les invariants serveur. La passe PHPUnit intégrale a atteint 6 052 réussites ; ses trois dérives de sentinelles (two frozen hashes, one plural scope bypass) ont été corrigées puis les 16 tests directement concernés sont verts.
- RISK — la réussite navigateur de l'inscription simule uniquement la réponse POST, car le vrai endpoint retourne légitimement 429 après les essais locaux répétés. Le contrat API réel est couvert côté Laravel ; aucun contournement de sécurité n'a été effectué.
- RISK — seule une revue indépendante externe reste manquante; aucun déploiement n'est autorisé par ce rapport.

VERDICT: PASS
