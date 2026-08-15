/**
 * Mi Cocina - JS de la aplicación (Alpine.js + fetch).
 * Todas las peticiones AJAX van al endpoint central /controladores/ajax.php
 * con el token CSRF de la <meta>.
 */

const URL_AJAX = '/controladores/ajax.php';

// ---------- Utilidades ----------

function tokenCsrf() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.content : '';
}

/** POST JSON al endpoint AJAX. Devuelve el JSON de respuesta; lanza si falla. */
async function peticionAjax(accion, datos = {}) {
    const respuesta = await fetch(URL_AJAX, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-Token': tokenCsrf(),
            'X-Requested-With': 'fetch',
        },
        body: JSON.stringify({ accion, ...datos }),
    });
    // Sesión caducada o revocada: en vez de dejar la app "muerta", al login.
    if (respuesta.status === 401) {
        window.location.href = '/index.php';
        throw new Error('Tu sesión expiró. Vuelve a entrar.');
    }
    const json = await respuesta.json().catch(() => ({}));
    if (!respuesta.ok) {
        const detalle = Array.isArray(json.details) ? ' ' + json.details.join(' ') : '';
        throw new Error((json.error || 'Error inesperado.') + detalle);
    }
    return json;
}

/**
 * POST multipart (FormData) al endpoint AJAX; lo usa el dictado por micrófono
 * para subir el audio. El navegador pone solo el Content-Type con su boundary.
 */
async function peticionAjaxArchivo(formData) {
    const respuesta = await fetch(URL_AJAX, {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'X-CSRF-Token': tokenCsrf(),
            'X-Requested-With': 'fetch',
        },
        body: formData,
    });
    if (respuesta.status === 401) {
        window.location.href = '/index.php';
        throw new Error('Tu sesión expiró. Vuelve a entrar.');
    }
    const json = await respuesta.json().catch(() => ({}));
    if (!respuesta.ok) throw new Error(json.error || 'Error inesperado.');
    return json;
}

/** Aviso flotante (mismo estilo que los flashes del servidor). */
function avisar(mensaje, tipo = 'success') {
    const caja = document.getElementById('avisos');
    if (!caja) return alert(mensaje);
    const elemento = document.createElement('div');
    elemento.className = 'aviso flex items-start gap-2 px-4 py-3 rounded-2xl shadow-lg text-sm font-semibold animate-aviso ' +
        (tipo === 'error' ? 'bg-red-600 text-white' : 'bg-oliva-600 text-white');
    elemento.innerHTML = `<span>${tipo === 'error' ? '⚠️' : '✅'}</span><span></span>`;
    elemento.querySelector('span:last-child').textContent = mensaje;
    caja.appendChild(elemento);
    setTimeout(() => elemento.remove(), 4000);
}

/** Alterna el modo oscuro (persistido). */
function cambiarTema() {
    const oscuro = document.documentElement.classList.toggle('dark');
    localStorage.tema = oscuro ? 'oscuro' : 'claro';
}

/** Botón "Sorpréndeme con IA" del inicio. */
async function sugerirConIA(tono) {
    avisar('✨ La IA está buscando una receta real… (puede tardar unos segundos)');
    try {
        const json = await peticionAjax('ia_sugerir', { tono });
        avisar(json.message);
        setTimeout(() => { window.location.href = '/vistas/platillos/ver.php?id=' + json.id_platillo; }, 900);
    } catch (error) {
        avisar(error.message, 'error');
    }
}

// Los avisos del servidor también se autodestruyen.
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('#avisos .aviso').forEach(el => setTimeout(() => el.remove(), 4000));

    // Al cerrar sesión, se le pide al service worker que borre su caché para no
    // dejar páginas privadas accesibles sin conexión en dispositivos compartidos.
    document.querySelectorAll('form input[name="accion"][value="salir"]').forEach(input => {
        input.form.addEventListener('submit', () => {
            navigator.serviceWorker?.controller?.postMessage('limpiar-cache');
        });
    });
});

// ---------- Componentes Alpine ----------

document.addEventListener('alpine:init', () => {

    /** Tarjeta de platillo: favorito + "lo hice hoy". */
    Alpine.data('tarjetaPlatillo', (idPlatillo, favorito, cocinado) => ({
        idPlatillo, favorito, cocinado,
        async alternarFavorito() {
            try {
                const json = await peticionAjax('favorito', { id_platillo: this.idPlatillo });
                this.favorito = json.es_favorito;
            } catch (error) { avisar(error.message, 'error'); }
        },
        async marcarHecho() {
            try {
                const json = await peticionAjax('hecho', { id_platillo: this.idPlatillo });
                this.cocinado = true;
                avisar(json.message);
            } catch (error) { avisar(error.message, 'error'); }
        },
    }));

    /** Ficha de platillo: receta, IA y modo cocina. */
    Alpine.data('fichaPlatillo', () => ({
        idPlatillo: null,
        favorito: false,
        cocinado: false,
        receta: null,

        editando: false,
        borrador: { ingredientes: [], pasos: [], notas: '', url_fuente: '' },

        iaOcupada: false,
        ajusteAbierto: false,
        instruccionAjuste: '',
        propuesta: null,

        modoCocina: false,
        pasoActual: 0,
        bloqueoPantalla: null,
        pantallaEncendida: false,
        segundosTimer: 0,
        timerCorriendo: false,
        intervaloTimer: null,

        iniciar() {
            const configuracion = JSON.parse(document.getElementById('config-platillo').textContent);
            this.idPlatillo = configuracion.idPlatillo;
            this.favorito = configuracion.favorito;
            this.cocinado = configuracion.cocinado;
            this.receta = configuracion.receta;
        },

        formatearCantidad(n) {
            const numero = Number(n);
            return Number.isInteger(numero) ? String(numero) : String(numero).replace(/0+$/, '').replace(/\.$/, '');
        },

        /** Al elegir un ingrediente del catálogo, sugiere su unidad si está vacía. */
        autoUnidad(i) {
            const mapa = window.CATALOGO_UNIDADES || {};
            const nombre = (this.borrador.ingredientes[i].nombre || '').trim().toLowerCase();
            const unidad = mapa[nombre];
            if (unidad && !String(this.borrador.ingredientes[i].unidad || '').trim()) {
                this.borrador.ingredientes[i].unidad = unidad;
            }
        },

        async alternarFavorito() {
            try {
                const json = await peticionAjax('favorito', { id_platillo: this.idPlatillo });
                this.favorito = json.es_favorito;
            } catch (error) { avisar(error.message, 'error'); }
        },

        async marcarHecho() {
            try {
                const json = await peticionAjax('hecho', { id_platillo: this.idPlatillo });
                this.cocinado = true;
                avisar(json.message);
            } catch (error) { avisar(error.message, 'error'); }
        },

        // ---- Receta manual ----
        abrirEditor() {
            this.borrador = this.receta
                ? JSON.parse(JSON.stringify({
                    ingredientes: this.receta.ingredientes,
                    pasos: this.receta.pasos,
                    notas: this.receta.notas || '',
                    url_fuente: this.receta.url_fuente || '',
                }))
                : { ingredientes: [{ nombre: '', cantidad: 1, unidad: '' }], pasos: [''], notas: '', url_fuente: '' };
            this.editando = true;
        },

        async guardarReceta() {
            // Limpieza básica en cliente; la validación real la hace validarReceta() en el servidor.
            const datos = {
                id_platillo: this.idPlatillo,
                ingredientes: this.borrador.ingredientes.filter(i => i.nombre.trim() !== ''),
                pasos: this.borrador.pasos.filter(p => p.trim() !== ''),
                notas: this.borrador.notas || null,
                url_fuente: this.borrador.url_fuente || null,
                de_ia: false,
            };
            this.iaOcupada = true;
            try {
                const json = await peticionAjax('guardar_receta', datos);
                avisar(json.message);
                setTimeout(() => location.reload(), 700);
            } catch (error) {
                avisar(error.message, 'error');
            } finally {
                this.iaOcupada = false;
            }
        },

        async verificarReceta() {
            try {
                const json = await peticionAjax('verificar_receta', { id_platillo: this.idPlatillo });
                this.receta.verificada = true;
                avisar(json.message);
            } catch (error) { avisar(error.message, 'error'); }
        },

        // ---- IA ----
        async generarConIA() {
            this.iaOcupada = true;
            try {
                const json = await peticionAjax('ia_receta', { id_platillo: this.idPlatillo });
                avisar(json.message);
                setTimeout(() => location.reload(), 900);
            } catch (error) {
                avisar(error.message, 'error');
            } finally {
                this.iaOcupada = false;
            }
        },

        async ajustarConIA() {
            if (this.instruccionAjuste.trim().length < 3) {
                return avisar('Describe el ajuste que quieres.', 'error');
            }
            this.iaOcupada = true;
            this.propuesta = null;
            try {
                const json = await peticionAjax('ia_ajustar', {
                    id_platillo: this.idPlatillo,
                    instruccion: this.instruccionAjuste.trim(),
                });
                this.propuesta = json.propuesta;
            } catch (error) {
                avisar(error.message, 'error');
            } finally {
                this.iaOcupada = false;
            }
        },

        async aplicarPropuesta() {
            if (!this.propuesta) return;
            this.iaOcupada = true;
            try {
                const json = await peticionAjax('guardar_receta', {
                    id_platillo: this.idPlatillo,
                    ...this.propuesta,
                    de_ia: true,
                });
                avisar(json.message);
                setTimeout(() => location.reload(), 700);
            } catch (error) {
                avisar(error.message, 'error');
            } finally {
                this.iaOcupada = false;
            }
        },

        // ---- Modo cocina ----
        async abrirModoCocina() {
            if (!this.receta || !this.receta.pasos || !this.receta.pasos.length) {
                return avisar('Esta receta no tiene pasos de preparación.', 'error');
            }
            this.pasoActual = 0;
            this.modoCocina = true;
            document.body.style.overflow = 'hidden';
            // Mantener la pantalla encendida (si el navegador lo permite).
            try {
                if ('wakeLock' in navigator) {
                    this.bloqueoPantalla = await navigator.wakeLock.request('screen');
                    this.pantallaEncendida = true;
                    this.bloqueoPantalla.addEventListener('release', () => { this.pantallaEncendida = false; });
                }
            } catch { /* sin wake lock no pasa nada */ }
        },

        cerrarModoCocina() {
            this.modoCocina = false;
            document.body.style.overflow = '';
            this.pausarTimer();
            this.segundosTimer = 0;
            if (this.bloqueoPantalla) { this.bloqueoPantalla.release().catch(() => {}); this.bloqueoPantalla = null; }
        },

        async terminarCocina() {
            await this.marcarHecho();
            this.cerrarModoCocina();
        },

        // ---- Temporizador ----
        formatearTimer() {
            const m = Math.floor(this.segundosTimer / 60), s = this.segundosTimer % 60;
            return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
        },
        fijarTimer(segundos) { this.pausarTimer(); this.segundosTimer = segundos; },
        iniciarTimer() {
            if (this.segundosTimer <= 0 || this.timerCorriendo) return;
            this.timerCorriendo = true;
            this.intervaloTimer = setInterval(() => {
                if (--this.segundosTimer <= 0) {
                    this.pausarTimer();
                    this.segundosTimer = 0;
                    avisar('⏰ ¡Tiempo! Revisa tu platillo.');
                    try { navigator.vibrate && navigator.vibrate([200, 100, 200]); } catch {}
                }
            }, 1000);
        },
        pausarTimer() {
            this.timerCorriendo = false;
            if (this.intervaloTimer) { clearInterval(this.intervaloTimer); this.intervaloTimer = null; }
        },
        reiniciarTimer() { this.pausarTimer(); this.segundosTimer = 0; },
    }));

    /** Planificador semanal: guarda cada celda al cambiar. */
    Alpine.data('planSemanal', () => ({
        async asignarCelda(fecha, tipoComida, valor) {
            try {
                await peticionAjax('plan_celda', {
                    fecha,
                    tipo_comida: tipoComida,
                    id_platillo: valor === '' ? null : parseInt(valor, 10),
                });
                avisar('Plan guardado.');
            } catch (error) {
                avisar(error.message, 'error');
            }
        },
    }));

    /** Editor de ingredientes + preparación del formulario de platillo. */
    Alpine.data('editorReceta', () => ({
        ingredientes: [],
        pasos: [],

        iniciar() {
            const nodo = document.getElementById('receta-inicial');
            const datos = nodo ? JSON.parse(nodo.textContent) : { ingredientes: [], pasos: [] };
            this.ingredientes = (datos.ingredientes && datos.ingredientes.length)
                ? datos.ingredientes.map(i => ({ nombre: i.nombre || '', cantidad: i.cantidad ?? '', unidad: i.unidad || '' }))
                : [{ nombre: '', cantidad: '', unidad: '' }];
            this.pasos = (datos.pasos && datos.pasos.length) ? [...datos.pasos] : [''];
        },

        /** Al elegir un ingrediente del catálogo, sugiere su unidad si está vacía. */
        autoUnidad(i) {
            const mapa = window.CATALOGO_UNIDADES || {};
            const nombre = (this.ingredientes[i].nombre || '').trim().toLowerCase();
            const unidad = mapa[nombre];
            if (unidad && !String(this.ingredientes[i].unidad || '').trim()) {
                this.ingredientes[i].unidad = unidad;
            }
        },

        agregarIngrediente() { this.ingredientes.push({ nombre: '', cantidad: '', unidad: '' }); },
        quitarIngrediente(i) {
            this.ingredientes.splice(i, 1);
            if (!this.ingredientes.length) this.ingredientes.push({ nombre: '', cantidad: '', unidad: '' });
        },
        agregarPaso() { this.pasos.push(''); },
        quitarPaso(i) {
            this.pasos.splice(i, 1);
            if (!this.pasos.length) this.pasos.push('');
        },
    }));

    /** Lista de compras: alta manual, palomeado, borrado y generar desde el plan. */
    Alpine.data('listaCompras', () => ({
        items: [],
        lunes: '',
        nuevo: { articulo: '', cantidad: '', unidad: '' },

        iniciar() {
            const nodo = document.getElementById('compras-datos');
            const datos = nodo ? JSON.parse(nodo.textContent) : { items: [], lunes: '' };
            this.items = datos.items;
            this.lunes = datos.lunes;
        },

        formatearCantidad(n) {
            const x = Number(n);
            return Number.isInteger(x) ? String(x) : String(x).replace(/0+$/, '').replace(/\.$/, '');
        },

        async agregar() {
            if (this.nuevo.articulo.trim().length < 2) {
                return avisar('Escribe qué comprar.', 'error');
            }
            try {
                const json = await peticionAjax('compra_agregar', {
                    articulo: this.nuevo.articulo.trim(),
                    cantidad: this.nuevo.cantidad || 1,
                    unidad: this.nuevo.unidad || '',
                });
                this.items.unshift(json.item);
                this.nuevo = { articulo: '', cantidad: '', unidad: '' };
            } catch (error) { avisar(error.message, 'error'); }
        },

        async alternar(item) {
            try {
                const json = await peticionAjax('compra_toggle', { id: item.id });
                item.comprado = json.comprado ? 1 : 0;
            } catch (error) { avisar(error.message, 'error'); }
        },

        async eliminar(item) {
            try {
                await peticionAjax('compra_eliminar', { id: item.id });
                this.items = this.items.filter(x => x.id !== item.id);
            } catch (error) { avisar(error.message, 'error'); }
        },

        async generarDesdePlan() {
            if (!confirm('Se reemplazan los artículos generados del plan de esta semana (los agregados a mano se conservan). ¿Continuar?')) return;
            try {
                const json = await peticionAjax('compra_generar', { lunes: this.lunes });
                avisar(json.message);
                setTimeout(() => location.reload(), 800);
            } catch (error) { avisar(error.message, 'error'); }
        },
    }));

    /**
     * Recomendador por despensa + antojo libre (inicio). Botón "Cocinar con lo
     * que tengo" (recomienda con la despensa, como en la sección Despensa) y
     * cuadro "¿Qué se te antoja?": la IA responde con una receta directa
     * (redirige al platillo creado) o con recomendaciones (mismas tarjetas).
     */
    Alpine.data('recomendadorDespensa', (iaActiva) => ({
        iaActiva,
        recomendaciones: [],
        cargandoReco: false,
        tituloReco: '',
        recoConDespensa: false, // solo con la despensa tiene sentido "✓ Tienes todo"
        antojo: '',
        cargandoAntojo: false,

        // --- Dictado por micrófono (transcrito con la IA) ---
        soportaMic: !!(navigator.mediaDevices && window.MediaRecorder),
        grabando: false,
        transcribiendo: false, // transcripción final en curso
        segundosDictado: 0,
        _grabadora: null,
        _trozos: [],
        _mimeDictado: '',
        _prefijoDictado: '', // lo que ya estaba escrito antes de dictar
        _intervaloDictado: null,
        _parcialEnVuelo: false,

        async recomendar() {
            if (!this.iaActiva) return avisar('Las funciones de IA no están configuradas (OPENAI_API_KEY).', 'error');
            this.cargandoReco = true;
            this.recomendaciones = [];
            this.tituloReco = 'Con lo que tienes en casa puedes hacer:';
            this.recoConDespensa = true;
            try {
                const json = await peticionAjax('ia_despensa');
                this.recomendaciones = (json.recomendaciones || []).map(r => ({ ...r, _creando: false }));
                if (this.recomendaciones.length === 0) avisar('La IA no encontró recomendaciones con lo que tienes.');
            } catch (error) {
                avisar(error.message, 'error');
            } finally {
                this.cargandoReco = false;
            }
        },

        async pedirAntojo() {
            if (!this.iaActiva) return avisar('Las funciones de IA no están configuradas (OPENAI_API_KEY).', 'error');
            const antojo = this.antojo.trim();
            if (antojo.length < 3) return avisar('Cuéntame qué se te antoja (mínimo 3 letras).', 'error');
            this.cargandoAntojo = true;
            let redirigiendo = false;
            try {
                const json = await peticionAjax('ia_antojo', { antojo });
                if (json.tipo === 'receta') {
                    redirigiendo = true; // el botón se queda en "Buscando…" hasta la redirección
                    avisar(json.message);
                    setTimeout(() => { window.location.href = '/vistas/platillos/ver.php?id=' + json.id_platillo; }, 900);
                    return;
                }
                this.tituloReco = 'Para tu antojo, la IA te recomienda:';
                this.recoConDespensa = false;
                this.recomendaciones = (json.recomendaciones || []).map(r => ({ ...r, _creando: false }));
                if (this.recomendaciones.length === 0) avisar('La IA no encontró nada para ese antojo. Intenta describirlo de otra forma.');
            } catch (error) {
                avisar(error.message, 'error');
            } finally {
                if (!redirigiendo) this.cargandoAntojo = false;
            }
        },

        /** Botón 🎤: inicia el dictado o lo detiene (y transcribe lo final). */
        async alternarDictado() {
            if (!this.iaActiva) return avisar('Las funciones de IA no están configuradas (OPENAI_API_KEY).', 'error');
            if (this.grabando) { this._grabadora?.stop(); return; }

            if (!window.isSecureContext) {
                return avisar('El micrófono solo funciona con HTTPS. Abre la app desde su dirección segura.', 'error');
            }
            let stream;
            try {
                stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            } catch (error) {
                console.error('Dictado: getUserMedia falló:', error.name, error.message);
                const mensajes = {
                    NotAllowedError: 'El micrófono está bloqueado para este sitio. Tócale al candado de la barra de direcciones y permite el micrófono.',
                    NotFoundError: 'No se encontró ningún micrófono en este dispositivo.',
                    NotReadableError: 'Otra aplicación está usando el micrófono. Ciérrala e intenta de nuevo.',
                    SecurityError: 'El navegador bloqueó el micrófono por seguridad (¿estás en HTTPS?).',
                };
                return avisar(mensajes[error.name] || 'No se pudo usar el micrófono: ' + error.message, 'error');
            }

            const mime = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg;codecs=opus']
                .find(m => MediaRecorder.isTypeSupported(m)) || '';
            const grabadora = new MediaRecorder(stream, mime ? { mimeType: mime } : undefined);

            this._grabadora = grabadora;
            this._trozos = [];
            this._mimeDictado = grabadora.mimeType || mime || 'audio/webm';
            this._prefijoDictado = this.antojo.trim() ? this.antojo.trim() + ' ' : '';
            this.segundosDictado = 0;
            this.grabando = true;

            grabadora.ondataavailable = e => { if (e.data && e.data.size) this._trozos.push(e.data); };
            grabadora.onstop = () => {
                stream.getTracks().forEach(t => t.stop());
                clearInterval(this._intervaloDictado);
                this.grabando = false;
                this._transcribir(true);
            };
            // Trozos de 1 s: el blob acumulado desde el inicio siempre es un archivo válido.
            grabadora.start(1000);

            // Contador cada segundo; cada 5 s una transcripción parcial para que el
            // texto vaya apareciendo mientras se habla. Tope de 60 s por dictado.
            this._intervaloDictado = setInterval(() => {
                this.segundosDictado++;
                if (this.segundosDictado >= 60) { grabadora.stop(); return; }
                if (this.segundosDictado % 5 === 0) this._transcribir(false);
            }, 1000);
        },

        /** Manda el audio acumulado a la IA. Las parciales fallan en silencio. */
        async _transcribir(esFinal) {
            if (this._trozos.length === 0) {
                if (esFinal) avisar('No se alcanzó a grabar audio. Intenta de nuevo.', 'error');
                return;
            }
            if (!esFinal && this._parcialEnVuelo) return; // no encimar parciales
            this._parcialEnVuelo = !esFinal;
            if (esFinal) this.transcribiendo = true;

            const extension = this._mimeDictado.includes('mp4') ? 'mp4'
                : this._mimeDictado.includes('ogg') ? 'ogg' : 'webm';
            const datos = new FormData();
            datos.append('accion', 'ia_transcribir');
            datos.append('audio', new Blob(this._trozos, { type: this._mimeDictado }), 'dictado.' + extension);

            try {
                const json = await peticionAjaxArchivo(datos);
                // Una parcial que llega después de detener ya no debe pisar el texto final.
                if (!esFinal && !this.grabando) return;
                this.antojo = (this._prefijoDictado + json.texto).slice(0, 400); // tope del backend
            } catch (error) {
                if (esFinal) avisar(error.message, 'error');
            } finally {
                this._parcialEnVuelo = false;
                if (esFinal) this.transcribiendo = false;
            }
        },

        async crearPlatillo(reco) {
            reco._creando = true;
            try {
                const json = await peticionAjax('ia_crear_platillo', { nombre: reco.nombre, tono: reco.tono, tipo_comida: reco.tipo_comida });
                avisar(json.message);
                setTimeout(() => { window.location.href = '/vistas/platillos/ver.php?id=' + json.id_platillo; }, 800);
            } catch (error) {
                avisar(error.message, 'error');
                reco._creando = false;
            }
        },
    }));

    /** Despensa: tabla de todo el catálogo con "Tengo"/"Falta", buscador e IA. */
    Alpine.data('despensa', () => ({
        items: [],
        iaActiva: false,
        busqueda: '',
        recomendaciones: [],
        cargandoReco: false,
        guardandoCatalogo: false,

        iniciar() {
            const nodo = document.getElementById('despensa-datos');
            const datos = nodo ? JSON.parse(nodo.textContent) : { items: [], ia: false };
            this.items = datos.items;
            this.iaActiva = datos.ia;
        },

        normaliza(s) {
            // Quita acentos (marcas combinantes U+0300–U+036F) para buscar sin distinguir tildes.
            return (s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        },

        // Lista filtrada por el buscador y ordenada: lo que tengo arriba, luego lo que falta.
        get filtrados() {
            const q = this.normaliza(this.busqueda.trim());
            let lista = this.items;
            if (q) {
                lista = lista.filter(i => this.normaliza(i.nombre).includes(q) || this.normaliza(i.categoria).includes(q));
            }
            return [...lista].sort((a, b) =>
                (b.tengo - a.tengo) || (b.hace_falta - a.hace_falta) || a.nombre.localeCompare(b.nombre, 'es'));
        },

        get tengoCount() { return this.items.filter(i => i.tengo).length; },
        get faltaCount() { return this.items.filter(i => i.hace_falta).length; },
        get sinResultados() { return this.busqueda.trim().length >= 2 && this.filtrados.length === 0; },

        async fijar(item, campo) {
            const valor = campo === 'tengo' ? !item.tengo : !item.hace_falta;
            try {
                const json = await peticionAjax('despensa_set', { nombre: item.nombre, campo, valor: valor ? 1 : 0 });
                item.tengo = json.tengo;
                item.hace_falta = json.hace_falta;
            } catch (error) { avisar(error.message, 'error'); }
        },

        async aCompras() {
            try {
                const json = await peticionAjax('despensa_a_compras');
                avisar(json.message);
            } catch (error) { avisar(error.message, 'error'); }
        },

        async recomendar() {
            if (!this.iaActiva) return avisar('Las funciones de IA no están configuradas (OPENAI_API_KEY).', 'error');
            this.cargandoReco = true;
            this.recomendaciones = [];
            try {
                const json = await peticionAjax('ia_despensa');
                this.recomendaciones = (json.recomendaciones || []).map(r => ({ ...r, _creando: false }));
                if (this.recomendaciones.length === 0) avisar('La IA no encontró recomendaciones con lo que tienes.');
            } catch (error) {
                avisar(error.message, 'error');
            } finally {
                this.cargandoReco = false;
            }
        },

        async crearPlatillo(reco) {
            reco._creando = true;
            try {
                const json = await peticionAjax('ia_crear_platillo', { nombre: reco.nombre, tono: reco.tono, tipo_comida: reco.tipo_comida });
                avisar(json.message);
                setTimeout(() => { window.location.href = '/vistas/platillos/ver.php?id=' + json.id_platillo; }, 800);
            } catch (error) {
                avisar(error.message, 'error');
                reco._creando = false;
            }
        },

        async agregarAlCatalogo(nombre) {
            nombre = (nombre || '').trim();
            if (nombre.length < 2) return avisar('Escribe el ingrediente.', 'error');
            this.guardandoCatalogo = true;
            try {
                const json = await peticionAjax('ingrediente_agregar', { nombre });
                avisar(json.message);
                if (json.item && !this.items.some(i => this.normaliza(i.nombre) === this.normaliza(json.item.nombre))) {
                    this.items.push({ nombre: json.item.nombre, categoria: json.item.categoria, tengo: 0, hace_falta: 0 });
                }
                // Deja el buscador en el nombre corregido para que salte hasta arriba.
                if (json.item) this.busqueda = json.item.nombre;
            } catch (error) {
                avisar(error.message, 'error');
            } finally {
                this.guardandoCatalogo = false;
            }
        },
    }));
});

// ---------- PWA ----------
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/service-worker.js').catch(() => {});
    });
}
