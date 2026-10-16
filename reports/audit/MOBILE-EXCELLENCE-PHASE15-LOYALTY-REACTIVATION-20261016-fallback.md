# Audit de secours — MOBILE-EXCELLENCE-PHASE15-LOYALTY-REACTIVATION-20261016

Date : 2026-10-16  
Canal : audit de secours, lecture seule du code produit  
Écriture autorisée : ce fichier uniquement

## Statut

**AUDIT_FALLBACK_VERDICT: NEEDS_FIX**

Ce document ne constitue pas un audit humain/Claude terminal réussi. Aucun
`AUDIT_VERDICT: PASS` ni `GPT_FINAL_AUDIT_VERDICT: PASS` n’a été émis et le
cycle actif n’a pas été modifié. Le cycle reste non clôturé.

## Sources et état du cycle

J’ai relu `AGENTS.md`, `.cursor/ACTIVE_CYCLE.md`, le plan
`plans/PLAN_MOBILE-EXCELLENCE-PHASE15-LOYALTY-REACTIVATION-20261016_2026-10-16.md`,
le report d’exécution associé, les fichiers de l’allowlist et les contrats
mobile/backend utiles au paiement, à la suppression de compte et à la fidélité.

État observé :

- `PHASE: VALIDATE` ;
- délégation : `foodking-complex-implementer (codex-extension-fallback)` ;
- raison : canal `codex-extension` indisponible ;
- champs d’audit Claude et GPT final encore vides.

Le journal d’activité confirme une exécution ciblée terminée à 16:50:05Z et
indique explicitement que les audits formels restent à faire
(`reports/AGENT_ACTIVITY_LOG.md:888-889`).

## 1. Implémentation Phase 15

### PASS — objectif local de réactivation

Le bouton `Réactiver mon compte` est relié à
`reactivateLoyaltyAccount` (`mobile/screens-main.jsx:58-85,1440-1445`). Le
helper :

- persiste `opted_in` via `LC.storage.setConsent` ;
- remet le compte local à zéro via `LC.loyalty._replaceAccount` ;
- émet `lc:loyalty-changed` pour rerendre l’écran et le QR ;
- ne dépend plus de `window.LC.dev` pour la réactivation.

Le décor fidélité est `aria-hidden` et `pointerEvents: 'none'`
(`mobile/screens-main.jsx:1433-1434`) : il ne doit plus intercepter les clics.

### PASS — preuves de validation ciblées

Preuves relues dans le report et contrôles statiques réexécutés pendant cet
audit :

- `bash .cursor/hooks/safety-check.sh` : PASS, zones gelées intactes ;
- `node mobile/tests/mobile-ui-excellence.spec.js` : PASS ;
- `node mobile/tests/mobile-catalog-visual.spec.js` : PASS ;
- `git diff --check` : PASS ;
- le report déclare `tests/mobile-e2e/loyalty-11-opt-out.spec.js` à `1/1
  PASS`.

L’E2E déclaré PASS n’a pas été relancé dans cet audit afin de rester en lecture
seule et de ne pas régénérer d’artefacts de capture. C’est donc une preuve
relue du report, pas une nouvelle exécution indépendante.

### NEEDS_FIX — l’opt-out reste dépendant du namespace dev

Le correctif retire `LC.dev` de la réactivation, mais l’opt-out conserve :

```
mobile/index.html:365
if (window.LC && window.LC.dev) window.LC.dev.setConsent('opted_out');
```

Le même fichier charge `data/dev-helpers.js` sans garde de build
(`mobile/index.html:89-90`). L’E2E s’exécute donc dans un contexte où le
helper est disponible et ne prouve pas que l’opt-out fonctionne dans un build
mobile dépourvu de `dev`. Le flux complet opt-out → réactivation n’est pas
portable au-delà du prototype tant que l’opt-out n’utilise pas l’API publique
`LC.storage.setConsent`.

Cette correction n’était pas dans l’allowlist Phase 15 : elle nécessite une
mission bornée séparée.

### NEEDS_FIX / ESCALATE — réactivation locale sans synchronisation serveur

Le helper met à jour uniquement le stockage et le miroir local. Il n’appelle
pas le backend. Le dépôt expose pourtant `POST /api/frontend/loyalty/opt-in`
(`routes/api.php:2221-2224`,
`app/Http/Controllers/Frontend/LoyaltyController.php:553-578`).

La preuve actuelle démontre donc la réapparition d’un QR local et un compte
local à zéro, mais pas la réactivation d’un compte réel côté serveur. Une
décision propriétaire est requise pour définir le contrat de réactivation
(identité, consentement, idempotence, ancien solde et statut), puis une mission
backend/mobile avec gate auth/loyalty doit l’implémenter et la tester.

La politique « restauration sous 30 jours » n’est pas inventée par cet audit et
ne peut pas être déduite de l’implémentation actuelle.

## 2. Invariants FoodKing

| Invariant | Verdict de secours | Constat |
|---|---|---|
| Pricing : backend SSOT | **PASS dans le périmètre** | Phase 15 ne modifie aucun prix ni service de commande. Le mobile transmet les identifiants d’articles, pas un prix, dans `mobile/api/client.js:434-456`. |
| `OrderStatus` enum unique | **PASS dans le périmètre** | Aucun changement Phase 15 ne touche aux transitions/statuts de commande ni n’introduit de chaîne de statut. |
| Isolation `branch_id` | **PASS sans delta observé** | Aucun changement Phase 15 de branche ou de requête backend. Le client conserve le `branch_id` de configuration dans `mobile/api/client.js:444`. |
| Dispatch après commit DB | **PASS / non applicable** | Aucun backend, job, event, dispatch ou transaction n’est modifié par Phase 15. |
| Zones frozen | **PASS pour l’exécution auditée** | Le safety-check passe ; le commit Phase 15 ne touche pas les zones backend/POS/kiosk gelées. |

Ces PASS sont des PASS de non-régression dans le périmètre Phase 15, pas une
certification globale de tout le dépôt.

## 3. Aptitude App Store

### NEEDS_FIX — projet iOS natif absent

La recherche du dépôt n’a trouvé aucun fichier `*.xcodeproj`,
`*.xcworkspace`, `Podfile`, `Info.plist`, `project.pbxproj` ou
`capacitor.config.*`. Le mobile actuel est servi par
`mobile/index.html`, JSX, scripts et assets ; le `package.json` ne contient
pas de dépendance Capacitor, Expo ou React Native.

Il n’y a donc pas de cible iOS signable, bundle ID, Info.plist, capabilities,
configuration de signature ou archive `.ipa` dans ce dépôt. La soumission à
App Store Connect n’est pas démontrée possible.

### NEEDS_FIX — Mollie non activé et non câblé dans le mobile

Le backend possède une structure Mollie et les routes de checkout/webhook
(`routes/api.php:2039-2044`, `app/Http/Controllers/Frontend/MolliePaymentController.php`,
`app/Http/PaymentGateways/Gateways/Mollie.php`). Mais :

- `config/payment.php:114-116` est fail-closed par défaut
  (`MOLLIE_ENABLED=false`, clé vide) ;
- `mobile/index.html:69-74` fixe `onlineCardEnabled: false` ;
- `mobile/api/client.js:28` désactive la carte en ligne ;
- `mobile/api/client.js:434-449` envoie toujours `payment_method: 1` ;
- `mobile/index.html:325-345` masque/redirige le parcours carte.

La parité avec le site web n’est donc pas prouvée : le backend web est
structuré, mais l’application mobile ne crée pas de checkout Mollie.
L’activation live exige un gate propriétaire, des secrets hors dépôt, le
contrat mobile checkout/idempotence/webhook et des tests de paiement dédiés.

### NEEDS_FIX — suppression de compte absente de l’interface mobile

Le backend expose `POST /api/auth/delete-account`
(`routes/api.php:294-298`) et le contrôleur efface les identifiants
personnels puis révoque les tokens
(`app/Http/Controllers/Auth/DeactivateController.php:59-110`).

Aucune occurrence d’un bouton, écran ou appel mobile vers cette route n’a été
trouvée dans `mobile/` et les tests mobile. L’endpoint backend seul ne
prouve pas le parcours de suppression exigé dans l’application : il faut une
entrée visible dans le compte, une confirmation, l’appel authentifié, la
déconnexion et une preuve E2E.

### ESCALATE — politique de réactivation et fenêtre de 30 jours

Le code affiche que les points sont effacés et réactive localement à zéro
(`mobile/screens-main.jsx:1442-1444`). Aucun endpoint ou modèle de
conservation/restauration sous 30 jours n’a été trouvé. Le backend de
suppression est au contraire documenté comme destructif et irréversible
(`app/Http/Controllers/Auth/DeactivateController.php:60-79`).

Une décision écrite du propriétaire est obligatoire sur :

1. la distinction entre désactivation de fidélité et suppression de compte ;
2. la durée et le point de départ des 30 jours ;
3. la conservation ou l’effacement des points et de l’historique ;
4. l’identité et la ré-authentification nécessaires ;
5. l’audit et la synchronisation multi-appareils.

Sans cette décision, l’application ne peut pas promettre une restauration sous
30 jours.

## 4. Verdict de secours et suite bornée

### PASS

- Correctif local du bouton de réactivation et de l’intercepteur visuel.
- Tests statiques UI/catalogue et safety-check réexécutés avec succès.
- Aucun delta observé sur pricing, `OrderStatus`, branche, dispatch ou zones
  gelées dans la phase auditée.

### NEEDS_FIX

- Découpler aussi l’opt-out de `LC.dev` et ajouter un test sans
  `dev-helpers`.
- Définir puis câbler la synchronisation serveur de la réactivation.
- Construire la cible iOS signable.
- Intégrer et activer Mollie dans le mobile après gate et tests dédiés.
- Ajouter le parcours mobile de suppression de compte et sa preuve E2E.

### ESCALATE

- Décision propriétaire obligatoire sur la politique 30 jours et le traitement
  des points/historique.
- Audit humain/Claude terminal puis GPT final obligatoires avant `CLOSED`.

**Conclusion :** Phase 15 est correcte pour son objectif local et sa
validation ciblée, mais l’audit de secours global reste **NEEDS_FIX**. Le
produit n’est pas démontré prêt pour soumission Apple.

`AUDIT_CHANNEL: fallback-read-only`  
`AUDIT_HUMAN_CLAUDE: NOT_RUN`  
`GPT_FINAL_AUDIT: NOT_RUN`
