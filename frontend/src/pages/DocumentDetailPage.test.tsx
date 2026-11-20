import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { api } from '../lib/api'
import DocumentDetailPage from './DocumentDetailPage'

let mockUser: { id: number; name: string; role: string; organization_id: number } | null = {
  id: 1,
  name: 'Admin User',
  role: 'compliance_staff',
  organization_id: 1,
}

vi.mock('../lib/api', () => ({
  api: {
    get: vi.fn(),
    post: vi.fn(),
  },
}))

vi.mock('../auth/AuthContext', () => ({
  useAuth: () => ({
    user: mockUser,
    isLoading: false,
    setUser: vi.fn(),
    refreshUser: vi.fn(),
  }),
}))

describe('DocumentDetailPage', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    mockUser = {
      id: 1,
      name: 'Admin User',
      role: 'compliance_staff',
      organization_id: 1,
    }
  })

  afterEach(() => {
    cleanup()
  })

  it('renders all document metadata correctly for authorized users', async () => {
    vi.mocked(api.get).mockResolvedValue({
      data: {
        id: 15,
        name: 'Fire Safety Operating License',
        category: 'permit_license',
        category_name: 'Permit/License',
        access_level: 'all_staff',
        visibility: 'All staff',
        current_version: '1.2.0',
        expiration_date: '2027-05-15',
        expiration_date_formatted: '15/05/2027',
        uploaded_by: 'Jane Doe',
        upload_date: '04/11/2026 10:30',
        processing_status: 'processed',
        versions: [
          {
            id: 1,
            version: '1.2.0',
            uploaded_by: 'Jane Doe',
            upload_date: '04/11/2026 10:30',
            status: 'processed',
            expiration_date: '15/05/2027',
            is_current: true,
          },
        ],
      },
    })

    render(
      <MemoryRouter initialEntries={['/documents/15']}>
        <Routes>
          <Route path="/documents/:id" element={<DocumentDetailPage />} />
        </Routes>
      </MemoryRouter>,
    )

    // Name
    expect(await screen.findByTestId('doc-name')).toHaveTextContent(
      'Fire Safety Operating License',
    )
    // Category
    expect(screen.getByTestId('doc-category')).toHaveTextContent('Permit/License')
    // Current version
    expect(screen.getByTestId('doc-version')).toHaveTextContent('1.2.0')
    // Visibility
    expect(screen.getByTestId('doc-visibility')).toHaveTextContent('All staff')
    // Expiration date
    expect(screen.getByTestId('doc-expiration')).toHaveTextContent('15/05/2027')
    // Uploaded by
    expect(screen.getByTestId('doc-uploaded-by')).toHaveTextContent('Jane Doe')
    // Upload date
    expect(screen.getByTestId('doc-upload-date')).toHaveTextContent('04/11/2026 10:30')
    // Processing status
    expect(screen.getByTestId('doc-status')).toHaveTextContent('processed')
  })

  it('renders "No expiration" when expiration date is not set', async () => {
    vi.mocked(api.get).mockResolvedValue({
      data: {
        id: 20,
        name: 'Internal Information Security Policy',
        category: 'policy',
        category_name: 'Policy',
        access_level: 'compliance_only',
        visibility: 'Compliance only',
        current_version: '1.0',
        expiration_date: null,
        expiration_date_formatted: 'No expiration',
        uploaded_by: 'Admin User',
        upload_date: '01/11/2026 09:00',
        processing_status: 'queued',
        versions: [],
      },
    })

    render(
      <MemoryRouter initialEntries={['/documents/20']}>
        <Routes>
          <Route path="/documents/:id" element={<DocumentDetailPage />} />
        </Routes>
      </MemoryRouter>,
    )

    expect(await screen.findByTestId('doc-name')).toHaveTextContent(
      'Internal Information Security Policy',
    )
    expect(screen.getByTestId('doc-expiration')).toHaveTextContent('No expiration')
    expect(screen.getByTestId('doc-visibility')).toHaveTextContent('Compliance only')
  })

  it('displays forbidden error message when user lacks permission to view document', async () => {
    vi.mocked(api.get).mockRejectedValue({
      isAxiosError: true,
      response: {
        status: 403,
        data: {
          message: 'You do not have permission to view this document.',
        },
      },
    })

    render(
      <MemoryRouter initialEntries={['/documents/99']}>
        <Routes>
          <Route path="/documents/:id" element={<DocumentDetailPage />} />
        </Routes>
      </MemoryRouter>,
    )

    expect(
      await screen.findByText('You do not have permission to view this document.'),
    ).toBeInTheDocument()
  })

  it('renders Versions tab with all required columns, Current badge, and download button', async () => {
    vi.mocked(api.get).mockResolvedValue({
      data: {
        id: 15,
        name: 'Fire Safety Operating License',
        category: 'permit_license',
        access_level: 'all_staff',
        current_version: '2.0',
        versions: [
          {
            id: 2,
            version: '2.0',
            uploaded_by: 'Jane Doe',
            upload_date: '10/11/2026 14:00',
            status: 'processed',
            expiration_date: '2028-05-15',
            is_current: true,
          },
          {
            id: 1,
            version: '1.0',
            uploaded_by: 'John Smith',
            upload_date: '01/11/2026 09:00',
            status: 'processed',
            expiration_date: '2027-05-15',
            is_current: false,
          },
        ],
      },
    })

    render(
      <MemoryRouter initialEntries={['/documents/15']}>
        <Routes>
          <Route path="/documents/:id" element={<DocumentDetailPage />} />
        </Routes>
      </MemoryRouter>,
    )

    expect(await screen.findByTestId('doc-name')).toBeInTheDocument()

    // Switch to Versions tab
    fireEvent.click(screen.getByTestId('tab-versions'))

    // Check table headers
    expect(screen.getByRole('columnheader', { name: 'Version' })).toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: 'Uploaded by' })).toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: 'Upload date' })).toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: 'Status' })).toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: 'Expiration date' })).toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: 'Current' })).toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: 'Download' })).toBeInTheDocument()

    // Version 2.0 has Current badge
    expect(screen.getByTestId('badge-current-2.0')).toHaveTextContent('Current')
    // Version 1.0 does not have Current badge but is listed and downloadable
    expect(screen.queryByTestId('badge-current-1.0')).not.toBeInTheDocument()
    expect(screen.getByTestId('download-button-1.0')).toBeInTheDocument()
    expect(screen.getByTestId('download-button-2.0')).toBeInTheDocument()
  })

  it('displays exact failure alert message when a version failed processing', async () => {
    vi.mocked(api.get).mockResolvedValue({
      data: {
        id: 15,
        name: 'Fire Safety Operating License',
        category: 'permit_license',
        access_level: 'all_staff',
        current_version: '2.0',
        versions: [
          {
            id: 3,
            version: '3.0',
            uploaded_by: 'Jane Doe',
            upload_date: '12/11/2026 10:00',
            status: 'failed',
            expiration_date: '2028-05-15',
            is_current: false,
          },
          {
            id: 2,
            version: '2.0',
            uploaded_by: 'Jane Doe',
            upload_date: '10/11/2026 14:00',
            status: 'processed',
            expiration_date: '2028-05-15',
            is_current: true,
          },
        ],
      },
    })

    render(
      <MemoryRouter initialEntries={['/documents/15']}>
        <Routes>
          <Route path="/documents/:id" element={<DocumentDetailPage />} />
        </Routes>
      </MemoryRouter>,
    )

    const alert = await screen.findByTestId('version-failure-alert')
    expect(alert).toHaveTextContent(
      'Version 3.0 could not be processed. Version 2.0 remains the current version.'
    )
  })

  it('shows "Upload new version" button for compliance_staff and hides it for general_employee', async () => {
    vi.mocked(api.get).mockResolvedValue({
      data: {
        id: 15,
        name: 'Fire Safety Operating License',
        category: 'permit_license',
        access_level: 'all_staff',
        current_version: '1.0',
        versions: [],
      },
    })

    render(
      <MemoryRouter initialEntries={['/documents/15']}>
        <Routes>
          <Route path="/documents/:id" element={<DocumentDetailPage />} />
        </Routes>
      </MemoryRouter>,
    )

    // With compliance_staff role
    expect(await screen.findByTestId('upload-new-version-button')).toBeInTheDocument()

    // Switch to general_employee role
    mockUser = {
      id: 2,
      name: 'General User',
      role: 'general_employee',
      organization_id: 1,
    }

    cleanup()

    render(
      <MemoryRouter initialEntries={['/documents/15']}>
        <Routes>
          <Route path="/documents/:id" element={<DocumentDetailPage />} />
        </Routes>
      </MemoryRouter>,
    )

    expect(await screen.findByTestId('doc-name')).toBeInTheDocument()
    expect(screen.queryByTestId('upload-new-version-button')).not.toBeInTheDocument()
  })

  it('renders "Text obtained by OCR" badge and extraction method when ocr_used is true', async () => {
    vi.mocked(api.get).mockResolvedValue({
      data: {
        id: 10,
        name: 'Constancia de Uso de Suelo',
        category: 'permit_license',
        access_level: 'all_staff',
        current_version: '1.0',
        ocr_used: true,
        ocr_confidence: 95.4,
        versions: [
          {
            id: 1,
            version: '1.0',
            uploaded_by: 'Jane Doe',
            upload_date: '04/11/2026 10:30',
            status: 'processed',
            expiration_date: '30/11/2026',
            is_current: true,
            ocr_used: true,
            ocr_confidence: 95.4,
          },
        ],
      },
    })

    render(
      <MemoryRouter initialEntries={['/documents/10']}>
        <Routes>
          <Route path="/documents/:id" element={<DocumentDetailPage />} />
        </Routes>
      </MemoryRouter>,
    )

    expect(await screen.findByTestId('doc-name')).toHaveTextContent('Constancia de Uso de Suelo')
    expect(screen.getByTestId('ocr-badge')).toHaveTextContent('Text obtained by OCR')
    expect(screen.getByTestId('doc-ocr-label')).toHaveTextContent('Text obtained by OCR')
    expect(screen.getByTestId('doc-ocr-confidence')).toHaveTextContent('(95.4% confidence)')
  })
})
