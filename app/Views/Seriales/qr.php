<?php
/**
 * Hoja de QR imprimible. No usa el layout de la app: es una página
 * pensada para el papel, sin navegación ni colores de pantalla.
 *
 * Sirve a dos modos, ya resueltos por el controller: el panel (qr(), detrás
 * de sesión) y el link público de la grabadora (grabado(), sin sesión, ver
 * "Link para la grabadora" en CLAUDE.md). $meta y $rutas ya vienen armados
 * -esta vista no arma ninguna URL ni decide qué mostrar en el encabezado-,
 * así que no hay ningún otro if($publico) más que el <meta name="robots">.
 *
 * @var array  $cliente
 * @var array  $seriales
 * @var bool   $recortado true si se cortó la lista por el tope
 * @var int    $maximo
 * @var string $baseQr    '' si falta configurar QR_BASE_URL
 * @var string $meta      línea bajo el título, ya armada: distinta en panel y público
 * @var array{hoja:string, zip:string, unidad:string} $rutas  'unidad' termina en '?' o '&': falta concatenarle 'serial=...'
 * @var bool   $publico   true en el link de la grabadora (sin sesión)
 * @var \App\Support\QrLogo $logo  logo del cliente (o el placeholder)
 */
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ($publico): ?>
<meta name="robots" content="noindex, nofollow">
<?php endif; ?>
<title>QR · <?= htmlspecialchars($cliente['nombre']) ?></title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'><rect width='16' height='16' fill='%232d6a4f'/><path fill='%23fff' d='M3 3h3v3H3zM10 3h3v3h-3zM3 10h3v3H3zM8 8h2v2H8zM11 11h2v2h-2z'/></svg>">
<style>
  /* Página pensada para el papel: tema claro, sin adornos que no impriman bien. */
  * { box-sizing: border-box; }
  body {
    margin: 0; padding: 18mm 14mm; background: #fff; color: #17201c;
    font-family: ui-sans-serif, -apple-system, "Segoe UI Variable Text", "Segoe UI",
                 Inter, Roboto, "Helvetica Neue", Arial, sans-serif;
    font-size: 14px; -webkit-font-smoothing: antialiased;
  }
  header { margin-bottom: 18px; border-bottom: 1px solid #e6e8e6; padding-bottom: 14px; }
  h1 { font-size: 17px; font-weight: 600; margin: 0 0 4px; letter-spacing: -0.02em; }
  .meta { font-size: 12.5px; color: #7c8783; }
  .aviso {
    border: 1px solid #ecd9c4; background: #fdf6ef; color: #8a4b1a;
    padding: 13px 17px; border-radius: 10px; font-size: 13px;
    margin-bottom: 18px; line-height: 1.6;
  }
  .grilla {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(34mm, 1fr));
    gap: 6mm;
  }
  /* break-inside vive en .celda -no en .qr-: es la unidad completa (código +
     link de descarga) la que no se tiene que partir entre dos páginas. */
  .celda { break-inside: avoid; page-break-inside: avoid; }
  .qr {
    border: 1px solid #e6e8e6; border-radius: 6px; padding: 3mm;
    text-align: center;
    cursor: zoom-in; transition: border-color .15s, box-shadow .15s;
  }
  .qr:hover, .qr:focus-visible {
    border-color: #c8ccc9;
    box-shadow: 0 1px 2px rgba(23,32,28,.04), 0 4px 12px -6px rgba(23,32,28,.10);
  }
  .qr:focus-visible { outline: 2px solid #145239; outline-offset: 1px; }
  .qr svg { width: 100%; height: auto; display: block; }
  .qr .serial {
    font-family: ui-monospace, "SF Mono", "JetBrains Mono", Menlo, monospace;
    font-size: 8pt; margin-top: 2mm; word-break: break-all;
    line-height: 1.25; color: #414b46; letter-spacing: .02em;
  }
  /* Descarga de un código suelto, hermana de .qr y no adentro: .qr es
     role="button" y el zoom escucha click ahí -anidar un <a> rompería esa
     semántica y el link dispararía el zoom al tocarlo. */
  .bajar {
    display: block; text-align: center; font-size: 9pt; margin-top: 1.5mm;
    color: #414b46; text-decoration: none;
  }
  .bajar:hover, .bajar:focus-visible { color: #145239; text-decoration: underline; }
  /* Las dos formas de exportar, una al lado de la otra: no hay una opción
     "normal" y otra escondida en un menú, son dos maneras de grabar el mismo
     lote y cuál sirve depende de si cada código va en su propia placa. */
  .descarga { margin-bottom: 18px; display: flex; flex-wrap: wrap; gap: 10px; }
  .descarga a {
    display: block; text-decoration: none; color: inherit;
    font: inherit; padding: 9px 15px; border-radius: 8px;
    border: 1px solid #d7dbd8; background: #fff;
  }
  .descarga a:hover { border-color: #145239; }
  .descarga a:focus-visible { outline: 2px solid #145239; outline-offset: 1px; }
  .descarga .que { font-size: 13px; font-weight: 500; }
  .descarga .como { font-size: 11.5px; color: #7c8783; margin-top: 2px; }

  /* ---------- modal de QR ampliado ---------- */
  /* No hay layout acá, así que se replican a mano los tokens de color/sombra
     del panel (Layouts/app.php) en vez de custom properties compartidas. */
  #dlgQr {
    border: none; border-radius: 14px; padding: 0; background: #fff;
    width: min(92vw, 420px); max-width: none;
    box-shadow: 0 12px 40px -12px rgba(23,32,28,.28);
  }
  #dlgQr::backdrop { background: rgba(23,32,28,.32); backdrop-filter: blur(1.5px); }
  #dlgQr .contenido { padding: 22px 22px 20px; position: relative; }
  #dlgQr .cerrar {
    position: absolute; top: 10px; right: 10px;
    border: none; background: none; padding: 4px 8px;
    font-size: 20px; line-height: 1; color: #7c8783; cursor: pointer;
  }
  #dlgQr .cerrar:hover { color: #17201c; }
  #dlgQr .lienzo svg { width: 100%; height: auto; display: block; }
  #dlgQr .serial {
    font-family: ui-monospace, "SF Mono", "JetBrains Mono", Menlo, monospace;
    font-size: 15px; letter-spacing: .04em; text-align: center;
    color: #17201c; margin-top: 14px;
  }
  #dlgQr .detalle {
    font-size: 11.5px; color: #7c8783; text-align: center;
    margin-top: 4px; word-break: break-all; line-height: 1.5;
  }
  @media (prefers-reduced-motion: no-preference) {
    #dlgQr[open] { animation: dlgQrEntrada .14s ease-out; }
    #dlgQr[open]::backdrop { animation: dlgQrFondo .14s ease-out; }
  }
  @keyframes dlgQrEntrada {
    from { opacity: 0; transform: scale(.97); }
    to   { opacity: 1; transform: scale(1); }
  }
  @keyframes dlgQrFondo {
    from { opacity: 0; }
    to   { opacity: 1; }
  }

  @media print {
    body { padding: 0; }
    .descarga { display: none; }
    .qr { border-color: #c8ccc9; cursor: auto; box-shadow: none; }
    .bajar { display: none; }
    #dlgQr { display: none !important; }
  }
</style>
</head>
<body>

<header>
  <h1><?= htmlspecialchars($cliente['nombre']) ?></h1>
  <div class="meta"><?= htmlspecialchars($meta) ?></div>
</header>

<?php if ($baseQr === ''): ?>
  <div class="aviso">
    <strong>No se puede imprimir todavía:</strong> falta configurar
    <code>QR_BASE_URL</code>. Sin esa variable el QR no apunta a ningún lado, y
    una vez impreso y pegado no hay forma de corregirlo.
  </div>
<?php else: ?>

  <?php if ($recortado): ?>
    <div class="aviso">
      Se muestran los primeros <?= (int) $maximo ?> códigos. Para imprimir el
      resto, filtrá por lote.
    </div>
  <?php endif; ?>

  <?php if (empty($seriales)): ?>
    <p class="meta">Este cliente todavía no tiene seriales generados.</p>
  <?php else: ?>
    <div class="descarga">
      <a href="<?= htmlspecialchars($rutas['hoja']) ?>">
        <div class="que">Descargar una hoja con todos</div>
        <div class="como">Un solo SVG, A4, para grabar todo de una pasada</div>
      </a>
      <a href="<?= htmlspecialchars($rutas['zip']) ?>">
        <div class="que">Descargar los QR separados</div>
        <div class="como">Un SVG por código, en un .zip</div>
      </a>
    </div>

    <div class="grilla" id="grillaQr">
      <?php foreach ($seriales as $fila): ?>
        <?php $urlUnidad = htmlspecialchars($rutas['unidad'] . 'serial=' . urlencode($fila['serial'])); ?>
        <div class="celda">
          <div class="qr" role="button" tabindex="0"
               aria-label="Ampliar <?= htmlspecialchars($fila['serial']) ?>">
            <?= \App\Support\QrSvg::render(\App\Support\FormatoSerial::url($baseQr, $fila['serial']), 128, $logo) ?>
            <div class="serial"><?= htmlspecialchars($fila['serial']) ?></div>
          </div>
          <a class="bajar" href="<?= $urlUnidad ?>" download>Descargar</a>
        </div>
      <?php endforeach; ?>
    </div>

    <dialog id="dlgQr">
      <div class="contenido">
        <button type="button" class="cerrar" id="btnCerrarQr" aria-label="Cerrar">&times;</button>
        <div class="lienzo" id="lienzoQr"></div>
        <div class="serial" id="serialQr"></div>
        <div class="detalle" id="detalleQr"></div>
        <a class="bajar" id="bajarQr" download>Descargar este código</a>
      </div>
    </dialog>

    <script>
      (function () {
        'use strict';
        var grilla = document.getElementById('grillaQr');
        var dlg = document.getElementById('dlgQr');
        var lienzo = document.getElementById('lienzoQr');
        var serialEl = document.getElementById('serialQr');
        var detalleEl = document.getElementById('detalleQr');
        var bajarEl = document.getElementById('bajarQr');

        function abrir(tarjeta) {
          var svg = tarjeta.querySelector('svg');
          if (!svg) return;

          var clon = svg.cloneNode(true);
          clon.removeAttribute('width');
          clon.removeAttribute('height');
          lienzo.innerHTML = '';
          lienzo.appendChild(clon);

          var serial = tarjeta.querySelector('.serial').textContent;
          var url = svg.getAttribute('aria-label') || '';

          serialEl.textContent = serial;
          detalleEl.textContent = url;

          // El modal no arma ninguna URL propia: copia la del link hermano
          // de la tarjeta, que el controller ya armó para este modo (panel o
          // público).
          var linkCelda = tarjeta.closest('.celda').querySelector('.bajar');
          bajarEl.href = linkCelda ? linkCelda.href : '';

          dlg.showModal();
        }

        grilla.addEventListener('click', function (ev) {
          var tarjeta = ev.target.closest('.qr');
          if (!tarjeta) return;
          abrir(tarjeta);
        });

        grilla.addEventListener('keydown', function (ev) {
          if (ev.key !== 'Enter' && ev.key !== ' ') return;
          var tarjeta = ev.target.closest('.qr');
          if (!tarjeta) return;
          ev.preventDefault();
          abrir(tarjeta);
        });

        document.getElementById('btnCerrarQr').addEventListener('click', function () {
          dlg.close();
        });

        dlg.addEventListener('click', function (ev) {
          if (ev.target === dlg) dlg.close();
        });
      })();
    </script>
  <?php endif; ?>

<?php endif; ?>

</body>
</html>
