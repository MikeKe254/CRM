<?php

declare(strict_types=1);

namespace App\Twig;

use App\Support\DomainHelper;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class DomainExtension extends AbstractExtension
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly DomainHelper $domains,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('currentDomain', $this->currentDomain(...)),
            new TwigFunction('currentSubdomain', $this->currentSubdomain(...)),
            new TwigFunction('isAdminHost', $this->isAdminHost(...)),
        ];
    }

    public function currentDomain(): string
    {
        $request = $this->requestStack->getCurrentRequest();

        return $request ? $this->domains->getBaseDomain($request) : 'localhost';
    }

    public function currentSubdomain(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();

        return $request ? $this->domains->getSubdomain($request) : null;
    }

    public function isAdminHost(): bool
    {
        $request = $this->requestStack->getCurrentRequest();

        return $request ? $this->domains->isAdminHost($request) : false;
    }
}
