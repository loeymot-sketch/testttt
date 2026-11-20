# Task — Mobile image convergence — 2026-10-16

## Objective

Align the Le Cayenne mobile app's sandwich, taco and galette imagery with the latest
canonical assets already served by the website and kiosk-facing catalogue. The mobile
app must stop referencing the obsolete `signature/*` and `tacos.png` variants for these
products.

## Source of truth

- Runtime mapping: `config/menu_images.php` live product mappings.
- Canonical binaries: `public/images/menu/` (`sandwich-*.png`, `tacos-cayenne.png`,
  `galette.png`, and the three category images).

## In scope

- `mobile/data/menu.js` image and hero mappings for sandwichs, tacos and galettes.
- Exact binary synchronization from `public/images/menu/` into the mobile asset pack.
- Static regression coverage and one targeted mobile E2E assertion for the canonical
  product image paths.
- Phase plan, execution report and cycle state.

## Out of scope

- Prices, catalogue names, option data, order payloads, payment, authentication/SMS,
  loyalty, backend PHP, database/migrations, native iOS packaging/signing, and frozen
  POS/caisse/kiosk implementation files.

## Acceptance criteria

1. Cayenne, Suprême, Méga and Terminator use the current public `sandwich-*.png`
   binaries for both `image` and `hero`.
2. Tacos M/L/XL use `tacos-cayenne.png`; both galette products use the current
   `galette.png` binary.
3. The mobile category cards for Sandwichs, Galette and Tacos use the same current
   public category binaries.
4. Non-visual catalogue metadata and order line payloads remain unchanged.
5. Targeted static checks and the catalog visual E2E pass.

## Governance

- No Graphiti MCP is loaded in this session; use repository memory and current code as
  the available context.
- No pricing, branch isolation, OrderStatus, dispatch or OrderService symmetry change
  is expected.
