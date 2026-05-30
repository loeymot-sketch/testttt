# ULTRAUDIT VISUEL — Images + Boutons + Affiches + Boîtes-produit + Interfaces (2026-05-30)

> Owner /goal : « images pas toutes alignées ; boutons / affiches / produits / boîtes-produit
> pas tous bien faits ; problèmes visuels + interfaces. Audit E2E abuse, capture analysée chaque
> écran, corrige TOUT. Audit TOUTES les pages, TOUTES les interfaces visuelles, TOUTES les images.
> Crée une task-list, attaque un par un, refresh E2E, valide, corrige, boucle jusqu'à fini. »

## SCOPE
- **Mobile** (`mobile/`, :8087) : splash, login, otp, home, menu, item-detail, wizard (toutes étapes),
  cart, stripe/pay, confirm, orders, order-detail, profile, loyalty.
- **Web** (`/Users/1millnonstop/Downloads/web/`, :8095) : home, menu (+catégories), item-detail,
  wizard (toutes étapes), cart, checkout, payment, account (login+register), loyalty, orders,
  order-detail, confirm, track, about, 5 pages légales.
- **Focus VISUEL** (au-delà du sujet-photo déjà validé) : alignement images (aspect-ratio, object-fit,
  crop, tailles incohérentes, débordement hors carte), qualité boutons (styles incohérents, overlap,
  taille, alignement), affiches/bannières/hero, cartes-produit + boîtes-produit (espacement, image
  dans la carte), états interface (overflow, vides, erreurs), responsive.

## SÉVÉRITÉ
- P0 : cassé (image 404/0px, layout brisé, crash, texte illisible). P1 : visible défaut (image
  déformée/mal-cadrée/débordante, bouton overlap/mal-aligné, carte cassée, overflow). P2 : polish
  (légère incohérence taille/espacement/contraste). P3 : cosmétique mineur.

## MÉTHODE (boucle owner)
ULTRAUDIT (agents visuels parallèles, capture+analyse chaque écran) → task-list findings →
attaque 1 par 1 (heal scope-minimal) → refresh E2E (re-capture) → valide visuel → re-audit →
converge (2 rounds 0 P0/P1). Anti-hallu : chaque finding = PNG + fichier:ligne + repro.

## TASK LIST (round 1 — inline brain findings + agents en cours)
| # | Surface | Page/élément | Problème | Sév | Statut |
|---|---------|--------------|----------|-----|--------|
| UV-01 | web+mobile | cartes produit (thumb) | `object-fit: cover` sur photos produit détourées (PNG transparent, food flottant) → **crop le haut du produit** (pain/food coupé). Correct = `contain` (food entier centré sur le dégradé). web `screens.jsx:41` + mobile `shared.jsx:107`. **Cause racine « images pas alignées ».** | P1 | 🔄 root-cause confirmée |
| UV-02 | web | home hero "SIGNATURE BOX" | SVG cartoon burger dessiné main (pas une vraie photo). Off-brand vs photos réelles ailleurs. | P2 | 🔵 owner-decision (art déco intentionnel) |
| (agents round-1 ajoutent leurs findings) | | | | | |

**NON-defects écartés (anti-hallu)** : home featured imgs natW=0 = lazy-load non déclenché (chargent à 800px au scroll) ; badge "+25 Points" flush bord (pas de débordement, viewport scalé).

Statut : ⏳ todo · 🔄 en cours · ✅ fixé+revérifié · 🔵 owner-decision · ⚪ backlog P3

## ROUND LOG
- R1 : audit visuel parallèle mobile+web (en cours)

## VERDICT (à la convergence)
(pending)
