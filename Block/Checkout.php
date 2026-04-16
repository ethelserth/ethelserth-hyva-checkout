<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Block;

use Ethelserth\Checkout\Model\Step\Pool;
use Ethelserth\Checkout\Model\Step\StepInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

class Checkout extends Template
{
    public function __construct(
        Context $context,
        private readonly Pool $stepPool,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /** @return StepInterface[] */
    public function getSteps(): array
    {
        return $this->stepPool->getSteps();
    }

    public function getStepsJson(): string
    {
        $steps = array_map(
            fn(StepInterface $s) => [
                'name'     => $s->getName(),
                'label'    => $s->getLabel(),
                'order'    => $s->getOrder(),
                'unlockOn' => $s->getUnlockOn(),
            ],
            $this->getSteps()
        );

        return json_encode(array_values($steps), JSON_THROW_ON_ERROR);
    }
}
