<?php

declare(strict_types=1);

namespace Mk\Director\Push\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\Push\Http\Requests\RegisterPushDeviceRequest;
use Mk\Director\Push\Models\MkPushDevice;

/**
 * Registrar y desregistrar el teléfono del usuario autenticado. Rutas opt-in:
 * `mk_director.push.register_routes`.
 */
final class MkPushDeviceController
{
    /**
     * Registra este teléfono, o lo pasa al usuario autenticado si era de otro
     * (cambió la sesión en el mismo celular). Idempotente.
     *
     * Es un `upsert` sobre el único `(provider, address)` y no un
     * buscar-y-guardar: dos registros simultáneos del mismo teléfono no pueden
     * dejar dos filas, la base lo impide.
     */
    public function store(RegisterPushDeviceRequest $request): Response
    {
        $owner = $this->owner($request);
        $data = $request->validated();

        MkPushDevice::query()->upsert([[
            'id' => (string) Str::uuid(),
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => (string) $owner->getKey(),
            'provider' => $data['provider'],
            'address' => $data['address'],
            'platform' => $data['platform'],
            'last_seen_at' => now(),
        ]], ['provider', 'address'], ['owner_type', 'owner_id', 'platform', 'last_seen_at']);

        return response()->noContent();
    }

    /**
     * Desregistra un teléfono PROPIO.
     *
     * 🔴 Una dirección ajena da 404, igual que una que no existe, y no borra
     * nada. Con un 403 la respuesta diría «ese teléfono existe y es de otro»;
     * y sin el filtro por dueño cualquiera con sesión podría dejar sin avisos
     * el teléfono de otra persona.
     */
    public function destroy(Request $request, string $address): Response
    {
        $deleted = MkPushDevice::query()
            ->ownedBy($this->owner($request))
            ->where('address', $address)
            ->delete();

        abort_if($deleted === 0, 404);

        return response()->noContent();
    }

    /**
     * El dueño es SIEMPRE el usuario autenticado. Falla cerrado: el
     * `route_middleware` por defecto es `['api']`, sin auth, y un consumer que
     * se olvide de sumarle `mk.auth:{scope}` no puede terminar con teléfonos
     * sin dueño.
     */
    private function owner(Request $request): AuthUser
    {
        $user = $request->user();

        abort_unless($user instanceof AuthUser, 401);

        return $user;
    }
}
