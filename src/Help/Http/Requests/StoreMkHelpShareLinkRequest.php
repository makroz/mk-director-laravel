<?php

declare(strict_types=1);

namespace Mk\Director\Help\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Mk\Director\Help\MkHelpCatalog;

/**
 * Crear un enlace público a un manual. Lista blanca: el controller lee sólo
 * `validated()`. El creador lo pone el controller; el permiso, también.
 *
 * - `target`: `slug:<slug>` de un artículo que el scope VE, o
 *   `manual:<audience>` de una audiencia del scope. Nadie comparte lo que no
 *   puede leer: el scope `admin` no comparte el manual `member`.
 * - `hours`: 1..`share.max_hours`. Las opciones (24 h, 7 días, 30 días) son
 *   cosa del front.
 */
final class StoreMkHelpShareLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'target' => ['required', 'string', 'max:255', $this->targetInScope(...)],
            'hours' => ['required', 'integer', 'min:1', 'max:'.(int) config('mk_director.help.share.max_hours', 720)],
        ];
    }

    private function targetInScope(string $attribute, mixed $value, Closure $fail): void
    {
        $scope = (string) $this->route()?->getAction('mk_help_scope');
        $audiences = array_values((array) config("mk_director.help.routes.scopes.{$scope}.audiences", []));
        [$kind, $name] = array_pad(explode(':', (string) $value, 2), 2, '');

        $ok = match ($kind) {
            'slug' => $name !== '' && app(MkHelpCatalog::class)->article($name, $audiences) !== null,
            'manual' => in_array($name, $audiences, true),
            default => false,
        };

        if (! $ok) {
            $fail('Ese artículo o manual no existe.');
        }
    }
}
