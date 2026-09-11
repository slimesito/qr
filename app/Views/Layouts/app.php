<?php
/**
 * @var string $titulo
 * @var string $contenido  HTML ya renderizado por el controller
 * @var array  $scripts    rutas de JS a cargar al final del body
 * @var bool   $sidebar    true si la vista trae el sidebar de clientes (hoy sólo Clientes/index)
 */
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($titulo) ?> · neo-qr</title>
<?php // Favicon embebido: evita el request a /favicon.ico que si no ensucia el log con un 404. ?>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'><rect width='16' height='16' rx='3' fill='%23145239'/><path fill='%23fff' d='M3.5 3.5h3v3h-3zM9.5 3.5h3v3h-3zM3.5 9.5h3v3h-3zM8.5 8.5h1.5v1.5H8.5zM11 11h1.5v1.5H11z'/></svg>">
<style>
  /* CSS propio e inline, a propósito: no se depende de hojas remotas ni de un
     cache-buster con time() como el proyecto previo, que fuerza descargar el CSS
     en cada request.

     Tema claro únicamente. No hay bloque prefers-color-scheme: se decidió que
     la app va en claro, y color-scheme abajo evita que el navegador oscurezca
     por su cuenta los controles de formulario. */
  :root {
    color-scheme: light;

    --ground:      #f7f7f6;
    --surface:     #ffffff;
    --ink:         #17201c;
    --ink-soft:    #414b46;
    --muted:       #7c8783;
    --line:        #e6e8e6;
    --line-soft:   #f1f2f1;

    --accent:      #145239;
    --accent-hover:#0e3c29;
    --accent-soft: #eef4f1;
    --accent-ink:  #ffffff;

    --alerta:      #8a4b1a;
    --alerta-fondo:#fdf6ef;
    --alerta-line: #ecd9c4;

    --radio:       10px;
    --radio-chico: 7px;
    --sombra:      0 1px 2px rgba(23,32,28,.04), 0 4px 12px -6px rgba(23,32,28,.10);
  }

  * { box-sizing: border-box; }

  html { -webkit-text-size-adjust: 100%; }

  body {
    margin: 0;
    min-height: 100vh;
    background: var(--ground);
    color: var(--ink);
    font-family: ui-sans-serif, -apple-system, "Segoe UI Variable Text", "Segoe UI",
                 Inter, Roboto, "Helvetica Neue", Arial, sans-serif;
    font-size: 14px;
    line-height: 1.55;
    letter-spacing: -0.004em;
    -webkit-font-smoothing: antialiased;
  }

  .envoltorio { max-width: 1060px; margin: 0 auto; padding: 40px 28px 64px; }
  /* Con el sidebar de clientes puesto, 1060px dejan el panel del cliente muy
     angosto. El login no lleva esta clase y sigue con el ancho de antes. */
  .envoltorio.con-sidebar { max-width: 1320px; }

  /* ---------- encabezado ---------- */

  header.app {
    display: flex; align-items: baseline; gap: 14px;
    margin-bottom: 28px; padding-bottom: 0;
  }
  header.app h1 {
    font-size: 19px; font-weight: 600; margin: 0;
    letter-spacing: -0.02em; color: var(--ink);
  }
  header.app .sub { color: var(--muted); font-size: 13px; }

  /* ---------- panel ---------- */

  .panel {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--radio);
    box-shadow: var(--sombra);
    padding: 22px 24px;
    margin-bottom: 20px;
  }
  .panel h2 {
    font-size: 11px; font-weight: 600; margin: 0 0 18px;
    text-transform: uppercase; letter-spacing: .09em; color: var(--muted);
  }

  .barra { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 18px; }
  .barra:last-child { margin-bottom: 0; }
  .barra .crece { flex: 1; }

  /* ---------- tablero (sidebar de clientes + panel del cliente elegido) ---------- */

  /* Clientes a la izquierda, cliente elegido a la derecha. */
  .tablero { display: flex; align-items: flex-start; gap: 20px; }
  .tablero .principal { flex: 1; min-width: 0; }

  /* El sidebar acompaña el scroll: la tabla de clientes es la navegación de la
     pantalla, no contenido que se deje atrás al elegir uno.

     Alto fijo en vez de max-height para que sea una columna de verdad y no una
     tarjeta que crece con la cantidad de clientes: así el pie con el botón de
     alta queda siempre abajo del todo. El descuento son los 40px de padding
     del envoltorio, los ~57px del encabezado y 24px de aire abajo — es lo que
     tiene arriba antes de que el sticky lo despegue, y con eso entra entero en
     pantalla incluso sin scrollear. */
  .lateral {
    flex: 0 0 290px;
    position: sticky; top: 24px;
    height: calc(100vh - 121px);
    display: flex; flex-direction: column;
    padding: 18px 16px; margin-bottom: 0;
  }

  /* Sólo la lista scrollea: el título y el botón de alta quedan siempre a la
     vista, que es lo que lo hace leer como un sidebar y no como un panel largo.
     El min-height: 0 es lo que habilita el scroll — sin él, un hijo flex no
     baja de su tamaño de contenido y el que termina desbordando es el panel. */
  .lateral .lista { flex: 1; min-height: 0; overflow-y: auto; }
  /* El encabezado queda fijo arriba de la lista que scrollea: si se fuera con
     el scroll, las columnas de una lista larga se leerían sin rótulo. Necesita
     fondo propio porque las filas le pasan por debajo. */
  .lateral .lista th { position: sticky; top: 0; background: var(--surface); z-index: 1; }

  /* El botón de alta vive abajo y centrado, separado de la lista por una línea:
     es la única acción del sidebar que no depende de qué cliente esté elegido. */
  .lateral .pie {
    flex: none; display: flex; justify-content: center;
    margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--line);
  }

  /* La tabla de clientes vive en 290px: table-layout fijo para poder recortar
     el nombre con puntos suspensivos (con el ancho automático, un nombre largo
     ensancha la columna y empuja al resto afuera del panel). */
  .lateral table { table-layout: fixed; }
  /* La columna de ID no puede ser un ancho fijo: los id se pueden escribir a
     mano al dar de alta (ver "ID de cliente opcional en el alta"), así que un
     999999 desborda los 32px de un id de dos dígitos y, con layout fijo, el
     texto se derrama encima del nombre. El ancho lo calcula el JS a partir del
     id más largo que se está mostrando (--col-id, ya en píxeles y medido sobre
     la fuente real de la celda) y acá queda el valor de arranque para el HTML
     inicial. El tope lo pone el propio JS: pasado cierto ancho se le come el
     nombre, que igual se recorta con puntos suspensivos. */
  .lateral th.col-id, .lateral td.col-id { width: var(--col-id, 32px); }
  /* Y aun así el id se recorta antes de invadir la columna del nombre: pasado
     el tope de arriba (o si el JS no llegó a correr) es preferible un número
     cortado que dos columnas encimadas. */
  .lateral td.col-id { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .lateral th, .lateral td { padding-left: 6px; padding-right: 6px; }
  .lateral th:first-child, .lateral td:first-child { padding-left: 4px; }
  .lateral th:last-child, .lateral td:last-child { padding-right: 0; }
  .lateral td { padding-top: 9px; padding-bottom: 9px; }
  .lateral .nombre { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

  /* Botón de logo del sidebar: cuadrado y con ícono, porque "Cambiar logo" no
     entra en la columna. El texto vive en title/aria-label, así que no se
     pierde ni para el mouse ni para un lector de pantalla. */
  .btn-icono { padding: 0; width: 26px; height: 26px; }
  .btn-icono svg { width: 14px; height: 14px; }
  /* Verde = el cliente ya tiene logo propio; gris = todavía usa el de prueba.
     Es una ayuda visual nomás, el dato lo dice el title: "Agregar logo" o
     "Cambiar logo". */
  .btn-icono[data-logo="0"] { color: var(--muted); }
  .btn-icono[data-logo="1"] { color: var(--accent); }
  .btn-icono[data-logo="1"]:hover { color: var(--accent-hover); }

  /* ---------- globo flotante ---------- */

  /* Ojo con el nombre: la clase .pista de más abajo ya existe y es otra cosa
     -el texto de ayuda de los modales-. Este es el globo que sigue al mouse.

     Una sola caja para toda la página, colocada por JS con position:fixed (ver
     neo.globo en clientes.js). Fixed y colgada del <body> es lo que la deja
     salir del sidebar: la lista scrollea, así que cualquier cosa dibujada
     adentro se recorta contra ese borde.
     El tono es el mismo de los paneles -fondo blanco, la línea fina de siempre-
     y no el globo oscuro de un tooltip nativo: acá lo que se muestra es un dato
     para leer, no una advertencia. La sombra es apenas más profunda que la de
     un panel, lo justo para que se despegue de la fila de abajo. */
  .globo {
    position: fixed; top: 0; left: 0; z-index: 60;
    pointer-events: none;   /* nunca se interpone entre el mouse y la fila */
    display: flex; align-items: baseline; gap: 7px;
    max-width: min(320px, calc(100vw - 24px));
    padding: 5px 9px;
    border: 1px solid var(--line); border-radius: var(--radio-chico);
    background: var(--surface);
    box-shadow: 0 1px 2px rgba(23,32,28,.05), 0 10px 24px -12px rgba(23,32,28,.28);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    /* Entra con un desplazamiento mínimo, no con un rebote: la idea es que
       aparezca, no que se anuncie. */
    opacity: 0; transform: translateY(-3px);
    transition: opacity .13s ease, transform .13s ease;
  }
  /* El display:flex de arriba es una regla de autor y le gana al [hidden] de la
     hoja del navegador, así que sin esto la caja quedaría siempre en pantalla. */
  .globo[hidden] { display: none; }
  .globo.visible { opacity: 1; transform: none; }
  @media (prefers-reduced-motion: reduce) { .globo { transition: none; } }

  /* El rótulo repite el estilo de los títulos de panel para que la pista se lea
     como parte de la interfaz y no como un globo pegado encima. */
  .globo .rotulo {
    font-size: 10px; font-weight: 600; text-transform: uppercase;
    letter-spacing: .09em; color: var(--muted);
  }
  .globo .valor {
    font-family: ui-monospace, "SF Mono", "JetBrains Mono", Menlo, monospace;
    font-size: 12px; color: var(--ink);
    overflow: hidden; text-overflow: ellipsis;
  }

  /* Abajo de 900px no hay lugar para dos columnas: el sidebar pasa arriba del
     contenido, sin sticky ni alto atado al viewport (una cajita con scroll
     propio adentro de una página que ya scrollea es peor que la lista entera). */
  @media (max-width: 900px) {
    .tablero { flex-direction: column; }
    .lateral {
      flex-basis: auto; width: 100%;
      position: static; height: auto; padding: 22px 24px;
    }
    .lateral .lista { overflow-y: visible; }
  }

  /* ---------- controles ---------- */

  button, .boton {
    font: inherit; font-size: 13px; font-weight: 500; cursor: pointer;
    border: 1px solid var(--line);
    background: var(--surface); color: var(--ink-soft);
    border-radius: var(--radio-chico);
    padding: 7px 14px; text-decoration: none;
    display: inline-flex; align-items: center; justify-content: center;
    white-space: nowrap;
    transition: background-color .15s, border-color .15s, color .15s;
  }
  button:hover, .boton:hover { background: var(--line-soft); border-color: #d8dcda; color: var(--ink); }
  button:active, .boton:active { background: #eaecea; }

  button.primario {
    background: var(--accent); border-color: var(--accent); color: var(--accent-ink);
  }
  button.primario:hover { background: var(--accent-hover); border-color: var(--accent-hover); color: var(--accent-ink); }

  button:focus-visible, .boton:focus-visible, input:focus-visible, select:focus-visible, textarea:focus-visible {
    outline: 2px solid var(--accent); outline-offset: 1px;
  }
  button:disabled { opacity: .45; cursor: not-allowed; }

  input[type=text], input[type=password], input[type=number], select, textarea {
    font: inherit; font-size: 13px;
    padding: 7px 11px;
    border: 1px solid var(--line);
    border-radius: var(--radio-chico);
    background: var(--surface); color: var(--ink);
    transition: border-color .15s, box-shadow .15s;
  }
  /* El halo de foco es para los campos que se escriben. Sin excluir a las
     casillas, el interruptor quedaba con un aro verde apenas se lo tocaba con
     el mouse, que es ruido: ahí el estado ya lo comunica el propio control. */
  input:not([type=checkbox]):hover, select:hover, textarea:hover { border-color: #d8dcda; }
  input:not([type=checkbox]):focus, select:focus, textarea:focus {
    outline: none; border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--accent-soft);
  }
  textarea {
    font-family: ui-monospace, "SF Mono", "JetBrains Mono", Menlo, monospace;
    font-size: 12.5px; line-height: 1.7; resize: vertical;
  }
  label {
    font-size: 12px; color: var(--muted); display: block;
    margin-bottom: 5px; font-weight: 500;
  }
  input[type=checkbox] { accent-color: var(--accent); width: 14px; height: 14px; }

  input[type=file] {
    font: inherit; font-size: 12.5px; color: var(--muted);
  }
  input[type=file]::file-selector-button {
    font: inherit; font-size: 13px; font-weight: 500; cursor: pointer;
    border: 1px solid var(--line);
    background: var(--surface); color: var(--ink-soft);
    border-radius: var(--radio-chico);
    padding: 6px 12px; margin-right: 10px;
    transition: background-color .15s, border-color .15s, color .15s;
  }
  input[type=file]::file-selector-button:hover {
    background: var(--line-soft); border-color: #d8dcda; color: var(--ink);
  }

  /* Preview del logo subido: nunca se incrusta el SVG crudo del disco acá
     (eso sería antes de sanear); se usa como <img src="blob:...">, que trata
     al SVG como imagen pasiva y no como documento que puede correr script. El
     logo ya guardado (saneado por el servidor) sí se incrusta inline. */
  .vista-logo {
    display: flex; align-items: center; justify-content: center;
    height: 90px; margin-top: 10px;
    border: 1px solid var(--line); border-radius: var(--radio-chico);
    background: var(--ground);
  }
  .vista-logo img, .vista-logo svg { max-height: 70px; max-width: 80%; }
  .vista-logo[hidden] { display: none; }

  .pista { font-size: 12px; color: var(--muted); line-height: 1.6; margin: 10px 0 0; }

  /* ---------- interruptor ---------- */

  /* Sigue siendo un checkbox de verdad, sólo que dibujado como toggle: así
     conserva el foco por teclado, la barra espaciadora y el click sobre el
     texto, que se perderían si fuera un div con un onclick. */
  .interruptor {
    display: inline-flex; align-items: center; gap: 9px; margin: 0;
    font-size: 12.5px; color: var(--muted);
    cursor: pointer; user-select: none;
    transition: color .15s;
  }
  .interruptor:hover { color: var(--ink-soft); }
  .interruptor[hidden] { display: none; }

  .interruptor input[type=checkbox] {
    appearance: none; -webkit-appearance: none;
    position: relative; flex: none; margin: 0;
    width: 34px; height: 20px;
    background: #dde1df; border: none; border-radius: 999px;
    cursor: pointer;
    transition: background-color .18s ease;
  }
  .interruptor input[type=checkbox]::after {
    content: ''; position: absolute; top: 2px; left: 2px;
    width: 16px; height: 16px; border-radius: 50%;
    background: #fff;
    box-shadow: 0 1px 2px rgba(23,32,28,.22);
    transition: transform .18s ease;
  }
  .interruptor:hover input[type=checkbox] { background: #d2d7d4; }
  .interruptor input[type=checkbox]:checked { background: var(--accent); }
  .interruptor:hover input[type=checkbox]:checked { background: var(--accent-hover); }
  .interruptor input[type=checkbox]:checked::after { transform: translateX(14px); }
  /* El aro aparece sólo cuando se llega por teclado, no al hacer click. */
  .interruptor input[type=checkbox]:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }

  /* ---------- tabla ---------- */

  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  th {
    text-align: left; font-size: 10.5px; font-weight: 600;
    text-transform: uppercase; letter-spacing: .07em; color: var(--muted);
    padding: 0 12px 10px; border-bottom: 1px solid var(--line);
    white-space: nowrap;
  }
  td { padding: 12px; border-bottom: 1px solid var(--line-soft); vertical-align: middle; color: var(--ink-soft); }
  tbody tr:last-child td { border-bottom: none; }
  tbody tr { transition: background-color .12s; }
  tbody tr:hover td { background: #fbfbfa; }
  #tablaClientes tr { cursor: pointer; }
  /* La fila elegida se marca con una barra de acento y un fondo apenas
     insinuado. Con un fondo más fuerte, las píldoras de estado -que usan el
     mismo verde suave- se perdían adentro de la fila. */
  tr.seleccionada td, tr.seleccionada:hover td { background: #f6f8f7; }
  tr.seleccionada td:first-child { box-shadow: inset 2px 0 0 var(--accent); }

  .mono {
    font-family: ui-monospace, "SF Mono", "JetBrains Mono", Menlo, monospace;
    font-size: 12px; letter-spacing: 0; color: var(--ink);
  }
  /* Bloc de "QRs viejos": arriba los códigos ya importados, en sólo lectura;
     abajo el área editable donde se pegan los nuevos. Son dos controles
     dentro de un mismo marco -un <textarea> no puede tener parte bloqueada y
     parte editable-, así que el borde y el halo de foco los dibuja el
     contenedor. */
  .bloc {
    border: 1px solid var(--line); border-radius: var(--radio-chico);
    background: var(--surface); overflow: hidden;
    transition: border-color .15s, box-shadow .15s;
  }
  .bloc:hover { border-color: #d8dcda; }
  .bloc:focus-within { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-soft); }
  /* Scroll propio y no el del modal: un cliente puede tener miles de importados. */
  .bloc-fijo {
    max-height: 30vh; overflow: auto; padding: 8px 11px;
    background: var(--ground); color: var(--muted); cursor: default;
    border-bottom: 1px solid var(--line);
    font-family: ui-monospace, "SF Mono", "JetBrains Mono", Menlo, monospace;
    font-size: 12.5px; line-height: 1.7;
  }
  .bloc-fijo[hidden] { display: none; }
  .bloc textarea { display: block; width: 100%; border: 0; border-radius: 0; }
  .bloc textarea:hover, .bloc textarea:focus { border-color: transparent; box-shadow: none; }
  .num { text-align: right; font-variant-numeric: tabular-nums; }
  .vacio { color: var(--muted); padding: 26px 12px; text-align: center; }
  td.acciones { white-space: nowrap; text-align: right; }
  td.acciones button + button, td.acciones .boton + button, td.acciones button + .boton { margin-left: 7px; }

  /* ---------- avisos ---------- */

  .alerta {
    background: var(--alerta-fondo); border: 1px solid var(--alerta-line);
    color: var(--alerta); border-radius: var(--radio);
    padding: 14px 18px; font-size: 13px; margin-bottom: 20px; line-height: 1.6;
  }
  .alerta strong { display: block; margin-bottom: 3px; font-weight: 600; }

  /* ---------- diálogos ---------- */

  dialog {
    border: 1px solid var(--line); border-radius: var(--radio);
    padding: 26px 28px; background: var(--surface); color: var(--ink);
    max-width: 460px; width: 92%;
    box-shadow: 0 12px 40px -12px rgba(23,32,28,.28);
  }
  /* Más ancho que el resto: apila el bloc de importados y el textarea. */
  #dlgImportar { max-width: 520px; }
  dialog::backdrop { background: rgba(23,32,28,.32); backdrop-filter: blur(1.5px); }
  dialog h3 { margin: 0 0 18px; font-size: 16px; font-weight: 600; letter-spacing: -0.015em; }
  dialog .acciones { display: flex; justify-content: flex-end; gap: 8px; margin-top: 22px; }

  /* ---------- toast ---------- */

  /* Es un <dialog> (no un div) mostrado con .show(), no .showModal(): así no
     bloquea la interacción con la página ni trae backdrop propio.
     Pero .show() —a diferencia de .showModal()— **no** promueve al top layer,
     así que colgado del <body> el aviso queda por debajo del ::backdrop con
     blur de cualquier modal abierto, justo cuando más se lo necesita (el error
     al guardar), y encima es inert: ni siquiera se puede seleccionar su texto.
     Por eso neo.aviso lo cuelga del <dialog> abierto mientras lo haya: los
     descendientes de un modal se pintan dentro del top layer y no son inert.
     Siendo position:fixed se ubica igual respecto del viewport, cuelgue de
     donde cuelgue. */
  #aviso {
    position: fixed; left: 50%; bottom: 28px; transform: translateX(-50%);
    margin: 0; border: none; padding: 10px 12px 10px 20px; max-width: min(92%, 640px); width: auto;
    background: var(--ink); color: #f4f6f5;
    border-radius: var(--radio-chico);
    font-size: 13px; line-height: 1.5;
    box-shadow: 0 8px 28px -8px rgba(23,32,28,.4);
    display: flex; align-items: center; gap: 14px;
  }
  #aviso:not([open]) { display: none; }
  #aviso .cuerpo { display: flex; flex-direction: column; gap: 5px; min-width: 0; }
  /* Un error se copia tanto con el botón como a mano, así que el texto tiene
     que poder seleccionarse. */
  #aviso .texto { user-select: text; -webkit-user-select: text; min-width: 0; }
  /* Barra al costado para distinguir de un vistazo un error de un "listo". */
  #aviso.error { box-shadow: inset 4px 0 0 #e0a06b, 0 8px 28px -8px rgba(23,32,28,.4); }
  /* El "listo" no lleva barra sino un ✓: la barra alcanza para avisar que algo
     anda mal -es lo único que se busca de un vistazo en un error-, pero cuando
     se acaba de copiar un link lo que se quiere ver es la confirmación de que
     la acción salió, y eso un color solo no lo dice. */
  #aviso .marca {
    flex: none; align-self: center;
    width: 17px; height: 17px; border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    background: #5aa483; color: #0d1a14;
    font-size: 11px; font-weight: 700; line-height: 1;
    /* La marca pertenece al texto que sigue; el gap de 14px del toast separa
       bloques distintos y acá quedaría demasiado suelta. */
    margin-right: -5px;
  }
  /* La marca ya aporta el peso visual que ese padding daba del lado izquierdo. */
  #aviso.ok { padding-left: 13px; }
  /* El dato que el aviso entrega: monoespaciado y en su propia línea, porque
     hay que poder verificarlo carácter por carácter. break-all y no la palabra
     entera: una URL larga tiene que cortar donde sea antes que desbordar. */
  #aviso .dato {
    align-self: flex-start; max-width: 100%;
    padding: 3px 7px; border-radius: 5px;
    background: rgba(244,246,245,.10); color: #f4f6f5;
    font-family: ui-monospace, "SF Mono", "JetBrains Mono", Menlo, monospace;
    font-size: 12.5px; line-height: 1.45;
    word-break: break-all;
    user-select: text; -webkit-user-select: text;
  }
  #aviso .marca[hidden], #aviso .dato[hidden] { display: none; }
  #aviso .acciones { display: flex; gap: 6px; margin: 0; flex: none; }
  #aviso button {
    font-size: 12px; padding: 3px 9px;
    background: transparent; border-color: rgba(244,246,245,.26); color: #cdd4d1;
  }
  #aviso button:hover {
    background: rgba(244,246,245,.12); border-color: rgba(244,246,245,.44); color: #fff;
  }
  #aviso button:focus-visible { outline-color: #f4f6f5; }
  #aviso button[hidden] { display: none; }

  /* ---------- pie ---------- */

  footer.app {
    margin-top: 34px; padding-top: 18px; border-top: 1px solid var(--line);
    color: var(--muted); font-size: 12px;
    display: flex; gap: 20px; flex-wrap: wrap; align-items: center;
  }
  footer.app a { color: var(--muted); text-decoration: none; border-bottom: 1px solid var(--line); }
  footer.app a:hover { color: var(--accent); border-bottom-color: var(--accent); }
</style>
</head>
<body>
<div class="envoltorio<?= !empty($sidebar) ? ' con-sidebar' : '' ?>">
  <header class="app">
    <h1>neo-qr</h1>
    <span class="sub">Generación de códigos QR por cliente</span>
  </header>

  <?= $contenido ?>

  <?php
    // El sello .build lo escribe el Dockerfile en UTC. Se emite tal cual como
    // respaldo -sirve igual si el JS no corre- y abajo un script lo pasa a la
    // zona horaria de quien mira.
    $build = trim(@file_get_contents(BASE_PATH . '/.build')) ?: '';
    $buildFecha = $build !== ''
        ? DateTimeImmutable::createFromFormat('d/m/Y H:i T', $build, new DateTimeZone('UTC'))
        : false;

    // La versión sale de config/app.php, igual que el resto de la configuración
    // (ver el comentario de 'version' ahí: se sube a mano, no sale de git).
    $configApp = require BASE_PATH . '/config/app.php';
  ?>
  <footer class="app">
    <span>v<?= htmlspecialchars($configApp['version']) ?></span>
    <span>PHP <?= htmlspecialchars(PHP_VERSION) ?></span>
    <span>Desplegado <span<?= $buildFecha ? ' data-ts="' . $buildFecha->getTimestamp() . '"' : '' ?>><?= htmlspecialchars($build ?: 'desconocido') ?></span></span>
    <span><a href="/health">/health</a></span>
    <?php if (!empty($autenticado)): ?>
      <span style="margin-left:auto"><a href="/logout">Salir</a></span>
    <?php endif; ?>
  </footer>
</div>

<?php
  // aria-live para que se anuncie sin robarle el foco al formulario (ver neo.aviso).
  //
  // El "dato" es la pieza que el aviso entrega para llevarse -hoy, el link de
  // la grabadora-, separada del texto y no embebida en él: es lo único que hay
  // que leer con atención (un carácter cambiado del token lleva a otro lote) y
  // es lo que copia el botón.
?>
<dialog id="aviso" aria-live="polite">
  <span class="marca" id="avisoMarca" aria-hidden="true" hidden></span>
  <span class="cuerpo">
    <span class="texto" id="avisoTexto"></span>
    <code class="dato" id="avisoDato" hidden></code>
  </span>
  <span class="acciones">
    <button type="button" id="avisoCopiar" hidden>Copiar</button>
    <button type="button" id="avisoCerrar" aria-label="Cerrar aviso">Cerrar</button>
  </span>
</dialog>

<?php
  // Pista flotante (hoy: el id completo de un cliente cuando la columna lo
  // recorta). Vive suelta acá abajo, una sola para toda la página, porque un
  // ::after sobre la propia celda quedaría recortado dos veces: por el
  // overflow:hidden que hace la elipsis y por el scroll de la lista. Es
  // aria-hidden y sin foco: no aporta nada a un lector de pantalla, que ya
  // lee el contenido entero de la celda.
?>
<div id="globo" class="globo" role="presentation" aria-hidden="true" hidden></div>

<script>
  /**
   * Fecha en la zona horaria de quien mira, a partir de segundos Unix.
   *
   * El servidor corre en UTC, así que sin esto todas las horas de la interfaz
   * se verían corridas para quien las lee. Se define acá, antes que el resto
   * del JS, porque la usan tanto el pie como las tablas.
   *
   * Se arma con dos llamadas en vez de toLocaleString porque este último mete
   * una coma entre fecha y hora, y en es-AR además devuelve 12 horas con
   * "p. m.". El resto del sistema usa 24 horas.
   */
  window.fechaLocal = function (ts) {
    if (!ts) return '';

    const d = new Date(ts * 1000);
    return d.toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric' })
      + ' ' + d.toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit', hour12: false });
  };

  // El pie trae el sello de deploy en UTC como respaldo; acá se reemplaza.
  document.querySelectorAll('[data-ts]').forEach(function (el) {
    el.textContent = window.fechaLocal(el.dataset.ts);
  });
</script>

<?php
/*
 * Los <script> van con ?v=<mtime del archivo>. No es cosmético: el HTML lo
 * genera PHP y no se cachea nunca, pero /js/*.js son archivos estáticos que
 * Cloudflare -que está delante de la app en producción- guarda cuatro horas
 * por su cuenta. Sin esto, después de un deploy el navegador combina el HTML
 * nuevo con el JS viejo del borde, y eso rompe de verdad: pasó que el JS
 * anterior tocaba un botón que la vista nueva ya no tenía, tiraba TypeError
 * antes de pedir los datos y la tabla de lotes quedaba en "Cargando…" para
 * siempre.
 *
 * Es el mtime y no time() -que es lo que hace el proyecto previo y que acá se
 * documenta como algo a NO copiar, porque anula el caché entero- ni una
 * versión a mano, que es una más para acordarse de subir. El mtime cambia
 * exactamente cuando cambia el archivo: se cachea fuerte y se invalida solo.
 */
foreach ($scripts as $script):
    $archivo = BASE_PATH . '/public' . $script;
    $src = is_file($archivo) ? $script . '?v=' . filemtime($archivo) : $script;
?>
<script src="<?= htmlspecialchars($src) ?>"></script>
<?php endforeach; ?>
</body>
</html>
