<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Concerns;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Schema\Builder as SchemaBuilder;

/**
 * UsesDatabase — conexión sqlite :memory: REAL para los tests que la necesitan.
 *
 * Spec: Comunicaciones Fase 1, PR 0.
 *
 * POR QUÉ EXISTE
 * --------------
 * `MkLaravelTestCase::bootContainer()` bootea el Capsule a propósito SIN
 * `addConnection()` (ver su comentario en el binding de `db`): los unit tests
 * del paquete mockean el Schema facade y no necesitan conexión. Eso funcionó
 * mientras lo único que se testeaba era config y forma del código.
 *
 * No alcanza para `mk_media` / reacciones / comentarios. El estilo dominante
 * del paquete es el "source-grep test" (leer el .php como string y assertear
 * `toContain`), y ese estilo NO PUEDE verificar:
 *  - que una constraint UNIQUE efectivamente rechace el duplicado,
 *  - que una transacción haga rollback,
 *  - que un `cascadeOnDelete` borre los hijos,
 *  - que `SoftDeletes` escriba `deleted_at` en vez de borrar la fila.
 * Grepear la palabra "unique" en una migración prueba que alguien la escribió,
 * no que la base la respete. El peor bug del módulo legacy que estamos
 * reemplazando (contador de likes desincronizado para siempre por falta de
 * UNIQUE + transacción) es exactamente de esa familia.
 *
 * ES OPT-IN A PROPÓSITO
 * ---------------------
 * No se toca `MkLaravelTestCase`. Los 1122 tests existentes dependen de que el
 * `db` del container NO tenga conexión y de que `db.schema` sea el stub
 * encadenable; darles una conexión real rompería los mocks de Schema en
 * silencio. Sólo el test que hace `uses(UsesDatabase::class)` paga el costo.
 *
 * USO
 * ---
 *   uses(TestCase::class, UsesDatabase::class);
 *
 *   beforeEach(function () {
 *       $this->setUpDatabase();
 *       $this->runPackageMigration('2026_07_19_000001_create_mk_media_table.php');
 *   });
 */
trait UsesDatabase
{
    protected ?Capsule $capsule = null;

    /**
     * Levanta sqlite en memoria y la cablea al Container ya booteado por
     * MkLaravelTestCase::setUp(). Llamar desde beforeEach/setUp del test.
     */
    protected function setUpDatabase(): void
    {
        $container = Container::getInstance();

        $capsule = new Capsule($container);
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            // sqlite arranca con las foreign keys APAGADAS. Sin esto, un
            // `->constrained()->cascadeOnDelete()` no borra nada y el test que
            // verifica el cascade pasa en verde sin haber probado nada.
            'foreign_key_constraints' => true,
        ], 'testing');
        $capsule->getDatabaseManager()->setDefaultConnection('testing');
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        // `:memory:` vive DENTRO de la conexión PDO: cada reconexión estrena una
        // base vacía. Pineamos la instancia en el container para que `DB::` y
        // `Schema::` resuelvan siempre ESTA conexión, no una nueva.
        $manager = $capsule->getDatabaseManager();
        $container->instance('db', $manager);
        $container->instance('db.schema', $manager->connection('testing')->getSchemaBuilder());

        $this->capsule = $capsule;
    }

    /**
     * Corre el `up()` de una migración del paquete contra la conexión de test.
     *
     * Las migraciones del paquete son clases anónimas (`return new class extends
     * Migration`), así que `require` devuelve la instancia y se la puede invocar
     * directo. Evita tener que bootear el Migrator, que arrastraría la tabla
     * `migrations`, un repositorio y un resolver de rutas que este paquete no
     * tiene.
     */
    protected function runPackageMigration(string $filename): void
    {
        $path = __DIR__.'/../../src/Database/Migrations/'.$filename;

        if (! is_file($path)) {
            throw new \RuntimeException("Migración de paquete inexistente: {$path}");
        }

        $migration = require $path;
        $migration->up();
    }

    protected function schema(): SchemaBuilder
    {
        return $this->capsule->getConnection('testing')->getSchemaBuilder();
    }

    /**
     * Suelta la conexión y desengancha Eloquent. Llamar desde afterEach.
     *
     * Sin el `unsetConnectionResolver()`, el resolver queda apuntando a un
     * Capsule muerto y el SIGUIENTE test que use Eloquent explota con un error
     * que no tiene nada que ver con lo que ese test estaba probando.
     */
    protected function tearDownDatabase(): void
    {
        if ($this->capsule !== null) {
            $this->capsule->getDatabaseManager()->disconnect('testing');
            $this->capsule = null;
        }

        EloquentModel::unsetConnectionResolver();
    }
}
