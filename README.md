# OCA · Control de horas extra y consumos

App interna para OCA Só Café Bar. Reemplaza el control en papel de:

- **Horas extra** de cada colaborador (las ingresa cada quien con usuario + PIN; el admin
  aprueba o rechaza).
- **Consumos del bar** (un colaborador registra el consumo de **otro**, nunca el propio).

Los registros son **inmutables**: se pide confirmación con los datos antes de guardar y no se
pueden editar después. Solo el admin puede **anular** (queda en la base para auditoría, no se
borra). Cuando el admin genera un **cierre**, los registros de ese rango se archivan y salen
del período abierto; queda el reporte (resumen por colaborador + CSV para Excel + versión
imprimible).

## Stack

| Capa      | Tecnología                                  |
|-----------|---------------------------------------------|
| Frontend  | React + Vite + TypeScript (SPA en `/control`) |
| Backend   | PHP 8.1+ sin framework (router propio + PDO), API REST JSON |
| Base      | MySQL 8 / MariaDB                            |
| Local     | ddev (Docker)                               |

## Correr en local

Requisitos: [ddev](https://ddev.readthedocs.io/), Docker, Node 20+.

```bash
# 1. Backend + base de datos
ddev start
ddev composer install -d /var/www/html/api
cp api/config.php.example api/config.php        # ya viene apuntando a la base de ddev
ddev exec php api/bin/migrate.php
ddev exec php api/bin/seed.php                  # crea admin "joe" (PIN 09111992) + colaboradores

# 2. Frontend
cd web
npm install
npm run dev            # http://localhost:5173/control/
```

El dev server de Vite hace proxy de `/control/api` → `https://oca-control.ddev.site`.

### Usuarios de ejemplo (seed)

`api/bin/seed.php` crea el admin `joe` y los colaboradores del equipo con sus PIN. Los PIN
nuevos (creación o "Cambiar PIN" desde **Usuarios**) deben tener exactamente 5 caracteres:
1 letra + 4 números (p. ej. `a1234`) — ver los valores reales en el propio `seed.php`, que no
se documentan aquí por ser datos sensibles. El PIN del admin `joe` es una excepción histórica
(`091192`, fijado directo en la base) que no sigue este formato porque el login no valida
formato, solo el hash.

> Cambiá todos los PIN antes de usar en producción.

## Tests

```bash
ddev exec 'cd /var/www/html/api && vendor/bin/phpunit'   # backend (validaciones)
cd web && npm test                                       # frontend (ConfirmDialog, formato)
```

## Estructura

```
api/                PHP: src/ (Kernel, Router, Auth, Controllers…), migrations/, bin/, tests/
web/                React: src/pages/{collab,admin}, components/, api.ts, auth.tsx
deploy/             .htaccess de producción, config de producción, guía de despliegue
.ddev/              config de ddev
```

## Despliegue

Ver [`deploy/README-deploy.md`](deploy/README-deploy.md). Resumen: build del front a
`public_html/control/`, código PHP en `public_html/control/api/`, base MySQL desde hPanel,
`.htaccess` de `deploy/`.

## Notas de diseño

- **Inmutabilidad**: la API no expone ningún endpoint de edición de registros. Solo alta,
  aprobación/rechazo (admin) y anulación (admin).
- **Cierres a demanda**: sin quincenas fijas. El rango por defecto va del último cierre a hoy.
  Las horas extra **pendientes** no se archivan: quedan para el próximo cierre.
- **Seguridad**: PIN con bcrypt, bloqueo tras 5 intentos fallidos, tokens hasheados con
  vencimiento, todas las marcas de tiempo las pone el servidor, zona horaria CR.
- **Auditoría**: `audit_log` registra login, aprobaciones, rechazos, anulaciones, alta de
  usuarios y generación de cierres.
