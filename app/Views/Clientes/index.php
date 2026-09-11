<?php
/**
 * @var bool   $autoNuevo  abrir el diálogo de alta al cargar (ruta /clientes/nuevo)
 * @var string $qrBaseUrl  vacío si falta configurar QR_BASE_URL
 */
?>
<div class="tablero">
  <aside class="panel lateral" id="sidebarClientes">
    <h2>Clientes</h2>

    <label class="interruptor" hidden>
      <input type="checkbox" id="verInactivos"> Mostrar dados de baja
    </label>

    <div class="lista">
      <table>
        <thead>
          <tr>
            <th class="col-id">ID</th>
            <th>Nombre</th>
            <th class="num" style="width:44px">Cód.</th>
            <th style="width:32px"></th>
          </tr>
        </thead>
        <tbody id="tablaClientes">
          <tr><td colspan="4" class="vacio">Cargando…</td></tr>
        </tbody>
      </table>
    </div>

    <div class="pie">
      <button class="primario" id="btnNuevo">Nuevo cliente</button>
    </div>
  </aside>

  <div class="principal">
    <?php if ($qrBaseUrl === ''): ?>
    <div class="alerta">
      <strong>Falta configurar <span class="mono">QR_BASE_URL</span></strong>
      Se pueden generar códigos, pero todavía no se pueden emitir las imágenes de los QR:
      la URL queda embebida en el código impreso y después no se corrige sin reimprimir.
    </div>
    <?php endif; ?>

    <div class="panel" id="panelVacio">
      <p class="vacio" style="margin:0">Elegí un cliente de la lista para ver sus lotes.</p>
    </div>

    <div class="panel" id="panelSeriales" hidden>
      <div class="barra">
        <h2 style="margin:0">Lotes de <span id="nombreCliente"></span></h2>
        <span class="crece"></span>
      </div>

      <div class="barra">
        <div>
          <label for="cantidad">Cantidad a generar</label>
          <input type="number" id="cantidad" value="10" min="1" max="5000" style="width:104px">
        </div>
        <div>
          <label for="tamano">Medida (mm)</label>
          <input type="number" id="tamano" min="5" max="200" step="0.5"
                 placeholder="p. ej. 25" style="width:104px">
        </div>
        <div style="align-self:flex-end">
          <button class="primario" id="btnGenerar">Generar QR</button>
        </div>
        <div style="align-self:flex-end">
          <button id="btnImportar" disabled>QRs viejos</button>
        </div>
      </div>

      <table>
        <thead>
          <tr>
            <th style="width:170px">Generado</th>
            <th class="num" style="width:96px">Códigos</th>
            <th class="num" style="width:96px">Medida</th>
            <th></th>
          </tr>
        </thead>
        <tbody id="tablaSeriales">
          <tr><td colspan="4" class="vacio">Sin lotes todavía.</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<dialog id="dlgNuevo">
  <h3>Nuevo cliente</h3>
  <label for="nombreNuevo">Nombre</label>
  <input type="text" id="nombreNuevo" style="width:100%" maxlength="120" placeholder="Pepito Caramuto">
  <label for="idNuevo" style="margin-top:12px">ID (opcional)</label>
  <input type="text" id="idNuevo" style="width:100%" inputmode="numeric" maxlength="10"
         placeholder="Se asigna solo">
  <label for="logoNuevo" style="margin-top:12px">Logo (opcional)</label>
  <input type="file" id="logoNuevo" accept=".svg,image/svg+xml" style="width:100%">
  <div class="vista-logo" id="vistaLogoNuevo" hidden></div>
  <div class="acciones">
    <button id="btnCancelarNuevo">Cancelar</button>
    <button class="primario" id="btnGuardarNuevo">Crear</button>
  </div>
</dialog>

<dialog id="dlgLogo">
  <h3>Logo de <span id="logoNombreCliente"></span></h3>
  <div class="vista-logo" id="vistaLogoActual" hidden></div>
  <p class="pista" id="avisoLogoPorDefecto" hidden>
    Todavía sin logo propio: los QR usan el logo de prueba.
  </p>
  <label for="logoArchivo" style="margin-top:12px">Archivo SVG</label>
  <input type="file" id="logoArchivo" accept=".svg,image/svg+xml" style="width:100%">
  <div class="vista-logo" id="vistaLogoNueva" hidden></div>
  <p class="pista">
    Tiene que ser un SVG vectorial de un solo color: texto convertido a curvas,
    sin degradados ni imágenes incrustadas. Usá el isotipo y no el logotipo con
    texto — entra en un cuadrado de pocos milímetros dentro del QR. Los trazos
    se graban como líneas.
  </p>
  <div class="acciones">
    <button id="btnCancelarLogo">Cancelar</button>
    <button class="primario" id="btnGuardarLogo">Guardar</button>
  </div>
</dialog>

<dialog id="dlgImportar">
  <h3>QRs viejos de <span id="nombreClienteImportar"></span></h3>
  <label for="serialesPegados">Un código por línea</label>
  <div class="bloc">
    <div class="bloc-fijo" id="listaExistentes" hidden></div>
    <textarea id="serialesPegados" rows="6"
              placeholder="QRABCDEF&#10;QRKXMPZA&#10;QRDNVHRC"></textarea>
  </div>
  <p class="pista" id="metaExistentes"></p>
  <p class="pista">
    Arriba están los códigos que ya se importaron antes -no se pueden editar
    ni borrar-; abajo se pegan los nuevos, uno por línea. Sirve para dar de
    alta códigos que ya se habían generado antes, de modo que queden
    reservados y el generador nunca los vuelva a sortear. Los repetidos se
    saltean solos. Formato esperado: <span class="mono">QR</span> + 6 letras.
  </p>
  <div class="acciones">
    <button id="btnCancelarImportar">Cerrar</button>
    <button class="primario" id="btnConfirmarImportar">Importar</button>
  </div>
</dialog>

<dialog id="dlgBaja">
  <h3>Dar de baja</h3>
  <p style="margin:0">
    Se va a dar de baja a <strong id="bajaNombre"></strong>.
  </p>
  <p class="pista">
    Sus códigos <strong>no</strong> se borran y siguen reservados: esos QR pueden
    estar impresos y pegados, y reciclarlos haría que una etiqueta física
    terminara apuntando al destino equivocado.
  </p>
  <div class="acciones">
    <button id="btnCancelarBaja">Cancelar</button>
    <button class="primario" id="btnConfirmarBaja">Dar de baja</button>
  </div>
</dialog>

<dialog id="dlgAnular">
  <h3>Anular link</h3>
  <p style="margin:0">Se va a anular el link para la grabadora de este lote.</p>
  <p class="pista">
    Se puede emitir un link nuevo cuando haga falta, pero el que ya tiene la
    grabadora deja de andar para siempre: no hay forma de reactivarlo.
  </p>
  <div class="acciones">
    <button id="btnCancelarAnular">Cancelar</button>
    <button class="primario" id="btnConfirmarAnular">Anular</button>
  </div>
</dialog>

<script>
  // Único dato que el servidor le pasa al JS.
  window.NEO_QR = {
    autoNuevo: <?= $autoNuevo ? 'true' : 'false' ?>,
    qrBaseUrl: <?= json_encode($qrBaseUrl, JSON_UNESCAPED_SLASHES) ?>
  };
</script>
