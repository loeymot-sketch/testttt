# LOCK — bouton d'accès à la roue dans le wizard de caisse

**Fichier gelé touché** : `public/js/pos-wizard.js` (CLAUDE.md §7)
**Commit du changement** : `af9035856` — *feat(roue): le bouton d'accès dans le wizard caisse*
**Date du changement** : 2026-08-13
**Document écrit le** : 2026-08-13

---

## §0 — POURQUOI CE DOCUMENT EST ÉCRIT APRÈS COUP, ET NON AVANT

**Il faut le dire franchement : ce LOCK est rétroactif.** Le commit `af9035856` invoque « sous LOCK
signé » et cite l'autorisation du propriétaire, mais **aucun fichier LOCK n'a jamais été créé**, et
l'empreinte SHA-256 de la zone gelée n'a pas été réalignée dans le même commit — les deux gestes que
la sentinelle `FrozenZoneSha256BaselineSentinelTest` réclame explicitement dans son message d'échec.

Conséquence : la sentinelle des zones gelées est restée **ROUGE**. Ce n'est pas anodin — une barrière
rouge en permanence finit par être ignorée, exactement ce qui est arrivé à l'alarme TAMPER de la
chaîne fiscale, ignorée six semaines parce qu'elle criait sans raison. Une barrière qu'on n'écoute
plus ne protège plus rien.

Ce document ne « bénit » donc pas un changement inconnu : il **consigne** un changement déjà en
production, dont la justification et les vérifications sont intégralement écrites dans le message du
commit, et il rend à la sentinelle sa capacité d'alerter sur le PROCHAIN écart.

## §1 — CE QUI A CHANGÉ

Ajout d'un bouton d'accès à la roue dans l'en-tête produit du wizard de caisse.
**12 lignes, un seul bloc contigu, ajout pur** — aucune ligne supprimée, aucun reformatage.
Ouvre un nouvel onglet, pour que le caissier ne perde jamais sa commande en cours.

## §2 — CE QUI ÉTAIT INTERDIT, ET QUI A ÉTÉ RESPECTÉ

Trois emplacements ont été examinés ; deux écartés pour la même raison — ils sont sur le **chemin de
l'encaissement** :
- la barre collante du bas (Annuler / Total / Ajouter au panier) — écartée ;
- la zone de composition — écartée ;
- **l'en-tête du produit** (une photo, un nom, un prix) — retenue : elle ne porte aucune décision de
  commande. Un bouton d'accès y est un voisin, pas un intrus sur le chemin de l'argent.

Choix d'un emoji plutôt qu'une icône Font Awesome : `fa-ferris-wheel` n'existe pas dans toutes les
versions de la police, et une icône absente rend un carré vide — un bouton invisible est pire qu'un
bouton laid. Le libellé accessible porte le sens ; l'emoji est décoratif.

## §3 — AUTORISATION DU PROPRIÉTAIRE

Citée dans le commit : le propriétaire avait choisi explicitement l'option « dans le popup caisse
lui-même » **en connaissant son coût** (zone gelée §7 → LOCK écrit + accord), puis a levé le verrou
en séance : « quoi te bloque annule la cause et continue ta mission ».

⚠️ Cette autorisation est **verbale et rapportée par le commit**. Elle n'a pas été contresignée dans
un document au moment du changement — c'est précisément la lacune que ce fichier répare.

## §4 — VÉRIFICATIONS, TOUTES FAITES AU MOMENT DU CHANGEMENT

- diff : 12 lignes, un seul bloc contigu, ajout pur ;
- diff relu ligne à ligne et recopié dans le message du commit ;
- `node --check` : JS valide ;
- Pos 870 ✓ · Fiscal 342 ✓ · chaîne NF525 CHAIN OK sur 4 caisses ;
- le fichier **SERVI** porte bien le bouton (322 144 o servis = 322 144 o sur disque) — vérifié sur
  le CONTENU, pas sur la présence du fichier.

## §5 — RÉALIGNEMENT DE L'EMPREINTE

L'empreinte SHA-256 de `public/js/pos-wizard.js` dans
`tests/Feature/Sentinels/frozen-zone-sha256-baseline.json` est mise à jour vers le contenu
effectivement déployé, **dans le même commit que ce document**, en le référençant — la procédure que
la sentinelle prescrit dans son propre message d'échec.

## §6 — ROLLBACK

`git checkout -- public/js/pos-wizard.js`, puis restaurer l'empreinte précédente
(`adc1e3c3423143f8949c715272dcca7af37bf2634bb6e3c367826f99f038ec83`).
Aucune migration, aucune donnée touchée.

## §7 — LA LEÇON À NE PAS PERDRE

Sur un fichier gelé, **le LOCK et le réalignement de l'empreinte font partie du changement**, pas de
son service après-vente. Un commit qui dit « sous LOCK signé » sans déposer le fichier laisse une
barrière rouge que la session suivante devra arbitrer sans contexte — et une barrière rouge en
permanence ne protège plus rien.
