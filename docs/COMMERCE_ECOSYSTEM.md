# The Drupal Commerce ecosystem, mapped

> Verified **2026-08-27** against drupal.org project pages. The ecosystem page
> for Commerce Core runs to 19 pages (~475 modules), most of them
> country-specific payment gateways; enumerating them is noise. This maps the
> modules that actually decide an architecture, with the compatibility facts
> that determine whether they can be used at all.
>
> Status legend: **stable+SA** = stable release covered by the Drupal security
> advisory policy (the bar for production). **RC/beta** = usable but no stable
> tag, so no SA coverage. **dead** = no D11-compatible release.

## 1. The foundation

| Module | Latest | Core | Commerce | Status |
| --- | --- | --- | --- | --- |
| `commerce` | **3.3.8** (2026-07-17) | `^10.3 \|\| ^11` | — | **stable+SA** |

Commerce 3 is stable, D11-ready and Centarro-maintained. Everything below
assumes it. Bundled with core (no extra module to hunt for): product types +
variations + attributes, order types, checkout flows, **promotions with
conditions and coupons**, the payment gateway API, address book, cart, and
**tax** including VAT rate plugins. A surprising amount of what people go
looking for on drupal.org is already in the box — check core before adding.

## 2. Payment

The gateway API is the extension point; ~100+ gateways exist. Two facts matter
more than the list:

- **Availability is regional and brutal.** A gateway that dominates one market
  may have no D11 module, or no module at all. Verify per-market before
  promising a payment method — this is the single most common late surprise in
  a commerce build.
- **Off-site gateways *can* store payment methods.** Since Commerce 8.x-2.19
  ([change record 3144453](https://www.drupal.org/node/3144453)) an off-site
  gateway may implement `SupportsStoredPaymentMethodsInterface`, creating the
  payment method in `onReturn()`/`onNotify()` and charging later off-session.
  The `commerce_recurring` README still claims an on-site gateway is required;
  **that README is stale**. This is what makes redirect-then-tokenise hybrids
  legitimate, and it removes the full-PCI-scope objection to recurring billing.

Writing a gateway plugin is a normal, well-trodden task when no module exists:
extend `OffsitePaymentGatewayBase`, add `SupportsNotificationsInterface` for
webhooks and `SupportsRefundsInterface` for refunds.

## 3. Subscriptions and entitlements

| Module | Latest | Core | Commerce | Status |
| --- | --- | --- | --- | --- |
| `commerce_license` | **3.0.2** (2025-06) | 9.3 / 10 / **11** | `^2.32 \|\| ^3` | **stable+SA** |
| `commerce_recurring` | `8.x-1.0-rc3` (2024-09) | `^9.2 \|\| ^10 \|\| ^11` | `^2.36 \|\| ^3` | **RC** — never stable since 2017 |
| `commerce_file` | **8.x-2.4** (2026-03) | `^10.3 \|\| ^11` | — | **stable+SA** |

**`commerce_license`** grants an entitlement (typically a role) on purchase and
revokes it on expiry. It works **without** `commerce_recurring`: a fixed-term
license that expires and is re-bought gives you memberships with no recurring
machinery at all. That is the cheap, reversible way to sell access.

**`commerce_recurring`** adds real subscriptions — fixed/rolling intervals,
prepaid/postpaid, prorating, free trials, usage. Two landmines:
1. **It cannot be uninstalled** ([core #2871486](https://www.drupal.org/project/drupal/issues/2871486)),
   stated on its own project page. Adoption is a one-way door — do not install
   it to "see how it works".
2. Renewals run **via cron** by default; production should use the Drush queue
   daemon at `/admin/config/system/queues/manage/commerce_recurring`.
   Manual-renewal support ([#2919602](https://www.drupal.org/project/commerce_recurring/issues/2919602))
   has been stalled at *needs review* for four years — do not plan around it.

**`commerce_file`** is `commerce_license` specialised for digital downloads:
time-limited access, download-count limits, S3 temporary URLs, and downloads
without login via a checkout pane.

## 4. Finance, compliance, money

| Module | Latest | Core | Status | Note |
| --- | --- | --- | --- | --- |
| `commerce_invoice` | **8.x-2.2** (2025-05) | 10.1 / **11** | **stable+SA** | PDF invoices via `entity_print`; **requires a private file system** |
| `commerce_currency_resolver` | **2.0.2** (2026-08-11) | `^10.3 \|\| ^11` | **stable+SA** | multi-currency by store/cookie/language/geo, with `commerce_exchanger` |
| tax | in core | — | — | VAT/rate plugins ship with Commerce |

`commerce_invoice` generates invoices on order placement (or on demand),
supports multiple invoice types and number patterns. It is *not* a legal
e-invoicing integration — jurisdictions with mandated clearance e-invoicing
(Colombia's DIAN, Italy's SdI, and similar) need a custom module against a
certified provider's API. Check this early; it is a legal requirement that
shapes checkout fields, not an accounting afterthought.

**Never hardcode a tax rate.** Make it config per product variation — rates and
exemptions change by statute, sometimes with months of notice.

## 5. Fulfilment

| Module | Latest | Core | Status |
| --- | --- | --- | --- |
| `commerce_shipping` | **3.0.3** (2026-07-22) | `^10.3 \|\| ^11 \|\| ^12` | **stable** |

Already D12-ready — ahead of most of the ecosystem. Carrier-rate modules
(USPS/UPS/FedEx/DHL and regional couriers) plug into it and vary wildly in
maintenance; verify each individually.

## 6. Analytics

| Module | Latest | Core | Commerce | Status |
| --- | --- | --- | --- | --- |
| `commerce_reports` | `8.x-1.0-rc4` (2025-02) | 9 / 10 / **11** | 3 | **RC** |

Writes report rows to purpose-built tables at order placement and queries those
instead of raw entity tables — the right shape for volume, but it is a
*framework for building reports*, not a dashboard you switch on.

## 7. The real gaps

**Multivendor / marketplace is effectively unavailable on D11.**
`commerce_marketplace` has **no supported stable release** and only a D9 dev
branch. If a build needs vendors owning their own stores and products with
commission splits, that is a custom build on Commerce's multi-store foundation
— budget for it explicitly rather than discovering it late.

Worth distinguishing two models people both call "marketplace":
- **True multivendor** — vendors sell through your checkout, you split funds.
  No module; custom.
- **Directory + vendor subscriptions** — vendors pay *you* for presence, and
  transactions happen off-site. This needs **no marketplace module at all**:
  it is `commerce_license` (+ optionally `commerce_recurring`) plus ordinary
  content types. Far cheaper, and frequently what was actually meant.

**Point of sale** and **stock/inventory** both need per-project verification;
neither has an obvious stable D11 answer, and stock in particular is often
better solved by the upstream system of record than inside Drupal.

## 8. Choosing, by archetype

| You are building | Install |
| --- | --- |
| Physical-goods store | core + a gateway + `commerce_shipping` + `commerce_invoice` |
| Digital downloads | core + gateway + `commerce_license` + `commerce_file` |
| Memberships / access | core + gateway + **`commerce_license` alone** — add `commerce_recurring` only when auto-renewal is genuinely required |
| Directory with paid listings | core + gateway + `commerce_license` + content types. **No marketplace module.** |
| Multi-currency | add `commerce_currency_resolver` + `commerce_exchanger` |
| True multivendor | core multi-store + **custom**; no contrib path |

## 9. Two things that bite regardless

**A store is a content entity, not config.** It does not travel in
`config/sync`; every environment creates its own. This is the most surprising
thing about deploying Commerce behind a config-export pipeline.

**Currency fraction digits follow CLDR, not local habit.** Where a currency is
quoted in whole units in practice but CLDR says two decimals (or the reverse),
the mismatch shows up as rounding noise — and payment providers disagree with
each other about the same currency, so the same amount can be off by 100×
between gateways. Pin it explicitly per provider and test with a real charge.

## Verified vs. assumed

Verified against project pages on 2026-08-27: `commerce`, `commerce_shipping`,
`commerce_invoice`, `commerce_reports`, `commerce_file`,
`commerce_currency_resolver`, `commerce_license`, `commerce_recurring`,
`commerce_marketplace`. Core-bundled features are from the Commerce project
page's own feature list. Carrier modules, POS and stock were **not**
individually verified — check them when a project needs them.
