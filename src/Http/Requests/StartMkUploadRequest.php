<?php

declare(strict_types=1);

namespace Mk\Director\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * El arranque de una subida directa. Lista blanca: el controller lee sólo
 * `validated()`, así que un `owner_id` o una `key` en el body no llegan a ningún
 * lado. Tipo y tamaño contra el perfil los valida `MkDirectUpload`.
 */
final class StartMkUploadRequest extends FormRequest
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
            'profile' => ['required', 'string', 'max:100'],
            'filename' => ['required', 'string', 'max:255'],
            'bytes' => ['required', 'integer', 'min:1'],
            'mime' => ['required', 'string', 'max:255'],
        ];
    }
}
