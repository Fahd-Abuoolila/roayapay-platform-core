<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Attributes;

use Attribute;
use InvalidArgumentException;

/**
 * Explicitly exempts a controller (class) or action (method) from the RoayaPay
 * response contract, e.g. a provider webhook that must answer in the provider's
 * own format. A non-empty reason is mandatory and is logged for review.
 *
 *     #[AllowsNonStandardResponse(reason: 'Acme webhook requires {"received": true}')]
 *     public function acmeWebhook(Request $request) { ... }
 *
 * The enforcement middleware reads it with reflection once per controller
 * action and memoizes the result (class metadata only, never request data).
 * The static scanner honours it too.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class AllowsNonStandardResponse
{
    public function __construct(public string $reason)
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('#[AllowsNonStandardResponse] requires a reason.');
        }
    }
}
