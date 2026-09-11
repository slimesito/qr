/**
 * Alta, baja y listado de clientes.
 *
 * Define además los helpers compartidos (neo.api, neo.esc, neo.aviso, neo.nota,
 * neo.error) que usa seriales.js, por eso este archivo se carga primero.
 *
 * Se mantiene el patrón del proyecto previo —vista PHP + fetch contra la API
 * JSON— pero sin su estado global desparramado: todo vive dentro de este IIFE
 * y lo único que se expone es el objeto neo.
 */
(function () {
  'use strict';

  const neo = (window.neo = {});

  neo.esc = function (valor) {
    return String(valor == null ? '' : valor).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  };

  // ==================== GLOBO FLOTANTE ====================

  /**
   * La cajita que muestra un dato completo al pasar el mouse (hoy: el id de un
   * cliente al pasar por la columna del ID).
   *
   * No se usa el title del navegador, que es lo más barato, por dos razones:
   * tarda casi un segundo en aparecer y se dibuja con el estilo del sistema
   * operativo, que no se parece en nada al resto del panel. Y no se dibuja con
   * un ::after sobre la propia celda porque quedaría recortado dos veces -por
   * el overflow que hace la elipsis y por el scroll de la lista-, así que la
   * caja vive suelta en el <body> y se posiciona a mano.
   */
  const globoCaja = document.getElementById('globo');

  // Un respiro antes de aparecer: sin él, cruzar la lista de arriba abajo con
  // el mouse dispara un globo por fila y la interfaz parpadea. Al salir se
  // oculta de una, que es lo que se espera.
  const MS_GLOBO = 140;
  const MS_SALIDA = 160;   // tiene que cubrir la transición del CSS

  let globoEntrar = null;
  let globoSalir = null;
  let globoAncla = null;

  neo.globo = {
    /**
     * @param {Element} ancla   elemento del que cuelga el globo
     * @param {string}  texto   el valor completo
     * @param {string=} rotulo  qué es ese valor ("ID"); opcional
     */
    mostrar: function (ancla, texto, rotulo) {
      if (!globoCaja || globoAncla === ancla) return;
      globoAncla = ancla;

      clearTimeout(globoEntrar);
      globoEntrar = setTimeout(function () {
        clearTimeout(globoSalir);
        globoCaja.innerHTML =
          (rotulo ? '<span class="rotulo">' + neo.esc(rotulo) + '</span>' : '') +
          '<span class="valor">' + neo.esc(texto) + '</span>';
        globoCaja.hidden = false;
        ubicar(ancla);
        // Dos cuadros: el primero para que el navegador registre el estado
        // inicial de la transición, si no la caja aparece de golpe.
        requestAnimationFrame(function () {
          requestAnimationFrame(function () { globoCaja.classList.add('visible'); });
        });
      }, MS_GLOBO);
    },

    ocultar: function () {
      if (!globoCaja) return;
      globoAncla = null;
      clearTimeout(globoEntrar);
      globoCaja.classList.remove('visible');
      // Se esconde recién cuando terminó de desvanecerse; ocultarla en el
      // mismo momento se comería la transición de salida.
      clearTimeout(globoSalir);
      globoSalir = setTimeout(function () { globoCaja.hidden = true; }, MS_SALIDA);
    }
  };

  // Debajo del elemento y alineada a su izquierda, salvo que no haya lugar: en
  // ese caso se sube o se corre, para que nunca quede mitad afuera de la
  // ventana. Se mide después de escribir el contenido, con la caja ya visible
  // pero todavía transparente.
  const SEPARACION = 6;
  const BORDE = 8;
  function ubicar(ancla) {
    const caja = ancla.getBoundingClientRect();
    const propio = globoCaja.getBoundingClientRect();

    let izq = caja.left;
    izq = Math.min(izq, window.innerWidth - propio.width - BORDE);
    izq = Math.max(izq, BORDE);

    let arriba = caja.bottom + SEPARACION;
    if (arriba + propio.height > window.innerHeight - BORDE) {
      arriba = caja.top - propio.height - SEPARACION;
    }

    globoCaja.style.left = Math.round(izq) + 'px';
    globoCaja.style.top = Math.round(arriba) + 'px';
  }

  // Cualquier movimiento del fondo deja el globo apuntando a otro lado, así que
  // se cierra en vez de perseguirlo. El true es para que también la cierre el
  // scroll de la lista del sidebar, que no burbujea.
  window.addEventListener('scroll', function () { neo.globo.ocultar(); }, true);

  // ==================== AVISOS ====================

  const avisoCaja = document.getElementById('aviso');
  const avisoMarca = document.getElementById('avisoMarca');
  const avisoTexto = document.getElementById('avisoTexto');
  const avisoDato = document.getElementById('avisoDato');
  const avisoCopiar = document.getElementById('avisoCopiar');

  // Un "listo" pelado se lee de un vistazo. Un error hay que entenderlo, y un
  // aviso con dato -un link para pasarle a la grabadora- hay que leerlo
  // carácter por carácter, o copiarlo a mano si el portapapeles no estuvo: los
  // dos se quedan más. En todos los casos el reloj se frena apenas se toca el
  // aviso (ver abajo).
  const MS_AVISO = 4000;
  const MS_LARGO = 12000;

  let avisoTimer = null;
  let avisoAnfitrion = null;

  /**
   * De qué elemento colgar el aviso para que se lea.
   *
   * El aviso se muestra con .show() y no con .showModal(), para no bloquear la
   * página ni traer un backdrop propio. El costo es que .show() no promueve al
   * top layer: colgando del <body>, un aviso queda por debajo del ::backdrop
   * con blur del modal abierto -ilegible justo cuando informa el error al
   * guardar- y además es inert, o sea que su texto ni se puede seleccionar.
   *
   * Colgarlo del <dialog> abierto lo resuelve sin volverlo modal: los
   * descendientes de un modal se pintan dentro del top layer y no son inert.
   * Al ser position:fixed se ubica igual respecto del viewport.
   */
  function anfitrionDelAviso() {
    // En orden de documento; la app nunca apila dos modales, así que alcanza.
    const abiertos = document.querySelectorAll('dialog[open]:not(#aviso)');
    return abiertos.length ? abiertos[abiertos.length - 1] : document.body;
  }

  function volverAlBody() {
    // El modal que hospedaba el aviso se cerró y se lo llevaría puesto: un
    // <dialog> cerrado es display:none, y con él desaparecen sus hijos.
    const abierto = avisoCaja.open;
    soltarAnfitrion();
    document.body.appendChild(avisoCaja);
    if (abierto && !avisoCaja.open) avisoCaja.show();
  }

  function soltarAnfitrion() {
    if (!avisoAnfitrion) return;
    avisoAnfitrion.removeEventListener('close', volverAlBody);
    avisoAnfitrion = null;
  }

  /**
   * @param {string} texto  la frase que se lee
   * @param {string} tipo   'ok' (salió), 'error' (falló) o '' (ni una cosa ni la otra)
   * @param {string} [dato] pieza copiable que se muestra aparte, no dentro del texto
   */
  function mostrarAviso(texto, tipo, dato) {
    avisoTexto.textContent = texto;
    avisoCaja.classList.toggle('error', tipo === 'error');
    avisoCaja.classList.toggle('ok', tipo === 'ok');
    avisoMarca.textContent = tipo === 'ok' ? '✓' : '';
    avisoMarca.hidden = tipo !== 'ok';

    avisoDato.textContent = dato || '';
    avisoDato.hidden = !dato;

    // El botón aparece cuando hay algo que valga la pena llevarse: el dato si
    // lo hay, y si no el texto del error, que se pega tal cual en un mensaje.
    avisoCopiar.hidden = !dato && tipo !== 'error';
    avisoCopiar.textContent = 'Copiar';

    const anfitrion = anfitrionDelAviso();
    if (avisoCaja.parentNode !== anfitrion) {
      soltarAnfitrion();
      anfitrion.appendChild(avisoCaja);
      if (anfitrion !== document.body) {
        avisoAnfitrion = anfitrion;
        anfitrion.addEventListener('close', volverAlBody);
      }
    }

    if (!avisoCaja.open) {
      // .show() mueve el foco al primer control del aviso, que se lo roba al
      // campo que se estaba escribiendo cuando saltó el error. El aviso ya se
      // anuncia solo por aria-live, así que el foco vuelve de donde vino y el
      // aviso queda ahí para quien lo quiera (es alcanzable con Tab).
      const foco = document.activeElement;
      avisoCaja.show();
      if (foco && foco !== document.body && typeof foco.focus === 'function') foco.focus();
    }
    clearTimeout(avisoTimer);
    avisoTimer = setTimeout(cerrarAviso, tipo === 'error' || dato ? MS_LARGO : MS_AVISO);
  }

  function cerrarAviso() {
    clearTimeout(avisoTimer);
    avisoCaja.close();
    if (avisoAnfitrion) volverAlBody();
  }

  // Apenas se toca el aviso -para seleccionar el texto, para copiarlo- el reloj
  // se frena y queda hasta que lo cierren. Pasar el mouse por encima no cuenta:
  // eso pasa de casualidad, tocarlo no.
  ['pointerdown', 'focusin'].forEach(function (evento) {
    avisoCaja.addEventListener(evento, function () { clearTimeout(avisoTimer); });
  });

  document.getElementById('avisoCerrar').addEventListener('click', cerrarAviso);

  avisoCopiar.addEventListener('click', async function () {
    // Se copia el dato solo, no la frase que lo acompaña: un link pegado con
    // "Link copiado" adelante no le sirve a nadie. Sin dato -un error- se
    // copia el texto, que es justamente lo que se pega en un mensaje.
    const fuente = avisoDato.hidden ? avisoTexto : avisoDato;
    try {
      await navigator.clipboard.writeText(fuente.textContent);
      avisoCopiar.textContent = 'Copiado';
    } catch (_) {
      // navigator.clipboard no existe fuera de contexto seguro (HTTP plano en
      // desarrollo). Ahí se deja el texto seleccionado para copiarlo a mano.
      const rango = document.createRange();
      rango.selectNodeContents(fuente);
      const seleccion = window.getSelection();
      seleccion.removeAllRanges();
      seleccion.addRange(rango);
      avisoCopiar.textContent = 'Copiá con Ctrl+C';
    }
  });

  /**
   * Confirmación de que algo salió: lleva ✓ y se va solo. `dato` es la pieza
   * que el aviso entrega para llevarse (hoy, el link de la grabadora); va
   * aparte del texto y es lo que copia el botón.
   */
  neo.aviso = function (texto, dato) { mostrarAviso(texto, 'ok', dato); };

  /**
   * Aviso sin veredicto: no falló nada, pero tampoco hay nada que festejar y
   * queda algo por hacer a mano. Sin ✓, para no afirmar que salió.
   */
  neo.nota = function (texto, dato) { mostrarAviso(texto, '', dato); };

  /** Error: dura más, se puede copiar y se distingue a la vista. */
  neo.error = function (texto) { mostrarAviso(texto, 'error'); };

  /**
   * fetch + envelope {ok, data|error}. Los errores de la API vienen con su
   * código HTTP real, así que basta con mirar res.ok y el flag.
   */
  neo.api = async function (url, opciones) {
    const res = await fetch(url, opciones);

    // Si la sesión venció, la API responde 401 en vez de redirigir (una
    // redirección a HTML dentro de un fetch sólo daría un error raro).
    if (res.status === 401) {
      window.location = '/login';
      throw new Error('Sesión expirada');
    }

    let cuerpo = null;
    try { cuerpo = await res.json(); } catch (_) { /* respuesta no JSON */ }

    if (!res.ok || !cuerpo || cuerpo.ok !== true) {
      throw new Error((cuerpo && cuerpo.error) || 'Error ' + res.status);
    }
    return cuerpo;
  };

  neo.post = function (url, datos) {
    return neo.api(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(datos || {})
    });
  };

  // ==================== LISTADO ====================

  const tabla = document.getElementById('tablaClientes');
  const verInactivos = document.getElementById('verInactivos');
  let clientes = [];

  // Cliente elegido, para volver a marcar su fila después de cada repintado:
  // generar, importar y guardar un logo recargan el listado, y sin esto la
  // fila quedaría sin marcar aunque su panel siga abierto en el área principal.
  let elegidoId = null;

  function marcarElegido() {
    tabla.querySelectorAll('tr[data-id]').forEach(function (fila) {
      fila.classList.toggle('seleccionada', Number(fila.dataset.id) === elegidoId);
    });
  }

  // Flecha saliendo de una bandeja: el ícono de "subir un archivo" de siempre.
  // Va como SVG inline y no como emoji o glifo Unicode porque así hereda el
  // color del botón (currentColor) y se dibuja igual en cualquier sistema, sin
  // depender de qué fuente tenga instalada.
  const ICONO_SUBIR =
    '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" ' +
         'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
      '<path d="M8 10.5V3.5"/>' +
      '<path d="M5 6.5 8 3.5l3 3"/>' +
      '<path d="M3 11v1.5A1.5 1.5 0 0 0 4.5 14h7a1.5 1.5 0 0 0 1.5-1.5V11"/>' +
    '</svg>';

  neo.cargarClientes = async function () {
    try {
      const res = await neo.api('/api/clientes?action=list' + (verInactivos.checked ? '&inactivos=1' : ''));
      clientes = res.data;
      pintar();
    } catch (e) {
      tabla.innerHTML = '<tr><td colspan="4" class="vacio">' + neo.esc(e.message) + '</td></tr>';
    }
  };

  function pintar() {
    // El repintado se lleva puesta la celda de la que cuelga el globo, así que
    // se cierra antes de reemplazar las filas.
    neo.globo.ocultar();

    if (!clientes.length) {
      tabla.innerHTML = '<tr><td colspan="4" class="vacio">Todavía no hay clientes.</td></tr>';
      return;
    }

    tabla.innerHTML = clientes.map(function (c) {
      // El texto completo no entra en la columna del sidebar, así que el botón
      // queda cuadrado con el ícono y lo que dice se lee por title/aria-label.
      const titulo = c.tiene_logo ? 'Cambiar logo' : 'Agregar logo';
      const nombre = neo.esc(c.nombre);

      return '<tr data-id="' + c.id + '">' +
        // El span de adentro es lo que se mide para dimensionar la columna:
        // una celda de tabla siempre reporta el ancho que la columna le da, no
        // el de su contenido.
        '<td class="mono col-id"><span>' + c.id + '</span></td>' +
        // El title repone el nombre entero cuando la celda lo recorta.
        '<td class="nombre" title="' + nombre + '">' + nombre + '</td>' +
        '<td class="num">' + c.seriales + '</td>' +
        '<td class="acciones"><button class="btn-icono" data-accion="logo" ' +
          'data-logo="' + (c.tiene_logo ? '1' : '0') + '" ' +
          'title="' + titulo + '" aria-label="' + titulo + '">' + ICONO_SUBIR + '</button></td>' +
        '</tr>';
    }).join('');

    ajustarColumnaId();
    marcarElegido();
  }

  // La tabla del sidebar es de layout fijo (hace falta para recortar el nombre
  // con puntos suspensivos), así que la columna del ID no crece sola: con un id
  // escrito a mano -999999- el número se derramaba encima del nombre. Se mide
  // el id más largo que está en pantalla, con la fuente real de la celda, y se
  // le pasa el ancho a la CSS. Medir en vez de estimar por cantidad de dígitos
  // es lo que hace que dé igual qué monoespaciada tenga instalada el sistema.
  const ANCHO_ID_MIN = 32;   // el "ID" del encabezado, que es el piso real
  const ANCHO_ID_MAX = 78;   // más que esto se le come el nombre, que importa más
  function ajustarColumnaId() {
    let ancho = 0;
    tabla.querySelectorAll('td.col-id > span').forEach(function (span) {
      ancho = Math.max(ancho, span.offsetWidth);
    });
    if (!ancho) return;   // "Cargando…" y los avisos van con colspan, sin celda de id
    const total = Math.min(Math.max(ancho + 10, ANCHO_ID_MIN), ANCHO_ID_MAX);
    tabla.closest('table').style.setProperty('--col-id', total + 'px');
  }

  // Al pasar el mouse por la columna del id se muestra el número entero, esté
  // recortado o no. Mostrarlo sólo cuando la elipsis se lo come sería más
  // sobrio, pero la columna se estira hasta que el id entra (ver
  // ajustarColumnaId): el recorte recién aparece pasado el tope, así que la
  // el globo casi nunca se vería y quedaría pareciendo roto justo en el caso que
  // la motivó -un id largo escrito a mano-.
  //
  // Va con mouseover/mouseout y no con mouseenter porque estos sí burbujean, y
  // así alcanza con un par de escuchas en la tabla en lugar de dos por fila
  // repintada.
  tabla.addEventListener('mouseover', function (ev) {
    const celda = ev.target.closest('td.col-id');
    if (!celda) return;
    neo.globo.mostrar(celda, celda.textContent.trim(), 'ID');
  });

  tabla.addEventListener('mouseout', function (ev) {
    const celda = ev.target.closest('td.col-id');
    // Moverse dentro de la misma celda (del span a la celda, por ejemplo)
    // dispara mouseout igual, y ahí no hay que cerrar nada.
    if (celda && celda.contains(ev.relatedTarget)) return;
    if (celda) neo.globo.ocultar();
  });

  tabla.addEventListener('click', function (ev) {
    const fila = ev.target.closest('tr[data-id]');
    if (!fila) return;

    const cliente = clientes.find(c => c.id === Number(fila.dataset.id));
    if (!cliente) return;

    // El botón de logo vive adentro de la fila, así que sin este corte
    // temprano un click ahí también dispararía la selección de abajo y
    // abriría el panel de lotes detrás del modal.
    const boton = ev.target.closest('[data-accion="logo"]');
    if (boton) {
      abrirLogo(cliente);
      return;
    }

    elegidoId = cliente.id;
    marcarElegido();
    neo.abrirSeriales(cliente);
  });

  verInactivos.addEventListener('change', neo.cargarClientes);

  // ==================== ALTA ====================

  const dlgNuevo = document.getElementById('dlgNuevo');
  const nombreNuevo = document.getElementById('nombreNuevo');
  const idNuevo = document.getElementById('idNuevo');
  const logoNuevo = document.getElementById('logoNuevo');
  const vistaLogoNuevo = document.getElementById('vistaLogoNuevo');

  // Objeto URL del blob del preview de alta, para poder revocarlo cuando se
  // elige otro archivo o se cierra el modal, y no ir acumulando referencias.
  let vistaLogoNuevoUrl = null;

  function limpiarVistaLogo(caja) {
    if (vistaLogoNuevoUrl && caja === vistaLogoNuevo) {
      URL.revokeObjectURL(vistaLogoNuevoUrl);
      vistaLogoNuevoUrl = null;
    }
    caja.innerHTML = '';
    caja.hidden = true;
  }

  // El preview de un archivo recién elegido nunca se incrusta inline: eso
  // requeriría confiar en un SVG que todavía no pasó por el saneador del
  // servidor. Un <img src="blob:...">  trata al SVG como imagen pasiva -no
  // como documento que puede tener <script>- así que es seguro mostrarlo
  // antes de subirlo.
  function previsualizarArchivo(input, caja) {
    if (caja === vistaLogoNuevo && vistaLogoNuevoUrl) {
      URL.revokeObjectURL(vistaLogoNuevoUrl);
      vistaLogoNuevoUrl = null;
    }

    const archivo = input.files && input.files[0];
    if (!archivo) {
      caja.innerHTML = '';
      caja.hidden = true;
      return;
    }

    const url = URL.createObjectURL(archivo);
    if (caja === vistaLogoNuevo) vistaLogoNuevoUrl = url;

    caja.innerHTML = '';
    const img = document.createElement('img');
    img.src = url;
    img.alt = 'Vista previa del logo';
    caja.appendChild(img);
    caja.hidden = false;
  }

  logoNuevo.addEventListener('change', function () {
    previsualizarArchivo(logoNuevo, vistaLogoNuevo);
  });

  document.getElementById('btnNuevo').addEventListener('click', function () {
    nombreNuevo.value = '';
    idNuevo.value = '';
    logoNuevo.value = '';
    limpiarVistaLogo(vistaLogoNuevo);
    dlgNuevo.showModal();
    nombreNuevo.focus();
  });

  document.getElementById('btnCancelarNuevo').addEventListener('click', () => dlgNuevo.close());

  document.getElementById('btnGuardarNuevo').addEventListener('click', async function (ev) {
    const nombre = nombreNuevo.value.trim();
    if (!nombre) {
      neo.error('El nombre es obligatorio');
      nombreNuevo.focus();
      return;
    }

    // El ID es opcional: en blanco lo asigna la base. Se valida igual acá y en
    // el backend; esto es sólo para no gastar un request en un error de tipeo.
    const id = idNuevo.value.trim();
    if (id && !/^[0-9]+$/.test(id)) {
      neo.error('El ID tiene que ser un número entero');
      idNuevo.focus();
      return;
    }

    // El logo es opcional: sin archivo, el cliente queda con el logo de
    // prueba (el mismo placeholder de siempre) hasta que se le cargue uno
    // propio desde el botón de la tabla.
    const archivoLogo = logoNuevo.files && logoNuevo.files[0];
    const datos = { nombre: nombre, id: id };

    ev.target.disabled = true;
    try {
      if (archivoLogo) datos.logo = await archivoLogo.text();
      const res = await neo.post('/api/clientes?action=create', datos);
      dlgNuevo.close();
      neo.aviso('Cliente creado con el id ' + res.id);
      await neo.cargarClientes();
    } catch (e) {
      neo.error(e.message);
    } finally {
      ev.target.disabled = false;
    }
  });

  // El input de archivo queda afuera a propósito: Enter ahí abre el selector
  // del sistema operativo, y sumarlo dispararía el selector y el guardado a
  // la vez.
  [nombreNuevo, idNuevo].forEach(function (campo) {
    campo.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') document.getElementById('btnGuardarNuevo').click();
    });
  });

  // ==================== BAJA ====================

  const dlgBaja = document.getElementById('dlgBaja');
  let clienteABajar = null;

  function pedirBaja(cliente) {
    clienteABajar = cliente;
    document.getElementById('bajaNombre').textContent = cliente.nombre;
    dlgBaja.showModal();
  }

  document.getElementById('btnCancelarBaja').addEventListener('click', () => dlgBaja.close());

  document.getElementById('btnConfirmarBaja').addEventListener('click', async function (ev) {
    if (!clienteABajar) return;

    ev.target.disabled = true;
    try {
      await neo.post('/api/clientes?action=delete&id=' + clienteABajar.id);
      dlgBaja.close();
      neo.aviso('Cliente dado de baja. Sus seriales siguen reservados.');
      await neo.cargarClientes();
    } catch (e) {
      neo.error(e.message);
    } finally {
      ev.target.disabled = false;
      clienteABajar = null;
    }
  });

  // ==================== LOGO POR CLIENTE ====================

  const dlgLogo = document.getElementById('dlgLogo');
  const logoNombreCliente = document.getElementById('logoNombreCliente');
  const vistaLogoActual = document.getElementById('vistaLogoActual');
  const avisoLogoPorDefecto = document.getElementById('avisoLogoPorDefecto');
  const logoArchivo = document.getElementById('logoArchivo');
  const vistaLogoNueva = document.getElementById('vistaLogoNueva');

  let clienteLogo = null;
  let vistaLogoNuevaUrl = null;

  // El logo guardado sí se incrusta inline (a diferencia del preview de un
  // archivo recién elegido): ya pasó por el saneador del servidor, así que es
  // geometría de confianza y no el SVG crudo que subió el usuario. El backend
  // siempre manda el logo efectivo -el propio o, si no tiene, el mismo logo de
  // prueba que usan sus QR- así que acá sólo hace falta decidir si mostrar el
  // aviso de "todavía sin logo propio", con tieneLogo.
  // Se pinta en negro, el mismo color con el que va al centro del QR
  // (QrLogo::COLOR): este preview es cómo va a salir, no una muestra de marca.
  function pintarLogoActual(viewbox, markup, tieneLogo) {
    vistaLogoActual.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="'
      + neo.esc(viewbox) + '" fill="#000000">' + markup + '</svg>';
    vistaLogoActual.hidden = false;
    avisoLogoPorDefecto.hidden = !!tieneLogo;
  }

  async function abrirLogo(cliente) {
    clienteLogo = cliente;
    logoNombreCliente.textContent = cliente.nombre;
    logoArchivo.value = '';
    avisoLogoPorDefecto.hidden = true;

    if (vistaLogoNuevaUrl) {
      URL.revokeObjectURL(vistaLogoNuevaUrl);
      vistaLogoNuevaUrl = null;
    }
    vistaLogoNueva.innerHTML = '';
    vistaLogoNueva.hidden = true;

    vistaLogoActual.innerHTML = 'Cargando…';
    vistaLogoActual.hidden = false;
    dlgLogo.showModal();

    try {
      const res = await neo.api('/api/clientes?action=logo&id=' + cliente.id);
      pintarLogoActual(res.data.viewbox, res.data.markup, res.data.tiene_logo);
    } catch (e) {
      neo.error(e.message);
      vistaLogoActual.innerHTML = '';
      vistaLogoActual.hidden = true;
    }
  }

  logoArchivo.addEventListener('change', function () {
    if (vistaLogoNuevaUrl) {
      URL.revokeObjectURL(vistaLogoNuevaUrl);
      vistaLogoNuevaUrl = null;
    }

    const archivo = logoArchivo.files && logoArchivo.files[0];
    if (!archivo) {
      vistaLogoNueva.innerHTML = '';
      vistaLogoNueva.hidden = true;
      return;
    }

    vistaLogoNuevaUrl = URL.createObjectURL(archivo);
    vistaLogoNueva.innerHTML = '';
    const img = document.createElement('img');
    img.src = vistaLogoNuevaUrl;
    img.alt = 'Vista previa del logo nuevo';
    vistaLogoNueva.appendChild(img);
    vistaLogoNueva.hidden = false;
  });

  document.getElementById('btnCancelarLogo').addEventListener('click', () => dlgLogo.close());

  document.getElementById('btnGuardarLogo').addEventListener('click', async function (ev) {
    if (!clienteLogo) return;

    const archivo = logoArchivo.files && logoArchivo.files[0];
    if (!archivo) {
      neo.error('Elegí un archivo SVG');
      return;
    }

    ev.target.disabled = true;
    try {
      const svg = await archivo.text();
      await neo.post('/api/clientes?action=logo&id=' + clienteLogo.id, { svg: svg });
      dlgLogo.close();
      neo.aviso('Logo actualizado');
      // Repinta el botón de la fila entre "Agregar logo" y "Cambiar logo".
      await neo.cargarClientes();
    } catch (e) {
      neo.error(e.message);
    } finally {
      ev.target.disabled = false;
    }
  });

  // ==================== ARRANQUE ====================

  neo.cargarClientes().then(function () {
    // La ruta /clientes/nuevo abre el diálogo de alta directamente.
    if (window.NEO_QR && window.NEO_QR.autoNuevo) {
      document.getElementById('btnNuevo').click();
    }
  });
})();
