import './tours';

const toast = (message, type = 'success') =>
    window.dispatchEvent(new CustomEvent('toast', { detail: { message, type } }));

/*
 * El botón atrás del celular cierra el cuadro abierto en lugar de salir de la pantalla.
 * Cada cuadro que se abre agrega una entrada al historial con la misma dirección; si se cierra
 * con su propio botón, se regresa esa entrada para no dejar "atrás" de sobra.
 * Escucha antes que Livewire (captura) para que wire:navigate no cambie de página.
 */
const overlays = [];
const pendingBacks = [];

window.backButton = {
    opened(key, close) {
        if (overlays.some((o) => o.key === key)) return;
        overlays.push({ key, close });
        history.pushState({ ...history.state, overlay: true }, '');
    },
    // La promesa se cumple cuando el historial ya regresó: sirve para navegar justo después.
    closed(key) {
        const i = overlays.findIndex((o) => o.key === key);
        if (i === -1 || !history.state?.overlay) {
            if (i !== -1) overlays.splice(i, 1);
            return Promise.resolve();
        }
        overlays.splice(i, 1);
        // Livewire pudo cambiar la dirección mientras el cuadro estaba abierto (?orden=): se conserva la más reciente.
        const url = location.href;
        return new Promise((resolve) => {
            pendingBacks.push(() => {
                history.replaceState(history.state, '', url);
                resolve();
            });
            history.back();
        });
    },
};

window.addEventListener(
    'popstate',
    (event) => {
        if (pendingBacks.length) {
            event.stopImmediatePropagation();
            pendingBacks.shift()();
            return;
        }
        const top = overlays.pop();
        if (top) {
            event.stopImmediatePropagation();
            top.close();
        }
    },
    { capture: true },
);

// Al cambiar de pantalla los cuadros de la anterior desaparecen.
document.addEventListener('livewire:navigating', () => (overlays.length = 0));

/*
 * Contador de Pedidos del menú. Lo pinta el servidor al cargar la página; cambia al momento con
 * lo que se hace aquí (evento pending-count) y cada minuto se consulta por lo vendido en otro equipo.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.store('pending', Number(document.body.dataset.pending ?? 0));
});
document.addEventListener('livewire:navigated', () => {
    if (document.body.dataset.pending !== undefined) window.Alpine.store('pending', Number(document.body.dataset.pending));
});
window.addEventListener('pending-count', (event) => window.Alpine.store('pending', event.detail.count));

const refreshPending = () => {
    if (document.visibilityState !== 'visible' || document.body.dataset.pending === undefined) return;
    fetch('/pedidos/pendientes', { headers: { Accept: 'application/json' } })
        .then((response) => (response.ok ? response.json() : null))
        .then((data) => data && window.Alpine.store('pending', data.count))
        .catch(() => {});
};
setInterval(refreshPending, 60000);
document.addEventListener('visibilitychange', refreshPending);

// Diálogo de confirmación propio (en lugar del confirm() del navegador).
// Uso en Alpine: @click="if (await $store.confirm.ask({ title: '...', action: 'Borrar' })) $wire.delete(1)"
document.addEventListener('alpine:init', () => {
    window.Alpine.store('confirm', {
        open: false,
        title: '',
        message: '',
        action: 'Confirmar',
        cancel: 'Cancelar',
        danger: true,
        resolver: null,
        ask(options) {
            this.resolver?.(false);
            Object.assign(this, { title: '', message: '', action: 'Confirmar', cancel: 'Cancelar', danger: true }, options, { open: true });
            window.backButton.opened('confirm', () => this.answer(false));
            return new Promise((resolve) => (this.resolver = resolve));
        },
        answer(value) {
            this.open = false;
            window.backButton.closed('confirm');
            this.resolver?.(value);
            this.resolver = null;
        },
    });
});

document.addEventListener('livewire:init', () => {
    let pending = 0;
    let timer = null;
    const bar = () => document.getElementById('busy');

    window.Livewire.interceptRequest(({ onSend, onFinish, onFailure, onError }) => {
        onSend(() => {
            pending++;
            clearTimeout(timer);
            // Solo aparece si la petición tarda: en local casi nunca se ve.
            timer = setTimeout(() => bar()?.classList.add('is-busy'), 200);
        });
        onFinish(() => {
            pending = Math.max(0, pending - 1);
            if (pending === 0) {
                clearTimeout(timer);
                bar()?.classList.remove('is-busy');
            }
        });
        // Sin conexión: avisar en lugar de dejar la pantalla congelada.
        onFailure(() => toast('Sin conexión. Revisa tu internet e intenta de nuevo.', 'error'));
        onError(({ response, preventDefault }) => {
            preventDefault();
            if (response.status === 419) {
                // La sesión expiró (por ejemplo, el celular estuvo dormido mucho tiempo).
                window.location.reload();
            } else {
                toast('Algo salió mal. Intenta de nuevo.', 'error');
            }
        });
    });

    // Al guardar con un error de validación, lleva la vista al primer mensaje: en un cuadro largo
    // puede quedar arriba, fuera de la pantalla, y parece que el botón no hizo nada.
    window.Livewire.interceptMessage(({ message, onSuccess }) => {
        const byUser = message.getActions().some((action) => !action.name.startsWith('$') && action.metadata.type !== 'poll');
        if (!byUser) return;

        onSuccess(({ onMorphed }) => onMorphed(() => {
            const error = [...message.component.el.querySelectorAll('.error')].find((el) => el.getClientRects().length);
            error?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }));
    });
});

// Al corregir un campo se quita su mensaje de error; si sigue mal, el servidor lo vuelve a pintar al guardar.
['input', 'change'].forEach((type) =>
    document.addEventListener(type, (event) => {
        const field = event.target.closest?.('div:has(> .error:not([x-show]))');
        field?.querySelectorAll(':scope > .error:not([x-show])').forEach((error) => (error.hidden = true));
    }),
);

// Rebote corto del contador de un producto; respeta "reducir movimiento".
window.bump = (el) => {
    if (!el || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    el.animate(
        [{ transform: 'scale(1)' }, { transform: 'scale(1.22)' }, { transform: 'scale(1)' }],
        { duration: 220, easing: 'cubic-bezier(.3,.7,.4,1.4)' },
    );
};
