# Design: R-PKG-011 Profile fields per-scope + email verification opt-in

> **Status**: design (R-PKG-011)
> **Spec**: `specs/profile-fields.md`

## ADR-001 (D1): Profile fields per scope, no User global compartido

**Contexto**: dos opciones para modelar "campos extra del user":
- (A) Cada scope tiene su propia tabla y modelo (`admins`, `members` con sus propias columnas)
- (B) Una tabla `users` global compartida con campos nullable

**Decisión**: opción (A). R-MK-001 (MME) estricto: cada bounded context vive en su propio folder, expone `Api/*` y NO comparte storage directo. Compartir tabla `users` requeriría coordinación cross-scope para migrations, lo cual rompe MME.

**Consecuencias**:
- (+) Encapsulación real: Admin no puede leer `members.dni` accidentalmente
- (+) Migraciones distribuidas: cada scope tiene su propia migration con sus campos
- (+) Type-safety: Admin::$fillable no expone `birthdate` que Member tiene
- (-) Más migrations que una tabla global
- (-) No se puede hacer `User::query()` cross-scope (pero MkAuthenticate ya previene eso via auth_scope check)

**Anti-pattern rechazado**: "agregar todas las columnas nullable a una sola tabla `users`" — funcionaría pero rompe MME y abre cross-scope leaks.

## ADR-002 (D2): Default sin profile fields, BC preservada

**Contexto**: si default = con profile fields, BC break para consumers existentes que asumían columnas mínimas (id, name, loginField, password, auth_scope, client_id, timestamps).

**Decisión**: default = sin profile fields. `--profile-fields=...` es opt-in explícito.

**Consecuencias**:
- (+) Consumers existentes regeneran idéntico (byte-level)
- (+) El flag es opcional, no rompe mental model
- (-) Consumers nuevos tienen que recordar el flag (pero el `--help` lo muestra + DEVELOPER_GUIDE § 3.9 lo explica)

## ADR-003 (D3): Todos los profile fields son `string` en v1.5.0-rc5

**Contexto**: tipos posibles: string, int, date, datetime, json, file (avatar). ¿Cuántos soportar en v1?

**Decisión**: solo `string` en v1.5.0-rc5. Tipos custom en v1.6.0 con syntax extendida:
```bash
# v1.5.0-rc5 (esta entrega)
--profile-fields=name,dni,phone

# v1.6.0 (futuro)
--profile-fields-types=name:string,dni:string,birthdate:date,avatar:file
```

**Razón**: simplificar la primera entrega. La validación string|max:255 cubre el 90% de casos. Tipos custom requieren lógica de validación + cast + form requests custom que complican el scaffolder.

**Consecuencias**:
- (+) Scaffolder simple, tests simples
- (+) Consumers que necesitan date pueden override `$casts` después
- (-) Si un consumer necesita date, tiene que editar el modelo a mano (acceptable)

## ADR-004 (D4): 3 endpoints exponen profile fields

**Contexto**: ¿dónde se exponen los profile fields al consumer?

**Decisión**: 3 endpoints:
- `GET /me` — read (user completo via serialization default, incluye profile fields por estar en $fillable)
- `PATCH /me` — update (valida cada profile field con `required|string|max:255`, llama `$user->update()`)
- `POST /register` — create (valida + crea + opcionalmente envía VerifyEmail notification)

**Anti-pattern rechazado**: tener un endpoint único `/profile` separado del `/me`. Sobrediseño, rompe la convención REST de "el recurso es el user".

## ADR-005 (D5): `--verify-email` default `false` (BC)

**Contexto**: ¿forzar verificación por default?

**Decisión**: NO. Default = sin verificación. El flag activa el flujo completo.

**Razón**: muchos consumers tienen otros mecanismos de verificación (SMS code, OAuth, magic link custom). Forzar email verification por default sería invasivo.

**Consecuencias**:
- (+) Consumers eligen su estrategia de verificación
- (+) BC con v1.5.0-rc4 (no había verificación)
- (-) Consumers que quieren verificación tienen que pasar el flag

## ADR-006 (D6): Laravel default `Illuminate\Auth\Notifications\VerifyEmail`

**Contexto**: ¿crear notificación custom o usar la default?

**Decisión**: usar la default. Custom templates / queues son responsabilidad del consumer.

**Razón**: la default es queueable, signed-URL-safe, y funciona out-of-the-box. Custom adds complexity sin valor inmediato.

**Consecuencias**:
- (+) Less code, less maintenance
- (+) Consumer puede override publicando la notification
- (-) El template de email es el default de Laravel (no branded)

## ADR-007 (D7): Validación PHP identifier

**Contexto**: ¿qué caracteres aceptar en field names?

**Decisión**: solo letras, números y guión bajo. Primer carácter no puede ser dígito. Regex: `/^[a-zA-Z_][a-zA-Z0-9_]*$/`.

**Razón**: los field names van a columnas DB + propiedades PHP. Ambos requieren identifier-style.

## ADR-008 (D8): Duplicados = fail-fast

**Contexto**: `--profile-fields=name,dni,name` — ¿ignorar el duplicado o rechazar?

**Decisión**: rechazar fail-fast con error explícito.

**Razón**: si el dev escribió dos veces `name`, es probablemente un typo. Mejor cortar early que generar un modelo con `name` dos veces.

## ADR-009 (D9): Ortogonalidad total de los 4 flags

**Contexto**: ¿pueden los flags interactuar de forma no trivial?

**Decisión**: cada flag es independiente. 16 combinaciones posibles (2^4). Todas válidas excepto:
- `--login-field=X --verify-email` donde X != `email` — `email_verified_at` no tiene sentido si el login no es por email.

**Matriz de validación**:

| login-field | verify-email | email_verified_at column | Verificación activa |
|---|---|---|---|
| email | false | NO | NO |
| email | true | SÍ | SÍ |
| ci | false | NO | NO |
| ci | true | NO (warning) | NO |

**Decisión sobre `--verify-email --login-field != email`**: warning explícito "verify-email no aplica cuando login-field != email (no hay email que verificar)". El scaffolder NO agrega la columna ni los endpoints. O alternativamente: permite al consumer agregar `--verify-email --login-field=email` para casos donde el scope tiene email como campo secundario (no login field).

**Simplificación v1.5.0-rc5**: si `--login-field != email` y `--verify-email`, IGNORAMOS `--verify-email` con warning. El usuario puede agregar verificación custom si quiere (override del AuthController).

## ADR-010 (D10): `/me` PATCH sin rate limit por default

**Contexto**: ¿rate limit en PATCH /me?

**Decisión**: sin rate limit por default. Consumer puede agregar via routes customization (`->middleware('throttle:30,1')`).

**Razón**: PATCH /me es de baja frecuencia (usuario actualiza su perfil ocasionalmente). Rate limit es overkill. Si el consumer ve abuso, lo agrega explícitamente.

## Implementation strategy

### Cómo agregar placeholders condicionales al modelo

```php
// auth-user.model.stub (extracto)
protected $fillable = [
    'name',
    '{{loginField}}',
{{profileFieldsFillableEntries}}    'password',
    'auth_scope',
    'client_id',
];

protected $casts = [
{{emailVerifiedAtCastEntry}}{{profileFieldsCastEntries}}    'password' => 'hashed',
];
```

`{{profileFieldsFillableEntries}}` se reemplaza por (sin profile fields):
```
(empty)
```

Con `--profile-fields=dni,phone`:
```
    'dni',
    'phone',
    
```

(La coma final + newline + indent se manejan para que el stub quede limpio visualmente.)

### Cómo agregar conditional routes

```php
// auth-user.routes.stub (extracto)
Route::prefix('api/{{moduleNameLower}}/auth')->group(function () {
    // ── Públicas ────────────────────────────────────────────────────
    Route::post('login', [AuthController::class, 'login']){{rbacLoginThrottle}};
    Route::post('refresh', [AuthController::class, 'refresh']);
    Route::post('register', [AuthController::class, 'register']);{{registerThrottle}}
    Route::post('forgot', [AuthController::class, 'forgot']){{rbacForgotThrottle}};
    Route::post('reset', [AuthController::class, 'reset']){{rbacResetThrottle}};
{{emailVerifyRoutes}}
    // ── Protegidas (auth: scope) ────────────────────────────────────
    Route::middleware('mk.auth:{{moduleNameLower}}'){{verifiedMiddleware}}->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::patch('me', [AuthController::class, 'updateProfile']);
    });
});
```

`{{emailVerifyRoutes}}` default = vacío. Con `--verify-email`:
```
    Route::get('email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
        ->middleware('signed')
        ->name('{{moduleNameLower}}.auth.verify');
    Route::post('email/resend', [AuthController::class, 'resendVerification'])
        ->middleware('throttle:6,1');
```

`{{verifiedMiddleware}}` default = vacío. Con `--verify-email`:
```
->middleware('verified')
```

(es un placeholder dentro del `Route::middleware(...)` group)

### Migration: columnas condicionales

```php
Schema::create('{{moduleNamePluralLower}}', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->string('name');
    $table->string('{{loginField}}')->unique();
{{profileFieldsColumns}}{{emailVerifiedAtColumn}}    $table->string('password');
    $table->string('auth_scope')->default('{{moduleNameLower}}')->index();
    $table->rememberToken();
    $table->timestamps();
});
```

`{{profileFieldsColumns}}` con `--profile-fields=dni,phone`:
```
        $table->string('dni')->nullable();
        $table->string('phone')->nullable();
        
```

(Nullable para que BC con registros existentes funcione — si la migration corre después de que hay data, no rompe.)

Wait — eso es opcional. Para v1.5.0-rc5 los profile fields pueden ser `nullable()` (no son auth fields, pueden faltar). El consumer puede agregar UNIQUE constraint después si quiere. Lo dejo nullable.

## Resumen de placeholders nuevos

| Placeholder | Default | Con `--profile-fields` | Con `--verify-email` |
|---|---|---|---|
| `{{profileFieldsFillableEntries}}` | (empty) | `'dni',\n    'phone',\n    ` | (no change) |
| `{{profileFieldsCastEntries}}` | (empty) | (empty para string — Laravel auto-castea) | (no change) |
| `{{profileFieldsColumns}}` | (empty) | Columnas string nullable | (no change) |
| `{{profileFieldsDocblock}}` | (empty) | `@property` entries | (no change) |
| `{{emailVerifyRoutes}}` | (empty) | (no change) | Routes verify + resend |
| `{{verifiedMiddleware}}` | (empty) | (no change) | `->middleware('verified')` |
| `{{registerEndpoint}}` | (empty) | `Route::post('register', ...)` | (no change, ya hay register) |
| `{{registerRoute}}` | (empty) | Register route (no flag, viene con `--profile-fields`) | (no change) |

Wait — el endpoint `/register` solo tiene sentido si hay `--profile-fields` o `--verify-email` (si no, no hay body que validar con campos custom). ¿O el `/register` debería existir siempre?

Decisión: `/register` SOLO si `--profile-fields` o `--verify-email` está activo. Sin flags, NO hay register route (BC con v1.5.0-rc4 que tampoco tenía).

Hmm, pero el spec dice "`POST /register` — write al crear (valida profile fields + crea user)". Si no hay profile fields ni verification, no hay razón para tener register.

Actualización: `/register` se agrega si `--profile-fields` O `--verify-email`. Validación: si `--profile-fields`, valida esos campos. Si `--verify-email`, valida `<loginField> + password` y dispara notification.

Y `PATCH /me` similar: solo si `--profile-fields`.

OK simplifico la matriz:

| Flag | Genera `/register` | Genera `PATCH /me` | Genera verification routes |
|---|---|---|---|
| (none) | NO | NO | NO |
| `--profile-fields` | SÍ | SÍ | NO |
| `--verify-email` | SÍ | NO | SÍ |
| `--profile-fields --verify-email` | SÍ | SÍ | SÍ |

`/register` con `--verify-email`: valida `<loginField> + password + (profile fields si aplica)`, crea user, dispatch `VerifyEmail` notification.

`/register` sin `--verify-email`: valida `<loginField> + password + (profile fields si aplica)`, crea user, sin notification.

OK, definitivo. Esto es la matriz final.

## Resumen de cambios en stubs

### auth-user.model.stub

Agregar:
- `{{profileFieldsFillableEntries}}` después de `{{loginField}}`
- `{{profileFieldsCastEntries}}` después de `{{emailVerifiedAtCastEntry}}`
- `{{profileFieldsDocblock}}` después del `@property string $name`

### auth-user.migration.stub

Modificar:
- `{{emailVerifiedAtColumn}}` solo aparece si `loginField=email` Y `--verify-email` (no solo si loginField=email, como en v1.5.0-rc3)

Agregar:
- `{{profileFieldsColumns}}` después de `{{loginField}}`

### auth-user.auth-controller.stub

Agregar:
- `register()` method (conditional)
- `updateProfile()` method (conditional)
- `verifyEmail()` method (conditional)
- `resendVerification()` method (conditional)
- `{{registerMethod}}` placeholder
- `{{updateProfileMethod}}` placeholder
- `{{verifyEmailMethods}}` placeholder

### auth-user.routes.stub

Agregar:
- `{{registerRoute}}` después de `refresh`
- `{{emailVerifyRoutes}}` después de `reset`
- `{{verifiedMiddleware}}` en el middleware group
- `Route::patch('me', ...)` después de `Route::get('me', ...)`