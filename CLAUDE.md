# CLAUDE.md — neo-qr

**Ruta:** `qr/`
**Qué es:** App de generación y administración de **códigos QR (seriales) por
cliente**. Cada cliente tiene tandas de códigos únicos con formato `QRABCDEF`,
pensados para imprimirse y pegarse en el mundo físico. El QR codifica una URL
formada con ese código.

> **neo-qr es un proyecto independiente y nuevo.** No comparte base, tablas ni
> dominio con ningún otro sistema: de un proyecto previo sólo se hereda el
> *stack* y las convenciones de código, nada más.

## Stack específico

Mismo stack que el proyecto previo — se sigue el mismo criterio en todo lo que no
está anotado como distinto acá:

- **PHP vanilla, arquitectura MVC.** Sin framework, sin composer, sin `vendor/`,
  sin build step.
- **PHP 8.2 + Apache** (`php:8.2-apache`), `mod_rewrite`, docroot en `public/`.
- **Base de datos: PostgreSQL** vía PDO (`pdo_pgsql` ya compilado en la imagen).
  Base **propia**, tablas con prefijo `qr_`.
- **Autoload PSR-4 manual** con `spl_autoload_register` sobre el prefijo `App\`.
- **Carpetas capitalizadas**: `app/Controllers`, `app/Models`, `app/Views`.
- **Front controller** `public/index.php`, router por `switch` sobre el path
  normalizado. Todo lo dinámico va por **query string**, no por parámetros de
  ruta (`/qr.svg?serial=...`, no `/qr/{serial}`).
- **Vista PHP + API JSON *action-based* + JS vanilla con `fetch`**, igual que el
  hermano. La única excepción es el login, que es un `<form>` POST clásico.
- **Generación de la imagen QR: SVG desde PHP.** El encoder está escrito para
  este proyecto en `app/Support/QrMatrix.php` (no es una librería de terceros,
  así que no hay licencia ajena que arrastrar) y `QrSvg.php` convierte la matriz
  en SVG. Vectorial, no necesita la extensión GD y no obliga a tocar el
  `Dockerfile`. Cubre **modo alfanumérico y modo byte**, nivel de corrección
  **H** y versiones 1 a 10. El nivel es H (~30%) y no el M (~15%) habitual
  porque el centro del código se reserva para un logo — ver más abajo, "Logo en
  el centro". El modo lo elige solo el encoder — ver "Cuántos módulos tiene el
  código".
- **Login con una sola contraseña**, sin tabla de usuarios. En el entorno va el
  **hash bcrypt**, nunca la contraseña. Todo el panel está detrás; sólo `/health`
  y `/login` quedan afuera.
- **Hosting:** EasyPanel, configuración por variables de entorno.
- **Deploy: push a `main` → webhook propio de EasyPanel**, ya configurado. A
  diferencia del proyecto previo, que se deploya con un `public/autoPull.php`
  hecho a mano, acá **no hay ni debe haber** ese archivo: lo resuelve la
  plataforma.

## Variables de entorno

| Variable | Uso |
|---|---|
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Conexión PostgreSQL. **Obligatorias**: si falta alguna, `config/database.php` aborta con 500 en vez de arrancar mal configurado. Nombres estilo Laravel — ojo: `DB_DATABASE`/`DB_USERNAME`, no `DB_NAME`/`DB_USER`. |
| `QR_BASE_URL` | Base de la URL que codifica el QR **y del link para la grabadora**: los dos cuelgan de la misma raíz (`/SERIAL` y `/TOKEN`). Sin esto **no se emite ninguna imagen**. |
| `ADMIN_PASSWORD_HASH_B64` | Hash bcrypt de la contraseña del panel, **codificado en base64**. Se genera con `php -r 'echo base64_encode(password_hash("...", PASSWORD_DEFAULT));'`. Vacío = no se puede entrar. |
| `APP_URL` | URL pública, sólo informativa. |

## Estructura de carpetas

```
qr/
├── public/
│   ├── index.php              ← front controller: autoload PSR-4 + guard + router
│   ├── .htaccess
│   └── js/
│       ├── clientes.js        ← alta/baja + helpers compartidos; se carga primero
│       └── seriales.js        ← panel de seriales, generación, filtro por lote
├── config/
│   ├── app.php                ← version, qr_base_url, admin_password_hash
│   └── database.php           ← credenciales PostgreSQL (env obligatorias)
├── db/
│   └── schema.sql             ← esquema versionado, idempotente; se aplica a mano
├── .env.example
└── app/
    ├── Controllers/
    │   ├── AuthController.php     ← login (GET formulario / POST ingreso) y logout
    │   ├── ClienteController.php  ← vista principal + API de alta/baja (sin edición)
    │   ├── SerialController.php   ← API de generación, vista de QR y las dos exportaciones
    │   ├── QrController.php       ← sirve el SVG de un serial
    │   └── HealthController.php   ← healthcheck con chequeo de conexión a la base
    ├── Models/
    │   ├── Database.php           ← PDO singleton → PostgreSQL (getConnection)
    │   ├── Cliente.php            ← tabla qr_clientes
    │   ├── Serial.php             ← tabla qr_seriales
    │   └── Enlace.php             ← tabla qr_enlaces: el link público por lote (ver "Link para la grabadora")
    ├── Support/
    │   ├── Auth.php               ← sesión, password_verify, cookie SameSite=Strict
    │   ├── FormatoSerial.php      ← sortea y valida el formato QR + 6 letras; arma la URL que codifica el QR
    │   ├── FormatoEnlace.php      ← sortea, valida y reconoce en la ruta el token del link de la grabadora
    │   ├── QrMatrix.php           ← encoder QR propio: texto → matriz de módulos
    │   ├── QrSvg.php              ← matriz → SVG (pantalla, grabado suelto y hoja)
    │   ├── QrLogo.php             ← objeto de valor del logo (viewBox + markup) y el placeholder
    │   ├── SaneadorSvg.php        ← SVG subido por el cliente → QrLogo seguro (ver "Logo en el centro")
    │   ├── Respuesta.php          ← envelope JSON {ok, data|error} + lectura del body
    │   └── Zip.php                ← armador de .zip a mano (la imagen no trae ext-zip)
    └── Views/
        ├── Layouts/app.php        ← layout del panel (CSS inline, sólo tema claro)
        ├── Auth/login.php         ← formulario de contraseña
        ├── Clientes/index.php     ← panel: tabla de clientes + panel de seriales
        └── Seriales/qr.php        ← vista de control imprimible + las dos descargas, sin layout
```

## Ruteo

| Ruta | Handler | Descripción |
|------|---------|-------------|
| `/login` | `AuthController::login()` | GET muestra el formulario, POST intenta el ingreso |
| `/logout` | `AuthController::salir()` | Cierra la sesión |
| `/` y `/clientes` | `ClienteController::index()` | Vista principal; los datos se cargan por `fetch` |
| `/clientes/nuevo` | `ClienteController::nuevo()` | Vista con el modal de alta auto-abierto |
| `/api/clientes` | `ClienteController::api()` | JSON: `list`, `get`, `create`, `delete` (baja lógica), `logo` (GET lee, POST reemplaza) |
| `/api/seriales` | `SerialController::api()` | JSON: `list`, `generar`, `importar`, `importados`, `crear_enlace`, `anular_enlace` |
| `/qr` | `SerialController::qr()` | Hoja imprimible con los SVG generados (`?cliente_id=`, opcional `?lote=`) |
| `/qr.zip` | `SerialController::zip()` | Exportación "QR separados": los mismos códigos, un SVG por archivo, para la grabadora |
| `/qr-hoja.svg` | `SerialController::hoja()` | Exportación "hoja con todos": un único SVG con todos los códigos ubicados en hojas A4 |
| `/qr-unidad.svg` | `SerialController::unidad()` | Descarga de un solo código en formato de grabado (`?serial=QRABCDEF`) |
| `/qr.svg` | `QrController::svg()` | Imagen de un QR suelto (`?serial=QRABCDEF`) |
| `/<TOKEN>` | `SerialController::grabado()` | **Pública, sin sesión.** Página del link de la grabadora, en la raíz del dominio (`qr.example.com/WEQEWRW`) — ver "Link para la grabadora" |
| `/grabado.zip` | `SerialController::grabadoZip()` | **Pública.** Igual que `/qr.zip`, con cliente y lote resueltos por el token |
| `/grabado-hoja.svg` | `SerialController::grabadoHoja()` | **Pública.** Igual que `/qr-hoja.svg` |
| `/grabado-unidad.svg` | `SerialController::grabadoUnidad()` | **Pública.** Igual que `/qr-unidad.svg` (`?t=token&serial=`) |
| `/health` | `HealthController::check()` | Healthcheck; verifica también la conexión a la base |
| cualquier otra | — | `404 - No encontrado` |

**Todas las rutas exigen sesión salvo `/health`, `/login`, `/logout`, las tres
rutas `/grabado*` y el token en la raíz.** El guard está en `public/index.php`,
antes del `switch`. A las rutas `/api/*` sin sesión se les responde **401
JSON** en vez de un 302: un `fetch` que recibe una redirección a HTML sólo
produce un error confuso, así que el JS mira el 401 y redirige él. Las rutas
del enlace público lo son porque el token **es** la credencial —no hace falta
sesión para demostrarla— y por eso `/qr-unidad.svg` (la misma descarga
individual, pero para el panel) se queda **fuera** de esa lista: ahí sí hace
falta sesión.

**El token en la raíz no es un `case` del switch: se reconoce por forma**, con
`FormatoEnlace::deRuta()`, y es el único lugar donde el router mira un patrón
en vez de un nombre. Esa función es la que comparten el guard y el `default`,
así que no pueden discrepar en qué path es público. Antes de aplicarla, el
path se descarta contra `$rutasEstaticas` —la lista plana de todos los `case`—
para que agregar mañana una ruta de 7 caracteres del alfabeto del token no la
vuelva pública sin querer. Hoy ninguna colisiona (todas son más cortas, más
largas, o llevan un punto, un guión o una barra, que no están en ese
alfabeto), pero el guard no puede depender de que eso siga siendo cierto.

Envelope JSON canónico: `{"ok": true, "data": ...}` / `{"ok": false, "error": "..."}`,
con el **código HTTP real** (400/401/404/405/409/500), no un 200 con `ok:false`.

## Formato del serial

```
QR ABCDEF
│  └─ 6 letras A-Z sorteadas al azar
└──── prefijo fijo

= QRABCDEF   (8 caracteres, largo fijo)
```

El código es **opaco a propósito**: no se puede deducir de él ni el cliente, ni
la fecha, ni qué número de la tanda es. Todo eso vive en las columnas de
`qr_seriales`, no en el string. Dos razones:

1. Un código que se autodescribe deja **adivinar los códigos vecinos**, y la URL
   del QR es de hecho la única credencial que hay para llegar a un código.
2. No expone hacia afuera cuántos clientes hay ni cuándo se generó cada tanda.

Las letras se sortean con `random_int`, que usa el generador criptográfico del
sistema: con `rand()` los códigos serían predecibles a partir de unos pocos.

**26⁶ = 308.915.776 combinaciones.** Como se sortean al azar, las colisiones son
posibles de verdad — y por eso el salteo importa (ver abajo).

## Modelo de datos

```sql
CREATE TABLE qr_clientes (
    id           INTEGER PRIMARY KEY GENERATED ALWAYS AS IDENTITY,
    nombre       TEXT        NOT NULL,
    activo       BOOLEAN     NOT NULL DEFAULT TRUE,
    creado_en    TIMESTAMPTZ NOT NULL DEFAULT now(),
    baja_en      TIMESTAMPTZ,
    logo_svg     TEXT,   -- markup ya saneado (ver "Logo en el centro"); NULL = sigue con el placeholder
    logo_viewbox TEXT,   -- "minX minY ancho alto"; NULL exactamente cuando logo_svg es NULL
    CONSTRAINT qr_clientes_logo_completo CHECK ((logo_svg IS NULL) = (logo_viewbox IS NULL))
);

CREATE TABLE qr_seriales (
    id         BIGINT PRIMARY KEY GENERATED ALWAYS AS IDENTITY,
    serial     TEXT        NOT NULL UNIQUE,   -- ← la regla "no se repiten", en la base
    cliente_id INTEGER     NOT NULL REFERENCES qr_clientes(id),
    origen     TEXT        NOT NULL DEFAULT 'generado',  -- 'generado' | 'importado'
    lote       TEXT,                          -- AAAAMMDD-HHMM, agrupa la tanda
    tamano_mm  NUMERIC(5,1),                  -- lado de la placa, cuadrada; NULL en filas viejas o importadas
    creado_en  TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT qr_seriales_formato CHECK (serial ~ '^QR[A-Z]{6}$'),
    CONSTRAINT qr_seriales_origen  CHECK (origen IN ('generado', 'importado'))
);

CREATE INDEX qr_seriales_cliente_idx ON qr_seriales (cliente_id);
CREATE INDEX qr_seriales_lote_idx    ON qr_seriales (cliente_id, lote);

-- El link público por lote para la grabadora. Ver "Link para la grabadora".
CREATE TABLE qr_enlaces (
    id         BIGINT PRIMARY KEY GENERATED ALWAYS AS IDENTITY,
    token      TEXT        NOT NULL UNIQUE,   -- opaco, sorteado; no el lote firmado
    cliente_id INTEGER     NOT NULL REFERENCES qr_clientes(id),
    lote       TEXT        NOT NULL,
    activo     BOOLEAN     NOT NULL DEFAULT TRUE,
    creado_en  TIMESTAMPTZ NOT NULL DEFAULT now(),
    baja_en    TIMESTAMPTZ,
    CONSTRAINT qr_enlaces_token_formato CHECK (token ~ '^[2-9A-HJKMNP-TV-Z]{7}$')
);

-- A lo sumo un enlace ACTIVO por lote; los anulados no cuentan.
CREATE UNIQUE INDEX qr_enlaces_lote_activo_idx ON qr_enlaces (cliente_id, lote) WHERE activo;
```

**La unicidad la garantiza el `UNIQUE` de Postgres**, no un `SELECT` previo en
PHP. Chequear y después insertar deja una ventana de carrera entre dos requests
simultáneos; el índice único no.

**Sobre `lote`:** como el serial es opaco y no lleva la fecha adentro, esta
columna es lo único que agrupa una generación. Es lo que se filtra para imprimir
sólo la última tanda y no todo el histórico del cliente.

**Sobre `tamano_mm`:** el lado en milímetros de la placa donde se graba ese
código, elegido en el formulario al generar el lote. Las placas son siempre
**cuadradas**, así que alcanza con guardar una sola medida (no ancho y alto por
separado). Un cliente puede tener un lote a 25 mm y otro a 40 mm sin ningún
problema: la medida es propia de cada lote, no del cliente ni global a la app.
Es NULL en dos casos: los seriales generados antes de que existiera esta
columna (el `.zip` los sigue emitiendo a 25 mm, el valor que se usaba siempre,
ver "Descarga para grabadoras") y los importados, que directamente no producen
imagen (ver "Importar códigos que ya existían").

> La tabla de lotes del panel (`Serial::lotesDeCliente()`) sólo lista
> `origen = 'generado'`: los importados no son una tanda para imprimir, son un
> inventario (ver "Importar códigos que ya existían" más abajo) y viven detrás
> del botón de importación, no en esta tabla. La columna "Códigos" de la tabla
> de clientes (`Cliente::all()` / `find()`) sigue contando **todo** -generados
> e importados-, así que ese número puede quedar por encima de la suma de los
> lotes visibles cuando el cliente ya importó algo.

> El nombre del lote (`AAAAMMDD-HHMM`) se arma con la hora del **servidor, que
> corre en UTC**, mientras que las fechas de la interfaz se muestran en la zona
> horaria de quien mira (ver abajo). O sea que el identificador del lote y la
> columna "Generado" **no coinciden**, y está bien: el lote es un código
> interno y estable, la fecha es la hora real del usuario. No hay que
> "corregir" ese desfasaje haciendo que el lote use la hora local — sería un
> identificador distinto según quién lo mire.

## Fechas en la interfaz

El servidor corre en UTC. Sin hacer nada, todas las horas se verían corridas
para quien las lee, así que **la conversión se hace en el navegador**:

- El backend manda **segundos Unix** (`creado_ts` en la API, `data-ts` en el
  HTML), no texto con formato. Un epoch no tiene ambigüedad de zona ni de
  formato; el texto que emite PostgreSQL (`2026-09-07 21:54:24.5+00`) no lo
  parsean todos los navegadores igual.
- `window.fechaLocal(ts)` está definida en `Layouts/app.php`, antes del resto
  del JS, porque la usan tanto el pie como las tablas.
- Usa `toLocaleDateString` + `toLocaleTimeString` por separado y no
  `toLocaleString`, que mete una coma entre fecha y hora y en `es-AR` devuelve
  12 horas con "p. m.".
- El pie imprime el sello de deploy en UTC como respaldo en el HTML, y el
  script lo reemplaza. Si el JS no corre, se sigue viendo la fecha (en UTC,
  etiquetada como tal) en vez de un hueco.
- Es **sólo visual**: en la base todo se guarda en `TIMESTAMPTZ` y los lotes se
  nombran en UTC. No hay ninguna zona horaria de usuario persistida.

**Sobre `origen`:** distingue los códigos sorteados por la app (`'generado'`)
de los que se dieron de alta pegándolos (`'importado'`). Un lote nunca mezcla
los dos, porque las importaciones llevan el prefijo `IMP-` en su etiqueta.

El proyecto previo no versiona su esquema (sus tablas se crean fuera del
repo). Como acá la base es nueva, se estrena la convención de un **`db/schema.sql`
versionado**, idempotente y aplicado a mano.

## Versión en el pie

El pie del panel muestra `v<version>` junto al `PHP <x.y.z>` y al sello de
deploy. Sale de `config/app.php` (clave `version`) y **se sube a mano** en el
mismo commit que la cambia.

No se deriva de git —ni tag, ni hash, ni `git describe`— porque
`.dockerignore` deja `.git` afuera de la imagen: en producción no hay repo del
que leerla, y pasarla por `ARG`/`ENV` en el build obligaría a tocar el
Dockerfile o EasyPanel para algo que ya vive versionado en el repo.

**Es un dato distinto del sello de deploy, no una duplicación.** `.build` dice
*cuándo se construyó la imagen* (lo escribe el `RUN date` del Dockerfile) y
sirve para diagnosticar un deploy que no promocionó (ver "Producción"); la
versión dice *qué código trae*. Dos deploys de la misma versión tienen sellos
distintos, y por eso van los dos.

La vista imprimible (`Seriales/qr.php`) no lleva pie —ni el del panel ni el
público— así que la versión se ve sólo en el panel.

## Reglas de negocio

**Generar N seriales para un cliente**
1. Sortear 6 letras.
2. Insertar con `INSERT ... ON CONFLICT (serial) DO NOTHING`, en una transacción.
3. **Si el código ya existía, saltearlo y sortear otro**, hasta juntar los N
   pedidos.
4. Hay una cota de intentos (`N * 20 + 100`): si el espacio de códigos estuviera
   agotado, el bucle termina igual en vez de colgar el request.
5. Responder cuántos se crearon y cuántos se saltearon.

> El salteo **no es decorativo**: con códigos al azar las colisiones ocurren, y
> es el `UNIQUE` de la base el que las detecta. Es la regla que se pidió desde el
> principio, y recién con el formato aleatorio pasa a tener efecto real.

**Importar códigos que ya existían**: se pegan en un textarea, uno por línea.
Cada línea se normaliza (`trim` + mayúsculas) y se valida contra el mismo
formato; los que pasan se insertan con el mismo `ON CONFLICT DO NOTHING` y
`origen = 'importado'`. Sirve para que códigos generados antes queden
reservados y el sorteo no los vuelva a entregar.

Se informan **tres resultados separados**, porque cada uno pide algo distinto de
quien importa: los **importados** entraron, los **duplicados** ya estaban (no hay
nada que hacer), y los **inválidos** quedaron afuera y hay que revisarlos a mano
— de estos se devuelven unos pocos ejemplos para que se entienda qué se rechazó.

> La tanda importada recibe una etiqueta con prefijo `IMP-`. Si compartiera el
> formato de las generadas, una importación y una generación en el mismo minuto
> caerían en el mismo lote y no habría forma de decir de dónde vino cada código.

**La importación se puede repetir.** No hay un tope de "una vez por cliente":
puede llegar una segunda tanda de códigos viejos, y cada llamada a
`Serial::importar()` arma su propia etiqueta `IMP-AAAAMMDD-HHMM` con la hora
de ese momento, así que dos importaciones separadas no se confunden entre sí.
Los repetidos -ya sea dentro de la misma tanda pegada o porque el código ya se
había importado antes- se siguen resolviendo con el mismo `ON CONFLICT (serial)
DO NOTHING` que la generación, y se cuentan como duplicados.

En la interfaz esto se ve en un solo botón, **"QRs viejos"**, siempre
disponible. Abre un modal con un bloc de notas: arriba, en sólo lectura y sin
poder editarse ni borrarse, **todo** lo importado hasta el momento
(`GET /api/seriales?action=importados`); abajo, el área editable donde se
pegan los códigos nuevos. Al confirmar, el modal no se cierra -sigue siendo la
vista del inventario- y el bloc de arriba se refresca con lo recién agregado.
**No hay hoja de QR ni .zip para los importados**: esos códigos ya se
imprimieron afuera con otro arte, así que emitirles una imagen no tenía uso
-por eso `Serial::porCliente()` y `Serial::porLote()`, que son las que
alimentan `/qr` y las dos exportaciones, filtran `origen = 'generado'` y dejan
a los importados fuera de todas.

**Baja de cliente**: `activo = FALSE` + `baja_en = now()` (baja lógica). Los
seriales **quedan intactos y no se liberan**: esos QR pueden estar impresos y
pegados, y reciclar los códigos haría que una etiqueta física terminara
apuntando al destino equivocado. No se pueden generar seriales para un cliente
dado de baja.

**ID de cliente opcional en el alta**: el modal tiene un campo de ID que, en
blanco, deja que lo asigne la base (el caso normal). Escrito, se inserta ese id
con `OVERRIDING SYSTEM VALUE` —la columna es `GENERATED ALWAYS`— y el choque lo
detecta el `PRIMARY KEY` con `ON CONFLICT DO NOTHING`, no un `SELECT` previo:
mismo criterio que el `UNIQUE` de `serial`. Un id ocupado responde **409**.

> Después de un alta con id manual se corre `setval` sobre la secuencia de la
> identidad hasta el `MAX(id)` de la tabla. Sin eso la secuencia sigue donde
> estaba y el próximo alta automática chocaría contra un id ya usado.

**Sin edición de clientes**: el CRUD es alta y baja únicamente para la
identidad del cliente (el nombre). Si un nombre queda mal, se da de baja y se
crea de nuevo. **El logo es la única excepción**: se puede reemplazar en
cualquier momento (`Cliente::guardarLogo()`), porque es arte, no identidad —
obligar a recrear el cliente para corregir un logo dejaría huérfanos sus
seriales, que es justo lo que la baja lógica evita.

**Logo opcional al alta**: el modal de "Nuevo cliente" ofrece subir el SVG en
el mismo paso que el nombre, pero no lo exige — ver "Logo en el centro" para
el contrato del archivo. Sin logo, el cliente queda con
`logo_svg`/`logo_viewbox` en NULL y sus QR usan `QrLogo::porDefecto()` (el
mismo logo de prueba de siempre) hasta que alguien le cargue uno propio desde
el botón de la tabla. No hay acción para **quitar** un logo una vez cargado,
sólo para reemplazarlo: eso sí se pidió así, para no tener un estado
intermedio de "tenía logo y se lo sacaron" que nadie decidió a propósito —
distinto del caso normal de "todavía no le cargaron uno".

## Qué codifica el QR

El QR **no** codifica el serial pelado: codifica una **URL**, porque un código
suelto no le sirve de nada a un celular que lo escanea.

```
{QR_BASE_URL}/{serial}   →   HTTPS://<DOMINIO-NEO-QR>/QRABCDEF
```

La arma `FormatoSerial::url()`, que es el único lugar donde se concatena base y
serial (la usan `/qr`, `/qr.svg`, `/qr.zip` y `/qr-hoja.svg`).

**El esquema y el host van en MAYÚSCULAS a propósito** — ver "Cuántos módulos
tiene el código" más abajo. Es seguro porque el esquema y el host son
insensibles a mayúsculas por RFC 3986 (§3.1 y §3.2.2), igual que el DNS. El
path **no** se toca, porque ese sí es sensible: si algún día `QR_BASE_URL`
llevara un tramo de path, se respeta tal cual.

> ⚠️ **Sin `QR_BASE_URL` no se emite ninguna imagen**: `/qr.svg` devuelve 409 y
> la hoja de QR se niega a imprimir. Es deliberado — la URL queda embebida
> en el código impreso y un lote mal generado no se arregla sin reimprimir.

Qué hay del otro lado de esa URL **todavía no está definido** y está fuera del
alcance de este proyecto: hoy neo-qr genera e inventaría los códigos, nada más.

## Cuántos módulos tiene el código

Los "cuadraditos" (módulos) son lo que decide **a qué distancia se puede
escanear**: la placa mide lo que mide, así que menos módulos = módulos más
grandes = se lee de más lejos. Bajarlos es una restricción de producto real, no
una optimización cosmética.

Lo que define la versión —y por lo tanto la cantidad de módulos— es cuántos
**bits** hay que meter adentro. De ahí salen las dos palancas:

1. **El modo de codificación.** El modo byte gasta 8 bits por carácter; el
   **alfanumérico mete dos caracteres en 11 bits** (5,5 por carácter, un 31%
   menos), pero sólo admite 45 símbolos: dígitos, letras **mayúsculas** y
   `$%*+-./: `. Por eso la URL se emite en mayúsculas: así entra entera en ese
   juego. `QrMatrix::modo()` lo detecta solo y cae a byte ante cualquier
   carácter fuera del juego, sin romperse.
2. **El largo del dominio.** Menos caracteres, menos bits (ver "Pendientes").

Con la URL de EasyPanel (46 caracteres), a nivel H:

| | Modo | Versión | Módulos | Módulo a 25 mm |
|---|---|---|---|---|
| Antes | byte | 6 | 41×41 | ~0,51 mm |
| **Hoy** | **alfanumérico** | **4** | **33×33** | **~0,61 mm** |
| Con `qr.example.com` | alfanumérico | 3 | 29×29 | ~0,68 mm |

> El hueco del logo no se agranda al bajar de versión: es un porcentaje fijo del
> lado (~26%), así que pasa de 11×11 sobre 41 (7,2% del área) a 9×9 sobre 33
> (7,4%). Prácticamente lo mismo, muy por debajo del ~30% que corrige el nivel H.

**No bajar el nivel de corrección para ganar módulos.** Es la otra palanca
posible y está descartada: el logo tapa ~7% del código y el nivel H es lo que
permite perder ese bloque. Ver "Logo en el centro".

## Logo en el centro

Todos los QR —generados o importados, `QrSvg` no distingue origen— llevan un
**logo en el centro**, tanto en pantalla/papel (`/qr`, `/qr.svg`) como en el
`.zip` de grabado. **El logo es propio de cada cliente**, se sube como SVG
desde el panel (botón "Agregar logo" / "Cambiar logo" en la tabla de
clientes) y se guarda en `qr_clientes.logo_svg` / `logo_viewbox` — no hay
disco escribible en producción (ver Producción/Dockerfile), así que la base es
el único lugar donde puede vivir.

- **`app/Support/QrLogo.php`** es un objeto de valor (viewBox ya parseado +
  markup), no el arte en sí. Tiene un único dato hardcodeado,
  `QrLogo::porDefecto()`: el placeholder — el isotipo de los favicons
  (`app/Views/Layouts/app.php`, `app/Views/Seriales/qr.php`) reexpresado como
  path — que usan los clientes que **todavía no tienen un logo propio**. Como
  el logo es opcional al alta, esto no es sólo un caso de compatibilidad con
  filas viejas: es el estado normal de cualquier cliente hasta que alguien le
  cargue uno. Es lo que hace que el botón de la tabla diga "Agregar logo" en
  vez de "Cambiar logo".
- **`app/Support/SaneadorSvg.php`** es lo que convierte el SVG que sube un
  cliente en un `QrLogo` seguro de incrustar. Recorre el XML y **reconstruye
  por lista blanca** (sólo `g`, `path`, `rect`, `circle`, `ellipse`, `line`,
  `polyline`, `polygon`, con un puñado de atributos geométricos validados por
  charset) en vez de borrar por lista negra sobre el DOM cargado: así "lo que
  no está permitido" nunca tiene un hueco por el que colarse, y de paso es la
  defensa contra XSS — el resultado se incrusta `innerHTML` en el panel y se
  hace `echo` crudo en la hoja imprimible. Rechaza con un mensaje en
  castellano (texto sin convertir a curvas, degradados, imágenes incrustadas,
  `<use>`/`<mask>`) en vez de aceptar cualquier cosa y perder parte del dibujo
  en silencio; sí sanea en silencio los no-ops reales, sobre todo
  `<clipPath>` — Figma envuelve casi todo export en uno que cubre el frame
  entero, así que rechazarlo rechazaría la mayoría de los logos de Figma.
- **Contrato del logo: geometría monocroma sobre un viewBox, con el color
  impuesto desde afuera.** Ese color es **negro (`QrLogo::COLOR`) en todas
  las salidas** — pantalla, papel y grabado —, igual que los módulos del
  código. En grabado no hay alternativa (la máquina graba una figura, no un
  color) y en pantalla se usa el mismo para que la vista de control muestre
  exactamente lo que va a salir de la grabadora; el verde de marca quedó
  para la interfaz del panel, no para el QR. Esto no cambió con el logo por
  cliente — lo que cambió es que ya no es necesariamente un único `<path>`:
  puede ser cualquier combinación de figuras que el saneador dejó pasar. Los
  huecos internos siguen yendo como subpaths con `fill-rule="evenodd"`, no
  como blanco pintado encima, porque el dibujo tiene que funcionar en negro
  puro sin fondo (grabado, donde no hay "blanco" — sólo lo que el path
  dibuja se graba).
- **El color se aplana a un `<g transform="translate() scale()">`, nunca a un
  `<svg>` anidado.** `QrSvg::logo()` reproduce a mano la cuenta que haría
  `preserveAspectRatio="xMidYMid meet"` en vez de anidar un `<svg viewBox>` y
  dejar que el navegador la resuelva. Es deliberado: el mismo markup termina
  en el `.zip` de grabado, y los importadores de LightBurn/RDWorks son
  parsers simples — el mismo motivo por el que `paraGrabado()` ya sacaba el
  `<rect>` de fondo y los atributos de sólo pantalla. Pedirles que resuelvan
  un `viewBox` en un elemento que no es la raíz del documento, o una palabra
  clave CSS como `currentColor`, es apostar a que lo hacen bien; un `<g>` con
  transform explícito y colores ya literales es justo lo que un parser tonto
  entiende. El costo es que se pierde el recorte al viewport que un `<svg>`
  anidado sí haría (geometría fuera del viewBox declarado se derrama sobre
  los módulos vecinos en vez de cortarse) — preferible a que la hoja recorte
  en pantalla y el grabado no.
- **Tope de 6 KB de markup saneado.** La hoja `/qr` embebe hasta 500 QR
  inline en una sola página y el `.zip` repite el logo en 500 archivos sin
  comprimir: con la URL de EasyPanel (v4 en modo alfanumérico, 46 caracteres)
  un QR sin logo pesa ~2,5 KB, así que una hoja de 500 códigos son ~1,3 MB
  antes del logo (eran ~2,9 MB cuando el código salía en v6, ~2,1 MB antes de
  fusionar los módulos en dos direcciones y ~1,6 MB antes de unir los
  contornos). Un
  logo de 6 KB la lleva a ~4,2 MB; sin tope, un SVG de Figma sin optimizar podía
  irse a 15-20 MB de página. El tope es además correcto por producto: el
  hueco central son 11 módulos ≈ 6,7 mm dentro de una placa de 25 mm, y un
  logotipo con texto ahí es ilegible — la pista del modal pide el isotipo, no
  el logotipo completo. Si algún día 6 KB no alcanzara, la salida es
  `<defs><symbol>` + `<use>` en la hoja (no sirve para el `.zip`: cada archivo
  tiene que ser autónomo para la grabadora), no subir el tope.
- `QrSvg` reserva un hueco cuadrado y centrado en la matriz (piso de 5
  módulos, siempre impar para caer simétrico sobre la grilla) y **quita esos
  módulos del `path()` en vez de taparlos**: es lo único que también funciona
  en el SVG de grabado.
- **El tamaño del hueco es por versión, no una fracción fija del lado**
  (`QrSvg::HUECO_POR_VERSION`). Lo que limita al logo no es el porcentaje de
  área que tapa sino **cuántos codewords rompe en el bloque Reed-Solomon más
  golpeado**, y eso no escala parejo con el tamaño del código.

  Un logo encima del QR produce **errores, no borrones**: el lector no sabe
  que esos módulos están tapados, los lee como blanco o negro y se come un
  valor equivocado. Reed-Solomon corrige la mitad de errores que de borrones,
  así que el tope por bloque es `floor(ec/2)`. Y el entrelazado reparte un
  cuadrado central de forma despareja: algunos bloques se llevan casi todo el
  daño mucho antes de que el promedio se acerque al límite. Por eso la tabla
  sale de recorrer el zigzag real del encoder y contar, no de una regla de
  tres sobre el área.

  Se usa como máximo el **75% de ese presupuesto**. El 25% que queda no es
  cautela abstracta: la corrección que gasta el logo es corrección que después
  no está para la suciedad, los rayones y la mala luz sobre una placa grabada
  que vive a la intemperie.

  | ver | lado | hueco | peor bloque | logo (% del área) |
  |---|---|---|---|---|
  | 3 | 29×29 | 9 | 8/11 · 73% | 6,5% |
  | 4 | 33×33 | 11 | 6/8 · 75% | 8,1% |
  | 5 | 37×37 | 11 | 6/11 · 55% | 6,5% |
  | 6 | 41×41 | 13 | 7/14 · 50% | 7,7% |
  | 7 | 45×45 | 17 | 9/13 · 69% | 11,7% |

- **Desde la versión 7 hay un patrón de alineación exactamente en el centro**,
  que cualquier hueco tapa. Es un patrón de *función*: el lector lo usa para
  enderezar la perspectiva y Reed-Solomon no lo cubre, así que taparlo no se
  paga en codewords sino en que el lector encuadre peor. Con el logo centrado
  no hay forma de evitarlo; las versiones 7-10 llegan al lector con una
  referencia geométrica menos. Hoy no afecta a nadie —las URL de la app caen
  en v3-v6— pero si algún día `QR_BASE_URL` se alarga hasta v7, esto es lo
  primero a mirar si aparecen problemas de lectura.
- Esto es lo que obliga a la corrección de errores en **nivel H** en vez de M
  (ver arriba): con M el hueco tendría que ser bastante más chico que el de
  la tabla para dejar el mismo margen.

> **El pliego de grabado láser pide un ícono de 12-20% del área y eso no se
> puede cumplir en las versiones que usa la app.** Está calculado, no
> estimado: en v3 (29×29, que es donde cae `qr.example.com` en modo alfanumérico)
> el hueco siguiente ya rompe 12 codewords contra un tope de 11 — ilegible,
> aun gastando el 100% del presupuesto. En v4 se llega a 11,9% quemando el
> 88%, o sea sin nada de reserva para daño físico. El propio pliego resuelve
> el conflicto en su orden de prioridades: la escaneabilidad va primero y el
> ícono último. La tabla es el máximo alcanzable, no un recorte por comodidad.
- **Los dos favicons son el isotipo de la app, no del cliente**, y siguen
  hardcodeados a propósito en `Layouts/app.php` y `Seriales/qr.php` — no
  tienen que "terminar de conectarse" al logo por cliente.

## Descarga para grabadoras

La vista `/qr` ofrece **dos exportaciones y hay que elegir una**, porque las dos
formas de grabar un lote son distintas de verdad:

| | Ruta | Qué baja | Cuándo |
|---|---|---|---|
| **QR separados** | `/qr.zip` | Un SVG por código (`QRABCDEF.svg`), dentro de un `.zip` | Cada QR va en su propia placa: un archivo por placa, nombrado por el serial |
| **Hoja con todos** | `/qr-hoja.svg` | **Un solo SVG** con todos los códigos ubicados en hojas A4 verticales | Se graban todas las placas de una pasada sobre la misma plancha |

**Todo lo que se descarga es SVG.** El `.zip` no es otro formato de salida: es
un contenedor, porque son muchos archivos. No hay -ni se va a agregar- PNG ni
PDF; la razón es la de siempre, que el vectorial se agranda a cualquier tamaño
sin pixelar y es lo que entiende la grabadora.

Las dos salen de la **misma** función de grabado (`QrSvg::paraGrabado()` para el
archivo suelto, `QrSvg::enHoja()` para la pieza colocada, y las dos comparten el
cuerpo en `QrSvg::grabado()`): mismos milímetros, mismo dibujo, mismo logo en
negro. Está verificado que el `d` del path que emiten las dos es idéntico
carácter por carácter — la hoja no es un render aparte que pueda desviarse.

`QrSvg::paraGrabado()` es una variante de `render()`, no la misma salida, y las
tres diferencias son necesarias del lado de la grabadora (LightBurn, RDWorks
y compañía):

1. **Milímetros de verdad** en `width`/`height`, no píxeles. Sin unidad
   —`width="128"`— cada programa supone su propio DPI y el código entra con el
   tamaño equivocado.
2. **Sin el `<rect>` blanco de fondo**. En pantalla da contraste; la grabadora
   lo importa como una figura más y termina grabando —o cortando— el cuadrado
   entero.
3. **Sin atributos de pantalla** (`shape-rendering`, `role`, `aria-label`).

El logo **no** está en la lista: va en negro acá y también en pantalla (ver
"Logo en el centro" más arriba), así que las dos salidas comparten el dibujo
entero.

**La medida es por lote, no una constante global.** Se elige en el formulario
al generar (columna `tamano_mm` de `qr_seriales`, ver "Modelo de datos") y
las placas son siempre cuadradas, así que un solo número alcanza. Un cliente
puede tener un lote a 25 mm y otro a 40 mm sin conflicto: `SerialController`
arma cada archivo del `.zip` con la medida de su propia fila
(`SerialController::mmDe()`), así que un `.zip` sin filtrar por lote puede
traer varias medidas mezcladas, cada una en su archivo. Las filas generadas
antes de que existiera la columna quedan en NULL y caen al fallback
`MM_POR_DEFECTO = 25.0`, que es el valor único que se usaba siempre — así que
un lote viejo sigue bajando exactamente igual que antes de este cambio. La
zona de silencio va **incluida** en esa medida: el archivo es un cuadrado y el
código ocupa el centro con el margen claro que pide la norma. Esto vale para
las dos exportaciones —en la hoja cada placa también entra con la medida de su
lote, así que una hoja sin filtrar mezcla cuadrados de 25 y de 40 mm— pero **no**
para la vista `/qr`, que es de control (grilla de ~34 mm, QR de 128 px) y no
refleja la medida del lote.

### Cómo se arma la hoja

`SerialController::distribuir()` reparte las placas de izquierda a derecha y de
arriba abajo sobre hojas **A4 verticales** (210×297 mm), y `hoja()` emite un SVG
de 210 mm de ancho con `viewBox="0 0 210 <alto>"`: **una unidad de usuario es un
milímetro**, así que las coordenadas calculadas se usan tal cual.

- **Margen de 5 mm, no de 10.** Con 10 no entraría a lo ancho un lote de 200 mm,
  que es el máximo que acepta el formulario, y esa placa desbordaría la hoja.
  Entre placas vecinas van otros 5 mm, además de la zona de silencio que cada
  código ya trae adentro.
- **Las filas no tienen altura fija**: un pedido puede traer lotes de medidas
  distintas, así que cada fila alta lo que la placa más alta que le tocó. **No se
  reordena por tamaño** para aprovechar mejor el material: el orden del archivo
  es el mismo que el de la vista de control, y encontrar una placa concreta ahí
  adentro vale más que ganar unos milímetros.
- **Si no entran todos, se apilan más hojas dentro del mismo archivo**, una
  debajo de la otra: el alto total es un múltiplo exacto de 297 mm, así que
  cortar cada 297 da las páginas. **No se dibuja ninguna línea que las separe** —
  sería una figura más para la grabadora, exactamente por lo que `paraGrabado()`
  ya saca el `<rect>` de fondo. La división es sólo geométrica.
- Las guardas de salto de fila y de página piden que ya haya algo colocado
  (`$x > 0`, `$y > 0`). Es lo que evita el bucle infinito con una placa más ancha
  que la hoja: en vez de no entrar nunca, se coloca igual y desborda el margen.
  Y las comparaciones llevan una holgura de 0,001 mm, porque sin eso una placa de
  200 mm podía no entrar en un área útil de 200 mm por el error de punto flotante.
- Cada código se coloca con un **`<g transform="translate() scale()">`**, no con
  un `<svg>` anidado — el mismo criterio, y por el mismo motivo, que el logo (ver
  "Logo en el centro"): del otro lado hay parsers simples. Además la geometría de
  adentro queda en coordenadas de módulo, enteras: emitir cada path ya convertido
  a milímetros llenaría de decimales los cientos de nodos de cada código.
- Rige el mismo tope de 500 que la vista y el `.zip`. A 25 mm entran 54 por hoja,
  así que 500 códigos son 10 páginas y ~1,3 MB.

### Unión de módulos y nodos colineales

**Los módulos oscuros se unen geométricamente y de cada mancha se dibuja su
contorno**, tanto acá como en pantalla. No hay cuadraditos sueltos, ni tiras por
fila, ni rectángulos fusionados: `QrSvg::path()` emite de cada celda oscura sólo
los lados cuyo vecino está apagado —los lados compartidos entre dos celdas
oscuras saldrían en sentidos opuestos, así que no se emiten— y encadena esas
aristas en contornos cerrados. Eso *es* la unión booleana, sin ninguna librería
de por medio.

**Sobre cada contorno cerrado se eliminan después todos los nodos colineales.**
Un vértice sobrevive únicamente si el contorno cambia ahí de horizontal a
vertical o al revés; los que quedaban sólo porque ahí terminaba un módulo de la
grilla se descartan. El lado de un patrón de búsqueda es un segmento y no siete.
La reducción es **topológicamente exacta**: no aproxima, no redondea vértices, no
convierte rectas en curvas, no mueve ninguna esquina — las coordenadas de las
esquinas reales quedan enteras y sin tocar.

Un patrón de búsqueda entero son ahora **3 contornos de 4 nodos** (el cuadrado
exterior, el hueco interior y el centro macizo de 3×3) contra los 4 rectángulos
—16 nodos— de antes. Medido sobre 42 códigos reales, son **~15% menos nodos y
~22% menos bytes de path** que fusionando por rectángulos. Y además de acortar el
archivo:

- desaparecen las costuras entre figuras pegadas, que la grabadora marca en el
  material porque rellena figura por figura — con la unión no quedan figuras
  pegadas en absoluto, cada mancha es un contorno solo;
- el cabezal deja de frenar en los nodos redundantes de cada tramo recto.

> **Los contornos cubren exactamente los mismos módulos** que la matriz del
> encoder, y **con las dos reglas de relleno** — está verificado reparseando el
> path y evaluando el winding en el centro de cada módulo, contra `nonzero` y
> contra `evenodd`. Que valgan las dos no es casualidad: los huecos internos (el
> anillo blanco del patrón de búsqueda) salen con orientación opuesta a la del
> contorno que los rodea, y los contornos nunca se cruzan. Es lo que hace falta
> cuando del otro lado hay un importador de láser del que no se sabe qué regla
> aplica.
>
> El caso a mirar si algo se toca acá es la esquina donde **dos manchas se tocan
> sólo en diagonal**: ahí llegan dos aristas y salen otras dos, y elegir mal une
> los dos contornos en un ocho pinchado en ese punto. `QrSvg::siguiente()` gira
> siempre hacia el lado oscuro, que es lo que deja a cada mancha con su propio
> contorno cerrado.

El path va con `stroke="none"` explícito. Es el valor por omisión de SVG, pero
un importador que suponga un contorno donde no lo hay termina **cortando** el
borde de cada figura además de grabarla — mismo criterio que sacar el `<rect>`
de fondo.

> `Support/Zip.php` arma el `.zip` a mano, sin comprimir. La imagen de
> producción no trae `ext-zip` (el `Dockerfile` sólo compila `pdo_pgsql`) y
> sumar `libzip` para empaquetar unos SVG de texto no se justifica — mismo
> criterio que tener encoder de QR propio en vez de una librería.

Las dos exportaciones contienen **exactamente lo que muestra la vista `/qr`**,
tope de 500 incluido: si está recortada, la propia vista avisa que hay que
filtrar por lote. Comparten la selección y las guardas en
`SerialController::armarPedido()`, así que no pueden desincronizarse. El tope
no es sólo cosmético — sortear miles de matrices QR en PHP dentro de un mismo
request no termina en un tiempo razonable.

> `armarPedido(int $clienteId, string $lote)` no lee el query string: recibe
> los dos parámetros ya resueltos. `pedidoDeDescarga()` (panel, los toma de
> `$_GET`) y `pedidoPorToken()` (público, los toma del token del link de la
> grabadora — ver "Link para la grabadora") son los dos que la llaman. Es lo
> que garantiza que la ruta pública **no pueda** elegir cliente ni lote por su
> cuenta: los dos únicos caminos hacia `armarPedido()` desde `/grabado*` pasan
> por resolver el token primero.

## Link para la grabadora

Además de las dos exportaciones de "Descarga para grabadoras", el panel puede
emitir un **link público por lote**: una URL que se le pasa a la persona que
graba las placas -un tercero sin cuenta en el panel- para que vea y baje los
QR de esa tanda sin sesión. Abre `qr.example.com/WEQEWRW` y desde ahí puede ver
la grilla y descargar la hoja A4, el `.zip` o un código suelto: las mismas tres
salidas que tiene el panel, sólo que sin login.

**El link va en la raíz del dominio y es corto.** Es la forma que se pidió, y
no es cosmética: se dicta por teléfono y se retipea desde un papel. De ahí
salen tres decisiones que van juntas — el largo de 7, el alfabeto sin
caracteres confundibles, y que `deRuta()` acepte minúsculas (`/weqewrw` lleva
al mismo lado que `/WEQEWRW`; el path de una URL es sensible a mayúsculas por
RFC, pero acá el path entero es un identificador nuestro y podemos decidir que
no lo sea).

**El dominio del link sale de `QR_BASE_URL`, no de `location.origin`.** El link
tiene que decir `qr.example.com` siempre —es lo que se le dicta a un tercero, y
no puede cambiar según desde qué dominio lo copie quien lo manda—, así que lo
arma el servidor en `FormatoEnlace::url()` y viaja ya hecho en la API
(`enlace_url` en `list`, `url` en `crear_enlace`). El JS no concatena nada: no
tiene por qué conocer `QR_BASE_URL`, y así hay un solo lugar donde se pegan
base y token, igual que `FormatoSerial::url()` con el serial. Es también la
razón por la que el link y el QR impreso comparten variable: los dos cuelgan
de la misma raíz, uno en `/TOKEN` y el otro en `/SERIAL`.

> Con `QR_BASE_URL` vacía, `FormatoEnlace::url()` devuelve `/TOKEN` a secas.
> Es relativo, así que el navegador lo resuelve contra el origen actual y el
> botón "Copiar link" sigue dando algo usable en desarrollo — que es
> exactamente lo que hacía antes con `location.origin`.

> **Las tres descargas siguen con el token en `?t=`**, no en el path. No se
> tipean ni se comparten: se clickean desde la página, y las tres bajan como
> `attachment` sin cambiar la barra de direcciones. La forma corta sólo hace
> falta en la URL que efectivamente se le pasa a alguien.

**El token es opaco y sorteado, no firmado.** Un HMAC o un base64 llevarían el
lote **en claro** al lado de la firma -se decodifican de memoria- y eso
violaría el requisito central: si alguien tipea mal un carácter, tiene que dar
**404**, nunca caer en otro lote existente y hacer que la grabadora termine
grabando la tanda equivocada. Por eso el token es un valor sorteado con
`random_int` y guardado en `qr_enlaces` (`FormatoEnlace`, `Models/Enlace`) —
mismo criterio que el serial, que es opaco por la misma razón (ver "Formato
del serial").

**Son 7 símbolos de un alfabeto de 30** (sin `0 1 I L O U`, los pares que se
confunden al leer o tipear): 30⁷ ≈ 2,19 × 10¹⁰, o sea **~34 bits**, no los ~98
de un token largo. Con 100 enlaces activos, acertar uno al azar sale 4,6 × 10⁻⁹
por intento — unos 219 millones de requests para esperar un acierto, semanas de
tráfico continuo y ruidoso —, y del otro lado hay los SVG de una tanda y el
nombre de un cliente. Es un margen aceptable para eso, pero **ya no es "no se
enumera"**, así que:

- `noEncontrado()` retarda **300 ms** cada 404, mismo criterio que el retardo
  ante contraseña incorrecta (ver "Autenticación"): no reemplaza a un bloqueo
  por intentos, encarece la fuerza bruta. Va en `noEncontrado()` y no en
  `resolverToken()` a propósito, así también retarda el 404 de un token válido
  con el lote vacío — que es lo que los deja indistinguibles por tiempo además
  de por cuerpo.
- Si algún día hubiera decenas de miles de enlaces activos, esto es lo primero
  a revisar: el riesgo escala con la cantidad de tokens vivos, no con el
  tráfico.

**El enlace no vence; se anula a mano.** Vencerlo automáticamente resolvería
un riesgo chico (aun a 34 bits, encontrar un token por fuerza bruta es
impracticable en la escala de esta app) a costa de uno real: que la grabadora
se quede a mitad de un trabajo largo con un link muerto. La revocación es una
acción explícita del panel ("Anular"), visible y deliberada, y no un timer
corriendo en silencio.

**Nace solo, al generar el lote.** `Serial::generar()` llama a
`Enlace::asegurar()` dentro de su propia transacción apenas hay al menos un
código creado (con cero creados el controller ya responde 409, y un enlace a
un lote vacío sería basura activa en la tabla). `asegurar()` es **idempotente**
a propósito, no se llama `crear()`: dos generaciones del mismo cliente en el
mismo minuto caen en el mismo `lote` (la etiqueta es `AAAAMMDD-HHMM`), así que
la segunda tiene que devolver el token que ya existe, no fallar ni duplicarlo.
Para los lotes generados antes de esta funcionalidad, el panel ofrece "Crear
link" a demanda — es el mismo camino que hace falta igual para reemitir un
link después de anularlo.

> **No hay backfill del token en SQL, y es a propósito.** Sortearlo desde
> `INSERT ... SELECT` obligaría a usar `random()` de Postgres, que es un PRNG
> por sesión y no el generador criptográfico del sistema — justo lo que se
> descarta para el serial, que es *menos* sensible que esto. Además un
> `INSERT ... SELECT` no puede reintentar ante un choque de token: la fila se
> perdería en silencio. El botón "Crear link" resuelve los lotes viejos sin
> ese riesgo.

**Es sólo por lote, nunca "todo el cliente".** Un link a todo el histórico de
un cliente cambiaría de contenido cada vez que se genera un lote nuevo, y
mezclaría tandas que capaz se mandan a grabar en momentos distintos. Un link
por lote, en cambio, apunta siempre al mismo conjunto de códigos: nace con el
lote y muestra exactamente eso, para siempre (o hasta que se anule).

**A lo sumo un enlace activo por lote, en la base y no en PHP**
(`qr_enlaces_lote_activo_idx`, índice único parcial `WHERE activo`): sin él,
dos clicks simultáneos en "Crear link" dejarían dos tokens vivos para el mismo
lote, y anular uno no cerraría el acceso del otro. Es también lo que hace
segura la consulta de `Serial::lotesDeCliente()` (que hace `LEFT JOIN
qr_enlaces ... AND activo`): garantiza que el `JOIN` nunca traiga más de una
fila por lote, así que el `COUNT(*)` de la tabla de lotes no se infla.

**La página pública (`SerialController::grabado()`) muestra el nombre del
cliente**, la medida en mm y la grilla — la misma vista `qr.php` que usa el
panel, parametrizada con `$meta`/`$rutas`/`$publico` en vez de duplicada (ver
`SerialController::pintarVista()`). No lee `cliente_id` ni `lote` del query
string **nunca**: los dos salen siempre de `Enlace::porToken()` — el token le
llega ya reconocido desde `public/index.php`, por parámetro. Las cuatro rutas
públicas (el token en la raíz, `/grabado.zip`, `/grabado-hoja.svg`,
`/grabado-unidad.svg`) responden el mismo `404 - Enlace no válido`,
indistinguible, ante token con formato roto, token inexistente y token
anulado — no se le da a nadie ninguna pista de cuál de los tres pasó. Llevan
además `X-Robots-Tag: noindex, nofollow`, `Referrer-Policy: no-referrer` y
`Cache-Control: no-store`.

> **No hay contador de intentos ni `error_log` de los tokens fallidos.** Con el
> retardo de 300 ms de arriba, un contador por IP no agregaría protección real
> contra un atacante distribuido -sólo una tabla más- y un registro de fallos
> llenaría los logs de ruido, porque cualquier escaneo de rutas de internet
> produce 404 acá. Si el alfabeto o el largo del token se achicaran todavía
> más, es la primera guarda a reconsiderar.

**Un cliente dado de baja no corta sus enlaces activos.** La baja lógica
(`activo = FALSE`) significa "no se generan códigos nuevos para este
cliente", no "revocar el acceso a lo ya emitido": las placas ya encargadas se
siguen grabando igual que antes de la baja. La única forma de cortar un
enlace es "Anular", explícita — por eso `anularEnlace()` usa
`Cliente::find()` y no `Cliente::existeActivo()`: hay que poder anular el
enlace de un cliente que se acaba de dar de baja.

**La descarga individual filtra `origen = 'generado'`.** Sin ese filtro, tanto
`/qr-unidad.svg` (panel) como `/grabado-unidad.svg` (público) serían la única
forma de sacarle un archivo de grabado a un código importado, que por diseño
no produce imagen (ver "Importar códigos que ya existían"). Y en el modo
público, `Serial::buscarEnLote()` exige además que el serial pedido pertenezca
**a ese lote de ese cliente**: no alcanza con que el código exista, porque si
alcanzara, un token válido serviría para bajar cualquier código de cualquier
cliente con sólo cambiar `?serial=`.

**El token viaja en la URL, y por lo tanto en los logs del servidor y en el
historial del navegador de quien lo abre.** Con el token en el path pasa
exactamente igual que con `?t=`: la URL entera se registra igual. Es el mismo
tipo de credencial que ya es la URL del QR, que también viaja en la URL y
también termina impresa: se acepta ese costo, no se agrega ninguna mitigación
especial para este caso.

## Autenticación

Una sola contraseña, sin usuarios. En `ADMIN_PASSWORD_HASH_B64` va el **hash
bcrypt codificado en base64**, así que quien lea el `.env` o las variables de
EasyPanel no se lleva la clave.

- **Por qué base64 y no el hash directo:** un hash bcrypt empieza con
  `$2y$10$...`, y varios sistemas de variables de entorno (Docker Compose entre
  ellos) interpretan un `$` como el inicio de otra variable y truncan el valor
  sin avisar — un fallo silencioso que deja el login roto sin ningún error
  visible. En base64 no hay ningún carácter especial que se preste a eso.
  `config/app.php` decodifica con `base64_decode(..., true)`: si el resultado
  no es válido, se descarta y el login queda bloqueado en vez de arrancar con
  un hash corrupto.
- `password_verify` ya compara en tiempo constante; no hace falta `hash_equals`.
- Al autenticarse se hace `session_regenerate_id(true)` para evitar fijación de
  sesión.
- La cookie va `HttpOnly` y **`SameSite=Strict`**: eso impide que una página
  ajena dispare un POST contra la API usando la sesión del usuario, que es lo
  que un token CSRF resolvería. Si en algún momento hiciera falta aflojar el
  `SameSite`, hay que agregar el token.
- `secure` se decide mirando también `X-Forwarded-Proto`, porque EasyPanel
  termina TLS en su proxy y le habla HTTP a la app.
- Tras una contraseña incorrecta hay un retardo de 400 ms. No reemplaza a un
  bloqueo por intentos, pero encarece la fuerza bruta.

### La sesión se recuerda 30 días

La contraseña se escribe una vez y no se vuelve a pedir. **No alcanza con
alargar la sesión de PHP**: sus archivos viven en el disco del contenedor, que
se reemplaza en cada deploy, así que cualquier despliegue expulsaría a todo el
mundo. Por eso el recuerdo va en una cookie firmada aparte
(`neoqr_recordado`), que no depende de nada guardado en el servidor.

- Formato `<vence>.<HMAC-SHA256>`. No lleva la contraseña ni nada reversible.
- **La clave del HMAC es el propio hash de la contraseña.** De ahí sale gratis
  una propiedad valiosa: cambiar la contraseña cambia la clave, y eso invalida
  de una todos los dispositivos recordados. No hace falta otra variable de
  entorno ni una tabla de tokens.
- El vencimiento **se corre en cada visita**, así que mientras el panel se use
  no caduca nunca. Si caducara a los 30 días del login, no sería "quedar
  guardada".
- `hash_equals` para comparar la firma: con `==` se podría deducir la firma
  correcta midiendo cuánto tarda en responder.
- `salir()` **tiene que borrar esta cookie además de la de sesión**. Si no, el
  request siguiente reconstruiría la sesión y el botón Salir no haría nada.
- Va con las mismas protecciones que la de sesión: `HttpOnly`, `SameSite=Strict`
  y `Secure` cuando corresponde.

## Convenciones heredadas del proyecto previo

No están escritas en ningún otro lado — su `CLAUDE.md` delega a un
*"CLAUDE.md raíz del workspace"* **que no existe**.

**Se copia tal cual:**
- `config/*.php` devuelven arrays y se cargan con `require` en el punto de uso.
- `config/database.php` con env obligatorias, driver `pgsql`, 500 si falta alguna.
- `Database::getConnection(): PDO` — singleton estático, `ERRMODE_EXCEPTION`,
  `FETCH_ASSOC`, `EMULATE_PREPARES => false`.
- Modelos 100% `static`, SQL a mano, prepared statements con placeholders nombrados.
- Controllers con un `render()` privado (`extract` + `ob_start`), doble pasada:
  vista → `$content` → `Layouts/app`.
- Commits en **español, imperativo, describiendo el efecto del cambio**.

**Se documenta explícitamente para NO copiarlo:**
- `Database.php` de allá hace `echo JSON + exit` ante fallo de conexión y filtra
  `$e->getMessage()` al cliente. Acá la excepción se propaga y el mensaje de PDO
  no sale nunca en la respuesta.
- El `HealthController` del hermano tuvo que **esquivar** `getConnection()` por
  eso mismo. Se porta su parte buena: conexión propia con `connect_timeout=3`,
  `SELECT 1`, **503** si la base no responde, detalle al `error_log`,
  `Cache-Control: no-store`.
- Errores de negocio con HTTP 200. Acá se usan códigos reales.
- CSS remoto con `?v=<?= time() ?>` (cero caché). Acá el CSS va inline, con
  custom properties; sólo tema claro, sin bloque `prefers-color-scheme` (ver
  el comentario en `Layouts/app.php`). Los `/js/*.js`, que sí son archivos
  sueltos, van con **`?v=<mtime>`** — ver "Caché de los JS" más abajo: se
  cachean fuerte y se invalidan solos, que es justo lo que `time()` no hace.
- Vistas sin `htmlspecialchars` confiando en un `escapeHtml()` de JS. Acá se
  escapa en PHP.
- Un `clientes.js` de miles de líneas con estado global y `onclick=` inline. El
  patrón (fetch contra la API JSON) se mantiene; el tamaño y el estado global no.
- Tokens hardcodeados y auth por `?super=true` en query string.
- `public/autoPull.php`: acá el deploy lo hace EasyPanel por su propio webhook.
- N+1 al listar: el conteo de seriales por cliente sale de un `LEFT JOIN`, no de
  una query por fila.

## Fuera de alcance

- **Exportación a CSV.** Se descartó; para llevar los códigos a la imprenta está
  la hoja de QR (`/qr`).
- **Resolver qué pasa al escanear un QR.** La URL que lleva el código
  (`{QR_BASE_URL}/{serial}`) **no la atiende nadie: devuelve 404**, y eso está
  decidido así, no es un bug pendiente de arreglo. Se consultó y la respuesta
  fue no ocuparse del 404 por ahora.

  Consecuencia a tener presente: hoy el sistema sirve para **generar e
  inventariar** códigos, no para imprimirlos — un QR pegado en el mundo real no
  llevaría a ningún lado.

  Cuando se decida el destino, la forma que menos ata es guardarlo en la base
  por serial y redirigir: así el código impreso nunca queda obsoleto, porque el
  destino se cambia sin reimprimir. Lo único que queda congelado para siempre es
  el dominio (ver Pendientes).

## Desarrollo local

`docker-compose.yml` levanta **sólo la app**, con el mismo `Dockerfile` que va a
producción (Apache + `mod_rewrite` + `pdo_pgsql` ya resueltos). La base **no**
está en el compose: se reutiliza un contenedor PostgreSQL que ya corre en la
máquina (acá se lo llama `postgres-18`), publicado en el puerto **5433** del
host. La base `neoqr` convive ahí con las demás sin tocarlas, igual que en
producción convive con las de otros proyectos.

Preparar la base, una sola vez (el esquema es idempotente, se puede repetir).
`$PGUSER` es el superusuario de ese contenedor, y `-d postgres` es sólo la base
desde la cual conectarse para poder emitir el `CREATE DATABASE`:

```bash
docker start postgres-18
docker exec -i postgres-18 psql -U "$PGUSER" -d postgres -c "CREATE DATABASE neoqr OWNER \"$PGUSER\";"
docker exec -i postgres-18 psql -v ON_ERROR_STOP=1 -U "$PGUSER" -d neoqr < db/schema.sql
```

Después, copiar `.env.example` a `.env` (`DB_HOST=host.docker.internal`,
`DB_PORT=5433`, `DB_DATABASE=neoqr`, y `QR_BASE_URL=http://localhost:8080`) y:

```bash
docker compose up -d --build     # http://localhost:8080
docker compose logs -f app       # el 500 del navegador no trae detalle: sale acá
```

La contraseña del panel sale de `ADMIN_PASSWORD_HASH_B64` igual que en
producción — vacío es login bloqueado, no login abierto. Se genera con el
`php -r` que documenta `.env.example`.

Tres cosas del compose que no son arbitrarias:

- **`DB_HOST` es `host.docker.internal` y no `postgres-18`.** Ese contenedor
  corre en la red bridge por defecto, donde Docker **no** resuelve nombres de
  contenedor; el nombre no conectaría. Por eso el servicio lleva
  `extra_hosts: host.docker.internal:host-gateway` y apunta al puerto
  publicado (5433), no al 5432 interno.
- **No hay `environment:` ni `env_file:`: los valores viven sólo en el `.env`,**
  que llega al contenedor por el bind-mount (el parser de `public/index.php` lo
  busca en `BASE_PATH`, o sea `/var/www/html/.env`). Duplicarlos en el compose
  abriría un caso borde de ese parser: una variable presente **pero vacía** en
  el entorno del proceso le gana al `.env` —`getenv()` devuelve `""`, no
  `false`— y entonces `config/database.php` aborta con 500 diciendo que falta.
- **El bind-mount `.:/var/www/html` tapa `/var/www/html/.build`**, así que el
  pie del panel sale sin sello de deploy. Es esperado, no un bug:
  `Layouts/app.php` lo lee con `@file_get_contents(...) ?: ''` y degrada solo.

> El puerto del host es el **8080**: el 80 ya está ocupado en esta laptop.
> Como `QR_BASE_URL` incluye ese puerto, los QR que se generen en desarrollo
> codifican `http://localhost:8080/QRABCDEF` — sirven para probar, no para
> imprimir.

## Producción

Desplegado en EasyPanel, detrás del proxy de Cloudflare, con PostgreSQL como
servicio aparte del mismo proyecto.

> **Los valores concretos —dominios, IPs, nombres de proyecto y de servicio,
> nombre y usuario de la base— no viven en este repo, que es público.** Están
> en el panel de EasyPanel y en las variables de entorno del servicio. Acá
> queda sólo lo que hace falta para entender las decisiones de diseño y para
> diagnosticar los dos modos de falla de abajo, que no son obvios.

- **El tráfico entra por el proxy de Cloudflare, no directo al origen.** Eso es
  lo que hace obligatorio el cache-buster de los JS (ver "Caché de los JS"): el
  borde cachea los estáticos aunque el HTML salga siempre dinámico.
- La cookie de sesión sigue saliendo `Secure` detrás del proxy porque
  `Auth::esHttps()` mira `X-Forwarded-Proto` — ni Cloudflare ni EasyPanel le
  hablan HTTPS a la app, terminan TLS antes.
- **El host interno de la base es `<proyecto>_<servicio>`**, con el nombre del
  servicio escrito exactamente como figura en el panel, erratas incluidas. Si
  el nombre no coincide carácter por carácter no resuelve, y el síntoma no es
  un error de DNS legible sino la falla de promoción de más abajo.
- La base de esta app es **propia y aparte** de las de otros proyectos que
  comparten el mismo servicio PostgreSQL.
- El esquema se aplica **a mano** con `psql` desde la terminal del servicio de
  base de datos. No hay migraciones automáticas: cualquier cambio futuro en
  `db/schema.sql` hay que aplicarlo igual, a mano.
- El deploy es **automático al pushear a `main`**, por webhook de la
  plataforma. El build corre solo y suele salir bien; lo que puede fallar en
  silencio es el paso siguiente.

> ⚠️ **Un cambio de esquema tiene que aplicarse en producción *antes* del push
> que lo asume.** El deploy es automático pero el esquema no: si el código
> nuevo llega primero, cualquier query que use una columna que todavía no
> existe rompe en caliente. Pasó con las columnas `logo_svg`/`logo_viewbox`
> de "Logo en el centro" — el `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` de
> `db/schema.sql` se corrió a mano en el servicio de base de datos antes de
> mergear el cambio que las lee.

> ⚠️ **Si el deploy figura en verde pero la app sigue sirviendo el código viejo**,
> el culpable casi seguro es el `HEALTHCHECK` del `Dockerfile`. `/health`
> verifica la conexión a PostgreSQL, así que si el contenedor nuevo no puede
> llegar a la base nunca alcanza el estado *healthy*, y Swarm lo descarta
> manteniendo el anterior. El deploy figura exitoso porque **el build sí
> funcionó**: lo que falló es la promoción, que es un paso posterior.
>
> Cómo diagnosticarlo:
> - `cat /var/www/html/.build` dentro del contenedor da la fecha real de
>   construcción de la imagen. Si no cambió, el contenedor no se reemplazó.
> - Los logs del servicio muestran la secuencia completa: el arranque, los
>   `[health] SQLSTATE[...]` con el error concreto, y el
>   `caught SIGWINCH, shutting down gracefully` con el que Swarm lo mata.
>
> Es el healthcheck haciendo su trabajo —evita publicar un contenedor roto—,
> no una falla del deploy.

### Caché de los JS

**Los `<script>` del layout salen con `?v=<mtime del archivo>`**
(`Layouts/app.php`, al final). Es obligatorio, no una optimización:

- Las páginas las genera PHP y salen `DYNAMIC` — Cloudflare no las guarda.
- Pero `/js/clientes.js` y `/js/seriales.js` son **archivos estáticos**, y
  Cloudflare los cachea por su cuenta con `max-age=14400`: **cuatro horas**.

Sin el sufijo, durante esas cuatro horas el navegador combina **HTML nuevo con
JS viejo**, y eso no degrada: rompe. Pasó de verdad al estrenar el dominio
detrás de Cloudflare —hasta entonces se entraba por una URL que iba directo al
origen, sin borde que cacheara nada, así que el problema nunca se había
visto—: el `seriales.js` que servía el borde era anterior a "Link para la
grabadora" y hacía `btnVerExistentes.hidden = true` sobre un botón que la
vista nueva ya había borrado. `TypeError` **antes** de pedir los datos, así que
la tabla de lotes se quedaba en "Cargando…" para siempre, sin error visible.

Se usa el **mtime** y no `time()` (que anula el caché entero, justo lo que este
archivo documenta como algo a no copiar del proyecto previo) ni una versión a
mano como `config/app.php['version']` (una más para acordarse de subir, y
olvidarse vuelve a traer el bug). El mtime cambia exactamente cuando cambia el
archivo — EasyPanel hace `git fetch` sobre una copia existente y git sólo
reescribe lo que cambió, así que un deploy que no toca el JS tampoco le mueve
la versión.

> Como el HTML nunca se cachea, esto **se arregla solo con desplegar**: el
> siguiente request pide una URL nueva y el borde va a buscarla al origen. No
> hace falta purgar Cloudflare. Purgar sirve sólo para destrabar en el momento,
> antes de tener el arreglo arriba.

## Pendientes

**Mudar al dominio definitivo**, que es corto y es el que se pidió para el link
de la grabadora. Hoy sigue sirviendo el subdominio automático que asigna la
plataforma, que es provisorio y bastante más largo.

> ⚠️ **`QR_BASE_URL` ya no gobierna sólo el QR impreso: también es el dominio
> que dice el link de la grabadora** (`FormatoEnlace::url()`). O sea que
> apuntarla al dominio definitivo antes de que el DNS resuelva emite links que
> dicen lo correcto pero todavía no abren. Es la misma condición que ya tenía
> el QR impreso, sólo que ahora se nota antes — desde el botón "Copiar link"
> del panel, no recién al escanear una placa.

Los pasos, en este orden —que importa:

1. En el DNS del dominio, un registro `A` explícito para el subdominio,
   apuntando al servidor de la app.
2. **Recién cuando resuelva**, agregar el dominio en el panel de la plataforma
   (servicio → Domains → Add Domain). Ahí sí emite el certificado: si se
   agrega antes, el desafío ACME llega al servidor equivocado y la emisión
   falla.
3. Actualizar `QR_BASE_URL` y `APP_URL`, guardar y desplegar.

> La imagen del QR no se guarda: se dibuja al momento con `QR_BASE_URL` + el
> serial. Cambiar esa variable repunta **todos** los códigos ya generados, lo
> cual es gratis hoy y deja de serlo apenas se imprima el primer lote.

> La mudanza también achica el código, **además** de lo que ya ganó el modo
> alfanumérico (ver "Cuántos módulos tiene el código"). Con nivel H y logo, la
> URL provisoria (46 caracteres) hoy cae en versión 4 (33×33 módulos); un
> dominio corto (~30 caracteres de URL) entra en versión 3 (29×29). A los mismos
> 25 mm, eso es pasar de un módulo de ~0,61 mm a uno de ~0,68 mm — un margen
> real para la grabadora, no sólo un detalle cosmético. Las dos palancas se
> suman: desde los ~0,51 mm originales, es un módulo un tercio más grande.

> **Qué verificar antes de elegir el dominio**, que ya costó un candidato
> descartado: que el dominio raíz no esté sirviendo otro sitio en otro
> servidor -apuntarlo entero a la plataforma lo bajaría-, y que no haya un
> comodín `*` en su DNS. Con un comodín, cualquier subdominio inventado "ya
> existe" pero resuelve al servidor equivocado, lo cual confunde el
> diagnóstico; un registro explícito le gana igual, pero conviene saberlo de
> antemano y no descubrirlo cuando falla la emisión del certificado.
