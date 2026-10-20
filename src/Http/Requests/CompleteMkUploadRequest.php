<?php

declare(strict_types=1);

namespace Mk\Director\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** El cierre de una subida directa: el ETag de cada parte, en orden (10.000 es el tope de partes de S3). */
final class CompleteMkUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'etags' => ['required', 'array', 'min:1', 'max:10000'],
            'etags.*' => ['required', 'string', 'max:100'],
        ];
    }
}
