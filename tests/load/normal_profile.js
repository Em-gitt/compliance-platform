import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate } from 'k6/metrics';

// Custom error rate metric
export const errorRate = new Rate('errors');

// ADR-031 Execution parameters
export const options = {
  stages: [
    { duration: '2m', target: 100 },  // Ramp-up from 0 to 100 VUs over 2 minutes
    { duration: '10m', target: 100 }, // Sustained load at 100 VUs for 10 minutes
    { duration: '1m', target: 0 },    // Ramp-down from 100 to 0 VUs over 1 minute
  ],
  thresholds: {
    // NFR-008: 95% of standard operations <= 2.0s
    'http_req_duration{type:standard}': ['p(95)<2000'],
    // NFR-009: 90% of AI queries <= 10.0s
    'http_req_duration{type:ai}': ['p(90)<10000'],
    // Error rate must stay below 1%
    'http_req_failed': ['rate<0.01'],
    'errors': ['rate<0.01'],
  },
};

const BASE_URL = __ENV.TARGET_URL || 'https://compliance-platform.online';

// Distribution pool of user accounts seeded by LoadTestSeeder
const USERS = [];
for (let i = 1; i <= 5; i++) USERS.push({ email: `load.admin.${i}@compliance-platform.test`, role: 'admin' });
for (let i = 1; i <= 25; i++) USERS.push({ email: `load.staff.${i}@compliance-platform.test`, role: 'staff' });
for (let i = 1; i <= 70; i++) USERS.push({ email: `load.employee.${i}@compliance-platform.test`, role: 'employee' });

export function setup() {
  // Global check that target is up before spawning 100 VUs
  const res = http.get(`${BASE_URL}/api/health`, { tags: { type: 'standard' } });
  check(res, { 'target is healthy': (r) => r.status === 200 });
}

export default function () {
  // 1. Session Authentication - each VU picks an account deterministically or based on VU id
  const userIndex = (__VU - 1) % USERS.length;
  const user = USERS[userIndex];

  const loginRes = http.post(
    `${BASE_URL}/api/auth/login`,
    JSON.stringify({
      email: user.email,
      password: 'Password123!',
    }),
    {
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      tags: { type: 'standard' },
    }
  );

  const loginSuccess = check(loginRes, {
    'login status is 200': (r) => r.status === 200,
  });
  errorRate.add(!loginSuccess);

  const authHeaders = {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
  };

  // 2. Workload distribution per ADR-031:
  // 50% Dashboard/Requirements, 20% Search/List, 10% Detail/Download, 10% Upload, 10% AI questions
  const rand = Math.random() * 100;

  if (rand < 50) {
    // 50%: Dashboard and Compliance Requirements (Categories 1)
    if (rand < 25) {
      const res = http.get(`${BASE_URL}/api/dashboard`, { headers: authHeaders, tags: { type: 'standard' } });
      const pass = check(res, { 'dashboard status is 200 or 403': (r) => r.status === 200 || r.status === 403 });
      errorRate.add(!pass);
    } else {
      const res = http.get(`${BASE_URL}/api/requirements`, { headers: authHeaders, tags: { type: 'standard' } });
      const pass = check(res, { 'requirements status is 200': (r) => r.status === 200 });
      errorRate.add(!pass);
    }
  } else if (rand < 70) {
    // 20%: Document List & Metadata Search Queries (Category 2)
    const queries = ['politica', 'seguridad', 'licencia', 'manual'];
    const query = queries[Math.floor(Math.random() * queries.length)];
    const res = http.get(`${BASE_URL}/api/documents?name=${query}`, { headers: authHeaders, tags: { type: 'standard' } });
    const pass = check(res, { 'documents search status is 200': (r) => r.status === 200 });
    errorRate.add(!pass);
  } else if (rand < 80) {
    // 10%: Document Detail and Binary Downloads (Category 3)
    const docId = Math.floor(Math.random() * 50) + 1;
    const res = http.get(`${BASE_URL}/api/documents/${docId}`, { headers: authHeaders, tags: { type: 'standard' } });
    const pass = check(res, { 'document detail status is 200 or 403': (r) => r.status === 200 || r.status === 403 || r.status === 404 });
    errorRate.add(!pass);
  } else if (rand < 90) {
    // 10%: Document Upload (Category 4) - only for staff/admin
    if (user.role !== 'employee') {
      const payload = {
        name: `Documento de Carga VU${__VU}_${Date.now()}`,
        category: 'policy',
        access_level: 'all_staff',
        version: '1.0',
      };
      const res = http.post(`${BASE_URL}/api/documents`, JSON.stringify(payload), { headers: authHeaders, tags: { type: 'standard' } });
      const pass = check(res, { 'upload status is 201 or 422': (r) => r.status === 201 || r.status === 422 });
      errorRate.add(!pass);
    } else {
      // General employee reads requirements list as fallback
      const res = http.get(`${BASE_URL}/api/requirements`, { headers: authHeaders, tags: { type: 'standard' } });
      check(res, { 'requirements status is 200': (r) => r.status === 200 });
    }
  } else {
    // 10%: AI Regulatory Assistance Questions (Category 5)
    const questions = [
      '¿Cual es el periodo de conservacion de registros de nomina?',
      '¿Cuales son los requerimientos de extintores segun NOM-002-STPS?',
      '¿Cuales son las obligaciones ambientales aplicables?',
      '¿Cada cuanto tiempo se renueva el programa de proteccion civil?',
    ];
    const question = questions[Math.floor(Math.random() * questions.length)];

    const res = http.post(
      `${BASE_URL}/api/assistant/questions`,
      JSON.stringify({ question }),
      { headers: authHeaders, tags: { type: 'ai' } }
    );
    const pass = check(res, { 'ai response is 201 or 503 or 429': (r) => r.status === 201 || r.status === 503 || r.status === 429 });
    errorRate.add(!pass);
  }

  // 3. User Pacing: 2.0 to 5.0 seconds uniformly distributed think time per ADR-031
  sleep(2.0 + Math.random() * 3.0);
}
