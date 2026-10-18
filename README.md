# Sunrice Commerce Biteship

[Biteship](https://biteship.com) shipping for [Sunrice Commerce](https://github.com/Digital-Citra-Kreatif/sunrice-commerce):

- **Live rates** at checkout from the couriers you choose (JNE, J&T, SiCepat, AnterAja, POS, TIKI,
  Ninja, Lion, ID Express, SAP, JDL, Wahana, GoSend, GrabExpress, Lalamove, Borzo, Paxel…).
- **Pickup booking**: when an order is paid (cash on delivery: when it is placed), or with one
  click on the order page. The courier comes to your address and the order is marked shipped with
  the waybill as its tracking number.
- **Tracking**: Biteship's webhooks (and commerce's hourly check) move the shipment to in transit
  and delivered.
- **Shipping labels**: a printable A6 label with the waybill barcode, routing code, both
  addresses, weight, contents and the COD amount. Biteship has no label API, so the package draws
  its own.

## Contents

- [Installation](#installation)
- [Configuration](#configuration)
- [Setting up the shipping method](#setting-up-the-shipping-method)
- [Booking, cancelling and labels](#booking-cancelling-and-labels)
- [Tracking and webhooks](#tracking-and-webhooks)
- [Testing Mode](#testing-mode)
- [Going live](#going-live)
- [Instant couriers (GoSend, GrabExpress)](#instant-couriers-gosend-grabexpress)
- [How it fits into commerce](#how-it-fits-into-commerce)
- [Development](#development)

## Installation

```bash
composer require sunrice/commerce-biteship
php artisan vendor:publish --tag=commerce-biteship-assets --force   # the order page's Biteship panel
```

Run the publish command again after each update (add it to your deploy script next to
`commerce-assets`). The package registers itself; switch it off with the **Biteship** feature in
Settings → Store → Features.

## Configuration

`.env`:

| Key | | |
| --- | --- | --- |
| `BITESHIP_API_KEY` | required | Biteship dashboard → Integrations → API. `biteship_test.…` is [Testing Mode](#testing-mode) |
| `BITESHIP_ORIGIN_POSTAL_CODE` | | Where parcels leave from; empty uses the store address's postal code (Settings → Store → General) |
| `BITESHIP_ORIGIN_LATITUDE`, `BITESHIP_ORIGIN_LONGITUDE` | | The store's coordinates, for [instant couriers](#instant-couriers-gosend-grabexpress) |
| `BITESHIP_ORIGIN_CONTACT_NAME`, `BITESHIP_ORIGIN_CONTACT_PHONE` | | Who the courier asks for at pickup; empty uses the store's name and phone |
| `BITESHIP_ORIGIN_NOTE` | | A note for the courier ("ring the bell at the side door") |
| `BITESHIP_WEBHOOK_PATH` | `biteship/webhook` | See [Tracking and webhooks](#tracking-and-webhooks) |
| `BITESHIP_WEBHOOK_TOKEN` | | Optional: a random string the webhook URL must carry as `?token=…` |
| `BITESHIP_QUEUE` | | The queue for automatic bookings (null: the default) |

Publish `config/commerce-biteship.php` with `php artisan vendor:publish --tag=commerce-biteship-config`
for the rest (base URL, timeout). The key stays in `.env`: it is never shown in the admin.

The store health check (Shop dashboard, `php artisan commerce:doctor`) tells you when the key is
missing, the origin can't be found, a test key is used in production, or the panel isn't published.

## Setting up the shipping method

Settings → Store → Shipping → a zone → add a method of the type **Biteship (live courier rates)**:

| Setting | |
| --- | --- |
| Couriers | Which couriers checkout quotes (default JNE, J&T, SiCepat, AnterAja, POS) |
| Book the pickup | **From the order page**, or **When the order is paid** (cash on delivery: when it is placed) |
| Hand over | The courier picks up at the store, or you drop parcels off at the courier |
| Insure parcels for their value | Adds Biteship's insurance to rates and bookings |
| What you ship | Biteship's item category (fashion, food and drink, electronics…); food and drink gets instant couriers assigned faster |

The usual method settings (title, minimum subtotal, maximum weight…) apply too.

Checkout shows one rate per courier service, e.g. "JNE Reguler · 2 - 3 days". Rates are quoted by
postal code: the customer's, or their village's from commerce's region list, or, when neither is
known, Biteship's area for the district (looked up once and remembered for 30 days). Weights are
the variants' weights in grams and sizes their dimensions in centimetres, so fill those in on your
products. Cash on delivery orders only see couriers that collect cash, priced with the COD fee,
and none above Biteship's COD limit of Rp15.000.000. When a route has no courier, checkout simply
shows no Biteship rate.

Rates are cached for ten minutes per cart and address by commerce.

## Booking, cancelling and labels

A booking sends everything left to ship in the order as one parcel, with the order number as
Biteship's reference (so Biteship refuses a second booking for the same order). When it succeeds,
commerce gets a shipment with the courier, the waybill as tracking number, Biteship's tracking
link and the price you pay; the order is marked shipped and the customer gets commerce's
"shipped" email.

The **Biteship** panel on the order page (permission `commerce.shipments.manage`) shows the
booking (status, courier, waybill, driver, price, live tracking) and has the buttons:

- **Book pickup**: for orders whose method books from the order page, or when an automatic booking
  failed. Failures (no balance, an address Biteship can't find) are on the order's timeline and
  on the panel.
- **Print label**: the shipping label PDF. Override the template with
  `resources/views/vendor/commerce-biteship/label.blade.php`.
- **Refresh**: fetches the booking now.
- **Cancel booking**: until the courier has picked the parcel up (Biteship's rule), cancels it at
  Biteship; the shipment is marked cancelled and **Book again** books it anew on the same shipment
  (reference `ORDER-NUMBER-2`).

Bookings are paid from your Biteship balance, so keep it topped up. A booking that gets no courier
(`courier_not_found`) or is rejected also marks the shipment cancelled: book it again.

Automatic bookings run on the queue (three tries for network errors; Biteship's own refusals
aren't retried). Run a queue worker. If Biteship already has a booking for the reference (the
answer to an earlier try was lost), that booking is taken over rather than refused.

The hand-over follows the method's setting when the courier offers it on the route, otherwise the
way it does offer (pickup or drop-off, from Biteship's rates). Items go to Biteship with their name,
variant, SKU, price, weight and size, and the method's category.

## Tracking and webhooks

Biteship has no API for webhooks: add it in the dashboard.

1. Biteship dashboard → Integrations → **Pengaturan** (Settings) → Webhook → **Tambah Webhook**.
2. URL: `https://your-store.example/biteship/webhook` (with `?token=…` when you set
   `BITESHIP_WEBHOOK_TOKEN`). It must be HTTPS and reachable from the internet; Biteship calls it
   once when you save, and the package answers 200.
3. Events: `order.status`, `order.price` and `order.waybill_id`.
4. Add it once in Testing Mode and once in live mode: each mode has its own webhooks.

In Testing Mode, Biteship's order list has a yellow button on each order to fire its webhooks
(Play Webhook): use it to walk a test booking through picked up, in transit and delivered.

Biteship doesn't sign webhooks, so the package only reads which booking changed from them and
then fetches that booking from Biteship's API before it changes anything. A forged webhook can at
most make the store ask Biteship again. Statuses move the shipment:

| Biteship | Shipment |
| --- | --- |
| confirmed, scheduled, allocated, picking_up | shipped |
| picked, in_transit, dropping_off, on_hold, return_in_transit | in transit |
| delivered | delivered (`ShipmentDelivered`) |
| returned | returned |
| cancelled, rejected, courier_not_found, disposed | cancelled (book again from the order page) |

Every change is on the order's timeline, including a new price when the courier weighs the parcel
differently at pickup (`order.price`; the shipment's cost follows). Some couriers issue the
waybill only after pickup; it arrives with the `order.waybill_id` webhook. Without webhooks,
`commerce:tick` still asks Biteship about each open booking once an hour.

## Testing Mode

Turn on **Mode Testing** in Biteship's dashboard and create a key there: it starts with
`biteship_test.`. With it:

- bookings are simulated: no courier comes and nothing is taken from your balance; the panel and
  the label say "testing mode";
- rates, areas and tracking are real data, and Biteship counts them as paid usage even in Testing
  Mode.

The health check warns about a test key in production.

## Going live

Biteship activates the Order API for live keys only after reviewing a test integration
(dashboard → Integrations → API → **Aktivasi Order API**):

1. In Testing Mode, place an order in the store and book it (Book pickup on the order page). Copy
   its Biteship order id from the panel ("Booking"): the form asks for a **Test Order ID**.
2. Choose the live key and the couriers to activate.
3. The form asks whether you use your own shipping label: you do. Upload a sample made with
   **Print label** on the test order.
4. Submit ("Kirim Data Aktivasi API") and wait for the status to read **Aktif**.
5. Put the `biteship_live.` key in `BITESHIP_API_KEY`, add the live webhook, and top up the
   Biteship balance (bookings are paid from it).
6. For cash on delivery, ask Biteship to enable COD for your account and couriers; until then
   bookings of COD orders are refused ("not available for COD feature").

Rates and tracking work with a live key before activation; only bookings wait for it.

## Instant couriers (GoSend, GrabExpress)

GoSend, GrabExpress, Lalamove, Borzo and Paxel price by distance, so Biteship needs the
coordinates of both ends. Set the store's in `BITESHIP_ORIGIN_LATITUDE` and
`BITESHIP_ORIGIN_LONGITUDE`. Commerce's checkout doesn't ask customers for a map pin yet, so these
couriers aren't quoted at checkout for now, even when chosen on the method. The package uses an
address's `latitude` and `longitude` as soon as commerce stores them.

## How it fits into commerce

Only commerce's public extension points:

- the shipping method type `biteship` (`ShippingMethodType`, `TracksShipments`, `BooksShipments`);
- the feature `biteship` (requires `shipping`);
- an order panel, a health check and courier presets;
- listeners on `OrderPaid` and `OrderPlaced`;
- commerce's `CreateShipment` and `UpdateShipment` actions.

Bookings live on commerce shipments, in `meta.biteship` (Biteship order id, tracking id, waybill,
status, driver, price), so the package has no tables of its own.

## Development

```bash
composer install
composer test      # Pest, with Biteship's API faked
composer lint      # Pint
composer analyse   # Larastan
```

`sunrice/cms` and `sunrice/commerce` come from their GitHub repositories until they are on
Packagist. To work against local checkouts next to this one, copy `composer.json` to
`composer.local.json`, point its repositories at `../sunrice-cms` and `../sunrice-commerce` as
`path` repositories, and run `COMPOSER=composer.local.json composer install`.

## License

MIT
