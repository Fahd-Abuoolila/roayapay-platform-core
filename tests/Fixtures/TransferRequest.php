<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;

final class TransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->input('deny') !== true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:1'],
            'currency' => ['required', 'in:EGP,USD'],
        ];
    }
}
