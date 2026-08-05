# Cycle 8 — vérifications COMPORTEMENTALES faites à la main
**Date** : 2026-08-05 · Web `4c7262a` · Backend `0c8adb238`

L'agent du cycle 8 s'est interrompu (limite d'usage hebdomadaire, réinitialisation 16h). J'ai donc
exercé moi-même les points les plus graves de sa liste. **Chaque résultat ci-dessous est une
exécution réelle**, pas une lecture de code — c'est précisément la distinction que le procureur
de convergence exigeait, après avoir démontré qu'un correctif intégralement annulé laissait la
sentinelle à 33/33 verts.

---

## ✅ F-D — vente carte web hors chaîne fiscale · VÉRIFIÉ, ET LA GARDE PEUT ROUGIR

`tests/Feature/Frontend/WebCardOrderPaidPathReleasesAllOrderTypesTest.php` **appelle**
`finalizePaidKioskOrder` sur une vraie commande carte web payée, pour les trois types.

| État du code | Résultat |
|---|---|
| restriction `order_type` réintroduite | **ROUGE** sur le type 20 — « Failed asserting that false is true » : la commande reste PENDING, donc payée, jamais en cuisine, hors chaîne fiscale NF525 |
| correctif en place | **3/3 verts** |

C'est la première vérification de cette campagne qui contrôle ce comportement **sans recopier la
règle qu'elle prétend vérifier**.

## ✅ F-I — prolifération de jetons · VÉRIFIÉ EN BASE

Trois inscriptions successives avec le même téléphone, via l'API réelle :

```
inscription #1 -> HTTP 201
inscription #2 -> HTTP 201
inscription #3 -> HTTP 201
RESULTAT users=1 jetons_vivants=1 CONFORME(1)
```

Avant le correctif : jusqu'à **13 jetons vivants** pour un seul utilisateur, chacun valable
30 jours, dont la déconnexion n'en tuait qu'un.

## ✅ F-F — sauce fantôme · VÉRIFIÉ EN BOUT-EN-BOUT

Vrai jeton, vrai `api.placeOrder`, backend local :

```json
{
  "fantome": { "resultat": "REFUSÉ", "kind": "resolve",
               "message": "La sauce « Poivre » n'est plus disponible pour « Cayenne ». Choisis-en une autre et réessaie." },
  "normale": { "resultat": "COMMANDE PASSÉE" }
}
```

Et le `composition_snapshot` **scellé** de la commande qui passe porte bien la sauce CHOISIE :

```
"attribute_name": "Sauce (1ère Gratuite)", "variation_name": "Mayonnaise"
```

Avant le correctif, choisir « Poivre » scellait « Mayonnaise » **sans le dire**, à prix
identique — donc sans qu'aucune garde monétaire puisse le détecter.

## ✅ F-B / F-C — carte jamais payée · VÉRIFIÉ AU DOM

Commande carte réelle #6259 (`payment_status=10`, `status=1`, `fiscal_sequence_no=NULL`),
retour `?order=` avec paiement 3DS en cours, écran observé jusqu'à épuisement de la sonde :

```
t+3s   → titre="PAIEMENT NON FINALISÉ" QR=0 ticket=0 confettis=0 badgeVert=0 total=0 url=""
t+15s  → titre="PAIEMENT NON FINALISÉ" QR=0 ticket=0 confettis=0 badgeVert=0 total=0 url=""
```

Aucun habillage de succès. Avant : confettis, QR scannable et TOTAL sous « C'EST PARTI ! »,
pendant que le suivi affichait « paiement non finalisé ».

## ✅ F-A (partiel) — `?order=`

L'URL est **nettoyée** (`url=""` ci-dessus) et le drapeau synchrone empêche l'effet voisin de
consommer le paiement en cours. Les deux vérifications **comportementales** de la sentinelle
couvrent l'autre moitié (panier et pending préservés sur un `?order=` étranger), et il est
prouvé qu'elles rougissent quand le correctif est annulé.

## ✅ F-H — ticket cuisine · format vérifié contre le VRAI formateur

```
note du client en tête      -> ["Nuggets · Viandes en plus : Cordon Bleu"]   (la note volait la ligne)
parties structurées en tête -> ["Cordon Bleu"]                               (la viande PAYÉE gagne)
```

---

## ❌ RESTE DÛ (non exercé)

- **F-E** — garde R1 livraison bloquée sur les DEUX routes + reap par le janitor.
- **F-G** — commande livraison → les 2 listeners d'impression (octets ESC/POS).
- **Cycle complet** — aucun cycle adversarial n'a pu tourner sur ces correctifs. Le critère de
  convergence (deux cycles consécutifs à 0 P0/P1) reste **non atteint**, et il ne peut pas
  l'être sans agents.

## Avertissement à reconduire

~21 des 35 assertions de la sentinelle restent des expressions régulières sur le texte source.
Elle est restée **35/35 verte avec six P1 ouverts**. Les seules preuves opposables de cette
campagne sont celles listées ci-dessus, plus les deux vérifications comportementales de la
sentinelle — toutes accompagnées de la démonstration qu'elles peuvent rougir.
