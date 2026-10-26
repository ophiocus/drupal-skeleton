# Shop delivery

Home delivery run by the shop itself (domicilios), on Commerce:

- a **Domicilio** shipping method: a flat fee, a fee by distance from the
  store, or a fee by zone;
- a pin for the customer's door on a maper map, in the shipping profile
  (address search through `maper_nominatim` when that module is on);
- a dispatch board (`/admin/commerce/domicilios`), a courier page
  (`/repartidor`) and cash-on-delivery settlement
  (`/admin/commerce/domicilios/liquidacion`);
- a private tracking page for the customer (`/pedido/{order}/{token}`), linked
  from checkout. No live position tracking.

Roles `despachador` and `repartidor` ship in `config/install`; the permissions
are `dispatch shop deliveries`, `settle shop deliveries` and
`deliver shop shipments`.

An upstream module (PROTOCOL D30): every site carries it and enables it only
where it delivers. It needs `commerce_shipping`, `state_machine` and `maper`;
a site that does not carry them cannot enable it, and the test gate leaves it
out there (`scripts/ci/unmet_upstream.php`).

Installing it wires Commerce once (shippable variations, shipments and the
fulfillment workflow on the default order type, the checkout panes, the
store's point). That wiring then travels in the site's `config/sync`.

## Rules it keeps

No update, post-update or deploy hooks (D25).
