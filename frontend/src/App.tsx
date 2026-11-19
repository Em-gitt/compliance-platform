import { Navigate, Route, Routes } from 'react-router-dom'
import Header from './components/Header'
import IdleTimeoutModal from './components/IdleTimeoutModal'
import ProtectedRoute from './components/ProtectedRoute'
import AlertsPage from './pages/AlertsPage'
import AssistantPage from './pages/AssistantPage'
import AuditLogPage from './pages/AuditLogPage'
import DashboardPage from './pages/DashboardPage'
import DocumentDetailPage from './pages/DocumentDetailPage'
import DocumentsListPage from './pages/DocumentsListPage'
import DocumentUploadPage from './pages/DocumentUploadPage'
import ForbiddenPage from './pages/ForbiddenPage'
import HomePage from './pages/HomePage'
import LoginPage from './pages/LoginPage'
import OrganizationCreatePage from './pages/OrganizationCreatePage'
import OrganizationDetailPage from './pages/OrganizationDetailPage'
import RequirementCreatePage from './pages/RequirementCreatePage'
import RequirementDetailPage from './pages/RequirementDetailPage'
import RequirementsListPage from './pages/RequirementsListPage'
import UsersPage from './pages/UsersPage'

export default function App() {
  return (
    <>
      <IdleTimeoutModal />
      <Header />
      <Routes>
        <Route path="/login" element={<LoginPage />} />
        <Route path="/403" element={<ForbiddenPage />} />
        <Route element={<ProtectedRoute />}>
          <Route path="/" element={<HomePage />} />
          <Route path="/alerts" element={<AlertsPage />} />
          <Route element={<ProtectedRoute ability="manage-organization" />}>
            <Route path="/organizations/new" element={<OrganizationCreatePage />} />
          </Route>
          <Route path="/organizations/:id" element={<OrganizationDetailPage />} />
          <Route element={<ProtectedRoute ability="view-dashboard" />}>
            <Route path="/dashboard" element={<DashboardPage />} />
          </Route>
          <Route element={<ProtectedRoute ability="use-ai-assistant" />}>
            <Route path="/assistant" element={<AssistantPage />} />
          </Route>
          <Route element={<ProtectedRoute ability="upload-documents" />}>
            <Route path="/documents/new" element={<DocumentUploadPage />} />
          </Route>
          <Route element={<ProtectedRoute ability="view-documents" />}>
            <Route path="/documents" element={<DocumentsListPage />} />
            <Route path="/documents/:id" element={<DocumentDetailPage />} />
          </Route>
          <Route element={<ProtectedRoute ability="manage-requirements" />}>
            <Route path="/requirements" element={<RequirementsListPage />} />
            <Route path="/requirements/new" element={<RequirementCreatePage />} />
            <Route path="/requirements/:id" element={<RequirementDetailPage />} />
          </Route>
          <Route element={<ProtectedRoute ability="manage-users" />}>
            <Route path="/admin/users" element={<UsersPage />} />
            <Route path="/admin/audit-log" element={<AuditLogPage />} />
          </Route>
        </Route>
        <Route path="*" element={<Navigate to="/dashboard" replace />} />
      </Routes>
    </>
  )
}
  )
}
