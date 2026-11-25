# Family storefront subscriptions API

This feature keeps subscription invoices separate from order checkout and all
existing order payment files. A family may buy silver or gold through a hosted
Moyasar invoice only when the owner enables subscriptions and the existing
Moyasar mode is configured. The server verifies the invoice and paid payment
directly with Moyasar before activating an entitlement. Client redirects and
callback payloads never prove payment. Test mode remains isolated by the
existing Moyasar mode policy.

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
- `GET /api/admin/family-store-purchases`: latest purchase statuses and verified
  invoice and payment IDs. Family details include the purchase history.

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
  `{ "plan": "silver" }`: reserves a price snapshot and returns the hosted
  checkout URL. Repeated requests reuse an unexpired invoice for that plan.
- `GET /api/v1/app/productive-family/storefront/purchases/latest`: recover the
  pending invoice after reopening the app.
- `POST /api/v1/app/productive-family/storefront/purchases/{id}/verify`: fetches
  the invoice from Moyasar, verifies paid status, currency, amount, invoice
  identity and paid payment, then creates exactly one grant.
- Moyasar invoice callback also triggers that server-side verification. If it
  arrives before the invoice ID is saved, the family can use Verify in the app.
  The invoice's checkout URL is never a proof of payment.

Purchased plans begin after the last unexpired entitlement, so changing plans
does not discard paid or gifted days. New invoices expire after 25 minutes;
uncertain provider responses temporarily block additional invoices to avoid
duplicate charges. A paid grant cannot be revoked through the owner gift
revoke endpoint. Provider refunds require a separate refund review workflow.

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
