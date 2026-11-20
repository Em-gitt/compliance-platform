import { render, screen, waitFor, cleanup } from '@testing-library/react'
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import DashboardPage from './DashboardPage'
import { api } from '../lib/api'

vi.mock('../lib/api', () => ({
  api: {
    get: vi.fn(),
  },
}))

describe('DashboardPage', () => {
  let queryClient: QueryClient

  beforeEach(() => {
    vi.clearAllMocks()
    queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    })
  })

  afterEach(() => {
    cleanup()
  })

  it('renders all summary cards and table rows with exact labels and values', async () => {
    vi.mocked(api.get).mockResolvedValueOnce({
      data: {
        summary: {
          applicable_requirements: 4,
          compliant: 1,
          pending: 1,
          at_risk: 1,
          overdue: 1,
          missing_documents: 2,
        },
        requirements: [
          {
            id: 1,
            name: 'Emissions Standard',
            regulation: 'NOM-043-SEMARNAT-1993',
            deadline: '2026-11-20',
            deadline_formatted: '20/11/2026',
            is_past_deadline: false,
            is_deadline_within_7_days: true,
            documents_provided: 2,
            documents_total: 3,
            documents_label: '2 of 3 provided',
            status: 'at_risk',
            status_label: 'At Risk',
            responsible: 'Carlos Perez',
            has_missing_documents: true,
          },
        ],
      },
    } as unknown as ReturnType<typeof api.get>)

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <DashboardPage />
        </MemoryRouter>
      </QueryClientProvider>
    )

    await waitFor(() => {
      expect(screen.getByText('Applicable requirements')).toBeInTheDocument()
    })

    expect(screen.getByText('4')).toBeInTheDocument()
    expect(screen.getAllByText('Compliant').length).toBeGreaterThan(0)
    expect(screen.getAllByText('Pending').length).toBeGreaterThan(0)
    expect(screen.getAllByText('At Risk').length).toBeGreaterThan(0)
    expect(screen.getAllByText('Overdue').length).toBeGreaterThan(0)
    expect(screen.getByText('Missing documents')).toBeInTheDocument()

    // Table elements
    expect(screen.getByText('Emissions Standard')).toBeInTheDocument()
    expect(screen.getByText('NOM-043-SEMARNAT-1993')).toBeInTheDocument()
    expect(screen.getByText('20/11/2026')).toBeInTheDocument()
    expect(screen.getByText('2 of 3 provided')).toBeInTheDocument()
    expect(screen.getByText('Carlos Perez')).toBeInTheDocument()
  })

  it('shows exact empty state when no applicable requirements exist', async () => {
    vi.mocked(api.get).mockResolvedValueOnce({
      data: {
        summary: {
          applicable_requirements: 0,
          compliant: 0,
          pending: 0,
          at_risk: 0,
          overdue: 0,
          missing_documents: 0,
        },
        requirements: [],
      },
    } as unknown as ReturnType<typeof api.get>)

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <DashboardPage />
        </MemoryRouter>
      </QueryClientProvider>
    )

    await waitFor(() => {
      expect(screen.getByText('No compliance requirements apply to your organization yet.')).toBeInTheDocument()
    })
  })

  it('shows exact 403 error message when user lacks permission', async () => {
    vi.mocked(api.get).mockRejectedValueOnce({
      isAxiosError: true,
      response: {
        status: 403,
        data: { message: 'You do not have permission to view the compliance dashboard.' },
      },
    })

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <DashboardPage />
        </MemoryRouter>
      </QueryClientProvider>
    )

    await waitFor(() => {
      expect(screen.getByText('You do not have permission to view the compliance dashboard.')).toBeInTheDocument()
    })
  })

  it('shows exact 500 error message with Retry button when dashboard fails to load', async () => {
    vi.mocked(api.get).mockRejectedValueOnce({
      isAxiosError: true,
      response: {
        status: 500,
        data: { message: 'Server error' },
      },
    })

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <DashboardPage />
        </MemoryRouter>
      </QueryClientProvider>
    )

    await waitFor(() => {
      expect(screen.getByText('The dashboard could not be loaded. Please refresh the page.')).toBeInTheDocument()
      expect(screen.getByRole('button', { name: 'Retry' })).toBeInTheDocument()
    })
  })
})
