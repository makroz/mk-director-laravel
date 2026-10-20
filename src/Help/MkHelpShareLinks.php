<?php

declare(strict_types=1);

namespace Mk\Director\Help;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mk\Director\Help\Models\MkHelpShareLink;
use RuntimeException;

/**
 * Los enlaces públicos y temporales a los manuales: crear, resolver, revocar
 * y listar los de un creador.
 *
 * 🔴 El token crudo (`Str::random(48)`) existe SÓLO en la URL que devuelve
 * `create()`; la tabla guarda su sha256. Un enlace vencido o revocado se
 * resuelve igual que uno que no existe: null, sin decir cuál de los tres es.
 *
 * Qué target puede pedir quién (las audiencias de su scope) lo valida el
 * request: acá sólo se arma y se guarda.
 */
final class MkHelpShareLinks
{
    /**
     * @return array{link: MkHelpShareLink, url: string} el token crudo sólo vive en `url`
     *
     * @throws InvalidArgumentException con `hours` fuera de 1..`share.max_hours`
     * @throws RuntimeException sin `share.public_url`: un enlace sin página no sirve
     */
    public function create(Model $creator, string $target, int $hours): array
    {
        $max = (int) config('mk_director.help.share.max_hours', 720);

        if ($hours < 1 || $hours > $max) {
            throw new InvalidArgumentException("Un enlace dura entre 1 y {$max} horas.");
        }

        $base = (string) config('mk_director.help.share.public_url', '');

        if ($base === '') {
            throw new RuntimeException('Falta mk_director.help.share.public_url (MK_HELP_SHARE_URL): la página pública de los enlaces.');
        }

        $token = Str::random(48);
        $link = MkHelpShareLink::query()->create([
            'token_hash' => hash('sha256', $token),
            'target' => $target,
            'created_by_type' => $creator->getMorphClass(),
            'created_by_id' => (string) $creator->getKey(),
            'expires_at' => Carbon::now()->addHours($hours),
            'uses' => 0,
        ]);

        return ['link' => $link, 'url' => rtrim($base, '/').'/'.$token];
    }

    /**
     * El enlace vivo de ese token, con su uso contado; null si no existe, venció
     * o lo revocaron.
     */
    public function resolve(string $token): ?MkHelpShareLink
    {
        $link = MkHelpShareLink::query()
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', Carbon::now())
            ->first();

        if ($link === null) {
            return null;
        }

        // `uses = uses + 1` en SQL: dos visitas a la vez no se pisan.
        $link->increment('uses', 1, ['last_used_at' => Carbon::now()]);

        return $link;
    }

    /**
     * Revoca un enlace DEL creador. false si el id no es suyo (o no existe):
     * nadie revoca el enlace de otro. Revocar uno ya revocado no cambia la fecha.
     */
    public function revoke(Model $creator, string $id): bool
    {
        $link = MkHelpShareLink::query()->createdBy($creator)->whereKey($id)->first();

        if ($link === null) {
            return false;
        }

        if ($link->revoked_at === null) {
            $link->forceFill(['revoked_at' => Carbon::now()])->save();
        }

        return true;
    }

    /** @return Collection<int, MkHelpShareLink> los del creador, el más nuevo primero */
    public function forCreator(Model $creator): Collection
    {
        return MkHelpShareLink::query()->createdBy($creator)->latest()->orderByDesc('id')->get();
    }
}
