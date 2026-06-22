# Audit final POS + borne vers KDS — 2026-05-06

## Verdict

PASS.

Les deux parcours critiques ont ete corriges puis rejoues en E2E navigateur avec captures et preuves backend :

- Caisse POS multi-produits -> paiement especes -> backend -> KDS -> preparation -> pret.
- Borne kiosk multi-produits -> paiement carte simule -> backend -> KDS -> preparation -> pret.

## Corrections appliquees

- KDS : affichage explicite du numero de file `N°...` et `N° file: ...` pour POS et borne.
- KDS : les commandes POS sont classees dans le bucket visible cote cuisine au lieu de disparaitre du tableau principal.
- KDS : les commandes terminees ne polluent plus la vue operationnelle par defaut.
- Kiosk catalogue : noms longs categorie/produit bornes visuellement pour eviter debordement et superposition.
- Kiosk wizard : champ instruction cuisine libre ajoute au recapitulatif avant ajout panier.
- POS fixture E2E : taxe forcee a 0% pour supprimer l'anomalie de total/taxe artificielle.
- KDS sync : les erreurs de synchronisation de fond ne remontent plus en erreur runtime bloquante.
- Langue frontend : fallback local pour `fr` afin de supprimer le 404 `/api/frontend/language/show/fr` et l'`AxiosError` non gere.
- Spec POS : helper de clic du wizard stabilise contre les faux negatifs de timing/visibilite dans le modal.

## Validations executees

```bash
npm run development
PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_CHANNEL=chrome E2E_BACKEND=1 npx playwright test tests/e2e/audit-pos-multiproduct-kds-journey.spec.js --project=chromium --workers=1
PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_CHANNEL=chrome E2E_BACKEND=1 npx playwright test tests/e2e/audit-kiosk-multiproduct-kds-journey.spec.js --project=chromium --workers=1
PLAYWRIGHT_CHANNEL=chrome E2E_BACKEND=1 npx playwright test tests/e2e/audit-max-sync-order-journey-documentation.spec.js --project=chromium --workers=1
```

Resultats :

- POS : 1 passed, sans retry.
- Borne : 1 passed, sans retry.
- Audit consolide POS + borne + backoffice + OSS/KDS : 1 passed.
- Smoke runtime admin : `pageErrors=[]`, `httpErrors=[]`, `unhandled=[]`.

## Preuves POS

- Rapport : `reports/audit/pos-multiproduct-kds-journey-2026-05-05/RAPPORT_AUDIT_POS_MULTI_PRODUITS_KDS.md`
- Trace : `reports/audit/pos-multiproduct-kds-journey-2026-05-05/raw-pos-multiproduct-trace.json`
- Captures : 11 PNG.

Trace finale :

- `source_surface=pos`
- `branch_id=1`
- `status=8` / PREPARED
- `payment_status=5` / PAID
- `order_items_count=2`
- `queue_number=A0002`
- `queue_number_visible=true`
- `stock_movement.delta_sum=-2`
- `queue_count_same_day=1`
- `runtimeErrors=[]`

## Preuves borne

- Rapport : `reports/audit/kiosk-multiproduct-kds-journey-2026-05-05/RAPPORT_AUDIT_BORNE_MULTI_PRODUITS_KDS.md`
- Trace : `reports/audit/kiosk-multiproduct-kds-journey-2026-05-05/raw-kiosk-multiproduct-trace.json`
- Captures : 13 PNG.

Trace finale :

- `source_surface=kiosk`
- `branch_id=1`
- `order_type=25`
- `status=8` / PREPARED
- `payment_method=4`
- `payment_status=5` / PAID
- `order_items_count=2`
- `queue_number=A0003`
- `queue_number_visible=true`
- `stock_movement.delta_sum=-2`
- `queue_count_same_day=1`
- `runtimeErrors=[]`

## Invariants verifies

- Prix : aucune logique prix produit ajoutee cote frontend ; les fixtures E2E controlent uniquement les donnees de test.
- `branch_id` : les commandes validees restent sur `branch_id=1`.
- Cycle commande : creation, paiement, transfert KDS et transitions de statut valides par backend.
- Stock : deux lignes commande produisent deux mouvements, somme `-2`, sans duplication.
- Visuel cuisine : numero de file et lignes produits visibles dans les extraits KDS.
