<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Traits;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mk\Director\Enums\MkReactionType;
use Mk\Director\Models\MkReaction;
use Mk\Director\Tests\Concerns\UsesDatabase;
use Mk\Director\Tests\TestCase;
use Mk\Director\Traits\HasMkReactions;

uses(TestCase::class, UsesDatabase::class);

/** Contenido reaccionable, sin contador materializado (el default). */
class ReactablePost extends EloquentModel
{
    use HasMkReactions;

    protected $table = 'posts';

    public $timestamps = false;

    protected $guarded = [];
}

/** Contenido CON contador materializado. */
class ReactableCounted extends EloquentModel
{
    use HasMkReactions;

    protected $table = 'posts_counted';

    public $timestamps = false;

    protected $guarded = [];

    protected ?string $mkReactionsCountColumn = 'reactions_count';
}

/** Contenido con SoftDeletes — un soft delete NO debe llevarse las reacciones. */
class ReactableSoft extends EloquentModel
{
    use HasMkReactions;
    use SoftDeletes;

    protected $table = 'posts_soft';

    public $timestamps = false;

    protected $guarded = [];
}

/**
 * Fuerza el escenario de la CARRERA PERDIDA.
 *
 * `mkReactionQueryFor` miente la PRIMERA vez y devuelve una query que no
 * matchea nada, imitando al request que hace su SELECT justo antes de que el
 * otro inserte. El INSERT que sigue choca de verdad contra el UNIQUE de la
 * base — no hay mock de la excepción — y eso ejercita el catch + reintento de
 * `react()` con la falla real.
 */
class ReactableRacy extends ReactablePost
{
    public int $missesLeft = 1;

    protected function mkReactionQueryFor(EloquentModel $author): MorphMany
    {
        $query = parent::mkReactionQueryFor($author);

        if ($this->missesLeft > 0) {
            $this->missesLeft--;
            $query->whereRaw('1 = 0');
        }

        return $query;
    }
}

/** Autor con PK bigint. */
class ReactionAuthorBigint extends EloquentModel
{
    protected $table = 'authors_bigint';

    public $timestamps = false;

    protected $guarded = [];
}

/** Autor con PK uuid — el caso de RETO. */
class ReactionAuthorUuid extends EloquentModel
{
    protected $table = 'authors_uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];
}

beforeEach(function () {
    $this->setUpDatabase();
    $this->runPackageMigration('2026_07_20_000001_create_mk_reactions_table.php');

    Schema::create('posts', function (Blueprint $table): void {
        $table->id();
    });
    Schema::create('posts_counted', function (Blueprint $table): void {
        $table->id();
        $table->unsignedInteger('reactions_count')->default(0);
    });
    Schema::create('posts_soft', function (Blueprint $table): void {
        $table->id();
        $table->softDeletes();
    });
    Schema::create('authors_bigint', function (Blueprint $table): void {
        $table->id();
    });
    Schema::create('authors_uuid', function (Blueprint $table): void {
        $table->uuid('id')->primary();
    });
});

afterEach(function () {
    $this->tearDownDatabase();
});

// ─── El toggle ────────────────────────────────────────────────────────────────

it('crea la reacción la primera vez', function () {
    $post = ReactablePost::create([]);
    $author = ReactionAuthorBigint::create([]);

    $result = $post->react($author);

    expect($result['action'])->toBe('added')
        ->and($result['reaction'])->toBeInstanceOf(MkReaction::class)
        ->and($result['reaction']->type)->toBe(MkReactionType::Like)
        ->and($post->reactions()->count())->toBe(1);
});

it('saca la reacción al repetir el mismo tipo', function () {
    $post = ReactablePost::create([]);
    $author = ReactionAuthorBigint::create([]);

    $post->react($author);
    $result = $post->react($author);

    expect($result['action'])->toBe('removed')
        ->and($result['reaction'])->toBeNull()
        ->and($post->reactions()->count())->toBe(0);
});

it('reemplaza la reacción al mandar otro tipo, sin duplicar filas', function () {
    $post = ReactablePost::create([]);
    $author = ReactionAuthorBigint::create([]);

    $post->react($author, MkReactionType::Like);
    $result = $post->react($author, MkReactionType::Love);

    expect($result['action'])->toBe('switched')
        // 🔴 LO QUE IMPORTA: sigue habiendo UNA fila. Con `type` adentro del
        // UNIQUE habría dos y el contador diría 2 por una sola persona.
        ->and($post->reactions()->count())->toBe(1)
        ->and($post->reactions()->first()->type)->toBe(MkReactionType::Love);
});

it('deja que autores distintos reaccionen al mismo contenido', function () {
    $post = ReactablePost::create([]);
    $a = ReactionAuthorBigint::create([]);
    $b = ReactionAuthorBigint::create([]);

    $post->react($a);
    $post->react($b);

    expect($post->reactions()->count())->toBe(2);
});

it('distingue autores de tipos distintos con la misma key', function () {
    // Sin `author_type` en el UNIQUE, el Admin #1 y el Member #1 serían la
    // misma persona para la base.
    $post = ReactablePost::create([]);
    $bigint = ReactionAuthorBigint::create(['id' => 1]);
    $uuid = ReactionAuthorUuid::create(['id' => '1']);

    $post->react($bigint);
    $post->react($uuid);

    expect($post->reactions()->count())->toBe(2);
});

it('soporta autores con PK uuid', function () {
    $post = ReactablePost::create([]);
    $author = ReactionAuthorUuid::create(['id' => '9f1c8a4e-0000-4000-8000-000000000001']);

    $post->react($author);

    expect($post->hasReactionFrom($author))->toBeTrue()
        ->and($post->reactionFrom($author)->type)->toBe(MkReactionType::Like);
});

// ─── La garantía de la base ───────────────────────────────────────────────────

it('la base RECHAZA una segunda reacción del mismo autor', function () {
    // Éste es EL test del PR. Todo lo de arriba puede estar bien escrito y el
    // contador se desincroniza igual si la base acepta el duplicado.
    $post = ReactablePost::create([]);
    $author = ReactionAuthorBigint::create([]);

    $post->react($author);

    $insertDuplicado = fn () => DB::table('mk_reactions')->insert([
        'reactable_type' => $post->getMorphClass(),
        'reactable_id' => (string) $post->getKey(),
        'author_type' => $author->getMorphClass(),
        'author_id' => (string) $author->getKey(),
        'type' => MkReactionType::Love->value,
        'created_at' => '2026-07-20 00:00:00',
        'updated_at' => '2026-07-20 00:00:00',
    ]);

    expect($insertDuplicado)->toThrow(UniqueConstraintViolationException::class);
});

it('se recupera cuando pierde la carrera contra otro request', function () {
    $post = ReactableRacy::create([]);
    $author = ReactionAuthorBigint::create([]);

    // Ya hay una reacción puesta. La pone OTRA instancia de la MISMA clase:
    // con `ReactablePost` el `reactable_type` sería distinto, no habría
    // conflicto de UNIQUE y el test verificaría un escenario que no existe.
    $otroRequest = ReactableRacy::find($post->getKey());
    $otroRequest->missesLeft = 0;
    $otroRequest->react($author);

    // ...pero este toggle no la ve en su primer SELECT, intenta insertar y
    // choca contra el UNIQUE. El reintento sí la ve y la trata como toggle.
    $result = $post->react($author);

    expect($result['action'])->toBe('removed')
        ->and($post->missesLeft)->toBe(0)
        // Estado CONSISTENTE, no corrupto: cero filas, no dos.
        ->and($post->reactions()->count())->toBe(0);
});

// ─── Conteos ──────────────────────────────────────────────────────────────────

it('agrupa los conteos por tipo', function () {
    $post = ReactablePost::create([]);

    foreach (range(1, 3) as $i) {
        $post->react(ReactionAuthorBigint::create([]), MkReactionType::Like);
    }
    $post->react(ReactionAuthorBigint::create([]), MkReactionType::Love);

    expect($post->reactionCounts())->toBe([
        MkReactionType::Like->value => 3,
        MkReactionType::Love->value => 1,
    ]);
});

it('no materializa contador si el modelo no declaró columna', function () {
    // El default es NO materializar. Que esto no explote es el contrato: el
    // paquete no puede asumir que el modelo del consumer tenga la columna.
    $post = ReactablePost::create([]);

    expect(fn () => $post->react(ReactionAuthorBigint::create([])))->not->toThrow(\Throwable::class);
});

it('mantiene el contador materializado en alta y baja', function () {
    $post = ReactableCounted::create([]);
    $a = ReactionAuthorBigint::create([]);
    $b = ReactionAuthorBigint::create([]);

    $post->react($a);
    $post->react($b);
    expect($post->fresh()->reactions_count)->toBe(2);

    $post->react($a);
    expect($post->fresh()->reactions_count)->toBe(1);
});

it('no toca el contador al cambiar de tipo', function () {
    $post = ReactableCounted::create([]);
    $author = ReactionAuthorBigint::create([]);

    $post->react($author, MkReactionType::Like);
    $post->react($author, MkReactionType::Love);

    expect($post->fresh()->reactions_count)->toBe(1);
});

// ─── Borrado del dueño ────────────────────────────────────────────────────────

it('borra las reacciones al borrar el contenido', function () {
    $post = ReactablePost::create([]);
    $post->react(ReactionAuthorBigint::create([]));

    $post->delete();

    expect(MkReaction::count())->toBe(0);
});

it('conserva las reacciones en un soft delete y las borra en un forceDelete', function () {
    // Restaurar un post tiene que devolverte sus likes.
    $post = ReactableSoft::create([]);
    $post->react(ReactionAuthorBigint::create([]));

    $post->delete();
    expect(MkReaction::count())->toBe(1);

    $post->forceDelete();
    expect(MkReaction::count())->toBe(0);
});
