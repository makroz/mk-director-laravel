<?php

declare(strict_types=1);

namespace Mk\Director\Models;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Mk\Director\Tenancy\HasTenantScope;
use RuntimeException;

/**
 * MkReport — un pedido de export y su ciclo de vida.
 *
 * Tabla: {@see mk_reports} (configurable). Origen: `App\Models\Report` de
 * Condaty, que en realidad siempre fue del motor y estaba mal ubicada.
 *
 * Flujo de estados:
 *
 *     pending → processing → completed
 *                        ↘ failed
 *
 * El usuario dispara el export, recibe un 202 con el `uuid`, y el front hace
 * polling de `status` hasta que sea terminal.
 *
 * Extiende Eloquent directo, igual que {@see MkMedia} y `Role`, y NO
 * `Mk\Director\Models\Model`: la base del paquete instala el
 * `BaseModelBuilder` con cache, y un registro cuyo `status` y `progress`
 * cambian varias veces por segundo mientras el job corre es exactamente lo
 * que no hay que cachear — el front pediría el status y le contestarían
 * `pending` para siempre.
 *
 * Tenancy vía {@see HasTenantScope}, opt-in por `mk_director.tenant.enabled`
 * (ADR-003). En una app single-tenant el trait es un no-op y la columna queda
 * vacía. El origen en Condaty usaba su `ClientTrait`, que no viaja al paquete.
 */
class MkReport extends EloquentModel
{
    use HasTenantScope;

    protected $fillable = [
        'uuid',
        'user_id',
        'tenant_id',
        'type',
        'format',
        'params',
        'status',
        'file_path',
        'error_message',
        'progress',
        'total_chunks',
        'current_chunk',
        'expires_at',
    ];

    protected $casts = [
        'params' => 'array',
        'progress' => 'integer',
        'total_chunks' => 'integer',
        'current_chunk' => 'integer',
        'expires_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /**
     * Estados terminales: el job ya no los toca.
     */
    public const TERMINAL_STATUSES = [self::STATUS_COMPLETED, self::STATUS_FAILED];

    /**
     * Piso al que cae la retención si la config trae algo que no sirve.
     * NO es el valor operativo: ese sale de la config.
     */
    private const RETENTION_HOURS_FALLBACK = 48;

    public function getTable(): string
    {
        return $this->table ?? config('mk_director.export.table', 'mk_reports');
    }

    /**
     * Cuánto vive un reporte antes de que el limpiador se lo lleve, con su
     * archivo.
     *
     * 🔴 Este es el ÚNICO lugar donde vive el número. En Condaty estaba
     * escrito cuatro veces por separado —dos dispatchers sellando `expires_at`
     * y un command barriendo por antigüedad—. Como los dos criterios se
     * aplican, ganaba el más corto: cambiar uno solo no cambiaba nada visible,
     * y quien lo intentara iba a creer que subió la retención cuando no la
     * subió.
     *
     * ⚠️ Un valor inválido NO puede significar "borrar todo". `MK_EXPORT_
     * RETENTION_HOURS=` vacío da `(int) '' === 0`, y una retención de 0 horas
     * se lleva puesto cada reporte apenas se genera. Ante cualquier cosa que
     * no sea un entero positivo, se cae al fallback en vez de vaciar la tabla.
     */
    public static function retentionHours(): int
    {
        $horas = (int) config('mk_director.export.retention_hours', self::RETENTION_HOURS_FALLBACK);

        return $horas > 0 ? $horas : self::RETENTION_HOURS_FALLBACK;
    }

    /**
     * El modelo de usuario del consumer, o `null` si la app no declaró
     * ninguno.
     *
     * Sale de la config; si no está declarada, del provider de auth de
     * Laravel. El paquete no puede nombrar la clase: cada consumer tiene la
     * suya y en distinto namespace.
     *
     * ⚠️ Devuelve `?string`, no `string`. Una app sin modelo de usuario
     * configurado es legítima —un servicio que sólo expone reportes de
     * sistema, o un test que no monta el stack de auth— y prometer `string`
     * ahí es un `TypeError` en tiempo de ejecución, adentro del job, con el
     * reporte quedando en `failed`. Lo cazó el primer test que corrió el
     * motor de punta a punta.
     */
    public static function userModel(): ?string
    {
        $declarado = config('mk_director.export.user_model')
            ?? config('auth.providers.users.model');

        return is_string($declarado) && $declarado !== '' ? $declarado : null;
    }

    /**
     * @throws RuntimeException si la app no declaró modelo de usuario. Es
     *                          explícito a propósito: `belongsTo(null)` falla
     *                          más adelante y con un mensaje que no dice qué
     *                          configurar.
     */
    public function user(): BelongsTo
    {
        $modelo = static::userModel();

        if ($modelo === null) {
            throw new RuntimeException(
                'MkReport::user() necesita un modelo de usuario. Declaralo en '
                .'`mk_director.export.user_model` o en `auth.providers.users.model`.'
            );
        }

        return $this->belongsTo($modelo, 'user_id');
    }

    /**
     * Marca el arranque del job. `$totalChunks` puede venir null cuando el
     * total todavía no se conoce (el job lo completa al primer chunk).
     */
    public function markProcessing(?int $totalChunks = null): void
    {
        $this->update([
            'status' => self::STATUS_PROCESSING,
            'progress' => 0,
            'total_chunks' => $totalChunks,
            'current_chunk' => 0,
        ]);
    }

    public function updateProgress(int $currentChunk, ?int $progress = null): void
    {
        $update = ['current_chunk' => $currentChunk];

        if ($progress !== null) {
            $update['progress'] = $progress;
        }

        $this->update($update);
    }

    public function markCompleted(string $filePath): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'progress' => 100,
            'file_path' => $filePath,
            'current_chunk' => $this->total_chunks,
        ]);
    }

    public function markFailed(string $errorMessage): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * La URL de descarga, o null si todavía no hay archivo.
     *
     * Se arma por `route()` y no concatenando el prefijo a mano: el prefijo es
     * configurable (`mk_director.export.route_prefix`) y una URL armada a mano
     * se desincroniza en silencio cuando el consumer lo cambia.
     */
    public function getDownloadUrl(): ?string
    {
        if ($this->status !== self::STATUS_COMPLETED || ! $this->file_path) {
            return null;
        }

        return route('mk.reports.download', ['report' => $this->uuid]);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
