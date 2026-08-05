# HANDOFF S4 → S7 — défauts vitrine trouvés pendant l'audit V1 (2026-07-29)

> Trouvés par la session S4 pendant l'audit visuel des 50 surfaces.
> Home / présentation / images / pages légales = voie S7 (DISCIPLINE §10) : **non touchés**.
> Preuves : `reports/goal-s4-web/V1-shots/`.

## P1 — Page 404 brute, en ANGLAIS, non stylée
- `desktop-24-404-serveur.png` / `mobile-24` : « Error response / Error code: 404 /
  Message: File not found. / Error code explanation: 404 - Nothing matches the given URI. »
- Times New Roman, aucun branding, aucun lien de retour, sur un site FR live.
- ⚠️ **À CONFIRMER SUR VERCEL AVANT DE CORRIGER** : ma capture vient du serveur
  `python3 -m http.server` local. Le comportement réel dépend de `vercel.json`
  (rewrites). Vérifier `curl -s -o /dev/null -w "%{http_code}" https://www.lecayenne.fr/page-inexistante`
  et ce qui est servi, avant de conclure à un vrai défaut.

## P2 — Zones vides marquées (rythme vertical)
- `desktop-01-home-haut.png` : la carte jaune « // NOTRE SIGNATURE » a ~165 px de jaune
  vide en haut et ~150 px en bas ; la pastille « 10 pts par euro dépensé » flotte seule.
- `desktop-04-fiche-produit.png` : ~270 px de blanc mort dans la colonne droite entre
  « Infos allergènes » et le prix.
- `desktop-13-orders` / `desktop-14-loyalty` : ~250 px de vide entre le CTA et le footer.

## P2 — Pages légales
- `desktop-20-legal-cgv.png` : le titre occupe toute la largeur (1383 px) alors que le
  corps est limité à ~860 px — déséquilibre net. Idem colonne droite vide sur toutes les
  pages légales.
- `mobile-23-legal-allergens.png` : artefacts de rendu au-dessus du titre
  « 1. LES 14 ALLERGÈNES MAJEURS (… INCO) » ; carte « Fruits à coque » qui déborde ;
  sous-libellés en petites capitales grises à la limite de la lisibilité.

## Non-défauts confirmés (ne pas « corriger »)
Aucun label brut (`undefined`, `NaN`, clé i18n) sur les 50 surfaces. Interface en français
partout sauf la 404 serveur. Palette Cayenne respectée, y compris pages légales.
