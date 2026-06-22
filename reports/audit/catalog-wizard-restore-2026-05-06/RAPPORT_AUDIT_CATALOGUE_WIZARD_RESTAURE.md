# Audit catalogue + wizard restaure - 2026-05-06

VERDICT: PASS

## Assertions

```json
{
  "pos_bad_fixture_text": false,
  "pos_header_clean": true,
  "tacos_l_item_id": "364",
  "pos_wizard_bad_fixture_text": false,
  "pos_wizard_contains_official_tacos": true,
  "pos_wizard_contains_viande_sauce": true,
  "pos_images_loaded": true,
  "kiosk_bad_fixture_text": false,
  "kiosk_official_categories_visible": true
}
```

## Runtime errors

```json
[]
```

## Captures

- 01-pos-catalogue-restaure.png — POS charge avec catalogue restaure — bad_fixture=false
- 02-pos-nos-tacos.png — POS categorie Nos Tacos avec vrais produits — bad_fixture=false
- 03-pos-wizard-tacos-l-restaure.png — Wizard POS ouvert sur Tacos L officiel — bad_fixture=false
- 04-kiosk-accueil.png — Borne accueil apres nettoyage catalogue — bad_fixture=false
- 05-kiosk-categories-restaurees.png — Borne categories officielles restaurees — bad_fixture=false
