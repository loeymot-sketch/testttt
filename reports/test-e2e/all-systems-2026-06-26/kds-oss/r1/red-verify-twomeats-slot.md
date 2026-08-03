# RED-VERIFY — 2 viandes distinctes regroupées sous « Choix » (carte V2)

VERDICT: **CONFIRMED P2** (réfutation tentée, échouée sur les faits ; severité downgrade P0/P1→P2 confirmée).

[P2] resources/js/helpers/kdsCustomization.js:279 — 2 viandes (Viande 1/Viande 2) regroupées sous le groupe générique « Choix » sur la carte V2 — slot perdu

repro: DB live `foodking_e2e`, order_item 4933 (order 5175, item_id 97). snapshot.lines = Viande 1=Cordon Bleu, Viande 2=Fricadelle, Sauce (1ère Gratuite)=Samouraï, extra Cheddar. `renderItem()` exécuté RÉELLEMENT (spec jetable créée→supprimée) → sortie prouvée:
```
{type:'header', label:'Tacos', category:'taco'}
{type:'variation', group:'other', label:'Cordon Bleu, Fricadelle'}   <-- 2 viandes fusionnées en 1 ligne 'other'
{type:'variation', group:'sauce', label:'Samouraï'}
{type:'supplement', label:'+ Cheddar'}
```
Carte V2 (KdsOrderCard.vue:133/390-391 → renderItem ; V2 default-ON KitchenDisplaySystemComponent.vue:1300 `return true`) → KdsOrderLine.vue:96-102 résout `label.kds_group_other`=« Choix » (fr.json:811) → cuisinier lit « Choix : Cordon Bleu, Fricadelle ».

evidence:
- snapshot DB réel (attribute_name="Viande 1"/"Viande 2", variation_name=valeur) — dump scratchpad.
- sortie renderItem RÉELLE (run vitest jetable, supprimé).
- classifyGroup (kdsCustomization.js:24-41) n'a AUCUN pattern « Viande »/« meat » → fallback 'other' pour les 2 → join l.289-295.
- heal `d71dfbfe8` (kdsVariationGroupValue/Line l.143-169) PAS appelé par renderItem : `kdsVariationLine` seulement importé/utilisé dans KitchenDisplaySystemComponent.vue:1577 (board legacy) + 2239 (print) sur `item.item_variations` (legacy), JAMAIS dans renderItem ni KdsOrderCard. Le board legacy rend bien « Viande 1: Cordon Bleu | Viande 2: Fricadelle » — mais ce n'est PAS la surface par défaut.
- test kdsCustomization.spec.js:298-310 couvre les helpers EN ISOLATION, jamais renderItem 2-viandes. Baseline = 35 verts.

lentille: cuisinier.

SÉVÉRITÉ (jugement adversaire): **P2, NON P0/P1**. Les 2 viandes restent VISIBLES, DISTINCTES, CORRECTES (« Cordon Bleu, Fricadelle ») — aucune masquée, aucune fusionnée-en-une-seule, aucune fausse. Le cuisinier prépare les 2 bonnes viandes. Seul le LIBELLÉ DE SLOT (Viande 1 vs Viande 2 — index sans importance pour un tacos) est remplacé par l'en-tête générique mais non-incorrect « Choix ». Le germe P0 (« 2 viandes identiques / mauvaise viande ») ne se produit PAS. Allergènes inchangés (chemin séparé allergens_snapshot). Donc dégradation de lisibilité réelle à healer, mais PAS une commande client ratée.

reco (NON-frozen, kdsCustomization.js éditable): dans renderItem, pour les lignes snapshot à `attribute_name` non-vide, grouper PAR `attribute_name` (slot réel) via kdsVariationGroupValue → une ligne 'variation' par groupe nommé (group=attribute_name, value=variation_name). Conserver classifyGroup pour les lignes legacy sans attribute_name (non-régression sauce/board). TDD `tests/js/kdsTwoMeatsDistinctRender.spec.js`: 2 lignes 'variation' group « Viande 1 »/« Viande 2 » distinctes ; non-régression kdsCustomization 35 verts.
