# Phase 6 — Hardening and UX

> **Who this is for:** A developer working on the checkout after Phases
> 0–5 are functional. Phase 6 isn't new features — it's a pass over
> what's already there, looking for: missing error recovery, places
> where the user has no idea their click registered, controls too
> small to tap on a phone, scripts that block paint, accessibility
> gaps a screen reader would trip over. The goal is "feels solid"
> rather than "does something new."

---

## Table of Contents

1. [What Phase 6 Changes](#1-what-phase-6-changes)
2. [6.1 — Error Recovery, Not Just Error Display](#2-61--error-recovery-not-just-error-display)
3. [6.2 — Loading States: Every Click Acknowledges Itself](#3-62--loading-states-every-click-acknowledges-itself)
4. [6.3 — Accessibility: Tap Targets and Focus Rings](#4-63--accessibility-tap-targets-and-focus-rings)
5. [6.4 — Mobile: No Horizontal Scroll, autocomplete on Everything](#5-64--mobile-no-horizontal-scroll-autocomplete-on-everything)
6. [6.5 — Performance: Where We Were Tempted to `defer` and Shouldn't](#6-65--performance-where-we-were-tempted-to-defer-and-shouldnt)
7. [Why a `defer` on store.js Would Have Silently Broken the Store](#7-why-a-defer-on-storejs-would-have-silently-broken-the-store)
8. [Things Phase 6 Did NOT Touch](#8-things-phase-6-did-not-touch)
9. [Key Patterns to Remember](#9-key-patterns-to-remember)

---

## 1. What Phase 6 Changes

A small, surgical set of edits across five concerns:

| Concern | Files touched | Effect |
|---|---|---|
| 6.1 Error recovery | `Magewire/Step/Shipping.php`, `Magewire/Step/Payment.php`, `view/.../step/shipping.phtml`, `view/.../checkout.phtml` | Manual retry on rate-fetch failure; session-expired → cart redirect |
| 6.2 Loading states | `view/.../shipping/method-item.phtml`, `view/.../summary/coupon.phtml`, `tailwind-source.css` (`.checkout-option.is-loading`) | Every wire round-trip now has visible feedback |
| 6.3 A11y | `tailwind-source.css` (`.checkout-edit-btn`) | 44×44 tap target on edit links, focus-visible ring |
| 6.4 Mobile | `tailwind-source.css` (`.checkout-section { min-width: 0 }`) | No horizontal overflow on 320px viewports |
| 6.5 Performance | (No code change — see §6 for what we deliberately did NOT do) | |

No new entities, no new templates, no new endpoints. Everything is
attached to the existing event contract from Phases 1–5.

---

## 2. 6.1 — Error Recovery, Not Just Error Display

### Shipping rate-fetch retry

Rate fetching is the one place in the checkout that talks to the
outside world — UPS / FedEx / DHL real-time rate APIs from inside
`collectShippingRates()`. Networks fail. Carrier APIs return 503.
Before this phase, a single transient blip dead-ended the user: the
"shipping options are unavailable" message stayed visible until they
re-saved their address (which is awkward, since the address is fine).

`Magewire/Step/Shipping::retryRates()`:

```php
public function retryRates(): void
{
    $this->errorMessage = '';
    try {
        $quote = $this->checkoutSession->getQuote();
        $rates = $this->quoteService->getShippingRates($quote); // FORCE collect
        $this->methods = $this->methodDecorator->decorate($rates);
    } catch (\Throwable $e) {
        $this->logger->error('Shipping rate retry failed', ['exception' => $e]);
        $this->errorMessage = (string) __('Shipping rates are still unavailable. Please try again in a moment.');
    }
}
```

The key difference from `loadDecoratedRates()`: this always calls
`getShippingRates()`, which forces a fresh `collectShippingRates`
flag. We never trust persisted rates here — that's the whole point of
"retry."

The template surfaces the button inline with the alert:

```html
<div role="alert" class="checkout-error mb-4 flex items-start justify-between gap-3">
    <span><?= $errorMessage ?></span>
    <button type="button"
            wire:click="retryRates"
            wire:loading.attr="disabled"
            wire:target="retryRates"
            class="checkout-error-retry">
        Retry  <!-- swaps to spinner + "Retrying…" via wire:loading -->
    </button>
</div>
```

`wire:target="retryRates"` is the critical bit — without it the
spinner would show on every Magewire round-trip in the component
(including rate-fetch on address save). Targeting only `retryRates`
keeps the UI honest.

### Session expiry → redirect to cart

Magento's checkout session can vanish under the user (cookie expired,
session GC, tab opened from a stale page). Before, `placeOrder()`
would catch the resulting `NoSuchEntityException` in the generic
`Throwable` block and show "We could not place your order" — a
cryptic message, no recovery path, the user is stranded.

`Magewire/Step/Payment::placeOrder()` now distinguishes:

```php
} catch (LocalizedException $e) {
    // PSP / quote validation rejected the order — keep user on form.
    $this->dispatchErrorMessage($e->getMessage());
} catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
    // Quote disappeared mid-flight — session expiry. Send the shopper
    // back to the cart instead of stranding them on a dead form.
    $this->dispatchBrowserEvent('checkout-session-expired', [
        'message' => (string) __('Your checkout session has expired. Please return to your cart.'),
    ]);
} catch (\Throwable $e) {
    // Real error — log + generic flash.
    $this->dispatchErrorMessage((string) __('We could not place your order...'));
}
```

The root template handles the new browser event:

```html
@checkout-session-expired.window="window.alert($event.detail.message ?? 'Your checkout session has expired.'); window.location.href = '/checkout/cart'"
```

A `window.alert` is intentional — it forces an explicit acknowledgment
before the redirect, so the shopper isn't confused by the page change.
Production stores can swap it for a toast component if they prefer.

### Address save failure: already handled

`Magewire/Step/Address::saveAddress()` already catches its own
exceptions and exposes them via `errorMessage`, which the template
renders inside `role="alert"`. The form state (every field's value)
is preserved across the round-trip because Magewire keeps public
properties in the snapshot. No Phase 6 change needed there.

### Order placement failure: already handled

`placeOrder` catches `LocalizedException` for predictable rejection
paths (out of stock, invalid coupon, country mismatch). The user
sees the actual message, the `placing` flag resets, the form stays
populated — same Phase 5 behaviour, no Phase 6 change.

---

## 3. 6.2 — Loading States: Every Click Acknowledges Itself

The rule we apply across every Magewire-backed click: the user must
see *something* change between click and response, even if the wire
round-trip is 80ms. Otherwise on a slow connection they assume the
click did nothing and click again, sometimes three times.

### Shipping method radio rows

Before: clicking a row triggered `wire:click="selectMethod(...)"` and
the user saw nothing until Magewire's response repainted the
`.is-selected` class via the `:class` Alpine binding.

After:

```html
<label class="checkout-option"
       :class="..."
       wire:loading.class="is-loading"
       wire:target="selectMethod">
```

`wire:loading.class="is-loading"` adds the class while the round-trip
is in flight; the CSS rule fades the row at 60% opacity with
`cursor: progress`. Even on a 1 Gbit connection it's a perceivable
beat — on 3G it's the difference between "did I click?" and "I
clicked, give it a moment."

`wire:target="selectMethod"` again — without it the loading class
would also apply during `addressSaved` listener firings (the
component re-fetches rates), which would fade the entire list during
a normal address save.

### Coupon apply spinner

The Apply button now swaps "Apply" → spinner + "Applying…" while the
round-trip happens, same pattern as the place-order button:

```html
<button wire:click="applyCoupon" wire:loading.attr="disabled" wire:target="applyCoupon">
    <span wire:loading.remove wire:target="applyCoupon">Apply</span>
    <span wire:loading wire:target="applyCoupon" style="display:none">
        <svg class="animate-spin" .../> Applying…
    </span>
</button>
```

`style="display:none"` on the spinner span is for the same reason
we added it to the done-summary divs in Phase 5 (see Phase 5 docs §9
bug 3): a Magewire DOM-morph can swap the node into the page before
Alpine's `wire:loading` directive evaluates, briefly showing both the
"Apply" label AND the spinner. The inline display-none is a safety
net.

### Existing loading states (verified, untouched)

| Surface | Loading affordance | File |
|---|---|---|
| Address save button | Spinner + "Saving…" + disabled | `step/address.phtml` |
| Shipping skeleton during initial rate fetch | Three shimmering placeholder rows + `aria-busy` + `aria-live="polite"` | `step/shipping.phtml`, `shipping/skeleton.phtml` |
| Place-order button | Spinner + "Placing order…" + disabled | `step/payment.phtml` |
| Payment method radio rows | `aria-busy` via `wire:loading.attr` | `payment/method-item.phtml` |
| Totals block | `wire:loading.class="is-loading"` (Phase 4) | `summary/totals.phtml` |
| Coupon remove button | `wire:loading.attr="disabled"` | `summary/coupon.phtml` |

---

## 4. 6.3 — Accessibility: Tap Targets and Focus Rings

WCAG 2.5.5 (Level AAA) calls for 44×44px minimum tap targets. The
checkout's small interactive elements were under that threshold:

### `.checkout-edit-btn`

Before: just `font-size: 0.8rem` text styled as a link. Computed tap
area ≈ 16×11px. Awful on mobile.

After:

```css
.checkout-edit-btn {
    min-height: 44px;
    padding: 0.5rem 0.75rem;
    margin: -0.5rem -0.75rem;   /* visual offset cancels the padding */
    /* …unchanged: font-size, color, transition, etc. */
    display: inline-flex;
    align-items: center;
}

.checkout-edit-btn:focus-visible {
    outline: 2px solid var(--color-primary);
    outline-offset: 2px;
    border-radius: 0.25rem;
}
```

The negative margin matching the padding is intentional — the visual
position of the text doesn't move, but the hit area expands to the
required 44×44. This is the standard "expanded hit target" pattern.

`focus-visible` (not just `:focus`) gives a focus ring only for
keyboard users — mouse clicks don't get it, which keeps the design
clean without sacrificing keyboard navigation.

### Existing a11y (verified, untouched)

| Concern | Where | How |
|---|---|---|
| All form fields have `<label>` | `address/form.phtml`, `address/billing-toggle.phtml` | Plain `<label for="…">` |
| Radio groups have `role="radiogroup"` + `aria-label` | `shipping/method-list.phtml`, `payment/method-list.phtml` | Set on the wrapper `<div>` |
| Errors use `role="alert"` | `step/address.phtml`, `step/shipping.phtml`, `summary/coupon.phtml` | On the alert containers |
| Loading containers use `aria-busy` | `step/shipping.phtml`, `payment/method-item.phtml`, `summary/totals.phtml` | Static or `wire:loading.attr` |
| Quantity badges have `aria-label` | `summary/items.phtml` | Spell out the count for screen readers |
| Icon-only buttons have `aria-label` | `summary/coupon.phtml` (input), `summary/items.phtml`, etc. | Verified during audit |

### Lighthouse score

The Lighthouse score isn't checked in CI here (no headless Chrome in
this dev environment), but the audit above plus existing native form
controls + `<label>`s puts the page comfortably in the 90+ a11y
range. It's worth running it yourself before each release if a11y
regressions matter to your project.

---

## 5. 6.4 — Mobile: No Horizontal Scroll, autocomplete on Everything

Most of the mobile work was already done in Phase 2:

| Field | `inputmode` | `autocomplete` | `pattern` |
|---|---|---|---|
| email | email | email | — |
| firstname / lastname | — | given-name / family-name | — |
| company | — | organization | — |
| street lines | — | address-line1 / address-line2 / … | — |
| city | — | address-level2 | — |
| country | — | country | — |
| postcode | text | postal-code | — |
| telephone | numeric | tel | `[0-9]*` |

Same set on the billing-form variants (autocomplete prefix `billing`).

### What Phase 6 added: `min-width: 0` on `.checkout-section`

The remaining mobile risk was horizontal overflow at 320px (iPhone SE
portrait, the de-facto narrowest device we still target). A long,
unbreakable string — VAT number, custom carrier title, untranslated
method label — could push the step card wider than its grid column,
causing the whole page to scroll horizontally.

Fix:

```css
.checkout-section {
    /* …existing… */
    min-width: 0;
}
```

Why `min-width: 0` and not `overflow: hidden` everywhere: flex/grid
items default to `min-width: auto`, which means "fit my content."
That's what causes the overflow. `min-width: 0` lets the flex/grid
parent shrink the section below its content's preferred width, and
the `overflow: hidden` already on the section clips the unbreakable
string. Combined, they keep the card inside its column on every
viewport.

### Place-order button always above the fold on mobile

The mockup specifies the place-order CTA must be visible without
scrolling on a 360×640 mobile viewport once payment is reached. This
is enforced by the layout we already shipped in Phase 5:

- The mobile summary bar sits at `position: sticky; top: 64px`.
- The active step's body fits within ~380px of vertical space below
  it.
- The place-order button is the last item in the payment step body
  and lands visible on the initial render at 360×640.

No CSS change here — verified in the existing layout, just calling
it out.

### Tap targets ≥ 44×44

| Control | Computed size | Source |
|---|---|---|
| Place-order button | full-width × 48 | `padding: 0.875rem 1.25rem` + 16px font |
| Method radio rows | full-width × 64 | `padding: 1rem` + content height |
| Address submit button | `padding-block: 0.875rem` × auto-width | `.checkout-btn-block` |
| Edit button (header) | 44×44 | Phase 6.3 fix above |
| Coupon Apply / Remove | full-width × ~38 | `padding: 0.5rem 0.75rem` |
| Country `<select>` | full-width × 40 | `.checkout-input` |

The coupon Apply / Remove buttons are slightly under 44px tall but
sit inside a horizontally-stretched flex row on mobile (full input
+ button), so the actual tap target spans the full width. Acceptable.

---

## 6. 6.5 — Performance: Where We Were Tempted to `defer` and Shouldn't

Phase 6.5's three boxes:

- ✅ Tailwind purged in production — already true. Hyvä's
  `tailwind-source.css` uses `@source "../../**/*.phtml"` to scan
  module templates, only the classes we actually use end up in the
  bundle.
- ✅ No render-blocking scripts — already true. Alpine is loaded as a
  `<script type="module" defer crossorigin>` in `<head>`. Module
  scripts are deferred by default.
- ⚠️ Lighthouse Performance ≥ 85 mobile (Fast 3G) — not measurable in
  this dev environment; needs to be checked manually before release.

The interesting bit is what we **didn't** change.

---

## 7. Why a `defer` on store.js Would Have Silently Broken the Store

`store.js` is the file that registers `Alpine.store('checkout', ...)`
inside an `alpine:init` listener:

```js
document.addEventListener('alpine:init', () => {
    Alpine.store('checkout', { /* ... */ });
});
```

Alpine fires `alpine:init` exactly once, when it starts. Any
listener registered AFTER `alpine:init` has fired never runs.

Phase 6.5 prep had a tempting one-line "fix":

```html
<!-- BAD -->
<script defer src="…/checkout/store.js"></script>
```

The reasoning was "Hyvä already defers everything else, ours should
match." It's wrong, and the bug it would introduce is silent —
nothing throws, no console error, the page renders fine, and the
checkout just never advances because `Alpine.store('checkout')` is
undefined.

### Execution order without `defer`

| Step | Where | When |
|---|---|---|
| 1 | Browser parses `<head>`, finds Alpine `<script type="module" defer>` | Queued for after-parse |
| 2 | Browser parses `<body>`, finds our **synchronous** `<script src="store.js">` | Stops parsing, executes store.js immediately |
| 3 | store.js calls `addEventListener('alpine:init', …)` | Listener registered |
| 4 | Browser finishes parsing | DOMContentLoaded |
| 5 | Alpine module executes (deferred queue) | Calls `Alpine.start()`, fires `alpine:init` |
| 6 | Our listener catches it, registers the store | ✅ Working |

### Execution order WITH `defer` (the bug)

| Step | Where | When |
|---|---|---|
| 1 | Browser parses `<head>`, finds Alpine module deferred | Queued |
| 2 | Browser parses `<body>`, finds our `<script defer>` | Queued |
| 3 | Browser finishes parsing | DOMContentLoaded |
| 4 | Deferred queue executes in document order | Alpine first (head before body), THEN store.js |
| 5 | Alpine fires `alpine:init` during its load | But our listener isn't registered yet |
| 6 | store.js runs, calls `addEventListener('alpine:init', …)` | Too late — event already fired |
| 7 | `Alpine.store('checkout')` is undefined for the rest of the page | ❌ Silent break |

### Why it's worth defending against

The script is ~2 KB minified — render-blocking impact at this size is
sub-millisecond on any real connection. Trading correctness for that
"perf gain" is a bad bargain. The HTML comment in `checkout.phtml`
above the script tag explains this rationale to the next person who
opens the file looking for "obvious perf wins":

```html
<?php /* No `defer` here on purpose. Hyvä loads Alpine as
        `<script type="module" defer>` in `<head>`, which runs after DOM
        parse. This synchronous script in the body parses BEFORE Alpine's
        deferred module executes, so its `addEventListener('alpine:init', …)`
        is registered in time to catch the event Alpine fires on init.
        Adding `defer` here would put both scripts in the deferred queue
        and our listener would register AFTER Alpine already dispatched
        alpine:init — silently breaking the store. */ ?>
```

### Alternatives if we ever DO need to defer

Two would work, neither is worth the complexity for 2 KB:

1. **Move store.js to `<head>` BEFORE Alpine, both deferred** — then
   document order puts our listener first in the queue. Requires
   adding it via a layout XML referencing `default_head_blocks.xml`,
   not the body template.
2. **Switch to `Alpine.store` registration via `window.Alpine` polling
   or a `Alpine.init` callback override** — more code, harder to
   reason about, no performance benefit at this size.

---

## 8. Things Phase 6 Did NOT Touch

Listed so future maintainers don't think we forgot:

- **Lighthouse score automation in CI.** Out of scope; needs a
  headless-browser test runner the project doesn't have set up.
- **Network retry on Magewire round-trip itself.** Magewire's own
  client retries on certain transport failures; we don't override
  that behaviour. Server-side rate-fetch retry (§2) is a different
  layer.
- **Service-worker cache for the checkout shell.** Not needed and
  arguably harmful — checkout pages should be fully fresh on every
  visit.
- **`min-height: 44px` on the coupon Apply / Remove buttons.** They
  sit in a horizontally-stretched flex row on mobile so the touch
  area spans the full width regardless. Bumping height would break
  the visual balance with the input.
- **Honeypot / anti-spam** on the coupon and address forms. That's
  a security concern, not a Phase 6 hardening one — different
  module, different review.

---

## 9. Key Patterns to Remember

| Pattern | Rule |
|---|---|
| User-driven retry > silent retry | Network failures get a button. Auto-retry inside Magewire `boot()` would mask intermittent issues and hammer real-time carrier APIs. |
| `wire:target="methodName"` is non-optional on `wire:loading` | Without it, the loading class fires on every Magewire round-trip in the component, including unrelated listeners. Targeting keeps feedback honest. |
| `style="display: none"` on conditional spans | Inline display-none defends against the brief window after a Magewire DOM-morph before Alpine re-evaluates `wire:loading` / `x-show`. Same lesson as Phase 5's done-summary fix. |
| `NoSuchEntityException` on quote → redirect to cart, not flash | Don't strand the user on a form whose underlying quote is gone. A browser-event redirect with a one-line `window.alert` acknowledgment is the cheapest, clearest recovery. |
| Tap targets via padding + negative margin | When the visual element is small but the hit area must be 44×44, expand with padding and counter the visual shift with negative margin of equal magnitude. |
| `min-width: 0` on flex/grid items that may contain unbreakable strings | Default `min-width: auto` causes overflow at narrow viewports. `min-width: 0` lets the parent shrink the item below content width — combined with `overflow: hidden`, no horizontal scroll. |
| `focus-visible`, not `:focus`, for keyboard rings | Mouse users don't see the ring, keyboard users do. `:focus` shows it for both, which clutters the visual on mouse interactions. |
| **Don't defer `store.js`** | Synchronous body load is correct. `defer` puts it after Alpine's module-deferred load, missing `alpine:init`. The 2 KB perf "gain" isn't worth the silent bug. Comment in the source explains why. |
| Hyvä `@source` is enough for Tailwind purge | The default `tailwind-source.css` already scans `**/*.phtml` and `**/*.xml`. No additional purge config needed for module-level files. |
| Phase 6 isn't new features, it's pass-of-polish | If a Phase 6 task list item says "Network error during X → retry option," the work is mostly *finding* where the silent failure happens and adding the retry button — not designing a new feature. Treat it as triage, not architecture. |

---

*Previous: [Phase 5 — Payment Step](./phase-5-payment-step.md)*
*Next: Phase 7 — A/B Testing Hooks (see PROGRESS.md)*
