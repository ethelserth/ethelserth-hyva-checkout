# Ethelserth_Checkout

A production-grade, single-page checkout for Magento 2 on the Hyvä stack.
Drop-in replacement for native Luma checkout — no KnockoutJS, no
RequireJS, no Luma inheritance — built ground-up on **Magewire**
(server-authoritative state) and **Alpine.js** (visual state only).

---

## What it is

| | |
|---|---|
| **Composer name** | `ethelserth/hyva-checkout` |
| **Magento name**  | `Ethelserth_Checkout` |
| **License**       | Proprietary |
| **PHP**           | 8.1+ |
| **Magento**       | Open Source 2.4.5 – 2.4.7 |
| **Hyvä Theme**    | ^1.3 |
| **Magewire**      | ^1.4 |

Designed to be deployed across multiple client storefronts without
per-installation licensing or forking. PSPs (Viva Wallet, Revolut, …)
plug in via a stable adapter interface and ship as separate modules.

---

## Why this exists

Native Luma checkout is a Knockout-era artefact: heavy, fragile,
poorly suited to Hyvä's render-on-the-server philosophy, and a
maintenance tax that grows with every Magento upgrade. Existing
"Hyvä checkout" alternatives are mostly closed-source paid licences.

This module is a complete, owned, MIT-style replacement:

- Server-side state — Magewire owns the quote, never the client.
- Magento-native everywhere it counts — VAT validation, customer
  groups, terms & conditions, agreements, newsletter subscription
  all flow through the standard Magento APIs and admin sections.
- Deploy across stores without code changes — every behaviour is
  admin-toggleable.
- Extensible without forking — PSPs implement an adapter interface
  in their own module.

---

## Features

### Three-step flow, single page

```
[ Express rail — wallet buttons (Apple Pay / Google Pay via PSP) ]

  1. Address       guest / logged-in, native VAT validation,
                   EU intra-community 0% VAT, postcode lookup,
                   inline sign-in for existing accounts
                                ↓ addressSaved
  2. Shipping      async carrier rates with skeleton, smart
                   "cheapest" badge, retry on transient failure
                                ↓ shippingMethodSelected
  3. Payment       offline + PSP adapters, COD surcharge,
                   order comments, newsletter opt-in,
                   required terms & conditions
                                ↓ orderPlaced
                   Confirmation page + order email
```

### Address step

- Every native Magento address field respected (prefix, middlename,
  suffix, fax, vat_id, …) — visibility / required state read straight
  from `Stores > Config > Customers > Name and Address Options`.
- **Live VAT validation** via Magento's native `VatValidator`
  observer — intra-EU B2B sees 0% VAT immediately when the VIES
  service confirms the number.
- **Postcode lookup** (NL / BE / DE) via Postcoder — admin-toggleable,
  swappable resolver via DI.
- **Live postcode-format validation** — country-specific patterns
  pulled from Magento's `zip_codes.xml`.
- **Phone** — digits-only enforcement with per-country example
  placeholders (31-country map).
- **Inline sign-in** — when a guest enters an email that has an
  account, a password panel slides in. Sign in mid-checkout (full
  page reload to surface saved addresses + customer-group VAT) or
  leave it blank to continue as guest. Luma parity.
- **Save in address book** for logged-in customers, with semantic
  duplicate detection (street + city + postcode + country + region)
  so a one-letter typo doesn't create a near-duplicate.
- **Saved-address picker** as a `<select>` dropdown — scales to any
  number of addresses without UI breakdown.

### Shipping step

- Async rate fetch with shimmer skeleton during carrier round-trips.
- **Manual retry** when the rate fetch fails (transient carrier API
  outage shouldn't dead-end the shopper).
- Auto-derived "Cheapest" badge across enabled carriers.
- Free-shipping displays as `Free`, never `€0.00`.
- Works with any admin-enabled carrier (offline, real-time, custom)
  out of the box.
- Carrier logos via DI-replaceable `Resolver` — host theme can drop
  in branded SVGs without forking the module.

### Payment step

- Adapter pattern — offline methods auto-discovered from
  `payment/<code>/active`; PSP modules append their own adapters.
- Place-order button shows the live grand total inline:
  `[Place order] | [€118.45]`.
- Order placement runs adapter `beforePlaceOrder` → place →
  `afterPlaceOrder` lifecycle hooks. PSP redirect URLs are surfaced
  via `afterPlaceOrder`'s return value.
- Surcharges (e.g. cash-on-delivery fee) update the sidebar live.

### Order summary

- One DOM tree, two presentations: sticky desktop sidebar (right
  column), collapsible mobile bar (top of viewport, dark navy).
- Live totals, items, coupon — re-render on every event that
  mutates the quote (address save, shipping pick, coupon, payment
  surcharge, VAT-group flip).

### Cross-cutting

- **Order comments** — admin-toggleable plain-text field, admin-
  placeable in either the Address or Payment step. Stored on quote
  AND `sales_order` (column + extension attribute on both Cart and
  Order interfaces). Sanitised on every write AND read path.
- **Newsletter opt-in** — discreet checkbox above place-order with
  GDPR notice, gated on the native `newsletter/subscription/allow_guest_subscribe`
  + an own admin toggle for store-by-store control.
- **Terms & conditions** — required, pulled live from Magento's
  native `Sales > Terms and Conditions` admin (no parallel admin).
- **Session-expired recovery** — `NoSuchEntityException` on
  `placeOrder` redirects to cart with a clear message instead of
  stranding the shopper on a dead form.

### A11y / mobile

- 44×44 minimum tap targets across all interactive elements.
- `role="alert"` on every error banner; `aria-busy` on every loading
  container; `role="radiogroup"` on every option list.
- `:focus-visible` rings (keyboard-only — mouse interactions stay
  clean).
- Full `autocomplete` + `inputmode` map across the address form.
- No horizontal scroll at 320px (`min-width: 0` on flex/grid items
  containing potentially-long unbreakable strings — VAT, custom
  carrier titles).

---

## Install

### Via Composer (recommended once published)

```bash
composer require ethelserth/hyva-checkout
bin/magento module:enable Ethelserth_Checkout
bin/magento setup:upgrade
bin/magento setup:di:compile           # production mode only
bin/magento cache:flush
```

### Manual (development)

Clone into `app/code/Ethelserth/Checkout/`, then:

```bash
bin/magento module:enable Ethelserth_Checkout
bin/magento setup:upgrade
bin/magento cache:flush
```

### Tailwind build

After ANY template or CSS change, rebuild the Hyvä Tailwind bundle:

```bash
npm --prefix vendor/hyva-themes/magento2-default-theme/web/tailwind run build
```

The module's `tailwind-source.css` is auto-imported via Hyvä's source
generator — no per-store config needed.

---

## Configuration

`Stores > Config > Ethelserth > Checkout` ships three groups:

| Group | Field | Effect |
|---|---|---|
| Address Lookup | Enable Postcode Lookup | NL/BE/DE quick lookup widget |
| Address Lookup | Postcoder API Key | Required when lookup is enabled |
| Newsletter Subscription | Show Checkbox at Checkout | Gate on Magento_Newsletter + native `allow_guest_subscribe` |
| Newsletter Subscription | Checkbox Label | Translatable |
| Newsletter Subscription | GDPR Notice | Translatable; defaults to a generic line |
| Order Comments | Enable | Show / hide the textarea |
| Order Comments | Field Placement | Address step (above Continue) or Payment step (above Place Order) |
| Order Comments | Field Label / Placeholder | Translatable |

Native Magento config also consulted (the module never duplicates
existing admin paths):

| Native path | Used for |
|---|---|
| `Stores > Config > Customers > Customer Config > Name and Address Options` | Address-field visibility / required state |
| `Stores > Config > Customers > Customer Config > Create New Account Options` | VAT validation customer-group assignment |
| `Stores > Config > Customers > Newsletter > Subscription Options` | Guest newsletter subscribe flag |
| `Stores > Config > Sales > Checkout > Checkout Options > Enable Terms and Conditions` | Required-agreements gate |
| `Sales > Terms and Conditions` | Agreement content + checkbox text |
| `Stores > Config > Sales > Payment Methods > [each method]` | Method title / instructions / COD fee |
| `Stores > Config > Sales > Shipping Methods > [each carrier]` | Carrier enable / pricing |

---

## Architecture

```
app/code/Ethelserth/Checkout/
├── Block/                     Page block (renders root template, exposes step JSON to Alpine)
├── Config/                    Step-config XML schema and instance
├── Controller/
│   ├── Index/                 /checkout
│   ├── Postcode/              /checkout/postcode/lookup (AJAX)
│   └── Success/               /checkout/success
├── Magewire/
│   ├── Concern/               Reusable traits (HasOrderComments)
│   ├── Step/                  Address, Shipping, Payment
│   └── Summary/               Items, Coupon, Totals
├── Model/
│   ├── Address/               Field config, postcode lookup, street splitter
│   ├── OrderComments/         Sanitiser
│   ├── Payment/Adapter/       AdapterInterface + AbstractAdapter + Offline
│   ├── Payment/MethodPool     Auto-discovers offline + accepts PSP adapters via DI
│   ├── Quote/                 QuoteService (the only thing that mutates the quote)
│   ├── Shipping/              Method decorator, carrier-logo resolver
│   ├── Step/                  Step config reader, pool, factories
│   └── Config/Source/         Admin select source models
├── Observer/                  Quote → Order copy on submit
├── Plugin/                    Cart + Order extension-attribute plugins
├── Service/                   Session wrappers
├── ViewModel/                 OrderCommentsConfig, NewsletterConfig, TermsConfig
├── etc/                       module / di / events / extension_attributes / db_schema / config / system
└── view/
    ├── adminhtml/             Order-comments display in admin order view
    └── frontend/
        ├── layout/            checkout_index_index.xml, checkout_success_index.xml
        ├── templates/         All checkout step / partial templates
        ├── tailwind/          Module-scoped CSS (auto-imported by Hyvä)
        └── web/js/checkout/   Alpine store + postcode-lookup data component
```

Two principles enforced throughout:

1. **Server is the source of truth.** Alpine never calls Magento's
   REST API; Magewire never delegates state to the client.
2. **No parallel admin.** If Magento has a config path or an
   interface for a thing, we use it instead of inventing one in our
   own namespace.

---

## Documentation

| Phase | What |
|---|---|
| [Phase 0–1 — Foundation](docs/phase-0-1-foundation.md) | Module scaffold, step system, root layout, Alpine store, Magewire bridge |
| [Phase 2 — Address Step](docs/phase-2-address-step.md) | Field config, VAT, postcode lookup, postcode pattern validation, phone formatter |
| [Phase 3 — Shipping Step](docs/phase-3-shipping-step.md) | Async rates, decorator, skeleton, single-mount summary pattern |
| [Phase 4 — Summary Sidebar](docs/phase-4-summary-sidebar.md) | Items / coupon / totals components, mobile vs desktop presentation |
| [Phase 5 — Payment Step](docs/phase-5-payment-step.md) | Adapter contract, MethodPool, place-order pipeline, dual-channel events |
| [Phase 6 — Hardening](docs/phase-6-hardening.md) | Error recovery, loading states, a11y, mobile, performance |
| [Feature: Order Comments](docs/feature-order-comments.md) | Sanitiser, extension attributes, quote→order observer, trait pattern |

Live development progress: [docs/PROGRESS.md](docs/PROGRESS.md).

---

## Adding a PSP

PSPs ship as separate modules that depend on this one. The contract
is in [`Model/Payment/Adapter/AdapterInterface.php`](Model/Payment/Adapter/AdapterInterface.php).

### 1. Scaffold the module

```
app/code/Vendor/CheckoutMyPsp/
├── etc/module.xml          (sequence after Ethelserth_Checkout and the PSP's vendor module)
├── etc/di.xml              (registers your adapter into Ethelserth's MethodPool)
├── Adapter/MyMethod.php    (extends AbstractAdapter)
└── view/frontend/
    └── templates/payment/mypsp/...
```

### 2. Implement the adapter

```php
namespace Vendor\CheckoutMyPsp\Adapter;

use Ethelserth\Checkout\Model\Payment\Adapter\AbstractAdapter;
use Magento\Quote\Model\Quote;

class MyMethod extends AbstractAdapter
{
    public function getMethodCode(): string { return 'mypsp_method'; }
    public function getTitle(): string      { return 'My PSP — Cards'; }
    public function getIconUrl(): string    { return '/static/.../mypsp.svg'; }

    public function getInstructions(): string
    {
        return 'You will be redirected to My PSP to complete the payment.';
    }

    public function getFormTemplate(): ?string
    {
        return 'Vendor_CheckoutMyPsp::payment/mypsp/form.phtml';
    }

    public function beforePlaceOrder(Quote $quote): void
    {
        // Validation that should abort placement — throw LocalizedException.
    }

    public function afterPlaceOrder(Quote $quote): ?string
    {
        // Return a hosted-page URL to redirect the shopper, or null
        // to stay on the local confirmation page.
        return $this->buildPspRedirectUrl($quote);
    }

    public function getJsAssets(): array
    {
        // Lazy-loaded when this method is selected. Empty for redirect adapters.
        return ['https://embed.mypsp.com/sdk.js'];
    }
}
```

### 3. Register it

`etc/di.xml`:

```xml
<type name="Ethelserth\Checkout\Model\Payment\MethodPool">
    <arguments>
        <argument name="adapters" xsi:type="array">
            <item name="mypsp_method" xsi:type="object">Vendor\CheckoutMyPsp\Adapter\MyMethod</item>
        </argument>
    </arguments>
</type>
```

That's it — your method appears in the payment list; the place-order
pipeline invokes your hooks; redirect URLs are followed.

For a Magewire-mounted form (3-D Secure inline, hosted-card fields,
etc.) ship a Magewire component in your own namespace and return its
template path from `getFormTemplate()`.

See [`docs/phase-5-payment-step.md`](docs/phase-5-payment-step.md) §3
for the full design rationale and the reference Viva / Revolut
adapter sketches.

---

## Development

```bash
# Watch + rebuild Tailwind on template change
npm --prefix vendor/hyva-themes/magento2-default-theme/web/tailwind run watch

# Lint module PHP
find app/code/Ethelserth/Checkout -name "*.php" -exec php -l {} \; | grep -v "No syntax errors"

# Cache reset after DI / template / layout changes
bin/magento cache:clean config layout block_html full_page
```

Standing development conventions are documented in
[`CLAUDE.md`](CLAUDE.md) at the project root and rigorously enforced
in [`docs/PROGRESS.md`](docs/PROGRESS.md):

- Read `docs/PROGRESS.md` at the start of every session — it is the
  single source of truth for development state.
- Never duplicate Magento native admin config — read the existing
  path through `ScopeConfigInterface` instead.
- Run the Tailwind build yourself after CSS changes — the project
  has hit silent visual drift from forgotten rebuilds three times.

---

## Testing

A guest path smoke test:

1. Add product to cart, navigate to `/checkout`.
2. Address step renders. Type an email of an existing customer →
   inline check finds the account → password panel slides down.
3. Type address, click Continue → quote saves, shipping unlocks
   with skeleton, then live rates.
4. Pick a method → payment unlocks.
5. Tick required terms, place order → confirmation page, increment
   ID visible, order email sent (Magento's native observer).

See [`docs/PROGRESS.md`](docs/PROGRESS.md) Phase 5.6 for the full
end-to-end smoke matrix (guest, logged-in, coupon, edit, B2B VAT).

---

## Roadmap

| Phase | Status |
|---|---|
| 0–6 | shipped |
| 7 — A/B testing hooks | next |
| 8 — Viva Wallet adapter (`Ethelserth_CheckoutViva`) | planned |
| 9 — Revolut adapter (`Ethelserth_CheckoutRevolut`) | planned |
| 10 — v1.0.0 release prep | planned |

---

## Support

Issues and feature requests via the project's GitHub repository (see
`composer.json`).

This module is licensed proprietary — contact the maintainer before
redistribution.
