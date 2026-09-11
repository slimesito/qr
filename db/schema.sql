-- Esquema de neo-qr. Base propia e independiente.
--
-- Las tablas van con prefijo qr_: si alguna vez esta base termina conviviendo
-- con otra app, los nombres no chocan.
--
-- Se aplica a mano sobre la base de EasyPanel (no hay herramienta de
-- migraciones: el proyecto es PHP vanilla, sin composer). Es idempotente,
-- así que se puede volver a correr sin romper nada.

CREATE TABLE IF NOT EXISTS qr_clientes (
    id        INTEGER PRIMARY KEY GENERATED ALWAYS AS IDENTITY,
    nombre    TEXT        NOT NULL,
    activo    BOOLEAN     NOT NULL DEFAULT TRUE,
    creado_en TIMESTAMPTZ NOT NULL DEFAULT now(),
    baja_en   TIMESTAMPTZ
);

-- Logo que se incrusta en el centro del QR de este cliente (ver "Logo en el
-- centro" en CLAUDE.md). Nullable: los clientes que ya existían antes de esta
-- columna siguen con el placeholder de QrLogo::porDefecto() hasta que alguien
-- les cargue uno. logo_svg es el markup ya saneado por SaneadorSvg (sólo
-- geometría, sin color propio); logo_viewbox es el viewBox de ese logo como
-- texto "minX minY ancho alto", que QrLogo::desdeBase() parsea al leerlo.
--
-- CREATE TABLE IF NOT EXISTS no agrega columnas a una tabla que ya existe, así
-- que en una base con la tabla ya creada estos ALTER son los que hacen
-- efecto; son inocuos en una base nueva, donde ya salen así de CREATE TABLE.
ALTER TABLE qr_clientes ADD COLUMN IF NOT EXISTS logo_svg     TEXT;
ALTER TABLE qr_clientes ADD COLUMN IF NOT EXISTS logo_viewbox TEXT;

-- Las dos columnas no significan nada por separado: o están las dos, o
-- ninguna (el cliente sigue con el placeholder).
ALTER TABLE qr_clientes DROP CONSTRAINT IF EXISTS qr_clientes_logo_completo;
ALTER TABLE qr_clientes ADD  CONSTRAINT qr_clientes_logo_completo
    CHECK ((logo_svg IS NULL) = (logo_viewbox IS NULL));

CREATE TABLE IF NOT EXISTS qr_seriales (
    id         BIGINT PRIMARY KEY GENERATED ALWAYS AS IDENTITY,

    -- QR + 6 letras. El UNIQUE es lo que garantiza la regla "los QR no se
    -- repiten": los códigos se sortean al azar, así que las colisiones ocurren
    -- de verdad, y acá es donde se detectan. Un SELECT previo desde PHP no
    -- alcanzaría: entre el chequeo y el INSERT otro request puede tomar el
    -- mismo código.
    serial     TEXT        NOT NULL UNIQUE,

    cliente_id INTEGER     NOT NULL REFERENCES qr_clientes(id),

    -- 'generado' (sorteado por la app) o 'importado' (código que ya existía y
    -- se dio de alta pegándolo). Los importados no producen imagen: son un
    -- inventario de reserva, no una tanda para imprimir.
    origen     TEXT        NOT NULL DEFAULT 'generado',

    -- Etiqueta de la tanda (AAAAMMDD-HHMM). Como el serial es opaco y no lleva
    -- la fecha adentro, esta columna es lo único que agrupa una generación.
    lote       TEXT,

    -- Lado en milímetros de la placa de este código, elegido al generar el
    -- lote. Las placas son cuadradas: una sola medida alcanza. Es lo que usa
    -- el SVG de grabado (/qr.zip); la hoja /qr es una vista de control y no
    -- la mira. NULL en dos casos: los seriales generados antes de esta
    -- columna (caen al fallback de 25 mm, que es lo que se venía emitiendo) y
    -- los importados, que ya no producen imagen.
    tamano_mm  NUMERIC(5,1),

    creado_en  TIMESTAMPTZ NOT NULL DEFAULT now(),

    CONSTRAINT qr_seriales_formato CHECK (serial ~ '^QR[A-Z]{6}$'),
    CONSTRAINT qr_seriales_origen  CHECK (origen IN ('generado', 'importado'))
);

-- CREATE TABLE IF NOT EXISTS no agrega columnas a una tabla que ya existe:
-- para una base con qr_seriales creada antes de tamano_mm, este ALTER es el
-- que hace efecto. Inocuo en una base nueva, donde ya sale así de CREATE TABLE.
ALTER TABLE qr_seriales ADD COLUMN IF NOT EXISTS tamano_mm NUMERIC(5,1);

ALTER TABLE qr_seriales DROP CONSTRAINT IF EXISTS qr_seriales_tamano;
ALTER TABLE qr_seriales ADD  CONSTRAINT qr_seriales_tamano
    CHECK (tamano_mm IS NULL OR (tamano_mm >= 5 AND tamano_mm <= 200));

-- Filtrar los seriales de un cliente, y dentro de un cliente los de una tanda.
CREATE INDEX IF NOT EXISTS qr_seriales_cliente_idx ON qr_seriales (cliente_id);
CREATE INDEX IF NOT EXISTS qr_seriales_lote_idx    ON qr_seriales (cliente_id, lote);

-- No hace falta índice extra sobre serial: el UNIQUE ya crea uno, y las
-- búsquedas son siempre por igualdad exacta.

-- Enlace público por lote para la grabadora (ver "Link para la grabadora" en
-- CLAUDE.md). Es una credencial: quien tiene el token ve el lote entero y baja
-- sus archivos sin sesión.
--
-- El token es un valor **opaco sorteado y guardado**, y no el lote firmado o
-- codificado: un base64 se decodifica de memoria y un HMAC igual lleva el
-- lote en claro al lado de la firma. Con el lote legible en la URL, un
-- carácter mal tipeado puede caer en otro lote existente y la grabadora
-- termina grabando la tanda equivocada. Mismo criterio que el serial, que es
-- opaco exactamente por lo mismo.
CREATE TABLE IF NOT EXISTS qr_enlaces (
    id         BIGINT PRIMARY KEY GENERATED ALWAYS AS IDENTITY,

    -- 7 símbolos de un alfabeto de 30 (dígitos y letras sin los ambiguos
    -- 0/O, 1/I/L y la U, estilo Crockford): ~34 bits. El largo lo fija la
    -- forma pedida para el link, qr.example.com/WEQEWRW, que se dicta por
    -- teléfono y se retipea desde un papel; el margen y su contrapartida
    -- están en el docblock de app/Support/FormatoEnlace.php.
    -- El UNIQUE es lo que garantiza que no se repita: se sortea con
    -- random_int y el choque lo detecta la base con ON CONFLICT, no un SELECT
    -- previo -mismo criterio que el serial.
    token      TEXT        NOT NULL UNIQUE,

    cliente_id INTEGER     NOT NULL REFERENCES qr_clientes(id),

    -- La tanda a la que da acceso. No hay FK: (cliente_id, lote) no es único
    -- en qr_seriales, son N filas. Si el lote no tuviera códigos, la página
    -- pública no encuentra nada y responde el mismo 404 que un token inválido.
    lote       TEXT        NOT NULL,

    -- El enlace no vence: se anula a mano desde el panel. Anular no borra la
    -- fila, así queda el historial de qué enlace se cortó y cuándo.
    activo     BOOLEAN     NOT NULL DEFAULT TRUE,
    creado_en  TIMESTAMPTZ NOT NULL DEFAULT now(),
    baja_en    TIMESTAMPTZ,

    CONSTRAINT qr_enlaces_token_formato CHECK (token ~ '^[2-9A-HJKMNP-TV-Z]{7}$')
);

-- Mismo patrón que las CHECK de qr_clientes/qr_seriales: CREATE TABLE IF NOT
-- EXISTS no toca una tabla que ya existe, así que el DROP + ADD es lo que
-- hace efecto si algún día cambia el formato del token.
ALTER TABLE qr_enlaces DROP CONSTRAINT IF EXISTS qr_enlaces_token_formato;
ALTER TABLE qr_enlaces ADD  CONSTRAINT qr_enlaces_token_formato
    CHECK (token ~ '^[2-9A-HJKMNP-TV-Z]{7}$');

-- **A lo sumo un enlace activo por lote**, en la base y no en PHP: sin este
-- índice, dos clicks simultáneos en "Crear link" dejarían dos tokens vivos
-- para el mismo lote y anular uno no cerraría el acceso. Los anulados no
-- participan del índice, así que un lote puede acumular todos los que quiera
-- mientras sólo uno esté activo.
CREATE UNIQUE INDEX IF NOT EXISTS qr_enlaces_lote_activo_idx
    ON qr_enlaces (cliente_id, lote) WHERE activo;

-- No hace falta índice sobre token: el UNIQUE ya crea uno y las rutas
-- públicas siempre buscan por igualdad exacta.

-- **No hay backfill de los lotes que ya existen, y es a propósito.** Sortear
-- el token desde SQL obligaría a usar random(), que es un PRNG por sesión y
-- no el generador criptográfico del sistema -justo lo que se descarta para el
-- serial, que es *menos* sensible que esto. Además un INSERT ... SELECT no
-- puede reintentar ante un choque de token: la fila se perdería en silencio.
-- Los lotes viejos se resuelven con el botón "Crear link" del panel, que hace
-- falta igual para reemitir un enlace después de anularlo.
