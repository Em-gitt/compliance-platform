# Informe Final de Corrección E2E: Rate Limiting y Microservicio IA

## 1. Resumen Ejecutivo

En cumplimiento con los requerimientos técnicos para resolver las fallas de la suite E2E en Playwright Chromium, se implementaron soluciones integrales para los dos problemas identificados:
1. **Rate Limiting en Autenticación (`throttle:10,1`)**: Colisiones artificiales por ráfagas de pruebas E2E desde `127.0.0.1`.
2. **Orquestación del Microservicio IA (FastAPI en `:8001`)**: Levantamiento y apagado automático durante la ejecución de pruebas E2E en local y GitHub Actions.

Tras la aplicación de las soluciones, la suite completa de Playwright en Chromium alcanzó una aprobación perfecta:
- **Total de pruebas**: 17
- **Aprobadas**: 17 (100%)
- **Fallidas**: 0 (0%)
- **Omitidas**: 0 (0%)
- **Tiempo total de ejecución**: 29.2 segundos

---

## 2. Causas Raíz Identificadas

### 2.1 Rate Limiting en Autenticación
- **Mecanismo**: `Route::post('/auth/login')` utilizaba el middleware estático `throttle:10,1`, restringiendo a 10 peticiones por minuto por IP (`127.0.0.1`).
- **Problema en E2E**: Las pruebas Playwright ejecutan 16 inicios de sesión automatizados en menos de 20 segundos. Al llegar a la prueba 11 (`uc-002 main flow`), Laravel respondía con código HTTP 429 (`"Too many sign-in attempts. Try again in 44 seconds."`), bloqueando los tests siguientes.
- **Compartición de límites**: Todas las pruebas y roles (`administrator`, `compliance_staff`, `general_employee`) compartían la misma dirección IP `127.0.0.1` en el entorno local/runner de CI.

### 2.2 Microservicio IA No Orquestado
- **Mecanismo**: El módulo de asistente (`/assistant` y `e2e/uc-002.spec.ts`) consulta al backend Laravel, el cual llama vía HTTP a `AI_SERVICE_URL` (`http://127.0.0.1:8001/v1/ask`).
- **Problema en E2E**: El archivo `playwright.config.ts` únicamente iniciaba Laravel (`:8000`) y Vite (`:5173`), omitiendo el microservicio FastAPI. Ante la ausencia de un servidor escuchando en el puerto 8001, Laravel retornaba HTTP 503 (`"The AI assistant is not available right now. Please try again later."`) y no se generaba la burbuja de respuesta (`[data-testid="answer-bubble"]`).

### 2.3 Estructura HTML en Lista de Requisitos (`uc-003`)
- **Problema**: `e2e/uc-003.spec.ts` esperaba un elemento semántico `table` con filas `tbody tr`. La implementación visual de `RequirementsListPage.tsx` utilizaba tarjetas en un contenedor `div grid`, provocando fallos por tiempo de espera en el localizador `table`.

---

## 3. Soluciones Implementadas

### 3.1 Rate Limiting Aislado y Seguro (H-01)
- **Named Rate Limiter**: En `backend/routes/api.php`, se reemplazó `throttle:10,1` por `throttle:login`.
- **Protección Irrompible en Producción**: En `backend/app/Providers/AppServiceProvider.php`, se definió el limitador `login`:
  ```php
  RateLimiter::for('login', function (Request $request) {
      if ($this->app->environment('production')) {
          return Limit::perMinute(10)->by($request->ip());
      }

      $maxAttempts = (int) config('auth.login_rate_limit', env('AUTH_RATE_LIMIT', 10));

      return Limit::perMinute($maxAttempts)->by($request->ip());
  });
  ```
  - **Inmunidad en Producción**: Si `APP_ENV=production`, el límite estricto de 10 intentos por minuto es incondicional y forzado en código. Cualquier variable de entorno o configuración permisiva es ignorada de manera estricta.
  - **Flexibilidad en Testing/Local**: Permite configurar `AUTH_RATE_LIMIT=100` exclusivamente en los servidores de pruebas E2E.
- **Configuración de Testing PHPUnit**: En `backend/phpunit.xml`, se fijó `<env name="AUTH_RATE_LIMIT" value="10"/>`, garantizando que todas las pruebas unitarias y de características de Laravel sigan validando el comportamiento original de protección contra fuerza bruta (10 intentos seguidos de 429).
- **Prueba Unitaria Específica**: Se añadió un test en `backend/tests/Feature/AuthenticationTest.php` que comprueba formalmente que en entorno `production` el límite es 10 incluso si se intenta configurar un valor superior.

### 3.2 Autoarranque del Microservicio IA en Playwright y CI (H-02)
- **Configuración `webServer` en Playwright**: En `playwright.config.ts`, se incorporó el microservicio IA al arreglo de servidores:
  ```ts
  {
    command: 'if [ -d .venv ]; then .venv/bin/uvicorn app.main:app --host 127.0.0.1 --port 8001; else python3 -m uvicorn app.main:app --host 127.0.0.1 --port 8001; fi',
    cwd: 'ai-service',
    url: 'http://127.0.0.1:8001/openapi.json',
    reuseExistingServer: !process.env.CI,
    timeout: 120_000,
    env: {
      APP_ENV: 'testing',
      LLM_PROVIDER: 'mock',
      EMBEDDING_PROVIDER: 'mock',
      AI_SERVICE_TOKEN: 'compliance_ai_secret_token',
    },
  }
  ```
- **Readiness Check**: Playwright verifica que `http://127.0.0.1:8001/openapi.json` responda con código HTTP 200 antes de lanzar las pruebas.
- **Alineación con GitHub Actions**: En `.github/workflows/ci.yml`:
  - Se agregó `actions/setup-python@v5` (Python 3.12) e instalación de `requirements.txt` en el job `e2e`.
  - Se configuró `sed` para apuntar `AI_SERVICE_URL=http://127.0.0.1:8001` y `AUTH_RATE_LIMIT=100` en el `.env` del runner.
  - Se encadenó `e2e` como dependiente de `[frontend, backend, ai-service]`.

### 3.3 Tabla Semántica de Requisitos (`uc-003`)
- En `frontend/src/pages/RequirementsListPage.tsx`, se transformó el renderizado a una tabla HTML semántica con cabeceras (`thead`), filas (`tbody tr`), enlaces directos y badges de estado y documentos faltantes, garantizando total compatibilidad con los localizadores de `e2e/uc-003.spec.ts` y manteniendo el diseño visual responsivo.
- Se corrigió la tipificación en `frontend/src/pages/DocumentUploadPage.tsx` importando `FileRejection` de `react-dropzone` para garantizar builds de producción impecables (`tsc -b && vite build`).

---

## 4. Archivos Modificados

| Archivo | Naturaleza del Cambio |
| :--- | :--- |
| `.github/workflows/ci.yml` | Configuración de Python 3.12, dependencias `ai-service`, y variables de entorno E2E en CI |
| `backend/app/Providers/AppServiceProvider.php` | Registro del limitador seguro `RateLimiter::for('login')` con salvaguarda en producción |
| `backend/config/auth.php` | Mapeo de `login_rate_limit` hacia `AUTH_RATE_LIMIT` con fallback seguro de 10 |
| `backend/phpunit.xml` | Definición de `AUTH_RATE_LIMIT=10` para suites PHPUnit |
| `backend/routes/api.php` | Migración de ruta `/auth/login` a middleware `throttle:login` |
| `backend/tests/Feature/AuthenticationTest.php` | Prueba de verificación de límite inmutable en producción |
| `backend/.env.example` | Declaración de `AUTH_RATE_LIMIT=10` en plantilla base |
| `frontend/src/pages/DocumentUploadPage.tsx` | Tipado estricto `FileRejection` para build de TypeScript |
| `frontend/src/pages/RequirementsListPage.tsx` | Renderizado de tabla semántica `table/tbody/tr` para localizadores E2E |
| `playwright.config.ts` | Autoarranque de `ai-service` en `webServer` y variable `AUTH_RATE_LIMIT=100` para backend |

---

## 5. Resultados Reales de Validación (H-03)

### 5.1 Pruebas Backend de Rate Limiting
```bash
$ php artisan test --filter=AuthenticationTest
PASS  Tests\Feature\AuthenticationTest
✓ it returns the exact validation messages
✓ it rejects wrong credentials with the exact message
✓ it rejects inactive users with the exact message
✓ it logs in an active user with an argon2id hash and records the security audit log
✓ it returns the authenticated user and logs out
✓ it returns the exact unauthenticated message for protected endpoints
✓ it keeps health public and rate limits login attempts with the exact message
✓ it strictly ignores AUTH_RATE_LIMIT in production environment

Tests: 8 passed (42 assertions)
```
*Suite completa de backend*: 450 pruebas pasadas (1965 aserciones).

### 5.2 Prueba Individual `uc-001`
```bash
$ npx playwright test e2e/uc-001.spec.ts --project=chromium
5 passed (10.8s)
```

### 5.3 Prueba Individual `uc-002`
```bash
$ npx playwright test e2e/uc-002.spec.ts --project=chromium
3 passed (8.9s)
```

### 5.4 Prueba Individual `uc-003`
```bash
$ npx playwright test e2e/uc-003.spec.ts --project=chromium
4 passed (9.0s)
```

### 5.5 Suite Completa Playwright Chromium
```bash
$ npx playwright test --project=chromium
Running 17 tests using 1 worker

✓ 1. Sign in, user management, and sign out (1.9s)
✓ 2. Compliance dashboard and alerts bell (1.5s)
✓ 3. Requirements browsing and status filters (2.2s)
✓ 4. Document upload, search filters, and version history (1.5s)
✓ 5. AI Assistant with citations and feedback widget (1.3s)
✓ 6. UC-001 main flow (1.9s)
✓ 7. UC-001 alt 1: rejected oversized or unsupported or executable file (1.4s)
✓ 8. UC-001 alt 2: missing required metadata (1.4s)
✓ 9. UC-001 alt 3: scanned PDF without selectable text uses OCR (1.4s)
✓ 10. UC-001 alt 4: processing failure marks status as Processing Failed (1.3s)
✓ 11. UC-002 main flow (1.8s)
✓ 12. UC-002 alt 1: insufficient information response (1.7s)
✓ 13. UC-002 alt 2: citation to compliance_only document denies access (1.3s)
✓ 14. UC-003 main flow (1.7s)
✓ 15. UC-003 alt 1: missing required information (1.8s)
✓ 16. UC-003 alt 2: required document missing identified (1.4s)
✓ 17. UC-003 alt 3: notification period alert generated (1.4s)

17 passed (29.2s)
```

### 5.6 Build de Frontend
```bash
$ npm --prefix frontend run build
✓ built in 2.97s (0 errors)
```
*Suite de Vitest frontend*: 156 pruebas pasadas (19 archivos).

### 5.7 Comprobación de Apagado Limpio de Procesos
```bash
$ lsof -i :8000 -i :8001 -i :5173
Exit code: 1 (Sin puertos escuchando ni procesos remanentes)
```

---

## 6. Estado de Git y Cumplimiento de Restricciones (H-04)

- **Commit `74f3c5d`**: Permanece intacto en el historial.
- **Ramas base**:
  - `develop` permanece intacta en `0bbc027`.
  - `origin/develop` permanece intacta en `23b8b5c`.
- **Ramas ajenas**: No se modificaron ramas distintas de `integration/sprints-3-4`.
- **Push / Merge**: No se ejecutó ningún `push` ni `merge`.
