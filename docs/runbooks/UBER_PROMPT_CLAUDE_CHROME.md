LIVRABLE 1 — PROMPT POUR "CLAUDE EXTENSION CHROME"

---

Tu es un agent navigateur. Ta mission : aider l'owner du restaurant **« Le Cayenne »** à mettre en place l'accès API **Uber Eats** et à récupérer toutes les valeurs nécessaires pour connecter Uber Eats à notre caisse (POS) et à l'écran cuisine (KDS).

**Règle d'or : navigue et LIS les pages réelles. L'interface Uber change souvent.** Si un libellé de bouton, un onglet ou un chemin que je décris ne correspond pas exactement à ce que tu vois à l'écran, adapte-toi à l'UI réelle et signale l'écart. N'invente jamais un chemin que tu n'as pas vu.

**Sécurité — à respecter en permanence :**
- Ne colle JAMAIS le `client_secret` en clair dans cette conversation ni nulle part de visible.
- Le `client_secret` doit aller dans un endroit privé (le fichier `.env` du serveur, ou un gestionnaire de secrets). Tu confirmes seulement « le secret a été généré et mis de côté en privé », sans le recopier.
- Les valeurs non-secrètes (client_id, Store UUID, scopes cochés, etc.) peuvent être recopiées dans le récapitulatif final.

---

**ÉTAPE 0 — Prérequis (souvent bloquant, à vérifier d'abord)**
1. L'accès aux API « Eats Marketplace » d'Uber n'est pas totalement libre-service : il peut exiger une **approbation écrite d'Uber** (NDA + accord de licence API) et un échange avec un **partner manager Uber Eats**. *(à confirmer sur la page réelle)*
2. Va sur la doc officielle : `developer.uber.com/docs/eats/guides/getting-started`. Lis la section des prérequis et note si l'on doit signer un NDA / accord de licence, et s'il faut demander des comptes de test.
3. Si l'accès Integrator / l'activation POS ne sont PAS libre-service, ouvre une demande de support : `https://t.uber.com/integration-support`. Décris le besoin : « intégration POS maison pour le store Le Cayenne, demande d'allow-list des scopes Eats + comptes de test (developer + store) ». Note le numéro de ticket et/ou le nom du partner manager.

**ÉTAPE 1 — Créer l'application développeur**
4. Va sur `https://developer.uber.com/dashboard` et connecte-toi avec le compte Uber de l'owner.
5. Clique **« Create Application »** *(bouton généralement en haut à droite — à confirmer sur la page réelle)*.
6. Suis le wizard tel qu'il apparaît réellement. La séquence documentée est *(à confirmer sur la page réelle, peut varier selon compte/région)* :
   - **API Suite Selection** → choisir **« Eats Marketplace »**.
   - **Organization Linking** → créer/sélectionner une organisation (Nom, Adresse, Email).
   - **Application Setup** → renseigner App Name + App Description, cocher les conditions d'utilisation (« Uber API Terms of Use »), puis créer.
7. Ouvre l'application (**« Open Application »**) pour accéder à sa configuration.

**ÉTAPE 2 — Récupérer les identifiants**
8. Dans la fiche de l'application, localise **Client ID** et **Client Secret**. *(La doc Eats dit seulement « récupérer depuis le Developer Dashboard » sans nommer l'onglet exact — l'onglet s'appelle souvent « Auth », mais à confirmer sur la page réelle.)*
9. Recopie le **Client ID** (non-secret) dans le récapitulatif final.
10. Pour le **Client Secret** : ne le recopie pas ici. Indique à l'owner de le coller directement dans le `.env` du serveur. S'il faut le (re)générer, cherche un bouton type « Generate / Regenerate secret » *(libellé exact à confirmer sur la page réelle)* — attention, régénérer **invalide immédiatement** l'ancien secret.

**ÉTAPE 3 — Activer le rôle Integrator + l'accès POS (scopes)**
11. Important : « Integrator » n'est pas un simple bouton de rôle ; c'est le fait qu'Uber **autorise (allow-list) les scopes POS** sur notre application. C'est le développeur/partenaire qui en fait la demande, pas le restaurateur. *(à confirmer sur la page réelle)*
12. Dans la configuration de l'app, cherche la section des **scopes / permissions** et vérifie/demande l'activation des scopes suivants. **N'utilise que ces noms exacts confirmés par la doc Uber :**
    - `eats.pos_provisioning` — provisioning/activation des stores
    - `eats.store` — gestion store + menu
    - `eats.store.status.write` — disponibilité du store
    - `eats.order` — traitement des commandes (orders v1)
    - `eats.store.orders.read` — lecture des commandes (orders v2)
    - `eats.report` — rapports
    - *(Si tu vois d'autres scopes proposés à l'écran que je n'ai pas listés ici : NE les coche pas à l'aveugle, note-les dans le récap comme « scope vu non confirmé ».)*
13. En production, ces scopes doivent être **approuvés/whitelistés par l'équipe Uber Eats**. Si l'UI indique « pending approval » ou équivalent, note-le et relie-le au ticket de l'étape 3.

**ÉTAPE 4 — Enregistrer le redirect_uri et le webhook**
14. Cherche le champ **Redirect URI(s)** (OAuth) dans la config de l'app et enregistre l'URL que je te fournirai (nécessaire pour le flow d'activation des stores). Note la valeur enregistrée.
15. Cherche la section **Webhooks** et enregistre l'URL publique de notre endpoint : `https://<notre-domaine>/api/webhooks/uber` *(emplacement exact de la section webhook à confirmer sur la page réelle)*. Note où elle a été enregistrée et quels événements sont disponibles (ex. `store.provisioned`, `store.deprovisioned`).

**ÉTAPE 5 — Obtenir le Store UUID**
16. Le **Store UUID** s'obtient normalement par API après que le marchand a lié son compte (login marchand → `GET /v1/eats/stores`), pas en le tapant à la main. *(à confirmer)* Si le dashboard ou Uber Eats Manager (`merchants.ubereats.com`) affiche un identifiant de store/établissement, recopie-le et indique clairement d'où il vient. Sinon, note que le Store UUID sera récupéré côté serveur via l'API après autorisation.

**ÉTAPE 6 — Demande au support partenaires (si non libre-service)**
17. Si à n'importe quelle étape le rôle Integrator, l'allow-list des scopes ou l'activation POS ne sont pas disponibles en libre-service : ouvre/relance la demande via `https://t.uber.com/integration-support` (ou via le partner manager). Précise : nom du store (Le Cayenne), Client ID de l'app, scopes demandés, besoin de comptes de test. Note la référence du ticket.

**ÉTAPE 7 — Récapitulatif final (à produire à la fin)**
Produis un bloc structuré que l'owner pourra me renvoyer, contenant UNIQUEMENT des valeurs non-secrètes + l'état des cases, par exemple :

```
RÉCAP UBER EATS — Le Cayenne
- Application créée : OUI / NON  (nom : ____)
- Client ID : ____
- Client Secret : généré et placé dans le .env en privé → OUI / NON  (jamais recopié ici)
- Rôle Integrator / allow-list scopes : actif / en attente / à demander
- Scopes cochés : eats.pos_provisioning [ ] · eats.store [ ] · eats.store.status.write [ ] · eats.order [ ] · eats.store.orders.read [ ] · eats.report [ ]
  - Scopes vus mais non confirmés : ____
- Activation POS sur le store : OUI / NON / en attente
- Redirect URI enregistré : ____
- Webhook enregistré (URL) : ____  → à : ____ (emplacement)
- Store UUID : ____  (source : dashboard / API / Uber Eats Manager / à récupérer)
- Comptes de test fournis par Uber : OUI / NON
- Ticket support / partner manager : ____
- Écarts entre l'UI réelle et les étapes décrites : ____
```

Termine en listant clairement ce qui reste bloqué et ce qui nécessite une action d'Uber.

---

LIVRABLE 2 — CHECKLIST « À ME RENVOYER »

**NON-SECRET (tu peux me l'envoyer dans le chat) :**
- [ ] Client ID de l'application Eats Marketplace
- [ ] Confirmation que l'application est créée (et son nom)
- [ ] Liste des scopes effectivement activés/allow-listés (parmi : `eats.pos_provisioning`, `eats.store`, `eats.store.status.write`, `eats.order`, `eats.store.orders.read`, `eats.report`) + tout scope « vu mais non confirmé »
- [ ] État du rôle Integrator / allow-list : actif / en attente / à demander
- [ ] État de l'activation POS sur le store : OUI / en attente
- [ ] Redirect URI enregistré (valeur exacte)
- [ ] URL du webhook enregistrée + emplacement où elle a été saisie
- [ ] Store UUID (s'il est déjà visible) + sa source
- [ ] Comptes de test fournis par Uber : oui/non
- [ ] Référence du ticket support Uber et/ou nom du partner manager
- [ ] Tout écart constaté entre l'UI réelle et les étapes (libellés, onglets différents)

**SECRET (JAMAIS dans le chat — uniquement dans le `.env` du serveur ou un coffre) :**
- [ ] `client_secret` — me confirmer seulement « généré et placé dans le `.env` », sans jamais le recopier
- [ ] (le cas échéant) identifiants des comptes de test marchand/dev fournis par Uber — à garder privés

Note : si une valeur est marquée « (à confirmer sur la page réelle) » dans le prompt, renvoie-la-moi telle qu'elle apparaît réellement à l'écran plutôt que selon le chemin supposé.
