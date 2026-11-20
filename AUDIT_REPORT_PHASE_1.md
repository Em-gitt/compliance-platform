# INFORME DE AUDITORÍA TÉCNICA INDEPENDIENTE – FASE 1
**Integración de ramas:** CMP-14, CMP-15, CMP-16, CMP-17 y CMP-39  
**Rama auditada:** `integration/sprints-3-4`  
**Commit evaluado:** `12a8820` (`fix(security): protect responsible_user contact details based on role and request type`)  
**Fecha de auditoría:** 19 de noviembre de 2026  
**Auditor:** Agente Técnico Senior Independiente de Control de Calidad y Seguridad  

---

## DICTAMEN FINAL

### **NO APROBADO (CONDICIONAL / REQUIERE CORRECCIÓN MENOR)**

> **Justificación del dictamen:**  
> La integración técnica en Git, el cifrado de datos sensibles (CMP-39), el motor de aplicabilidad (CMP-17), el control de estados (CMP-16), los flujos de creación (CMP-15) y registro (CMP-14), así como la suite completa de backend en **Laravel/Pest (448/448 pasadas, 100%)** y **FastAPI/Pytest (99/99 pasadas, 100% de las no dependientes de DB externa)** son plenamente conformes.  
> Sin embargo, la suite de frontend en **React/Vitest presenta 3 pruebas fallidas** en el archivo `src/pages/RequirementCriteriaPage.test.tsx` (18 archivos pasados, 1 fallido; 153 pruebas pasadas, 3 fallidas).  
> Conforme al principio de tolerancia cero ante pruebas fallidas en una integración formal, la fase queda dictaminada como **NO APROBADA** hasta la aplicación y verificación de la corrección documentada en la Sección 8.

---

## 1. INTEGRIDAD DE GIT Y TRAZABILIDAD DE RAMAS

Se verificó el árbol de commits, referencias y estados de Git mediante comandos directos (`git status`, `git log`, `git merge-base`, `git rev-parse`).

### 1.1 Estado del Working Tree y Rama de Trabajo
- **Rama activa:** `integration/sprints-3-4`.
- **Working Tree:** Completamente limpio (`nothing to commit, working tree clean`).
- **Head commit:** `12a8820` (Fix de seguridad CMP-39 para detalles de contacto de `responsible_user`).

### 1.2 Integridad de la rama `develop`
- **Hash de `origin/develop`:** `23b8b5c83cd91e2a7bab5e30d3168e5b55f744a9` (PR #17 CMP-09).
- **Estado de `develop`:** **INTACTA**. No se ha realizado ninguna fusión directa ni modificación sobre `origin/develop` ni sobre la rama local `develop`.

### 1.3 Verificación de las 5 Ramas de Funcionalidad Integradas
Mediante `git merge-base --is-ancestor <commit> HEAD`, se comprobó que los commits cabecera de las 5 ramas de funcionalidad son ancestros directos de la rama de integración:

| Requisito | Rama Origen | Commit Rama | Merge Commit en `integration/sprints-3-4` | Estado de Integración |
|---|---|---|---|---|
| **CMP-14** | `origin/feature/CMP-14-organization-registration` | `6c70e12` | `6a8dc35` | **INTEGRADO Y ANCESTRO DIRECTO** |
| **CMP-15** | `origin/feature/CMP-15-compliance-requirement-creation` | `46deac6` | `1d162bd` | **INTEGRADO Y ANCESTRO DIRECTO** |
| **CMP-16** | `origin/feature/CMP-16-compliance-status-changes` | `9709ffe` | `36ac785` | **INTEGRADO Y ANCESTRO DIRECTO** |
| **CMP-17** | `origin/feature/CMP-17-applicability-criteria` | `351b229` | `0c9dc9a` | **INTEGRADO Y ANCESTRO DIRECTO** |
| **CMP-39** | `origin/feature/CMP-39-protect-sensitive-fields` | `c92f015` | `795756c` | **INTEGRADO Y ANCESTRO DIRECTO** |

### 1.4 Preservación de los 22 Commits Originales de Sprint 3 y 4
Se verificó que los 22 commits originales de Sprint 3 y Sprint 4 permanecen inalterados en el historial y son ancestros de la rama actual:
- Base: `6da7407` (KAN-121) a `090946d` (KAN-144).
- Ningún commit original fue reescrito, rebaseado ni eliminado (`rebase` no ejecutado).

### 1.5 Etiquetas y Puntos de Restauración
Se confirmó la existencia de las etiquetas de seguridad creadas antes de cada integración:
- `restore/pre-phase-1-base` -> apunta a `090946d` (KAN-144).
- `restore/pre-cmp-14` -> apunta a `090946d` (KAN-144).
- `restore/pre-cmp-15` -> apunta a `560ef1b` (Fix de alineación de roles y deduplicación de auditoría CMP-14).
- `restore/pre-cmp-16` -> apunta a `7e198dd` (Fix de integración de requisitos y deduplicación CMP-15).
- `restore/pre-cmp-17` -> apunta a `a1e6d86` (Fix de deduplicación de cambio de estado CMP-16).
- `restore/pre-cmp-39` -> apunta a `0c9dc9a` (Merge de criterios de aplicabilidad CMP-17).

---

## 2. AUDITORÍA DE EVENTOS Y TRAZABILIDAD

Se realizó un escaneo estático y dinámico de todos los usos de `withoutEvents()`, `saveQuietly()`, eventos de Eloquent (`dispatchesEvents`), Observers y `AuditLogger`.

### 2.1 Uso de `withoutEvents()`
- **Ubicaciones:**
  1. `backend/app/Http/Controllers/OrganizationController.php` (línea 31):
     ```php
     $organization = Organization::withoutEvents(fn () => Organization::create($payload));
     ```
  2. `backend/app/Http/Controllers/ComplianceRequirementController.php` (líneas 43 y 117):
     ```php
     $requirement = ComplianceRequirement::withoutEvents(fn () => ComplianceRequirement::create($payload));
     ```
     ```php
     ComplianceRequirement::withoutEvents(fn () => $requirement->update(['status' => $validated['status']]));
     ```
- **Evaluación técnica:**
  - El uso de `withoutEvents()` se introdujo porque existían Observers (`OrganizationObserver`, `ComplianceRequirementObserver`) que registraban logs de auditoría automáticos de forma redundante cuando los controladores llamaban explícitamente a `AuditLogger::log(...)`.
  - **No se suprimen eventos de negocio legítimos**: Las llamadas explícitas a `AuditLogger::log()` y `AuditLog::query()->create()` persisten los eventos de auditoría (`organization.registered`, `requirement.created`, `requirement.status_changed`) con sus metadatos exactos (`actor_id`, `actor_role`, `ip_address`, `changes`, etc.).
  - **Riesgo detectado (Medio)**: La supresión global con `withoutEvents()` apaga cualquier otro observer futuro que no sea de auditoría (por ejemplo, invalidación de cachés o alertas push).

### 2.2 Uso de `saveQuietly()`
- **Ubicaciones:**
  1. `backend/app/Http/Controllers/ApplicabilityCriteriaController.php` (línea 46):
     ```php
     $requirement->saveQuietly();
     ```
  2. `backend/app/Jobs/EvaluateApplicability.php` (línea 78):
     Evaluación en lote y guardado del estado de aplicabilidad.
- **Evaluación técnica:**
  - `ApplicabilityCriteriaController` dispara inmediatamente después el evento de dominio dedicado `CriteriaUpdated::dispatch($requirement)`.
  - Por lo tanto, el flujo de negocio no pierde trazabilidad; se registra explícitamente en `AuditLog` la acción `requirement.criteria_updated`.

### 2.3 Registro y Consulta de Auditoría
- Se verificó que todas las operaciones críticas de CMP-14, CMP-15, CMP-16, CMP-17 y CMP-39 generan registros en la tabla `audit_logs`.
- La inmutabilidad de la tabla `audit_logs` (prohibición de `UPDATE` y `DELETE` mediante triggers y políticas) se mantiene intacta y verificada por la prueba `SecurityAuditLoggingTest`.

---

## 3. AUDITORÍA DEL MOTOR DE APLICABILIDAD (CMP-17)

Se inspeccionó `backend/app/Services/Compliance/ApplicabilityEvaluator.php`, `CriteriaValidator.php`, `EvaluateApplicability.php` y su integración en `AppServiceProvider.php`.

### 3.1 Manejo Síncrono vs Asíncrono
- En `backend/app/Providers/AppServiceProvider.php`:
  ```php
  if (app()->runningUnitTests()) {
      EvaluateApplicability::dispatchSync($organization);
  } else {
      EvaluateApplicability::dispatch($organization)->afterCommit();
  }
  ```
- **Confirmaciones:**
  - **Producción:** En entorno real (`runningUnitTests() === false`), el job `EvaluateApplicability` se encola asíncronamente con directiva `afterCommit()`, garantizando que la transacción de base de datos haya finalizado antes de que el worker procese la organización o el requisito.
  - **Pruebas:** En pruebas de integración, `dispatchSync` se ejecuta dentro del ciclo de vida de `RefreshDatabase` sin requerir workers de cola activos.
  - **Evaluación de criterios:** Se validaron operadores numéricos (`eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `between`), operadores de colección (`in`, `not_in`, `contains_any`), y evaluación recursiva de grupos booleanos (`all`, `any`, anidados). Las 37 pruebas unitarias de `ApplicabilityEvaluatorTest` y 13 de `ApplicabilityCriteriaTest` pasan al 100%.

---

## 4. AUDITORÍA DE PROTECCIÓN DE DATOS SENSIBLES (CMP-39)

Se verificó exhaustivamente el esquema de cifrado, serialización y respuesta de datos personales y organizacionales.

### 4.1 Cifrado en Base de Datos
- **Modelo `Organization` (`backend/app/Models/Organization.php`):**
  - Campos cifrados en `$casts`:
    - `tax_id => 'encrypted'`
    - `legal_representative_name => 'encrypted'`
    - `fiscal_address => 'encrypted'`
  - Campos ocultos en `$hidden`:
    - `tax_id`, `legal_representative_name`, `fiscal_address`.
- **Modelo `User` (`backend/app/Models/User.php`):**
  - Campos cifrados en `$casts`:
    - `phone => 'encrypted'`
  - Campos ocultos en `$hidden`:
    - `password`, `remember_token`, `phone`.

### 4.2 Respuestas de API y Control de Acceso RBAC
- **Endpoint `/api/users`:**
  - Solo accesible por rol `Administrator`. Los roles `Compliance Staff` y `General Employee` reciben `403 Forbidden`.
  - Serialización mediante `UserResource`: El número telefónico se entrega enmascarado con el formato `*** *** 5678`.
- **Endpoint `/api/compliance-requirements/{id}`:**
  - En `ComplianceRequirementDetailResource`:
    - Para `general_employee` o peticiones `POST`: El objeto `responsible_user` expone exclusivamente `id` y `name`, eliminando `email` y `phone`.
    - Para `administrator` y `compliance_staff` en consultas de detalle (`GET`): Se incluye información de contacto necesaria para gestión interna.
- **Sanitización de Logs:**
  - `backend/app/Logging/SanitizedLineFormatter.php` y `SanitizeSensitiveDataProcessor.php` filtran automáticamente patrones de correo, teléfono y contraseñas antes de escribir en `laravel.log`. La prueba `it_ensures_log_files_contain_no_email_phone_or_password_values` confirma 0 filtraciones.

---

## 5. RESULTADOS REALES DE EJECUCIÓN DE PRUEBAS

Todas las pruebas fueron ejecutadas directamente en el entorno de trabajo:

### 5.1 Suite Laravel / Pest
- **Comando:** `cd backend && ./vendor/bin/pest`
- **Resultado:** **448 pasadas, 0 fallidas (1959 aserciones)**
- **Tiempo de ejecución:** 196.89 segundos.
- **Pruebas omitidas:** 0.

### 5.2 Suite FastAPI / Pytest
- **Comando:** `cd ai-service && .venv/bin/pytest -v`
- **Resultado:** **99 pasadas, 20 omitidas, 0 fallidas (1 warning de Starlette)**
- **Tiempo de ejecución:** 24.75 segundos.
- **Pruebas omitidas:** 20 pruebas de integración de base de datos vectorial (pgvector) omitidas legítimamente por ausencia de la variable `TEST_DATABASE_URL` en el entorno local sin instancia de PostgreSQL dedicada.

### 5.3 Suite React / Vitest
- **Comando:** `cd frontend && npx vitest run`
- **Resultado:** **18 archivos pasados, 1 archivo fallido (153 pruebas pasadas, 3 pruebas fallidas de 156)**
- **Tiempo de ejecución:** 9.95 segundos.
- **Detalle del fallo:** Archivo `src/pages/RequirementCriteriaPage.test.tsx`:
  1. `RequirementCriteriaPage > shows "condition without field" and does not save` -> **FAIL**
  2. `RequirementCriteriaPage > shows "condition without operator" and does not save` -> **FAIL**
  3. `RequirementCriteriaPage > shows "condition without value" and does not save` -> **FAIL**

---

## 6. COMPARACIÓN CON LAS RAMAS ORIGINALES

| Rama Original | Verificación contra rama integrada | Hallazgos |
|---|---|---|
| **CMP-14** | Endpoints `POST /api/organizations`, catálogo de organizaciones, modelo, políticas de registro. | Sin pérdida de validaciones ni contratos. |
| **CMP-15** | Creación de requisitos, listados, catálogos, campos `responsible_user`, `mandatory`, `frequency`. | Sin regresiones; resource actualizado para proteger datos de contacto. |
| **CMP-16** | Cambio de estados, transiciones válidas, componente `StatusChanger.tsx`, rutas y validaciones. | Correctamente integrado en página de detalle. |
| **CMP-17** | Criterios de aplicabilidad en JSON (ADR-021), validaciones, evaluación y vistas React. | Fallo en 3 aserciones del test unitario de frontend (ver Hallazgo H-01). |
| **CMP-39** | Cifrado en base de datos, rotación de llaves, enmascaramiento y sanitizadores Monolog. | Plenamente funcional y verificado. |

---

## 7. CLASIFICACIÓN DE HALLAZGOS

### [ALTO] H-01: Condición de carrera en suite de pruebas de `RequirementCriteriaPage.test.tsx`
- **Archivo afectado:** `frontend/src/pages/RequirementCriteriaPage.test.tsx` (líneas 124-141) y `frontend/src/pages/RequirementCriteriaPage.tsx` (líneas 144-153).
- **Evidencia:**  
  Al ejecutarse en paralelo con el resto de la suite de Vitest, la petición asíncrona de React Query `useQuery(['requirements', id])` resuelve milisegundos después de que la prueba hace clic en el radio button `Use criteria`. Como el criterio almacenado en el mock es `{ all: [] }`, el hook `useEffect` sobreescribe el estado local reactivo y vuelve a marcar `useCriteria = false`. Al pulsar `Save criteria`, las validaciones de campos se omiten (porque `use_criteria` está desactivado), la mutación intenta invocar `saveCriteria` y arroja:
  `TypeError: Cannot read properties of undefined (reading 'data')`.
- **Impacto:** Falla la suite automatizada de Vitest (3 pruebas fallidas). En la interfaz de usuario, si una carga de datos ocurre mientras el usuario interactúa rápidamente, el radio button puede deseleccionarse inesperadamente.

---

### [MEDIO] H-02: Supresión global de eventos de Eloquent mediante `withoutEvents()`
- **Archivo afectado:** `backend/app/Http/Controllers/OrganizationController.php:31`, `backend/app/Http/Controllers/ComplianceRequirementController.php:43,117`.
- **Evidencia:**  
  Para evitar que los Observers (`OrganizationObserver`, `ComplianceRequirementObserver`) generaran registros duplicados en `audit_logs`, se envolvió la persistencia en `withoutEvents()`.
- **Impacto:** Si en futuras fases se agregan nuevos listeners de Eloquent sobre estos modelos (para webhooks, sincronización o notificaciones), no se ejecutarán a menos que se elimine el wrapper.

---

### [BAJO] H-03: Asimetría en la serialización del teléfono en `UserController`
- **Archivo afectado:** `backend/app/Http/Controllers/UserController.php`.
- **Evidencia:**  
  El método `index()` retorna `UserResource::collection($users)`, el cual aplica `maskPhone()` devolviendo `*** *** 5678`. En contraste, los métodos `store()` y `update()` retornan la instancia directa `$user`. Dado que `phone` está en el array `$hidden` del modelo `User`, el teléfono simplemente no aparece en el JSON de respuesta en lugar de aparecer enmascarado.
- **Impacto:** No hay fuga de datos (el dato sensible no se expone), pero genera una ligera discrepancia de contrato entre la creación y el listado de usuarios.

---

### [BAJO] H-04: Bifurcación condicional de entorno en `AppServiceProvider`
- **Archivo afectado:** `backend/app/Providers/AppServiceProvider.php:28`.
- **Evidencia:**  
  Se incluye `if (app()->runningUnitTests())` para despachar `EvaluateApplicability::dispatchSync($organization)` en lugar de encolarlo con `afterCommit()`.
- **Impacto:** Es una solución funcional para SQLite en memoria y `RefreshDatabase`, pero introduce lógica específica de pruebas en el proveedor principal de la aplicación.

---

## 8. CORRECCIONES NECESARIAS (SIN IMPLEMENTAR)

Conforme a las restricciones obligatorias de esta auditoría, **las siguientes correcciones NO han sido implementadas en el código y quedan documentadas para su ejecución en la fase de resolución de hallazgos**:

1. **Corrección de H-01 (`RequirementCriteriaPage.test.tsx`):**
   - En `frontend/src/pages/RequirementCriteriaPage.test.tsx`, en el bloque `mockApi()`, definir un mock por defecto para `api.put` (`vi.mocked(api.put).mockResolvedValue({ data: { message: 'saved' } })`).
   - En la prueba parametrizada `it.each`, esperar a que los datos del requisito se hayan cargado antes de interactuar con el formulario:
     ```tsx
     await screen.findByRole('heading', { name: 'Applicability criteria' });
     await screen.findByText('Large manufacturing safety program');
     ```
   - En `frontend/src/pages/RequirementCriteriaPage.tsx`, evitar resetear `useCriteria` si el usuario ya modificó el formulario, o condicionar la inicialización únicamente cuando `!loaded && requirement !== undefined`.

2. **Refactorización de H-02 (Arquitectura de Auditoría):**
   - Retirar `withoutEvents()` de los controladores y unificar la responsabilidad del registro de auditoría en los Observers de Eloquent o en un servicio de dominio único, evitando llamadas dobles y manteniendo activos los eventos estándar del framework.

3. **Homologación de H-03 (`UserController`):**
   - Envolver el retorno de `store()` y `update()` en `new UserResource($user)` para mantener uniformidad en la representación del recurso.
