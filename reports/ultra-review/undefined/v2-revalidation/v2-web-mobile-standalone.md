# V2 Revalidation adversariale — WEB standalone + MOBILE RN (code-only)

Cible : `/Users/1millnonstop/Downloads/web/**` (React CDN) + `mobile/**` (RN).
Posture : réfuter « GREEN ». Aucune écriture, aucune modif projet. DB lecture seule (foodking_e2e).

## Attaques exécutées

### A1 — Produits inventés (correctness / anti-drift)
- `grep mkItem\( data/menu.js` web ET mobile → 31 produits identiques.
- `grep -inE "Box Familiale|Nashville|Solo|Family|Combo|Trio"` → 0 dans les DATA vivantes ;
  seuls hits = COMMENTAIRES « ANTI-FICTION HEAL » documentant la PURGE (mobile orders.js:15, loyalty.js:113).
- item_ids réellement utilisés (orders.js/loyalty.js) = {101,102,104,202,401,501,502,602,701,702,902,1001,1002}
  → TOUS membres canoniques de menu.js. Le commentaire cite 301/302/608 (stale) mais la data n'en contient AUCUN.
- **VERDICT : held-green. Aucun produit inventé.**

### A2 — Prix SSOT (correctness)
Comparaison directe menu.js (web) ↔ DB `items` (24 produits clés) :
Cayenne 7.40, Suprême 7.00, Méga 8.00, Terminator 9.00, Tacos M 6.90, Tacos L 7.90,
Bol Frites/Riz 7.90, Chicken 4.90, Cheese 6.00, Double Cheese 7.00, Fish 6.00, Big 9.00,
Grill 8.00, Galette Normale 6.50 / Cayenne 7.00, Petite Frites 2.50, Grande 4.00,
Coca 1.90, Eau 1.00, Capri-Sun 1.50, Menu Enfant N/B 4.90, Glace 3.50.
→ **24/24 MATCH exact avec la DB.** Mobile menu.js = mêmes prix. Suppléments 0.90, viande suppl 2.50 (EXTRA_MEAT_PRICE), sauce +0.50 au-delà de 1 → cohérent caisse.
- **VERDICT : held-green.**

### A3 — Palette mobile (mandat NOIR/ORANGE/JAUNE/BLANC, PAS Cayenne-red)
`mobile/styles.css` tokens : `--ink #0A0A0A`, `--orange #FF5A1F`, `--yellow #FFD93D`, `--paper #FFFFFF`.
Aucun `#F4501E` (brand kiosk) utilisé comme primaire. `--red #E5341A` réservé aux états d'erreur (standard).
- **VERDICT : held-green. Palette conforme.**

### A4 — API-wireup non autorisé (mandat « NO API V1 »)
- MOBILE : `grep fetch\(|axios|/api/` → 0 appel réseau réel ; toutes les réfs `/api/v1/...` sont des COMMENTAIRES « Phase 6 ». api/storage.js = localStorage only.
  → **Mobile RESTE standalone, conforme au mandat. held-green.**
- WEB : api.js + wireup RÉEL présents. C'est une DÉVIATION du mandat « NO API V1 » MAIS owner-autorisée
  explicitement (MEMORY project_web_api_wireup_caisse_2026-06-26 « override du mandat 0-API »). Non-finding.

### A5 — Secrets / clé API en clair (security)
- `web/api.js:21` embarque `X-API-Key` app-wide en dur. Comparaison octet-à-octet avec `.env MIX_API_KEY`
  → **MATCH exact (38 chars).** La clé de PROD réelle est shippée dans le bundle front web.
- Contexte : X-API-Key front est par nature semi-public (envoyée par chaque navigateur) ; garde-fou v1 la classe
  « déférée cloud-prep/by-design » ; V1 LOCAL mono-poste, API joignable loopback/local uniquement.
- **VERDICT : attesté mais NON re-signalé comme P0/P1** (respecte garde-fou déféré). Note cloud-prep ci-dessous.

### A6 — XSS / raw-label / i18n FR
- `grep dangerouslySetInnerHTML|innerHTML=` web → 0. Aucun `Label.X` / `kiosk.x` / `undefined€`.
- `<html lang="fr">` web ET mobile. Textes FR.
- **VERDICT : held-green.**

## Divergences mineures (non bloquantes, V1)
- Mobile cat 6 (bols) `wizard_template:'tacos'` alors que web utilise `'bol'` (gratiné/base riz corrects côté web).
  → mirror mobile non fini (connu MEMORY « mirror mobile non fait ») ; standalone non exécuté ici. **P3 latent.**
- Web `web/api.js` porte la vraie MIX_API_KEY (cf. A5) — item cloud-prep déféré, à rotationner + `<meta>`-only avant tout déploiement web public.

## Verdict global
**GREEN_HELD.** Aucune attaque n'a cassé la cible sur ses angles applicables (correctness data/prix,
palette, mandat standalone mobile, absence produits inventés, XSS/FR). 0 P0/P1 nouveau.
