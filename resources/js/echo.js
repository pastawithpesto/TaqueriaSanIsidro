import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.realtime = { connected: false };

const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.content;
const isAdmin = () => document.body?.hasAttribute('data-realtime-admin');
const setState = (connected) => {
    if (window.realtime.connected === connected) return;
    window.realtime.connected = connected;
    window.dispatchEvent(new CustomEvent('realtime:state', { detail: connected ? 'connected' : 'disconnected' }));
};

let adminChannel = null;
let audio = null;
let changedTimer = null;

/*
 * Respaldo del tiempo real para las pantallas que se vuelven a leer solas (Pedidos, cocina, seguimiento).
 * Con el websocket caído consulta cada `seconds`; conectado, cada minuto por si se perdió un aviso.
 * Al reconectar se lee de inmediato lo que cambió mientras tanto. Devuelve la función para detenerlo.
 */
window.realtimeFallback = (refresh, seconds) => {
    let last = Date.now();
    const run = () => { last = Date.now(); refresh(); };
    const timer = setInterval(() => {
        if (document.visibilityState !== 'visible') return;
        if (Date.now() - last >= (window.realtime.connected ? 60 : seconds) * 1000) run();
    }, 5000);
    const onState = (event) => event.detail === 'connected' && run();
    const onChanged = () => { last = Date.now(); };
    window.addEventListener('realtime:state', onState);
    window.addEventListener('orders-changed', onChanged);

    return () => {
        clearInterval(timer);
        window.removeEventListener('realtime:state', onState);
        window.removeEventListener('orders-changed', onChanged);
    };
};

// Igual que en cocina: el audio se habilita únicamente con un gesto real del usuario.
const unlockAudio = (event) => {
    if (! event.isTrusted || ! isAdmin() || document.body.hasAttribute('data-realtime-kitchen')) return;
    try {
        const Audio = window.AudioContext ?? window.webkitAudioContext;
        if (! Audio) return;
        audio ??= new Audio();
        audio.resume().catch(() => {});
    } catch {}
};
window.addEventListener('pointerdown', unlockAudio);
window.addEventListener('keydown', unlockAudio);

const beep = () => {
    try {
        // Cocina ya tiene su contexto, botón de silencio y tono; se reutilizan sin cambiar la página.
        if (document.body.hasAttribute('data-realtime-kitchen')) {
            const kitchen = document.body.querySelector('[x-data]');
            if (kitchen) window.Alpine?.$data(kitchen).beep?.();
            return;
        }
        if (! audio || audio.state !== 'running' || localStorage.getItem('cocina:sonido') === 'no') return;
        [0, 0.28].forEach((at) => {
            const start = audio.currentTime + at;
            const tone = audio.createOscillator();
            const volume = audio.createGain();
            tone.frequency.value = 880;
            volume.gain.setValueAtTime(0.3, start);
            volume.gain.exponentialRampToValueAtTime(0.001, start + 0.22);
            tone.connect(volume).connect(audio.destination);
            tone.start(start);
            tone.stop(start + 0.22);
        });
    } catch {}
};

// Al resuscribirse se recupera el contador, incluidos los pedidos recibidos durante una caída.
// Usa la misma ruta que refreshPending en app.js y su evento para actualizar Alpine.
const refreshAdminPending = () => {
    const url = document.body?.dataset.pendingUrl;
    if (! isAdmin() || ! url) return;
    fetch(url, { headers: { Accept: 'application/json' } })
        .then((response) => response.ok ? response.json() : null)
        .then((data) => {
            if (data && isAdmin() && document.body.dataset.pendingUrl === url) {
                window.dispatchEvent(new CustomEvent('pending-count', { detail: { count: data.count } }));
            }
        })
        .catch(() => {});
};

const syncAdminChannel = () => {
    if (! window.Echo) return;
    if (! isAdmin()) {
        if (adminChannel) window.Echo.leave('admin-orders');
        adminChannel = null;
        return;
    }
    if (adminChannel) return;
    adminChannel = window.Echo.private('admin-orders')
        .subscribed(refreshAdminPending)
        .listen('.placed', (detail) => {
            if (! isAdmin()) return;
            if (window.Alpine) window.Alpine.store('pending', Number(window.Alpine.store('pending') ?? 0) + 1);
            beep();
            window.dispatchEvent(new CustomEvent('online-order', { detail }));
            window.dispatchEvent(new CustomEvent('toast', {
                detail: { message: `Pedido en línea nuevo de ${detail.name || 'Cliente'}`, type: 'success' },
            }));
        })
        // Un pedido cambió (Telegram, caja u otra pantalla): Pedidos y cocina se vuelven a leer y el contador se corrige.
        // Guardar o cobrar un pedido manda varios avisos seguidos: se juntan en una sola lectura.
        .listen('.changed', () => {
            if (! isAdmin()) return;
            clearTimeout(changedTimer);
            changedTimer = setTimeout(() => {
                window.dispatchEvent(new CustomEvent('orders-changed'));
                refreshAdminPending();
            }, 300);
        });
};

const start = () => {
    const key = meta('reverb-key');
    if (! window.Echo && key) {
        const forceTLS = window.location.protocol === 'https:';
        const port = Number(window.location.port || (forceTLS ? 443 : 80));
        window.Echo = new Echo({
            broadcaster: 'reverb',
            Pusher,
            key,
            wsHost: window.location.hostname,
            wsPort: port,
            wssPort: port,
            forceTLS,
            enabledTransports: ['ws', 'wss'],
            authEndpoint: meta('reverb-auth-endpoint'),
        });
        window.Echo.connector.pusher.connection.bind('state_change', ({ current }) => setState(current === 'connected'));
        setState(window.Echo.connector.pusher.connection.state === 'connected');
    }
    syncAdminChannel();
};

// Una sola instancia y escucha aunque wire:navigate cambie entre los layouts público e interno.
document.addEventListener('livewire:navigated', start);
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
else start();
