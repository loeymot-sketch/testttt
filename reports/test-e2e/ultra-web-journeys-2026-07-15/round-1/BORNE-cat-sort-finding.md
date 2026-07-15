# Onde B (BORNE) — P2 tri catégories non-déterministe (journey réel)

**Surfacé par** : parcours borne réel (/kiosk/idle → touch → categories). La borne a atterri sur une catégorie à sort=1 en doublon (tie avec « Sandwichs » sort=1) au lieu de la 1re attendue → « 0 produit ».

**Root cause (prouvé empiriquement)** : `KioskMenuService::projectCategories` (l.251) triait via `sortBy([fn1, fn2])` (forme tableau) — Laravel interprète fn2 comme une DIRECTION, pas un tie-breaker. Test : `[58(s1),1(s1),2(s2),4(s4)]` → `4,1,2,58` (faux) au lieu de `1,58,2,4`. C'est le MÊME bug corrigé pour les items sous Wave Y A-001 (l.299) mais laissé sur les catégories.

**Impact** : à sort égal entre deux catégories (créable par un gérant qui duplique un ordre), l'ordre des catégories borne est non-déterministe → présentation/atterrissage sur une catégorie arbitraire (potentiellement vide). Le catalogue Le Cayenne réel a des sorts distincts → non visible aujourd'hui, mais contrat rompu + latent.

**Fix** : sortBy chaîné (stable PHP 8) — id puis sort. `app/Services/Kiosk/KioskMenuService.php`. +2 tests réflexion. Frozen 0.

NB : symptôme « atterri sur cat 58 » confondu par une catégorie de test créée par un agent concurrent (RJ-Dcat) ; le BUG de tri est réel et prouvé indépendamment.
