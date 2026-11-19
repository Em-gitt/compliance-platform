import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import RequirementDetailPage from './RequirementDetailPage'
import { api } from '../lib/api'

vi.mock('../lib/api', () => ({
  api: {
    get: vi.fn(),
    post: vi.fn(),
    delete: vi.fn(),
  },
}))

describe('RequirementDetailPage', () => {
  let queryClient: QueryClient

  beforeEach(() => {
    vi.clearAllMocks()
    queryClient = new QueryClient({
      defaultOptions: {
        queries: { retry: false },
      },
    })
  })

  it('shows "Missing Document" badge when required document is not linked (AC #1)', async () => {
    vi.mocked(api.get).mockImplementation(async (url: string) => {
      if (url === '/api/requirements/1') {
        return {
          data: {
            id: 1,
            name: 'Environmental Compliance Standard',
            description: 'Annual environmental compliance',
            regulation: 'NOM-001',
            deadline: '2026-12-31',
            status: 'pending',
            missing_documents_count: 1,
            all_documents_provided: false,
            missing_documents_label: '1 missing documents',
            required_documents: [
              {
                id: 101,
                name: 'Waste Management Plan',
                category: 'environmental_permit',
                document_id: null,
                status: 'missing',
                badge: 'Missing Document',
                tooltip: null,
                is_satisfied: false,
              },
            ],
          },
        } as any
      }
      return { data: [] } as any
    })

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/requirements/1']}>
          <Routes>
            <Route path="/requirements/:id" element={<RequirementDetailPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>
    )

    await waitFor(() => {
      expect(screen.getByText('Environmental Compliance Standard')).toBeInTheDocument()
    })

    expect(screen.getByText('Missing Document')).toBeInTheDocument()
    expect(screen.getByText('1 missing documents')).toBeInTheDocument()
    expect(screen.getByText('Link document')).toBeInTheDocument()
  })

  it('links a processed document and updates to "Provided" without page reload (AC #2)', async () => {
    vi.mocked(api.get).mockImplementation(async (url: string) => {
      if (url === '/api/requirements/1') {
        return {
          data: {
            id: 1,
            name: 'Environmental Compliance Standard',
            description: 'Annual environmental compliance',
            regulation: 'NOM-001',
            deadline: '2026-12-31',
            status: 'pending',
            missing_documents_count: 1,
            all_documents_provided: false,
            missing_documents_label: '1 missing documents',
            required_documents: [
              {
                id: 101,
                name: 'Waste Management Plan',
                category: 'environmental_permit',
                document_id: null,
                status: 'missing',
                badge: 'Missing Document',
                tooltip: null,
                is_satisfied: false,
              },
            ],
          },
        } as any
      }
      if (url.startsWith('/api/documents')) {
        return {
          data: [
            {
              id: 50,
              name: 'Valid Permit 2026.pdf',
              category: 'environmental_permit',
              access_level: 'all_staff',
              current_version: {
                id: 1,
                version_label: 'v1.0',
                status: 'processed',
              },
            },
          ],
        } as any
      }
      return { data: [] } as any
    })

    vi.mocked(api.post).mockResolvedValueOnce({
      data: {
        message: 'Document linked successfully.',
        required_document: {
          id: 101,
          name: 'Waste Management Plan',
          category: 'environmental_permit',
          document_id: 50,
          status: 'provided',
          badge: 'Provided',
          tooltip: null,
          is_satisfied: true,
          document: {
            id: 50,
            name: 'Valid Permit 2026.pdf',
          },
        },
      },
    } as any)

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/requirements/1']}>
          <Routes>
            <Route path="/requirements/:id" element={<RequirementDetailPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>
    )

    await waitFor(() => {
      expect(screen.getByText('Link document')).toBeInTheDocument()
    })

    fireEvent.click(screen.getByText('Link document'))

    await waitFor(() => {
      expect(screen.getByText(/Valid Permit 2026.pdf/)).toBeInTheDocument()
    })

    const select = screen.getByRole('combobox')
    fireEvent.change(select, { target: { value: '50' } })

    const confirmBtn = screen.getByRole('button', { name: 'Link Document' })
    fireEvent.click(confirmBtn)

    await waitFor(() => {
      expect(screen.getByText(/Provided/)).toBeInTheDocument()
    })

    expect(screen.getByText('(Valid Permit 2026.pdf)')).toBeInTheDocument()
    expect(screen.getByText('Unlink')).toBeInTheDocument()
  })

  it('shows tooltip when document is expired', async () => {
    vi.mocked(api.get).mockImplementation(async (url: string) => {
      if (url === '/api/requirements/1') {
        return {
          data: {
            id: 1,
            name: 'Environmental Standard',
            description: 'Expired test',
            regulation: 'NOM-001',
            deadline: '2026-12-31',
            status: 'pending',
            missing_documents_count: 1,
            all_documents_provided: false,
            missing_documents_label: '1 missing documents',
            required_documents: [
              {
                id: 102,
                name: 'Expired Permit',
                category: 'environmental_permit',
                document_id: 60,
                status: 'expired',
                badge: 'Missing Document',
                tooltip: 'The linked document expired on 10/10/2026.',
                is_satisfied: false,
                document: {
                  id: 60,
                  name: 'Old Permit.pdf',
                },
              },
            ],
          },
        } as any
      }
      return { data: [] } as any
    })

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/requirements/1']}>
          <Routes>
            <Route path="/requirements/:id" element={<RequirementDetailPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>
    )

    await waitFor(() => {
      expect(screen.getByText('The linked document expired on 10/10/2026.', { exact: false })).toBeInTheDocument()
    })
  })

  it('shows "Processing" badge when document version is still processing', async () => {
    vi.mocked(api.get).mockImplementation(async (url: string) => {
      if (url === '/api/requirements/1') {
        return {
          data: {
            id: 1,
            name: 'Environmental Standard',
            description: 'Processing test',
            regulation: 'NOM-001',
            deadline: '2026-12-31',
            status: 'pending',
            missing_documents_count: 1,
            all_documents_provided: false,
            missing_documents_label: '1 missing documents',
            required_documents: [
              {
                id: 103,
                name: 'Pending Permit',
                category: 'environmental_permit',
                document_id: 70,
                status: 'processing',
                badge: 'Processing',
                tooltip: null,
                is_satisfied: false,
                document: {
                  id: 70,
                  name: 'Processing Permit.pdf',
                },
              },
            ],
          },
        } as any
      }
      return { data: [] } as any
    })

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/requirements/1']}>
          <Routes>
            <Route path="/requirements/:id" element={<RequirementDetailPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>
    )

    await waitFor(() => {
      expect(screen.getByText('Processing')).toBeInTheDocument()
    })
  })
})
