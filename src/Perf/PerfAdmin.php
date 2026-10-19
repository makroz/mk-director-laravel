<?php

declare(strict_types=1);

namespace Mk\Director\Perf;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mk\Director\Auth\Services\TokenIssuer;
use Mk\Director\Console\Concerns\ResolvesScopeModel;
use RuntimeException;

/**
 * The user the screens are measured as, created in the scratch database: the scope's model with the `'*'`
 * ability and a Sanctum token carrying `'*'` and the scope, which is what `mk.auth` and `mk.ability` require.
 * The package has no bypass, and the perf run does not add one.
 */
final class PerfAdmin
{
    use ResolvesScopeModel;

    /** @return string the plain-text bearer token */
    public function make(VolumeSeeder $seed, string $connection, string $scope = 'admin'): string
    {
        $class = $this->resolveScopeModel($scope);
        if (! class_exists($class) || ! is_subclass_of($class, Model::class) || ! method_exists($class, 'createToken')) {
            throw new RuntimeException("the auth scope {$scope} has no model with Sanctum tokens ({$class}).");
        }
        /** @var Model $model */
        $model = new $class;
        $table = $model->getTable();
        $columns = array_column(Schema::connection($connection)->getColumns($table), 'name');
        $row = array_merge($seed->row($connection, $table, 1, $model->getCasts()), array_intersect_key([
            'password' => Hash::make(Str::random(40)),
            'auth_scope' => $scope,
            'is_active' => true,
        ], array_flip($columns)));

        // The column decides, not the model: the seeder fills a uuid key and leaves an auto-increment one to the database.
        $key = $model->getKeyName();
        if (isset($row[$key])) {
            DB::connection($connection)->table($table)->insert($row);
            $id = $row[$key];
        } else {
            $id = DB::connection($connection)->table($table)->insertGetId($row, $key);
        }
        $user = $class::query()->withoutGlobalScopes()->findOrFail($id);
        if (method_exists($user, 'giveAbilityTo')) {
            $user->giveAbilityTo('*');
        }

        return $user->createToken('mk-studio-perf', ['*', TokenIssuer::SCOPE_ABILITY_PREFIX.$scope])->plainTextToken;
    }
}
