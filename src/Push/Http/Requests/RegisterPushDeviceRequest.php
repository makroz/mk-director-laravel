<?php

declare(strict_types=1);

namespace Mk\Director\Push\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Mk\Director\Push\Models\MkPushDevice;

/**
 * El registro de un teléfono. Lista blanca: el controller lee sólo
 * `validated()`, así que un `owner_id` en el body no llega a ningún lado.
 */
final class RegisterPushDeviceRequest extends FormRequest
{
    /** La autenticación la pone el middleware de las rutas; el dueño, el controller. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'provider' => ['required', 'string', Rule::in(MkPushDevice::PROVIDERS)],
            // El largo de la columna: más largo no entra en el índice único.
            'address' => ['required', 'string', 'max:512'],
            'platform' => ['required', 'string', Rule::in(MkPushDevice::PLATFORMS)],
        ];
    }
}
