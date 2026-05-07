<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\ViewModel;

use Magento\CheckoutAgreements\Api\CheckoutAgreementsListInterface;
use Magento\CheckoutAgreements\Api\Data\AgreementInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Read-only bridge to Magento's native terms-and-conditions
 * (Sales > Terms and Conditions admin section).
 *
 * The data + admin UI come from `Magento_CheckoutAgreements` — we
 * don't build a parallel one. Two gates:
 *   1. Module is enabled on the install (some lean Magento builds
 *      strip it out).
 *   2. `checkout/options/enable_agreements` flag is on (native admin
 *      path under `Stores > Config > Sales > Checkout > Checkout Options`).
 *      We read it directly via `ScopeConfigInterface` rather than
 *      through `Magento\CheckoutAgreements\Helper\Data` — the helper
 *      was removed in recent Magento versions and isn't a stable API.
 *
 * Agreements come back via `CheckoutAgreementsListInterface::getList()`
 * which already filters to active rows for the current store. Our
 * Magewire component validates "all required agreements were checked"
 * before placing the order.
 */
class TermsConfig implements ArgumentInterface
{
    private const PATH_ENABLED = 'checkout/options/enable_agreements';

    public function __construct(
        private readonly ModuleManager $moduleManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly CheckoutAgreementsListInterface $agreementsList,
    ) {}

    public function isEnabled(): bool
    {
        if (!$this->moduleManager->isEnabled('Magento_CheckoutAgreements')) {
            return false;
        }
        return $this->scopeConfig->isSetFlag(self::PATH_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    /** @return AgreementInterface[] */
    public function getAgreements(): array
    {
        if (!$this->isEnabled()) {
            return [];
        }
        try {
            return $this->agreementsList->getList();
        } catch (\Throwable $e) {
            // Don't take the checkout down because agreement loading
            // hiccupped — fall through to "no agreements." Place-
            // order validation will see an empty required-list and
            // proceed; better than blocking checkout entirely.
            return [];
        }
    }

    /**
     * IDs of every active agreement — used by the Magewire component
     * to check the shopper accepted all of them before placing the
     * order. Mirrors what `Magento\CheckoutAgreements\Model\AgreementsValidator`
     * does internally; we re-derive it so we don't need to plumb the
     * validator through the wire.
     *
     * @return int[]
     */
    public function getRequiredAgreementIds(): array
    {
        $ids = [];
        foreach ($this->getAgreements() as $agreement) {
            $id = (int) $agreement->getAgreementId();
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return $ids;
    }
}
