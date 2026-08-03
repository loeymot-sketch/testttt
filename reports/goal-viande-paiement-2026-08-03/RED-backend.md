# RED-team — fix « viande supplémentaire nommée au ticket cuisine » (f4c0538db)

Mission read-only. Cibles : `public/js/pos-wizard.js` buildTicketInstruction, `app/Services/Hardware/KitchenTicketSymbolicFormatter.php` (NB : le fichier est dans `Hardware/`, pas `Pos/`), `resources/js/helpers/kdsCustomization.js`, fixture `tests/fixtures/parity_php.json`.

---

## [P1] app/Services/Hardware/KitchenTicketSymbolicFormatter.php:604 + resources/js/helpers/kdsCustomization.js:223 — le nouveau compoRe AVALE la note client (dont ALLERGIES) quand elle partage la ligne avec « Viandes/Sauces en plus : »

**Le problème.** `cleanInstruction()` / `sanitizeKdsInstruction()` droppent la **LIGNE ENTIÈRE** dès que le regex matche **n'importe où** dans la ligne (`(^|\s)…`, pas d'ancre). Or la borne/web n'écrit **qu'UNE SEULE ligne** : `KioskWizardComponent.vue:2328-2332` fait `parts.join('. ')` puis `[joined, "Note: <note client>"].join('. ')` → la note manuelle du client vit **sur la même ligne physique** que les marqueurs de compo. Le regex étendu (`Viandes?(\s+en\s+plus)?|Sauces?\s+en\s+plus`) matche désormais des lignes que l'ancien (`Viandes?\s*:|Sauce\s*:` — jamais « Viandes en plus : » ni « Sauces en plus : », le mot est suivi d'un espace, pas d'un `:`) laissait passer → ces lignes, **note client comprise**, disparaissent du ticket cuisine imprimé, du KDS (`KitchenDisplaySystemComponent.vue:1948`), du reçu web caisse (`ReceiptComponent.vue:581`) et de la note du ticket client (`OrderReceiptEscPosRenderer.php:336, 544`).

**Preuve 1 — la fixture régénérée l'ENCODE** (`git show f4c0538db -- tests/fixtures/parity_php.json`) : 7 rows réelles (corpus régénéré depuis la DB réelle, cf. entête `tests/js/kitchenParityRealData.spec.js:12`) flippent `note` → `""` :

- row ~2224/2693 (Tacos L) : `instr = "ALLERGIE ARACHIDE — sans cacahuète. Viandes en plus : Tenders, Nuggets. Sauces en plus : Algérienne"` — `note` passait de la chaîne complète (allergie VISIBLE en cuisine) à `""` (allergie INVISIBLE).
- row ~1057 : `"Sauces en plus : Ketchup | ZZ-TEST bien cuit"` → note `""` (la note « bien cuit » perdue).
- rows ~2048/2139/2517/2608 : idem, pertes sans note co-localisée (celles-là sont le comportement voulu).

**Preuve 2 — reproduction exécutée** (php -r, formatter réel) :

```
$i = "ALLERGIE ARACHIDE — sans cacahuète. Viandes en plus : Tenders, Nuggets. Sauces en plus : Algérienne";
cleanInstruction($i, "Tacos L")  => ""            // NOUVEAU : tout perdu
preg_match(ANCIEN_compoRe, $i)   => false         // AVANT : la ligne était GARDÉE (note visible)

$i3 = "Sauces en plus : Ketchup. Note: allergie gluten";
cleanInstruction($i3, "Sandwich Cayenne") => ""   // NOUVEAU : allergie gluten perdue
preg_match(ANCIEN_compoRe, $i3)  => false         // AVANT : gardée
```

**Périmètre honnête.** (a) Pour un item borne AVEC viande de base, la ligne contient déjà `Viandes : {list}` (i18n `resources/js/languages/fr.json:1995`) que l'ANCIEN regex matchait déjà → pour cette classe la perte de note était **pré-existante** (architecture « drop la ligne entière » sur payload mono-ligne), pas une régression de ce commit. (b) La classe **NOUVELLE** = lignes dont le seul marqueur est « Sauces en plus : » / « Viandes en plus : » (ex. sandwich 2 sauces sans supplément viande + note allergie) — prouvée par le flip des rows 1057/2139/2224. (c) Le POS n'est PAS touché : ses notes sont bracketées `[...]` sur ligne séparée (pos-wizard.js:3948) et la règle bracket passe AVANT compoRe → prouvé : `cleanInstruction("TACOS\n…\nViandes en plus : Tenders\n[allergie arachide]") => "[allergie arachide]"`. Uber non touché (note toujours bracketée, `UberOrderMapper.php:99-107`, testé `UberOrderMapperNoteTest`).

**Fix suggéré.** Ne pas dropper la ligne : **retirer le segment matché** (`preg_replace` du motif `…en plus\s*:\s*[^.|\n]+[.|]?`) et garder le reste de la ligne ; ou splitter la ligne borne sur `'. '` avant filtrage. Parité PHP↔JS + régénération fixture requises.

---

## [P2] KitchenTicketSymbolicFormatter.php:346-347 + splitViandeList:389 — nom de viande contenant `.` ou `,` = capture tronquée / éclatée (latent, aucun cas au catalogue)

`extraViandeNames` capture `[^\n.]+` : un nom avec un point (« St. Hubert ») serait tronqué au `.` ; `splitViandeList` splitte sur `,` : un nom avec virgule serait éclaté en 2 « viandes ». Le `.` est par ailleurs NÉCESSAIRE (délimiteur de phrase du payload borne `'. '` — vérifié : « …Viandes en plus : Tenders, Nuggets. Sauces… » capture exactement `Tenders, Nuggets`). Catalogue actuel (Poulet mariné, Kefta, Merguez, Tenders, Nuggets, Fricadelle, Cordon Bleu, Viande Hachée, Escalope…) : **aucun** nom avec `.`/`,` → latent seulement. « Cordon Bleu » OK. « 2× Nuggets » OK (pas de virgule interne, testé : `extraDisplayName("Viande supplémentaire", …) => "Viande supplémentaire : Tenders, Nuggets"`).

---

## [REFUTED] Doublon de la ligne sur une surface

Vérifié consommateur par consommateur — **aucun doublon** :
- Ticket cuisine imprimé (`OrderReceiptEscPosRenderer.php:336+346`) : `supplementLines` émet UNE ligne « + Viande supplémentaire : X » (nommée via `extraDisplayName:369-373`) ; la ligne « Viandes en plus : » ET le blob « Viandes : … » de la ligne 1 sont droppés par compoRe (bloc :651-653, après les règles bracket/`^[+↳]`).
- KDS board (`KitchenDisplaySystemComponent.vue:1948` → `sanitizeKdsInstruction`, drop `kdsCustomization.js:306`) : idem, une seule ligne nommée.
- Ticket CLIENT (`OrderReceiptEscPosRenderer.php:448-449` extras nommés + `:544` note nettoyée) : une seule occurrence, prix scellé inchangé.
- Reçu web caisse (`ReceiptComponent.vue:547` nommage + `:581` sanitize) : idem.

## [REFUTED] Double émission par les deux builders du pos-wizard

`buildWizardInstruction()` (:2471, ligne « Viandes en plus » :2508) n'alimente QUE l'HTML du récap (`:2427` → `'Instruction KDS: ' + …` affiché à l'écran, jamais persisté). Seul `buildTicketInstruction()` remplit la textarea soumise (`:4339-4346`). Aucun chemin où les deux sorties se concatènent. À l'intérieur de `buildTicketInstruction`, l'émission est exclusive (branche if :3745-3768 / else-if :3770-3788) et unique.

## [REFUTED] Branche « else if » sans viande principale (:3770-3788)

`viandeSupplTicketNames` est déclarée AVANT le if (:3725) donc visible dans le else — pas de ReferenceError. Format émis identique (« Viandes en plus : » + `N× Nom`). Cas réel rare (suppl. sans viande de base, ex. restore edge `:5028-5045` où totalViandes est recomputé), mais défensif et conforme au parseur.

## [REFUTED] Uber Eats

`UberOrderMapper::safeNote()` (:99-107) brackete toute note non vide → la règle bracket de `cleanInstruction` (qui passe AVANT compoRe) la préserve, même si elle contient « sauce : » ou « viande en plus : ». Les extras Uber portent leurs vrais noms (jamais le générique « Viande supplémentaire ») → `extraDisplayName` ne les réécrit pas. `UberOrderMapperNoteTest` couvre le chemin.

## [REFUTED] Parité PHP↔JS cassée

Exécuté : `vitest run kitchenParityRealData.spec.js posWizardViandeSupplementUnified.spec.js` → **12/12 verts** ; `php artisan test --filter=KitchenSymbol` → **5/5 verts**. Le diff fixture ne touche QUE 7 champs `note` (aucun `php`/`supps`/`menu`). MAIS : c'est précisément ce diff qui encode le P1 ci-dessus (« un test qui passe peut encoder un bug »). Note : les `supps` de la fixture sont générés SANS instruction (`tools/audit/gen-parity-fixture.php:33` — `supplementLines($snap)` sans 2ᵉ arg), donc la fixture ne teste pas le chemin « nommage » lui-même (hors périmètre parité, couvert par les specs dédiées).

## [REFUTED] Régression du ticket CLIENT

Avant le fix, une commande CAISSE affichait le générique « + Viande supplémentaire ×N » sur le reçu client (la ligne dédiée n'existait pas dans l'instruction soumise). Après : nommée (« : Tenders, Nuggets »), prix scellé inchangé — miroir exact du comportement sauce en place depuis 2026-07-18 et de la borne depuis 2026-07-24. Amélioration, pas régression.

---

# VERDICT GLOBAL : LE FIX N'EST PAS CONFIRMÉ EN L'ÉTAT — 1 vrai problème (P1)

Le cœur du fix (ligne dédiée POS + nommage) est correct, sans doublon, parité verte, POS/Uber sains. MAIS l'anti-doublon par **drop de ligne entière** régresse les payloads **mono-ligne borne/web** : toute note client co-localisée (dont « ALLERGIE ARACHIDE — sans cacahuète », row réelle de la fixture, précédemment affichée) devient invisible sur ticket cuisine, KDS et reçu client. Food-safety. Corriger en strippant le segment matché au lieu de dropper la ligne (ou splitter le payload borne sur `'. '` avant filtrage), puis régénérer la fixture.
