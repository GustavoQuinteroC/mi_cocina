/**
 * Mi Cocina - Service Worker (PWA).
 * Estrategia:
 *  - Assets estáticos (JS/CSS/imágenes): network-first con fallback a caché. Así
 *    siempre están frescos en línea; la caché solo se usa sin conexión. Además
 *    app.js/app.css llevan "?v=filemtime", de modo que cada cambio genera URL nueva.
 *  - Documentos HTML (páginas privadas de la familia): NUNCA se cachean, para no
 *    dejar datos privados ni tokens CSRF viejos accesibles sin conexión en un
 *    dispositivo compartido. Offline se muestra la página de "Sin conexión".
 *  - Nunca se cachean peticiones POST ni el endpoint AJAX.
 *  - Al cerrar sesión, la app manda 'limpiar-cache' para vaciar esta caché.
 */

const CACHE = 'mi-cocina-v4';
const ASSETS_ESTATICOS = [
    '/assets/iconos/icon.svg',
    '/manifest.json',
];

self.addEventListener('install', (evento) => {
    evento.waitUntil(
        caches.open(CACHE).then((cache) => cache.addAll(ASSETS_ESTATICOS)).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (evento) => {
    evento.waitUntil(
        caches.keys()
            .then((llaves) => Promise.all(llaves.filter((llave) => llave !== CACHE).map((llave) => caches.delete(llave))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (evento) => {
    const peticion = evento.request;
    const url = new URL(peticion.url);

    // Solo GET del mismo origen; lo demás va directo a la red.
    if (peticion.method !== 'GET' || url.origin !== location.origin || url.pathname.startsWith('/controladores/')) {
        return;
    }

    // ¿Es una página HTML (navegación)? Esas no se cachean (datos privados + CSRF).
    const esDocumento = peticion.mode === 'navigate'
        || (peticion.headers.get('accept') || '').includes('text/html');

    evento.respondWith(
        fetch(peticion)
            .then((respuesta) => {
                // Solo se cachean ASSETS estáticos, nunca documentos con datos privados.
                if (respuesta.ok && !esDocumento) {
                    const copia = respuesta.clone();
                    caches.open(CACHE).then((cache) => cache.put(peticion, copia));
                }
                return respuesta;
            })
            .catch(() =>
                caches.match(peticion).then((cacheado) => {
                    if (cacheado) return cacheado;
                    if (esDocumento) {
                        return new Response(
                            '<!DOCTYPE html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' +
                            '<body style="font-family:sans-serif;display:grid;place-items:center;min-height:100vh;background:#fdf9f3;color:#57534e;text-align:center">' +
                            '<div><div style="font-size:3rem">🍳</div><h1>Sin conexión</h1><p>Mi Cocina necesita internet para esta página.</p>' +
                            '<p style="font-size:.75rem;color:#a8a29e;margin-top:1.5rem">Powered by <b style="color:#a94a26">Alkes</b></p></div></body></html>',
                            { headers: { 'Content-Type': 'text/html; charset=utf-8' } }
                        );
                    }
                    return Response.error();
                })
            )
    );
});

// Al cerrar sesión la app manda 'limpiar-cache': se vacía la caché por privacidad.
self.addEventListener('message', (evento) => {
    if (evento.data === 'limpiar-cache') {
        evento.waitUntil(caches.delete(CACHE));
    }
});
