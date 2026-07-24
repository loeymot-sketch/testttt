# ACCÈS CUISINE (KDS) + MOBILE ADMIN (/m) — findings (lens humain cuisinier + patron)

READ-ONLY. Chaque finding vérifié (file:line + grep/Read). Sévérité P0-P3.

## CUISINE (KDS)

### F1 — Son « nouvelle commande » bloqué par autoplay au boot (P2 · sync/UX)
`KitchenDisplaySystemComponent.vue:2003` `el.play().catch(() => {})` — SEUL point audio,
AUCUN unlock geste (grep touchstart/pointerdown/AudioContext.resume = 0 hit). Repro : une
tablette KDS dédiée qui **auto-charge /kds au démarrage ou après reload** (cas réel cuisine)
n'a jamais reçu de geste utilisateur → Chrome/Safari REJETTENT `HTMLMediaElement.play()` →
le `.catch` avale l'échec en silence → **le carillon de la 1re commande ne sonne pas**. Le
cuisinier a les mains dans la nourriture, dos à l'écran : le son EST l'alerte primaire. Débloqué
seulement au 1er bump (geste), donc fenêtre = juste après chaque reload/reboot. Reco : amorcer
l'audio au 1er `pointerdown`/`click` (play muted puis pause), OU fallback `navigator.vibrate` /
Notification, OU bandeau « 🔇 touchez pour activer le son » tant que non débloqué.

### F2 — Ticket 100% symbolique sans légende à l'écran (P3 · amélioration)
`KdsOrderLine.vue:29-35` (type `symbolic-main`) + `KitchenTicketSymbolicFormatter.php:150`
rendent « G | SANDWICH | P | STO | SAM » (codes 3 lettres, tacos sans taille — design owner
T3-CUISINE/MEGA-BORNE, LÉGITIME). Mais AUCUNE légende/clé n'existe à l'écran. Un cuisinier
neuf ne décode pas STO/SAM/O̲ (oignons cuits) sans mémoriser les tables. Reco (onboarding, non
bloquant) : petit panneau « légende » repliable OU toggle noms complets. Ne PAS retirer les codes.

Points FORTS vérifiés (à ne pas régresser) : 86 depuis la cuisine FONCTIONNE (bouton 🚫 gate
`canToggleAvailability` L1329 + rôle **Chef** a bien `availability_toggle`,
`AvailabilityTogglePermissionSeeder.php:39`) et propage via `AvailabilityService::toggle` ;
extras/variations 86-ables (`AvailabilityTogglePanel.vue:299`) ; alerte 86 in-flight sur carte +
aria-live (L2634-2658) ; chime ID-diff anti « longueur stable » (L1569-1578) ; recall 60s NF525-safe
(service L411) ; programmées bandeau + timer ancré release (`KdsOrderCard.vue:319`) ; 409
optimistic-lock + release-guard « visible == bumpable » ; N+1 batché ; allergènes splittés.

## MOBILE ADMIN (/m, PIN 2580)

### F3 — /m ne 86 QUE des produits entiers, pas sauces/viandes/variations (P2 · amélioration, fort impact)
`MobileStockController.php:36-140` : `catalog()` ne renvoie que des `items` ; `toggle()` n'accepte
qu'`item_id`. Le patron sur son tél NE PEUT PAS marquer « plus de sauce Andalouse » ni « plus de
merguez » — alors que la caisse/cuisine LE PEUVENT (AvailabilityTogglePanel extras+variations).
Asymétrie : la rupture terrain la plus fréquente (un ingrédient, pas un produit) est justement
celle qu'il ne peut pas gérer à distance. Reco : exposer extras/variations comme le panel admin.

### F4 — /m = seul accès mobile, binaire, sans quantités/recherche/gestion (P3 · amélioration)
`mobile-stock.blade.php` : PIN + « À acheter » + toggle EN STOCK/RUPTURE. AUCUNE recherche,
AUCUNE catégorie repliable (45 items OK, scroll long si +), AUCUN stock théorique/quantité (le
backend a pourtant StockLevel/StockMovement — non exposé), AUCUN accès compta/CA/gestion. C'est
le seul écran mobile → « vraie gestion mobile » (souhait owner futur) manque tout sauf le 86 produit.

### F5 — Toggle sans confirmation/undo + session 12h glissante (P3 · UX/sécu)
`mobile-stock.blade.php:264` : tap RUPTURE → 86 INSTANTANÉ propagé borne/caisse/web, sans confirm
ni undo (ré-appui = réversible, atténue). Session `session_minutes=720` (~12h) glissante,
re-PIN jamais redemandé pour une mutation ; PIN 4 chiffres exposé Internet. Solide par ailleurs :
fail-closed PIN vide (AuthController:27), `hash_equals`, `session()->regenerate()`, throttle 5/min,
boutons ≥44px, safe-area. Reco : re-PIN pour muter OU session plus courte ; confirm sur 86.

Cohérence SSOT : /m lit ItemBranchAvailability + délègue à `AvailabilityService::toggle` (même
chemin qu'admin/caisse) → stock identique cross-surface. VÉRIFIÉ, aucun chemin d'écriture parallèle.
