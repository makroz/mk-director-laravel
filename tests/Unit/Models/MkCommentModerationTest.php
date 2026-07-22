<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Models;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mk\Director\Enums\MkCommentReportReason;
use Mk\Director\Enums\MkCommentReportStatus;
use Mk\Director\Models\MkComment;
use Mk\Director\Models\MkCommentReport;
use Mk\Director\Tests\Concerns\UsesDatabase;
use Mk\Director\Tests\TestCase;
use Mk\Director\Traits\HasMkComments;

uses(TestCase::class, UsesDatabase::class);

/** Contenido comentable. */
class ModeratedPost extends EloquentModel
{
    use HasMkComments;

    protected $table = 'm_posts';

    public $timestamps = false;

    protected $guarded = [];
}

/** Autor / reporter / moderador con PK bigint. */
class ModActor extends EloquentModel
{
    protected $table = 'm_actors';

    public $timestamps = false;

    protected $guarded = [];
}

/** Reporter con PK uuid — el caso de RETO. */
class ModActorUuid extends EloquentModel
{
    protected $table = 'm_actors_uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];
}

beforeEach(function () {
    $this->setUpDatabase();
    $this->runPackageMigration('2026_07_20_000002_create_mk_comments_table.php');
    $this->runPackageMigration('2026_07_22_000002_add_moderation_to_mk_comments_table.php');
    $this->runPackageMigration('2026_07_22_000001_create_mk_comment_reports_table.php');

    Schema::create('m_posts', function (Blueprint $table): void {
        $table->id();
    });
    Schema::create('m_actors', function (Blueprint $table): void {
        $table->id();
    });
    Schema::create('m_actors_uuid', function (Blueprint $table): void {
        $table->uuid('id')->primary();
    });

    $this->post = ModeratedPost::create([]);
    $this->author = ModActor::create([]);
    $this->reporter = ModActor::create([]);
    $this->admin = ModActor::create([]);
});

afterEach(function () {
    $this->tearDownDatabase();
});

// ─── Reportar ───────────────────────────────────────────────────────────────

it('reporta un comentario y nace pendiente', function () {
    $comment = $this->post->addComment($this->author, 'Ofensivo');

    $report = $comment->reportBy($this->reporter, MkCommentReportReason::Offensive, 'no va');

    expect($report)->toBeInstanceOf(MkCommentReport::class)
        ->and($report->reason)->toBe(MkCommentReportReason::Offensive)
        ->and($report->note)->toBe('no va')
        ->and($report->status)->toBe(MkCommentReportStatus::Pending)
        ->and($comment->reports()->count())->toBe(1);
});

it('es idempotente: el mismo reporter no duplica el reporte', function () {
    // 🔴 La defensa real es el UNIQUE(comment, reporter). Dos taps del mismo
    // usuario NO pueden inflar la cola con "reportado por 2".
    $comment = $this->post->addComment($this->author, 'Spam');

    $first = $comment->reportBy($this->reporter, MkCommentReportReason::Spam);
    $second = $comment->reportBy($this->reporter, MkCommentReportReason::Spam);

    expect($second->getKey())->toBe($first->getKey())
        ->and($comment->reports()->count())->toBe(1);
});

it('dos reporters distintos dejan dos reportes', function () {
    $comment = $this->post->addComment($this->author, 'Spam');
    $otro = ModActor::create([]);

    $comment->reportBy($this->reporter, MkCommentReportReason::Spam);
    $comment->reportBy($otro, MkCommentReportReason::Harassment);

    expect($comment->reports()->count())->toBe(2);
});

it('soporta reporters con PK uuid', function () {
    $comment = $this->post->addComment($this->author, 'x');
    $uuidReporter = ModActorUuid::create(['id' => '9f1c8a4e-0000-4000-8000-000000000001']);

    $report = $comment->reportBy($uuidReporter, MkCommentReportReason::Other, 'detalle');

    expect($report->reporter_id)->toBe($uuidReporter->getKey());
});

// ─── Ocultar / restaurar ──────────────────────────────────────────────────────

it('ocultar marca el comentario y acciona sus reportes pendientes', function () {
    $comment = $this->post->addComment($this->author, 'Fuera');
    $comment->reportBy($this->reporter, MkCommentReportReason::Offensive);

    $comment->hideForModeration($this->admin, MkCommentReportReason::Offensive, 'viola reglas');

    $comment->refresh();
    $report = $comment->reports()->first();

    expect($comment->isHidden())->toBeTrue()
        ->and($comment->moderation_reason)->toBe(MkCommentReportReason::Offensive)
        ->and($comment->moderation_note)->toBe('viola reglas')
        ->and($comment->moderated_by_id)->toBe((string) $this->admin->getKey())
        ->and($report->status)->toBe(MkCommentReportStatus::Actioned)
        ->and($report->resolved_by_id)->toBe((string) $this->admin->getKey())
        ->and($report->resolved_at)->not->toBeNull();
});

it('restaurar limpia el estado de moderación pero no re-abre los reportes', function () {
    // El admin ya decidió sobre las denuncias; mostrar de nuevo el comentario
    // no debería resucitarlas.
    $comment = $this->post->addComment($this->author, 'Vuelve');
    $comment->reportBy($this->reporter, MkCommentReportReason::Spam);
    $comment->hideForModeration($this->admin, MkCommentReportReason::Spam);

    $comment->unhide();

    $comment->refresh();

    expect($comment->isHidden())->toBeFalse()
        ->and($comment->moderation_reason)->toBeNull()
        ->and($comment->moderated_by_id)->toBeNull()
        ->and($comment->reports()->first()->status)->toBe(MkCommentReportStatus::Actioned);
});

it('descartar resuelve los reportes sin ocultar el comentario', function () {
    $comment = $this->post->addComment($this->author, 'Está bien');
    $comment->reportBy($this->reporter, MkCommentReportReason::Other, 'no me gusta');

    $comment->dismissReports($this->admin);

    $comment->refresh();

    expect($comment->isHidden())->toBeFalse()
        ->and($comment->reports()->first()->status)->toBe(MkCommentReportStatus::Dismissed);
});

it('no re-resuelve un reporte ya terminal (histórico inmutable)', function () {
    // Descarto, y después oculto: el reporte YA resuelto como Dismissed no debe
    // pasar a Actioned. Sólo los pendientes se accionan.
    $comment = $this->post->addComment($this->author, 'x');
    $comment->reportBy($this->reporter, MkCommentReportReason::Spam);
    $comment->dismissReports($this->admin);

    $comment->hideForModeration($this->admin, MkCommentReportReason::Spam);

    expect($comment->reports()->first()->status)->toBe(MkCommentReportStatus::Dismissed);
});

// ─── Visibilidad ──────────────────────────────────────────────────────────────

it('el scope visibleTo esconde los ocultos a terceros y muestra el propio a su autor', function () {
    $visible = $this->post->addComment($this->author, 'Visible');
    $oculto = $this->post->addComment($this->author, 'Oculto');
    $oculto->hideForModeration($this->admin, MkCommentReportReason::Offensive);

    $paraAutor = MkComment::visibleTo($this->author)->pluck('id')->all();
    $paraTercero = MkComment::visibleTo($this->reporter)->pluck('id')->all();
    $paraAnonimo = MkComment::visibleTo(null)->pluck('id')->all();

    expect($paraAutor)->toContain($visible->id, $oculto->id)      // su propia lápida
        ->and($paraTercero)->toContain($visible->id)
        ->and($paraTercero)->not->toContain($oculto->id)          // no ve lo ajeno oculto
        ->and($paraAnonimo)->toEqual([$visible->id]);
});

// ─── Cascada ──────────────────────────────────────────────────────────────────

it('borrar FÍSICAMENTE el comentario se lleva sus reportes por FK', function () {
    $comment = $this->post->addComment($this->author, 'x');
    $comment->reportBy($this->reporter, MkCommentReportReason::Spam);

    $comment->forceDelete();

    expect(MkCommentReport::count())->toBe(0);
});
