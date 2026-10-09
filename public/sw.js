// Service worker mínimo: permite instalar la app y muestra un aviso sin conexión.
// No guarda páginas en caché: los datos de ventas siempre vienen del servidor.
const OFFLINE = new Response(
    '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' +
    '<title>Sin conexión</title><body style="font-family:system-ui;display:grid;place-items:center;min-height:100vh;margin:0;text-align:center;padding:24px">' +
    '<div><h1>Sin conexión</h1><p>Revisa tu internet y vuelve a intentar.</p>' +
    '<button onclick="location.reload()" style="font:inherit;padding:12px 20px;border-radius:12px;border:0;background:#c62a1d;color:#fff">Reintentar</button></div>',
    { headers: { 'Content-Type': 'text/html; charset=utf-8' } },
);

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));

self.addEventListener('fetch', (event) => {
    if (event.request.mode === 'navigate') {
        event.respondWith(fetch(event.request).catch(() => OFFLINE.clone()));
    }
});
