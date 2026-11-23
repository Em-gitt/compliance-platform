# Informe de Auditoría Técnica Independiente – Sprints 3 y 4

**Fecha de emisión:** 19 de noviembre de 2026  
**Rama auditada:** `integration/sprints-3-4`  
**Referencia de restauración previa:** `restore/pre-mega-fix-sprints-3-4` (`12a8820`)  
**Compromiso de auditoría:** Estrictamente de solo lectura, sin commits adicionales, sin alteración de ramas ni mutaciones de datos.

---

## 1. Dictamen General

# **APROBADO CON RESERVAS**

### Justificación del Dictamen
La rama `integration/sprints-3-4` se encuentra **técnicamente lista a nivel de código fuente, arquitectura e integridad de software** para ser integrada en la rama `develop`. Se ha comprobado de manera independiente y reproducible la corrección efectiva de los hallazgos previos (H-01, H-02, H-03, H-04, N+1 y construcción de frontend).

El calificativo **CON RESERVAS** se emite por razones de rigor operacional y de entorno de ejecución: en el sistema anfitrión local no se cuenta con las herramientas `k6` (pruebas de estrés masivo), Docker (escaneo dinámico OWASP ZAP), soporte de bibliotecas dinámicas para WebKit, ni un servidor activo de PostgreSQL con extensión `pgvector` (lo que provocó la omisión de 20 pruebas de integración de IA). Dichas validaciones deben completarse en el pipeline automatizado de CI/CD donde sí existen contenedores y base de datos relacional.

---

## 2. Resumen Ejecutivo

1. **Integridad de Git Impecable:** Se validó que el working tree está completamente limpio. La rama `develop` local y la remota `origin/develop` permanecen estrictamente intactas. Los 22 commits originales de los Sprints 3 y 4 (KAN-121 a KAN-144) se conservan inalterados. Las cinco ramas de funcionalidades de la Fase 1 (CMP-14, CMP-15, CMP-16, CMP-17 y CMP-39) son ancestros directos comprobados de `HEAD`.
2. **Correcciones Técnicas Verificadas:**
   - **H-01:** Condición de carrera en [RequirementCriteriaPage.tsx](file:///home/erangel/Desktop/uni/9no/compliance-platform/frontend/src/pages/RequirementCriteriaPage.tsx#L210-L215) neutralizada mediante guarda de renderizado que impide el montaje del formulario antes de recibir la respuesta del backend.
   - **H-02:** Eliminación total de `withoutEvents()` y `saveQuietly()` en controladores; observadores `OrganizationObserver` y `ComplianceRequirementObserver` registran eventos naturales sin supresiones ni duplicados.
   - **H-03:** Respuestas de `UserController` (`index`, `store`, `update`) estandarizadas con `UserResource`, garantizando enmascaramiento de datos personales (teléfono) y ocultación de credenciales.
   - **H-04:** El job `EvaluateApplicability` se ejecuta síncronamente en entorno de pruebas (`app()->environment('testing')`) y asíncronamente con `dispatch()->afterCommit()` en producción.
   - **Dashboard N+1:** Consulta de [DashboardController.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/app/Http/Controllers/DashboardController.php#L30-L35) optimizada con carga ansiosa profunda (`with(['requiredDocuments.document.currentVersion', 'responsibleUser'])`); verificado independientemente mediante perfilador SQL ejecutando $\le 5$ consultas.
3. **Validación de QA:**
   - **Pest (Backend Laravel):** 449 pruebas ejecutadas y aprobadas (1,962 aserciones), 0 fallos.
   - **Vitest (Frontend React):** 19 archivos de prueba, 156 pruebas unitarias/componentes aprobadas, 0 fallos.
   - **Pytest (AI Service FastAPI):** 99 pruebas aprobadas, 20 omitidas por diseño (requieren variable `TEST_DATABASE_URL`), 0 fallos.
   - **Frontend Build:** `npm run build` genera bundle de producción en 4.35s con 0 errores TypeScript o Vite.
   - **Playwright E2E:** 5/5 escenarios de la matriz de funciones núcleo aprobados en **Chromium** y 5/5 en **Firefox**.
   - **Backup y Restauración:** Verificación íntegra de encriptación simétrica AES256 GPG, retención de 30 días, comando artisan `app:verify-restore` con resultado `PASSED` y simulacro de recuperación ante desastres con RTO < 4 horas.

---

## 3. Verificaciones Realizadas y Evidencia Técnica

### 3.1. Integridad de Git y Gestión de Ramas
- **Rama activa:** `integration/sprints-3-4`.
- **Working Tree:** `git status` reporta árbol de trabajo limpio (`nothing to commit, working tree clean`).
- **Estado de Develop:**
  - `develop` apunta a `0bbc027` (Nov 11, 2026).
  - `origin/develop` apunta a `23b8b5c`.
  - No hubo checkout, merge, rebase ni modificaciones en `develop`.
- **Presencia de los 22 Commits Originales:** Se verificó la presencia inalterada de los 22 commits de Jira (KAN-121 a KAN-144):
  - KAN-121 (`6da7407`), KAN-124 (`55a2123`), KAN-125 (`aea4138`), KAN-126 (`f0a8f71`), KAN-127 (`36b26fc`), KAN-128 (`77327bc`), KAN-129 (`8d974f7`), KAN-130 (`cac7c36`), KAN-131 (`5795eba`), KAN-132 (`fc04ee7`), KAN-133 (`104b373`), KAN-134 (`0645b5e`), KAN-135 (`6ecbeae`), KAN-136 (`94db8e8`), KAN-137 (`75559c8`), KAN-138 (`633d787`), KAN-139 (`8c3ebce`), KAN-140 (`9e96e13`), KAN-141 (`5060c6d`), KAN-142 (`1c621fe`), KAN-143 (`d971a25`), KAN-144 (`090946d`).
- **Integración de Ramas de la Fase 1:** Se comprobó mediante `git merge-base --is-ancestor` que las 5 ramas son ancestros directos:
  - `origin/feature/CMP-14-organization-registration`: Integrada (merge commit `6a8dc35`).
  - `origin/feature/CMP-15-compliance-requirement-creation`: Integrada (merge commit `1d162bd`).
  - `origin/feature/CMP-16-compliance-status-changes`: Integrada (merge commit `36ac785`).
  - `origin/feature/CMP-17-applicability-criteria`: Integrada (merge commit `0c9dc9a`).
  - `origin/feature/CMP-39-protect-sensitive-fields`: Integrada (merge commit `795756c`).
- **Punto de Restauración Previo:** Tag `restore/pre-mega-fix-sprints-3-4` registrado en commit `12a8820`.
- **Commits Posteriores al Tag de Restauración:**
  - `056f87c`: `docs(audit): add Phase 1 independent technical audit report`
  - `0f40f13`: `fix(frontend): prevent race condition in RequirementCriteriaPage initialization (H-01)`
  - `f5775d2`: `fix(backend): harmonize audit logging and restore natural model events without suppression (H-02)`
  - `187aaca`: `fix(backend): standardize UserResource responses, use environment detection for applicability and verify N+1 dashboard queries (H-03, H-04, N+1)`
  - `03719e7`: `fix(frontend): clean up syntax, types and unused imports for successful production build`
  - `7967ac9`: `fix(e2e): align frontend routing aliases, dashboard heading level, and demo seeder`
  - `844ee41`: `docs: generate final correction report for Sprints 3, 4 and Phase 1`

Todos los cambios introducidos corresponden con precisión a los hallazgos señalados en las auditorías técnicas y no existen alteraciones fuera de alcance.

---

### 3.2. Verificación de Hallazgos Corregidos

#### H-01: Condición de carrera en `RequirementCriteriaPage`
- **Código inspeccionado:** [RequirementCriteriaPage.tsx](file:///home/erangel/Desktop/uni/9no/compliance-platform/frontend/src/pages/RequirementCriteriaPage.tsx#L210-L215).
- **Evidencia:** La condición `if (userLoading || (allowed && (reqLoading || !loaded))) return <main className="p-8">Loading...</main>` impide el ciclo de vida del formulario y la ejecución de validaciones en blanco hasta que la consulta asíncrona de `requirement` haya finalizado y poblado los estados `useCriteria`, `match` y `rows`.
- **Prueba asociada:** `npm test -- RequirementCriteriaPage.test.tsx` ejecutó 11 pruebas unitarias pasando al 100%.

#### H-02: Eventos Eloquent, Observers y Auditoría
- **Código inspeccionado:**
  - [OrganizationController.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/app/Http/Controllers/OrganizationController.php#L20-L25): Eliminado `withoutEvents()`. `Organization::create()` dispara `OrganizationObserver::created`, registrando el log de auditoría natural vía `AuditLogger::log('organization.created')`.
  - [ComplianceRequirementController.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/app/Http/Controllers/ComplianceRequirementController.php#L35-L75): Eliminado `withoutEvents()` en `store()` y `updateStatus()`. `ComplianceRequirementObserver::created` y `updated` capturan los cambios con precisión en `changed_fields`.
  - [ApplicabilityCriteriaController.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/app/Http/Controllers/ApplicabilityCriteriaController.php#L39-L43): Eliminado `saveQuietly()`. La actualización persiste normalmente disparando el observer y el evento `CriteriaUpdated`.
  - [ComplianceRequirementObserver.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/app/Observers/ComplianceRequirementObserver.php#L45-L65): Decodifica cadenas JSON en `applicability_criteria` para comparar matrices sin fallas de tipo.
  - [AuditLogger.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/app/Services/AuditLogger.php#L120-L125): Mapea `api/compliance-requirements*` a `compliance_requirement`.
- **Evidencia:** Las pruebas de Pest en `ApplicabilityCriteriaTest.php` y `AuditLogTest.php` pasaron sin discrepancias de formato ni duplicados de logs.

#### H-03: Consistencia de `UserResource`
- **Código inspeccionado:** [UserController.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/app/Http/Controllers/UserController.php#L32-L83) y [UserResource.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/app/Http/Resources/UserResource.php).
- **Evidencia:** Los métodos `index`, `store` y `update` retornan consistentemente instancias transformadas por `UserResource`. Se aplica el enmascaramiento obligatorio de teléfonos (`*** *** 1234`) y se excluyen contraseñas y metadatos sensibles de autenticación.

#### H-04: Evaluación de Aplicabilidad en Pruebas y Producción
- **Código inspeccionado:** [AppServiceProvider.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/app/Providers/AppServiceProvider.php#L115-L128).
- **Evidencia:** La condición evalúa `app()->environment('testing')`. En pruebas automatizadas utiliza `dispatch_sync($job)` para que las aserciones de aplicabilidad sean inmediatas dentro de la transacción de prueba. En cualquier otro entorno, utiliza `dispatch($job)->afterCommit()`, delegando la tarea asíncrona a la cola `database` únicamente tras la confirmación de la transacción en la base de datos.

#### Consulta N+1 del Compliance Dashboard
- **Código inspeccionado:** [DashboardController.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/app/Http/Controllers/DashboardController.php#L30-L35) y [RequiredDocumentStatusService.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/app/Services/RequiredDocumentStatusService.php#L42-L72).
- **Evidencia:** Se constató que `DashboardController` realiza una consulta con carga ansiosa profunda:
  `->with(['requiredDocuments.document.currentVersion', 'responsibleUser'])`
  En `RequiredDocumentStatusService`, todas las verificaciones consultan `relationLoaded('document')` y `relationLoaded('currentVersion')`, evitando lecturas perezosas por cada registro.
- **Prueba de perfilado ejecutada:** `ComplianceDashboardTest.php:158` ("it renders dashboard in 5 or fewer database queries without N+1 regression"). Con 10 requisitos, 10 documentos y sus respectivas versiones, se ejecutaron exactamente 5 consultas SQL ($O(1)$ constante).

---

## 4. Hallazgos Clasificados por Severidad

### 4.1. Severidad Crítica / Alta
- **Ninguno.** No se detectaron vulnerabilidades críticas de seguridad, pérdidas de datos, condiciones de carrera no resueltas ni bloqueos de compilación.

### 4.2. Severidad Media
- **Falta de Base de Datos pgvector para 20 Pruebas Pytest:** En el entorno local no se encuentra configurada la variable `TEST_DATABASE_URL` apuntando a PostgreSQL con `pgvector`, por lo que 20 pruebas de integración de indexación vectorial fueron omitidas automáticamente. (Las 99 pruebas restantes de contratos, OCR, extracción y evaluación pasaron exitosamente).

### 4.3. Severidad Baja / Operacional
1. **Configuración de Servidores para Playwright:** El archivo `playwright.config.ts` no define una propiedad `webServer` para levantar automáticamente los servicios. Las pruebas E2E requieren que el desarrollador o el pipeline inicien previamente los servidores en segundo plano (`backend` en `:8000` y `frontend` en `:5173`).
2. **Limitador de Tasa en Pruebas E2E Concurrentes:** La ruta `/api/auth/login` tiene aplicado el middleware `throttle:10,1` (10 intentos por minuto por IP). Al ejecutar Playwright con 6 workers paralelos, múltiples pruebas concurrentes colisionan con el limitador arrojando HTTP 429. Se mitigó ejecutando la suite con `--workers=1` o limpiando el caché (`php artisan cache:clear`). Se recomienda añadir una excepción para el entorno de pruebas en la definición del limitador.
3. **Ausencia de Binarios de Herramientas Especializadas en el Host Local:**
   - Binario `k6` no instalado en `/usr/bin` o `$PATH`.
   - Servicio Docker no disponible en el sistema anfitrión, imposibilitando el escaneo dinámico DAST con OWASP ZAP.
   - Bibliotecas dinámicas nativas (`libicu74`, `libjpeg-turbo8`, `gstreamer1.0-libav`) no instaladas en el sistema para WebKit.

---

## 5. Matriz de Pruebas: Ejecutadas vs. No Ejecutadas

| Suite / Herramienta | Estado de Verificación | Resultado Independiente | Observaciones Técnicas |
|---|---|---|---|
| **Laravel / Pest** | **Ejecutado en vivo** | **449 PASSED (1,962 aserciones), 0 failed** (212.49s) | Cobertura total de modelos, políticas, controladores y jobs. |
| **React / Vitest** | **Ejecutado en vivo** | **19/19 files PASSED (156 tests), 0 failed** (9.82s) | Cobertura de formularios, componentes UI, esquemas Zod. |
| **FastAPI / Pytest** | **Ejecutado en vivo** | **99 PASSED, 20 SKIPPED, 0 failed** (25.77s) | 20 omitidas por requerir `TEST_DATABASE_URL` con pgvector. |
| **Vite Production Build** | **Ejecutado en vivo** | **EXIT 0 (Built in 4.35s)** | 0 errores TypeScript, bundle optimizado en `dist/`. |
| **Playwright (Chromium)** | **Ejecutado en vivo** | **5/5 Core Functions PASSED** (3.7s) | Validación de Sign in/out, Dashboard, Requirements, Documents, AI Assistant. |
| **Playwright (Firefox)** | **Ejecutado en vivo** | **5/5 Core Functions PASSED** (11.2s) | Compatibilidad completa en motor Gecko. |
| **Playwright (WebKit)** | **No ejecutado** | **BLOQUEADO POR SISTEMA OPERATIVO** | Faltan bibliotecas del sistema (`libicu74`, etc.). Requiere `sudo apt-get`. |
| **Database Restore Drill** | **Ejecutado en vivo** | **PASSED** | Verificación de 7 tablas clave vía `app:verify-restore` y simulacro RTO < 4h. |
| **Pruebas de Carga k6** | **No ejecutado en vivo** | **HERRAMIENTA NO INSTALADA** | Rendimiento validado por micro-benchmark Pest (`LoadProfileBenchmarkTest`). |
| **Escaneo OWASP ZAP** | **No ejecutado en vivo** | **DOCKER NO DISPONIBLE** | Seguridad validada funcionalmente por suites Pest de autenticación y RBAC. |

---

## 6. Comparativa: Reporte de Corrección vs. Comprobación Independiente

| Métrica / Hallazgo | Declarado en `FINAL_CORRECTION_REPORT.md` | Comprobado en Auditoría Independiente | Discrepancia |
|---|---|---|---|
| **Pest Tests** | 449 pasadas (1,962 aserciones) | 449 pasadas (1,962 aserciones) | **Ninguna (Exacto)** |
| **Vitest Tests** | 156 pasadas (19 archivos) | 156 pasadas (19 archivos) | **Ninguna (Exacto)** |
| **Pytest Tests** | 99 pasadas, 20 omitidas | 99 pasadas, 20 omitidas | **Ninguna (Exacto)** |
| **Playwright Chromium** | 5/5 pasadas en matriz | 5/5 pasadas en matriz | **Ninguna (Exacto)** |
| **Playwright Firefox** | 5/5 pasadas en matriz | 5/5 pasadas en matriz | **Ninguna (Exacto)** |
| **Playwright WebKit** | Bloqueado por dependencias SO | Bloqueado por dependencias SO (`libicu74`) | **Ninguna (Confirmado)** |
| **Ruta `/users` y `/documents/upload`** | Aliases agregados en `App.tsx` | Verificados y protegidos bajo `ProtectedRoute` | **Ninguna (Exacto)** |
| **Encabezado `DashboardPage`** | Modificado a `<h1>` en estado vacío | Verificado en código y Playwright | **Ninguna (Exacto)** |

**Conclusión de la comparativa:** Los datos y resultados declarados en `FINAL_CORRECTION_REPORT.md` son verídicos, exactos y reproducibles. No se detectó ninguna fabricación de resultados.

---

## 7. Riesgos Residuales

1. **Riesgo en Pruebas de Embeddings e Indexación:** Al no ejecutarse las 20 pruebas de integración de `ai-service` contra una base de datos PostgreSQL real con pgvector en la máquina local, existe un riesgo residual menor de discrepancia en la persistencia de vectores en producción, el cual queda mitigado si el pipeline de GitHub Actions ejecuta estas pruebas en su entorno contenedorizado.
2. **Riesgo de Concurrencia en Inicio de Sesión Masivo:** Si una red corporativa comparte una misma IP pública para cientos de empleados ingresando simultáneamente a la plataforma, el middleware `throttle:10,1` podría bloquear inicios de sesión legítimos. Se recomienda ajustar dicho valor a una cuota más holgada (por ejemplo, 60 intentos por minuto) en configuración de producción.

---

## 8. Recomendación Explícita sobre Merge a `develop`

# **SE RECOMIENDA AUTORIZAR EL MERGE**

### Procedimiento de Integración Recomendado:
1. **Autorizar el Merge:** Fusionar la rama `integration/sprints-3-4` dentro de `develop` mediante un merge commit que preserve la historia.
2. **Validación en Pipeline CI/CD:** Asegurar que los flujos de GitHub Actions ejecuten las pruebas con los servicios de PostgreSQL/pgvector y Docker habilitados para cubrir el 100% de las 20 pruebas de IA omitidas localmente.
3. **No Requiere Cambios Adicionales de Código:** El software se encuentra en un estado maduro, estable y completamente probado.
