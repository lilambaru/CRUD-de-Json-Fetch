'use strict';

const URL_API = '../api/registrar.php';

// toast propio: abajo a la derecha, 2.5 s, con barra de tiempo y pausa al pasar el mouse
const Tostada = Swal.mixin({
  toast: true,
  position: 'bottom-end',
  timer: 2500,
  timerProgressBar: true,
  showConfirmButton: false,
  customClass: { popup: 'tostada-clavo' },
  didOpen: (nodoToast) => {
    nodoToast.addEventListener('mouseenter', Swal.stopTimer);
    nodoToast.addEventListener('mouseleave', Swal.resumeTimer);
  }
});

function escaparHtml(textoCrudo) {
  const nodoTemporal = document.createElement('div');
  nodoTemporal.textContent = String(textoCrudo);
  return nodoTemporal.innerHTML;
}

// espera a que el usuario deje de teclear antes de disparar la función
function retardar(funcionObjetivo, milisegundos) {
  let temporizador = null;
  return (...argumentos) => {
    clearTimeout(temporizador);
    temporizador = setTimeout(() => funcionObjetivo(...argumentos), milisegundos);
  };
}

/*
 * Cliente: toda la conversación con el servidor y con SweetAlert pasa por aquí.
 * El resto del archivo no vuelve a llamar fetch ni Swal directamente.
 */
const Cliente = {
  async solicitar(cargaFormData) {
    let respuestaHttp;
    try {
      respuestaHttp = await fetch(URL_API, { method: 'POST', body: cargaFormData });
    } catch (falloRed) {
      console.error('Sin conexión con el servidor:', falloRed);
      return { success: false, message: 'No se pudo contactar al servidor.', accion: 'error_red', errors: {}, data: null };
    }

    const textoCrudo = await respuestaHttp.text();
    try {
      return JSON.parse(textoCrudo);
    } catch (falloParseo) {
      // si el PHP imprime algo de más, aquí se ve exactamente qué fue
      console.error(`Respuesta no JSON (HTTP ${respuestaHttp.status}):`, textoCrudo);
      return { success: false, message: 'El servidor devolvió una respuesta con formato inválido.', accion: 'error_formato', errors: {}, data: null };
    }
  },

  exito(mensaje) {
    Tostada.fire({ icon: 'success', iconColor: '#f5a524', title: mensaje });
  },

  fallo(mensaje, errores = {}) {
    const detalles = Object.values(errores);
    const listaHtml = detalles.length
      ? `<ul class="lista-errores-swal">${detalles.map((d) => `<li>${escaparHtml(d)}</li>`).join('')}</ul>`
      : '';
    return Swal.fire({
      icon: 'error',
      title: 'No se pudo completar',
      html: `<div>${escaparHtml(mensaje)}</div>${listaHtml}`,
      confirmButtonText: 'Entendido',
      confirmButtonColor: '#1f2933'
    });
  },

  async confirmar(titulo, texto) {
    const decision = await Swal.fire({
      icon: 'question',
      title: titulo,
      text: texto,
      showCancelButton: true,
      confirmButtonText: 'Sí, continuar',
      cancelButtonText: 'Cancelar',
      confirmButtonColor: '#f5a524',
      cancelButtonColor: '#52606d'
    });
    return decision.isConfirmed;
  }
};

const refs = {
  formulario: document.getElementById('formProducto'),
  campoId: document.getElementById('Id'),
  campos: {
    Codigo: document.getElementById('Codigo'),
    Producto: document.getElementById('Producto'),
    Precio: document.getElementById('Precio'),
    Cantidad: document.getElementById('Cantidad')
  },
  aviso: document.getElementById('avisoFormulario'),
  btnGuardar: document.getElementById('btnGuardar'),
  giroGuardar: document.getElementById('giroGuardar'),
  textoBtnGuardar: document.getElementById('textoBtnGuardar'),
  btnCancelar: document.getElementById('btnCancelarEdicion'),
  tituloFormulario: document.getElementById('tituloFormulario'),
  etiquetaModo: document.getElementById('etiquetaModo'),
  cuerpoTabla: document.getElementById('cuerpoTabla'),
  contador: document.getElementById('contadorItems'),
  buscador: document.getElementById('buscador')
};

const estado = {
  modo: 'crear',                 // 'crear' | 'editar'
  itemsEnPantalla: new Map(),
  secuenciaPeticion: 0           // descarta respuestas viejas del buscador
};

const formatoMoneda = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });

/* ---------- validación del lado del cliente ---------- */

const reglasCampo = {
  Codigo: (valor) => {
    const limpio = valor.trim();
    if (!limpio) return 'El código es obligatorio.';
    if (!/^[A-Za-z0-9-]{2,20}$/.test(limpio)) return 'Usa de 2 a 20 caracteres: letras, números o guion.';
    return '';
  },
  Producto: (valor) => {
    const largo = valor.trim().length;
    if (!largo) return 'El nombre del producto es obligatorio.';
    if (largo < 2 || largo > 100) return 'El nombre debe tener entre 2 y 100 caracteres.';
    return '';
  },
  Precio: (valor) => {
    const numero = Number(valor);
    if (valor === '' || Number.isNaN(numero) || numero <= 0) return 'El precio debe ser un número mayor que 0.';
    return '';
  },
  Cantidad: (valor) => {
    if (valor === '' || !Number.isInteger(Number(valor))) return 'La cantidad debe ser un número entero.';
    const piso = estado.modo === 'editar' ? 0 : 1;
    if (Number(valor) < piso) {
      return estado.modo === 'editar' ? 'La cantidad no puede ser negativa.' : 'Un producto nuevo debe registrarse con al menos 1 unidad.';
    }
    return '';
  }
};

function pintarError(nombreCampo, mensaje) {
  const entrada = refs.campos[nombreCampo];
  if (!entrada) return;
  const nodoMensaje = entrada.parentElement.querySelector('.invalid-feedback');
  entrada.classList.toggle('is-invalid', Boolean(mensaje));
  if (nodoMensaje) nodoMensaje.textContent = mensaje || '';
}

function pintarErrores(mapaErrores) {
  Object.entries(mapaErrores).forEach(([nombreCampo, mensaje]) => pintarError(nombreCampo, mensaje));
}

function limpiarErrores() {
  Object.keys(refs.campos).forEach((nombreCampo) => pintarError(nombreCampo, ''));
  refs.aviso.classList.add('d-none');
  refs.aviso.textContent = '';
}

function mostrarAviso(texto) {
  refs.aviso.textContent = texto;
  refs.aviso.classList.remove('d-none');
}

function validarTodo() {
  const hallazgos = {};
  Object.entries(refs.campos).forEach(([nombreCampo, entrada]) => {
    const mensaje = reglasCampo[nombreCampo](entrada.value);
    if (mensaje) hallazgos[nombreCampo] = mensaje;
  });
  return hallazgos;
}

Object.entries(refs.campos).forEach(([nombreCampo, entrada]) => {
  entrada.addEventListener('input', () => {
    pintarError(nombreCampo, reglasCampo[nombreCampo](entrada.value));
    refs.aviso.classList.add('d-none');
  });
});

/* ---------- estado visual del formulario ---------- */

function fijarOcupado(estaOcupado) {
  refs.btnGuardar.disabled = estaOcupado;
  refs.giroGuardar.classList.toggle('d-none', !estaOcupado);
}

function entrarModoCrear() {
  estado.modo = 'crear';
  refs.formulario.reset();
  refs.campoId.value = '';
  refs.campos.Cantidad.min = '1';
  refs.tituloFormulario.textContent = 'Registrar producto';
  refs.etiquetaModo.textContent = 'Nuevo';
  refs.textoBtnGuardar.textContent = 'Registrar';
  refs.btnCancelar.classList.add('d-none');
  limpiarErrores();
}

function entrarModoEditar(itemElegido) {
  estado.modo = 'editar';
  limpiarErrores();
  refs.campoId.value = itemElegido.id;
  refs.campos.Codigo.value = itemElegido.codigo;
  refs.campos.Producto.value = itemElegido.producto;
  refs.campos.Precio.value = itemElegido.precio;
  refs.campos.Cantidad.value = itemElegido.cantidad;
  // al editar se admite 0: el producto puede estar agotado y aun así necesitar corrección
  refs.campos.Cantidad.min = '0';
  refs.tituloFormulario.textContent = 'Editar producto';
  refs.etiquetaModo.textContent = `ID ${itemElegido.id}`;
  refs.textoBtnGuardar.textContent = 'Actualizar';
  refs.btnCancelar.classList.remove('d-none');
  refs.campos.Codigo.focus();
  refs.formulario.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

/* ---------- tabla ---------- */

function esqueletoTabla(filas = 4) {
  refs.cuerpoTabla.replaceChildren();
  for (let i = 0; i < filas; i += 1) {
    const filaVacia = document.createElement('tr');
    for (let j = 0; j < 5; j += 1) {
      const celda = document.createElement('td');
      const barra = document.createElement('span');
      barra.className = 'esqueleto';
      celda.appendChild(barra);
      filaVacia.appendChild(celda);
    }
    refs.cuerpoTabla.appendChild(filaVacia);
  }
}

function celdaTexto(texto, clases = '') {
  const celda = document.createElement('td');
  celda.textContent = texto;
  if (clases) celda.className = clases;
  return celda;
}

function renderTabla(listaItems, mensajeVacio) {
  estado.itemsEnPantalla = new Map(listaItems.map((item) => [item.id, item]));
  refs.cuerpoTabla.replaceChildren();
  refs.contador.textContent = `${listaItems.length} ${listaItems.length === 1 ? 'producto' : 'productos'}`;

  if (!listaItems.length) {
    const filaAviso = document.createElement('tr');
    const celdaAviso = celdaTexto(mensajeVacio, 'text-center text-secondary py-4');
    celdaAviso.colSpan = 5;
    filaAviso.appendChild(celdaAviso);
    refs.cuerpoTabla.appendChild(filaAviso);
    return;
  }

  listaItems.forEach((item) => {
    const fila = document.createElement('tr');
    if (item.cantidad === 0) fila.classList.add('fila-agotada');

    fila.appendChild(celdaTexto(item.codigo, 'fw-semibold'));
    fila.appendChild(celdaTexto(item.producto));
    fila.appendChild(celdaTexto(formatoMoneda.format(item.precio), 'text-end'));

    const celdaCantidad = document.createElement('td');
    celdaCantidad.className = 'text-end';
    const insignia = document.createElement('span');
    insignia.className = item.cantidad === 0 ? 'badge text-bg-danger' : item.cantidad <= 10 ? 'badge text-bg-warning' : 'badge text-bg-success';
    insignia.textContent = item.cantidad === 0 ? 'Agotado' : String(item.cantidad);
    celdaCantidad.appendChild(insignia);
    fila.appendChild(celdaCantidad);

    const celdaAcciones = document.createElement('td');
    celdaAcciones.className = 'text-end';
    const btnEditar = document.createElement('button');
    btnEditar.type = 'button';
    btnEditar.className = 'btn btn-sm btn-outline-dark';
    btnEditar.textContent = 'Editar';
    btnEditar.addEventListener('click', () => entrarModoEditar(item));
    celdaAcciones.appendChild(btnEditar);
    fila.appendChild(celdaAcciones);

    refs.cuerpoTabla.appendChild(fila);
  });
}

/* ---------- despacho de respuestas del backend ---------- */

function alertaGenerica(respuesta) {
  Cliente.fallo(respuesta.message || 'Respuesta inesperada del servidor.', respuesta.errors || {});
}

function despachar(respuesta) {
  switch (respuesta.accion) {
    case 'creado':
      Cliente.exito(respuesta.message);
      entrarModoCrear();
      ListarProductos();
      break;

    case 'actualizado':
      Cliente.exito(respuesta.message);
      entrarModoCrear();
      ListarProductos();
      break;

    case 'listado':
      renderTabla(respuesta.data.items, 'Todavía no hay productos registrados.');
      break;

    case 'buscado':
      renderTabla(respuesta.data.items, `Sin resultados para "${respuesta.data.termino}".`);
      break;

    case 'error_validacion':
      pintarErrores(respuesta.errors);
      mostrarAviso('El servidor encontró datos que corregir.');
      Cliente.fallo(respuesta.message, respuesta.errors);
      break;

    case 'no_encontrado':
      Cliente.fallo(respuesta.message);
      entrarModoCrear();
      ListarProductos();
      break;

    default:
      alertaGenerica(respuesta);
  }
}

/* ---------- operaciones ---------- */

async function pedirListado(accion, extra = {}) {
  const numeroPeticion = ++estado.secuenciaPeticion;
  esqueletoTabla();

  const carga = new FormData();
  carga.set('Accion', accion);
  Object.entries(extra).forEach(([llave, valor]) => carga.set(llave, valor));

  const respuesta = await Cliente.solicitar(carga);
  // si el usuario siguió escribiendo, esta respuesta ya quedó vieja
  if (numeroPeticion !== estado.secuenciaPeticion) return;
  despachar(respuesta);
  if (!respuesta.success) renderTabla([], 'No se pudo cargar el inventario.');
}

function ListarProductos() {
  return pedirListado('listar');
}

function BuscarProductos(termino) {
  return termino.trim() === '' ? ListarProductos() : pedirListado('buscar', { Termino: termino.trim() });
}

refs.buscador.addEventListener('input', retardar((evento) => BuscarProductos(evento.target.value), 350));

refs.btnCancelar.addEventListener('click', entrarModoCrear);

refs.formulario.addEventListener('submit', async (evento) => {
  evento.preventDefault();

  const hallazgos = validarTodo();
  if (Object.keys(hallazgos).length) {
    pintarErrores(hallazgos);
    mostrarAviso('Hay campos con errores. Revísalos antes de continuar.');
    return;
  }
  limpiarErrores();

  if (estado.modo === 'editar') {
    const aceptado = await Cliente.confirmar('¿Guardar los cambios?', 'Se actualizarán los datos de este producto.');
    if (!aceptado) return;
  }

  const carga = new FormData(refs.formulario);
  carga.set('Accion', estado.modo === 'editar' ? 'actualizar' : 'crear');

  fijarOcupado(true);
  const respuesta = await Cliente.solicitar(carga);
  fijarOcupado(false);

  despachar(respuesta);
});

entrarModoCrear();
ListarProductos();
