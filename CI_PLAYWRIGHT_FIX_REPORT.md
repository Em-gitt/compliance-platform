# Informe de Corrección de CI: Habilitación de Playwright en GitHub Actions

**Fecha:** 19 de noviembre de 2026  
**Rama:** `integration/sprints-3-4`  
**Commit de la corrección:** `a9bb586` (`ci: configure Playwright webServer and setup backend and frontend dependencies in CI`)  
**Estado:** Corrección verificada localmente y lista para su ejecución remota en GitHub Actions.

---

## 1. Problema Original

La auditoría técnica independiente y el diagnóstico de integración detectaron un defecto estructural en el workflow de CI (`.github/workflows/ci.yml`):

1. **Ausencia de servidores en ejecución:** El job `e2e` ejecutaba `npx playwright test --project=chromium` contra `http://localhost:5173`. Al ejecutarse en una máquina virtual de runner aislada e independiente de los jobs anteriores (`frontend` y `backend`), ni Laravel (puerto 8000) ni Vite (puerto 5173) estaban en ejecución.
2. **Falta de directiva `webServer` en Playwright:** `playwright.config.ts` no tenía declarada la propiedad `webServer`, por lo que Playwright asumía que los servidores ya se encontraban activos. Al no responder los puertos, las pruebas fallaban por `ERR_CONNECTION_REFUSED`.
3. **Falta de inicialización de dependencias y base de datos en el runner de E2E:** El job `e2e` no instalaba las dependencias de `frontend` (`node_modules` en `frontend/`), no configuraba PHP 8.3 para Laravel, no creaba la base de datos SQLite ni sembraba los usuarios de prueba (`DemoSeeder`), necesarios para que `loginAs` funcione.
4. **Variables de entorno de Sanctum en `.env.example`:** `backend/.env.example` no definía `SANCTUM_STATEFUL_DOMAINS` ni `DOCUMENT_ENCRYPTION_KEY`, y tenía `SESSION_SECURE_COOKIE=true`, lo que impedía que una aplicación servida sobre HTTP en CI pudiera emitir y mantener cookies de sesión SPA.

---

## 2. Archivos Modificados

| Archivo | Tipo de Cambio | Justificación |
|---|---|---|
| [playwright.config.ts](file:///home/erangel/Desktop/uni/9no/compliance-platform/playwright.config.ts) | Modificado | Incorporación de la directiva `webServer` dual para auto-arranque y apagado de Laravel y Vite. |
| [.github/workflows/ci.yml](file:///home/erangel/Desktop/uni/9no/compliance-platform/.github/workflows/ci.yml) | Modificado | Configuración de PHP 8.3, SQLite, dependencias de frontend y base de datos con `DemoSeeder` en el job `e2e`. |
| [backend/.env.example](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/.env.example) | Modificado | Inclusión de `SANCTUM_STATEFUL_DOMAINS`, `DOCUMENT_ENCRYPTION_KEY` y `SESSION_SECURE_COOKIE=false` para entornos HTTP locales/CI. |

---

## 3. Explicación Técnica de la Solución

### 3.1. Orquestación Nativa con `webServer` en Playwright
En lugar de depender de scripts frágiles de shell con esperas manuales (`sleep` o bucles `curl`), se aprovechó el soporte nativo de `@playwright/test` para múltiples servidores web (`webServer: [...]`):

1. **Backend (Laravel):**
   - Comando: `php artisan serve --host=127.0.0.1 --port=8000`
   - Directorio de trabajo: `backend`
   - URL de sondeo (*readiness probe*): `http://127.0.0.1:8000/sanctum/csrf-cookie` (responde HTTP 204 de forma instantánea sin requerir servicios externos de IA).
   - Reutilización local: `reuseExistingServer: !process.env.CI` (si el desarrollador ya tiene levantado Laravel, no lo duplica; en CI, arranca un proceso limpio).
   - Timeout: 120,000 ms.

2. **Frontend (Vite / React):**
   - Comando: `npm run dev`
   - Directorio de trabajo: `frontend`
   - URL de sondeo: `http://localhost:5173`
   - Reutilización local: `reuseExistingServer: !process.env.CI`
   - Timeout: 120,000 ms.

Al finalizar las pruebas (tanto con éxito como con fallo), Playwright envía las señales de terminación a los procesos que él mismo inició, liberando los puertos 8000 y 5173 sin dejar procesos huérfanos.

### 3.2. Aprovisionamiento del Runner en GitHub Actions
En `.github/workflows/ci.yml`, el job `e2e` ahora:
- Instala PHP 8.3 con extensiones SQLite (`shivammathur/setup-php@v2`).
- Ejecuta `composer install`, copia `.env.example`, genera la clave de aplicación, crea `database/database.sqlite`, ejecuta migraciones y corre `php artisan db:seed --class=DemoSeeder`.
- Instala dependencias de Node en la raíz (`npm ci`) y en el frontend (`npm ci` en `frontend/`).
- Instala el binario del navegador con dependencias (`npx playwright install --with-deps chromium`).
- Ejecuta Playwright en modo CI con un único worker (`workers: process.env.CI ? 1 : undefined`), garantizando que no se exceda el limitador de tasa de inicio de sesión (`throttle:10,1`).

---

## 4. Comandos Ejecutados y Evidencia de Validación

### 4.1. Validación Sintáctica del Workflow YAML
- **Comando:**
  ```bash
  ai-service/.venv/bin/python -c "import yaml; data = yaml.safe_load(open('.github/workflows/ci.yml')); print('YAML SYNTAX VALID. Jobs:', list(data['jobs'].keys()))"
  ```
- **Resultado:**
  ```text
  YAML SYNTAX VALID. Jobs: ['frontend', 'backend', 'ai-service', 'e2e']
  ```

### 4.2. Validación de Configuración de Playwright
- **Comando:**
  ```bash
  npx playwright test --list
  ```
- **Resultado:**
  ```text
  Listing tests:
  Total: 85 tests in 4 files
  (Configuración analizada e interpretada sin advertencias ni errores).
  ```

### 4.3. Compilación de Producción del Frontend
- **Comando:**
  ```bash
  cd frontend && npm run build
  ```
- **Resultado:**
  ```text
  tsc -b && vite build
  ✓ 857 modules transformed.
  ✓ built in 3.96s (Exit code 0, 0 errores).
  ```

### 4.4. Validación de Auto-Arranque, Pruebas y Apagado en Chromium
Se verificó que los puertos 8000 y 5173 estuvieran cerrados antes de ejecutar:
- **Comando:**
  ```bash
  npx playwright test e2e/browser-matrix.spec.ts --project=chromium
  ```
- **Resultado:**
  ```text
  Running 5 tests using 5 workers
    ✓ Sign in, user management, and sign out (4.4s)
    ✓ Compliance dashboard and alerts bell (4.6s)
    ✓ Requirements browsing and status filters (4.3s)
    ✓ Document upload, search filters, and version history (4.6s)
    ✓ AI Assistant with citations and feedback widget (3.9s)
  5 passed (6.7s)
  ```
- **Comprobación de puertos tras la prueba:** `lsof -i :8000 -i :5173` $\rightarrow$ Vacío (servidores apagados limpiamente).

### 4.5. Validación en Firefox
- **Comando:**
  ```bash
  npx playwright test e2e/browser-matrix.spec.ts --project=firefox
  ```
- **Resultado:**
  ```text
  Running 5 tests using 5 workers
    ✓ 5 passed (11.5s)
  ```
- **Comprobación de puertos tras la prueba:** `lsof -i :8000 -i :5173` $\rightarrow$ Vacío.

---

## 5. Limitaciones Pendientes

1. **Ejecución Real en los Servidores de GitHub:** Las validaciones de Playwright y `webServer` fueron ejecutadas localmente en este entorno Linux. La ejecución definitiva dentro de la infraestructura de GitHub Actions ocurrirá automáticamente cuando la rama sea publicada mediante `git push` o al abrir el Pull Request correspondiente.
2. **WebKit en el Entorno Local:** Como se documentó en las auditorías previas, WebKit requiere paquetes dinámicos del sistema operativo (`libicu74`, `libjpeg-turbo8`) que requieren privilegios de superusuario (`sudo apt-get install`). En el pipeline de GitHub Actions se ejecuta Chromium (`--project=chromium`), que es el objetivo configurado en el workflow.

---

## 6. Confirmación de Reglas de Integridad Git

- **Rama actual:** `integration/sprints-3-4`.
- **Ramas distintas modificadas:** **Ninguna.** (`develop` y `origin/develop` permanecen estrictamente inalteradas).
- **Push / Merge:** **No se ejecutó ningún push ni merge.**
- **Commits originales de Jira:** Los 22 commits de KAN-121 a KAN-144 se conservan intactos.
- **Commit generado:** Commit `a9bb586` en la rama de integración, encapsulando exclusivamente las correcciones de CI y Playwright.
