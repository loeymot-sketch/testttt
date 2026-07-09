# KDS vide sur la borne — diagnostic SANS DB (UI only) + checklist config

> Le cowork re-cite `kds_station="none"` : **c'est un MYTHE (3ᵉ fois)** — la table `orders` n'a AUCUNE
> colonne `kds_station` (une requête `WHERE kds_station` échoue « Unknown column »). Le KDS filtre par
> `status ∈ {4,7,8}` ET `payment_status ∈ {5=PAID,15=PENDING_COUNTER}` ET la branche du chef. **Ne plus
> perdre de temps là-dessus.** Le CODE est correct (prouvé : 29 commandes borne visibles au KDS en local).

## Diagnostic en 30 s, SANS PowerShell ni DB (juste l'interface admin)
Après avoir passé une commande borne, ouvre la caisse **`/admin/encaissement`** (page « À encaisser ») :

| Ce que tu vois | Cause | Fix |
|---|---|---|
| La commande borne apparaît dans « **À encaisser** » | Elle est **PENDING_COUNTER (correct)** → elle DOIT être aussi sur le KDS. Si le KDS reste vide → le chef regarde une **autre branche** OU le KDS n'a pas rafraîchi (F5 / worker). | Chef sur branche 1 ; recharger le KDS ; worker `--queue=high,default` actif. |
| La commande **N'apparaît PAS** dans « À encaisser » | Elle est **UNPAID (pas routée en caisse)** → invisible KDS. La borne n'a pas envoyé `payment_method=CASH_ON_DELIVERY`. | `.env` VPS : `KIOSK_PAYMENT_ROUTE_ALL_TO_COUNTER=true` + `config:cache` + **rebuild** + reboot borne. |
| Aucune commande n'est créée (le « retour à l'idle » était un timeout, pas un succès) | La borne n'était pas authentifiée (auto-login machine échoué). | Voir checklist config ci-dessous (payload + secret). |

## Checklist CONFIG `.env` VPS (indispensable pour que la borne route vers le KDS)
```env
# Auto-login borne (SANS ces 3, la borne ne s'authentifie pas → commande pas kiosk → pas sur KDS)
KIOSK_MACHINE_USERNAME=<username de la KioskMachine active>
KIOSK_MACHINE_PASSWORD=<password de la KioskMachine active>
KIOSK_AUTO_LOGIN_SECRET=lcb-227b5373163391c875eeb43f7ee1affe3972   # == le ?machine_key= de l'URL borne
# Routage paiement → PENDING_COUNTER → visible KDS + file d'encaissement
KIOSK_PAYMENT_ROUTE_ALL_TO_COUNTER=true
POS_WALKIN_ROUTE_TO_COUNTER=false   # ⚠️ CORRIGÉ 02-07 : FALSE — la caisse paie INLINE (déjà encaissée). true forçait TOUTE commande caisse dans « à encaisser » (bug owner). Seule la BORNE va en à-encaisser (via KIOSK_PAYMENT_ROUTE_ALL_TO_COUNTER).
KIOSK_DEFAULT_LOCALE=fr
```
Puis : `php artisan config:clear && php artisan config:cache`.
> ⚠️ Le `?machine_key=lcb-227b...` de l'URL borne DOIT être **exactement égal** à `KIOSK_AUTO_LOGIN_SECRET`
> (comparaison timing-safe, `KioskAutoLoginGate`). S'il ne matche pas → pas d'identifiants injectés → pas
> d'auto-login → la commande n'est pas une commande kiosk → JAMAIS sur le KDS.

## Ordre de vérification (le plus probable en premier)
1. `git rev-parse --short HEAD` sur le VPS == `61e9ea7b7` (ou +). Sinon → **déployer** (les bugs terrain = ancien code).
2. `.env` : les 5 clés ci-dessus présentes + `config:cache` + **rebuild** (`npm run production`) + **reboot borne**.
3. Worker : `php artisan queue:work --queue=high,default` (sinon sync en polling, KDS lent).
4. Passer une commande borne → vérifier « À encaisser » (tableau ci-dessus) → puis le KDS.

## Impression (ticket physique)
- Le ticket doit être le **ESC/POS propre** du pont (`127.0.0.1:9100`). S'il sort **illisible/HTML** =
  le pont était injoignable → Chrome est retombé sur `--kiosk-printing` (window.print). Vérifier
  `http://127.0.0.1:9100/health` = UP + que le node bridge tourne (VBS startup).

## Restant PHYSIQUE (sur place seulement)
- Ticket Sanei SK1-31 réellement imprimé (client + cuisine) et lisible.
- La commande borne visible en caisse « À encaisser » puis encaissable.
