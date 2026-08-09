# 🍳 Mi Cocina

Aplicación web para que una familia/casa organice **qué cocinar**: platillos con sus recetas, favoritos, historial de lo ya cocinado, plan de menú semanal y generación/ajuste de recetas con IA **sin alucinaciones** (grounding + validación estricta).

## Stack

- **PHP 8.2+ puro**, sin frameworks: funciones globales + controladores por módulo
- **Medoo** como query builder (nunca SQL concatenado a mano)
- **MariaDB/MySQL** (probado en MariaDB 12.1.2)
- **Alpine.js + Tailwind CSS** (por CDN, sin paso de build)
- **OpenAI** (Responses API con `web_search` o Chat Completions JSON) para recetas
- **PWA**: instalable en el celular (manifest + service worker)

## Estructura

Misma estructura que los demás proyectos de la casa:

```
mi_cocina/
├── index.php                  # Login y registro (punto de entrada; con sesión redirige al inicio)
├── manifest.json              # PWA
├── service-worker.js          # PWA
├── .htaccess                  # Protege .env, vendor/, sql/, storage/ y controladores internos
├── .env / .env.example        # Configuración (secretos SOLO aquí)
├── assets/                    # css, js (Alpine), iconos
├── sql/
│   └── migrate.sql            # Migrate único: esquema completo VACÍO (resetea la base)
├── controladores/
│   ├── ajax.php               # Endpoint AJAX central (JSON + CSRF)
│   ├── globales/
│   │   ├── funcionesPhp.php   # Conexión Medoo ($database), sesión, guardián de familia, CSRF, layout
│   │   ├── funcionesIA.php    # OpenAI: generar/sugerir/ajustar recetas + caché
│   │   └── login.php          # Lógica de login/registro/salir
│   └── individuales/
│       ├── inicio.php         # Recomendaciones y cierre de ciclo
│       ├── platillos.php      # CRUD de platillos, favoritos, validación
│       ├── recetas.php        # Recetas + validarReceta() (barrera anti-alucinación)
│       ├── historial.php      # "Lo hice hoy" e historial
│       ├── plan_semanal.php   # Planificador semanal
│       ├── familia.php        # Familias, código de invitación, miembros
│       └── lista_compras.php  # [Fase 2] lista de compras desde el plan
├── vistas/
│   ├── inicio/index.php       # Antojo del día, recomendaciones, plan de hoy, historial
│   ├── platillos/
│   │   ├── index.php          # Listado con filtros
│   │   ├── formulario.php     # Alta/edición (?id=)
│   │   ├── ver.php            # Detalle + receta + IA + modo cocina (?id=)
│   │   └── _tarjeta.php       # Parcial de tarjeta
│   ├── plan/index.php         # Cuadrícula semanal
│   ├── familia/index.php      # Código de invitación y miembros
│   └── error404.php
└── storage/                   # Caché de IA y logs (fuera de web por .htaccess)
```

## Instalación

```bash
cd mi_cocina

# 1. Dependencias (Medoo, phpdotenv, Guzzle)
composer install

# 2. Configuración
cp .env.example .env
# Edita .env: host/usuario/contraseña de tu MariaDB.
# Agrega tu OPENAI_API_KEY si quieres las funciones de IA (opcional).

# 3. Base de datos — estructura vacía, SIN registros.
#    sql/migrate.sql crea la base `mi_cocina` completa desde cero
#    (DROP DATABASE + CREATE DATABASE + tablas). Revisión y ejecución manual:
mysql -u root -p < sql/migrate.sql
```

El document root es la **raíz del proyecto** (como en los demás proyectos). Al abrir la app aparece el login: el primer usuario se registra, crea su familia y queda como `admin`; los demás se unen con el código de invitación (sección Familia).

## Funcionalidades

- **Platillos**: alta/edición con nombre, tono (dulce/salado/agridulce/picante/otro), tipo de comida, cocina, dificultad, tiempo, porciones y foto (URL).
- **Recetas**: captura manual (ingredientes + pasos), pueden quedar vacías, o generarse con IA.
- **Favoritos** por usuario (estrella en cada tarjeta).
- **"Lo hice hoy"**: registra en `historial_cocina` sin borrar nada; la tarjeta se marca visualmente como hecha.
- **Historial y cierre de ciclo**: cuando ya se cocinó todo, el inicio lo anuncia, muestra el último platillo hecho y recomienda rotar hacia lo que lleva más tiempo sin cocinarse (**sin repetir el de ayer**).
- **Antojo del día**: filtro rápido por tono en el inicio.
- **Plan semanal**: cuadrícula día × comida con guardado automático por celda.
- **Familias**: registro creando familia (rol `admin`) o uniéndose con código de invitación (rol `miembro`); el admin puede expulsar miembros y regenerar el código.
- **Modo cocina**: pasos en grande, temporizador integrado y pantalla siempre encendida (Wake Lock).
- **Modo oscuro** con toggle persistente. Interfaz 100% responsiva (mobile-first) y PWA instalable.

## IA sin alucinaciones

Flujo completo en `controladores/globales/funcionesIA.php` + `validarReceta()` en `controladores/individuales/recetas.php`:

1. **Grounding**: con `OPENAI_USE_WEB_SEARCH=true` se usa la Responses API con la herramienta `web_search`; la receta se basa en fuentes reales y regresa `url_fuente`. Sin búsqueda web, se exige limitarse a recetas tradicionales documentadas (y a responder `{"error":"desconocido"}` si el platillo no existe).
2. **Salida estructurada**: la IA responde **solo JSON** con el shape exacto de la tabla `recetas` (`ingredientes` `[{nombre, cantidad, unidad}]`, `pasos`, `porciones`, `tiempo_preparacion`, `notas`, `url_fuente`).
3. **Validación**: `validarReceta()` revisa campos, tipos y cantidades coherentes. **Si falla, no se persiste nada.**
4. **Marca de origen**: lo generado por IA se guarda con `generada_ia=1` y `verificada=0`; un humano lo revisa y pulsa "marcar verificada".
5. **Caché**: respuestas en `storage/cache/ia/` por hash del prompt, para no quemar tokens.

Casos implementados: generar receta por nombre del platillo, sugerir platillo según el tono del día ("Sorpréndeme"), y ajustar recetas ("hazla vegetariana", "para 6 personas", "más picante") con vista previa antes de guardar.

## Multi-familia y seguridad

- El guardián de familia (`filtroFamilia()` / `verificarFamiliaDeFila()` en `funcionesPhp.php`) inyecta el `id_familia` **de la sesión** en todas las consultas; una familia no puede ver ni tocar datos de otra ni manipulando ids (responde 404).
- Contraseñas con `password_hash()` / `password_verify()` (columna `usuarios.password`).
- Sesión con cookie `httponly` + `SameSite=Lax` + `secure` en producción, **regeneración de id al iniciar sesión**.
- **CSRF** obligatorio en todo POST (campo `_csrf` o cabecera `X-CSRF-Token`).
- Validación de entrada en todos los endpoints; salida escapada con `e()` en las vistas (anti-XSS).
- Roles: `admin` gestiona la familia y cualquier platillo; `miembro` solo lo suyo.
- Secretos únicamente en `.env`; `.htaccess` bloquea `.env`, `vendor/`, `sql/`, `storage/` y los controladores internos.
- Bitácora en `storage/logs/` vía `registroLog()` (sin datos sensibles).

## Base de datos

Esquema en español, con `COMMENT` en cada campo, **sin llaves foráneas** (solo índices `KEY`), tenant por `id_familia` y timestamps `sistema_creacion` / `sistema_actualizacion` — mismas convenciones que los demás proyectos de la casa.

Tablas MVP: `familias`, `usuarios`, `platillos`, `recetas`, `favoritos`, `historial_cocina`, `plan_semanal`.
Tablas Fase 2 (creadas desde ya): `preferencias`, `despensa`, `lista_compras`, `etiquetas`, `platillos_etiquetas`, `votos`.

## Producción (notas)

- Document root = raíz del proyecto, con PHP-FPM. En **nginx** replicar lo del `.htaccess`:

```nginx
location ~ /\. { deny all; }
location ~ ^/(vendor|sql|storage)/ { deny all; }
location ~ ^/controladores/(globales|individuales)/ { deny all; }
```

- `APP_ENV=prod` en `.env` (errores a `storage/logs/`, cookie `secure`).
- `storage/` necesita permisos de escritura para el usuario de PHP (caché de IA y logs).

## Fase 2 (preparada)

- `preferencias` (alergias/gustos/dietas): **ya se envían a la IA** al generar recetas.
- `despensa` + `lista_compras`: `lista_compras.php` ya calcula la lista del plan semanal restando la despensa (falta exponer la vista).
- `etiquetas`/`platillos_etiquetas` y `votos` ("qué se antoja" semanal): esquema listo.
- Pendientes documentados: porciones ajustables en la interfaz, importar receta desde URL, turnos de cocina, estadísticas, colecciones de favoritos y notificaciones.
