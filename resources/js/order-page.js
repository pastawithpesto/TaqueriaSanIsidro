/* Página pública para pedir: carrito, personalización de productos y envío del pedido (resources/views/pages/pedir.blade.php). */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('orderPage', (catalog, store) => ({
        catalog,
        store,
        lines: [],
        editor: null,
        editorError: '',
        cart: false,
        sending: false,
        errors: {},
        form: { token: '', name: '', phone: '', service: 'togo', address: '', references: '', notes: '', website: '' },

        init() {
            try {
                let saved = JSON.parse(localStorage.getItem('pedir:borrador') || 'null');
                // El borrador guarda nombre y teléfono: a los 30 días se descarta.
                if (saved && Date.now() - (saved.savedAt || 0) > 30 * 86400000) saved = null;
                if (saved) {
                    this.form = { ...this.form, ...saved.form, website: '' };
                    // Solo se recuperan líneas de productos que siguen en el menú.
                    this.lines = (saved.lines || [])
                        .map((l) => ({ ...l, product: this.catalog.find((p) => p.id === l.product_id) }))
                        .filter((l) => l.product && !l.product.soldOut);
                }
            } catch (e) {}
            if (!this.form.token) this.form.token = crypto.randomUUID();
            if (!this.store.delivery && this.form.service === 'delivery') this.form.service = 'togo';
            this.$watch('lines', () => this.save());
            this.$watch('form', () => this.save());
        },
        save() {
            try {
                localStorage.setItem(
                    'pedir:borrador',
                    JSON.stringify({
                        savedAt: Date.now(),
                        form: { ...this.form, website: '' },
                        lines: this.lines.map(({ product, ...rest }) => rest),
                    }),
                );
            } catch (e) {}
        },

        get groups() {
            const map = new Map();
            this.catalog.forEach((p) => map.set(p.category, [...(map.get(p.category) || []), p]));
            return [...map].map(([name, products]) => ({ name, products }));
        },
        money(n) {
            const whole = Math.abs(n % 1) < 0.005;
            return new Intl.NumberFormat('es-MX', {
                style: 'currency',
                currency: 'MXN',
                minimumFractionDigits: whole ? 0 : 2,
                maximumFractionDigits: whole ? 0 : 2,
            }).format(n);
        },
        customizable(p) {
            return p.without.length > 0 || p.extras.length > 0 || p.options.length > 0;
        },
        countOf(p) {
            return this.lines.filter((l) => l.product_id === p.id).reduce((t, l) => t + l.qty, 0);
        },
        plainCount(p) {
            return this.countOf(p);
        },
        count() {
            return this.lines.reduce((t, l) => t + l.qty, 0);
        },
        toggle(list, id) {
            const i = list.indexOf(id);
            i === -1 ? list.push(id) : list.splice(i, 1);
        },

        tap(p) {
            if (p.soldOut) return;
            if (this.customizable(p)) return this.openEditor(p);
            this.changeQty(p, 1);
        },
        changeQty(p, by) {
            const i = this.lines.findIndex(
                (l) => l.product_id === p.id && !l.without.length && !l.extras.length && !Object.keys(l.choices).length && !l.notes,
            );
            if (i === -1) {
                if (by > 0) this.lines.push(this.newLine(p, 1, {}, [], [], ''));
                return;
            }
            this.bump(i, by);
        },
        newLine(p, qty, choices, without, extras, notes) {
            return { key: crypto.randomUUID(), product_id: p.id, product: p, qty, choices, without, extras, notes };
        },
        bump(i, by) {
            const l = this.lines[i];
            l.qty = Math.min(20, l.qty + by);
            if (l.qty <= 0) this.lines.splice(i, 1);
            if (!this.lines.length) this.cart = false;
        },

        openEditor(p) {
            const choices = {};
            p.options.forEach((g) => (choices[g.id] = g.required && g.choices.length === 1 ? g.choices[0].id : null));
            this.editorError = '';
            this.editor = { product: p, qty: 1, choices, without: [], extras: [], notes: '' };
        },
        editorUnit() {
            const e = this.editor;
            if (!e) return 0;
            const extras = e.product.extras.filter((x) => e.extras.includes(x.id)).reduce((t, x) => t + x.price, 0);
            const chosen = e.product.options.flatMap((g) => g.choices.filter((c) => e.choices[g.id] === c.id)).reduce((t, c) => t + c.price, 0);
            return e.product.price + extras + chosen;
        },
        editorTotal() {
            return this.editorUnit() * (this.editor?.qty || 1);
        },
        saveEditor() {
            const e = this.editor;
            const missing = e.product.options.find((g) => g.required && !e.choices[g.id]);
            if (missing) return (this.editorError = 'Elige ' + missing.name.toLowerCase() + '.');
            const choices = {};
            Object.entries(e.choices).forEach(([g, c]) => c && (choices[g] = c));
            this.lines.push(this.newLine(e.product, e.qty, choices, [...e.without], [...e.extras], e.notes.trim()));
            this.editor = null;
        },

        describe(l) {
            const p = l.product;
            const parts = [
                ...p.options.flatMap((g) => g.choices.filter((c) => l.choices[g.id] === c.id).map((c) => c.name)),
                ...p.without.filter((w) => l.without.includes(w.id)).map((w) => 'sin ' + w.name.toLowerCase()),
                ...p.extras.filter((x) => l.extras.includes(x.id)).map((x) => x.name),
            ];
            if (l.notes) parts.push('“' + l.notes + '”');
            return parts.join(', ');
        },
        lineUnit(l) {
            const p = l.product;
            const extras = p.extras.filter((x) => l.extras.includes(x.id)).reduce((t, x) => t + x.price, 0);
            const chosen = p.options.flatMap((g) => g.choices.filter((c) => l.choices[g.id] === c.id)).reduce((t, c) => t + c.price, 0);
            return p.price + extras + chosen;
        },
        lineTotal(l) {
            return this.lineUnit(l) * l.qty;
        },
        subtotal() {
            return this.lines.reduce((t, l) => t + this.lineTotal(l), 0);
        },
        fees() {
            return { packaging: this.store.packagingFee, delivery: this.form.service === 'delivery' ? this.store.deliveryFee : 0 };
        },
        total() {
            const f = this.fees();
            return this.subtotal() + f.packaging + f.delivery;
        },

        openCart() {
            this.errors = {};
            this.cart = true;
        },
        async submit() {
            if (this.sending) return;
            this.errors = {};
                        this.sending = true;
            const lines = this.lines.map((l) => ({
                product_id: l.product_id,
                qty: l.qty,
                notes: l.notes,
                without: l.without,
                extras: l.extras,
                choices: Object.values(l.choices),
            }));
            try {
                const result = await this.$wire.place(lines, { ...this.form, expected_total: this.total() });
                if (result && !result.ok) {
                    this.errors = result.errors || {};
                    if (!Object.keys(this.errors).length) this.errors = { store: 'No pudimos mandar tu pedido. Inténtalo otra vez.' };
                    return;
                }
                // Pedido enviado: ya no se necesita el borrador (se conservan el nombre y el teléfono).
                try {
                    localStorage.setItem(
                        'pedir:borrador',
                        JSON.stringify({ savedAt: Date.now(), form: { name: this.form.name, phone: this.form.phone, service: this.form.service }, lines: [] }),
                    );
                } catch (e) {}
            } catch (e) {
                this.errors = { store: 'Sin conexión. Revisa tu internet e inténtalo de nuevo.' };
            } finally {
                this.sending = false;
            }
        },
    }));
});
