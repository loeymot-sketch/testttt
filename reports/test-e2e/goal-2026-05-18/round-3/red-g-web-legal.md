# RED-Visual G — Web Legal Pages Validator
**Round** : GOAL 2026-05-18 Round 3 — ADVERSARIAL RED + Visual Gate
**Source-of-truth** : `round-1/agent-9-web.md` (W.7.1 P1 BLOCKER LCEN) + `round-2/impl-g-web-legal-evidence.md`
**Target** : `/Users/1millnonstop/Downloads/web/legal/` (standalone, outside FoodKing repo — no git)
**Posture** : READ-ONLY, no code change, anti-fiction strict on legal coverage
**Captures** : 5 pages × 2 viewports = **10/10 GREEN** (HTTP 200, 0 console error, 0 page error)

---

## 1. Legal sections coverage table (per page ↔ French law)

### 1.1 `mentions.html` — LCEN art. 6 III
| Required section (LCEN) | Page block | State |
|---|---|---|
| Raison sociale | §1 Éditeur — "Raison sociale" | placeholder OK |
| Forme juridique | §1 — "Forme juridique" | placeholder OK |
| Capital social | §1 — "Capital social" | placeholder OK |
| Siège social (adresse) | §1 — "14 rue de la République, 62210 Hénin-Beaumont" | **CONCRETE** |
| SIREN | §1 — "SIREN: [À COMPLÉTER]" | placeholder OK |
| SIRET | §1 — "SIRET: [À COMPLÉTER]" | placeholder OK |
| RCS | §1 — "RCS: [À COMPLÉTER]" | placeholder OK |
| TVA intra | §1 — "N° TVA intracommunautaire" | placeholder OK |
| Code APE/NAF | §1 — "Code APE / NAF" | placeholder OK |
| Directeur de la publication | §1 — "Directeur de la publication" | placeholder OK |
| Contact (tel + email) | §1 — "06 51 30 XX XX / contact@lecayenne.fr" | partial concrete |
| Hébergeur | §2 — Nom/Adresse/Téléphone/Site | placeholders OK |
| Propriété intellectuelle | §3 — CPI L111-1 + L335-2 cited | **CONCRETE** |
| Données perso → privacy link | §4 — cross-link `privacy.html` | **GREEN** |
| Cookies → cookies link | §5 — cross-link `cookies.html` | **GREEN** |
| Limitation responsabilité | §6 | **CONCRETE** |
| Droit applicable + juridiction | §7 — droit français + tribunaux FR | **CONCRETE** |
| Contact dédié | §8 — email + courrier | **CONCRETE** |

**RED question** : any LCEN-required block missing? → **NO**. 8 sections cover all 13 LCEN art. 6 III items via concrete text + 13 owner-fillable placeholders. **GREEN** pending owner completion.

### 1.2 `cgv.html` — Code conso L221-5 + L221-28
| Required (L221-5 + ePrivacy) | Page block | State |
|---|---|---|
| Identification du vendeur | Art. 1 → mentions.html | **GREEN** cross-ref |
| Champ d'application (objet) | Art. 2 | **CONCRETE** |
| Caractéristiques essentielles produits | Art. 3 — prix TTC, allergènes, photos non-contractuelles | **CONCRETE** |
| Processus de commande | Art. 4 — 5 étapes énumérées + acceptation CGV | **CONCRETE** |
| Prix TTC / TVA | Art. 5 — euros TTC, click&collect, pickup-only | **CONCRETE** |
| Modalités de paiement | Art. 6 — CB / Apple-Google Pay / Espèces / TR | **CONCRETE** (prestataire placeholder) |
| Retrait / livraison | Art. 7 — comptoir 11h-00h, 30min tolérance | **CONCRETE** |
| **Droit de rétractation L221-18 + L221-28 4°** | Art. 8 — yellow callout EXCLUSION restauration | **CONCRETE CRITICAL** ✅ |
| Annulation / modification | Art. 9 | **CONCRETE** |
| Réclamations + garanties | Art. 10 — délai 24h + réponse 14j ouvrés | **CONCRETE** |
| Médiation consommation L611-1 | Art. 11 + plateforme EU ODR | **CONCRETE** (médiateur placeholder) |
| Pepper Club / fidélité | Art. 12 — barème + paliers | **CONCRETE** |
| Données perso → privacy | Art. 13 — cross-link | **GREEN** |
| Propriété intellectuelle → mentions | Art. 14 | **GREEN** |
| Force majeure | Art. 15 | **CONCRETE** |
| Droit applicable + juridiction R631-3 | Art. 16 — droit FR + R631-3 conso | **CONCRETE** |
| Acceptation + modification CGV | Art. 17 | **CONCRETE** |

**RED question** : any L221-5 / L221-18 block missing? → **NO**. Critical L221-28 4° exclusion-rétractation pour restauration explicitly cited (Art. 8 yellow callout). Médiateur conso requis L612-1 listé en placeholder. **GREEN** pending médiateur agréé + prestataire paiement.

### 1.3 `privacy.html` — RGPD + Loi I&L 78-17
| Required (RGPD art. 13/14) | Page block | State |
|---|---|---|
| Responsable de traitement (identité + adresse) | §1 | placeholder identité, **CONCRETE** adresse + contact RGPD |
| DPO contact | §1 — DPO ligne dédiée | placeholder OK (avec note seuils) |
| Données collectées + origines | §2 — 8-row table (identification/contact/auth/commande/paiement/fidélité/technique/préférences) | **CONCRETE** |
| Finalités + bases légales (RGPD art. 6) | §3 — 9-row table (contrat / obligation légale / consentement / intérêt légitime) | **CONCRETE** |
| Durées de conservation | §3 — colonne dédiée (5y commerce, 10y compta, 6y NF525, 13mois CNIL cookies, etc.) | **CONCRETE** |
| Destinataires + sous-traitants | §4 — hébergeur, paiement, e-mail, SMS, POS/KDS | partial (5 placeholders pour vendors) |
| Transferts hors UE (CCT art. 44-49) | §5 — encadrement + état actuel | placeholder OK |
| Sécurité (mesures techniques) | §6 — TLS, hash bcrypt/argon2, OTP, PCI-DSS, RBAC, NF525 HMAC, sauvegardes | **CONCRETE** |
| **Droits utilisateurs (accès/rectification/suppression/portabilité/opposition/limitation)** | §7 — 9 bullets dont art. 15 / 16 / 17 / 18 / 20 / 21 + retrait consentement + post-mortem + CNIL | **CONCRETE COMPLETE** ✅ |
| Modalités exercice droits | §7 callout — rgpd@lecayenne.fr + courrier + 1 mois | **CONCRETE** |
| Cookies → cross-ref | §8 | **GREEN** |
| Mineurs (art. 8 RGPD) | §9 — seuil 15 ans + autorité parentale | **CONCRETE** |
| **CNIL contact** | §10 — 3 place de Fontenoy + 01 53 73 22 22 + cnil.fr | **CONCRETE** ✅ |
| Modification politique | §11 | **CONCRETE** |

**RED question** : any RGPD art. 13 block missing? → **NO**. All 6 user rights present + base légale per finalité + DPO mention + CNIL contact. **GREEN** pending owner-identité + sous-traitant vendors (legitimate post-launch fill).

### 1.4 `cookies.html` — CNIL délibération 2020-091 + art. 82 loi I&L
| Required (CNIL) | Page block | State |
|---|---|---|
| Définition cookie/traceur | §1 — incluant pixels, localStorage, fingerprinting | **CONCRETE** |
| **Consentement libre, éclairé, révocable** | §2 callout + bandeau accept/refuse/paramétrer | **CONCRETE** (NB: actual cookie banner widget = V1 owner backlog) |
| Refus aussi simple qu'accepter | §2 — explicit | **CONCRETE** ✅ |
| Cookies strictement nécessaires (exempts) | §3.1 — 3-row table (lc_session / XSRF-TOKEN / lc_consent) | **CONCRETE** |
| Cookies mesure audience (consentement) | §3.2 — placeholder Matomo | placeholder + note no-tracker-actuel |
| Cookies fonctionnels | §3.3 — lc_lang / lc_favorites | **CONCRETE** |
| Cookies publicitaires / réseaux sociaux | §3.4 — callout "n'utilise pas" | **CONCRETE** ✅ |
| Cookies tiers techniques (CDN) | §4 — Google Fonts / unpkg.com | **CONCRETE** (transparent IP transfer) |
| Durée conservation 13 mois | §5 — CNIL 13mois + 25mois data | **CONCRETE** |
| Paramétrage via navigateur | §6 — Chrome/Firefox/Safari/Edge + CNIL link | **CONCRETE** |
| Réclamation CNIL | §7 — cnil.fr | **CONCRETE** |

**RED question** : any CNIL 2020-091 block missing? → **NO**. **GREEN** with the operational caveat that the actual consent-banner widget is a V1 owner-backlog implementation (banner declared in §2, mécanique pas codée — pages legal text ≠ banner widget; not RED-G scope).

### 1.5 `allergens.html` — INCO UE 1169/2011
| Required (annexe II INCO) | Page block | State |
|---|---|---|
| 14 allergènes obligatoires | §1 grid 14 items | **CONCRETE COMPLETE** ✅ |
| Céréales contenant du gluten (+ liste) | item #1 + blé/seigle/orge/avoine/épeautre/kamut | **CONCRETE** |
| Crustacés | item #2 | **CONCRETE** |
| Œufs | item #3 | **CONCRETE** |
| Poissons | item #4 | **CONCRETE** |
| Arachides | item #5 | **CONCRETE** |
| Soja | item #6 | **CONCRETE** |
| Lait + lactose | item #7 | **CONCRETE** |
| Fruits à coque (8 listés) | item #8 — amande/noisette/noix/cajou/pécan/Brésil/pistache/macadamia | **CONCRETE** |
| Céleri | item #9 | **CONCRETE** |
| Moutarde | item #10 | **CONCRETE** |
| Graines de sésame | item #11 | **CONCRETE** |
| Anhydride sulfureux & sulfites (>10mg/kg SO₂) | item #12 — seuil cité | **CONCRETE** |
| Lupin | item #13 | **CONCRETE** |
| Mollusques | item #14 | **CONCRETE** |
| Allergènes fréquents Le Cayenne | §2 — 9-row table contextualisé | **CONCRETE** |
| Risque contamination croisée | §3 callout | **CONCRETE** |
| Comment connaître allergènes par plat | §4 — 4 voies (modale / wizard / récap / staff) | **CONCRETE** |
| Régimes spéciaux (végé / sans gluten / halal) | §5 | partial — halal placeholder owner |
| Contact allergènes | §6 | **CONCRETE** |
| Cadre réglementaire (INCO + décret 2015-447 + L412-1 + 178/2002) | §7 | **CONCRETE** ✅ |

**RED question** : any annexe II INCO allergène manquant ? → **NO**. 14/14 listés textuellement avec exemples. **GREEN**.

---

## 2. Footer wireup verification (`components.jsx:97-145`)

| Check | Result |
|---|---|
| 4th column "Légal" present (line 125-134) | **GREEN** |
| 5 links present (mentions / cgv / privacy / cookies / allergens) | **GREEN** — lines 128-132 |
| Each `<li><a href="legal/X.html">` | **GREEN** (5/5 anchor format from index.html root) |
| Footer end copyright + 3 quick-links (CGV / CONFIDENTIALITÉ / MENTIONS LÉGALES) | **GREEN** — line 137 |
| Cross-page footer (mentions/cgv/privacy/cookies/allergens) also wired | **GREEN** — embedded `<footer class="lc-footer">` (note: 5 links use `mentions.html / cgv.html / ...` relative form, **valid from /legal/** but **inconsistent** with React WebFooter which uses `legal/mentions.html`) — see RED §3.1 |
| Grid class `lc-footer-grid--4col` for new 4-col layout | **GREEN** (line 102) |

**RED question** : do links resolve correctly?

- From `index.html` (web root) → `legal/mentions.html` ✓ resolves to `/legal/mentions.html` ✓
- From `legal/cgv.html` → `mentions.html` ✓ resolves to `/legal/mentions.html` ✓ (same dir)
- From `legal/cgv.html` → `../index.html` for nav return ✓ resolves to web root ✓
- Footer Navigation column on legal pages uses `../index.html` for all 4 nav items (Accueil/Menu/Fidélité/L'enseigne) — all collapse to root, since legal HTML is static and doesn't host the SPA hash routing. Acceptable for V1 static-legal-pages; **P3 P-Web-Legal-1** = nav buttons all land on home not on `home/menu/loyalty/about` SPA route (would need `?route=menu` query or hash fragment). Cosmetic, not LCEN-blocking.

**Verdict footer wireup** : **GREEN** with 1 P3 cosmetic note.

---

## 3. Brand consistency verification

### 3.1 CSS suite imports per page
Verified per page (all 5 HTML files lines 9-18) :
```
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Anton&family=Bebas+Neue&family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../styles.css">
<link rel="stylesheet" href="../styles-v2.css">
<link rel="stylesheet" href="../styles-v3.css">
<link rel="stylesheet" href="../styles-v4.css">
<link rel="stylesheet" href="../styles-v5.css">
<link rel="stylesheet" href="../styles-mobile.css">
<link rel="stylesheet" href="legal.css">
```
- **All 5 pages**: identical CSS suite (6 styles*.css + legal.css) ✅
- **All 5 pages**: Google Fonts preconnect + 4 families (Anton, Bebas Neue, Inter, JetBrains Mono) ✅
- `lc-nav` / `lc-footer` / `lc-container` / `lc-legal-*` classes consistent ✅
- legal.css reuses `var(--cream)`, `var(--ink)`, `var(--orange)`, `var(--font-display)`, `var(--font-mono)` from styles.css :root tokens ✅

### 3.2 RED disputes
- **No raw label leaks** (no `Label.X`, no `kiosk.foo`, no `0undefined`, no `i18n.fallback`) in any screenshot — verified visually ✅
- **No broken images** : pages contain zero `<img>` references (text-only legal pages) — no 404 risk ✅
- **No console errors** : all 10 captures `errors: []` (verified via capture-results.json) ✅
- **Branding intact** desktop + mobile : header "LC Le Cayenne" + "← Retour au site" + black footer with 4 columns (Brand / Navigation / Légal × 5 / End) ✅

**RED conflict resolution** : footer wireup uses `<button>` in React `WebFooter` but `<a>` in static HTML pages. legal.css line 217-227 adds `lc-footer-col li a` override styling matching button look. Verified visually — no visual regression. ✅

---

## 4. Owner placeholders inventory

Grep `[À COMPLÉTER]` count per page :
| Page | Count | Owner items |
|---|---|---|
| mentions.html | 13 | raison sociale, forme juridique, capital, SIREN, SIRET, RCS, TVA, APE/NAF, directeur publication, hébergeur (nom+adresse+tel+site) |
| privacy.html | 7 | identité responsable, DPO, sous-traitants (hébergeur, paiement, e-mail, SMS), transferts hors EEE |
| cgv.html | 4 | prestataire paiement, médiateur (nom + adresse + site) |
| cookies.html | 4 | cookie audience cookie/finalité/durée/émetteur (1 placeholder row) |
| allergens.html | 1 | certification halal |
| **TOTAL** | **29** | **All categorized as legitimate owner-input items** |

**Owner gate (post-GOAL)** :
- **P0 owner items (cannot ship publicly without)** : SIREN/SIRET/RCS (mentions §1), Médiateur conso agréé (cgv §11)
- **P1 owner items (within 30 days of go-live)** : DPO contact ou justif "non requis" (privacy §1), prestataire paiement nominatif (cgv §6 + privacy §4), hébergeur nominatif (mentions §2 + privacy §4)
- **P2 owner items** : capital social si SAS/SARL (mentions §1), halal certif (allergens §5), Matomo cookie list (cookies §3.2)

**Anti-fiction discipline confirmed** : no fictional SIREN/SIRET/RCS/numéro TVA invented (which would constitute a legal forgery risk). Placeholders explicitly bracketed `[À COMPLÉTER PAR PROPRIÉTAIRE — …]` make ownership unambiguous.

---

## 5. Visual inventory (10 screenshots)

Directory : `/tmp/foodking-goal-round3/red-g-web-legal/`

| # | File | Page | Viewport | Size | HTTP | Errors |
|---|---|---|---|---|---|---|
| 1 | `desktop-mentions.png` | mentions.html | 1920×1080 | 415 KB | 200 | 0 |
| 2 | `desktop-cgv.png` | cgv.html | 1920×1080 | 823 KB | 200 | 0 |
| 3 | `desktop-privacy.png` | privacy.html | 1920×1080 | 810 KB | 200 | 0 |
| 4 | `desktop-cookies.png` | cookies.html | 1920×1080 | 567 KB | 200 | 0 |
| 5 | `desktop-allergens.png` | allergens.html | 1920×1080 | 536 KB | 200 | 0 |
| 6 | `mobile-mentions.png` | mentions.html | 375×667 | 325 KB | 200 | 0 |
| 7 | `mobile-cgv.png` | cgv.html | 375×667 | 644 KB | 200 | 0 |
| 8 | `mobile-privacy.png` | privacy.html | 375×667 | 659 KB | 200 | 0 |
| 9 | `mobile-cookies.png` | cookies.html | 375×667 | 437 KB | 200 | 0 |
| 10 | `mobile-allergens.png` | allergens.html | 375×667 | 442 KB | 200 | 0 |

Plus `capture-results.json` (10-entry array, all 200 + 0 errors + correct H1 + correct title + 9 footer links per page).

---

## 6. Visual analysis per page

| Page | Desktop | Mobile | Verdict |
|---|---|---|---|
| **mentions.html** | LC-CAYENNE header / "MENTIONS LÉGALES" Bebas Neue H1 / 8 sections numbered / breadcrumb / placeholders inline-visible / footer 4-col with Légal column / black-yellow brand | Single column stack, nav-burger collapsed, "Retour au site" button visible, full content readable | **GREEN** |
| **cgv.html** | 17 articles ART.1-17 numbered Bebas Neue, yellow callouts on Art. 5 (pickup-only) + Art. 8 (L221-28 rétractation EXCLUSION), médiateur §11 placeholders | Single column, articles stack vertically, callouts preserved | **GREEN** |
| **privacy.html** | 11 sections, 2 wide tables (8-row données / 9-row finalités+base+durée), yellow callout exercice-droits, CNIL coords | Tables horizontal-scroll via legal.css media-query 600px, all content accessible | **GREEN** |
| **cookies.html** | 7 sections + 4 cookie tables (strictement / audience / fonctionnels / publicitaires-callout) + browser-settings list + CNIL link | Tables responsive, lc_session/XSRF/lc_consent rows visible | **GREEN** |
| **allergens.html** | 14-item grid (4 cols desktop) annexe II INCO, yellow callouts (info-disponibilité + contamination croisée), 9-row "fréquents Le Cayenne" table, 4 sources d'info + cadre réglementaire | Grid degrades to single column, all 14 allergens stacked, "Halal" placeholder owner-clear | **GREEN** |

**Cross-page visual consistency** :
- Same header `lc-nav` ✅
- Same footer `lc-footer` 4-column ✅
- Same breadcrumb pattern ✅
- Same H1/H2 Bebas Neue uppercase ✅
- Same yellow `lc-legal-callout` highlight ✅
- Same `lc-legal-related` "Voir aussi" navigation box at end ✅
- All footer "// Légal" columns list 5 same links ✅

**Visual RED disputes** :
- ❌ Found 0 (zero) raw label leaks
- ❌ Found 0 (zero) broken cells / collapsed grids
- ❌ Found 0 (zero) JS console errors
- ❌ Found 0 (zero) navigation dead-ends (every page links back to `../index.html` + sister legal pages)
- ⚠️ 1 P3 cosmetic : `../index.html` on legal-page nav links lands on web home (no hash fragment) — does not affect legal compliance, would benefit from `?route=menu` fragment if owner wants deep-link return. **NOT blocking**.

---

## 7. VERDICT

**GREEN — legal launch-ready pending placeholder fills**

### Justifications
1. **5/5 pages exist on disk** with correct legal coverage per French law :
   - mentions.html → LCEN art. 6 III ✅
   - cgv.html → Code conso L221-5 + L221-28 4° (critical rétractation-exclusion restauration) ✅
   - privacy.html → RGPD complet (responsable, bases légales, finalités, durées, droits, DPO, CNIL) ✅
   - cookies.html → CNIL délibération 2020-091 (consentement, exempts, audience, fonctionnels, pub) ✅
   - allergens.html → INCO 1169/2011 annexe II (14/14 allergènes listés) ✅
2. **Footer wireup live** in `components.jsx:97-145` — 5 links + 3 quick-links + copyright. Cross-resolution verified.
3. **Brand consistency 5/5** — identical CSS suite, fonts, header/footer, breadcrumb, H1 typography.
4. **0 anti-fiction violations** — 29 placeholders explicitly bracketed, no invented SIREN/SIRET/médiateur agréé.
5. **10/10 visual captures GREEN** — 0 console error, 0 broken image, 0 raw label, layout intact desktop + mobile.
6. **W.7.1 LCEN P1 BLOCKER from Round 1 → CLOSED** (was the only public-launch blocker).

### Owner gate (NOT RED-G scope, but required pre-public-launch)
- **P0 BEFORE DNS flip** : SIREN/SIRET/RCS (mentions), médiateur conso agréé (cgv §11)
- **P1 WITHIN 30 DAYS** : DPO contact ou non-requis-justif (privacy), prestataire paiement nominatif (cgv §6 + privacy §4), hébergeur nominatif (mentions §2 + privacy §4)
- **P2 BACKLOG** : capital social si applicable, halal certif, Matomo cookie list, cookie-banner widget actual implementation (not legal-text)
- **P3 COSMETIC** : nav-return hash fragments on legal pages

### Sentinel for regression
Recommandation : add Playwright spec `test-e2e-web-legal-2026-05-18.spec.js` (Round 4 backlog, NOT RED-G) :
- 5 pages × 4 viewports = 20 cells
- assert HTTP 200 + H1 present + footer.legal column count = 5 + 0 console error
- baseline screenshots → `/legal/__screenshots__/`

---

**End RED-Visual G. P0=0 / P1=0 / P2=0 / P3=1 (cosmetic). Standalone discipline preserved (`/Users/1millnonstop/Downloads/web/` outside FoodKing repo, no commit, no FoodKing wireup). LCEN/L221-5/RGPD/CNIL-cookies/INCO compliance achieved at content level — final activation requires owner to fill 29 placeholders.**
