import Alpine from 'alpinejs';
import { Chart, DoughnutController, ArcElement, BarController, BarElement, CategoryScale, LinearScale, Tooltip, Legend } from 'chart.js';

Chart.register(DoughnutController, ArcElement, BarController, BarElement, CategoryScale, LinearScale, Tooltip, Legend);
window.Chart = Chart;
window.Alpine = Alpine;

// Tema: respeta preferencia guardada o la del sistema
const stored = localStorage.getItem('theme');
if (stored === 'dark' || (!stored && matchMedia('(prefers-color-scheme: dark)').matches)) {
    document.documentElement.classList.add('dark');
}

Alpine.data('theme', () => ({
    dark: document.documentElement.classList.contains('dark'),
    toggle() {
        this.dark = !this.dark;
        document.documentElement.classList.toggle('dark', this.dark);
        try { localStorage.setItem('theme', this.dark ? 'dark' : 'light'); } catch (e) {}
    },
}));

// Formulario de movimiento con equivalencia en vivo USD <-> Bs
const moneyFormDef = (rate, defaults = {}) => ({
    rate,
    type: 'expense',
    newCat: false,
    currency: 'USD',
    amount: '',
    ...defaults,
    get hint() {
        const n = parseFloat(this.amount);
        if (!n || !this.rate) return '';
        return this.currency === 'USD'
            ? '≈ Bs ' + (n * this.rate).toLocaleString('es-VE', { maximumFractionDigits: 2 })
            : '≈ $' + (n / this.rate).toLocaleString('en-US', { maximumFractionDigits: 2 });
    },
});
Alpine.data('moneyForm', moneyFormDef);

// Deuda con detalles: copia las propiedades (incluidos getters) del formulario de montos y suma filas
Alpine.data('debtForm', (rate) => {
    const extra = {
        rows: [], total: '',
        get detailSum() { return this.rows.reduce((s, r) => s + (parseFloat(r.amount) || 0), 0); },
        addRow() { this.rows.push({ name: '', amount: '' }); },
        removeRow(i) { this.rows.splice(i, 1); },
    };
    return Object.defineProperties({}, { ...Object.getOwnPropertyDescriptors(moneyFormDef(rate)), ...Object.getOwnPropertyDescriptors(extra) });
});

// Calculadora del mercado: lista de productos con total en vivo, guardada en el dispositivo.
Alpine.data('market', (cfg) => ({
    key: 'finanve.market.' + cfg.user,
    items: [],
    budget: '',
    flow: null,                // null = aún no elige | 'detail' | 'total'
    place: '',
    mode: cfg.base,            // moneda en que se escriben los precios (USD o VES en Venezuela)
    form: { name: '', price: '', qty: 1 },
    sheet: false,
    final: '',
    error: '',
    names: [],

    init() {
        try {
            if (cfg.saved) localStorage.removeItem(this.key);
            const s = JSON.parse(localStorage.getItem(this.key) || 'null');
            if (s) { this.items = s.items || []; this.budget = s.budget || ''; this.names = s.names || []; this.place = s.place || ''; this.flow = s.flow || null; }
            if (this.items.length && !this.flow) this.flow = 'detail';
        } catch (e) {}
        const save = () => { try { localStorage.setItem(this.key, JSON.stringify({ items: this.items, budget: this.budget, names: this.names.slice(0, 60), place: this.place, flow: this.flow })); } catch (e) {} };
        this.$watch('items', save, { deep: true });
        this.$watch('budget', save);
        this.$watch('place', save);
        this.$watch('flow', save);
    },

    round(n) { return Math.round(n * 100) / 100; },
    num(n) {
        const f = cfg.fmt;
        const [i, d] = Math.abs(n).toFixed(f.decimals).split('.');
        return i.replace(/\B(?=(\d{3})+(?!\d))/g, f.thou) + (d ? f.dec + d : '');
    },
    fmt(n) {
        const f = cfg.fmt, t = this.num(n);
        return (n < 0 ? '−' : '') + (f.after ? t + ' ' + f.symbol : f.symbol + t);
    },
    ves(n) {
        if (!cfg.rate) return '';
        return 'Bs ' + (n * cfg.rate).toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },
    unit(i) { return i.cur === 'VES' ? 'Bs ' + i.price.toLocaleString('es-VE', { maximumFractionDigits: 2 }) : this.fmt(i.price); },

    get total() { return this.round(this.items.reduce((s, i) => s + i.qty * i.base, 0)); },
    get count() { return this.items.reduce((s, i) => s + i.qty, 0); },
    get left() { const b = parseFloat(this.budget); return b > 0 ? this.round(b - this.total) : null; },
    get pct() { const b = parseFloat(this.budget); return b > 0 ? Math.min(100, this.total / b * 100) : 0; },

    add() {
        const price = parseFloat(String(this.form.price).replace(',', '.'));
        if (!price || price <= 0) { this.error = 'Escribe el precio del producto.'; this.$refs.price.focus(); return; }
        this.error = '';
        const name = this.form.name.trim();
        const base = this.mode === 'VES' ? price / cfg.rate : price;
        this.items.unshift({ id: Date.now() + Math.random(), name, qty: Math.max(1, parseInt(this.form.qty) || 1), price, cur: this.mode, base: this.round(base) });
        if (name && !this.names.includes(name)) this.names.unshift(name);
        this.form.name = ''; this.form.price = ''; this.form.qty = 1;
        this.$refs.name.focus();
    },
    step(i, d) { i.qty = Math.max(1, i.qty + d); },
    remove(i) { this.items = this.items.filter(x => x.id !== i.id); },
    clear() { if (this.items.length && confirm('¿Vaciar la lista?')) { this.items = []; this.budget = ''; } },
    openSave() { this.final = this.total.toFixed(2); this.sheet = true; },
    payload() { return JSON.stringify(this.items.map(i => ({ name: i.name, qty: i.qty, price: i.base }))); },
}));

// Inclinación 3D de una tarjeta según el puntero
Alpine.data('tilt', () => ({
    ok: matchMedia('(hover: hover) and (pointer: fine)').matches && !matchMedia('(prefers-reduced-motion: reduce)').matches,
    move(e) {
        if (!this.ok) return;
        const r = this.$refs.card.getBoundingClientRect();
        const x = (e.clientX - r.left) / r.width - .5, y = (e.clientY - r.top) / r.height - .5;
        this.$refs.card.style.setProperty('--ry', (x * 9).toFixed(2) + 'deg');
        this.$refs.card.style.setProperty('--rx', (-y * 7).toFixed(2) + 'deg');
    },
    reset() { this.$refs.card.style.setProperty('--rx', '0deg'); this.$refs.card.style.setProperty('--ry', '0deg'); },
}));

// Formato de montos en la moneda base (usa la configuración que inyecta el layout)
window.finMoney = (n) => {
    const f = (window.FIN && window.FIN.fmt) || { symbol: '$', decimals: 2, dec: '.', thou: ',', after: false };
    const [i, d] = Math.abs(n).toFixed(f.decimals).split('.');
    const t = i.replace(/\B(?=(\d{3})+(?!\d))/g, f.thou) + (d ? f.dec + d : '');
    return (n < 0 ? '−' : '') + (f.after ? t + ' ' + f.symbol : f.symbol + t);
};

// Compartir un gasto: % por persona, parte que queda a cargo de quien lo registró y total en vivo
Alpine.data('share', (cfg) => ({
    amount: cfg.amount,
    rows: cfg.rows,    // [{ id, name, on, percent }]
    get total() { return this.rows.filter(r => r.on).reduce((s, r) => s + (parseFloat(r.percent) || 0), 0); },
    get mine() { return Math.round((100 - this.total) * 100) / 100; },
    get invalid() { return this.total > 100.0001 || this.rows.some(r => r.on && !(parseFloat(r.percent) > 0)); },
    money(pct) { return window.finMoney(this.amount * (parseFloat(pct) || 0) / 100); },
    toggle(r) { if (r.on && !(parseFloat(r.percent) > 0)) r.percent = Math.max(1, Math.min(50, this.mine)); },
    equal() {
        const on = this.rows.filter(r => r.on);
        if (!on.length) return;
        const each = Math.floor(10000 / (on.length + 1)) / 100;   // incluye tu parte
        on.forEach(r => r.percent = each);
    },
}));

// Carrusel: desliza (scroll-snap), puntos, flechas y avance automático con pausa
Alpine.data('slides', (n) => ({
    n, i: 0, playing: false, paused: false, timer: null,
    reduce: matchMedia('(prefers-reduced-motion: reduce)').matches,
    init() {
        this.playing = !this.reduce && n > 1;
        const t = this.$refs.track;
        t.addEventListener('scroll', () => {
            const w = t.clientWidth || 1;
            this.i = Math.max(0, Math.min(n - 1, Math.round(t.scrollLeft / w)));
        }, { passive: true });
        this.start();
    },
    go(k) {
        this.i = (k + n) % n;
        const t = this.$refs.track;
        t.scrollTo({ left: this.i * t.clientWidth, behavior: this.reduce ? 'auto' : 'smooth' });
    },
    next() { this.go(this.i + 1); },
    prev() { this.go(this.i - 1); },
    start() {
        clearInterval(this.timer);
        if (this.playing && n > 1) this.timer = setInterval(() => { if (!this.paused) this.next(); }, 7000);
    },
    toggle() { this.playing = !this.playing; this.start(); },
}));

// Gráfica de dona / barras
window.finChart = (el, cfg) => new Chart(el, cfg);

Alpine.start();
