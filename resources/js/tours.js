import { driver } from 'driver.js';
import 'driver.js/dist/driver.css';

/*
 * Recorridos guiados (driver.js). Cada pantalla tiene el suyo: se muestra solo la primera
 * vez que se entra (se puede omitir) y después con el botón Tutorial del encabezado.
 *
 * Cada paso lleva:
 *   el     nombre del data-tour a resaltar; sin él, el aviso sale centrado.
 *   title  y text (puede llevar HTML; {negocio} se cambia por el nombre del negocio).
 *   media  'mobile' o 'desktop' si solo aplica en esa pantalla.
 *   open   qué abrir antes de mostrarlo ('pedido' es el cuadro de cobro de Vender).
 * Los pasos cuyo elemento no está en la página (por ejemplo, una lista vacía) se saltan.
 */
const modules = `
    <ul>
        <li><b>Resumen:</b> cuánto vendiste y cuánto ganaste en el periodo.</li>
        <li><b>Lista de compras:</b> qué comprar para mañana según lo que se vende ese día.</li>
        <li><b>Pantalla de cocina:</b> los pedidos en letra grande para una tablet en la plancha.</li>
        <li><b>Corte de caja:</b> compara el efectivo que debería haber con el que cuentas al cerrar.</li>
        <li><b>Productos e Insumos:</b> precios, recetas y costos de lo que vendes.</li>
        <li><b>Ajustes:</b> nombre, logo, cobro de empaque y envío, contraseña.</li>
    </ul>`;

const tours = {
    vender: [
        {
            title: 'Te damos la bienvenida a {negocio}',
            text: 'Esta app lleva las ventas, los pedidos, los gastos y la ganancia del negocio. En un par de minutos te enseño a tomar un pedido y dónde está cada cosa. Si ya la conoces, toca Omitir.',
        },
        {
            el: 'producto',
            title: 'Tus productos',
            text: 'Cada tarjeta es un producto con su precio. Tócala una vez por pieza: tres toques en un taco son tres tacos. El círculo rojo dice cuántos llevas y el botón − quita uno.',
        },
        {
            el: 'categorias',
            title: 'Categorías',
            text: 'Filtra el menú por tipo para encontrar más rápido lo que te piden. Todo vuelve a mostrar el menú completo.',
        },
        {
            el: 'agotados',
            title: 'Cuando algo se acaba',
            text: 'Toca Agotados y después el producto que se terminó. Queda en gris solo por hoy, así nadie lo vende por error. Mañana vuelve a estar disponible solo.',
        },
        {
            el: 'pedido-panel',
            media: 'wide',
            title: 'El pedido',
            text: 'Aquí se va armando lo que pidió el cliente, con el total. Cuando termines, toca Continuar para cobrarlo o guardarlo.',
        },
        {
            media: 'narrow',
            title: 'El pedido',
            text: 'Al agregar productos aparece abajo una barra roja con cuántas piezas llevas y el total. Tócala para ver el pedido completo y cobrarlo. Te enseño qué hay ahí.',
        },
        {
            el: 'para-quien',
            open: 'pedido',
            title: '¿Para quién es?',
            text: 'Un nombre o una seña: Juan, camioneta negra, mesa 2. Si cobras en ese momento es opcional. Si lo guardas para cobrar después, es obligatorio, porque con ese nombre lo encuentras en Pedidos.',
        },
        {
            el: 'servicio',
            open: 'pedido',
            title: 'Para llevar, aquí o a domicilio',
            text: 'Para llevar suma el cobro de empaque, si lo configuraste en Ajustes. A domicilio pide la dirección y el teléfono y suma el envío. Después, desde Pedidos, puedes llamar al cliente, mandarle WhatsApp o abrir la dirección en el mapa.',
        },
        {
            el: 'cuando',
            open: 'pedido',
            title: '¿Ahora o más tarde?',
            text: 'Si el cliente pasa después, elige Más tarde y la hora. Pedidos y la cocina lo acomodan por esa hora y lo marcan en rojo cuando faltan menos de 10 minutos.',
        },
        {
            el: 'renglones',
            open: 'pedido',
            title: 'Indicaciones de cada producto',
            text: 'Toca un producto del pedido para pedirlo sin algo (sin cebolla), agregarle extras con precio, escribir una nota o darlo de cortesía. Si llevan 3 tortas y solo una va sin cebolla, puedes aplicarlo a una sola.',
        },
        {
            el: 'pago',
            open: 'pedido',
            title: 'Forma de pago',
            text: 'Efectivo, transferencia, tarjeta o mixto (una parte en efectivo y el resto con otro método). En efectivo escribe con cuánto te pagan y la app te dice el cambio. Arriba de esto está + Descuento, en pesos o en porcentaje.',
        },
        {
            el: 'propina',
            open: 'pedido',
            title: 'Propina',
            text: 'Se cobra junto con el pedido pero se lleva aparte: no se suma a las ventas. En el corte de caja sí cuenta el efectivo que entró por propinas.',
        },
        {
            el: 'cobrar',
            open: 'pedido',
            title: 'Cobrar o guardar',
            text: 'Cobrar registra la venta y la pasa a Pedidos para entregarla. Guardar sin cobrar lo deja apartado con su nombre, para cuando el cliente pague al final. Si te equivocas al cobrar, toca Deshacer en el aviso que sale unos segundos. Si ya se fue, en Ventas abre la venta: ahí puedes cambiar la forma de pago o corregir el pedido.',
        },
        {
            el: 'nav-pedidos',
            title: 'Pedidos',
            text: 'Lo que falta entregar y lo que falta cobrar, con el tiempo que lleva esperando cada cliente. Ahí marcas Entregado, cobras lo pendiente y abres la pantalla de cocina.',
        },
        {
            el: 'nav-ventas',
            title: 'Ventas',
            text: 'Todo lo cobrado, día por día: cuánto se vendió, cuánto entró en efectivo y cuánto por transferencia o tarjeta, y el detalle de cada orden. Si una venta estuvo mal, ahí la corriges.',
        },
        {
            el: 'nav-gastos',
            title: 'Gastos',
            text: 'Anota todo lo que pagas: carne, tortillas, gas, desechables. Si eliges el insumo y la cantidad, la app actualiza su costo y con eso sabe cuánto te cuesta cada producto.',
        },
        {
            el: 'nav-admin',
            media: 'desktop',
            title: 'Administración',
            text: 'Lo que usas menos seguido:' + modules,
        },
        {
            el: 'nav-mas',
            media: 'mobile',
            title: 'Más',
            text: 'Aquí está lo que usas menos seguido:' + modules,
        },
        {
            el: 'ayuda',
            title: '¿Dudas después?',
            text: 'Cada pantalla tiene este botón Tutorial para volver a ver cómo funciona. La primera vez que entres a cada una, la explicación sale sola; si no la necesitas, toca Omitir.',
        },
    ],

    pedidos: [
        {
            title: 'Pedidos',
            text: 'Aquí está todo lo que sigue en curso: lo que falta entregar y lo que se guardó sin cobrar. Se actualiza solo cada pocos segundos, así que puedes dejar esta pantalla abierta.',
        },
        {
            el: 'pedido',
            title: 'Cada pedido',
            text: 'Arriba ves el número para llamar al cliente, su nombre, si ya pagó o está por cobrar y cuánto lleva esperando (se pone en rojo después de 15 minutos). Abajo, cada producto con sus indicaciones.',
        },
        {
            el: 'pedido-acciones',
            title: 'Entregar o cobrar',
            text: 'Entregado lo quita de la lista cuando se lo das al cliente. Los de comer aquí ya cobrados salen solos cuando la cocina los marca Listo. Si está por cobrar, Cobrar abre el pedido para recibir el pago, y en el menú ··· puedes editarlo o cancelarlo. Los de domicilio primero se marcan con Enviar, cuando salen con el repartidor.',
        },
        {
            el: 'pedidos-vacio',
            title: 'Todavía no hay pedidos',
            text: 'En cuanto cobres o guardes un pedido en Vender, aparecerá aquí.',
        },
        {
            el: 'nuevo-pedido',
            title: 'Nuevo pedido',
            text: 'Te lleva a Vender para tomar otro pedido.',
        },
        {
            el: 'cocina',
            title: 'Pantalla de cocina',
            text: 'Abre una vista con letra grande pensada para una tablet junto a la plancha. Ahí quien cocina marca cada pedido como Listo y aquí lo ves para entregarlo.',
        },
        {
            el: 'pedidos-anteriores',
            title: 'Sin cobrar de días anteriores',
            text: 'Pedidos que se guardaron y nunca se cobraron. Ya no salen en la cocina. Cóbralos si te pagaron o cancélalos para que no se acumulen.',
        },
        {
            el: 'entregados',
            title: 'Entregados hoy',
            text: 'Lo que ya se entregó. Si marcaste uno por error, ábrelo y toca Regresar.',
        },
    ],

    cocina: [
        {
            title: 'Pantalla de cocina',
            text: 'Pensada para una tablet junto a la plancha. Muestra en letra grande lo que hay que preparar, en el orden en que se pidió o por la hora en que lo recogen. Se actualiza sola y mantiene la pantalla encendida.',
        },
        {
            el: 'cocina-pedido',
            title: 'Cada pedido',
            text: 'Número, nombre y cuánto lleva esperando. Lo que va sin algo sale en rojo, los extras en verde y las notas en amarillo, para que no se pasen.',
        },
        {
            el: 'cocina-listo',
            title: 'Listo',
            text: 'Tócalo cuando el pedido esté preparado. Se quita de aquí y en Pedidos aparece como Listo para entregarlo.',
        },
        {
            el: 'cocina-listos',
            title: 'Los últimos listos',
            text: 'Si marcaste uno por error, tócalo aquí y regresa a preparación.',
        },
        {
            el: 'cocina-volver',
            title: 'Volver',
            text: 'Regresa a la lista de Pedidos.',
        },
    ],

    ventas: [
        {
            el: 'fecha',
            title: 'Elige el día',
            text: 'Empieza en hoy. Usa las flechas o toca la fecha para ver otro día. Cada venta cuenta el día en que se cobró: un pedido guardado anoche y cobrado hoy cuenta hoy, igual que en el corte.',
        },
        {
            el: 'ventas-dia',
            title: 'Lo vendido',
            text: 'El total cobrado ese día y cuántas órdenes fueron. La barra de colores separa lo que entró en efectivo, por transferencia y con tarjeta. Si hubo propinas o descuentos, salen abajo.',
        },
        {
            el: 'orden',
            title: 'Cada orden',
            text: 'Tócala para ver sus productos, cómo se pagó y cuánto dejó según la receta. Si se cobró mal, ahí puedes cambiar la forma de pago, corregir el pedido (se abre en Vender para cobrarlo de nuevo) o cancelarla. Solo las de hoy: lo de otros días ya está en su corte.',
        },
        {
            el: 'ventas-vacio',
            title: 'Sin ventas este día',
            text: 'Cuando cobres pedidos en Vender, aquí verás cada uno con su hora y forma de pago.',
        },
        {
            el: 'corte',
            title: 'Corte de caja',
            text: 'Al cerrar, ve al corte para contar el efectivo y ver si la caja cuadra.',
        },
    ],

    gastos: [
        {
            el: 'nuevo-gasto',
            title: 'Registrar un gasto',
            text: 'Cada vez que pagues algo del negocio, anótalo aquí: carne, tortillas, verdura, gas, desechables, una reparación. Así Resumen puede decirte cuánto te queda de verdad.',
        },
        {
            title: 'Compras de insumos',
            text: 'Si es carne, tortilla o cualquier insumo, elige cuál y la cantidad, por ejemplo 5 kg de bistec por $950. La app saca el costo por kilo y recalcula cuánto te cuesta cada producto que lo lleva.',
        },
        {
            title: '¿Salió de la caja?',
            text: 'Al guardar un gasto, marca si se pagó con el efectivo de la caja. Si sí, el corte lo descuenta de lo que debe haber. Si lo pagaste con tarjeta o de tu bolsa, apágalo.',
        },
        {
            el: 'mes',
            title: 'Mes',
            text: 'Los gastos se ven por mes. Cambia de mes con las flechas.',
        },
        {
            el: 'gastos-mes',
            title: 'Total del mes',
            text: 'Lo que se gastó en el mes, separado por tipo, para ver en qué se va el dinero.',
        },
        {
            el: 'gasto',
            title: 'Cada gasto',
            text: 'Tócalo para corregirlo y con el bote de basura lo borras. "Fuera de caja" quiere decir que no se pagó con el efectivo de la caja.',
        },
    ],

    resumen: [
        {
            el: 'periodo',
            title: 'Periodo',
            text: 'Elige qué días ver: hoy, los últimos 7, el mes en curso o los últimos 30. Todo lo de abajo cambia con esto.',
        },
        {
            el: 'para-empezar',
            title: 'Para empezar',
            text: 'Con estos pasos la app puede calcular tu ganancia. Se marcan solos cuando los completas.',
        },
        {
            el: 'resumen-ventas',
            title: 'Ventas',
            text: 'Lo cobrado en el periodo, cuántas órdenes fueron, cuánto gasta en promedio cada cliente y el margen. La etiqueta de color compara contra el periodo anterior del mismo largo.',
        },
        {
            el: 'resumen-ganancia',
            title: 'Dos formas de ver la ganancia',
            text: '<b>Ganancia por receta:</b> lo vendido menos lo que costaron los ingredientes de eso que vendiste. Te dice si tus precios dejan.<br><b>Ventas menos gastos:</b> el dinero que entró menos todo lo que pagaste. Baja los días que compras mucha mercancía y sube los demás.',
        },
        {
            el: 'resumen-top',
            title: 'Lo que más se vende',
            text: 'Tus productos ordenados por piezas vendidas, con lo que dejó cada uno. "Sin receta" quiere decir que falta armar su receta en Productos para calcular la ganancia.',
        },
        {
            el: 'resumen-dias',
            title: 'Por día',
            text: 'Ventas en rojo y gastos en amarillo de cada día. Toca una barra para ver los montos.',
        },
        {
            el: 'resumen-semana',
            title: 'Por día de la semana',
            text: 'Cuánto se vende en promedio cada día de la semana, con lo de las últimas 8 semanas, y qué es lo que más sale. Sirve para saber qué días preparar más.',
        },
        {
            el: 'resumen-horas',
            title: 'Horas fuertes',
            text: 'A qué hora se vende más, para tener todo listo antes de que llegue la gente.',
        },
    ],

    compras: [
        {
            title: 'Lista de compras',
            text: 'Te sugiere cuánto comprar de cada insumo para un día. La calcula con lo que se vendió los mismos días de la semana en las últimas 4 semanas y con las recetas de tus productos.',
        },
        {
            el: 'fecha',
            title: '¿Para qué día?',
            text: 'Empieza en mañana. Si el sábado compras para el domingo, elige el domingo.',
        },
        {
            el: 'compras-lista',
            title: 'Qué comprar',
            text: 'Las cantidades vienen redondeadas para comprarlas (kilos de 100 en 100 gramos, piezas enteras) con un costo aproximado según el último precio que pagaste. Réstale lo que ya tienes guardado.',
        },
        {
            el: 'compras-extra',
            title: '10% extra',
            text: 'Agrega un poco más por si se vende más de lo normal. Apágalo si prefieres comprar justo.',
        },
        {
            el: 'compras-compartir',
            title: 'Mandar la lista',
            text: 'Compártela por WhatsApp a quien va a ir a comprar, o cópiala para pegarla donde quieras.',
        },
        {
            el: 'compras-vacio',
            title: 'Todavía no hay lista',
            text: 'Hacen falta recetas en tus productos y unos días de ventas. En cuanto los haya, aquí aparece la lista.',
        },
    ],

    corte: [
        {
            title: 'Corte de caja',
            text: 'Sirve para saber si el efectivo cuadra al final del día. La app calcula cuánto debería haber en la caja y tú lo comparas con lo que cuentas.',
        },
        {
            el: 'corte-fondo',
            title: 'Fondo inicial',
            text: 'El efectivo con el que abriste la caja, el cambio. Se llena solo con lo que contaste en el corte anterior. Cámbialo si sacaste o metiste dinero entre un día y otro.',
        },
        {
            el: 'corte-esperado',
            title: 'Debe haber en caja',
            text: 'Fondo inicial, más lo cobrado en efectivo, menos los gastos pagados con dinero de la caja, más o menos las entradas y retiros. Las transferencias y la tarjeta no cuentan porque ese dinero no entra a la caja.',
        },
        {
            el: 'corte-movimientos',
            title: 'Sacar o meter efectivo',
            text: 'Si sacas dinero de la caja (un depósito al banco, un pago personal) o metes cambio, anótalo aquí. Si no, el corte no va a cuadrar.',
        },
        {
            el: 'corte-contado',
            title: 'Efectivo contado',
            text: 'Al cerrar, cuenta billetes y monedas y escribe el total. En ese momento verás si la caja cuadra, si sobra o si falta.',
        },
        {
            el: 'corte-guardar',
            title: 'Guardar el corte',
            text: 'Queda registrado con su diferencia, y lo que contaste será el fondo del día siguiente. Si vuelves a contar, puedes actualizarlo.',
        },
        {
            el: 'corte-historial',
            title: 'Cortes anteriores',
            text: 'Los últimos días, para ver si la caja ha cuadrado.',
        },
    ],

    productos: [
        {
            el: 'nuevo-producto',
            title: 'Agregar un producto',
            text: 'Todo lo que vendes es un producto con su precio. Cada variedad va aparte (taco de pastor, taco de tripa), porque su receta y su costo son distintos.',
        },
        {
            el: 'producto',
            title: 'Costo y ganancia',
            text: 'Si el producto tiene receta, ves cuánto cuesta hacer una pieza, cuánto ganas y el margen. Verde es buen margen, amarillo regular y rojo bajo (menos de 25%).',
        },
        {
            el: 'producto-orden',
            title: 'Acomodar',
            text: 'Arrastra desde los puntitos para cambiar el orden. Vender muestra los productos en este mismo orden, así que pon primero lo que más se vende.',
        },
        {
            el: 'producto-menu',
            title: 'Más opciones',
            text: 'Editar, duplicar (útil para otra variedad con la misma receta), subir o bajar y ocultarlo de Vender sin borrarlo. Un producto que ya se vendió no se puede borrar, solo ocultar.',
        },
        {
            title: 'Receta, extras y opciones',
            text: 'Al editar un producto armas su receta con tus insumos: lo que lleva una pieza, por ejemplo 0.08 kg de carne (u 80 g si en Insumos la usas por gramo) y 2 tortillas. Toca "Se puede quitar" en lo que el cliente puede pedir sin, agrega extras con precio (queso, aguacate) y opciones a escoger cuando el cliente elige entre varias, como el refresco de un combo. Lo que siempre lleva va en la receta.',
        },
    ],

    insumos: [
        {
            title: 'Insumos',
            text: 'Son las cosas que compras para cocinar: carne, tortillas, cebolla, salsas, desechables. Cada uno tiene una unidad (kg, pieza, paquete) y su costo por esa unidad.',
        },
        {
            el: 'nuevo-insumo',
            title: 'Agregar un insumo',
            text: 'Ponle nombre, la unidad en que lo compras y un costo aproximado. El costo se corrige solo después. Si no lo usas como lo compras (el pan se compra por paquete y se usa por pieza), dilo en "En las recetas" con cuántas trae.',
        },
        {
            el: 'insumo',
            title: 'Costo por unidad',
            text: 'Se actualiza cada vez que registras en Gastos una compra de ese insumo con su cantidad. Con este costo se calcula cuánto cuesta cada producto. Si ya no lo usas, desactívalo desde el menú ···.',
        },
        {
            el: 'buscar',
            title: 'Buscar',
            text: 'Escribe parte del nombre para filtrar la lista.',
        },
    ],

    ajustes: [
        {
            el: 'ajustes-logo',
            title: 'Logo',
            text: 'Sale al entrar, en el menú y como ícono si instalas la app en el celular. Usa una imagen cuadrada para que se vea completa.',
        },
        {
            el: 'ajustes-negocio',
            title: 'Nombre del negocio',
            text: 'El nombre completo sale en el menú y al entrar. El corto es el que aparece debajo del ícono en el celular, por eso lleva pocas letras.',
        },
        {
            el: 'ajustes-cobros',
            title: 'Empaque y envío',
            text: 'El empaque se suma una vez a cada pedido para llevar o a domicilio. El envío es lo que cobras normalmente por llevarlo; en cada pedido se puede cambiar. Deja 0 si no cobras.',
        },
        {
            el: 'ajustes-dia',
            title: 'Día de trabajo',
            text: 'Si cierras después de medianoche, elige la hora en que termina tu día. Así lo que vendes a la 1 de la mañana cuenta en el mismo día, el corte sale completo y los números de pedido no empiezan de nuevo a media noche.',
        },
        {
            el: 'ajustes-contrasena',
            title: 'Contraseña',
            text: 'Al cambiarla se cierra la sesión en los demás celulares o computadoras.',
        },
        {
            el: 'ajustes-recorridos',
            title: 'Explicaciones',
            text: 'Desde aquí puedes repetir el recorrido de Vender o hacer que todas las pantallas te vuelvan a explicar cómo funcionan.',
        },
    ],
};

const selector = (name) => `[data-tour="${name}"]`;
const visible = (name) => [...document.querySelectorAll(selector(name))].find((el) => el.getClientRects().length > 0);
const isDesktop = () => window.matchMedia('(min-width: 768px)').matches;
// El panel del pedido en Vender va al lado desde 1024 px; antes se usa la barra "Ver pedido".
const isWide = () => window.matchMedia('(min-width: 1024px)').matches;
const fitsScreen = (media) => !media || ({ desktop: isDesktop(), mobile: !isDesktop(), wide: isWide(), narrow: !isWide() })[media];
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const escapeHtml = (text) => text.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

let active = null;
// Lo que el recorrido abrió (el cuadro de cobro) para cerrarlo al cambiar de paso o al salir.
let opened = null;
// Vistos en esta visita: el HTML de una página regresada con "atrás" puede traer la lista vieja.
const seenNow = new Set();

async function prepare(open) {
    if (open === opened) return;
    if (opened) window.dispatchEvent(new CustomEvent('tour-close'));
    if (open) window.dispatchEvent(new CustomEvent('tour-open', { detail: open }));
    opened = open;
    // Deja terminar la animación del cuadro antes de medir dónde quedó cada elemento.
    await wait(350);
}

function markSeen(key) {
    if (key === 'todas') Object.keys(tours).forEach((k) => seenNow.add(k));
    seenNow.add(key);
    fetch(document.body.dataset.tourUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
        },
        body: JSON.stringify({ tour: key }),
    }).catch(() => {});
}

// Botón de texto para el pie o la descripción del cuadro.
function textButton(className, label, onClick) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = className;
    button.textContent = label;
    button.addEventListener('click', onClick);
    return button;
}

/**
 * @param {string} key
 * @param {{ auto?: boolean }} options  auto: se abrió solo al entrar a la pantalla.
 */
export async function startTour(key, { auto = false } = {}) {
    if (active?.isActive()) return;

    const business = escapeHtml(document.body.dataset.business ?? '');
    const steps = (tours[key] ?? [])
        .filter((s) => fitsScreen(s.media))
        .filter((s) => !s.el || s.open || visible(s.el))
        .map((s) => ({
            element: s.el ? () => visible(s.el) : undefined,
            popover: { title: s.title.replace('{negocio}', business), description: s.text },
            data: { open: s.open ?? null },
        }));

    if (!steps.length) return;

    // Al cambiar de paso primero se abre o cierra lo necesario y luego se resalta.
    const go = async (tour, index) => {
        if (index < 0) return;
        if (index >= steps.length) return tour.destroy();
        await prepare(steps[index].data.open);
        tour.moveTo(index);
    };

    active = driver({
        steps,
        showProgress: steps.length > 1,
        progressText: '{{current}} de {{total}}',
        nextBtnText: 'Siguiente',
        prevBtnText: 'Atrás',
        doneBtnText: 'Listo',
        closeBtnLabel: 'Cerrar',
        popoverClass: 'tour',
        overlayColor: '#1a110a',
        overlayOpacity: 0.6,
        // Un toque fuera del cuadro no cierra el recorrido: se sale con Omitir o la X.
        overlayClickBehavior: 'none',
        disableActiveInteraction: true,
        smoothScroll: true,
        stagePadding: 6,
        stageRadius: 14,
        onNextClick: (el, step, { driver: tour }) => go(tour, tour.getActiveIndex() + 1),
        onPrevClick: (el, step, { driver: tour }) => go(tour, tour.getActiveIndex() - 1),
        onDoneClick: (el, step, { driver: tour }) => tour.destroy(),
        // Omitir en cada paso; si salió solo, también se pueden apagar todas las explicaciones.
        onPopoverRender: (popover, { driver: tour }) => {
            popover.footer.prepend(textButton('tour-skip', 'Omitir', () => tour.destroy()));

            if (auto) {
                const row = document.createElement('p');
                row.className = 'tour-skip-all';
                row.append(textButton('', 'No volver a mostrar explicaciones', () => {
                    markSeen('todas');
                    tour.destroy();
                    window.dispatchEvent(new CustomEvent('toast', {
                        detail: { message: 'Ya no saldrán solas. Usa el botón Tutorial de cada pantalla cuando quieras.', ms: 5000 },
                    }));
                }));
                popover.description.after(row);
            }
        },
        onDestroyed: () => {
            active = null;
            prepare(null);
            markSeen(key);
        },
    });

    await prepare(steps[0].data.open);
    active.drive(0);
}

window.startTour = startTour;

// El botón Tutorial de cada encabezado (data-tour-start) lo inicia, y también marca de qué pantalla es.
document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-tour-start]');
    if (button) startTour(button.dataset.tourStart);
});

// Ajustes puede pedir que todas las explicaciones vuelvan a salir.
window.addEventListener('tours-reset', () => seenNow.clear());

// Al llegar a una pantalla: si su recorrido no se ha visto (o la URL trae ?recorrido=), se muestra.
document.addEventListener('livewire:navigated', () => {
    const button = document.querySelector('[data-tour-start]');
    if (!button) return;

    const key = button.dataset.tourStart;
    const url = new URL(location.href);

    if (url.searchParams.get('recorrido') === key) {
        // Se limpia la dirección después: Livewire reescribe la URL al arrancar y volvía a poner ?recorrido=.
        setTimeout(() => {
            const clean = new URL(location.href);
            clean.searchParams.delete('recorrido');
            history.replaceState(history.state, '', clean);
            startTour(key);
        }, 400);
        return;
    }

    let saved = [];
    try {
        saved = JSON.parse(document.body.dataset.toursSeen || '[]');
    } catch (e) {}

    if (!saved.includes(key) && !seenNow.has(key)) {
        setTimeout(() => startTour(key, { auto: true }), 600);
    }
});

// Si se navega con el recorrido abierto (botón atrás), se cierra para no dejarlo a medias.
document.addEventListener('livewire:navigating', () => active?.destroy());
