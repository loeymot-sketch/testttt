# Gate — Backend menu images borne/caisse — 2026-10-17

## Status

HUMAN_GATE_REQUIRED

## Scope

Synchroniser les visuels boissons du backend Le Cayenne avec le bundle web/mobile
déjà validé, pour les surfaces borne et caisse uniquement.

## Evidence of current defect

With the local API key and `GET /api/frontend/item?branch_id=1` on the backend
server, the current `Item::getThumbAttribute()` projection returns:

| slug | current backend thumb | required owner asset |
|---|---|---|
| `hawai` | `thumbs/fanta-fraise.webp` | `hawai.png` |
| `orangina` | `thumbs/tropico.webp` | `orangina.webp` |
| `fuze-tea` | `thumbs/lipton-framboise.webp` | `fuze-tea.png` |
| `perrier` | `thumbs/eau.webp` | `perrier.png` |

The required assets are not currently present in backend
`public/images/menu/`. The wrong mappings are in
`config/menu_images.php` and the returned thumbnail files are generated assets.

## Requested approval

Approve a bounded backend image cycle that may:

1. copy the four owner-approved assets from the Le Cayenne web/mobile source pack
   into backend `public/images/menu/`;
2. update only the four corresponding entries in `config/menu_images.php`;
3. regenerate the POS thumbnails for the three PNG assets using the repository
   image-thumbnails command; serve `orangina.webp` directly unless a separately
   approved WebP-thumbnail change is included (the current command intentionally
   scans PNG/JPEG sources only);
4. clear only the required Laravel/config caches;
5. verify the API, borne and caisse projections without creating or modifying an
   order.

No price, product name, slug, branch, order, payment, migration, or frontend
logic change is authorized by this gate.

## Acceptance evidence required

- `GET /api/frontend/item?branch_id=1` returns the four required asset filenames
  (or generated thumbnail equivalents for the PNG assets), not the legacy
  filenames.
- The four asset URLs return HTTP 200 and valid image content.
- Existing 15-drink catalog and prices remain unchanged.
- POS and kiosk menu screens show the four correct products visually.
- `branch_id` remains unchanged and no cross-branch data is read or written.
- `git diff --check` and the relevant image/catalog tests pass.

## Invariants

- Backend remains the pricing source of truth.
- No `OrderStatus` or order lifecycle changes.
- No branch isolation changes.
- No dispatch or database write is introduced.
- This gate does not authorize edits to frozen POS/kiosk components.

## Decision

Decision: `PENDING_HUMAN_GATE`
Approver:
Date:
Notes:
