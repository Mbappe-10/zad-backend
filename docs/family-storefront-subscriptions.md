# Family storefront subscriptions API

Family subscription purchases use the same Moyasar live/sandbox credentials and
native card/Apple Pay SDK as orders, with a separate purchase/payment record.
Web checkout uses a single-use Payment Intent while the existing order payment
files remain untouched. The server fetches the provider payment and matches
its ID or intent, amount, currency, environment and family/purchase metadata
before activating an entitlement. Neither a client redirect nor a webhook
body proves payment. Test mode remains isolated by the existing policy.
ZAD Sync then issues its own immutable subscription invoice PDF, without tax
fields or a Moyasar hosted invoice. The owner controls the invoice wording and
design; payment facts remain server-generated and locked.

## Owner console

- `GET /api/admin/family-store-plans`: all three plans, including hidden ones.
- `PATCH /api/admin/family-store-plans/{free|silver|gold}`: name, description,
  price, duration, feature cards, capabilities, display order and visibility.
- `GET /api/admin/families/{id}/subscription`: effective plan and grant history.
- `POST /api/admin/families/{id}/subscription/grants`:
  `{ "plan": "gold", "days": 100, "reason": "تجربة" }`.
- `POST /api/admin/families/{id}/subscription/grants/{grantId}/revoke`:
  immediately returns the family to free unless another valid entitlement exists.
- `GET /api/admin/control-center` and
  `PATCH /api/admin/control-center/family_subscriptions` with
  `{ "value": { "enabled": true } }`: owner switch for subscription offers.
  Turning off offers does not confiscate existing grants or alter product orders.
- `GET /api/admin/family-store-purchases`: purchase status, invoice number and
  verified Moyasar payment ID. The owner can verify a purchase again by provider
  payment ID and download its archived PDF.
- `GET/PATCH /api/admin/family-store-receipt-settings`: owner-editable issuer,
  heading, intro, footer, layout and accent color. The owner can upload an
  optional logo and image (PNG/JPEG, private R2) and preview them. Changes
  affect future invoices only; money, payer, payment reference, dates and
  hashes cannot be edited.
- `GET /api/admin/family-store-purchases/lookup?reference=...` searches by
  ZAD receipt number, payment UUID, given ID or internal ID across all history.

The `features` array contains editable display cards (`id`, `title`, optional
`description`). The `capabilities` object contains server-enforced design gates:
`banner`, `themes`, `fonts`, `product_cards`, `rename_store`. The
`weekly_promotions` and `coupons` fields reserve the proposed commercial
entitlements, but their request/approval and fulfillment flows are **not built**.
Do not present them as working actions yet. A new feature card does not create
behavior by itself. Only the owner can edit plans or grants.

## Family account

- `GET /api/v1/app/productive-family/storefront/plans`: visible plans and sales status.
- `GET /api/v1/app/productive-family/storefront`: effective entitlement, actual
  public storefront payload, saved design and products for live preview.
- `PATCH /api/v1/app/productive-family/storefront`: description; silver or gold
  themes, accent color and font; gold product card and name change every 30 days.
- `POST /api/v1/app/productive-family/storefront/images/{logo|banner}`:
  multipart `image`. Free families get one logo replacement; banner needs an
  entitled plan. Images use the existing Cloudinary public-disk configuration.
- `POST /api/v1/app/productive-family/storefront/purchases` with
  `{ "plan": "silver" }`: reserves a price and 30-day duration snapshot,
  returning the native SDK configuration. Repeated requests reuse an open
  attempt for the same plan and amount.
- `POST /api/v1/app/productive-family/storefront/purchases/{id}/web-intent`:
  creates or safely reuses a single-use Moyasar Payment Intent for the web.
- `GET /api/v1/app/productive-family/storefront/purchases/latest`: recover the
  pending attempt after reopening the app.
- `POST /api/v1/app/productive-family/storefront/purchases/{id}/verify`: fetches
  the payment directly from Moyasar and creates exactly one grant and immutable
  receipt snapshot. The PDF is stored in the existing private R2 contract disk.
- `GET /api/v1/app/productive-family/storefront/purchases/{id}/receipt`:
  authenticated family PDF download; the owner has a separate admin endpoint.
- The independent subscription webhook requires the existing Moyasar secret,
  and the `family-subscriptions:reconcile` scheduler checks pending native
  attempts. Configure `POST /api/v1/app/family-store-purchases/webhook` in
  Moyasar and ensure the scheduler runs in production before enabling sales.
  A web payment can also be verified by its ID from the original family tab
  or from the owner's purchase lookup. An uncertain web intent creation is
  never retried blindly; the owner reviews the provider payment first.

Purchased plans begin after the last unexpired entitlement, so changing plans
does not discard paid or gifted days. Each plan lasts the configured number of
calendar days (30 by default) from its actual start time. An uncertain web
intent response blocks additional payment intents to avoid duplicate charges.
At expiry the customer storefront falls back to the free design. A paid grant
cannot be revoked through the owner gift revoke endpoint. Provider refunds and
chargebacks require a separate finance review before broad availability.

The storefront page belongs inside **إدارة المتجر**, with separate tabs for
**بيانات الأسرة**, **واجهة المتجر**, and **الباقات والاشتراكات**. The preview
should render the same store/product component as the customer page. On each
field change apply unsaved input to local preview state; after save refetch the
family endpoint. Provide `auto`, `phone`, `tablet` viewport modes: auto uses
available container width, phone fits its mock frame with CSS scaling, tablet
widens the frame without horizontal overflow. The screen is visible to free
families too; a gated edit opens the comparison of silver and gold.

## Customer storefront

`GET /api/v1/app/stores/{id}/products` contains `store.plan` and `store.design`.
The legacy `store.cover_url` is also suppressed when the plan lacks a banner.
The bootstrap home catalog recomputes effective design on every request, even
when its product data is cached. Entitlements use the server clock; at expiry
the public view falls back to default styling without deleting saved design.
For a page already open on the customer's phone, refresh or polling is needed
to receive the new entitlement state.

The old `metadata.subscription_plan` is legacy display data, never an
entitlement. General admin family/store editing no longer changes the effective
plan. The existing generic subscriptions resource is not a payment record.

## Next integration gates

1. Apply and verify the supplied React owner editor in `zad-admin-dashboard`.
2. Apply and verify the supplied family tabs, responsive preview and customer
   storefront changes in `zad_app`.
3. Implement coupon approval and weekly promotion allocation with separate
   entitlement checks and a moderation queue; those capabilities currently
   describe planned offerings and must not be shown as active actions.
4. Review refund and chargeback handling before broad availability. Keep the
   owner subscription switch off until configuration and purchase tests pass.
