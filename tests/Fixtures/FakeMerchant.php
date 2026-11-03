<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

final class FakeMerchant extends Model
{
    protected $table = 'merchants';
}
