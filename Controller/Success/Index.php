<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Controller\Success;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\UrlInterface;

class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly PageFactory $pageFactory,
        private readonly CheckoutSession $checkoutSession,
        private readonly RedirectFactory $redirectFactory,
        private readonly UrlInterface $url,
    ) {}

    public function execute(): ResultInterface
    {
        $orderId = $this->checkoutSession->getLastOrderId();

        if (!$orderId) {
            $redirect = $this->redirectFactory->create();
            $redirect->setUrl($this->url->getUrl('checkout/cart'));
            return $redirect;
        }

        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set(__('Order Confirmation'));

        return $page;
    }
}
