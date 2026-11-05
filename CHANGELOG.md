# Changelog

## Unreleased

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
