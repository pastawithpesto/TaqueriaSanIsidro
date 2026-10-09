# Taquería San Isidro

App web para llevar el control de una taquería pequeña: ventas, gastos e insumos, costo por taco y ganancia.

Laravel 13, Livewire 4, Tailwind 4, PostgreSQL 17. Todo corre en Docker.

## Desarrollo local

Requiere Docker con Compose.

```sh
git clone https://github.com/revmnds/TaqueriaSanIsidro.git
cd TaqueriaSanIsidro
export UID GID=$(id -g)
docker compose up -d --build
```

La primera vez crea `.env`, instala dependencias, genera la llave y corre las migraciones.

- App: http://localhost:8090
- Vite (HMR): puerto 5174
- PostgreSQL: puerto 5433 (usuario `taqueria`, password `secret`, base `taqueria`)

En local el seeder crea el usuario `admin@taqueria.test` con contraseña `password`. En producción:

```sh
docker compose exec app php artisan app:create-user
```

Los puertos se cambian en `.env` (`APP_PORT`, `VITE_PORT`, `FORWARD_DB_PORT`).

## Comandos útiles

```sh
docker compose exec app php artisan migrate
docker compose exec app php artisan test
docker compose exec app composer require <paquete>
docker compose logs -f app
```

## Módulos

- Vender: botones por producto, total, método de pago (efectivo, transferencia, tarjeta) y cambio.
- Ventas: historial por día, con cancelación.
- Gastos: compras de insumos, gas, desechables, etc. Una compra de insumo con cantidad actualiza el costo por unidad.
- Productos: precio, categoría y receta. Calcula costo y margen. Cada variedad (pastor, tripa) es un producto aparte.
- Insumos: catálogo con unidad y costo.
- Resumen: ventas, gastos, ganancia por receta y ventas menos gastos, por periodo, con comparativo y horas fuertes.
- Corte de caja: efectivo esperado contra contado.
- Ajustes: nombre y logo del negocio (se usan en el login, el menú y el ícono de la app instalable), y cambio de contraseña.

Cada venta guarda el costo de receta del momento, así el historial no cambia si luego sube la carne.

## Notas

- Agregar y quitar productos en Vender ocurre en el navegador; el servidor solo interviene al cobrar y recalcula todo con los precios de la base.
- El logo por defecto está en `public/images/brand/`. El que se sube desde Ajustes se guarda en `storage/app/private/branding/`.
- Detrás de un túnel o proxy HTTPS: `TRUSTED_PROXIES=*` y `APP_URL` con la URL pública.

## Pendiente

- Cobro con tarjeta (Mercado Pago). La orden ya guarda `payment_method` y `payment_reference`.
- Inventario y mermas.
