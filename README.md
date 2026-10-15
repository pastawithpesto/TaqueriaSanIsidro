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
- PostgreSQL: puerto 5433, solo desde este equipo (usuario `taqueria`, password `secret`, base `taqueria`)

En local el seeder crea el usuario `admin@taqueria.test` con contraseña `password`.

Los puertos se cambian en `.env` (`APP_PORT`, `VITE_PORT`, `FORWARD_DB_PORT`).

Los tests corren contra Postgres, en la base `taqueria_test`. Se crea sola con un volumen nuevo; si el volumen ya existía:

```sh
docker compose exec db createdb -U taqueria taqueria_test
```

## Producción

Se despliega como stack de Portainer desde este repo, detrás de Nginx Proxy Manager. `compose.prod.yaml` construye la imagen con el código, las dependencias sin las de desarrollo y los assets ya compilados; no publica puertos (el proxy entra por la red `npm`) y al arrancar corre las migraciones y `php artisan optimize`.

En Portainer, Stacks > Add stack > Repository:

- Repository URL: `https://github.com/revmnds/TaqueriaSanIsidro`, rama `refs/heads/main`.
- Compose path: `compose.prod.yaml`.
- Authentication: token de GitHub (el repo es privado).
- Environment variables:
  - `APP_KEY`: `echo "base64:$(openssl rand -base64 32)"`. No se cambia después.
  - `APP_URL`: la URL pública con https.
  - `DB_PASSWORD`: `openssl rand -base64 24`.

En Nginx Proxy Manager, un Proxy Host con el dominio que reenvía a `taqueria-web`, puerto 80, con certificado SSL.

Primer usuario, desde la consola del contenedor `taqueria-app` en Portainer:

```sh
php artisan app:create-user
```

El logo subido vive en el volumen `storage` y la base en `db-data`.

El contenedor `taqueria-backup` respalda la base una vez al día en el volumen `backups` y guarda los últimos 14 días (`BACKUP_KEEP_DAYS`). Están en el mismo servidor: de vez en cuando conviene bajar uno a otro lado.

```sh
# Ver los respaldos
docker exec taqueria-backup ls -lh /backups
# Bajar uno a esta carpeta
docker cp taqueria-backup:/backups/taqueria-2026-10-15.dump .
# Restaurar (borra lo que haya en la base)
docker exec -i taqueria-db pg_restore -U taqueria -d taqueria --clean --if-exists < taqueria-2026-10-15.dump
```

## Comandos útiles

```sh
docker compose exec app php artisan migrate
docker compose exec app php artisan test
docker compose exec app composer require <paquete>
docker compose logs -f app
```

## Módulos

- Vender: botones por producto, indicaciones por pieza ("sin cebolla" sale de la receta y descuenta su costo; extras con precio), nota libre y nombre del pedido. Se cobra de una vez o se guarda sin cobrar.
- Pedidos: cola de lo que falta entregar y de lo que falta cobrar, con tiempo de espera y hora de recogida. Los guardados sin cobrar de días anteriores van aparte. Pantalla de cocina para una tablet, solo con lo de hoy; si un pedido listo se edita, vuelve a la cocina.
- Combos con opciones para elegir (refresco), para llevar, aquí o a domicilio (dirección, teléfono, envío, accesos a llamar, WhatsApp y mapa), productos agotados por día.
- Propinas aparte de las ventas, descuentos, cortesías y pago mixto.
- Ventas: historial por día, con cancelación. Una orden cuenta el día en que se cobró, igual que en el corte y en Resumen.
- Gastos: compras de insumos, gas, desechables, etc. Una compra de insumo con cantidad actualiza el costo por unidad.
- Productos: precio, categoría, receta (marcando lo que se puede pedir sin) y extras. Calcula costo y margen. Cada variedad (pastor, tripa) es un producto aparte. Se acomodan arrastrando, en el mismo orden que salen en Vender. Un producto con ventas no se borra, se oculta.
- Insumos: catálogo con unidad y costo. Si un insumo en uso pasa de kg a g (o de lt a ml), recetas y compras se convierten solas.
- Resumen: ventas, gastos, ganancia por receta y ventas menos gastos, por periodo, con comparativo y horas fuertes.
- Corte de caja: efectivo esperado contra contado, con entradas y retiros de efectivo. Al cerrar se anota cuánto se queda de fondo; es el fondo inicial del día siguiente.
- Lista de compras: sugerencia por insumo según lo usado los mismos días de semanas anteriores.
- Ajustes: nombre y logo del negocio (se usan en el login, el menú y el ícono de la app instalable), hora en que termina el día de trabajo (si se cierra después de medianoche, lo de madrugada cuenta en el día que se abrió) y cambio de contraseña.
- Explicaciones: cada pantalla tiene un recorrido guiado (driver.js) que resalta sus partes. Sale sola la primera vez (se puede omitir o apagar) y se repite con el botón Tutorial del encabezado. Los pasos y textos están en `resources/js/tours.js`.

Cada venta guarda el costo de receta del momento, así el historial no cambia si luego sube la carne.

## Notas

- Agregar y quitar productos en Vender ocurre en el navegador; el servidor solo interviene al cobrar y recalcula todo con los precios de la base. El pedido en curso se guarda en el navegador (`localStorage`), así sobrevive a una recarga o a una sesión vencida.
- El logo por defecto está en `public/images/brand/`. El que se sube desde Ajustes se guarda en `storage/app/private/branding/`.
- Detrás de un túnel o proxy HTTPS: `TRUSTED_PROXIES=*` y `APP_URL` con la URL pública.

## Datos de ejemplo

`php artisan db:seed --class=DemoRecipesSeeder` carga insumos, recetas, extras y opciones de ejemplo para los productos de la taquería.

## Pendiente

- Cobro con tarjeta (Mercado Pago). La orden ya guarda `payment_method` y `payment_reference`.
- Inventario y mermas.
