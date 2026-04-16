<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Model\Step;

class Step implements StepInterface
{
    public function __construct(
        private readonly string $name,
        private readonly string $label,
        private readonly string $magewireClass,
        private readonly string $template,
        private readonly int $order,
        private readonly ?string $unlockOn = null,
        private readonly bool $disabled = false,
    ) {}

    public function getName(): string       { return $this->name; }
    public function getLabel(): string      { return $this->label; }
    public function getMagewireClass(): string { return $this->magewireClass; }
    public function getTemplate(): string   { return $this->template; }
    public function getOrder(): int         { return $this->order; }
    public function getUnlockOn(): ?string  { return $this->unlockOn; }
    public function isDisabled(): bool      { return $this->disabled; }
}
