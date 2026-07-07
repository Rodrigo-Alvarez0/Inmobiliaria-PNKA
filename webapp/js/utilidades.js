/**
 * LIBRERÍA DE UTILIDADES JS
 * Archivo: /js/utilidades.js
 * Codificación: UTF-8
 */

'use strict';

/* ════════════════════════════════════════════════
   API - Comunicación con el servidor
   ════════════════════════════════════════════════ */
const API = {
  /**
   * Petición genérica al servidor
   * @param {string} url
   * @param {object} opciones
   * @returns {Promise<object>}
   */
  async peticion(url, opciones = {}) {
    const config = {
      headers: {
        'Content-Type': 'application/json; charset=utf-8',
        'Accept':       'application/json',
        'X-Pedido-Con': 'XMLHttpRequest',
      },
      credentials: 'same-origin',
      ...opciones,
    };

    try {
      const respuesta = await fetch(url, config);

      // Manejar errores HTTP
      if (respuesta.status === 401) {
        window.location.href = '/html/login.html';
        throw new Error('No autenticado');
      }
      if (respuesta.status === 403) {
        Toast.mostrar('error', 'Acceso denegado', 'No tiene permisos para esta acción.');
        throw new Error('Sin permisos');
      }

      const datos = await respuesta.json();
      return { ok: respuesta.ok, estado: respuesta.status, ...datos };

    } catch (err) {
      if (err.message !== 'No autenticado' && err.message !== 'Sin permisos') {
        Toast.mostrar('error', 'Error de conexión', 'No se pudo comunicar con el servidor.');
      }
      throw err;
    }
  },

  async get(url, params = {}) {
    const qs = new URLSearchParams(params).toString();
    return this.peticion(qs ? `${url}?${qs}` : url, { method: 'GET' });
  },

  async post(url, cuerpo) {
    return this.peticion(url, { method: 'POST', body: JSON.stringify(cuerpo) });
  },

  async put(url, cuerpo) {
    return this.peticion(url, { method: 'PUT', body: JSON.stringify(cuerpo) });
  },

  async delete(url, cuerpo) {
    return this.peticion(url, { method: 'DELETE', body: JSON.stringify(cuerpo) });
  },

  /** Obtiene un token CSRF para un formulario */
  async obtenerCSRF(formulario = 'general') {
    const datos = await this.get('/php/modules/csrf_token.php', { formulario });
    return datos.csrf_token ?? '';
  },
};

/* ════════════════════════════════════════════════
   TOAST - Notificaciones en pantalla
   ════════════════════════════════════════════════ */
const Toast = {
  contenedor: null,

  iniciar() {
    if (this.contenedor) return;
    this.contenedor = document.createElement('div');
    this.contenedor.className = 'toast-contenedor';
    this.contenedor.setAttribute('aria-live', 'polite');
    this.contenedor.setAttribute('aria-atomic', 'false');
    document.body.appendChild(this.contenedor);
  },

  /**
   * @param {'exito'|'error'|'advertencia'|'info'} tipo
   * @param {string} titulo
   * @param {string} [mensaje]
   * @param {number} [duracion=5000]
   */
  mostrar(tipo, titulo, mensaje = '', duracion = 5000) {
    this.iniciar();
    const iconos = { exito: '✅', error: '❌', advertencia: '⚠️', info: 'ℹ️' };
    const el = document.createElement('div');
    el.className = `toast toast--${tipo}`;
    el.setAttribute('role', tipo === 'error' ? 'alert' : 'status');
    el.innerHTML = `
      <span class="toast__icono" aria-hidden="true">${iconos[tipo] ?? 'ℹ️'}</span>
      <div class="toast__texto">
        <div class="toast__titulo">${this._escapar(titulo)}</div>
        ${mensaje ? `<div class="toast__mensaje">${this._escapar(mensaje)}</div>` : ''}
      </div>
      <button class="toast__cerrar" aria-label="Cerrar notificación">✕</button>
    `;

    el.querySelector('.toast__cerrar').addEventListener('click', () => this._remover(el));
    this.contenedor.appendChild(el);

    if (duracion > 0) {
      setTimeout(() => this._remover(el), duracion);
    }
    return el;
  },

  _remover(el) {
    el.style.animation = 'entrarToast .3s ease reverse forwards';
    setTimeout(() => el.remove(), 280);
  },

  _escapar(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  },
};

/* ════════════════════════════════════════════════
   VALIDADOR DE FORMULARIOS
   ════════════════════════════════════════════════ */
const Validador = {
  reglas: {
    requerido: (v)       => v.trim().length > 0              || 'Este campo es obligatorio.',
    correo:    (v)       => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v.trim()) || 'Ingrese un correo electrónico válido.',
    minLong:   (v, n)    => v.trim().length >= n              || `Mínimo ${n} caracteres.`,
    maxLong:   (v, n)    => v.trim().length <= n              || `Máximo ${n} caracteres.`,
    numero:    (v)       => !isNaN(parseFloat(v)) && isFinite(v) || 'Ingrese un número válido.',
    positivo:  (v)       => parseFloat(v) >= 0               || 'El valor no puede ser negativo.',
    entero:    (v)       => Number.isInteger(parseFloat(v))  || 'Ingrese un número entero.',
    contrasena:(v)       => /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,}$/.test(v)
                              || 'Mínimo 8 caracteres con mayúscula, minúscula, número y símbolo.',
    igualar: (v, id) => {
      const ref = document.getElementById(id);
      return ref && v === ref.value || 'Los campos no coinciden.';
    },
  },

  /**
   * Valida un campo y aplica estilos visuales
   * @param {HTMLInputElement|HTMLSelectElement|HTMLTextAreaElement} campo
   * @returns {boolean}
   */
  validarCampo(campo) {
    const validaciones = campo.dataset.validar?.split('|') ?? [];
    if (!validaciones.length) return true;

    let error = null;
    for (const validacion of validaciones) {
      const [nombre, ...args] = validacion.split(':');
      const regla = this.reglas[nombre];
      if (!regla) continue;
      const resultado = regla(campo.value, ...args);
      if (resultado !== true) { error = resultado; break; }
    }

    this._aplicarEstado(campo, error);
    return !error;
  },

  _aplicarEstado(campo, error) {
    const wrapper  = campo.closest('.grupo-campo') ?? campo.parentElement;
    const mensajeEl = wrapper?.querySelector('.campo__mensaje');

    campo.classList.toggle('campo__control--error',  !!error);
    campo.classList.toggle('campo__control--valido', !error && campo.value.trim().length > 0);
    campo.setAttribute('aria-invalid', error ? 'true' : 'false');

    if (mensajeEl) {
      mensajeEl.textContent  = error ?? '';
      mensajeEl.className    = `campo__mensaje ${error ? 'campo__mensaje--error' : ''}`;
    }
  },

  /**
   * Valida todos los campos de un formulario
   * @param {HTMLFormElement} form
   * @returns {boolean}
   */
  validarFormulario(form) {
    const campos  = form.querySelectorAll('[data-validar]');
    let valido    = true;
    let primeroError = null;

    campos.forEach((campo) => {
      if (!this.validarCampo(campo)) {
        valido = false;
        if (!primeroError) primeroError = campo;
      }
    });

    if (primeroError) primeroError.focus();
    return valido;
  },

  /** Muestra errores del servidor en el formulario */
  mostrarErroresServidor(form, errores = {}) {
    Object.entries(errores).forEach(([nombre, mensaje]) => {
      const campo = form.querySelector(`[name="${nombre}"]`);
      if (campo) this._aplicarEstado(campo, mensaje);
    });
  },

  /** Limpia todos los estados de validación */
  limpiar(form) {
    form.querySelectorAll('[data-validar]').forEach((campo) => {
      campo.classList.remove('campo__control--error', 'campo__control--valido');
      campo.removeAttribute('aria-invalid');
      const wrapper = campo.closest('.grupo-campo') ?? campo.parentElement;
      const msg = wrapper?.querySelector('.campo__mensaje');
      if (msg) { msg.textContent = ''; msg.className = 'campo__mensaje'; }
    });
  },
};

/* ════════════════════════════════════════════════
   FUERZA DE CONTRASEÑA
   ════════════════════════════════════════════════ */
const FuerzaContrasena = {
  analizar(valor) {
    let puntaje = 0;
    if (valor.length >= 8)  puntaje++;
    if (valor.length >= 12) puntaje++;
    if (/[A-Z]/.test(valor)) puntaje++;
    if (/[a-z]/.test(valor)) puntaje++;
    if (/[0-9]/.test(valor)) puntaje++;
    if (/[\W_]/.test(valor)) puntaje++;

    const niveles = [
      { min: 0, max: 1, texto: 'Muy débil',  color: '#dc2626', porcentaje: 16 },
      { min: 2, max: 2, texto: 'Débil',       color: '#f97316', porcentaje: 33 },
      { min: 3, max: 3, texto: 'Regular',     color: '#eab308', porcentaje: 50 },
      { min: 4, max: 4, texto: 'Buena',       color: '#22c55e', porcentaje: 66 },
      { min: 5, max: 5, texto: 'Fuerte',      color: '#16a34a', porcentaje: 83 },
      { min: 6, max: 6, texto: 'Muy fuerte',  color: '#15803d', porcentaje: 100 },
    ];
    return niveles.find(n => puntaje >= n.min && puntaje <= n.max) ?? niveles[0];
  },

  enlazar(campoId, contenedorId) {
    const campo     = document.getElementById(campoId);
    const contenedor = document.getElementById(contenedorId);
    if (!campo || !contenedor) return;

    const barra  = contenedor.querySelector('.fuerza-contrasena__progreso');
    const texto  = contenedor.querySelector('.fuerza-contrasena__texto');

    campo.addEventListener('input', () => {
      if (!campo.value) {
        if (barra) { barra.style.width = '0'; barra.style.background = ''; }
        if (texto) texto.textContent = '';
        return;
      }
      const nivel = this.analizar(campo.value);
      if (barra) {
        barra.style.width      = `${nivel.porcentaje}%`;
        barra.style.background = nivel.color;
      }
      if (texto) {
        texto.textContent = nivel.texto;
        texto.style.color = nivel.color;
      }
    });
  },
};

/* ════════════════════════════════════════════════
   MODAL
   ════════════════════════════════════════════════ */
const Modal = {
  /**
   * Abre un modal existente por su ID
   */
  abrir(idModal) {
    const overlay = document.getElementById(idModal);
    if (!overlay) return;
    overlay.hidden = false;
    overlay.removeAttribute('aria-hidden');
    document.body.style.overflow = 'hidden';

    // Focus trap
    const focusables = overlay.querySelectorAll(
      'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
    );
    if (focusables[0]) focusables[0].focus();

    overlay.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') this.cerrar(idModal);
      if (e.key === 'Tab') {
        const lista = [...focusables];
        const primero = lista[0], ultimo = lista[lista.length - 1];
        if (e.shiftKey && document.activeElement === primero) {
          e.preventDefault(); ultimo.focus();
        } else if (!e.shiftKey && document.activeElement === ultimo) {
          e.preventDefault(); primero.focus();
        }
      }
    });
  },

  cerrar(idModal) {
    const overlay = document.getElementById(idModal);
    if (!overlay) return;
    overlay.hidden = true;
    overlay.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  },

  /**
   * Modal de confirmación dinámico
   */
  confirmar({ titulo = '¿Está seguro?', mensaje, textoCancelar = 'Cancelar', textoConfirmar = 'Confirmar', tipo = 'peligro' } = {}) {
    return new Promise((resolver) => {
      const overlay = document.createElement('div');
      overlay.className = 'modal-overlay';
      overlay.innerHTML = `
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="confirm-titulo" style="max-width:420px">
          <div class="modal__cabecera">
            <h2 class="modal__titulo" id="confirm-titulo">${titulo}</h2>
          </div>
          <div class="modal__cuerpo">
            <p style="color:var(--texto-secundario)">${mensaje ?? ''}</p>
          </div>
          <div class="modal__pie">
            <button class="btn btn-tenue btn-cancelar">${textoCancelar}</button>
            <button class="btn btn-${tipo} btn-confirmar">${textoConfirmar}</button>
          </div>
        </div>`;

      document.body.appendChild(overlay);
      document.body.style.overflow = 'hidden';

      const limpiar = (resultado) => {
        document.body.style.overflow = '';
        overlay.remove();
        resolver(resultado);
      };

      overlay.querySelector('.btn-cancelar').addEventListener('click', () => limpiar(false));
      overlay.querySelector('.btn-confirmar').addEventListener('click', () => limpiar(true));
      overlay.addEventListener('click', (e) => { if (e.target === overlay) limpiar(false); });
      overlay.querySelector('.btn-cancelar').focus();

      overlay.addEventListener('keydown', (e) => { if (e.key === 'Escape') limpiar(false); });
    });
  },
};

/* ════════════════════════════════════════════════
   SESIÓN - Estado del usuario en cliente
   ════════════════════════════════════════════════ */
const Sesion = {
  datos: null,

  async cargar() {
    try {
      const res = await API.get('/php/modules/autenticacion.php', { accion: 'sesion_actual' });
      this.datos = res.datos ?? { autenticado: false };
    } catch {
      this.datos = { autenticado: false };
    }
    return this.datos;
  },

  get estaAutenticado()  { return this.datos?.autenticado  ?? false; },
  get nivelRol()         { return this.datos?.nivel_rol     ?? 1; },
  get nombreRol()        { return this.datos?.nombre_rol    ?? 'invitado'; },
  get nombre()           { return this.datos?.nombre        ?? ''; },
  get idUsuario()        { return this.datos?.id_usuario    ?? 0; },

  tieneNivel(nivel)      { return this.nivelRol >= nivel; },

  /**
   * Aplica visibilidad de elementos según nivel del rol
   * Uso en HTML: data-nivel-minimo="3"  → visible solo si nivel >= 3
   *              data-nivel-maximo="2"  → visible solo si nivel <= 2
   */
  aplicarPermisos() {
    document.querySelectorAll('[data-nivel-minimo]').forEach((el) => {
      el.hidden = !this.tieneNivel(parseInt(el.dataset.nivelMinimo, 10));
    });
    document.querySelectorAll('[data-nivel-maximo]').forEach((el) => {
      el.hidden = this.nivelRol > parseInt(el.dataset.nivelMaximo, 10);
    });
    document.querySelectorAll('[data-rol-requerido]').forEach((el) => {
      el.hidden = el.dataset.rolRequerido !== this.nombreRol;
    });
  },

  async cerrar() {
    const csrf = await API.obtenerCSRF('general');
    await API.post('/php/modules/autenticacion.php', { accion: 'cerrar_sesion', csrf_token: csrf });
    window.location.href = '/html/login.html';
  },
};

/* ════════════════════════════════════════════════
   TABLA - Gestión de tablas dinámicas
   ════════════════════════════════════════════════ */
class TablaAdmin {
  constructor(config) {
    this.urlBase      = config.urlBase;
    this.idTabla      = config.idTabla;
    this.columnas     = config.columnas ?? [];
    this.renderFila   = config.renderFila;
    this.estadoActual = { pagina: 1, limite: 20, buscar: '', ...config.parametros };

    this.tabla       = document.getElementById(idTabla);
    this.cuerpoTabla = this.tabla?.querySelector('tbody');
    this.totalReg    = 0;
    this.totalPags   = 0;
  }

  async cargar(params = {}) {
    Object.assign(this.estadoActual, params);
    this._mostrarCargando();

    try {
      const datos = await API.get(this.urlBase, this.estadoActual);
      this.totalReg  = datos.datos?.total        ?? 0;
      this.totalPags = datos.datos?.total_paginas ?? 0;
      this._renderizarFilas(datos.datos);
      this._actualizarPaginacion();
    } catch (err) {
      console.error('[TablaAdmin]', err);
    }
  }

  _mostrarCargando() {
    if (this.cuerpoTabla) {
      const cols = this.columnas.length || 5;
      this.cuerpoTabla.innerHTML = `
        <tr><td colspan="${cols}" style="text-align:center;padding:3rem;color:var(--texto-tenue)">
          <div class="spinner spinner--primario" style="margin:0 auto 1rem"></div>
          <div>Cargando datos…</div>
        </td></tr>`;
    }
  }

  _renderizarFilas(datos) {
    if (!this.cuerpoTabla) return;
    const items = datos?.[Object.keys(datos).find(k => Array.isArray(datos[k]))] ?? [];
    if (!items.length) {
      const cols = this.columnas.length || 5;
      this.cuerpoTabla.innerHTML = `
        <tr><td colspan="${cols}">
          <div class="estado-vacio">
            <div class="estado-vacio__icono">📋</div>
            <div class="estado-vacio__titulo">Sin resultados</div>
            <div class="estado-vacio__desc">No se encontraron registros con los filtros actuales.</div>
          </div>
        </td></tr>`;
      return;
    }
    this.cuerpoTabla.innerHTML = items.map(fila => this.renderFila(fila)).join('');
  }

  _actualizarPaginacion() {
    const el = document.getElementById(`paginacion-${this.idTabla}`);
    if (!el) return;

    const { pagina } = this.estadoActual;
    const inicio = (pagina - 1) * this.estadoActual.limite + 1;
    const fin    = Math.min(pagina * this.estadoActual.limite, this.totalReg);

    el.querySelector('.paginacion__info').textContent =
      this.totalReg ? `Mostrando ${inicio}–${fin} de ${this.totalReg}` : 'Sin registros';

    const botonesEl = el.querySelector('.paginacion__botones');
    if (!botonesEl) return;

    let html = `<button class="paginacion__btn" data-pag="${pagina-1}" ${pagina<=1?'disabled':''}>‹</button>`;
    for (let p = Math.max(1, pagina-2); p <= Math.min(this.totalPags, pagina+2); p++) {
      html += `<button class="paginacion__btn ${p===pagina?'paginacion__btn--activo':''}" data-pag="${p}">${p}</button>`;
    }
    html += `<button class="paginacion__btn" data-pag="${pagina+1}" ${pagina>=this.totalPags?'disabled':''}>›</button>`;
    botonesEl.innerHTML = html;

    botonesEl.querySelectorAll('[data-pag]').forEach(btn => {
      btn.addEventListener('click', () => !btn.disabled && this.cargar({ pagina: parseInt(btn.dataset.pag) }));
    });
  }
}

/* ════════════════════════════════════════════════
   FORMULARIO CON AJAX
   ════════════════════════════════════════════════ */
async function enviarFormulario(form, urlEndpoint, accion, { onExito, onError, formularioCSRF } = {}) {
  if (!Validador.validarFormulario(form)) return;

  const btnEnviar = form.querySelector('[type="submit"]');
  const textoOriginal = btnEnviar?.innerHTML ?? '';
  if (btnEnviar) {
    btnEnviar.disabled   = true;
    btnEnviar.innerHTML  = `<span class="spinner"></span> Procesando…`;
  }

  try {
    const datos   = Object.fromEntries(new FormData(form));
    const csrf    = await API.obtenerCSRF(formularioCSRF ?? accion);
    datos.accion      = accion;
    datos.csrf_token  = csrf;

    const resultado = await API.post(urlEndpoint, datos);

    if (resultado.exito) {
      if (onExito) onExito(resultado);
      else {
        Toast.mostrar('exito', 'Operación exitosa', resultado.mensaje);
        if (resultado.datos?.redirigir) {
          setTimeout(() => window.location.href = resultado.datos.redirigir, 800);
        }
      }
    } else {
      if (resultado.datos?.errores) {
        Validador.mostrarErroresServidor(form, resultado.datos.errores);
      }
      if (onError) onError(resultado);
      else Toast.mostrar('error', 'Error', resultado.mensaje);
    }
  } catch (err) {
    console.error('[FormAjax]', err);
  } finally {
    if (btnEnviar) {
      btnEnviar.disabled  = false;
      btnEnviar.innerHTML = textoOriginal;
    }
  }
}

/* ════════════════════════════════════════════════
   INICIALIZACIÓN GLOBAL
   ════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', () => {
  // Validación en tiempo real (blur)
  document.querySelectorAll('[data-validar]').forEach((campo) => {
    campo.addEventListener('blur', () => Validador.validarCampo(campo));
    campo.addEventListener('input', () => {
      if (campo.classList.contains('campo__control--error')) {
        Validador.validarCampo(campo);
      }
    });
  });

  // Fuerza de contraseña automática
  document.querySelectorAll('[data-fuerza-contrasena]').forEach((campo) => {
    FuerzaContrasena.enlazar(campo.id, campo.dataset.fuerzaContrasena);
  });

  // Botones de cerrar modal
  document.querySelectorAll('[data-cerrar-modal]').forEach((btn) => {
    btn.addEventListener('click', () => Modal.cerrar(btn.dataset.cerrarModal));
  });

  // Cerrar modal al clickear overlay
  document.querySelectorAll('.modal-overlay[id]').forEach((overlay) => {
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) Modal.cerrar(overlay.id);
    });
  });

  // Sidebar móvil
  const btnMenu    = document.querySelector('.btn-menu-movil');
  const sidebar    = document.querySelector('.sidebar');
  const sidebarOverlay = document.querySelector('.sidebar-overlay');

  if (btnMenu && sidebar) {
    btnMenu.addEventListener('click', () => {
      sidebar.classList.toggle('sidebar--abierto');
      sidebarOverlay?.classList.toggle('sidebar-overlay--visible');
      btnMenu.setAttribute('aria-expanded',
        sidebar.classList.contains('sidebar--abierto').toString()
      );
    });
    sidebarOverlay?.addEventListener('click', () => {
      sidebar.classList.remove('sidebar--abierto');
      sidebarOverlay.classList.remove('sidebar-overlay--visible');
    });
  }

  // Cerrar sesión
  document.querySelectorAll('[data-accion-cerrar-sesion]').forEach((btn) => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      Modal.confirmar({
        titulo:           'Cerrar sesión',
        mensaje:          '¿Está seguro de que desea cerrar la sesión?',
        textoConfirmar:   'Cerrar sesión',
        textoCancelar:    'Cancelar',
        tipo:             'secundario',
      }).then((confirmado) => { if (confirmado) Sesion.cerrar(); });
    });
  });
});

// Exportar para módulos ESM (opcional)
if (typeof module !== 'undefined' && module.exports) {
  module.exports = { API, Toast, Validador, FuerzaContrasena, Modal, Sesion, TablaAdmin, enviarFormulario };
}
