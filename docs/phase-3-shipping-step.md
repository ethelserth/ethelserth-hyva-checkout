# Phase 3 — Shipping Step

> **Who this is for:** A developer who has already worked through
> `phase-0-1-foundation.md` and `phase-2-address-step.md`. Phase 3 is much
> shorter than Phase 2 — most of the heavy lifting (sessions, quote service,
> the step XML system, Magewire→Alpine bridge) was built earlier. This
> phase plugs the shipping step into those systems.

---

## Table of Contents

1. [What Phase 3 Builds](#1-what-phase-3-builds)
2. [3.1 — Shipping Magewire Component](#2-31--shipping-magewire-component)
3. [3.2 — Method Decorator + Carrier Logo Resolver](#3-32--method-decorator--carrier-logo-resolver)
4. [3.3 — Templates and Skeleton](#4-33--templates-and-skeleton)
5. [3.4 — Layout Wiring + Done-Summary Slot](#5-34--layout-wiring--done-summary-slot)
6. [Working With Any Admin-Enabled Carrier](#6-working-with-any-admin-enabled-carrier)
7. [Why We Did NOT Add ETA / Fastest Config](#7-why-we-did-not-add-eta--fastest-config)
8. [Key Patterns to Remember](#8-key-patterns-to-remember)

---

## 1. What Phase 3 Builds

Address save emits `addressSaved`. The shipping step listens for that, fetches
rates from the quote address, decorates them for display, and renders a
one-click radio list. When the user picks a method, the component persists
the selection, recomputes totals (VAT + shipping line), and emits
`shippingMethodSelected` so Alpine advances to payment and the sidebar
(Phase 4) can refresh.

The step supports **any shipping method the merchant enables** in
`Stores > Configuration > Sales > Shipping Methods` — flatrate, freeshipping,
tablerate, real-time carriers (UPS/USPS/DHL/FedEx), and any third-party
custom carrier. Nothing about the rendering path is hard-coded to a
particular code.

End result:

```
Address section   ← already built
   ↓ addressSaved (from Magewire)
Shipping section  ← THIS PHASE
   [skeleton while onAddressSaved fetches rates]
   [method-list with logos + prices + "Cheapest" badge]
   ↓ shippingMethodSelected
Payment section   ← unlocked next
```

---

## 2. 3.1 — Shipping Magewire Component

### `Magewire/Step/Shipping.php`

Public properties that are synced to the frontend:

```php
public string $selectedCarrier = '';
public string $selectedMethod  = '';
public bool   $complete        = false;
public string $errorMessage    = '';
public array  $methods         = []; // decorated rates
```

Listeners:

```php
protected $listeners = [
    'addressSaved'      => 'onAddressSaved',
    'stepEditRequested' => 'onEditRequested',
];
```

Only the two cross-step listeners remain. We used to also listen to
`shippingMethodSelected` back when a second Magewire mount rendered the
collapsed summary; that mount was dropped (see §5), so the listener went
with it.

### `boot()` — runs on every Magewire request

- Reads the quote's saved shipping method. If present, pre-fills
  `selectedCarrier`/`selectedMethod` and flips `complete = true`.
- If the address step has already been completed (quote shipping address has
  a firstname — the simplest "is populated" probe), we also **fetch rates
  here**. This guarantees that on a full page refresh the user sees the
  method list without having to re-save the address.

### `onAddressSaved()`

Fires when the Address component emits `addressSaved`. Calls
`fetchDecoratedRates()`, which:

1. Asks `QuoteService::getShippingRates()` for raw `Magento\Quote\Model\Quote\Address\Rate` objects.
2. Passes them through `MethodDecorator::decorate()`.

It also resets `selectedCarrier/selectedMethod` if the previously chosen
method is no longer in the new rate set (can happen after the user edits an
address to a country the old carrier doesn't service).

### `selectMethod(string $carrierCode, string $methodCode)`

1. Calls `QuoteService::setShippingMethod()`.
2. Calls `QuoteService::collectAndSave()` — this recomputes totals **and** re-fires the VAT validator (harmless but consistent).
3. `$this->emit('shippingMethodSelected', …)` — server-side Magewire listener channel. Any other component subscribing to it re-renders.
4. `$this->dispatchBrowserEvent('shipping-method-selected', …)` — browser CustomEvent channel. This is what lets `@shipping-method-selected.window` in the root `checkout.phtml` fire `$store.checkout.advance('shipping', 'payment')`. **Both calls are required** — see §8 for the gotcha.

Errors (bad rate, saving failure) are caught, logged, and surfaced as
`$errorMessage` at the top of the step body.

### `getSelectedSummary()` — template-facing

Called inline from `step/shipping.phtml` (the done-view block). Returns the
decorated entry matching the selection by scanning `$methods` first, then
falling back to a fresh `decorate()` pass against
`QuoteService::getShippingRates()` so the summary survives a page refresh
even when `$methods` hasn't been re-hydrated yet.

---

## 3. 3.2 — Method Decorator + Carrier Logo Resolver

### `Model/Shipping/MethodDecorator.php`

Turns `Rate[]` into the array shape the templates consume:

| Key                | Source                                                             |
|--------------------|---------------------------------------------------------------------|
| `carrier`          | `Rate::getCarrier()` — **not** `getCarrierCode()`; see §8           |
| `method`           | `Rate::getMethod()` — **not** `getMethodCode()`; see §8             |
| `code`             | `"<carrier>_<method>"` — matches Magento's stored `shipping_method` |
| `carrier_title`    | `Rate::getCarrierTitle()` (falls back to code)                      |
| `method_title`     | `Rate::getMethodTitle()` (falls back to code)                       |
| `price`            | `Rate::getPrice()` as float                                         |
| `price_formatted`  | `"Free"` when `0.0`, otherwise `PriceCurrencyInterface::format()`   |
| `logo_url`         | `CarrierLogo\Resolver::getLogoUrl()` (may be `null`)                |
| `badge`            | `'cheapest'` or `null`                                              |
| `badge_label`      | Localised label for `badge`                                         |
| `error`            | `Rate::getErrorMessage()` passthrough                               |

**Cheapest-badge rule:** only emitted when there is more than one priced
rate (a single-rate display should not wear a comparison badge). Rates that
carry an `error` message are excluded from the cheapest calculation so we
don't advertise a broken option as "cheapest".

**Helper:** `findDecoratedByCode($rates, $carrier, $method)` — the
summary template uses this to rebuild the chosen method after a page
refresh, without rebuilding the full list.

### `Model/Shipping/CarrierLogo/Resolver.php`

Maps a carrier code to an SVG under
`view/frontend/web/images/shipping/<carrier>.svg`.
The resolver ships a `KNOWN_CARRIERS` map (flatrate, freeshipping, tablerate,
ups, usps, fedex, dhl, postnl, dpd, gls, bpost). Unknown carriers return
`null`; the template then renders a neutral truck icon so the row layout
stays stable.

**Adding a new carrier logo** is a two-line change:

```php
private const KNOWN_CARRIERS = [
    // ...
    'mycarrier' => 'mycarrier.svg',
];
```

Drop the SVG at `view/frontend/web/images/shipping/mycarrier.svg`, run
`setup:static-content:deploy`, done.

---

## 4. 3.3 — Templates and Skeleton

Five files, composed via PHP `include` (same rule as Phase 2 — see
`phase-2-address-step.md` §5):

| File | Role |
|------|------|
| `step/shipping.phtml` | Orchestrator: error banner, skeleton (`wire:loading`), method list include or empty-state hint |
| `shipping/method-list.phtml` | `<div role="radiogroup">` wrapping the iteration |
| `shipping/method-item.phtml` | Single row: radio + logo (or fallback icon) + method title + cheapest badge + carrier title + price. `is-selected` is driven off `$wire.selectedCarrier`/`$wire.selectedMethod` so the selection ring is instant |
| `shipping/skeleton.phtml` | Three grey animated rows that mirror the real method-item shape, preventing layout jank on reveal |
| `shipping/summary.phtml` | Compact view rendered in the root done-summary slot |

### `wire:loading` pattern

The skeleton is gated by Magewire's built-in loading directives:

```html
<div wire:loading class="space-y-3" aria-busy="true" aria-live="polite">
    <?php include $skeletonTemplate; ?>
</div>
<div wire:loading.remove>
    [real method list]
</div>
```

Any Magewire round-trip on the component (boot after `addressSaved`,
`selectMethod` call, edit re-open) shows the skeleton. No custom
`$loading` property needed — Magewire handles it.

### Disabled rows

A rate that carries an `error` message (e.g. UPS saying "no rate for this
postcode") is still rendered, but with:

- `.checkout-option.is-disabled` (muted styling + `cursor: not-allowed`)
- `disabled` on the `<input type="radio">`
- No `wire:click` — the click handler is stripped so the component can't be
  made to save a broken code
- The error string shown in place of the carrier title

---

## 5. 3.4 — Layout Wiring + Inline Done-Summary

### The problem

In Phase 1 the root `checkout.phtml` rendered each step body only while it
was active:

```html
<div x-show="$store.checkout.isActive('<stepName>')">
    <?= $block->getChildHtml('checkout.step.<name>') ?>
</div>
```

That's correct for forms, but the shipping step also needs a **collapsed
summary** ("DHL Express Standard · €5.95") once it's done — something that
lives in DOM even when the step is no longer active.

### The path not taken — second Magewire mount

An earlier draft of this phase added a separate
`checkout.step.shipping.summary` block — a second Magewire mount of the
same component class rendered inside a generic done-summary slot in the
root template. It **looked** clean (isolated template for the summary)
but it produced a subtle race: both mounts subscribed to `addressSaved`,
which meant every address save kicked off **two** concurrent shipping-rate
collects against the same quote. One of them could produce a silent 500 on
the wire XHR (symptom: clicking *Continue to shipping* did nothing, no JS
console error). Debugging was slow because only the server log tells you
there are two components under the same event.

### The pattern we use — one mount, two sibling views

`step/shipping.phtml` is **the only** Magewire mount of `Shipping` on the
page. Its single root wrapper holds two siblings:

```html
<div>
    <?php if ($errorMessage): ?>
        <div role="alert" class="checkout-error mb-4">…</div>
    <?php endif ?>

    <!-- Active view: method list -->
    <div x-show="$store.checkout.isActive('shipping')" x-cloak>
        <div wire:loading>…skeleton…</div>
        <div wire:loading.remove>…method list…</div>
    </div>

    <!-- Done view: collapsed summary -->
    <?php if ($summary): ?>
    <div x-show="$store.checkout.isDone('shipping')" x-cloak class="checkout-done-summary">
        …logo + titles + price…
    </div>
    <?php endif ?>
</div>
```

For both siblings to remain in the DOM across state transitions, the root
`checkout.phtml` renders the body wrapper whenever the step is **active
OR done**:

```html
<div x-show="$store.checkout.isActive('<stepName>') || $store.checkout.isDone('<stepName>')">
    <?= $block->getChildHtml('checkout.step.<name>') ?>
</div>
```

Because that rule widens visibility for every step, each step template
gates its own active content with its own `x-show="isActive(...)"` to
avoid "address form still showing under the done header" bleed. The
address step follows the same sibling pattern as shipping (active form +
collapsed summary within one root; see `Address::getAddressSummary()`).

### Why only one mount

- **No dual rate-collect on `addressSaved`** — only one component
  subscribes to the event, so one server round-trip recomputes rates.
- **No cross-mount state sync story** — the single mount is self-evidently
  the source of truth, no "does the summary reflect the main mount's
  selection?" questions.
- **Fewer listeners to maintain** — we don't need
  `onShippingMethodSelected` just to nudge a second mount to re-render.

### Layout XML

```xml
<block class="Magento\Framework\View\Element\Template"
       name="checkout.step.shipping"
       template="Ethelserth_Checkout::checkout/step/shipping.phtml">
    <arguments>
        <argument name="magewire" xsi:type="object">Ethelserth\Checkout\Magewire\Step\Shipping</argument>
    </arguments>
</block>
```

That's all. No `.summary` block, no generic done-summary slot plumbing in
the root template. The orphaned `shipping/summary.phtml` can be deleted
safely whenever we next touch that directory.

---

## 6. Working With Any Admin-Enabled Carrier

This was an explicit requirement for the phase: **the shipping step must
render every carrier the merchant has enabled, not just the handful this
module knows about.** Verifying the flow:

1. `QuoteService::getShippingRates()` calls
   `ShippingAddress::setCollectShippingRates(true)` + `collectShippingRates()`.
   This is Magento's native path. It asks every enabled carrier (core +
   third-party) to quote for the current quote address.
2. The raw rates flow into `MethodDecorator::decorate()` which reads only
   generic accessors (`getCarrierCode`, `getCarrierTitle`, etc.) — no
   carrier-specific branching.
3. The template renders whatever comes back. Unknown codes just get:
   - a neutral truck-icon fallback instead of a branded logo,
   - the carrier title as written in the carrier's own config.

Adding a brand-new carrier = enable it in admin. No code changes in this
module required.

---

## 7. Why We Did NOT Add ETA / Fastest Config

PROGRESS.md originally listed "fastest badge: configurable per carrier
code in `config.xml`". During implementation we drafted:

```xml
<!-- THIS WAS REJECTED — do not re-add -->
<ethelserth_checkout>
    <shipping>
        <fastest_carriers>...</fastest_carriers>
        <eta_flatrate>3–5 business days</eta_flatrate>
    </shipping>
</ethelserth_checkout>
```

…and the user correctly flagged that shipping method settings already
have their own admin location under **Stores > Configuration > Sales >
Shipping Methods**. Duplicating shipping-related config into an
`ethelserth_checkout` section fragments merchant UX — the merchant would
have to hunt in two places for related knobs.

**Decision for this phase:** drop both "fastest" badge and ETA display.
They're soft UX extras, not core pricing or eligibility — the step works
perfectly without them. The `cheapest` badge is auto-derived from the
rate set itself, so it needs no config at all.

**If a client genuinely needs curated ETAs later**, the right
implementation is either:

- a separate client module that overrides `MethodDecorator` via DI
  `<preference>` and adds its own data source (their own module's config,
  their ERP, etc.), or
- a patch to the `Magento_Shipping` carrier config node(s) they control,
  not to ours.

Either way, this module stays honest about where shipping config lives.

---

## 8. Key Patterns to Remember

| Pattern | Rule |
|---------|------|
| Every Magewire template needs a single root tag | Magewire wraps the component's HTML with its own `wire:id` / state attributes, so the template must emit **exactly one** top-level element. An `if ($summary) return;` that short-circuits to empty output throws `Missing root tag when trying to render the Magewire component`. Keep a permanent root wrapper; gate the inner rendering instead. |
| No duplicate Magento config | Shipping method titles/prices/countries live under `Sales > Shipping Methods`. Don't mirror them under `ethelserth_checkout/*`. If you catch yourself writing `<shipping>` inside our config, stop. |
| Generic carrier handling | Never branch on `carrier === 'flatrate'`. If you need carrier-specific behaviour, use a DI override of `MethodDecorator` from a client module, not a core change here. |
| One Magewire mount per step | Never mount the same component twice on the page. Render both the active view and the collapsed summary as siblings inside one root tag and toggle them with `x-show`. See §5 for the race-condition story that motivated this rule. |
| `emit()` ≠ browser event | Magewire has **two separate effect channels**. `$this->emit('foo')` only fires the `effects.emits` channel — server-side component-to-component. Browser `CustomEvent` listeners (`@foo.window` in Alpine) fire from `effects.dispatches` only, which comes from `$this->dispatchBrowserEvent('foo')`. Any cross-step transition that needs both a Magewire listener and an Alpine store update must call **both** methods in the PHP action. Symptom when you forget: address save succeeds, shipping XHR fires and returns new `methods`, but the shipping step never becomes visible because `$store.checkout.advance('address','shipping')` never ran. |
| Rate accessors differ by entity | `Magento\Quote\Model\Quote\Address\RateResult\Method` (pre-import, produced by carrier models) exposes `getCarrierCode()` / `getMethodCode()`. `Magento\Quote\Model\Quote\Address\Rate` (post-import, what `ShippingAddress::getAllShippingRates()` returns) exposes `getCarrier()` / `getMethod()`. Calling `getCarrierCode()` on the address Rate silently returns `""` — there's no such field. `MethodDecorator` works on the address Rate, so use `getCarrier()` / `getMethod()`. |
| `wire:loading` for skeletons | No manual `$loading` state. Toggle the skeleton with `wire:loading` on its wrapper and the real list with `wire:loading.remove`. |
| Disabled rates stay visible | Rates that come back with an `error` still render, but without a click handler and in a muted style. Don't hide them — merchants and customers want to know the option was attempted. |
| Price formatting goes through `PriceCurrencyInterface` | Don't write `"€" . number_format(...)`. Currencies vary by store and `0.0` should read as **Free**, which `formatPrice()` handles. |
| `collectAndSave` after every quote mutation | `setShippingMethod` alone does not update totals. The pattern is: mutate → `collectAndSave` → emit. The VAT validator runs on every totals pass; that's intentional. |
| Logo resolver fallback is null | The resolver returns `null` for unknown carriers; the template draws a neutral glyph in the same bounding box so row height never shifts. |

---

*Previous: [Phase 2 — Address Step](./phase-2-address-step.md)*
*Next: Phase 4 — Summary Sidebar (see PROGRESS.md)*
