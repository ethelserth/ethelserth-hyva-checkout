# Phase 0 & 1 — Foundation, Scaffold, and Core Architecture

> **Who this is for:** A developer who understands PHP and has touched Magento before,
> but hasn't built a module from scratch on the Hyva + Magewire stack.
> Every decision here is explained: what it is, why we made it, where it lives, and how it fits together.

---

## Table of Contents

1. [The Big Picture — What Are We Building?](#1-the-big-picture)
2. [Technology Stack Decisions](#2-technology-stack-decisions)
3. [Phase 0 — Environment Setup](#3-phase-0--environment-setup)
4. [Phase 0.4 — Module Scaffold](#4-phase-04--module-scaffold)
5. [Phase 1.1 — Routing and Controller](#5-phase-11--routing-and-controller)
6. [Phase 1.2 — The Step Configuration System](#6-phase-12--the-step-configuration-system)
7. [Phase 1.3 — Root Layout, Alpine Store, and Magewire Bridge](#7-phase-13--root-layout-alpine-store-and-magewire-bridge)
8. [Phase 1.4 — Quote and Session Services](#8-phase-14--quote-and-session-services)
9. [Payment Adapter Interface (Phase 5 preview)](#9-payment-adapter-interface)
10. [Bugs We Hit and Why](#10-bugs-we-hit-and-why)
11. [Key Principles to Remember](#11-key-principles-to-remember)

---

## 1. The Big Picture

Magento ships with a checkout called "Luma checkout". It is built on KnockoutJS and RequireJS —
two technologies that are slow, hard to maintain, and completely incompatible with the Hyva theme.

We are replacing it entirely. Our module (`Ethelserth_Checkout`) intercepts the `/checkout` route
before Magento's native checkout module runs, renders its own page, and owns everything from
address entry to order confirmation.

The result is a checkout that:
- Loads fast (no KnockoutJS bundle, no RequireJS)
- Is easy to read and modify (Alpine.js + Tailwind, no magic framework)
- Runs all business logic server-side (Magewire)
- Can be deployed on any Hyva store without per-installation licensing

---

## 2. Technology Stack Decisions

### Why Magewire for server logic?

Magewire is a PHP port of Laravel Livewire for Magento. When a user interacts with a component
(clicks a button, submits a form), Magewire sends a POST request to `/__magewire/update`,
re-runs the PHP component method, and returns an HTML diff that patches the DOM.

**The server is always the source of truth.** The quote, the address, shipping rates, tax —
all of this lives in Magento's database. Magewire lets us manipulate it from PHP and return
the updated HTML in one round trip, without ever writing a REST API endpoint or managing
JavaScript state.

```
CORRECT flow:
  User clicks "Save address"
    → Magewire POST fires
    → PHP Address::saveAddress() runs
    → QuoteService saves to DB
    → Magento's VatValidator fires (VAT group assigned)
    → Component re-renders with updated totals
    → Browser patches DOM

WRONG flow:
  User clicks "Save address"
    → JavaScript calls fetch('/rest/V1/carts/mine/shipping-information')
    → JavaScript parses the response
    → JavaScript manually updates 6 different UI elements
    → JavaScript hopes it got everything right
```

### Why Alpine.js for display?

Alpine.js handles **visual state only**: which step is active, CSS transitions, show/hide toggles.
It never reads or writes cart data. This separation keeps Alpine small and predictable.

### Why Tailwind CSS?

Hyva's build pipeline already compiles Tailwind. Our templates use Tailwind classes and they
get purged automatically in production. Zero extra build setup.

---

## 3. Phase 0 — Environment Setup

### Installing Magewire

```bash
composer require magewirephp/magewire:"^1.4"
bin/magento module:enable Magewirephp_Magewire
bin/magento setup:upgrade
```

**Why `^1.4`?** The caret means "1.4 or higher but below 2.0". Magewire 1.x is the
Magento-native version. Version 2.x exists but changes the API significantly.

**What `setup:upgrade` does:** Registers the module in `setup_module` table, runs any
`InstallSchema`/`UpgradeSchema` scripts, and clears generated code. Always run it after
enabling a new module.

### Verifying the Hyva theme

The active theme must extend `Hyva/default`. We confirmed this by reading
`app/code/RedGoat/Theme/theme.xml`:

```xml
<parent>Hyva/default</parent>
```

This means our module's templates will be picked up by Hyva's Tailwind scanner,
and Alpine.js is already loaded on every page.

---

## 4. Phase 0.4 — Module Scaffold

### Where modules live

All custom modules go under `app/code/{Vendor}/{ModuleName}/`. Ours is:

```
app/code/Ethelserth/Checkout/
```

`Ethelserth` is the vendor namespace. `Checkout` is the module name.
Together they form the Magento module name: `Ethelserth_Checkout`.

### `registration.php` — the entry point

```php
ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'Ethelserth_Checkout',
    __DIR__
);
```

**What it does:** Tells Magento "there is a module called `Ethelserth_Checkout` and its files
live at this directory path". Magento scans all `registration.php` files on every request
(cached after first run). Without this file, the module does not exist as far as Magento is concerned.

### `etc/module.xml` — identity and load order

```xml
<module name="Ethelserth_Checkout" setup_version="1.0.0">
    <sequence>
        <module name="Magento_Checkout"/>
        <module name="Hyva_Theme"/>
        <module name="Magewirephp_Magewire"/>
    </sequence>
</module>
```

**What `sequence` means:** "Load my module's configuration AFTER these modules."
This is critical for layout XML merging — we need our layout to be processed after Magento's
checkout and Hyva's checkout, so our `remove` directives actually remove their blocks.
Without the correct sequence, layout XML can be merged in the wrong order.

### `composer.json` — dependency declaration

```json
{
    "name": "ethelserth/hyva-checkout",
    "require": {
        "hyva-themes/magento2-theme-module": "^1.3",
        "hyva-themes/magento2-default-theme": "^1.3",
        "magewirephp/magewire": "^1.4"
    }
}
```

This file serves two purposes:
1. Documents dependencies so anyone installing this module knows what it needs
2. Enables the module to be distributed via Composer packages

### Enabling the module

```bash
bin/magento module:enable Ethelserth_Checkout
bin/magento setup:upgrade
bin/magento setup:di:compile
```

**Why `di:compile`?** Magento's Dependency Injection system generates proxy classes and
factory classes for every class that uses constructor injection. If you skip this step,
Magento falls back to runtime compilation which is much slower — and in some configurations
won't work at all. Always run it after adding new PHP classes.

---

## 5. Phase 1.1 — Routing and Controller

### How Magento routing works

When a request hits `/checkout`, Magento walks through registered routers looking for a match.
The "standard" router maps URLs to controllers using the pattern:
`/{frontName}/{controller}/{action}`

So `/checkout` resolves to the `index` action of the `Index` controller for whatever module
has registered the `checkout` frontName.

### `etc/frontend/routes.xml`

```xml
<route id="checkout" frontName="checkout">
    <module name="Ethelserth_Checkout" before="Magento_Checkout"/>
</route>
```

**The key part:** `before="Magento_Checkout"`. This tells Magento's router to check our module
first when resolving the `checkout` frontName. If our controller handles the request, Magento's
native checkout controller never runs. This is how we intercept without monkey-patching.

Note: This file lives in `etc/frontend/` not `etc/` — the `frontend` subdirectory means
this configuration only applies to the storefront (not admin, not API).

### `Controller/Index/Index.php`

```php
class Index implements HttpGetActionInterface
{
    public function execute(): ResultInterface
    {
        $quote = $this->checkoutSession->getQuote();

        if (!$quote->hasItems()) {
            // Redirect empty cart to cart page
            $redirect = $this->redirectFactory->create();
            $redirect->setUrl($this->url->getUrl('checkout/cart'));
            return $redirect;
        }

        return $this->pageFactory->create();
    }
}
```

**Why `HttpGetActionInterface`?** In Magento 2.3+, controllers should implement action
interfaces instead of extending `Action`. It makes the intent explicit and allows the
framework to enforce correct HTTP methods. GET requests get `HttpGetActionInterface`.

**Why redirect on empty cart?** Without this guard, a user navigating directly to `/checkout`
with no items would see a broken checkout page trying to work with an empty quote.
Always guard against invalid state at the controller level.

---

## 6. Phase 1.2 — The Step Configuration System

This is the most architecturally interesting part of Phase 1. Understanding it well will
make every future phase easier.

### The problem it solves

We have three checkout steps: Address, Shipping, Payment. But we want this checkout to be
deployable across multiple client stores where some clients might need extra steps
(gift wrapping, loyalty points, custom fields). We also want to allow steps to be reordered
or disabled per client — without modifying the core module.

The solution: steps are registered via XML files, just like Magento registers its own config
(routes, DI, layout, etc.). Any module can drop a `Config/checkout_steps.xml` file and add,
remove, or reorder steps.

### `etc/checkout_steps.xsd` — the schema

```xml
<xs:complexType name="stepType">
    <xs:attribute name="name" use="required"/>      <!-- unique key: "address" -->
    <xs:attribute name="label" use="optional"/>     <!-- "Delivery" -->
    <xs:attribute name="magewire" use="optional"/>  <!-- PHP class for the component -->
    <xs:attribute name="template" use="optional"/>  <!-- phtml template path -->
    <xs:attribute name="order" use="optional"/>     <!-- sort order: 10, 20, 30 -->
    <xs:attribute name="unlock_on" use="optional"/> <!-- Magewire event that unlocks this step -->
    <xs:attribute name="disabled" use="optional"/>  <!-- true to remove this step -->
</xs:complexType>
```

The XSD schema defines what a valid `checkout_steps.xml` looks like. If a module provides
a malformed config, Magento (or any XML validator) can detect it immediately.

### `Config/checkout_steps.xml`

```xml
<step name="address"  order="10"/>
<step name="shipping" order="20" unlock_on="addressSaved"/>
<step name="payment"  order="30" unlock_on="shippingMethodSelected"/>
```

**Where this file lives:** `Config/` inside the module root — NOT in `etc/`. This is intentional:
Magento's standard config reader (`ModuleDirReader::getConfigurationFiles`) only looks in `etc/`.
By putting it in `Config/`, we use our own reader and avoid collisions with Magento's config system.

**Why `unlock_on`?** This is the event name that Alpine.js listens for to advance past the
previous step. `shipping` unlocks when `addressSaved` fires. `payment` unlocks when
`shippingMethodSelected` fires. This drives the progressive disclosure UX.

### `Model/Step/Config.php` — the reader

```php
$modulePaths = $this->componentRegistrar->getPaths(ComponentRegistrar::MODULE);

foreach ($modulePaths as $modulePath) {
    $file = $modulePath . '/Config/' . $this->configFile;
    if (!file_exists($file)) {
        continue;
    }
    // parse XML, merge step data...
}
```

**Why `ComponentRegistrarInterface` not `ModuleDirReader`?**

We learned this the hard way. `ModuleDirReader::getConfigurationFiles($filename)` internally
calls `getModuleDir(self::MODULE_ETC_DIR, $moduleName)` — it only looks in `etc/` directories.
`ComponentRegistrarInterface::getPaths()` returns the root path of every registered module,
letting us look in any subdirectory we want.

**Lesson:** When Magento's built-in file readers don't match your file location, go one level
lower and use `ComponentRegistrarInterface` to get raw module paths.

### `Model/Step/Pool.php` — the sorted registry

```php
public function getSteps(): array
{
    $data = $this->config->getStepsData();
    $steps = [];

    foreach ($data as $item) {
        if (!empty($item['disabled'])) continue;
        $steps[] = $this->stepFactory->create([...]);
    }

    usort($steps, fn($a, $b) => $a->getOrder() <=> $b->getOrder());
    return $this->sorted = $steps;
}
```

**Why a separate Pool from Config?** Config reads and merges raw data from XML files.
Pool turns that data into typed `Step` objects and sorts them. Single responsibility:
Config knows about files, Pool knows about the step collection. This makes testing easier
and the code more readable.

**Why `StepFactory`?** Magento's ObjectManager requires you to use generated factories
for instantiating objects with constructor arguments at runtime. We don't call `new Step()`
directly — we call `$this->stepFactory->create([...])`. This allows Magento's DI system
to intercept creation and inject dependencies.

### `Block/Checkout.php`

```php
public function getStepsJson(): string
{
    $steps = array_map(
        fn(StepInterface $s) => [
            'name'     => $s->getName(),
            'unlockOn' => $s->getUnlockOn(),
        ],
        $this->getSteps()
    );

    return json_encode(array_values($steps), JSON_THROW_ON_ERROR);
}
```

**Why JSON?** The Alpine.js store needs to know the step list to manage transitions.
The block encodes the steps as JSON, the template drops it inline into the Alpine `init()` call.
This is the server-to-client handoff for step configuration.

---

## 7. Phase 1.3 — Root Layout, Alpine Store, and Magewire Bridge

### Magento layout XML fundamentals

Magento builds every page from a tree of blocks. The tree is defined in layout XML files.
Layout XML from ALL modules is merged together for each page handle (like `checkout_index_index`).
Theme layout files are merged on top of module layout files.

Our file: `view/frontend/layout/checkout_index_index.xml`

The handle `checkout_index_index` matches the URL `/checkout/index/index` which is the same
as `/checkout`. Magento maps `{frontName}/{controller}/{action}` → `{frontName}_{controller}_{action}`.

### Removing Hyva's fallback block

The Hyva default theme ships with this in its own `checkout_index_index.xml`:

```xml
<block class="Magento\Framework\View\Element\Text" name="fallback.module.missing">
    <arguments>
        <argument name="text">No Checkout module installed...</argument>
    </arguments>
</block>
```

This renders unless we remove it. The fix:

```xml
<referenceBlock name="fallback.module.missing" remove="true"/>
```

**Why `remove="true"` and not `setTemplate("")`?**
`remove="true"` on a `<referenceBlock>` is processed during layout tree generation, before
any block is instantiated. The block is removed from the tree entirely — it never gets
created. `setTemplate("")` tries to set a property on an already-instantiated block; it
renders an empty string but still runs the constructor and `toHtml()`. `remove="true"` is cleaner.

### Wiring Magewire components — the correct pattern

**Wrong approach (what we initially tried):**
```php
// In the template
echo $block->getLayout()
    ->createBlock(\Magento\Framework\View\Element\Template::class)
    ->setTemplate($step->getTemplate())
    ->toHtml();
```
This creates a plain template block. Magewire has no idea it should manage it.

**Correct approach — layout XML with `magewire` argument:**
```xml
<block class="Magento\Framework\View\Element\Template"
       name="checkout.step.address"
       template="Ethelserth_Checkout::checkout/step/address.phtml">
    <arguments>
        <argument name="magewire" xsi:type="object">
            Ethelserth\Checkout\Magewire\Step\Address
        </argument>
    </arguments>
</block>
```

Magewire has a plugin on Magento's block generation. When it sees a block with a `magewire`
argument, it bootstraps the component: assigns a `wire:id`, serializes the component's public
properties into the DOM, and sets up the AJAX lifecycle. Without this argument, Magewire
simply doesn't know the block exists.

Then in the template:
```php
echo $block->getChildHtml('checkout.step.' . $step->getName());
```

**Lesson:** Magewire components MUST be declared in layout XML with the `magewire` argument.
You cannot wire them at runtime by passing a template to a plain block.

### The Alpine store

```javascript
Alpine.store('checkout', {
    steps: [],
    currentStep: null,
    doneSteps: [],

    init(steps) {
        this.steps = steps;
        this.currentStep = steps[0]?.name ?? null;
    },

    isActive(name) { return this.currentStep === name; },
    isDone(name)   { return this.doneSteps.includes(name); },
    isLocked(name) {
        // A step is locked if the step before it is not yet done
        const idx = this.steps.findIndex(s => s.name === name);
        if (idx <= 0) return false;
        return !this.isDone(this.steps[idx - 1].name);
    },

    advance(from, to) {
        this.doneSteps.push(from);
        this.currentStep = to;
    },

    reopenStep(name) {
        // Remove this step and everything after it from doneSteps
        const idx = this.steps.findIndex(s => s.name === name);
        const toRemove = this.steps.slice(idx).map(s => s.name);
        this.doneSteps = this.doneSteps.filter(n => !toRemove.includes(n));
        this.currentStep = name;
    },
});
```

**Why `Alpine.store()` instead of `x-data` on the root element?**
`Alpine.store()` creates a global singleton. Any component anywhere on the page can read and
write `$store.checkout`. If we used `x-data` on the root div, child components would need
to be nested inside it — which doesn't work once Magewire starts replacing parts of the DOM.
A global store survives DOM patches.

**Why is the store initialized with PHP data?**
```html
<div x-init="$store.checkout.init(<?= $escaper->escapeHtmlAttr($stepsJson) ?>)">
```
The PHP `Block/Checkout.php` encodes the step list as JSON. The template drops it inline.
Alpine reads it on `x-init`. This means the store always reflects whatever the server's
step configuration says — no hardcoding step names in JavaScript.

### The Magewire-to-Alpine bridge

```html
<div
    @address-saved.window="$store.checkout.advance('address', 'shipping')"
    @shipping-method-selected.window="$store.checkout.advance('shipping', 'payment')"
    @step-edit-requested.window="$store.checkout.reopenStep($event.detail[0])"
    @order-placed.window="window.location.href = '/checkout/success'"
>
```

**How Magewire events work:** When a Magewire component calls `$this->emit('addressSaved')`,
Magewire dispatches a browser Custom Event on `window` with the name converted to kebab-case:
`address-saved`. Alpine's `@address-saved.window` listener catches it.

**Why on `window`?** Magewire dispatches to `window` so any listener on the page can hear it,
regardless of DOM position. If we listened on the component element directly, it might miss
events when Magewire patches that part of the DOM.

**Critical HTML gotcha we hit:** You CANNOT put HTML comments inside an opening HTML tag:

```html
<!-- WRONG — this breaks the HTML parser -->
<div
    x-data
    <!-- This is a comment -->
    @click="doSomething"
>

<!-- CORRECT -->
<!-- This is a comment -->
<div
    x-data
    @click="doSomething"
>
```

When the browser's HTML parser hits `<` inside an attribute list (from `<!--`), it terminates
the tag immediately. Everything after the `<` becomes text content instead of attributes.
That's exactly why we saw the `@address-saved.window="..."` text printed on the page.

---

## 8. Phase 1.4 — Quote and Session Services

### Why a dedicated QuoteService?

Magewire components could call Magento's quote repositories directly. We deliberately don't
allow that. All quote mutations go through `Model/Quote/QuoteService.php`.

**Why?**
1. **Single place to add cross-cutting concerns.** Want to log every address save? Add one line in `QuoteService::saveShippingAddress()`.
2. **Testable.** You can test QuoteService in isolation. Components become thin and boring.
3. **Prevents drift.** Without a service layer, every developer will call a slightly different combination of repositories and methods. After six months you have six different ways to save an address and three of them are wrong.

### The VatValidator hook

```php
public function collectTotals(Quote $quote): void
{
    $quote->collectTotals();
}
```

This one line is doing more than it looks. When `collectTotals()` runs, Magento fires the
event `sales_quote_address_collect_totals_before`. Magento's built-in `VatValidator` observer
listens to this event. It reads `vat_id` from the shipping address, pings the EU VIES SOAP
service, and assigns the quote to the appropriate customer group (which controls whether
0% intra-EU VAT is applied).

**The important lesson:** We didn't write any VAT validation code. We just save `vat_id` to
the address correctly and call `collectTotals()`. Magento handles the rest. This is the right
approach — never re-implement what Magento already does correctly.

### Session services

```
GuestSessionService    → wraps CheckoutSession
CustomerSessionService → wraps CheckoutSession + CustomerSession
```

Both expose `getQuote()`. Magewire components inject whichever they need and call
`getQuote()` — they don't care whether the customer is a guest or logged in.
This is the Strategy pattern: the session strategy is swappable, the component code is identical.

---

## 9. Payment Adapter Interface

We built this in Phase 1 even though payments are Phase 5, because the `MethodPool`
constructor is injected into `Payment.php` (Phase 1's Magewire component). We had to
define the interface to avoid a crash.

```php
interface AdapterInterface
{
    public function getMethodCode(): string;
    public function isAvailable(Quote $quote): bool;
    public function afterPlaceOrder(Quote $quote): ?string;
    public function getJsAssets(): array;
}
```

**Why `afterPlaceOrder()` returns `?string`?**
For offline methods (bank transfer, cash), after placing the order we just redirect to the
confirmation page — return `null`. For PSPs (Viva Wallet, Revolut), after placing the order
we need to redirect to their hosted payment page — return the URL. The adapter decides.

**The `Offline` adapter problem we hit:**

We initially put `Offline` in `di.xml` as:
```xml
<item name="offline" xsi:type="object">Ethelserth\Checkout\Model\Payment\Adapter\Offline</item>
```

Magento's ObjectManager tried to instantiate it with no arguments. But `Offline::__construct()`
requires `$methodCode` and `$title`. Crash.

**Lesson:** `xsi:type="object"` in di.xml tells the ObjectManager to call the constructor
with no arguments (only injecting DI dependencies). If your class has required non-DI arguments
(like a method code string), you cannot wire it this way. It must be instantiated programmatically
via `$objectManager->create(Offline::class, ['methodCode' => $code, ...])` inside `MethodPool`.

The `adapters` array in di.xml is only for PSP adapters (Viva, Revolut) that have zero
primitive constructor arguments — their constructor receives only DI-injected services.

---

## 10. Bugs We Hit and Why

### Bug: `@address-saved.window` rendered as visible text

**Root cause:** HTML comment `<!-- ... -->` inside an opening HTML tag.

```html
<!-- BROKE everything: -->
<div
    x-data
    <!-- Magewire → Alpine bridge -->
    @address-saved.window="..."
>
```

The `<` in `<!--` terminates the tag parser. Alpine attributes became page text.

**Fix:** Remove comments from inside opening tags. Comments before or after the tag are fine.

### Bug: "No Checkout module installed"

**Root cause:** Hyva's `checkout_index_index.xml` in the default theme always adds a
`fallback.module.missing` block to the content container. Since theme XML is merged after
module XML, it appears alongside our content.

**Fix:** `<referenceBlock name="fallback.module.missing" remove="true"/>` in our layout file.

### Bug: Blank page (steps not rendering)

**Root cause:** `Model/Step/Config.php` used `ModuleDirReader::getConfigurationFiles()` which
only scans `etc/` directories. Our `checkout_steps.xml` lives in `Config/`. Zero files found,
zero steps, empty foreach, blank page.

**Fix:** Switch to `ComponentRegistrarInterface::getPaths()` which gives us each module's root
path. We then look for `Config/checkout_steps.xml` ourselves.

### Bug: Magewire components not wiring

**Root cause:** We created step blocks with `$block->getLayout()->createBlock(Template::class)->setTemplate(...)`.
This creates a plain Template block with no Magewire awareness.

**Fix:** Declare step blocks in layout XML with `<argument name="magewire" xsi:type="object">ComponentClass</argument>`.
Magewire's layout plugin detects this argument and bootstraps the component lifecycle.

### Bug: `Offline` adapter constructor crash

**Root cause:** `Offline` was in `di.xml` as `xsi:type="object"`. ObjectManager tried to
construct it with no arguments but `$methodCode` is required.

**Fix:** Remove `Offline` from di.xml. `MethodPool` creates `Offline` instances programmatically
via `$objectManager->create()` with explicit arguments.

---

## 11. Key Principles to Remember

| Principle | What it means in practice |
|---|---|
| Server owns state | Never store cart data in JavaScript. Use Magewire for all quote operations. |
| Magewire needs layout XML | Components MUST have `magewire` argument in layout XML. You cannot wire them from PHP code at runtime. |
| `remove="true"` before `setTemplate("")` | `remove="true"` eliminates the block from the tree. `setTemplate("")` just renders nothing. |
| `ComponentRegistrarInterface` for custom dirs | For config files outside `etc/`, use `getPaths()` not `getConfigurationFiles()`. |
| `di:compile` after every class change | Magento generates proxy and factory classes. Skip `di:compile` and you get runtime errors or slow fallback compilation. |
| `xsi:type="object"` has no constructor args | Objects wired via di.xml `xsi:type="object"` get only DI-injected dependencies. Scalar args must come from `create()` calls. |
| HTML comments never inside tags | `<!-- -->` inside `<div ...>` terminates the HTML tag parser. Attributes become text. |
| Collect totals fires VatValidator | Call `QuoteService::collectTotals()` after saving address. You don't need to call VatValidator manually — it fires automatically. |

---

*Next: [Phase 2 — Address Step](./phase-2-address-step.md)*
