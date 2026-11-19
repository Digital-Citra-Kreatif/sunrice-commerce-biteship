# Changelog

## Unreleased

## 0.2.0 (2026-11-19)

Works with Sunrice Commerce 0.1, 0.2 or 0.3; shipping from a branch and order scopes need commerce
0.3. Update with `composer require sunrice/commerce-biteship:^0.2`. The new **Shipping label** setting
defaults to the store's label, so nothing changes until you choose Biteship's.

- Fix: the order page's Biteship panel and booking use the newest Biteship shipment. Commerce orders
  an order's shipments by id, so `latest('id')` still found the oldest one.
- **Hourly tracking applies the whole booking**: `commerce:tick`'s check (`track()`) now updates the
  shipment as a webhook does. Before, it only noticed deliveries, so with a missed webhook a
  cancelled, rejected, returned or repriced booking kept its old status on the order page.
- Statuses written in camelCase (`pickingUp`, as Biteship's status docs show them) are read as the
  API's snake_case (`picking_up`).
- **Biteship's own label**: a new method setting, **Shipping label**, chooses between the store's
  A6 label (the default, as before) and Biteship's label from its Shipping Label API
  (`GET /v1/orders/{id}/shipping_labels`, Biteship's Grow plan or higher), on 10 × 15 cm, 8 × 10 cm
  or A4 paper (**Biteship label paper**). Print label opens Biteship's PDF; if Biteship refuses,
  the store's label is printed. `Client::shippingLabel()` returns the PDF's URL.
- Works with Sunrice Commerce 0.3 (`sunrice/commerce` `^0.1 || ^0.2 || ^0.3`).
- **Order scopes**: the order page's Biteship buttons and the label (book, cancel, refresh, print)
  answer `404` for an order outside the staff member's scope (commerce 0.3's
  `Commerce::scopeAdminOrders()`), and resolve the order after sign-in, as commerce's own order
  pages do. Before, a scoped staff member could reach them by order id.
- **Labels from a branch**: a label's sender is the branch the pickup was booked at (the order's
  shipping origin), not always the store address.
- **Ships from a branch**: with sunrice/commerce's shipping origins (`Commerce::shippingOriginUsing()`, commerce 0.3), rates are quoted from the cart's origin, with its own postal code and map point (so GoSend and Grab can be offered), and the pickup is booked there with its name and phone (`Order::shippingOrigin()`). The `commerce-biteship.origin.*` overrides apply to the store address only. Nothing changes without an extension's origin, or on commerce 0.2.

## 0.1.0 (2026-11-07)

The first release. Needs Sunrice Commerce 0.1 or 0.2 (`sunrice/commerce`, on `sunrice/admin` and `sunrice/cms` 0.6 or 0.7),
PHP 8.4 and Laravel 13.

- Ported to the split Sunrice: commerce runs on the panel (`sunrice/admin`) and the CMS
  (`sunrice/cms`). `BiteshipPlugin` (add it after commerce in the panel provider) registers the
  order page's booking routes and the Biteship panel's script, loaded after commerce's bundle on
  its pages (window.Sunrice.modules, runtime 3). The admin pages moved from `/cms/commerce/…` to
  `/admin/commerce/…`; route names stay `sunrice.admin.commerce-biteship.*`.
- First release: Biteship live rates at checkout, pickup booking (automatic when paid, or from the
  order page), cancelling until pickup and booking again, taking over a booking Biteship already
  has for the reference, tracking through webhooks (verified against the API, optional token) and
  `commerce:tick`, price changes at pickup, shipping labels with the waybill barcode, a health
  check and Testing Mode.
