# Changelog

## Unreleased

- **Ships from a branch**: with sunrice/commerce's shipping origins (`Commerce::shippingOriginUsing()`, next release), rates are quoted from the cart's origin, with its own postal code and map point (so GoSend and Grab can be offered), and the pickup is booked there with its name and phone (`Order::shippingOrigin()`). The `commerce-biteship.origin.*` overrides apply to the store address only. Nothing changes without an extension's origin, or on commerce 0.2.

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
