<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Model\Step;

interface StepInterface
{
    public function getName(): string;
    public function getLabel(): string;
    public function getMagewireClass(): string;
    public function getTemplate(): string;
    public function getOrder(): int;
    public function getUnlockOn(): ?string;
    public function isDisabled(): bool;
}
