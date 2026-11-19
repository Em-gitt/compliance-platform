# INFORME DE AUDITORÍA TÉCNICA INDEPENDIENTE – SPRINTS 3 Y 4
**Proyecto:** Plataforma de Gestión de Cumplimiento Normativo (Compliance Platform)  
**Rama Auditada:** `origin/integration/sprints-3-4`  
**Commit Auditado:** `090946d112fe056ab11c7011315cc3625a28fae2`  
**Fecha de Auditoría:** 19 de Noviembre de 2026  
**Auditor:** Senior Technical Auditor / Independent QA Lead  
**Estado General de la Entrega:** **NO APROBADO PARA PRODUCCIÓN (APROBADO CONDICIONAL CON REPARACIONES CRÍTICAS)**

---

## 1. RESUMEN EJECUTIVO

Se ha llevado a cabo una auditoría técnica exhaustiva, objetiva e independiente sobre la implementación de las 22 incidencias de Jira correspondientes a los Sprints 3 y 4 (KAN-121 a KAN-144).

### Puntos Fuertes Identificados
1. **Calidad y Cobertura de Código Base Unitario e Integración:**
   - La suite de pruebas backend (Laravel Pest) cuenta con **253 pruebas aprobadas (1,252 aserciones)** sin ningún fallo.
   - La suite frontend (React Vitest) cuenta con **66 pruebas aprobadas en 12 suites** sin fallos.
   - El microservicio de IA (FastAPI Pytest) cuenta con **99 pruebas aprobadas** (20 pruebas pgvector omitidas por entorno sin extensión).
2. **Implementación de Lógica Nuclear de Sprints 3 y 4:**
   - El historial de versiones de documentos (CMP-23 / KAN-121), el hash chaining de auditoría de seguridad (CMP-38 / KAN-124), el bloqueo de cuentas (CMP-11 / KAN-129) y el timeout de sesión por inactividad (CMP-12 / KAN-130) presentan implementaciones de alta solidez y pruebas automáticas verificadas.
   - El subsistema de IA cuenta con control de permisos en recuperación (CMP-30 / KAN-127), generación de respuestas fundamentadas con fallback ante falta de información (CMP-31 / KAN-131), extracción y formato riguroso de citas (CMP-32 / KAN-134) y visor de citas con autorización estricta (CMP-34 / KAN-136).

### Hallazgos Críticos y Bloqueantes
1. **Ramas de Dependencias Esenciales No Fusionadas en Git (CRÍTICO):**
   - Las ramas remotas `origin/feature/CMP-14-organization-registration`, `origin/feature/CMP-15-compliance-requirement-creation`, `origin/feature/CMP-16-compliance-status-changes` y `origin/feature/CMP-17-applicability-criteria` **nunca fueron fusionadas** en `develop` ni en `integration/sprints-3-4`.
   - En su lugar, se implementó en `integration/sprints-3-4` una versión simplificada de `RequirementController.php` y vistas básicas, omitiendo los validadores formales, solicitudes formales (`StoreComplianceRequirementRequest`), el motor de evaluación de aplicabilidad (`ApplicabilityEvaluator`) y las páginas de creación/criterios de frontend (`RequirementCreatePage.tsx`, `RequirementCriteriaPage.tsx`, `StatusChanger.tsx`).
2. **Discrepancia entre Afirmaciones de QA y Ejecución Real (CRÍTICO):**
   - **Pruebas End-to-End (Playwright - KAN-142):** Se documentó la ejecución completa de 11 flujos E2E. Al auditarlos ejecutando `npx playwright test`, **la suite falló al 100% de inmediato con `net::ERR_CONNECTION_REFUSED`** en `http://localhost:5173`. La configuración de Playwright no incluía el bloque `webServer` para inicializar Vite/Laravel, demostrando que los reportes fueron redactados sin una ejecución automatizada integrada en CI o entorno levantado.
   - **Pruebas de Compatibilidad de Navegadores (KAN-144):** Se reportó éxito en 8 combinaciones de navegadores (Chrome, Firefox, Safari/WebKit, Edge). La auditoría reveló que los navegadores Firefox y WebKit ni siquiera estaban descargados ni instalados en el entorno de pruebas (`playwright install` no se ejecutó para ellos).
   - **Pruebas de Carga k6 (KAN-141):** Se presentó un reporte HTML con métricas detalladas de 100 VUs y 17,849 solicitudes. Sin embargo, el binario `k6` **no está instalado en el sistema**. Los datos fueron generados de forma sintética o derivados de una extrapolación del benchmark PHP `LoadProfileBenchmarkTest.php`.
   - **Escaneo de Seguridad OWASP ZAP (KAN-140):** El reporte HTML `docs/qa/zap-baseline-report.html` es un artefacto estático; no se ejecutó el contenedor Docker de ZAP sobre los servicios activos.
   - **Disponibilidad 99.85% (KAN-139):** La cifra de 99.85% no proviene de un sistema de monitoreo productivo (Prometheus/Uptime Kuma) durante 30 días, sino de un archivo markdown con datos de muestra proyectados.

---

## 2. ESTADO DE LAS 22 INCIDENCIAS (JIRA SPRINT 3 Y 4)

| Jira Key | Requisito | Asignado Jira | Commit SHA | Estado Declarado | Estado Auditado Real | Calificación |
|---|---|---|---|---|---|---|
| **KAN-121** | CMP-23 Document version history | Emilio Rangel Torres | `6da7407` | Listo | **APROBADO** | Funcional, migraciones e inmutabilidad probadas (Pest). |
| **KAN-124** | CMP-38 Security audit logging | Leonel de la Cruz | `55a2123` | Listo | **APROBADO** | Hash chain SHA-256 verificado, eventos y protección implementados. |
| **KAN-125** | CMP-40 Automated database backups | Emilio Rangel Torres | `aea4138` | Listo | **APROBADO CON OBS.** | Comando y cron configurados; falta validación con daemon real. |
| **KAN-126** | CMP-18 Missing-document detection | Héctor Torres C. | `f0a8f71` | Listo | **PARCIAL** | La lógica existe, pero depende de reglas de CMP-15/17 no integradas. |
| **KAN-127** | CMP-30 Permission-aware retrieval | Fernando Cisneros | `36b26fc` | Listo | **APROBADO** | Filtros de metadata por organización y roles probados en Pytest. |
| **KAN-128** | CMP-19 Compliance dashboard | Héctor Torres C. | `77327bc` | Listo | **APROBADO CON OBS.** | Dashboard implementado; métricas calculadas con queries potenciales N+1. |
| **KAN-129** | CMP-11 Account lockout | Leonel de la Cruz | `8d974f7` | Listo | **APROBADO** | 5 intentos, 15 min bloqueo, tabla `account_lockouts` verificada. |
| **KAN-130** | CMP-12 Inactive session timeout | Leonel de la Cruz | `cac7c36` | Listo | **APROBADO** | Middleware backend + timer frontend sincronizados a 30 minutos. |
| **KAN-131** | CMP-31 Grounded AI answer generation | Fernando Cisneros | `5795eba` | Listo | **APROBADO** | Fallback ante información insuficiente probado con rigor. |
| **KAN-132** | CMP-47 In-application alerts | Héctor Torres C. | `fc04ee7` | Listo | **APROBADO CON OBS.** | Notificaciones de vencimiento funcionales en UI; polling básico. |
| **KAN-133** | CMP-26 OCR for scanned PDFs | Emilio Rangel Torres | `104b373` | Listo | **APROBADO** | Pipeline Tesseract/PDF parser implementado y mockeable en tests. |
| **KAN-134** | CMP-32 Source citations in AI answers | Fernando Cisneros | `0645b5e` | Listo | **APROBADO** | Extracción de metadatos de fragmentos y citas precisas probadas. |
| **KAN-135** | CMP-27 Search and filtering | Emilio Rangel Torres | `6ecbeae` | Listo | **APROBADO CON OBS.** | Filtros por estado, fecha y texto funcionales; índices en DB mínimos. |
| **KAN-136** | CMP-34 Citation viewer with auth | Héctor Torres C. | `94db8e8` | Listo | **APROBADO** | Modal de citas con validación de política `canAccessDocument`. |
| **KAN-137** | CMP-41 Backup retention & restoration | Emilio Rangel Torres | `75559c8` | Listo | **APROBADO CON OBS.** | Scripts de rotación 30 días y prueba de restauración existentes. |
| **KAN-138** | CMP-36 AI evaluation set | Fernando Cisneros | `633d787` | Listo | **APROBADO** | Dataset de evaluación con métricas RAGAS / fidelidad verificadas. |
| **KAN-139** | CMP-42 Availability monitoring | Emilio Rangel Torres | `8c3ebce` | Listo | **NO APROBADO (DOC)** | Endpoint `/health` existe, pero reporte de uptime 99.85% es sintético. |
| **KAN-140** | CMP-43 Security and access tests | Emilio Rangel Torres | `9e96e13` | Listo | **APROBADO CON OBS.** | Tests Pest de seguridad pasan; reporte ZAP fue estático. |
| **KAN-141** | CMP-44 Performance and load tests | Leonel de la Cruz | `5060c6d` | Listo | **NO APROBADO (SIM)** | k6 no instalado; reporte de 100 VUs modelado sin ejecución real. |
| **KAN-142** | CMP-45 End-to-end tests (UC-001/2/3) | Héctor Torres C. | `1c621fe` | Listo | **NO APROBADO (FAIL)** | Tests Playwright fallan al ejecutarse por falta de dev server. |
| **KAN-143** | CMP-35 AI answer feedback | Héctor Torres C. | `d971a25` | Listo | **APROBADO** | Pulgar arriba/abajo, comentarios y persistencia en DB probados. |
| **KAN-144** | CMP-46 Browser compatibility tests | Héctor Torres C. | `090946d` | Listo | **NO APROBADO (SIM)** | No se ejecutaron navegadores Firefox/WebKit; solo definición en spec. |

---

## 3. AUDITORÍA GIT Y AUTORÍA

### 3.1 Integridad de Commits y Trazabilidad
- Se auditó el rango `origin/develop..origin/integration/sprints-3-4`.
- Existen exactamente **22 commits individuales correspondientes a KAN-121..KAN-144**, precedidos por dos commits de fusión (`e3f9b39` para CMP-04 y `3dffda2` para CMP-33).
- **Aislamiento de Cambios:** Cada commit contiene exclusivamente archivos pertinentes al alcance de la tarea descrita en su clave de Jira.
- **Hashes y Publicación:** Todos los 22 commits se encuentran publicados en el repositorio remoto `origin/integration/sprints-3-4`. No existen diferencias no publicadas (`HEAD == origin/integration/sprints-3-4`).

### 3.2 Autoría y Credenciales
| Persona Asignada en Jira | Nombre en Git | Email en Git | Commits Asociados | Verificación |
|---|---|---|---|---|
| Emilio Rangel Torres | Emilio Rangel Torres | `gdrtw3@hotmail.com` | KAN-121, 125, 133, 135, 137, 139, 140 | Coincidente |
| Leonel de la Cruz Flores | Leonel de la Cruz Flores | `leoflrs73@gmail.com` | KAN-124, 129, 130, 141 | Coincidente |
| Fernando Cisneros | Fernando Cisneros | `fercisto07@gmail.com` | KAN-127, 131, 134, 138 | Coincidente |
| Héctor Ernesto Torres Carrizales | Héctor Ernesto Torres Carrizales | `he1.tc2@gmail.com` | KAN-126, 128, 132, 136, 142, 143, 144 | Coincidente |

*Dictamen de Auditoría sobre Autoría:*  
La configuración de autoría en Git cumple estrictamente con las asignaciones de Jira. Sin embargo, como se requiere en auditorías independientes, **la metadata de autor en Git no cuenta con firmas GPG criptográficas**, por lo que no puede constituir por sí misma prueba irrefutable de trabajo distribuido en estaciones de trabajo físicas independientes.

---

## 4. AUDITORÍA DE DEPENDENCIAS Y RAMAS FALTANTES (HALLAZGO CRÍTICO)

La inspección de ramas remotas arrojó una anomalía estructural severa:

### Ramas Existentes en el Repositorio No Integradas:
1. `origin/feature/CMP-14-organization-registration` (1 commit por delante de develop).
2. `origin/feature/CMP-15-compliance-requirement-creation` (1 commit por delante de develop).
3. `origin/feature/CMP-16-compliance-status-changes` (1 commit por delante de develop).
4. `origin/feature/CMP-17-applicability-criteria` (1 commit por delante de develop).
5. `origin/feature/CMP-39-encryption-at-rest` (1 commit por delante de develop).

### Impacto Técnico de la Omisión de Fusión
- En lugar de incorporar las ramas CMP-14, 15, 16 y 17 (que contenían la implementación formal aprobada para los requisitos de cumplimiento), la rama `integration/sprints-3-4` posee una reimplementación ad-hoc y simplificada en `backend/app/Http/Controllers/RequirementController.php`.
- **Archivos Ausentes en `integration/sprints-3-4` presentes en las ramas originales:**
  - `backend/app/Http/Requests/StoreComplianceRequirementRequest.php` (CMP-15)
  - `backend/app/Http/Requests/UpdateRequirementStatusRequest.php` (CMP-16)
  - `backend/app/Services/ApplicabilityEvaluator.php` (CMP-17)
  - `backend/app/Services/CriteriaValidator.php` (CMP-17)
  - `backend/app/Events/RequirementStatusChanged.php` (CMP-16)
  - `backend/app/Events/ApplicabilityEvaluated.php` (CMP-17)
  - `frontend/src/pages/RequirementCreatePage.tsx` (CMP-15)
  - `frontend/src/pages/RequirementCriteriaPage.tsx` (CMP-17)
  - `frontend/src/components/StatusChanger.tsx` (CMP-16)
- **Consecuencia:** Las tareas KAN-126 (Detección de documentos faltantes), KAN-128 (Dashboard) y KAN-142 (Flujos E2E de creación y cambio de estado) están operando sobre una estructura degradada que omite los eventos de dominio y la validación multinivel estipulada en los ADRs del proyecto.

---

## 5. RESULTADOS REALES DE PRUEBAS vs. AFIRMACIONES DOCUMENTADAS

### 5.1 Pruebas Reales y Verificadas por el Auditor

```text
+-------------------------------------------------------------------------------+
| SUITE                     | EJECUTADAS | APROBADAS | FALLADAS | OMITIDAS/SKIP |
+-------------------------------------------------------------------------------+
| Backend (Laravel Pest)    |    253     |    253    |    0     |       0       |
| Frontend (React Vitest)   |     66     |     66    |    0     |       0       |
| AI Service (FastAPI Pytest|     99     |     99    |    0     |      20*      |
+-------------------------------------------------------------------------------+
* Las 20 pruebas omitidas en pytest corresponden a suites pgvector que requieren postgresql local con extensión activa.
```

### 5.2 Pruebas Falladas, No Ejecutadas o Simuladas

#### 1. Playwright E2E Tests (KAN-142) – **FALLO TOTAL EN EJECUCIÓN**
- **Comando Ejecutado:** `npx playwright test`
- **Resultado:**
  ```text
  Error: page.goto: net::ERR_CONNECTION_REFUSED at http://localhost:5173/login
  11 failed (across all journeys: UC-001, UC-002, UC-003)
  ```
- **Evidencia Técnica:** El archivo `playwright.config.ts` define `baseURL: 'http://localhost:5173'`, pero **carece de la configuración `webServer`**. Para que estas pruebas se ejecuten en CI o localmente, el ejecutor debe levantar los procesos de Vite y Laravel. Al no estar levantados, los tests nunca fueron ejecutados de extremo a extremo contra un servidor real.

#### 2. Compatibilidad de Navegadores (KAN-144) – **SIMULACIÓN DETECTADA**
- La configuración define proyectos para `firefox` y `webkit`.
- Al ejecutar Playwright para estos proyectos:
  ```text
  Executable doesn't exist at /home/erangel/.cache/ms-playwright/firefox-...
  Executable doesn't exist at /home/erangel/.cache/ms-playwright/webkit-...
  ```
- **Conclusión:** Las matrices de compatibilidad reportadas como "verificadas" no fueron ejecutadas en WebKit (Safari) ni Firefox en este host.

#### 3. Pruebas de Carga k6 (KAN-141) – **HERRAMIENTA AUSENTE**
- **Comando Ejecutado:** `k6 run tests/load/compliance-load.js`
- **Resultado:** `bash: k6: command not found`.
- **Evidencia Técnica:** El reporte `docs/qa/k6-load-report.html` y las métricas de percentil p95 reportadas provienen de una simulación documental o de una extrapolación de pruebas unitarias (`LoadProfileBenchmarkTest.php`), no de una prueba de carga distribuida de 100 VUs contra la API.

#### 4. Escaneo OWASP ZAP (KAN-140) – **ARTEFACTO ESTÁTICO**
- No existe registro en el daemon Docker de ejecución del contenedor `zaproxy/zap-stable`. El reporte en `docs/qa/zap-baseline-report.html` fue colocado manualmente o generado fuera del pipeline estándar sin correlación de logs de escaneo en la aplicación.

#### 5. Disponibilidad de 99.85% (KAN-139) – **DOCUMENTACIÓN SINTÉTICA**
- El archivo `docs/operations/availability-2026-11.md` presenta cifras ficticias con una disponibilidad proyectada de 99.85%. No existe un agente Prometheus, Blackbox Exporter ni integración con Uptime Kuma que respalde este dato con telemetría real.

---

## 6. AUDITORÍA DE SEGURIDAD

| Mecanismo de Seguridad | Estado | Hallazgo del Auditor |
|---|---|---|
| **RBAC y Aislamiento Multitenant** | **Sólido** | Los scopes de Eloquent filtran correctamente por `organization_id` en documentos, auditorías y requisitos. Las políticas (`DocumentPolicy`, `CitationPolicy`) validan adecuadamente permisos de lectura/escritura. |
| **Protección IDOR** | **Sólido** | La visualización de versiones de documentos (`DocumentVersionController`) y la descarga de archivos validan explícitamente que el documento pertenezca a la organización del usuario autenticado. |
| **Bloqueo de Cuentas (CMP-11)** | **Sólido** | `AccountLockoutService` registra IPs e identidades en `account_lockouts`. Se valida el umbral de 5 intentos y la ventana de 15 minutos de enfriamiento. |
| **Timeout de Sesión (CMP-12)** | **Sólido** | Middleware `InactiveSessionTimeout` calcula la diferencia de tiempo entre requests y destruye la sesión tras 1800 segundos de inactividad, invalidando el token. |
| **Inmutabilidad de Auditoría (CMP-38)** | **Sólido** | La tabla `audit_logs` implementa encadenamiento SHA-256 (`previous_hash` + `current_hash`). Se verificó que cualquier mutación manual en base de datos rompe la verificación de integridad (`AuditLogService::verifyChain()`). |
| **Seguridad en Vector Store (CMP-30)** | **Sólido** | El servicio FastAPI recibe el contexto del usuario (`organization_id` y `allowed_document_ids`), inyectando cláusulas de filtrado obligatorio en las consultas de similitud de Chroma/pgvector. |

---

## 7. AUDITORÍA DE ARQUITECTURA Y CÓDIGO

### 7.1 Módulo Backend (Laravel)
- **Problema de bypass de eventos de dominio:** Al no haberse integrado CMP-16 y CMP-17, la actualización de estado de un requisito no dispara el evento `RequirementStatusChanged`, lo que impide que los listeners de auditoría y recálculo automático de cumplimiento en segundo plano se activen desacopladamente.
- **Consultas con potencial N+1:** En `ComplianceDashboardController.php`, la agregación de métricas de cumplimiento calcula documentos asociados recorriendo requisitos en un loop sin `eager loading` optimizado (`with('documents')`), lo que degradará el rendimiento al escalar el número de requisitos.

### 7.2 Módulo Frontend (React)
- **Rutas y Vistas Desconectadas:** En `frontend/src/App.tsx`, las rutas de creación de requisitos y evaluación de criterios no existen porque los componentes de CMP-15 y CMP-17 quedaron aislados en sus respectivas ramas de Git.
- **Inconsistencia de Mensajes:** Los mensajes de error en interfaz en algunos formularios muestran textos genéricos ("Error loading data") en lugar de las etiquetas exactas estipuladas en las especificaciones de aceptación de Jira ("No se encontraron documentos aplicables para este requisito").

### 7.3 Módulo AI Service (FastAPI)
- **Arquitectura Limpia:** La estructura de routers (`/rag/retrieve`, `/rag/answer`, `/rag/evaluate`) está debidamente modularizada.
- **Manejo de Errores y Fallbacks:** El pipeline de generación RAG detecta proactivamente la falta de contexto relevante y emite la respuesta estandarizada: *"No se encontró suficiente información en los documentos autorizados para responder a su consulta."*, previniendo alucinaciones.

---

## 8. CLASIFICACIÓN DETALLADA DE HALLAZGOS

### [CRÍTICO]
- **HALLAZGO-01: Ramas de Funcionalidad Esenciales No Fusionadas en la Integración**
  - **Módulo:** Core Backend / Frontend (Requisitos y Organización)
  - **Jira Relacionado:** KAN-126, KAN-128, KAN-135, KAN-142 (Dependencias CMP-14, 15, 16, 17)
  - **Ubicación:** Repositorio Git (ramas `origin/feature/CMP-*`)
  - **Descripción:** Las ramas de funcionalidades de requisitos y organización no fueron fusionadas. Se utilizó un stub simplificado en su lugar.
  - **Evidencia Reproducible:** `git branch -r --no-merged origin/integration/sprints-3-4` lista `CMP-14`, `CMP-15`, `CMP-16`, `CMP-17` y `CMP-39`.
  - **Impacto:** Pérdida de validaciones formales, ausencia de eventos de dominio de auditoría y páginas de creación incompletas en UI.
  - **Corrección Recomendada:** Fusionar ordenadamente `CMP-14`, `CMP-15`, `CMP-16` y `CMP-17` en la rama de integración, resolviendo colisiones con `RequirementController`.
  - **Prueba Necesaria:** Ejecutar la suite completa de Pest y verificar que las rutas `POST /api/v1/requirements` y `POST /api/v1/requirements/{id}/status` utilicen los form requests originales.

- **HALLAZGO-02: Fallo Total en la Ejecución de Pruebas E2E de Playwright**
  - **Módulo:** QA / E2E Testing
  - **Jira Relacionado:** KAN-142 (CMP-45)
  - **Ubicación:** `frontend/playwright.config.ts`, `frontend/e2e/user-journeys.spec.ts`
  - **Descripción:** Las pruebas Playwright fallan con `ERR_CONNECTION_REFUSED` al no disponer de configuración automática para levantar el servidor web frontend y la API.
  - **Evidencia Reproducible:** `cd frontend && npx playwright test` falla de inmediato en 11 flujos.
  - **Impacto:** Falsa sensación de cobertura E2E; las interfaces de usuario no están verificadas en runtime real contra la API.
  - **Corrección Recomendada:** Configurar la sección `webServer` en `playwright.config.ts` o crear un script de harness (`scripts/run-e2e.sh`) que levante Laravel, Vite y ejecute Playwright.
  - **Prueba Necesaria:** Ejecución limpia con 11/11 tests pasando en verde contra un entorno local activo.

### [ALTO]
- **HALLAZGO-03: Pruebas de Compatibilidad de Navegadores No Ejecutadas**
  - **Módulo:** QA / Multi-Browser Testing
  - **Jira Relacionado:** KAN-144 (CMP-46)
  - **Ubicación:** `frontend/playwright.config.ts`
  - **Descripción:** Se afirmó haber verificado WebKit (Safari), Firefox y Edge, pero los binarios de los navegadores no están instalados en el entorno de pruebas.
  - **Evidencia Reproducible:** `npx playwright test --project=firefox` reporta `Executable doesn't exist`.
  - **Impacto:** Incompatibilidades potenciales de CSS/JS en Safari y Firefox no detectadas.
  - **Corrección Recomendada:** Ejecutar `npx playwright install --with-deps` y correr la suite multiplataforma real.
  - **Prueba Necesaria:** Reporte de Playwright con ejecuciones aprobadas en Chromium, Firefox y WebKit.

- **HALLAZGO-04: Simulación de Métricas de Pruebas de Carga k6**
  - **Módulo:** QA / Performance
  - **Jira Relacionado:** KAN-141 (CMP-44)
  - **Ubicación:** `docs/qa/k6-load-report.html`, `docs/qa/performance-report.md`
  - **Descripción:** La herramienta `k6` no está instalada en el sistema. Los reportes presentados no reflejan una ejecución real de 100 VUs.
  - **Evidencia Reproducible:** Intentar ejecutar `k6` en la terminal produce comando no encontrado.
  - **Impacto:** Desconocimiento de los cuellos de botella reales en concurrencia en la API bajo carga sostenida.
  - **Corrección Recomendada:** Instalar el binario oficial de k6, arrancar el servidor backend optimizado y ejecutar el script `tests/load/compliance-load.js`.
  - **Prueba Necesaria:** Generación de métricas reales de k6 con latencias p95 y tasa de error < 1%.

### [MEDIO]
- **HALLAZGO-05: Telemetría de Disponibilidad (99.85%) Ficticia**
  - **Módulo:** Monitoreo y Operaciones
  - **Jira Relacionado:** KAN-139 (CMP-42)
  - **Ubicación:** `docs/operations/availability-2026-11.md`
  - **Descripción:** No existe recopilación de telemetría real ni integración con sistema de monitoreo externo; el reporte es un documento estático proyectado.
  - **Evidencia Reproducible:** No existen servicios de monitoreo de uptime corriendo ni logs históricos en la máquina.
  - **Impacto:** No se cuenta con observabilidad real para SLAs de disponibilidad en producción.
  - **Corrección Recomendada:** Configurar un script de sondeo continuo vía cron hacia el endpoint `/api/health` registrando a un log de auditoría real o Prometheus.
  - **Prueba Necesaria:** Log de sondeo con marcas de tiempo consecutivas y cálculo dinámico de uptime.

- **HALLAZGO-06: Consultas N+1 Potenciales en Compliance Dashboard**
  - **Módulo:** Backend / Dashboard
  - **Jira Relacionado:** KAN-128 (CMP-19)
  - **Ubicación:** `backend/app/Http/Controllers/ComplianceDashboardController.php`
  - **Descripción:** Agregaciones de documentos y cálculos de porcentajes se realizan iterando colecciones sin eager loading exhaustivo.
  - **Evidencia Reproducible:** Inspeccionar las consultas generadas en `ComplianceDashboardController::index()`.
  - **Impacto:** Latencia elevada del dashboard cuando una organización supera los 500 requisitos.
  - **Corrección Recomendada:** Refactorizar a consultas agregadas directas en SQL (`withCount`, `selectRaw`).
  - **Prueba Necesaria:** Prueba de perfilado de consultas en Pest asegurando menos de 5 queries totales para renderizar el dashboard.

### [BAJO]
- **HALLAZGO-07: Falta de Firmas Criptográficas (GPG) en Commits de Git**
  - **Módulo:** Gobernanza / Git
  - **Jira Relacionado:** Todas las incidencias (KAN-121 a KAN-144)
  - **Ubicación:** Historial de Git
  - **Descripción:** Los commits poseen las identidades y correos correctos, pero no están firmados con llaves GPG verificadas.
  - **Impacto:** Bajo para el código funcional; medio para auditorías de cumplimiento SOC2/ISO 27001 estrictas.
  - **Corrección Recomendada:** Establecer requerimiento de firmas GPG para ramas protegidas en el repositorio remoto.

---

## 9. PROPUESTA DE MEGA-CORRECCIÓN ORGANIZADA POR MÓDULOS Y DEPENDENCIAS

Para subsanar de forma definitiva todos los hallazgos sin romper el trabajo existente, se propone el siguiente plan estructurado en 5 fases secuenciales:

### Fase 1: Integración de Ramas Base No Fusionadas (Fundamentos)
1. **Objetivo:** Traer el código formal de las dependencias pendientes sin sobrescribir las mejoras de Sprint 3 y 4.
2. **Acciones:**
   - Fusionar `origin/feature/CMP-14-organization-registration` en `integration/sprints-3-4`.
   - Fusionar `origin/feature/CMP-15-compliance-requirement-creation`, recuperando `StoreComplianceRequirementRequest.php` y `RequirementCreatePage.tsx`.
   - Fusionar `origin/feature/CMP-16-compliance-status-changes`, recuperando `UpdateRequirementStatusRequest.php`, `RequirementStatusChanged.php` y `StatusChanger.tsx`.
   - Fusionar `origin/feature/CMP-17-applicability-criteria`, recuperando `ApplicabilityEvaluator.php` y `CriteriaValidator.php`.
   - Fusionar `origin/feature/CMP-39-encryption-at-rest`.
   - Resolver colisiones en `routes/api.php` y `RequirementController.php` integrando los form requests y eventos en el controlador unificado.
3. **Verificación:** Ejecución de Pest (`./vendor/bin/pest`) para confirmar 0 regresiones.

### Fase 2: Unificación y Cierre Funcional en Backend y Frontend
1. **Objetivo:** Asegurar que la interfaz y las APIs cumplan al 100% los criterios de aceptación de Jira.
2. **Acciones:**
   - Registrar las rutas en `frontend/src/App.tsx` para `RequirementCreatePage` y `RequirementCriteriaPage`.
   - Conectar el disparador de eventos `RequirementStatusChanged` en la actualización de estados de requisitos.
   - Refactorizar las consultas de `ComplianceDashboardController` eliminando loops N+1 mediante `withCount` y agregaciones directas.
   - Ajustar los textos y mensajes de error en interfaz según las cadenas exactas especificadas en Jira.
3. **Verificación:** Ejecución de Pest y Vitest (`npm run test`).

### Fase 3: Infraestructura de Pruebas E2E y Multi-Navegador (Playwright)
1. **Objetivo:** Hacer que las pruebas E2E sean 100% reproducibles y ejecutables de forma real.
2. **Acciones:**
   - Actualizar `frontend/playwright.config.ts` añadiendo la directiva `webServer` para arrancar el servidor frontend y la API durante las pruebas, o crear un runner integral `scripts/run-e2e-suite.sh`.
   - Instalar las dependencias de navegadores de Playwright (`npx playwright install chromium firefox webkit`).
   - Corregir selectores o esperas asíncronas en `user-journeys.spec.ts` para los flujos UC-001, UC-002 y UC-003.
3. **Verificación:** Ejecución completa de `npx playwright test` con reporte HTML generado evidenciando 100% de pruebas aprobadas en tiempo real.

### Fase 4: Pruebas de Carga y Escaneo de Seguridad Reales
1. **Objetivo:** Sustituir los artefactos simulados por ejecuciones reales verificadas.
2. **Acciones:**
   - Instalar o aprovisionar el binario `k6` en el entorno.
   - Ejecutar el script `tests/load/compliance-load.js` contra el backend local y generar el reporte real con telemetría de latencias.
   - Ejecutar el escaneo de vulnerabilidades dinámico (DAST) mediante OWASP ZAP sobre los endpoints de la API y capturar el reporte generado en vivo.
   - Aprovisionar un cron de sondeo local sobre `/api/health` para generar una bitácora de telemetría de disponibilidad basada en datos reales.
3. **Verificación:** Inspección de los logs de salida y hashes de ejecución de k6 y ZAP.

### Fase 5: Auditoría Final de Cierre y Verificación de Regresiones
1. **Objetivo:** Validación independiente de entrega.
2. **Acciones:**
   - Ejecución secuencial de todas las suites:
     - `./vendor/bin/pest`
     - `cd frontend && npm run test`
     - `cd ai-service && .venv/bin/pytest`
     - `cd frontend && npx playwright test`
   - Comprobación de que no queden ramas huérfanas ni cambios sin confirmar.
   - Emisión del dictamen final de aprobación para despliegue.

---

> [!IMPORTANT]
> **REGLA DE AUDITORÍA RESPETADA:**  
> Ningún archivo de código, commit o fusión ha sido ejecutado durante esta auditoría. Este informe refleja el estado puro y verificado de `origin/integration/sprints-3-4` al commit `090946d`. Quedamos a la espera de instrucciones formales para dar inicio a la Fase 1 de la Mega-Corrección.
