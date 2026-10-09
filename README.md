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

Los puertos se cambian en `.env` (`APP_PORT`, `VITE_PORT`, `FORWARD_DB_PORT`).

## Comandos útiles

```sh
docker compose exec app php artisan migrate
docker compose exec app php artisan test
docker compose exec app composer require <paquete>
docker compose logs -f app
```

## Estado

En construcción. Módulos planeados: ventas, gastos e insumos, productos con costeo por receta, reportes.
