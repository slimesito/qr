/**
 * Panel de un cliente: generación de códigos e historial de lotes.
 *
 * La tabla lista las tandas generadas, no los códigos uno por uno: un cliente
 * puede tener miles de seriales y enumerarlos en pantalla no le sirve a nadie.
 * Lo que se hace con un lote es imprimirlo, así que cada fila lleva directo a su
 * hoja de QR.
 *
 * Depende de los helpers que expone clientes.js (neo.api, neo.post, neo.esc,
 * neo.aviso, neo.nota, neo.error), por eso se carga después.
 */
(function () {
  'use strict';

  const neo = window.neo;

  const panel = document.getElementById('panelSeriales');
  const panelVacio = document.getElementById('panelVacio');
  const tabla = document.getElementById('tablaSeriales');

  let cliente = null;

  neo.abrirSeriales = async function (elegido) {
    cliente = elegido;
    document.getElementById('nombreCliente').textContent = elegido.nombre;
    panelVacio.hidden = true;
    panel.hidden = false;

    tabla.innerHTML = '<tr><td colspan="4" class="vacio">Cargando…</td></tr>';
    btnImportar.disabled = true;
    await recargar();
  };

  async function recargar() {
    try {
      const res = await neo.api('/api/seriales?action=list&cliente_id=' + cliente.id);
      pintar(res.data.lotes);
      btnImportar.disabled = false;
    } catch (e) {
      tabla.innerHTML = '<tr><td colspan="4" class="vacio">' + neo.esc(e.message) + '</td></tr>';
    }
  }

  const btnImportar = document.getElementById('btnImportar');

  // La fecha se muestra en la zona horaria de quien mira, no en la del
  // servidor. Sale de creado_ts (segundos Unix), que no tiene ambigüedad de
  // formato ni de zona. El formateador lo define el layout, porque también lo
  // usa el pie de página.
  //
  // Ojo: el identificador del lote (AAAAMMDD-HHMM) se arma con la hora del
  // servidor, que es UTC. O sea que esta columna y la del lote pueden no
  // coincidir — el lote es un código interno, esta es la hora real de quien
  // lee. Es esperable, no un desfasaje a corregir.
  const fechaLocal = window.fechaLocal;

  function pintar(lotes) {
    if (!lotes.length) {
      tabla.innerHTML = '<tr><td colspan="4" class="vacio">'
        + 'Este cliente todavía no tiene lotes. Generá el primero con el botón de arriba.'
        + '</td></tr>';
      return;
    }

    tabla.innerHTML = lotes.map(function (l) {
      const href = '/qr?cliente_id=' + cliente.id + '&lote=' + encodeURIComponent(l.lote);
      // tamano_mm es null sólo en lotes generados antes de que existiera la
      // columna; el zip de esos igual sale, con el fallback de 25 mm.
      const medida = l.tamano_mm === null ? '—' : l.tamano_mm + ' mm';

      // Cada lote generado nace con su link (Serial::generar() lo crea de
      // una), así que "Crear link" sólo debería verse en tandas viejas,
      // anteriores a esta funcionalidad, o después de anular uno.
      const acciones = l.enlace_token
        ? '<button type="button" data-accion="copiar-link" data-url="' + neo.esc(l.enlace_url) + '">Copiar link</button>' +
          '<button type="button" data-accion="anular-link">Anular</button>'
        : '<button type="button" data-accion="crear-link">Crear link</button>';

      return '<tr data-lote="' + neo.esc(l.lote) + '">' +
        '<td>' + neo.esc(fechaLocal(l.creado_ts)) + '</td>' +
        '<td class="num">' + l.cantidad + '</td>' +
        '<td class="num">' + neo.esc(medida) + '</td>' +
        '<td class="acciones">' +
          '<a class="boton" href="' + href + '" target="_blank" rel="noopener">Ver QRs</a>' +
          acciones +
        '</td>' +
        '</tr>';
    }).join('');
  }

  // ==================== LINK PARA LA GRABADORA ====================
  // Ver "Link para la grabadora" en CLAUDE.md: un token opaco por lote, que
  // se le pasa a un tercero sin cuenta para que vea y baje los QR de esa
  // tanda sin sesión.

  /**
   * @param {string}  url El link ya armado por el servidor (FormatoEnlace::url).
   *   No se compone acá a propósito: tiene que decir el dominio de producción
   *   siempre, y no aquel por el que se entró al panel, así que sale de
   *   QR_BASE_URL y el navegador no tiene por qué conocer esa variable.
   * @param {boolean} [recienCreado] true si el link acaba de nacer, para que el
   *   aviso diga las dos cosas que pasaron y no sólo la última.
   */
  async function copiarLink(url, recienCreado) {
    try {
      await navigator.clipboard.writeText(url);
      neo.aviso(recienCreado ? 'Link creado y copiado' : 'Link copiado', url);
    } catch (e) {
      // navigator.clipboard no existe fuera de contexto seguro (HTTP plano
      // en desarrollo): sin este fallback el botón no haría nada visible.
      // Va como nota y no como aviso porque el link no quedó copiado: el ✓
      // diría que sí. El botón "Copiar" del aviso es el segundo intento -y
      // cuando también falla, deja el link seleccionado para el Ctrl+C.
      neo.nota(recienCreado
        ? 'Link creado. No se pudo copiar solo, copialo de acá:'
        : 'No se pudo copiar solo. Copialo de acá:', url);
    }
  }

  async function crearLink(lote, boton) {
    boton.disabled = true;
    try {
      const res = await neo.post('/api/seriales?action=crear_enlace', {
        cliente_id: cliente.id,
        lote: lote
      });
      await recargar();
      await copiarLink(res.data.url, true);
    } catch (e) {
      neo.error(e.message);
    } finally {
      boton.disabled = false;
    }
  }

  const dlgAnular = document.getElementById('dlgAnular');
  let loteAAnular = null;

  function pedirAnularLink(lote) {
    loteAAnular = lote;
    dlgAnular.showModal();
  }

  document.getElementById('btnCancelarAnular').addEventListener('click', () => dlgAnular.close());

  document.getElementById('btnConfirmarAnular').addEventListener('click', async function (ev) {
    if (!loteAAnular) return;

    ev.target.disabled = true;
    try {
      await neo.post('/api/seriales?action=anular_enlace', {
        cliente_id: cliente.id,
        lote: loteAAnular
      });
      dlgAnular.close();
      neo.aviso('Link anulado. El que ya tenía la grabadora deja de andar.');
      await recargar();
    } catch (e) {
      neo.error(e.message);
    } finally {
      ev.target.disabled = false;
      loteAAnular = null;
    }
  });

  // Un solo listener delegado sobre la tabla: las filas se repintan enteras
  // en cada recargar(), así que un listener por botón se perdería solo.
  tabla.addEventListener('click', function (ev) {
    const boton = ev.target.closest('button[data-accion]');
    if (!boton) return;

    const fila = boton.closest('tr[data-lote]');
    if (!fila) return;
    const lote = fila.dataset.lote;

    if (boton.dataset.accion === 'copiar-link') {
      copiarLink(boton.dataset.url);
    } else if (boton.dataset.accion === 'crear-link') {
      crearLink(lote, boton);
    } else if (boton.dataset.accion === 'anular-link') {
      pedirAnularLink(lote);
    }
  });

  document.getElementById('btnGenerar').addEventListener('click', async function (ev) {
    if (!cliente) return;

    const cantidad = Number(document.getElementById('cantidad').value);
    if (!Number.isInteger(cantidad) || cantidad < 1 || cantidad > 5000) {
      neo.error('La cantidad tiene que estar entre 1 y 5000');
      return;
    }

    const tamanoInput = document.getElementById('tamano');
    const tamano = Number(tamanoInput.value);
    if (tamanoInput.value.trim() === '' || !Number.isFinite(tamano) || tamano < 5 || tamano > 200) {
      neo.error('Elegí la medida del QR: tiene que estar entre 5 y 200 mm');
      tamanoInput.focus();
      return;
    }

    ev.target.disabled = true;
    try {
      const res = await neo.post('/api/seriales?action=generar', {
        cliente_id: cliente.id,
        cantidad: cantidad,
        tamano_mm: tamano
      });

      // Los salteados son códigos sorteados que ya existían: el generador los
      // descarta y sortea otro, así que igual se entregan los N pedidos... salvo
      // que se agote la cota de intentos (ver Serial::generar), caso en el que
      // conviene avisar en vez de dejar que se note sólo por la diferencia.
      let mensaje = 'Se generaron ' + res.data.creados + ' códigos de ' + res.data.tamano_mm
        + ' mm (lote ' + res.data.lote + ')';
      if (res.data.creados < res.data.pedidos) {
        mensaje += '. ¡Atención! Se pidieron ' + res.data.pedidos + ' y sólo se pudieron generar '
          + res.data.creados + ': el espacio de códigos parece estar casi agotado.';
      } else if (res.data.salteados > 0) {
        mensaje += '. Se saltearon ' + res.data.salteados + ' que ya existían.';
      }
      neo.aviso(mensaje);

      // El lote nuevo aparece primero: la lista viene ordenada de más nuevo a
      // más viejo, así que queda arriba sin necesidad de resaltarlo.
      await recargar();
      await neo.cargarClientes();
    } catch (e) {
      neo.error(e.message);
    } finally {
      ev.target.disabled = false;
    }
  });

  // ==================== QRS VIEJOS (bloc de importados + importar) ====================

  const dlgImportar = document.getElementById('dlgImportar');
  const pegados = document.getElementById('serialesPegados');
  const metaExistentes = document.getElementById('metaExistentes');
  const listaExistentes = document.getElementById('listaExistentes');

  // Siempre abre el mismo modal: se puede importar más de una vez (por
  // ejemplo, cuando llega una tanda nueva de códigos viejos) y de paso
  // muestra los que ya están cargados, como un bloc de notas de sólo lectura.
  btnImportar.addEventListener('click', function () {
    if (!cliente) return;

    document.getElementById('nombreClienteImportar').textContent = cliente.nombre;
    pegados.value = '';
    metaExistentes.textContent = 'Cargando…';
    listaExistentes.hidden = true;
    listaExistentes.innerHTML = '';
    dlgImportar.showModal();
    pegados.focus();
    cargarExistentes();
  });

  document.getElementById('btnCancelarImportar').addEventListener('click', () => dlgImportar.close());

  document.getElementById('btnConfirmarImportar').addEventListener('click', async function (ev) {
    if (!cliente) return;

    if (pegados.value.trim() === '') {
      neo.error('Pegá al menos un código');
      pegados.focus();
      return;
    }

    ev.target.disabled = true;
    try {
      const res = await neo.post('/api/seriales?action=importar', {
        cliente_id: cliente.id,
        seriales: pegados.value
      });

      // Se informan los tres resultados porque cada uno pide algo distinto:
      // los duplicados ya estaban cargados y no hay nada que hacer; los
      // inválidos quedaron afuera y hay que revisarlos a mano.
      const d = res.data;
      let mensaje = 'Se importaron ' + d.importados + ' códigos';
      if (d.duplicados > 0) mensaje += ', ' + d.duplicados + ' ya existían';
      if (d.invalidos > 0) {
        mensaje += ' y ' + d.invalidos + ' se rechazaron por formato';
        if (d.ejemplos && d.ejemplos.length) mensaje += ' (' + d.ejemplos.join(', ') + ')';
      }
      neo.aviso(mensaje + '.');

      // El modal queda abierto: ahora también es la vista del inventario, así
      // que cerrarlo escondería justo el resultado. Se vacía el área
      // editable y se refresca el bloc de arriba con lo recién importado.
      pegados.value = '';
      await cargarExistentes();
      await recargar();
      await neo.cargarClientes();
    } catch (e) {
      neo.error(e.message);
    } finally {
      ev.target.disabled = false;
    }
  });

  async function cargarExistentes() {
    try {
      const res = await neo.api('/api/seriales?action=importados&cliente_id=' + cliente.id);
      const seriales = res.data.seriales;

      if (!seriales.length) {
        listaExistentes.hidden = true;
        listaExistentes.innerHTML = '';
        metaExistentes.textContent = 'Este cliente todavía no tiene códigos viejos cargados.';
        return;
      }

      metaExistentes.textContent = seriales.length + ' código' + (seriales.length === 1 ? '' : 's')
        + ' importado' + (seriales.length === 1 ? '' : 's')
        + ', el último el ' + fechaLocal(seriales[0].creado_ts) + '.';

      listaExistentes.hidden = false;
      listaExistentes.innerHTML = seriales.map(function (s) {
        return '<div>' + neo.esc(s.serial) + '</div>';
      }).join('');
    } catch (e) {
      metaExistentes.textContent = '';
      listaExistentes.hidden = false;
      listaExistentes.innerHTML = '<div>' + neo.esc(e.message) + '</div>';
    }
  }
})();
