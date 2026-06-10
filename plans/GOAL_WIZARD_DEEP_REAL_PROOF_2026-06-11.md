# GOAL — Wizard builder : preuve RÉELLE bout-en-bout (siège gérant) + frozen autorisé + Vagues en boucle (2026-06-11)

> Owner GO : « prends ma place comme gérant, modifie un wizard / produit / choix, ça DOIT être accessible et faisable ; les listes enregistrées doivent être RÉELLES ; pas juste "oui c'est fait" — va au bout, prouve avec captures. » Frozen wizard AUTORISÉ (raisonnement max avant toute ligne + dispute adversaire). Après chaque vague : preuve visuelle+technique → audit/ultra-review complet → améliorer en boucle jusqu'à excellence, pour tous les systèmes.

## PHASE A — TEST RÉEL DU BUILDER (siège gérant, NON-frozen) — on commence ICI
Prouver chaque opération LIVE (:8768/foodking_e2e), capture analysée à chaque étape :
- A1. **Ouvrir** un produit → composer. La page charge l'état réel (pages existantes).
- A2. **Listes enregistrées RÉELLES** : « D'où viennent les choix ? » → Attribut produit / Groupe d'extras / Addon → le sélecteur « Limiter à un groupe » montre les VRAIS attributs/groupes/addons de l'item (pas vide, pas faux). Sélectionner un attribut réel → l'aperçu live montre les VRAIS choix.
- A3. **Ajouter une page** (addStep) → apparait dans PAGES + éditable + aperçu.
- A4. **Modifier une page** : label, min/max, visible_on, source_type, active → persiste (saveDraft) + dirty-guard.
- A5. **Réordonner** (reorder) les pages → ordre persiste.
- A6. **Page personnalisée** (créer/éditer) : ajouter des choix (addPersonalOption), supprimer un choix (removePersonalOption) → liste réelle éditable.
- A7. **Supprimer une page** (confirmRemoveStep) → confirmation → retirée.
- A8. **Template** (applyTemplate) : applique un gabarit de wizard réel.
- A9. **Publier** (publish) → diff valeur-par-valeur (price-free) → version bump → aperçu Caisse+Borne reflète.
- A10. **Supprimer le wizard** (destroyProfile, unpublished only, 409 si publié).
- A11. **Le rendu CÔTÉ CLIENT** : la borne (et caisse si flag) rend réellement ce wizard composé → parcours client complet.
Verdict A : est-ce VRAIMENT la meilleure méthode (accessible/faisable) ou faut-il améliorer ? (déterminé par preuve, pas par foi)

## PHASE B — DISPUTE ADVERSAIRE sur A
Agent(s) adversaire : attaque l'accessibilité/faisabilité réelle, les listes (vides ? fausses ? non-persistées ?), les cas limites (page sans choix, attribut renommé, publish concurrent, suppression page publiée), a11y, NF525 (0 prix sur étape). file:line / capture. Heal loop → 2 cycles identiques + adversaire épuisé.

## PHASE C — FROZEN WIZARD (T-COMPO-1/2) — autorisé, raisonnement max
- Raisonnement AVANT toute ligne : le renderer generic_choices existe déjà (branche cms-gestion-spine, additif +335/-1, NF525-clean, 25 specs, flag FALSE). Décision : intégrer via LOCK (owner a donné le GO explicite ici) OU re-dériver proprement. Dispute adversaire dédiée. Flag reste FALSE (flip prod = G-5 séparé).
- Preuve : caisse rend le wizard composé (flag ON e2e) ; flag OFF = legacy bit-identique. Captures.

## PHASE D — VAGUES 1-3 en boucle
Pour chaque vague : exécuter → test visuel (capture analysée) + technique (PHPUnit/Vitest/frozen 0) → **audit/ultra-review complet** → si gaps : améliorer → re-boucle → vague suivante. Tous les systèmes (POS, KDS, OSS, admin, apps) jusqu'à excellence.

## INVARIANTS
Frozen = gate (sauf wizard ici autorisé via raisonnement+LOCK+dispute) ; NF525 0-prix-sur-étape ; FR ; palette ; SSOT ; mutations :8768 seulement ; ne retourner QUE quand tout validé AVEC PREUVES.
