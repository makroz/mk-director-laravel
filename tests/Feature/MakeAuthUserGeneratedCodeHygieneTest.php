<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\Concerns\RunsAuthUserScaffolder;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Lo que `mk:make:auth-user` deja en el repo del consumer tiene que describir
 * el código generado, no la historia del paquete.
 *
 * Hallazgo #44 del piloto NetPizza, medido con su barrido de docblocks huérfanos
 * sobre el `Operator` recién generado:
 *
 *  1. Docblocks HUÉRFANOS: «Profile fields per-scope» con sus `@property`
 *     flotando después de `$loginField` (fuera del docblock de la clase ninguna
 *     herramienta los lee), y «accessors `*_url`… si no hay file fields, este
 *     placeholder queda como whitespace» describiendo código que no se generó.
 *     El lector del método de abajo leía tres explicaciones y la suya última.
 *  2. ARQUEOLOGÍA: ids de tickets internos, fechas, nombres de consumers,
 *     «Pre-fix / Post-fix». Eso vive en el CHANGELOG del paquete; en el modelo
 *     de cada consumer es ruido que envejece.
 *  3. Comentarios que MIENTEN según los flags (el de `$casts` decía «queda solo
 *     `password`» con `status` adentro).
 *
 * Se mide sobre archivos GENERADOS en varias combinaciones de flags, no sobre
 * los stubs: los placeholders se expanden distinto según la combinación, y el
 * huérfano sólo aparece en algunas.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class, RunsAuthUserScaffolder::class);

afterEach(function () {
    $this->tearDownHttpApp();
    $this->cleanScaffolderTempDirs();
});

/**
 * Ids internos que no tienen que llegar al código del consumer. Salen de los
 * stubs y de los fragmentos que arma el comando (medido con `rg` sobre ambos).
 */
const INTERNAL_MARKER_PATTERN = '/\b(R-PKG-\d+|R-P-\d+|R-MK-\d+|R-G-\d+|R-AD-\d+|FEEDBACK\d*|BUG-NEW-\d+|BUG-\d+|PKG-NEW-\d+|F\d+-[A-Z]\d+|LAR-\d+|BACK-\d+|RBAC-\d+|R-PKG-NEW|HALLAZGO[-\w]*|MEJORA-[\w-]*\d+|OBS-[\w-]*\d+|ADR-\d+|SDD|S8 Fase \d+)\b|\bRETO\b|NetPizza|Condaty|\bMario\b|\b20\d\d-\d\d-\d\d\b|Pre-fix|Post-fix|v1\.\d+\.\d+(-rc\d+)?/';

/**
 * Docblocks sin elemento debajo: seguidos de OTRO docblock, del cierre de un
 * bloque, o del fin del archivo. Mismo criterio que el barrido de NetPizza
 * (tokenizador, no regex: un `/**` dentro de un string no es un docblock), más
 * el caso del cierre de clase.
 *
 * @return array<int, string>
 */
function orphanDocblocks(string $file): array
{
    $orphans = [];
    $pending = null;

    foreach (token_get_all((string) file_get_contents($file)) as $token) {
        if (! is_array($token)) {
            if ($pending !== null && $token === '}') {
                $orphans[] = "línea {$pending}: docblock seguido del cierre del bloque";
            }
            if (trim($token) !== '') {
                $pending = null;
            }

            continue;
        }

        [$id, , $line] = $token;
        if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_ATTRIBUTE) {
            continue;
        }
        if ($id === T_DOC_COMMENT) {
            if ($pending !== null) {
                $orphans[] = "línea {$pending}: docblock seguido de otro docblock (línea {$line})";
            }
            $pending = $line;

            continue;
        }
        $pending = null;
    }

    if ($pending !== null) {
        $orphans[] = "línea {$pending}: docblock al final del archivo";
    }

    return $orphans;
}

test('autoprueba del detector: ve un huérfano y no marca un docblock sano con atributo', function () {
    $fixture = sys_get_temp_dir().'/mk-orphan-fixture-'.uniqid().'.php';
    file_put_contents($fixture, "<?php\nclass Fixture\n{\n    /** huérfano */\n    /** de intruso */\n    public function intruder(): void {}\n\n    /** sano */\n    #[\\Deprecated]\n    public function healthy(): void {}\n\n    /** al cierre */\n}\n");

    $orphans = orphanDocblocks($fixture);
    unlink($fixture);

    expect($orphans)->toHaveCount(2);
    expect($orphans[0])->toStartWith('línea 4:');
    expect($orphans[1])->toStartWith('línea 12:');
});

test('generado: sin docblocks huérfanos, sin ids internos, sin clases propias faltantes, y todo el PHP compila', function (array $args) {
    [$exit, $output, $base] = $this->runScaffolderInTempDir($args);
    expect($exit)->toBe(0, $output);

    $problems = [];
    foreach ((new Filesystem)->allFiles($base.'/app/Modules', true) as $file) {
        $path = $file->getPathname();
        $relative = $file->getRelativePathname();

        if (preg_match_all(INTERNAL_MARKER_PATTERN, $file->getContents(), $m)) {
            $problems[] = "{$relative}: ids internos ".implode(', ', array_unique($m[0]));
        }

        if ($file->getExtension() !== 'php') {
            continue;
        }

        foreach (orphanDocblocks($path) as $orphan) {
            $problems[] = "{$relative}: {$orphan}";
        }

        // Toda clase del PROPIO módulo que un archivo importa o nombra con
        // `::class` tiene que haberse generado: si no, el endpoint que la usa es
        // un 500 «Class not found» (pasó con `AssignAccessRequest`).
        $scope = $args['scope'];
        preg_match_all('/(?:^use\s+|\\\\)App\\\\Modules\\\\'.$scope.'\\\\([\\w\\\\]+?)(?:;|::class)/m', $file->getContents(), $refs);
        foreach (array_unique($refs[1]) as $classPath) {
            if (! is_file($base."/app/Modules/{$scope}/".str_replace('\\', '/', $classPath).'.php')) {
                $problems[] = "{$relative}: referencia `App\\Modules\\{$scope}\\{$classPath}`, que no se generó";
            }
        }

        exec('php -l '.escapeshellarg($path).' 2>&1', $lint, $code);
        if ($code !== 0) {
            $problems[] = "{$relative}: no compila — ".implode(' ', $lint);
        }
        $lint = [];
    }

    expect($problems)->toBe([], implode("\n", $problems));
})->with([
    'manager default (CRUD + RBAC + status)' => [['scope' => 'Operator']],
    '--no-crud' => [['scope' => 'Operator', '--no-crud' => true]],
    '--no-status --no-rbac' => [['scope' => 'Operator', '--no-status' => true, '--no-rbac' => true]],
    'consumer' => [['scope' => 'Mesero', '--kind' => 'consumer', '--managed-by' => 'Admin']],
    'profile field :file + login ci' => [['scope' => 'Operator', '--profile-fields' => 'avatar:file,!dni', '--login-field' => 'ci']],
    '--verify-email --with-register --with-permissions-endpoint' => [['scope' => 'Operator', '--verify-email' => true, '--with-register' => true, '--with-permissions-endpoint' => true]],
    '--two-factor=required' => [['scope' => 'Operator', '--two-factor' => 'required']],
    '--multi-tenant --no-crud' => [['scope' => 'Operator', '--multi-tenant' => true, '--no-crud' => true]],
]);

test('modelo: los @property de los profile fields viven en el docblock de la CLASE', function () {
    [$exit, $output, $base] = $this->runScaffolderInTempDir(['scope' => 'Operator', '--no-crud' => true, '--profile-fields' => 'birthdate:date']);
    expect($exit)->toBe(0, $output);

    $model = (string) file_get_contents($base.'/app/Modules/Operator/Models/Operator.php');
    $classDocblock = substr($model, 0, (int) strpos($model, 'class Operator extends AuthUser'));

    expect($classDocblock)->toContain('@property \\Carbon\\Carbon|null $birthdate');
    expect(substr($model, (int) strpos($model, 'class Operator extends AuthUser')))->not->toContain('@property');
});

test('modelo: el docblock de $casts no afirma contenidos que dependen de los flags', function () {
    [$exit, $output, $base] = $this->runScaffolderInTempDir(['scope' => 'Operator', '--no-crud' => true]);
    expect($exit)->toBe(0, $output);

    $model = (string) file_get_contents($base.'/app/Modules/Operator/Models/Operator.php');
    preg_match('#/\*\*((?:(?!\*/).)*)\*/\s*protected \$casts#s', $model, $m);

    expect($m[1] ?? '')->not->toBe('');
    expect($m[1])->not->toContain('queda solo');
    expect($model)->toContain("'status' => \\App\\Modules\\Operator\\Enums\\OperatorStatus::class");
});
