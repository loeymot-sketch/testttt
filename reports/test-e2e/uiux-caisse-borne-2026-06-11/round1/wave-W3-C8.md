# W3-C8 — Borne : paiement + post-commande + erreurs (reconstitué depuis les logs disque)

> L'agent C8 a été coupé (limite) après sa campagne (97 tool-uses) mais avant son rapport.
> Reconstitué par l'orchestrateur depuis `shots-c8/_{flow1,crossflow,errors-edges}-log.txt`
> + 14 screenshots.

## Ce qui a été réellement testé (logs)
- **Flux A→Z ×2 réels** : commandes #4513 (A0002, 39 €, 21 lignes) et #4514 (A0003, 3,80 €)
  créées via POST 201. Plan B : payment → « PAIEMENT À LA CAISSE / Veuillez payer à la caisse »,
  0 méthode TPE visible (correct, Plan B assumé).
- **cash-instruction** : titre « Rendez-vous en caisse », numéro **#A0002 en 96px** (lisible),
  montant « 39,00 € » (formaté FR ici), décompte « Retour à l'accueil dans 42 s ».
- **Cross-flow PROUVÉ** : commande borne A0003 retrouvée dans /admin/encaissement
  (`14-crossflow-encaissement.png`, needle found=true).
- **4 pages d'erreur** : toutes FR, fond blanc light-mode, actions de sortie claires
  (Connexion perdue → RÉESSAYER/PRÉVENIR ; Menu indisponible ; Article indisponible ;
  Paiement refusé → RÉESSAYER/PAYER EN CAISSE/ANNULER).
- **Edges verts** : double-tap « Commander » → **1 seul POST** (idempotence UI) ;
  back depuis waiting → reste sur confirmation ; refresh confirmation → retour idle propre.

## Findings
- **[P1] C8-OFFLINE-01 — confirmation hors-ligne : erreur brute « Network Error » (EN), pas d'écran d'attente, pas de reprise au retour réseau.**
  reproduction: setOffline(true) sur /kiosk/payment → confirmer ; log : `queuedScreen=false title="ABSENT" error="Network Error" toasts=[]` ; retour online : `conflictCta=false` (aucune reprise).
  evidence: `09-offline-after-confirm.png`, `10-back-online.png`, `_errors-edges-log.txt`.
  recommendation: dans le handler d'échec POST order du composant paiement kiosk (non-frozen `KioskPaymentComponent.vue`), détecter l'erreur réseau → message FR (« Connexion perdue. Votre commande n'a pas été envoyée. ») + CTA réessayer ; idéalement router vers /kiosk/error/network avec retour au panier intact.
- **[P1→déjà tracé] 401 console récurrents en flux normal** — même root-cause que dim-console (rotation token kiosk destructive). Pas re-compté ici.
- **[P2→déjà tracé K1] « TOTAL À RÉGLER : €39,00 »** sur payment — symbole avant (helper kioskFormatPrice), cohérent avec C5/C6/C7. NB: cash-instruction affiche « 39,00 € » correct (autre chemin de formatage).

## ✅ Sains
Plan B clair, numéro géant lisible, reset auto, idempotence double-tap, refresh-safe,
cross-flow borne→caisse opérationnel, 4 pages d'erreur conformes.
