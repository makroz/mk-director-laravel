<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Traits;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mk\Director\Models\MkComment;
use Mk\Director\Tests\Concerns\UsesDatabase;
use Mk\Director\Tests\TestCase;
use Mk\Director\Traits\HasMkComments;

uses(TestCase::class, UsesDatabase::class);

/** Contenido comentable. */
class CommentablePost extends EloquentModel
{
    use HasMkComments;

    protected $table = 'c_posts';

    public $timestamps = false;

    protected $guarded = [];
}

/** Contenido comentable con SoftDeletes. */
class CommentableSoft extends EloquentModel
{
    use HasMkComments;
    use SoftDeletes;

    protected $table = 'c_posts_soft';

    public $timestamps = false;

    protected $guarded = [];
}

/** Autor con PK bigint. */
class CommentAuthor extends EloquentModel
{
    protected $table = 'c_authors';

    public $timestamps = false;

    protected $guarded = [];
}

/** Autor con PK uuid — el caso de RETO. */
class CommentAuthorUuid extends EloquentModel
{
    protected $table = 'c_authors_uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];
}

beforeEach(function () {
    $this->setUpDatabase();
    $this->runPackageMigration('2026_07_20_000002_create_mk_comments_table.php');

    Schema::create('c_posts', function (Blueprint $table): void {
        $table->id();
    });
    Schema::create('c_posts_soft', function (Blueprint $table): void {
        $table->id();
        $table->softDeletes();
    });
    Schema::create('c_authors', function (Blueprint $table): void {
        $table->id();
    });
    Schema::create('c_authors_uuid', function (Blueprint $table): void {
        $table->uuid('id')->primary();
    });

    $this->post = CommentablePost::create([]);
    $this->author = CommentAuthor::create([]);
});

afterEach(function () {
    $this->tearDownDatabase();
});

// ─── Alta ─────────────────────────────────────────────────────────────────────

it('agrega un comentario raíz', function () {
    $comment = $this->post->addComment($this->author, 'Buenísimo');

    expect($comment)->toBeInstanceOf(MkComment::class)
        ->and($comment->body)->toBe('Buenísimo')
        ->and($comment->isRoot())->toBeTrue()
        ->and($this->post->commentsCount())->toBe(1);
});

it('agrega una respuesta a un comentario raíz', function () {
    $root = $this->post->addComment($this->author, 'Pregunta');
    $reply = $this->post->addComment($this->author, 'Respuesta', $root);

    expect($reply->parent_id)->toBe($root->getKey())
        ->and($reply->isRoot())->toBeFalse()
        ->and($root->replies()->count())->toBe(1);
});

it('soporta autores con PK uuid', function () {
    $author = CommentAuthorUuid::create(['id' => '9f1c8a4e-0000-4000-8000-000000000001']);

    $comment = $this->post->addComment($author, 'Hola');

    expect($comment->author_id)->toBe($author->getKey());
});

// ─── Las dos reglas de integridad ─────────────────────────────────────────────

it('rechaza responder a una respuesta', function () {
    // Un solo nivel de anidamiento. Sin cota, ni la query ni la UI la tienen.
    $root = $this->post->addComment($this->author, 'Raíz');
    $reply = $this->post->addComment($this->author, 'Respuesta', $root);

    expect(fn () => $this->post->addComment($this->author, 'Nieto', $reply))
        ->toThrow(\InvalidArgumentException::class, 'un solo nivel');
});

it('rechaza un padre que pertenece a otro contenido', function () {
    // La FK garantiza que el padre EXISTE, no que sea del post correcto. Sin
    // este chequeo queda una respuesta que no aparece en ningún hilo.
    $otroPost = CommentablePost::create([]);
    $ajeno = $otroPost->addComment($this->author, 'De otro post');

    expect(fn () => $this->post->addComment($this->author, 'Colgado', $ajeno))
        ->toThrow(\InvalidArgumentException::class, 'otro contenido');
});

// ─── SoftDeletes de verdad ────────────────────────────────────────────────────

it('el borrado de un comentario es SOFT, no físico', function () {
    // 🔴 El legacy tenía la columna `deleted_at` SIN el trait: el delete
    // borraba la fila y la columna sólo daba una falsa sensación de que se
    // podía restaurar.
    $comment = $this->post->addComment($this->author, 'Se borra');

    $comment->delete();

    expect($this->post->commentsCount())->toBe(0)
        ->and(MkComment::withTrashed()->count())->toBe(1)
        ->and(MkComment::withTrashed()->first()->deleted_at)->not->toBeNull();
});

it('propaga el soft delete de un raíz a sus respuestas', function () {
    // La FK `cascadeOnDelete` NO cubre esto: un soft delete es un UPDATE y la
    // base no dispara ninguna FK. Sin la propagación quedan respuestas
    // visibles colgando de un padre que ya no se ve.
    $root = $this->post->addComment($this->author, 'Raíz');
    $this->post->addComment($this->author, 'Respuesta 1', $root);
    $this->post->addComment($this->author, 'Respuesta 2', $root);

    $root->delete();

    expect($this->post->commentsCount())->toBe(0)
        ->and(MkComment::withTrashed()->count())->toBe(3);
});

it('el forceDelete de un raíz se lleva las respuestas por FK', function () {
    $root = $this->post->addComment($this->author, 'Raíz');
    $this->post->addComment($this->author, 'Respuesta', $root);

    $root->forceDelete();

    expect(MkComment::withTrashed()->count())->toBe(0);
});

// ─── Paginación ───────────────────────────────────────────────────────────────

it('pagina sólo los comentarios raíz', function () {
    // Las respuestas viajan anidadas adentro de su padre, no como items
    // sueltos: si contaran como items, una página de 15 podría traer 15
    // respuestas del mismo comentario y ningún comentario nuevo.
    $root = $this->post->addComment($this->author, 'Raíz');
    $this->post->addComment($this->author, 'Respuesta', $root);
    $this->post->addComment($this->author, 'Otro raíz');

    $page = $this->post->paginatedComments();

    expect($page->total())->toBe(2)
        ->and($this->post->commentsCount())->toBe(3);
});

it('respeta el perPage y no devuelve el hilo entero', function () {
    foreach (range(1, 5) as $i) {
        $this->post->addComment($this->author, "Comentario {$i}");
    }

    $page = $this->post->paginatedComments(2);

    expect($page->count())->toBe(2)
        ->and($page->total())->toBe(5)
        ->and($page->lastPage())->toBe(3);
});

it('trae las respuestas eager-loaded, sin N+1', function () {
    // Sin el `with('replies')`, una página de 15 comentarios dispara 16
    // queries. El legacy tenía exactamente ese problema.
    $root = $this->post->addComment($this->author, 'Raíz');
    $this->post->addComment($this->author, 'Respuesta', $root);

    $page = $this->post->paginatedComments();

    expect($page->first()->relationLoaded('replies'))->toBeTrue()
        ->and($page->first()->replies)->toHaveCount(1);
});

// ─── Borrado del dueño ────────────────────────────────────────────────────────

it('borra los comentarios al borrar el contenido', function () {
    $root = $this->post->addComment($this->author, 'Raíz');
    $this->post->addComment($this->author, 'Respuesta', $root);

    $this->post->delete();

    expect(MkComment::count())->toBe(0);
});

it('conserva los comentarios en un soft delete del contenido', function () {
    // Restaurar un post tiene que devolverte su hilo.
    $post = CommentableSoft::create([]);
    $post->addComment($this->author, 'Sobrevive');

    $post->delete();

    expect(MkComment::count())->toBe(1);
});
