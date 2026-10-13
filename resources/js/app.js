import './tours';

const toast = (message, type = 'success') =>
    window.dispatchEvent(new CustomEvent('toast', { detail: { message, type } }));

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
            return new Promise((resolve) => (this.resolver = resolve));
        },
        answer(value) {
            this.open = false;
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

// Rebote corto del contador de un producto; respeta "reducir movimiento".
window.bump = (el) => {
    if (!el || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    el.animate(
        [{ transform: 'scale(1)' }, { transform: 'scale(1.22)' }, { transform: 'scale(1)' }],
        { duration: 220, easing: 'cubic-bezier(.3,.7,.4,1.4)' },
    );
};
