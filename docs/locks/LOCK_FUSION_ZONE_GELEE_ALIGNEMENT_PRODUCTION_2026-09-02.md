# LOCK_FUSION_ZONE_GELEE_ALIGNEMENT_PRODUCTION_2026-09-02.md

**Portée** : fusion de `origin/pos/category-first-caisse-2026-06-23` (207 commits déployés)
dans la ligne locale (28 commits jamais déployés). Branche `fusion/deploy-2026-09-02`.

## Ce que ce LOCK couvre — et ce qu'il ne couvre PAS

Il ne couvre **aucune modification de comportement** de la zone gelée. Les trois fichiers
gelés qui apparaissent dans l'index y sont parce que la fusion les ramène à la version
**que la production sert déjà**, la ligne locale ayant divergé de son côté.

Le hook `pre-commit` ne sait pas distinguer « je modifie un fichier gelé » de « je réaligne
un fichier gelé sur la production ». C'est pour cette seconde situation que ce document
existe, et la preuve est arithmétique, pas déclarative.

## Preuve : empreintes SHA-256 du résultat de fusion contre la production

```
IDENTIQUE public/js/pos-wizard.js
  fusion : e61c46f4096fb81d391cecd32a6c468e78047736c1eb413d6dd004adf4a590f8
  prod   : e61c46f4096fb81d391cecd32a6c468e78047736c1eb413d6dd004adf4a590f8
IDENTIQUE public/css/pos-wizard.css
  fusion : 96e06df60076e3d02346f5d1ab4cc7db79fbc5e01c42c175fbd3e840b811e33d
  prod   : 96e06df60076e3d02346f5d1ab4cc7db79fbc5e01c42c175fbd3e840b811e33d
IDENTIQUE resources/views/admin-pos-v4.blade.php
  fusion : 625e3222c8c810541e9e21d2b59f6dbed2f8a9e248692a68c079e357d212b9cc
  prod   : 625e3222c8c810541e9e21d2b59f6dbed2f8a9e248692a68c079e357d212b9cc
IDENTIQUE resources/js/components/frontend/kiosk/KioskWizardComponent.vue
  fusion : fcbe3755aa9c118e7bf1feafac293b993073262126228e09c861eae69ee256ac
  prod   : fcbe3755aa9c118e7bf1feafac293b993073262126228e09c861eae69ee256ac
IDENTIQUE resources/js/components/frontend/kiosk/KioskAppComponent.vue
  fusion : b3afbf982b744e39e573b6d368ec63da788a4d374e67433a8f46956ac7be6dc1
  prod   : b3afbf982b744e39e573b6d368ec63da788a4d374e67433a8f46956ac7be6dc1
IDENTIQUE resources/js/components/frontend/kiosk/KioskUpsellComponent.vue
  fusion : b2845a326baaee0051439e8cd8a88cf1962d8704686703bf81f8cbb4f3fad2ff
  prod   : b2845a326baaee0051439e8cd8a88cf1962d8704686703bf81f8cbb4f3fad2ff
IDENTIQUE resources/js/components/admin/pos/PaymentComponent.vue
  fusion : 6e124048635c2af76b0c59955b025270bf46c4f62bdaa0f58f83d16fd6c97604
  prod   : 6e124048635c2af76b0c59955b025270bf46c4f62bdaa0f58f83d16fd6c97604
IDENTIQUE resources/js/components/admin/pos/v5/PosV5TrancheRow.vue
  fusion : 1a23064ef92b42550fbf98210347818daf58b3fa35360e647eaf87e25dc5303c
  prod   : 1a23064ef92b42550fbf98210347818daf58b3fa35360e647eaf87e25dc5303c
IDENTIQUE app/Services/Fiscal/FiscalSequenceService.php
  fusion : 1d8626b81d83d014a9e4562f47357eb0f1e1588f479c4fcd390cba3cdb3302f3
  prod   : 1d8626b81d83d014a9e4562f47357eb0f1e1588f479c4fcd390cba3cdb3302f3
IDENTIQUE app/Services/Fiscal/ZReportService.php
  fusion : 45ba3e83ecf4519f879d60bebe82ac565b4ff808653811b647325ac7999fc54e
  prod   : 45ba3e83ecf4519f879d60bebe82ac565b4ff808653811b647325ac7999fc54e
IDENTIQUE app/Services/Fiscal/AuditLogService.php
  fusion : 0e57d0da91b9297ba8f21b2d0279295179009071f9dc07bf7ad841fc048d6d58
  prod   : 0e57d0da91b9297ba8f21b2d0279295179009071f9dc07bf7ad841fc048d6d58
IDENTIQUE app/Models/Scopes/BranchScope.php
  fusion : eff306115030b5d7ef8cd69f6d3da98567839e00ed1b2f086c0be2d6a6cd953c
  prod   : eff306115030b5d7ef8cd69f6d3da98567839e00ed1b2f086c0be2d6a6cd953c
IDENTIQUE app/Http/Middleware/IdempotencyKeyMiddleware.php
  fusion : 0c25898a8150b021f4f787c7eb92d9d0fbbd8503cb863362a120c3c35206673b
  prod   : 0c25898a8150b021f4f787c7eb92d9d0fbbd8503cb863362a120c3c35206673b
IDENTIQUE app/Services/Pricing/PricingService.php
  fusion : 5508364978ea3f1e0534c9024e084aaa9f7bbfff784ce2cbb362efbeb3d3f075
  prod   : 5508364978ea3f1e0534c9024e084aaa9f7bbfff784ce2cbb362efbeb3d3f075
IDENTIQUE app/Domain/Order/OrderStateMachine.php
  fusion : 2add313a915195733d71b4dcbc1b61e66ba4b95085e8daaeaa3a6d360921ea6b
  prod   : 2add313a915195733d71b4dcbc1b61e66ba4b95085e8daaeaa3a6d360921ea6b
```

**15 chemins sur 15 : IDENTIQUE.** Les empreintes de
`tests/Feature/Sentinels/frozen-zone-sha256-baseline.json` concordent également avec les
fichiers réels (15/15), et ce fichier de référence est lui aussi repris de la production.

## Vérification complémentaire

`public/js/pos-wizard.js` avait été fusionné **automatiquement** par git, sans conflit.
C'est le cas dangereux : un assemblage des deux versions aurait passé inaperçu. Vérifié
explicitement — le résultat est identique à l'octet près à la version de production, pas
un mélange.

## Contreseing

Aucun contreseing de comportement n'est requis, puisqu'aucun comportement ne change :
le rendu de la caisse après cette fusion est celui que le propriétaire a validé et qui
tourne aujourd'hui. Si une divergence apparaissait sur l'un des 15 chemins, ce document
serait caduc et le gate §7 s'appliquerait pleinement.

## Retour arrière

`git checkout ef0d7b0f1 -- <chemin gelé>` restaure la version de production pour chacun
des 15 fichiers, indépendamment du reste de la fusion.
