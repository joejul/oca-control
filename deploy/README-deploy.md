# Despliegue en Hostinger (`ocasocafebar.com/control`)

Requisitos del hosting: PHP 8.1+ con PDO MySQL, `ext-dom` y `ext-mbstring` (los usa dompdf para
el PDF del cierre), MySQL/MariaDB, Apache con `mod_rewrite`.
No toca el React app que ya está en la raíz del dominio.

## 1. Base de datos

1. hPanel → **Bases de datos MySQL** → crear base + usuario (anotar nombre, usuario, contraseña).
2. hPanel → **phpMyAdmin** → seleccionar la base → pestaña **Importar** →
   subir `api/migrations/001_init.sql`.

## 2. Construir el front

En tu máquina:

```bash
cd web
npm ci
npm run build
```

Queda en `web/dist/` (`index.html` + `assets/`).

## 3. Subir archivos (FTP / Administrador de archivos)

Crear `public_html/control/` y dejarlo así:

```
public_html/control/
  .htaccess              <- deploy/htaccess-control
  index.html             <- de web/dist/
  assets/                <- de web/dist/
  api/
    bootstrap.php
    composer.json
    src/                 <- todo el código PHP
    migrations/
    public/index.php
    config.php           <- deploy/config.production.php con tus credenciales
    vendor/              <- ver paso 4
```

- Copiá `deploy/htaccess-control` → `public_html/control/.htaccess`.
- Copiá `deploy/config.production.php` → `public_html/control/api/config.php` y completá
  las credenciales de la base.
- **No** subas `api/config.php` local, `node_modules/`, ni `web/` completo.

## 4. Dependencias PHP (`vendor/`)

El proyecto usa `dompdf/dompdf` para generar el PDF del cierre, así que ahora sí hace falta
generar y subir `vendor/`:

```bash
cd api
composer install --no-dev --optimize-autoloader
```

y subir la carpeta `api/vendor/` resultante. Si el hosting tiene Composer por SSH, se puede
correr allá. **Sin `vendor/` subido, el resto de la app funciona (usa el autoloader mínimo de
`src/autoload.php`), pero el botón "Descargar PDF" del cierre fallará con error 500.**

## 5. Crear el admin

Opción A (SSH): `php api/bin/seed.php` una sola vez.
Opción B (sin SSH): en phpMyAdmin, insertá un usuario admin. Generá el hash con este
snippet en cualquier PHP local:

```php
echo password_hash('TU_PIN_O_CLAVE', PASSWORD_BCRYPT);
```

```sql
INSERT INTO users (username, display_name, role, credential_hash)
VALUES ('joe', 'Joe', 'admin', '<hash generado>');
```

Después entrás a `https://ocasocafebar.com/control/`, creás los colaboradores desde
**Usuarios** y cambiás tu propio PIN.

## 6. Verificar

- `https://ocasocafebar.com/control/` carga el login.
- `https://ocasocafebar.com/control/api/me` responde `{"error":"No autenticado"}` (401) — eso
  confirma que la API enruta bien.
- Login como admin → crear un colaborador → probar registro de horas y consumo.

## 7. Respaldos

- hPanel permite backups automáticos; además, **antes de cada cierre** exportá la base
  (phpMyAdmin → Exportar) o corré `mysqldump` por SSH/cron.
- Un cron semanal recomendado:
  `mysqldump -u USER -pPASS BASE | gzip > ~/backups/oca-$(date +\%F).sql.gz`

## Actualizaciones futuras

- Front: `npm run build` y reemplazar `index.html` + `assets/`.
- Back: reemplazar `api/src/` y correr las migraciones nuevas (`api/bin/migrate.php` por SSH,
  o importar el `.sql` por phpMyAdmin).
