<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\ViewModel;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Read-only config bridge for the "subscribe to newsletter" checkbox
 * that lives above the place-order button.
 *
 * Three gates, ALL must pass for the checkbox to render:
 *   1. The Magento_Newsletter module is enabled on the install.
 *   2. Magento's own `newsletter/subscription/allow_guest_subscribe`
 *      flag is on (the native admin path under
 *      `Stores > Config > Customers > Newsletter > Subscription Options`).
 *   3. Our own `ethelserth_checkout/newsletter/enabled` toggle is on
 *      (so a merchant can keep newsletters globally enabled but hide
 *      the checkout opt-in if they collect consent some other way).
 *
 * The GDPR notice copy lives at `ethelserth_checkout/newsletter/notice`
 * — translatable, defaults to a generic line. Merchants in EU
 * jurisdictions can override with their privacy-policy link.
 */
class NewsletterConfig implements ArgumentInterface
{
    private const PATH_OWN_ENABLED        = 'ethelserth_checkout/newsletter/enabled';
    private const PATH_OWN_LABEL          = 'ethelserth_checkout/newsletter/label';
    private const PATH_OWN_NOTICE         = 'ethelserth_checkout/newsletter/notice';
    private const PATH_NATIVE_GUEST_ALLOW = 'newsletter/subscription/allow_guest_subscribe';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ModuleManager $moduleManager,
    ) {}

    public function isEnabled(): bool
    {
        if (!$this->moduleManager->isEnabled('Magento_Newsletter')) {
            return false;
        }
        if (!$this->scopeConfig->isSetFlag(self::PATH_NATIVE_GUEST_ALLOW, ScopeInterface::SCOPE_STORE)) {
            return false;
        }
        return $this->scopeConfig->isSetFlag(self::PATH_OWN_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    public function getLabel(): string
    {
        $configured = trim((string) $this->scopeConfig->getValue(self::PATH_OWN_LABEL, ScopeInterface::SCOPE_STORE));
        return $configured !== '' ? $configured : (string) __('Subscribe to our newsletter');
    }

    public function getNotice(): string
    {
        $configured = trim((string) $this->scopeConfig->getValue(self::PATH_OWN_NOTICE, ScopeInterface::SCOPE_STORE));
        return $configured !== '' ? $configured : (string) __(
            'By subscribing you agree to receive marketing emails from us. '
            . 'We process your email solely to send these messages and you can unsubscribe at any time. '
            . 'See our privacy policy for details.'
        );
    }
}
