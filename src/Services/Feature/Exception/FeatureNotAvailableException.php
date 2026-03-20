<?php

declare(strict_types=1);

namespace App\Services\Feature\Exception;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Thrown when a company's plan does not include the requested feature,
 * and no active tenant override grants access.
 *
 * Extends AccessDeniedHttpException so Symfony automatically maps it to a
 * 403 response — but it has its own type so the exception handler or the
 * controller can catch it specifically and show an "upgrade your plan" prompt.
 *
 * Example catch in a controller:
 *
 *   try {
 *       $this->features->require($session, TenantFeatureAccessService::FEATURE_STK_PUSH);
 *   } catch (FeatureNotAvailableException $e) {
 *       return $this->render('upgrade.html.twig', ['feature' => $e->featureSlug]);
 *   }
 */
final class FeatureNotAvailableException extends AccessDeniedHttpException
{
    public function __construct(
        public readonly string $featureSlug,
        string                 $message = '',
    ) {
        parent::__construct(
            $message ?: "Feature '{$featureSlug}' is not available on your current plan.",
        );
    }
}
