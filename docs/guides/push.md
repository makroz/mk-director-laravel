# Push en 30 minutos

**Objetivo**: que tu proyecto mande notificaciones push a los teléfonos de sus usuarios —con la app
cerrada, abriendo la pantalla correcta al tocarlas— sin escribir lógica de envío propia. Del lado del
servidor elegís el servicio (Firebase Cloud Messaging u OneSignal) y cargás sus credenciales; del lado
de la app montás un provider.

Las piezas:

| Dónde | Qué trae |
|---|---|
| `makroz/director-laravel` | Las tablas `mk_push_devices` y `mk_push_topic_subscriptions`, las rutas de registro, `MkPush::to()/topic()/subscribe()/unsubscribe()`, el job que envía y poda, los drivers `fcm`, `onesignal`, `log` y `null`, y `mk:push:prune`. |
| `@makroz/mobile` | `MkPushProvider`, `useMkPush()`, `createFcmPushClient()` y `createOneSignalPushClient()`. El paquete **no importa ningún SDK**: la app le inyecta el de Firebase o el de OneSignal. |

El envío sale **siempre del servidor**: las credenciales del servicio son secretas y nunca viajan a la app.

Todo lo que dice esta guía se probó en un proyecto real (Android, con la app cerrada, en segundo plano y
abierta). Las trampas del final están medidas, no supuestas.

---

## 1. Servidor (≈10 minutos)

### 1.1 Tablas

Las migraciones vienen con el paquete (`loadMigrationsFrom`): no hay nada que publicar.

```bash
php artisan migrate
```

El dueño de un teléfono es cualquier modelo que extienda `Mk\Director\Auth\Models\AuthUser`, y se guarda
polimórfico: el mismo módulo sirve para varios scopes (miembros y admins, por ejemplo).

### 1.2 `.env`

```dotenv
# fcm | onesignal | log | null. El default es null: instalar el paquete no manda nada.
MK_PUSH_DRIVER=log

# Cola del envío; vacía = la default.
MK_PUSH_QUEUE=

# FCM: ruta ABSOLUTA al JSON de la cuenta de servicio de Firebase
# (consola de Firebase → Configuración del proyecto → Cuentas de servicio → Generar nueva clave privada).
MK_PUSH_FCM_CREDENTIALS=/path/to/service-account.json

# OneSignal: el App ID y una App API key (Settings → Keys & IDs).
MK_PUSH_ONESIGNAL_APP_ID=
MK_PUSH_ONESIGNAL_API_KEY=

# mk:push:prune borra los teléfonos que no se vieron en estos días. 0 la apaga.
MK_PUSH_PRUNE_AFTER_DAYS=60
```

- Empezá con `log`: escribe en el log qué saldría y a qué teléfonos, sin credenciales de ningún servicio.
- Un driver desconocido **explota** al resolverse (un typo no puede dejar a todos sin avisos en silencio).
  `fcm` sin `MK_PUSH_FCM_CREDENTIALS`, u `onesignal` sin app id o sin clave, también.
- El JSON de la cuenta de servicio y la API key de OneSignal **nunca se commitean**. El `.env.example`
  lleva sólo los nombres.

### 1.3 Rutas de registro

Son **opt-in**, como el export. Sin `register_routes` no se monta ninguna. Se configuran en
`config/mk_director.php` (sección `push`) o, sin publicar el config, en el `boot()` de un provider tuyo:

```php
// app/Providers/AppServiceProvider.php
public function boot(): void
{
    config([
        'mk_director.push.register_routes' => true,
        'mk_director.push.route_prefix' => 'api/member/push',
        // El scope de auth lo decidís vos. Sin auth el controller igual responde 401.
        'mk_director.push.route_middleware' => ['api', 'mk.auth:member'],
    ]);
}
```

Eso monta:

| Ruta | Qué hace |
|---|---|
| `POST {prefix}/devices` | Recibe `{ provider: 'fcm'\|'onesignal', address: string(≤512), platform: 'ios'\|'android' }` y responde `204`. Registra el teléfono para el usuario autenticado, o lo pasa a él si era de otro (cambió la sesión en el mismo celular). Idempotente, y refresca `last_seen_at`. |
| `DELETE {prefix}/devices/{address}` | Responde `204`. Sólo borra un teléfono **propio**: una dirección ajena da `404`, igual que una que no existe, y no borra nada. |

El dueño sale **siempre** de la sesión, nunca del body. No hay ruta de envío: se envía desde código.

### 1.4 Enviar

```php
use Mk\Director\Push\Facades\MkPush;
use Mk\Director\Push\PushMessage;

MkPush::to($member)->send(new PushMessage(
    title: 'Tu retiro fue pagado',
    body: 'Te transferimos BOB 301,50 a tu cuenta.',
    data: ['kind' => 'withdrawal.paid', 'id' => (string) $withdrawal->id],
    url: '/wallet/withdrawals',   // la ruta de la app que abre el toque; viaja como data.url
));
```

- `to()` acepta un `AuthUser` o un iterable de ellos (array, Collection, resultado de una consulta), con
  tipos mezclados. Un dueño repetido cuenta una vez. Un grupo vacío no manda nada — nunca «a todos».
- `data` es un mapa de **strings** (FCM sólo acepta strings).
- `send()` encola `SendPushJob` con **`afterCommit`**: si la transacción que avisa se revierte, el push no
  sale. Las direcciones se leen cuando el job corre, no al llamar `send()`.
- El job manda a todos los teléfonos del dueño **del servicio activo** y borra los que el servicio
  rechaza para siempre (FCM: `404 UNREGISTERED`). Va de a tandas (`mk_director.push.chunk`, default 500).

### 1.5 La cola y la poda

```bash
php artisan queue:work      # sin worker no sale nada (salvo QUEUE_CONNECTION=sync)
```

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('mk:push:prune')->daily();
```

`mk:push:prune [--days=N] [--dry-run]` borra los teléfonos con `last_seen_at` más viejo que N días. Hace
falta porque los servicios no siempre avisan de una dirección muerta (ver OneSignal en las trampas). Un
teléfono activo no se poda: la app re-registra cada vez que arranca con sesión.

---

## 2. App Expo con Firebase (≈15 minutos)

> **Expo Go no recibe push desde el SDK 53.** Hace falta un build de desarrollo (`expo run:android`) o
> la APK. En Expo Go la app tiene que seguir andando sin push: de eso se ocupa la guarda del paso 2.3.

### 2.1 Firebase

1. Un proyecto en la consola de Firebase (acá, `my-project`) con una app Android con el `applicationId`
   de tu app.
2. Bajá `google-services.json` a la raíz de la app. **Va en `.gitignore`.**
3. La cuenta de servicio del mismo proyecto es la que lee el servidor (`MK_PUSH_FCM_CREDENTIALS`).

### 2.2 Dependencias y config nativa

```bash
npx expo install @react-native-firebase/app @react-native-firebase/messaging expo-notifications
```

`app.json`:

```json
{
  "expo": {
    "android": {
      "package": "com.example.app",
      "googleServicesFile": "./google-services.json"
    },
    "plugins": [
      "@react-native-firebase/app",
      "@react-native-firebase/messaging",
      "./plugins/withFcmNotificationColor",
      [
        "expo-notifications",
        {
          "icon": "./assets/images/notification-icon.png",
          "color": "#1e40af",
          "sounds": ["./assets/sounds/payment_chime.wav"]
        }
      ]
    ]
  }
}
```

`firebase.json`, en la raíz de la app:

```json
{
  "react-native": {
    "messaging_android_notification_channel_id": "default"
  }
}
```

- 🔴 El canal por defecto de FCM va en `firebase.json`, **no** en el `defaultChannel` del plugin de
  `expo-notifications`: el manifest de RN Firebase ya declara `default_notification_channel_id` y el merge
  chocaría.
- 🔴 El **color** NO va en `firebase.json` (`messaging_android_notification_color`) apuntando a un recurso
  de la app como `@color/notification_icon_color`: RN Firebase copia ese valor al manifest de SU librería y
  el build **release** falla en `:react-native-firebase_messaging:verifyReleaseResources` («resource
  color/notification_icon_color not found»; el debug compila). El color lo pone el plugin de
  `expo-notifications`, y un config plugin chico le gana el merge al `@color/white` que declara RN Firebase:

  ```js
  // plugins/withFcmNotificationColor.js
  const { AndroidConfig, withAndroidManifest } = require('expo/config-plugins');

  const FCM_COLOR = 'com.google.firebase.messaging.default_notification_color';

  module.exports = function withFcmNotificationColor(config) {
    return withAndroidManifest(config, (config) => {
      const manifest = AndroidConfig.Manifest.ensureToolsAvailable(config.modResults);
      const app = AndroidConfig.Manifest.getMainApplicationOrThrow(manifest);
      const meta = (app['meta-data'] ?? []).find((item) => item.$['android:name'] === FCM_COLOR);
      if (!meta) {
        throw new Error(`withFcmNotificationColor: ${FCM_COLOR} not found; list it BEFORE expo-notifications`);
      }
      meta.$['tools:replace'] = 'android:resource';
      return config;
    });
  };
  ```

  En `app.json` va **antes** de `expo-notifications`: los mods del manifest corren en orden inverso, así
  que el de `expo-notifications` (que escribe la meta-data) corre primero.
- El ícono chico de Android es una **silueta blanca** sobre transparente (96×96). El
  `android-icon-monochrome.png` de la plantilla de Expo no sirve: es el logo de la plantilla.
- El sonido de un canal va empaquetado (`sounds`); las plataformas no lo bajan de una URL.

### 2.3 El cliente, con la guarda de Expo Go

```ts
// src/config/push.ts
import { TurboModuleRegistry } from 'react-native';
import { createFcmPushClient, type MkPushChannel, type MkPushClient } from '@makroz/mobile';

/** Relativo a la base del cliente de API (`{origin}/api`) → `api/member/push/devices`. */
export const PUSH_DEVICES_ENDPOINT = '/member/push/devices';

/** Los MISMOS ids que `mk_director.push.channels` del servidor. */
export const PUSH_CHANNELS: MkPushChannel[] = [
  { id: 'default', name: 'Avisos' },
  { id: 'payments', name: 'Pagos', sound: 'payment_chime.wav', importance: 'high' },
];

type MessagingModule = typeof import('@react-native-firebase/messaging');

// 🔴 RN Firebase hace `TurboModuleRegistry.getEnforcing` AL IMPORTARSE: en Expo Go (o en un build
// hecho antes de sumarlo) un import estático tumba la app al arrancar. Se mira el módulo nativo primero.
function nativeMessaging(): MessagingModule | null {
  if (!TurboModuleRegistry.get('NativeRNFBTurboMessaging')) return null;
  try {
    return require('@react-native-firebase/messaging') as MessagingModule;
  } catch {
    return null;
  }
}

function createPushClient(): MkPushClient | null {
  const messaging = nativeMessaging();
  if (!messaging) return null;
  const notifications = require('expo-notifications') as typeof import('expo-notifications');
  // RN Firebase v26 es SÓLO API modular: `getMessaging()`, no `messaging()`.
  return createFcmPushClient(messaging.getMessaging(), { notifications });
}

export const pushClient = createPushClient();

/** Un push que despierta la app CERRADA corre una tarea headless: necesita un handler. */
export function registerPushBackgroundHandler(): void {
  const messaging = nativeMessaging();
  messaging?.setBackgroundMessageHandler(messaging.getMessaging(), () => Promise.resolve());
}
```

- `createFcmPushClient(messaging, { notifications })`: `notifications` (el módulo de
  `expo-notifications`) hace falta para crear los canales de Android y para `foreground: 'system'`.
- En Android 13+ el permiso `POST_NOTIFICATIONS` lo pide el propio cliente (el `requestPermission()` de RN
  Firebase responde AUTHORIZED en Android sin preguntar). El permiso en el manifest lo trae
  `expo-notifications`.

### 2.4 La entrada: el handler de segundo plano

Sin handler, un push con la app cerrada hace que RN Firebase avise «No task registered for key
ReactNativeFirebaseMessagingHeadlessTask». La tarea headless nunca monta el layout raíz, así que el handler
se registra en una **entrada propia** (el «custom entry point» de expo-router):

```js
// index.js
import { registerPushBackgroundHandler } from './src/config/push';
import 'expo-router/entry';

registerPushBackgroundHandler();
```

En `package.json`: `"main": "index.js"`.

### 2.5 El provider

Va **dentro** de `MkAuthProvider` (y de `MkApiProvider`): registra con sesión y se desregistra en el logout.

```tsx
// src/app/_layout.tsx
import { MkPushProvider } from '@makroz/mobile';
import { PUSH_CHANNELS, PUSH_DEVICES_ENDPOINT, pushClient } from '@/config/push';

<MkAuthProvider onUnauthenticated={() => router.replace('/login')}>
  <MkPushProvider client={pushClient} endpoint={PUSH_DEVICES_ENDPOINT} channels={PUSH_CHANNELS}>
    <Stack />
  </MkPushProvider>
</MkAuthProvider>
```

| Prop | Qué hace |
|---|---|
| `client` | `MkPushClient \| null`. `null` = sin push (Expo Go): la app anda igual y `status` es `'unsupported'`. |
| `endpoint` | Relativo a la base del cliente de API. |
| `askOnLogin` | Pide el permiso y registra apenas hay sesión. Default `true`. |
| `onOpen(data)` | Se llama en cada toque, en el acto. Default: `router.push(data.url)` si es una ruta de la app (`/…`; nunca `//host` ni `https://…`), cuando hay sesión y navegador. En arranque en frío la ruta espera al login. |
| `foreground` | Qué hace un push con la app abierta: `'none'` (default, no se muestra nada), `'system'` (el banner del sistema, en el mismo canal) o una función que recibe el mensaje (por ejemplo, para un toast propio). |
| `channels` | Los canales de Android que se crean al arrancar, antes de que pueda llegar un push. |

- El logout borra el teléfono **antes** de limpiar la sesión: `MkPushProvider` se engancha con
  `addLogoutHook` de `MkAuthProvider` (cada hook tiene 3 s; un error o un cuelgue no traban el logout).
- Un token nuevo (`onTokenRefresh`) se re-registra solo.

### 2.6 El interruptor

```tsx
import { useMkPush } from '@makroz/mobile';

const { status, enable, disable } = useMkPush();
// status: 'unsupported' | 'denied' | 'off' | 'on' | 'pending'
if (status === 'unsupported') return null;   // Expo Go: sin interruptor
```

- `disable()` hace el `DELETE` **primero** y recién después guarda la bandera local `mk.push.off`
  (AsyncStorage: es del teléfono y sobrevive al logout). Si el `DELETE` falla, tira y no cambia nada.
- `enable()` borra la bandera, pide el permiso y registra; devuelve `true` si quedó registrado.
- Con la bandera puesta, nada registra solo. El logout no la pone: cerrar sesión no es apagar.

---

## 3. Probar (≈5 minutos)

1. Con `MK_PUSH_DRIVER=log`, logueate en la app: tiene que aparecer una fila en `mk_push_devices`.
   Disparar un envío escribe `[mk-director] push: …` en el log con las direcciones.
2. Pasá a `MK_PUSH_DRIVER=fcm` y **reiniciá el worker** (`php artisan queue:restart`).
3. Probá con la app **cerrada**: `adb shell am kill <applicationId>` con la app en segundo plano.
   🔴 No uses «Forzar detención» ni `am force-stop`: Android bloquea los push de una app detenida a la fuerza
   hasta que se abre de nuevo.
4. Tocá la notificación: tiene que abrir `data.url`, también en frío.

---

## 4. Más allá de lo mínimo

### Canales, imagen, sonido, ícono y color

```php
// config: mk_director.push
'channels' => [
    'default' => ['name' => 'Avisos', 'importance' => 'default'],
    'payments' => ['name' => 'Pagos', 'sound' => 'payment_chime.wav', 'importance' => 'high'],
],
'default_channel' => 'default',   // MK_PUSH_DEFAULT_CHANNEL; null = sin canal
```

```php
new PushMessage(
    title: 'Tu pago fue confirmado',
    body: '…',
    url: '/purchase/42',
    channel: 'payments',                          // hereda sound / icon / color de su canal
    image: 'https://example.com/banner.png',      // https
    sound: 'payment_chime.wav',                   // un sonido de la app, o 'default'
    icon: 'notification_icon',                    // recurso de Android; iOS siempre usa el ícono de la app
    color: '#16A34A',                             // #RRGGBB, sólo Android
);
```

- Resolución, una vez en el job: lo que dice el mensaje > su canal en la config > `default_channel`. Del
  canal el servidor toma `sound`, `icon` y `color`; `name` e `importance` los usa la app al crearlo.
- Un valor inválido tira `InvalidArgumentException` en el constructor (el mensaje no repite el valor).
- 🔴 En Android 8+ el sonido y la importancia **son del canal** y se fijan la primera vez que el teléfono lo
  crea. Cambiarlos no cambia nada en un teléfono que ya lo tiene: otro sonido es otro id de canal, o hay que
  reinstalar la app (o borrar sus datos) para probar.
- La imagen en iOS necesita una Notification Service Extension en la app (ver iOS).

### Temas

```php
MkPush::subscribe($member, 'novedades');    // idempotente
MkPush::topic('novedades')->send(new PushMessage('Hay novedades', '…'));
MkPush::unsubscribe($member, 'novedades');
```

- El tema es un slug: minúsculas, dígitos y `. - _ :`, de 1 a 100 caracteres. Otro nombre tira
  `InvalidArgumentException` sin escribir nada.
- Los temas viven en la base del proyecto, no en el servicio: se comportan igual con FCM y con OneSignal, y
  cambiar de servicio no pierde suscripciones. La suscripción es de la persona: llega a todos sus teléfonos.

### Un servicio propio

`PushProvider` se registra con `bindIf`: bindeá tu implementación de
`Mk\Director\Push\Contracts\PushProvider` en tu provider y gana sin desregistrar nada.

---

## 5. OneSignal en vez de Firebase

Servidor: `MK_PUSH_DRIVER=onesignal` + `MK_PUSH_ONESIGNAL_APP_ID` + `MK_PUSH_ONESIGNAL_API_KEY`. El resto
del código no cambia.

App: `react-native-onesignal` + `onesignal-expo-plugin`, y el cliente:

```ts
import { TurboModuleRegistry } from 'react-native';
import { createOneSignalPushClient, type MkPushClient } from '@makroz/mobile';

const ONESIGNAL_APP_ID = '<your-onesignal-app-id>';   // público: va en la app

function createPushClient(): MkPushClient | null {
  // Igual que RN Firebase, importarlo sin el módulo nativo tumba la app.
  if (!TurboModuleRegistry.get('OneSignal')) return null;
  const { OneSignal } = require('react-native-onesignal');
  const notifications = require('expo-notifications');   // para los canales
  return createOneSignalPushClient(OneSignal, ONESIGNAL_APP_ID, { notifications });
}
```

- `createOneSignalPushClient` llama a `OneSignal.initialize(appId)`: crealo **una vez**, fuera de los
  componentes. `getAddress()` espera hasta 15 s el subscription id de una instalación nueva.
- Una app con OneSignal **no lleva** RN Firebase messaging. Si usa `expo-notifications` para los canales,
  sacá su `ExpoFirebaseMessagingService` del manifest (`tools:node="remove"` con un config plugin): si no,
  se ven notificaciones dobles.
- Los canales propios viajan como `existing_android_channel_id`: el canal tiene que existir en el teléfono
  (los crea `channels` del provider). Si no existe, cae al canal por defecto de OneSignal.
- Un solo servicio activo a la vez: al pasar de `fcm` a `onesignal`, los teléfonos registrados con FCM no
  reciben hasta que la app con OneSignal se registre.

---

## 6. iOS

Lo que hace falta además de Android:

- Una cuenta de **Apple Developer** y la clave APNs (`.p8`) subida a Firebase (o a OneSignal).
- `GoogleService-Info.plist` en la raíz de la app (ignorado en git) y `ios.googleServicesFile` en
  `app.json`.
- `ios.infoPlist.UIBackgroundModes: ["remote-notification"]`. El `aps-environment` lo pone el plugin de
  `expo-notifications`.
- RN Firebase por CocoaPods con frameworks estáticos:

  ```json
  ["@react-native-firebase/app", { "ios": { "disableSPM": true } }],
  ["expo-build-properties", {
    "ios": { "useFrameworks": "static", "forceStaticLinking": ["RNFBApp", "RNFBMessaging"] }
  }]
  ```

  RN Firebase 26 resuelve el SDK de Apple por SPM por defecto, que exige frameworks dinámicos;
  `forceStaticLinking` lo pide con el React Native precompilado de Expo 54+.
- La **imagen** necesita una Notification Service Extension (un target nativo extra) que baje
  `fcm_options.image`; el servidor ya manda `mutable-content: 1` cuando hay imagen.

🔴 **Límite conocido**: la configuración de iOS compila y corre en el simulador, pero **todavía no se probó
en un iPhone real** (registro del token, llegada, imagen y toque). Y Firebase deja de publicar versiones en
CocoaPods después de octubre de 2026: las existentes siguen andando, pero para actualizar el SDK habrá que
pasar a SPM con frameworks dinámicos.

---

## 7. Trampas (todas medidas)

| Síntoma | Causa | Qué hacer |
|---|---|---|
| La app se cierra al arrancar en Expo Go | RN Firebase (y OneSignal) hacen `getEnforcing` al importarse | Guarda con `TurboModuleRegistry.get` antes del `require` (2.3). |
| Un ejemplo con `messaging()` (la API vieja) no anda | RN Firebase v26 es sólo API modular | `getMessaging()`, `setBackgroundMessageHandler(getMessaging(), fn)`. |
| En Android 13+ no aparece nada y no se pidió permiso | `requestPermission()` de RN Firebase no pide `POST_NOTIFICATIONS` | Lo pide `createFcmPushClient`; no lo saltees con tu propio cliente. |
| Warning «No task registered for key ReactNativeFirebaseMessagingHeadlessTask» | Sin handler de segundo plano | `index.js` propio (2.4). |
| El build release falla en `verifyReleaseResources` | `messaging_android_notification_color` en `firebase.json` apunta a un recurso de la app | Sacarlo y usar `withFcmNotificationColor`, antes de `expo-notifications` (2.2). |
| Conflicto de manifest con `default_notification_channel_id` | Canal por defecto en `defaultChannel` de expo-notifications | Va en `firebase.json` (2.2). |
| Cambié el sonido o la importancia y no cambia | El canal se fija al crearse | Otro id, o reinstalar / borrar datos. |
| Después del deploy no sale ningún push, o fallan los jobs | El `queue:work` viejo no conoce los campos nuevos de `PushMessage` / `SendPushJob` | `php artisan queue:restart` después de cada deploy. |
| «Con la app cerrada no llega» | Se cerró con «Forzar detención» | Probar con `adb shell am kill <applicationId>`. |
| Una notificación de un grupo colapsado abre el inicio | Android arma un resumen con 4+ notificaciones de la app; su intent no trae `data` | Tocar la notificación suelta o expandir el grupo. Comportamiento de Android. |
| OneSignal cuenta como enviado a un teléfono muerto | Con `include_subscription_ids` y al menos un id válido, responde 200 sin informar los inválidos | `mk:push:prune` diario (poda por `last_seen_at`). |
| OneSignal deja de mandar | El plan gratis tiene un tope de 1.000 usuarios activos por mes en push móvil; si se pasa, pausa hasta pagar | Medir los MAU antes de elegirlo. |

## 8. Límites aceptados

- Sin envíos programados, sin estadísticas de apertura, sin web push, sin preferencias por tipo de aviso
  (un solo interruptor por teléfono).
- FCM v1 es un request por token y los temas viven en la base: un tema de 50.000 teléfonos son 50.000
  requests desde la cola. El camino para crecer son los temas nativos de FCM o los segmentos de OneSignal.
- Si el logout ocurre sin red, el teléfono sigue recibiendo hasta que otra sesión lo re-asigna, el servicio
  lo invalida o lo poda `mk:push:prune`.
- Con `foreground: 'system'`, el toque a esa notificación local con la app ya muerta no se rutea.
- En iOS el ícono chico y el color no se personalizan.
