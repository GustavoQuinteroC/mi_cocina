/**
 * Mi Cocina - Service Worker (PWA).
 * Estrategia:
 *  - TODO (assets y páginas): network-first con fallback a caché. Así el
 *    JS/CSS siempre está fresco estando en línea (nunca se sirve una versión
 *    vieja); la caché solo se usa sin conexión. Además app.js/app.css llevan
 *    "?v=filemtime", de modo que cada cambio genera una URL nueva.
 *  - Nunca se cachean peticiones POST ni el endpoint AJAX.
 */

const CACHE = 'mi-cocina-v3';
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

    // Todo (assets y páginas): network-first con fallback a caché y aviso offline.
    evento.respondWith(
        fetch(peticion)
            .then((respuesta) => {
                if (respuesta.ok) caches.open(CACHE).then((cache) => cache.put(peticion, respuesta.clone()));
                return respuesta;
            })
            .catch(() =>
                caches.match(peticion).then((cacheado) =>
                    cacheado || new Response(
                        '<!DOCTYPE html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' +
                        '<body style="font-family:sans-serif;display:grid;place-items:center;min-height:100vh;background:#fdf9f3;color:#57534e;text-align:center">' +
                        '<div><div style="font-size:3rem">🍳</div><h1>Sin conexión</h1><p>Mi Cocina necesita internet para esta página.</p></div></body></html>',
                        { headers: { 'Content-Type': 'text/html; charset=utf-8' } }
                    )
                )
            )
    );
});
