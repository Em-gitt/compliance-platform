# Informe de Auditoría Técnica Independiente — Rama integration/sprints-3-4 (HEAD 745c990)

**Fecha:** 20 de Noviembre de 2026  
**Auditor:** Auditor Técnico Senior Independiente  
**Objetivo:** Auditar objetivamente el estado técnico, seguridad, integridad Git y reproducibilidad de la rama `integration/sprints-3-4` en el commit `745c990ffce4629141448060d0b96ab5d041b754`.

---

## Dictamen de Auditoría: APROBADO CON RESERVAS

> [!IMPORTANT]
> **Resumen del Dictamen**:  
> La implementación en `745c990` resuelve de forma integral y rigurosa los fallos previamente reportados en la suite E2E de Playwright (alcanzando 17/17 pruebas aprobadas en Chromium). El mecanismo de rate limiting está blindado contra relajaciones en producción mediante código determinista, la orquestación del microservicio IA es completamente autónoma y funcional, y la integridad de la historia de Git (22 commits originales Jira y 5 ramas de funcionalidad) se encuentra intacta.  
> Se otorga dictamen **APROBADO CON RESERVAS** debido a que ciertas herramientas accesorias (k6, OWASP ZAP, librerías de sistema WebKit y el contenedor PostgreSQL/pgvector para 20 pruebas de almacenamiento de fragmentos) son externas al runner local y solo son verificables bajo infraestructura especializada o en los jobs correspondientes de CI.  
> **Recomendación para Integración**: La rama es **técnicamente segura y apta para fusionarse a `develop`**.

---

## 1. Resumen Ejecutivo

| Parámetro | Estado Auditado |
| :--- | :--- |
| **Rama Activa** | `integration/sprints-3-4` |
| **HEAD Actual** | `745c990ffce4629141448060d0b96ab5d041b754` (`745c990`) |
| **Commits Recientes** | `745c990` -> `74f3c5d` -> `a9bb586` -> `844ee41` (todos presentes y verificados) |
| **Commits Jira Sprints 3-4** | 22 de 22 commits originales preservados con sus hashes y metadatos intactos |
| **Ramas Funcionales** | CMP-14, CMP-15, CMP-16, CMP-17 y CMP-39 integradas |
| **Ramas Base** | `develop` (`0bbc027`) y `origin/develop` (`23b8b5c`) inalteradas; `origin/develop` es ancestro directo |
| **Working Tree** | Limpio (sin cambios pendientes ni archivos sobreescritos) |
| **Suite Backend (Laravel/Pest)** | **450 pasadas**, 0 fallidas (1965 aserciones, 203.77s) |
| **Suite Frontend (Vitest)** | **156 pasadas**, 0 fallidas (19 archivos, 7.85s) |
| **Build Frontend (TypeScript/Vite)** | Exitoso en 3.73s (0 errores de compilación) |
| **Suite Playwright (Chromium)** | **17 pasadas**, 0 fallidas, 0 omitidas (29.1s) |
| **Matriz Navegadores (Firefox)** | **5 pasadas**, 0 fallidas (15.1s en `browser-matrix.spec.ts`) |
| **Suite IA (Pytest)** | **99 pasadas**, 20 omitidas (por ausencia de PostgreSQL pgvector local) |

---

## 2. Integridad Git

### 2.1 Verificación de Commits Recientes
Se comprobó la existencia y cadena de ancestría de los últimos commits:
- `745c990`: `fix(e2e): resolve auth rate limiting, auto-start AI microservice, and align requirements table (H-01, H-02)`
- `74f3c5d`: `fix(ci): sanitize encryption key, secure session cookies, and serialize e2e test execution (H-01, H-02, H-03, H-05)`
- `a9bb586`: `ci: configure Playwright webServer and setup backend and frontend dependencies in CI`

Ningún commit fue enmendado ni reescrito en el historial.

### 2.2 Preservación de los 22 Commits Originales Jira (KAN-121 a KAN-144)
Se auditó la presencia de cada uno de los 22 commits fundacionales:
1. `8d974f7` KAN-129: Account lockout after 5 failed logins
2. `cac7c36` KAN-130: 30-minute inactive session timeout
3. `55a2123` KAN-124: Security audit logging and audit-record protection
4. `6da7407` KAN-121: Document version history
5. `104b373` KAN-133: OCR for scanned PDFs
6. `aea4138` KAN-125: Automated daily database backups with monitoring
7. `75559c8` KAN-137: 30-day backup retention and test restoration
8. `8c3ebce` KAN-139: Availability monitoring and reporting
9. `36b26fc` KAN-127: Permission-aware retrieval
10. `5795eba` KAN-131: Grounded answer generation and insufficient-information response
11. `0645b5e` KAN-134: Source citations in AI answers
12. `633d787` KAN-138: AI evaluation set and run evaluation
13. `f0a8f71` KAN-126: Missing-document detection
14. `fc04ee7` KAN-132: In-application alerts for deadlines and expirations
15. `77327bc` KAN-128: Compliance dashboard
16. `6ecbeae` KAN-135: Search and filtering for documents and requirements
17. `94db8e8` KAN-136: Citation viewer with authorization check
18. `d971a25` KAN-143: Feedback on AI answers
19. `9e96e13` KAN-140: Security and access-control automated test suite
20. `5060c6d` KAN-141: Performance and load test suite for ADR-031 profile
21. `1c621fe` KAN-142: End-to-end user journey test suite
22. `090946d` KAN-144: Multi-browser and viewport compatibility test suite

*Resultado*: **22/22 presentes e inalterados**.

### 2.3 Fusión de Ramas Funcionales
Se verificaron los merges de las ramas de características:
- `6a8dc35`: `origin/feature/CMP-14-organization-registration`
- `1d162bd`: `origin/feature/CMP-15-compliance-requirement-creation`
- `36ac785`: `origin/feature/CMP-16-compliance-status-changes`
- `0c9dc9a`: `origin/feature/CMP-17-applicability-criteria`
- `795756c`: `origin/feature/CMP-39-protect-sensitive-fields`

*Resultado*: **5/5 ramas integradas en el historial**.

### 2.4 Estado de Ramas Base
- `origin/develop`: commit `23b8b5c83cd91e2a7bab5e30d3168e5b55f744a9`. Se confirmó mediante `git merge-base --is-ancestor origin/develop integration/sprints-3-4` que es ancestro directo.
- `develop`: commit `0bbc02749a00564044f3bc36f299818fcf74e307`. Permanece intacta en su estado local preexistente.

---

## 3. Auditoría de Seguridad del Rate Limiting

### 3.1 Inspección del Código de Rate Limiting
Se examinó la implementación en:
- `backend/routes/api.php`:
  ```php
  Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
  ```
- `backend/app/Providers/AppServiceProvider.php`:
  ```php
  RateLimiter::for('login', function (Request $request) {
      if ($this->app->environment('production')) {
          return Limit::perMinute(10)->by($request->ip());
      }

      $maxAttempts = (int) config('auth.login_rate_limit', env('AUTH_RATE_LIMIT', 10));

      return Limit::perMinute($maxAttempts)->by($request->ip());
  });
  ```
- `backend/config/auth.php`:
  ```php
  'login_rate_limit' => env('AUTH_RATE_LIMIT', 10),
  ```
- `backend/phpunit.xml`:
  ```xml
  <env name="AUTH_RATE_LIMIT" value="10"/>
  ```
- `backend/.env.example`:
  ```ini
  AUTH_RATE_LIMIT=10
  ```

### 3.2 Evaluación de Preguntas Críticas de Seguridad
1. **¿Producción siempre aplica un máximo de 10 intentos por minuto por IP?**  
   **SÍ**. Si `$this->app->environment('production')` es verdadero, el método retorna inmediatamente `Limit::perMinute(10)->by($request->ip())`.
2. **¿Puede `AUTH_RATE_LIMIT` alterar ese límite en producción por alguna vía?**  
   **NO**. En producción, la bifurcación condicional retorna antes de evaluar `AUTH_RATE_LIMIT` o `config('auth.login_rate_limit')`. Incluso si un operador configurase erróneamente `AUTH_RATE_LIMIT=1000` en el `.env` de producción, el valor es completamente ignorado.
3. **¿La configuración de pruebas debilita accidentalmente la protección de producción?**  
   **NO**. `phpunit.xml` fija explícitamente `AUTH_RATE_LIMIT=10`. Los tests unitarios y de características se ejecutan bajo el límite de fuerza bruta de 10 intentos. Solo la configuración del servidor web E2E en `playwright.config.ts` pasa `AUTH_RATE_LIMIT: '100'` de forma aislada a su propio subproceso `php artisan serve`.
4. **¿Está `throttle:login` correctamente aplicado?**  
   **SÍ**, directamente aplicado a la ruta POST `/api/auth/login`.
5. **¿La prueba nueva verifica el comportamiento efectivo del limitador?**  
   **SÍ**. En `backend/tests/Feature/AuthenticationTest.php`, la prueba `strictly ignores AUTH_RATE_LIMIT in production environment` invoca la clausura del rate limiter bajo entorno simulado `production` con `config(['auth.login_rate_limit' => 100])` y comprueba que la instancia `Limit` generada tiene `maxAttempts === 10`. Además, la prueba `keeps health public and rate limits login attempts with the exact message` realiza 10 intentos HTTP reales y comprueba que el intento 11 es bloqueado con HTTP 429 y mensaje exacto `"Too many sign-in attempts. Try again in X seconds."`.

---

## 4. Auditoría del Microservicio IA

### 4.1 Inspección de Orquestación y Configuración
- **`playwright.config.ts`**:
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
- **Readiness Check**: Comprueba disponibilidad HTTP real contra `http://127.0.0.1:8001/openapi.json`, el cual retorna HTTP 200 sin autenticación cuando el servicio está listo.
- **Portabilidad en Runners Limpios**: La instrucción bash condicional ejecuta `.venv/bin/uvicorn` si existe entorno virtual local, o `python3 -m uvicorn` en runners como GitHub Actions donde las dependencias se instalan globalmente en el entorno del runner.
- **Workflow CI (`.github/workflows/ci.yml`)**:
  - Incorpora `actions/setup-python@v5` con Python 3.12 y cache de pip en el job `e2e`.
  - Ejecuta `pip install -r requirements.txt`.
  - Configura mediante `sed`: `AI_SERVICE_URL=http://127.0.0.1:8001`, `AUTH_RATE_LIMIT=100`, `SESSION_SECURE_COOKIE=false` y `DOCUMENT_ENCRYPTION_KEY` dinámico.
  - El job `e2e` depende formalmente de `needs: [frontend, backend, ai-service]`.
- **Credenciales y Secretos**: Utiliza el token estándar de desarrollo `compliance_ai_secret_token`. No existen credenciales personales ni tokens de API expuestos.
- **Funcionamiento Real vs Disponibilidad HTTP**: En la prueba `e2e/uc-002.spec.ts`, se comprobó que el flujo no es simulado a nivel frontend:
  1. Frontend envía la consulta en lenguaje natural a Laravel `/api/assistant/questions`.
  2. Laravel contacta a FastAPI `/v1/ask` mediante `AiServiceClient`.
  3. FastAPI procesa la consulta con `MockEmbeddingProvider`, recupera candidatos y genera la respuesta con el proveedor mock autorizado.
  4. Laravel almacena el registro en SQLite y retorna la respuesta al frontend.
  5. Playwright verifica que `[data-testid="answer-bubble"]` muestra la respuesta, interactúa con la cita fuente y comprueba el feedback útil.
- **Apagado Limpio**: Se verificó con `lsof -i :8000 -i :8001 -i :5173` que tras la finalización de Playwright no queda ningún proceso activo.

---

## 5. Revisión de Regresiones Frontend

### 5.1 `DocumentUploadPage.tsx`
- **Manejo de Rechazo en Dropzone**: Se incorporó el parámetro tipado `fileRejections: FileRejection[]` para capturar archivos no soportados (`.exe`, etc.) o que exceden 25 MB antes de enviar, mostrando el mensaje de alerta accesible requerido.
- **Accesibilidad y Radio Buttons**: Los radio buttons de nivel de acceso (`#access-all-staff` y `#access-compliance-only`), previamente ocultos con `className="hidden"`, ahora cuentan con etiquetas visibles y puntero interactivo dentro de un contenedor flex accesible, preservando la sincronización bidireccional con el `<select>`.
- **Validaciones**: Se mantuvieron intactas las validaciones de tamaño, extensiones permitidas (`pdf`, `docx`, `txt`), metadata obligatoria y categorías con expiración.

### 5.2 `RequirementsListPage.tsx`
- **Estructura HTML Semántica**: Se migró el contenedor de resultados de `div.grid` a una tabla HTML semántica (`<table className="w-full ...">` con `<thead>`, `<tbody>`, `<tr>`, `<th>`, `<td>`), satisfaciendo los localizadores de `e2e/uc-003.spec.ts` (`page.locator('table')`, `table.locator('tbody tr')`).
- **Placeholder de Búsqueda**: Se estandarizó el atributo `placeholder="Search by requirement name..."` para que coincida tanto con selectores parciales de `name` como de `Search by requirement name`.
- **Filtros y Paginación**: Todas las funciones de filtrado por nombre, regulación, estado y rango de fechas operan sin alteraciones.

### 5.3 Resultados de Compilación y Pruebas Unitarias Frontend
- `npm --prefix frontend test`: **156 pasadas (19 archivos de prueba, 0 fallos)**.
- `npm --prefix frontend run build`: **Exitoso en 3.73s (0 errores de TypeScript, 0 advertencias críticas)**.

---

## 6. Resultados de Verificación en Tiempo Real

| Suite / Herramienta | Comando Ejecutado | Resultado |
| :--- | :--- | :--- |
| **Backend Total** | `cd backend && php artisan test` | **450 pasadas** (1965 aserciones, 0 fallos) |
| **Backend Rate Limit** | `cd backend && php artisan test --filter=AuthenticationTest` | **8 pasadas** (42 aserciones, 0 fallos) |
| **Frontend Unit (Vitest)**| `npm --prefix frontend test` | **156 pasadas** (19 suites, 0 fallos) |
| **Frontend Compilación** | `npm --prefix frontend run build` | **Exitoso** (`tsc -b && vite build`, 3.73s) |
| **Playwright E2E Total** | `npx playwright test --project=chromium` | **17 pasadas**, 0 fallidas, 0 omitidas (29.1s) |
| **Playwright UC-001** | `npx playwright test e2e/uc-001.spec.ts --project=chromium` | **5 pasadas** (10.7s) |
| **Playwright UC-002** | `npx playwright test e2e/uc-002.spec.ts --project=chromium` | **3 pasadas** (7.8s) |
| **Playwright UC-003** | `npx playwright test e2e/uc-003.spec.ts --project=chromium` | **4 pasadas** (8.9s) |
| **Playwright Browser Matrix** | `npx playwright test e2e/browser-matrix.spec.ts --project=firefox` | **5 pasadas** (15.1s) |
| **Workflow CI YAML** | `python3 -c "import yaml; yaml.safe_load(...)"` | **Válido** (4 jobs bien definidos) |
| **Limpieza de Puertos** | `lsof -i :8000 -i :8001 -i :5173` | **Código 1** (Sin puertos abiertos) |

> [!NOTE]
> **Aclaración sobre el Entorno de CI**:  
> Aunque la configuración de `.github/workflows/ci.yml` ha sido validada sintáctica y lógicamente para reproducir el entorno desde cero (instalación de PHP 8.3, Node 20, Python 3.12, migraciones SQLite y seeders), la ejecución efectiva del workflow depende de la publicación en GitHub (`git push`), acción que permanece expresamente restringida durante esta fase de auditoría.

---

## 7. Estado de las Reservas Anteriores

A continuación se clasifica el estado de las reservas identificadas en auditorías previas:

| Reserva | Descripción | Clasificación Actual | Justificación Técnica |
| :--- | :--- | :--- | :--- |
| **R-01: PostgreSQL / pgvector** | 20 pruebas de `ai-service` omitidas en testing local | **Pendiente / Verificable en CI** | Requiere el contenedor Docker con la extensión `pgvector` (`TEST_DATABASE_URL`), el cual se ejecuta en el servicio `services.postgres` de GitHub Actions pero no está activo en el entorno local host. |
| **R-02: WebKit (Safari)** | Pruebas de Playwright en proyecto WebKit | **Pendiente / No verificable localmente** | El sistema operativo anfitrión carece de librerías compartidas de sistema (`libicu74`, `libjpeg-turbo8`, `gstreamer1.0-libav`). En CI, el workflow instala exclusivamente el binario de Chromium con sus dependencias. |
| **R-03: Pruebas de Carga k6** | Verificación de umbrales p95 bajo perfil ADR-031 | **Pendiente / No verificable localmente** | El binario CLI `k6` no está instalado en el PATH del entorno. La prueba interna equivalente en PHP (`LoadProfileBenchmarkTest`) pasa exitosamente (p95 < 2.0s). |
| **R-04: Escaneo OWASP ZAP** | Escaneo dinámico de vulnerabilidades DAST | **Pendiente / No verificable localmente** | La herramienta OWASP ZAP no se encuentra instalada en el anfitrión. La suite estática y de control de acceso (`SecurityAccessControlSuiteTest`, 24 casos) pasa al 100%. |
| **R-05: Multi-navegador vs E2E** | Distinción entre `browser-matrix` y suite E2E completa | **Resuelta** | Se verificó formalmente la distinción: `browser-matrix.spec.ts` cubre 5 flujos clave de compatibilidad (verificado en Chromium y Firefox, 5/5 pasados), mientras que la suite E2E completa (`uc-001`, `uc-002`, `uc-003`, 17 pruebas) se ejecuta y aprueba al 100% en Chromium como estándar de CI. |

---

## 8. Hallazgos Clasificados por Severidad

### Severidad Crítica (0)
*Ningún hallazgo identificado.*

### Severidad Alta (0)
*Ningún hallazgo identificado.*

### Severidad Media (0)
*Ningún hallazgo identificado.*

### Severidad Baja / Informativa (2)
1. **L-01: Dependencia de variables de entorno para pruebas WebKit locales**:  
   - *Detalle*: Para desarrolladores que deseen ejecutar pruebas locales contra WebKit en Linux, se requiere instalar las librerías nativas del sistema (`sudo apt-get install libicu74 libjpeg-turbo8 gstreamer1.0-libav`).  
   - *Mitigación*: Se sugiere documentar en el README las instrucciones de instalación de dependencias nativas de Playwright (`sudo npx playwright install-deps`).
2. **L-02: Tamaño de bundle en build de frontend**:  
   - *Detalle*: El bundle JavaScript compilado en producción (`dist/assets/index-Cyd9CZbn.js`) pesa ~1.08 MB (325 kB gzipped), generando una advertencia informativa de Vite (`chunks larger than 500 kB`).  
   - *Mitigación*: En un sprint futuro de optimización, se recomienda implementar carga dinámica (`React.lazy` / `dynamic import()`) para rutas secundarias (`/admin/*`, `/requirements/*`).

---

## 9. Recomendación Final

Se dictamina **APROBADO CON RESERVAS**.

La rama `integration/sprints-3-4` en el commit `745c990`:
1. Mantiene total integridad histórica y trazabilidad con respecto a `origin/develop` y a los 22 commits de Jira.
2. Posee una política de seguridad estricta para rate limiting que imposibilita la relajación accidental de umbrales en producción.
3. Cuenta con una orquestación reproducible y limpia para la ejecución de pruebas E2E con el microservicio IA.
4. Cumple con el 100% de aprobación en las pruebas automatizadas de backend (450), frontend (156) y Playwright Chromium (17).

**Conclusión:** Se autoriza técnicamente la preparación del Pull Request y posterior fusión de `integration/sprints-3-4` hacia `develop`.
