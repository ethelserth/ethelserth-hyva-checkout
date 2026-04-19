# Phase 2 — Address Step

> **Who this is for:** A developer who has read `phase-0-1-foundation.md` and understands
> the Phase 1 architecture (Magewire components, Alpine store, step system).
> This document explains every decision made in Phase 2, including why certain patterns
> were chosen and the bugs we hit along the way.

---

## Table of Contents

1. [What Phase 2 Builds](#1-what-phase-2-builds)
2. [2.1 — Native Address Field Config Reader](#2-21--native-address-field-config-reader)
3. [2.2 — Address Magewire Component (Full)](#3-22--address-magewire-component-full)
4. [2.3 — VAT Validation](#4-23--vat-validation)
5. [2.4 — Address Templates and Composition](#5-24--address-templates-and-composition)
6. [2.5 — Postcode Lookup](#6-25--postcode-lookup)
7. [2.6 — Postcode Format Validation (live, native patterns)](#7-26--postcode-format-validation-live-native-patterns)
8. [2.7 — Telephone Digits-Only + Per-Country Example](#8-27--telephone-digits-only--per-country-example)
9. [2.8 — CSS Pipeline via Hyvä Source Discovery](#9-28--css-pipeline-via-hyva-source-discovery)
10. [2.9 — Integration Notes](#10-29--integration-notes)
11. [Key Patterns to Remember](#11-key-patterns-to-remember)

---

## 1. What Phase 2 Builds

Phase 1 gave us a stub Address component with a hardcoded form. Phase 2 replaces it with
a production-ready address step:

- All native Magento address fields (prefix, middlename, suffix, company, vat_id, fax, telephone)
  are shown/hidden and marked required/optional based on admin configuration
- VAT validation fires automatically via Magento's native observer
- Logged-in customers see their saved address book and can pick an address to pre-fill the form
- NL/BE/DE postcode lookup fills street, city, and region automatically from postcode + house number
- Separate billing address when billing ≠ shipping

---

## 2. 2.1 — Native Address Field Config Reader

### `Model/Address/FieldConfig.php`

**The problem:** Magento lets merchants configure which address fields are visible and required
under `Stores > Config > Customers > Customer Configuration > Name and Address Options`.
Nothing in our templates should hardcode field visibility — a B2B store needs company and VAT,
a B2C store doesn't. A German store needs a title prefix; most others don't.

**Config paths:**

| Field       | Config path                          | Values                    |
|-------------|--------------------------------------|---------------------------|
| prefix      | `customer/address/prefix_show`       | `req`, `opt`, empty = no  |
| middlename  | `customer/address/middlename_show`   | `1` = show, `0` = hide    |
| suffix      | `customer/address/suffix_show`       | `req`, `opt`, empty = no  |
| vat_id      | `customer/address/taxvat_show`       | `req`, `opt`, empty = no  |
| fax         | `customer/address/fax_show`          | `req`, `opt`, empty = no  |
| telephone   | `customer/address/telephone_show`    | `req`, empty = no         |
| company     | `customer/address/company_show`      | `req`, `opt`, empty = no  |
| street lines| `customer/address/street_lines`      | integer 1–4               |

**Important:** The VAT config path is `taxvat_show`, not `vat_show`. In Magento's customer model
the field is called `taxvat`. In quote addresses it's called `vat_id`. The config path follows
the customer model name.

**Fallbacks:** Some config paths may not exist in all Magento versions. FieldConfig uses a
`FALLBACKS` map so that telephone defaults to required and company defaults to optional even
if the config path returns null.

**API:**

```php
$fieldConfig->isFieldVisible('vat_id')   // bool
$fieldConfig->isFieldRequired('vat_id')  // bool — only true when value is 'req'
$fieldConfig->getStreetLines()           // int 1–4
```

**Why inject into both the block AND the Magewire component?**

The template needs FieldConfig to show/hide fields. The Magewire component needs it
to validate that all required fields are filled on `saveAddress()`. We could share the
instance via the block argument and a `$wire->getFieldConfig()` call from the template,
but the cleanest pattern is: inject via the layout XML `field_config` block argument for
templates, and inject via DI for the PHP component. Magento's ObjectManager returns the
same singleton instance both ways.

---

## 3. 2.2 — Address Magewire Component (Full)

### `Magewire/Step/Address.php`

The Phase 1 stub grew into a full component with:

**Public properties (synced to frontend):**
- All standard address fields, including `prefix`, `middlename`, `suffix`, `fax`
- All billing-specific variants (`billingFirstname`, `billingStreet1`, etc.)
- `billingSameAsShipping: bool` — controls billing toggle
- `complete: bool` — tracked by Alpine to advance steps
- `errorMessage: string` — rendered at top of form

**New injected dependencies:**
- `FieldConfig` — drives validation
- `CustomerSession` — for saved address picker (logged-in only)
- `AddressRepositoryInterface` — loads saved address by ID
- `CountryCollection` — renders the country `<select>`

**`boot()` vs `mount()`:**

`boot()` runs on every Magewire request (initial render + every wire call). We use it to
pre-fill from the existing quote address and reset the error message. This means if the
merchant updates the quote externally, a page refresh picks up the new address.

If you need something that runs only once (e.g. tracking a "first visit"), use `mount()` instead.

**`selectSavedAddress(int $addressId)`:**

Loads the saved address, verifies ownership (security check — the logged-in customer
can only load their own addresses), and pre-fills all shipping fields.

```php
$customerId = (int) $this->customerSession->getCustomerId();
if ((int) $address->getCustomerId() !== $customerId) {
    return; // silently ignore — not their address
}
```

**Validation in `saveAddress()`:**

```
Always required: email (+ format), firstname, lastname, street1, city, postcode, countryId
Config-driven:   prefix, middlename, suffix, company, vat_id, telephone, fax
Billing fields:  validated when billingSameAsShipping = false
```

All errors are collected into an array, joined with spaces, and put in `$this->errorMessage`.
This causes a Magewire re-render with the error message visible at the top of the form
without losing field values.

---

## 4. 2.3 — VAT Validation

### How it works (nothing new to build)

Magento's native VAT validation is triggered purely by saving `vat_id` to the quote shipping
address and then calling `collectTotals()`. This was already wired in Phase 1's QuoteService.

The observer chain:
```
QuoteService::collectAndSave()
  → Quote::collectTotals()
    → fires: sales_quote_address_collect_totals_before
      → Magento\Quote\Observer\Frontend\Quote\Address\VatValidator::execute()
        → Magento\Customer\Model\Vat::checkVatNumber() [pings EU VIES SOAP]
          → assigns quote customer group
            → tax rules apply based on customer group
```

**What we do:** Save `vat_id` to address, call `collectAndSave()`. Done.

**What we do NOT do:** Write any VAT validation code, call VIES directly, or manage
customer group assignment manually. Magento does all of this. We just feed it the right data.

**FieldConfig integration:** `vat_id` is shown/hidden based on `customer/address/taxvat_show`.
If the merchant hides it, the field is absent from the HTML. The Magewire component never
receives a value for it. The empty string is saved to the address, and VatValidator simply
doesn't fire (no VAT number = no validation = use default customer group).

**Admin prerequisite:** `Stores > Config > Customers > Customer Config > Create New Account Options > Enable Automatic Assignment to Customer Group` must be set to `Yes`.

---

## 5. 2.4 — Address Templates and Composition

### Why sub-templates?

The full address form is long. Breaking it into focused files keeps each one under ~150 lines
and makes the role of each section obvious.

### Template composition with PHP `include`

We use PHP's native `include` with `$block->getTemplateFile()` to compose sub-templates:

```php
// In step/address.phtml:
$formTemplate = $block->getTemplateFile('Ethelserth_Checkout::checkout/address/form.phtml');
include $formTemplate;
```

**Why `include` instead of `$block->getChildHtml()`?**

`$block->getChildHtml('child-name')` creates a new block rendering context. Child blocks
do not inherit Magewire component variables (`$email`, `$firstname`, `wire:model` directives
work because they're in the HTML, but PHP variables like `$fieldConfig`, `$countryOptions`,
and the Magewire public properties extracted as variables are NOT available).

`include` runs in the current PHP scope, so every variable — `$fieldConfig`, `$countryOptions`,
`$errorMessage`, all Magewire public properties — is available in the included file.

**The files:**

| File | Purpose |
|------|---------|
| `step/address.phtml` | Root template: error message, sign-in prompt, saved address picker, form wrapper, submit button |
| `address/form.phtml` | All shipping fields with FieldConfig visibility, includes postcode-lookup.phtml inline |
| `address/billing-toggle.phtml` | Billing same/different checkbox + collapsed billing form |
| `address/saved-addresses.phtml` | Address book for logged-in customers |
| `address/postcode-lookup.phtml` | NL postcode + house number widget (Alpine x-data inline) |

### `field_config` block argument

FieldConfig is injected into the address step block via layout XML:

```xml
<argument name="field_config" xsi:type="object">
    Ethelserth\Checkout\Model\Address\FieldConfig
</argument>
```

In templates: `$fieldConfig = $block->getData('field_config');`

**Why not get it from `$wire`?** Magewire component variables available in templates
are limited to public _properties_ extracted as PHP variables. Calling component methods
from templates requires `$magewire->method()` but Magewire 1.x does not expose the PHP
component object as a variable in the template context. Injecting via block argument is
the clean, reliable pattern.

### Country selector

Country options are loaded via ObjectManager in the root template:

```php
$countryOptions = \Magento\Framework\App\ObjectManager::getInstance()
    ->get(\Magento\Directory\Model\ResourceModel\Country\Collection::class)
    ->loadByStore()
    ->toOptionArray(false);
```

This is deliberate. The Magewire component already fetches country options via DI
(for programmatic use), but the template needs them as a PHP array _before_ rendering.
Using ObjectManager here is consistent with how the Hyva theme itself handles display-only
dependencies in templates. The result is a singleton — no performance cost.

---

## 6. 2.5 — Postcode Lookup

### Architecture

```
Controller/Postcode/Lookup.php      ← AJAX endpoint (GET /checkout/postcode/lookup)
Model/Address/PostcodeLookup.php    ← application service
Model/Address/Lookup/LookupInterface.php
Model/Address/Lookup/Postcoder.php  ← Postcoder.com implementation
Model/Address/StreetSplitter.php    ← splits "Keizersgracht 100" → street + number
view/frontend/web/js/checkout/postcode-lookup.js  ← Alpine.data for standalone use
view/frontend/templates/checkout/address/postcode-lookup.phtml  ← inline Alpine widget
```

### The controller uses the existing `checkout` frontName

The controller lives at `Controller/Postcode/Lookup.php` which maps to the URL
`/checkout/postcode/lookup` via the `checkout` route already registered in Phase 1.
No new route registration needed.

### Postcoder.com integration

Postcoder requires an API key configured at:
`Stores > Config > Checkout > Ethelserth Checkout > Postcoder API Key`

If the key is missing, the controller returns an error JSON. The UI handles this gracefully
by showing an error message and letting the user fill in the form manually.

**To swap providers:** Override the DI preference in a child module:
```xml
<preference for="Ethelserth\Checkout\Model\Address\Lookup\LookupInterface"
            type="YourVendor\YourModule\Model\Address\Lookup\YourProvider"/>
```

### StreetSplitter

Dutch, German, and Belgian addresses put the house number AFTER the street name.
The splitter extracts it with a regex:

```
"Keizersgracht 100"     → street: "Keizersgracht", number: "100", addition: ""
"Lange Weg 20-3"        → street: "Lange Weg",    number: "20",  addition: "3"
"Plein 4 B"             → street: "Plein",         number: "4",   addition: "B"
"Keizersgracht 100A"    → street: "Keizersgracht", number: "100", addition: "A"
```

Pattern: `^(.*?)\s+(\d+)\s*([A-Za-z0-9\-\/]*)$`

### Postcode lookup Alpine component (inline in template)

The `postcode-lookup.phtml` uses an inline `x-data` object because it needs `$wire`
(which is only in scope inside a Magewire component template). On success, it calls:

```javascript
$wire.set('street1', d.street + ' ' + d.house_number + ...);
$wire.set('city', d.city);
$wire.set('postcode', ...);
```

`$wire.set()` is Magewire 1.x's way to set a component property from JavaScript.
It sends a deferred update — the next Magewire round trip (e.g. saveAddress) will
include the updated values. The visible inputs are updated immediately via wire:model binding.

**Why only NL?** The lookup widget is shown only when `countryId === 'NL'`:
```html
x-show="countryId === 'NL'"
```

The `countryId` variable here is the Magewire public property extracted into the Alpine scope
via wire:model. When the user changes country, Alpine hides the widget. No postcode lookup
for countries where it doesn't make sense (or where Postcoder doesn't support it).

---

## 7. 2.6 — Postcode Format Validation (live, native patterns)

### The mechanism (server-side)

Magento ships per-country postcode patterns in
`vendor/magento/module-directory/etc/zip_codes.xml`. Each entry is a country
block with one or more regex patterns and an example:

```xml
<zip countryCode="GR">
    <codes><code id="pattern_1" active="true" example="12345">^[0-9]{5}$</code></codes>
</zip>
```

Magento exposes them through two services we inject into the Address component:

- `Magento\Directory\Model\Country\Postcode\ConfigInterface::getPostCodes()` — all patterns keyed by country
- `Magento\Directory\Model\Country\Postcode\ValidatorInterface::validate($postcode, $countryId)` — returns bool
- `Magento\Directory\Helper\Data::isZipCodeOptional($countryId)` — matches `general/country/optional_zip_countries` (default: HK, IE, MO, PA, GB)

Server-side validation in `saveAddress()` runs both paths via `validatePostcodeFor()`:

1. If country is in the optional-zip list AND postcode is empty → pass.
2. Otherwise require postcode.
3. If country has a pattern, call `validate()`. On `InvalidArgumentException` ("no pattern configured"), treat as valid — same as Luma.

### The mechanism (client-side, live)

Luma shows a hint under the postcode as the user types an invalid format. We
match that behaviour with a thin Alpine scope per postcode input.

`Address::getPostcodePatterns()` emits the same data client-side, keyed by country:

```php
['GR' => ['optional' => false, 'patterns' => [['pattern' => '^[0-9]{5}$', 'example' => '12345']]]]
```

`step/address.phtml` builds the Alpine `x-data` JSON with a small factory so the
shipping and billing postcode fields each get their own scope wired to the right
country property (`countryId` vs `billingCountryId`):

```php
$buildPostcodeXData = function (string $valueProp, string $countryProp) use ($postcodePatternsJson, $magewire) {
    // emits { patterns, value, msgFormat, msgInvalid, get err() { ... } }
};
$postcodeXData        = $buildPostcodeXData('postcode', 'countryId');
$billingPostcodeXData = $buildPostcodeXData('billingPostcode', 'billingCountryId');
```

Each scope is rendered once per postcode input:

```html
<div x-data="<?= $escaper->escapeHtmlAttr($postcodeXData) ?>">
    <input x-model="value" wire:model.defer="postcode" .../>
    <p class="checkout-field-error" x-show="err" x-text="err" x-cloak></p>
</div>
```

**Why `x-model` and not `@input`:** with Magewire's `wire:model.defer` attached
to the same input, Alpine reactivity felt laggy until after blur. `x-model`
installs Alpine's own input listener independent of Magewire's, and writes to
the reactive `value` property on every keystroke. The `get err()` getter reads
`this.value` plus `this.$wire.countryId`, so Alpine re-evaluates it live as the
user types and also when the country changes.

**Why `x-show` and not `template x-if`:** an `x-if` block mounts/unmounts the
`<p>`; `x-show` just toggles `display`. The latter is cheaper and visibly
snappier on every keystroke.

**Message:** `"Please use the format: %1"` with `%1` replaced by the example from
the pattern entry. Empty postcodes show nothing — required enforcement happens
server-side in `validatePostcodeFor()`.

---

## 8. 2.7 — Telephone Digits-Only + Per-Country Example

`Address::getPhoneFormats()` returns a static map of country → example national
phone digits for 31 common countries (EU + US/GB/CH/NO). Unknown countries fall
through to an empty placeholder; the digit-only rule still applies.

The telephone field gets an inline Alpine scope that reads the current country
from `$wire.countryId` (or `$wire.billingCountryId` for billing):

```html
<div x-data='{ formats: <?= $phoneFormatsJson ?>, get example() { return this.formats[this.$wire.countryId] || ""; } }'>
    <input type="tel"
           wire:model.defer="telephone"
           @input="$event.target.value = $event.target.value.replace(/\D+/g, '')"
           :placeholder="example"
           inputmode="numeric" pattern="[0-9]*"
           class="checkout-input"/>
    <p class="checkout-hint">Digits only.</p>
</div>
```

- `@input` strips every non-digit from the DOM value as the user types — paste
  sanitises too, since paste fires an input event after the text lands.
- `:placeholder` updates reactively as the country changes.
- `inputmode="numeric" pattern="[0-9]*"` gives mobile a numeric keypad and lets
  browsers apply native validation.
- Server-side, `saveAddress()` runs `preg_replace('/\D+/', '', $this->telephone)`
  _before_ validation so (a) a required-phone rule doesn't false-positive on a
  user who typed `+30 210` and (b) nothing non-numeric ever reaches the quote.

---

## 9. 2.8 — CSS Pipeline via Hyvä Source Discovery

Hyvä 1.3 uses Tailwind v4 (`@source` directives, not v3's `content` array). The
theme generates `generated/hyva-source.css` at build time by scanning two
sources:

1. Extensions listed in the root `app/etc/hyva-themes.json` — vendor packages
   like Magewire and the Hyvä checkout/compat modules register here.
2. Tailwind includes listed in the theme's `web/tailwind/hyva.config.json`
   under `tailwind.include`.

For `app/code` modules the theme's local `hyva.config.json` is the right place:

```jsonc
// vendor/hyva-themes/magento2-default-theme/web/tailwind/hyva.config.json
{
  "tailwind": {
    "include": [
      "app/code/Ethelserth/Checkout"
    ]
  }
}
```

When the theme runs `npm run build`, `npx hyva-sources` scans that path for
`.phtml` / `.xml` and, crucially, for `view/frontend/tailwind/tailwind-source.css`,
then `@import`s our stylesheet into the generated source.

Our module stylesheet lives at:
```
app/code/Ethelserth/Checkout/view/frontend/tailwind/tailwind-source.css
```

It is organised under `@layer components` using only Hyvä default tokens
(`--color-primary`, `--color-surface`, `--color-gray-*`, `--radius-md`, etc.).
No child-theme tokens. Classes: `.checkout-section`, `-section-body`,
`-step-badge`, `-input`, `-label`, `-hint`, `-error`, `-field-error`,
`-choice`, `-btn`, `-btn-secondary`, `-divider`, `-tabs`, `-link-muted`, …

After editing templates or the module stylesheet:
```bash
cd vendor/hyva-themes/magento2-default-theme/web/tailwind
npm run build                          # regenerates source scan + styles.css
bin/magento cache:flush
```

---

## 10. 2.9 — Integration Notes

### After `saveAddress()` succeeds

```
QuoteService::saveShippingAddress()    → shipping address saved to DB
QuoteService::copyShippingToBilling()  → or saveBillingAddress() for separate billing
QuoteService::collectAndSave()         → VatValidator fires, customer group assigned, quote saved
$this->emit('addressSaved')            → browser event @address-saved.window fires
Alpine store.advance('address','shipping')  → shipping step unlocks
```

### Summary sidebar reflects VAT result immediately

When a valid intra-EU VAT number is entered, VatValidator assigns the quote to the
"valid intra-EU" customer group. On the next Magewire totals update (Phase 4), the
sidebar will show 0% VAT applied. No code needed here — the customer group assignment
in the DB is the trigger for everything downstream.

### Saved address picker security

`selectSavedAddress()` loads the address by ID and checks:
1. The address exists (`AddressRepositoryInterface::getById` throws on 404)
2. The address `customer_id` matches the logged-in customer's ID

Without check 2, a malicious user could enumerate address IDs and load another customer's
saved address. The check is a single integer comparison and costs nothing.

---

## 8. Key Patterns to Remember

| Pattern | Rule |
|---------|------|
| FieldConfig for all visibility | Never hardcode `display: none` or `hidden` for address fields in HTML. Always ask FieldConfig. |
| VAT via collectTotals | Save `vat_id` to address, call `collectAndSave()`. Magento's observer handles the rest. |
| `include` for sub-templates | Magewire variables (including public properties and `$wire`) are not available in child blocks. Use PHP `include` to compose templates in the same scope. |
| `taxvat_show` not `vat_id_show` | Magento's config path for the VAT field uses the customer model name (`taxvat`), not the quote address field name (`vat_id`). |
| Block argument for display data | FieldConfig, country options, and other read-only display data are injected via layout XML block arguments. Read them from `$block->getData('key')` in templates. |
| `ArgumentInterface` for block objects | Any class injected as `xsi:type="object"` in layout XML must implement `Magento\Framework\View\Element\Block\ArgumentInterface`. It's a marker interface — no methods to implement. Missing it throws `UnexpectedValueException` at layout generation time. |
| `$magewire` is the only Magewire injection | Magewire's template engine plugin adds `$magewire` (the component PHP object) to the template scope — nothing else. Public properties are NOT extracted as flat PHP variables. Read them explicitly: `$errorMessage = $magewire->errorMessage;`. Call methods directly: `$magewire->getSavedAddresses()`. |
| No inline `<script>` — use external files | Magento 2.4.x CSP blocks `<script>` tags without a matching nonce. Move all JavaScript to `.js` files in `view/frontend/web/` and reference them with `<script src="...">`. Same-origin external scripts are always allowed. After adding a new JS file, run `setup:static-content:deploy` to publish it to `pub/static`. |
| Postcoder is swappable | The DI preference for `LookupInterface` → `Postcoder` is set in `etc/frontend/di.xml`. Any child module can override it. |
| Owner check on address load | `selectSavedAddress()` must verify the address belongs to the logged-in customer before pre-filling. |

---

*Previous: [Phase 0 & 1 — Foundation](./phase-0-1-foundation.md)*  
*Next: Phase 3 — Shipping Step (see PROGRESS.md)*
