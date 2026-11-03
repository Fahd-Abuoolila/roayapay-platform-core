<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Tests\Fixtures;

use Illuminate\Http\JsonResponse;
use RoayaPay\PlatformCore\Attributes\AllowsNonStandardResponse;

final class ExemptController
{
    #[AllowsNonStandardResponse(reason: 'Provider webhook requires {"received": true}')]
    public function webhook(): JsonResponse
    {
        return response()->json(['received' => true]);
    }

    public function normal(): JsonResponse
    {
        return response()->json(['received' => true]);
    }
}
