<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Illuminate\Console\OutputStyle;
use Mk\Director\Console\Commands\MakeAuthUserCommand;
use Mk\Director\Tests\MkLaravelTestCase;
use ReflectionClass;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * La Policy que emite `--with-crud` tiene que poder EJECUTARSE.
 *
 * ── 🔴 POR QUE HIZO FALTA ESTE ARCHIVO ──────────────────────────────────
 *
 * Mientras `CRUDSmart` no invocaba la Policy, lo que el scaffolder escribiera
 * adentro daba igual: era codigo muerto. Con el enganche puesto
 * (`features.authorize_with_policy`), lo que emite el stub PASA A CORRER — y
 * el piloto de NetPizza mostro que lo que emitia no podia funcionar en un
 * scope gestionado:
 *
 *   1. Tipaba `{Scope} $user`. En un scope `consumer` el CRUD lo rutea el
 *      MANAGER (`Http/Routes/managed.php`, `mk.auth:{manager}`), asi que el
 *      usuario autenticado es del manager. Medido: 500 TypeError,
 *      `Argument #1 ($user) must be of type Mesero, Admin given`.
 *
 *   2. Preguntaba por `{scope}.{recurso}.{accion}`, la familia del
 *      AUTOSERVICIO. Las rutas managed piden `{manager}.{recurso}.{accion}`.
 *      Aun con el tipo arreglado, la Policy habria denegado a TODO usuario
 *      del manager correctamente configurado.
 *
 * 🔴 SE GENERA DE VERDAD Y SE LEE EL ARCHIVO. La alternativa —source-parsing
 * del comando, buscando el string del placeholder— ya nos mordio: matchea una
 * forma que puede estar bien escrita y mal resuelta. Acá se corre
 * `generateCrudPack()` contra un tempdir y se afirma sobre el CONTENIDO
 * generado, que es lo que el consumer va a tener en su repo.
 */
uses(MkLaravelTestCase::class);

function makePolicyScaffoldCommand(string $tempDir): MakeAuthUserCommand
{
    $command = new class extends MakeAuthUserCommand
    {
        public string $testBasePath = '';

        protected function modulesPath(string $moduleName = ''): string
        {
            return $moduleName === ''
                ? $this->testBasePath
                : $this->testBasePath.'/'.$moduleName;
        }

        // `base_path()` necesita una Application Laravel completa, que este
        // paquete no instala. No-op, igual que MakeAuthUserKindConsumerTest.
        protected function registerProviderInBootstrap(string $providerFqcn, ?string $afterProvider = null): void
        {
            // no-op en tests: no debe tocar bootstrap/providers.php real.
        }
    };

    $command->testBasePath = $tempDir;
    $command->setOutput(new OutputStyle(new StringInput(''), new NullOutput));

    return $command;
}

function invocarProtegido(object $command, string $method, array $args): mixed
{
    return (new ReflectionClass($command))->getMethod($method)->invoke($command, ...$args);
}

/**
 * Genera el pack CRUD de un scope y devuelve el contenido de su Policy.
 */
function policyGenerada(string $scope, string $scopeLower, string $scopePlural, ?string $managedBy): string
{
    $tempDir = sys_get_temp_dir().'/mk-policy-scaffold-'.uniqid();
    mkdir("{$tempDir}/{$scope}/Providers", 0755, true);
    mkdir("{$tempDir}/{$scope}/Http/Routes", 0755, true);

    $command = makePolicyScaffoldCommand($tempDir);

    invocarProtegido($command, 'generateStub', [
        $scope, $scopeLower, $scopePlural, 'email',
        'auth-user.service-provider.stub', 'Providers', "{$scope}ServiceProvider.php", [],
    ]);

    invocarProtegido($command, 'generateCrudPack', [
        $scope, $scopeLower, $scopePlural, 'email', [], [], true, false, [],
        $managedBy !== null, $managedBy,
    ]);

    $contenido = (string) file_get_contents("{$tempDir}/{$scope}/Policies/{$scope}Policy.php");

    exec('rm -rf '.escapeshellarg($tempDir));

    return $contenido;
}

// ─────────────────────────────────────────────────────────────────────────────

test('🔴 la Policy de un scope GESTIONADO pregunta por las abilities del MANAGER', function () {
    $policy = policyGenerada('Member', 'member', 'members', managedBy: 'Admin');

    // Positiva Y negativa: la familia correcta esta, y la equivocada NO.
    // Sin la negativa, el test quedaria verde si el stub emitiera las dos.
    expect($policy)->toContain("canMk('admin.members.viewAny')")
        ->and($policy)->toContain("canMk('admin.members.delete')")
        ->and($policy)->not->toContain('member.members.');
});

test('🔴 la Policy tipa AuthUser: en un scope gestionado el usuario es del MANAGER', function () {
    $policy = policyGenerada('Member', 'member', 'members', managedBy: 'Admin');

    expect($policy)->toContain('use Mk\Director\Auth\Models\AuthUser;')
        ->and($policy)->toContain('public function before(AuthUser $user, string $ability): ?bool')
        ->and($policy)->toContain('public function viewAny(AuthUser $user): bool')
        // El modelo sigue tipado en el argumento de la FILA — eso si es de
        // este scope. Lo que no puede ser `Member` es el USUARIO.
        ->and($policy)->toContain('public function view(AuthUser $user, Member $model): bool')
        ->and($policy)->not->toContain('function before(Member $user');
});

test('un scope NO gestionado sigue preguntando por las suyas (BC)', function () {
    $policy = policyGenerada('Admin', 'admin', 'admins', managedBy: null);

    expect($policy)->toContain("canMk('admin.admins.viewAny')")
        ->and($policy)->toContain('public function before(AuthUser $user, string $ability): ?bool');
});

/*
|--------------------------------------------------------------------------
| El stub tiene que DECIR que la Policy no corre sola. Es la otra mitad del
| hallazgo: el consumer ve el archivo en su repo y asume que esta vivo.
|--------------------------------------------------------------------------
*/
test('la Policy generada documenta que hay que prender authorize_with_policy', function () {
    $policy = policyGenerada('Admin', 'admin', 'admins', managedBy: null);

    expect($policy)->toContain('authorize_with_policy');
});
