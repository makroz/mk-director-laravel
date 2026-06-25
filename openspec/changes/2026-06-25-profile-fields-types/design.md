# Design: R-PKG-012 Profile fields with custom types

> **Status**: design (R-PKG-012)
> **Spec**: `specs/profile-fields-types.md`

## ADR-001 (D1): Extensión de `--profile-fields`, no flag nuevo

**Contexto**: dos opciones para sintaxis:
- (A) `--profile-fields=name:string,birthdate:date,age:int` (extensión, un solo CSV parseado)
- (B) `--profile-fields-types=key:type,...` (flag nuevo, separado)

**Decisión**: opción (A). Mismo flag, parser más rico. Default `string` cuando no se especifica tipo (BC con R-PKG-011).

**Razón**:
- Más simple para el dev (un solo flag que recordar).
- BC natural: `--profile-fields=name,dni` se interpreta como `name:string,dni:string`.
- Ortogonalidad clara: `--profile-fields` sigue siendo "agregar columnas", solo que ahora con tipo.

**Consecuencias**:
- (+) Sintaxis unificada.
- (+) Documentación más simple (un solo flag con sintaxis opcional).
- (-) `--profile-fields` requiere parseo más rico (split por `:`).

## ADR-002 (D2): Single CSV parseado, no doble flag

**Decisión**: mismo flag, mismo CSV. No `--profile-fields` + `--profile-fields-types` separado.

## ADR-003 (D3): Tipos case-sensitive (lowercase only)

**Decisión**: tipos solo lowercase. `String`, `STRING`, `sTrInG` se rechazan.

**Razón**: consistencia con SQL/Laravel conventions. Menos sorpresas.

## ADR-004 (D4): Lista cerrada de 8 tipos

**Decisión**: 8 tipos soportados en v1.6.0-rc1: `string`, `text`, `int`, `decimal`, `bool`, `date`, `datetime`, `json`.

Cualquier otro tipo se rechaza con error explícito:
```
El tipo "foo" no está soportado. Tipos válidos: string, text, int, decimal, bool, date, datetime, json.
```

**Razón**: lista cerrada evita typos y permite documentación clara.

## ADR-005 (D5): `decimal` con precisión default `8,2`

**Decisión**: `$table->decimal('field', 8, 2)->nullable()` por default. No custom precision en v1.6.0-rc1.

**Razón**: 8 dígitos total, 2 decimales cubre el 95% (precios, scores). Custom precision post-RC si RETO necesita.

## ADR-006 (D6): `json` cast como `array` (Laravel moderno)

**Decisión**: `'metadata' => 'array'` en `$casts`. Laravel moderno usa `array` para JSON (no `json`).

**Razón**: `array` cast automáticamente `json_encode`/`json_decode`. Más limpio que `json` cast.

## ADR-007 (D7): Validation rules table-driven

**Decisión**: rules por tipo en una tabla constante:

```php
const PROFILE_FIELD_VALIDATION_RULES = [
    'string' => ['required', 'string', 'max:255'],
    'text' => ['required', 'string'],
    'int' => ['required', 'integer'],
    'decimal' => ['required', 'numeric'],
    'bool' => ['required', 'boolean'],
    'date' => ['required', 'date'],
    'datetime' => ['required', 'date'],
    'json' => ['required', 'array'],
];
```

**Consumer override**: para custom validation (regex CI, date format estricto, etc.), override `register()` y `updateProfile()` en el AuthController generado.

## ADR-008 (D8): `'date'` rule loose

**Decisión**: `'date'` loose para `date` y `datetime`. No `date_format:Y-m-d`.

**Razón**: `'date'` acepta múltiples formatos (Y-m-d, Y-m-d H:i:s, ISO 8601, etc.). Consumer puede override para strict.

## ADR-009 (D9): BC preservada

**Decisión**: `--profile-fields=name,dni` (sin tipos) → interpretados como `name:string,dni:string`. Idéntico a R-PKG-011.

**Tests BC**:
- `pest` con `--profile-fields=name` genera migration idéntica a v1.5.0-rc5.
- `pest` con `--profile-fields=name:string` genera migration idéntica a `--profile-fields=name` (mismo resultado).

## ADR-010 (D10): Ortogonalidad con otros 4 flags

**Decisión**: 5 flags totalmente ortogonales:
- `--login-field`
- `--with-auth-rbac`
- `--profile-fields` (con o sin tipos)
- `--verify-email`

16 (2^4) combinaciones válidas + el caso "tipos custom" = 32 combinaciones. Todas válidas.

## Implementation strategy

### Tabla de tipos (constante en el command)

```php
const PROFILE_FIELD_TYPES = [
    'string' => [
        'column_method' => 'string',
        'column_args' => [],  // string() no necesita args
        'cast' => null,  // default
        'validation' => ['required', 'string', 'max:255'],
    ],
    'text' => [
        'column_method' => 'text',
        'column_args' => [],
        'cast' => null,
        'validation' => ['required', 'string'],
    ],
    'int' => [
        'column_method' => 'integer',
        'column_args' => [],
        'cast' => 'integer',
        'validation' => ['required', 'integer'],
    ],
    'decimal' => [
        'column_method' => 'decimal',
        'column_args' => [8, 2],  // precision, scale
        'cast' => 'decimal:2',
        'validation' => ['required', 'numeric'],
    ],
    'bool' => [
        'column_method' => 'boolean',
        'column_args' => [],
        'cast' => 'boolean',
        'validation' => ['required', 'boolean'],
    ],
    'date' => [
        'column_method' => 'date',
        'column_args' => [],
        'cast' => 'date',
        'validation' => ['required', 'date'],
    ],
    'datetime' => [
        'column_method' => 'dateTime',
        'column_args' => [],
        'cast' => 'datetime',
        'validation' => ['required', 'date'],
    ],
    'json' => [
        'column_method' => 'json',
        'column_args' => [],
        'cast' => 'array',
        'validation' => ['required', 'array'],
    ],
];
```

### Parser extendido

```php
// En resolveProfileFields():
$items = array_map('trim', explode(',', $raw));
$fields = [];
foreach ($items as $item) {
    if (str_contains($item, ':')) {
        [$key, $type] = explode(':', $item, 2);
        $key = trim($key);
        $type = trim($type);
        if (!array_key_exists($type, self::PROFILE_FIELD_TYPES)) {
            $this->error("Tipo \"{$type}\" no soportado. Válidos: " . implode(', ', array_keys(self::PROFILE_FIELD_TYPES)));
            return null;
        }
    } else {
        $key = trim($item);
        $type = 'string';  // BC default
    }
    $fields[$key] = $type;
}
```

### Stubs: cómo se ven con tipos

**Migration con tipos custom**:
```php
Schema::create('admins', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->string('name');
    $table->string('email')->unique();
    // ── Profile fields (R-PKG-012 con tipos custom) ──────────
    $table->date('birthdate')->nullable();
    $table->integer('age')->nullable();
    $table->text('biography')->nullable();
    $table->boolean('active')->nullable();

    $table->string('password');
    $table->string('auth_scope')->default('admin')->index();
    $table->rememberToken();
    $table->timestamps();
});
```

**Model con casts por tipo**:
```php
protected $casts = [
    'email_verified_at' => 'datetime',  // si --verify-email
    'birthdate' => 'date',              // R-PKG-012
    'age' => 'integer',                 // R-PKG-012
    'active' => 'boolean',              // R-PKG-012
    'metadata' => 'array',              // R-PKG-012
    'password' => 'hashed',
];
```

**Controller con validation rules por tipo**:
```php
public function register(Request $request): JsonResponse
{
    $data = $request->validate([
        'name' => ['required', 'string', 'max:255'],
        'email' => ['required', 'string', 'max:255'],
        'birthdate' => ['required', 'date'],          // R-PKG-012
        'age' => ['required', 'integer'],             // R-PKG-012
        'biography' => ['required', 'string'],        // R-PKG-012 (text → string rule)
        'active' => ['required', 'boolean'],          // R-PKG-012
        'password' => ['required', 'string', 'min:8'],
    ]);
    // ...
}
```

## Anti-patterns rechazados

- ❌ `--profile-fields=name:String` (case incorrecto): rechazado, solo lowercase.
- ❌ `--profile-fields=name:varchar` (tipo no en lista): rechazado con lista de válidos.
- ❌ `--profile-fields=email:string --login-field=email` (colisión con login field): rechazado por R-PKG-011 (regla preserved).
- ❌ `--profile-fields=name,age:int` (mezcla con/sin tipos): válido, default `string` para los sin tipo.
- ❌ Custom validation sin override: si necesitás regex CI, override `register()`/`updateProfile()`.

## Resumen de cambios

### Command (MakeAuthUserCommand.php)
- `resolveProfileFields()` extendido para split `key[:type]`.
- Nueva constante `PROFILE_FIELD_TYPES` con 8 entradas.
- `buildProfileFieldsReplacements()` actualizado para usar la tabla de tipos.

### Stubs
- `auth-user.migration.stub`: usa `{{profileFieldsColumns}}` con `column_method` + `column_args`.
- `auth-user.model.stub`: usa `{{profileFieldsCastEntries}}` con `cast` por tipo.
- `auth-user.auth-controller.stub`: usa `{{profileFieldsValidationRules}}` con rules por tipo.

### Tests
- `MakeAuthUserProfileFieldsTypesTest.php` (NEW, ~20 tests).
- `AuthUserProfileFieldsTypesTest.php` (NEW, ~8 tests encapsulación).