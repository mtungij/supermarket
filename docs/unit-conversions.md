# Units & unit conversions

## Principles

1. Every product has exactly **one base (inventory) unit**. Balances,
   movements, reorder levels and costs are all stored in base units.
2. Any other unit is a **product-specific conversion**,
   `1 unit = factor × base unit`, stored in `product_units`. A
   *carton* is 24 pieces for product A and 12 pieces for product B. Nothing
   about packaging is assumed globally.
3. Documents (PO, GRN, adjustment and transfer lines) and stock movements
   store a **snapshot** of the unit, the entered quantity, the conversion
   factor and the resulting base quantity. Changing a product's factor later
   affects only future documents. History is never recalculated.

## Unit definitions

Units are company-owned (`units`). Defaults are provisioned per company:

| Code | Name | Dimension | Ratio to reference |
|---|---|---|---|
| pc, pack, ctn, box, btl, can, bag | Piece, Pack, Carton, Box, Bottle, Can, Bag | count | (per product) |
| kg, g | Kilogram, Gram | mass | 1, 0.001 (reference kg) |
| l, ml | Litre, Millilitre | volume | 1, 0.001 (reference l) |
| m | Metre | length | 1 (reference m) |

Companies can add their own units, such as Dozen, Tray or a mass unit with
its own ratio (`units.manage`).

## Conversion rules (`UnitConversionService::resolveFactor`)

| Base unit | Added unit | Rule |
|---|---|---|
| any | same unit | factor = 1 |
| measurement | measurement, **same** dimension | factor = ratio(unit) ÷ ratio(base), derived automatically (1 kg = 1000 g). A different value is rejected |
| measurement | measurement, **different** dimension | rejected (kg ↔ l) |
| count | measurement | rejected (a piece cannot be measured in kg) |
| count | count/packaging | product-specific factor required: a **whole number**, ≠ 1 |
| measurement | count/packaging | product-specific factor required, e.g. 1 Bag = 25 kg |

Factors must be > 0, have at most 6 decimal places and be ≤ 1,000,000.
Zero, negative, non-numeric and over-precise factors are rejected.

## Quantities (`toBase`)

- Entered quantities must be > 0 with at most 4 decimal places.
- base = quantity × factor. It must be exactly representable with 4
  decimals.
- **Standard products** must convert to a whole number of base units: half a
  carton of 24 (= 12 pieces) is allowed, 1.5 pieces is not.
- The unit must be active and belong to the product. Purchasing also requires
  `can_purchase`, and pricing requires `can_sell`.

## Why snapshots

If a supplier changes cartons from 24 to 20 pieces, existing purchase order
lines still say "2 cartons × 24 = 48 pieces", while new orders use 20.
`UnitConversionTest::test_conversion_snapshots_survive_factor_changes` covers
this.

## Future POS

Selling in a non-base unit uses the same conversion. The sale line will
snapshot `(product_unit_id, factor, quantity, quantity_base, price)`, and
stock will be issued in base units.
