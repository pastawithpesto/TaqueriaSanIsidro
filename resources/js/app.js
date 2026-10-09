// Barra de actividad: aparece si una petición al servidor tarda más de 200 ms.
document.addEventListener('livewire:init', () => {
    let pending = 0;
    let timer = null;
    const bar = () => document.getElementById('busy');

    window.Livewire.interceptRequest(({ onSend, onFinish }) => {
        onSend(() => {
            pending++;
            clearTimeout(timer);
            timer = setTimeout(() => bar()?.classList.add('is-busy'), 200);
        });
        onFinish(() => {
            pending = Math.max(0, pending - 1);
            if (pending === 0) {
                clearTimeout(timer);
                bar()?.classList.remove('is-busy');
            }
        });
    });
});

// Animación corta de "rebote" que respeta reducir movimiento.
window.bump = (el) => {
    if (!el || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    el.animate(
        [{ transform: 'scale(1)' }, { transform: 'scale(1.22)' }, { transform: 'scale(1)' }],
        { duration: 220, easing: 'cubic-bezier(.3,.7,.4,1.4)' },
    );
};
