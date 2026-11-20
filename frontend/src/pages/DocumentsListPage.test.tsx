import { render, screen, waitFor, cleanup } from '@testing-library/react'
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import DocumentsListPage from './DocumentsListPage'
import { api } from '../lib/api'

vi.mock('../lib/api', () => ({
  api: {
    get: vi.fn(),
  },
}))

describe('DocumentsListPage', () => {
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

  it('renders documents table and shows "<n> results"', async () => {
    vi.mocked(api.get).mockResolvedValueOnce({
      data: {
        data: [
          {
            id: 1,
            name: 'Política de Seguridad',
            category: 'policy',
            access_level: 'all_staff',
            created_at: '2026-11-19T00:00:00Z',
            current_version: {
              id: 1,
              version_label: 'v1.0',
              status: 'processed',
              expiration_date: null,
              uploaded_at: '2026-11-19T00:00:00Z',
            },
          },
        ],
        total: 1,
      },
    } as unknown as ReturnType<typeof api.get>)

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <DocumentsListPage />
        </MemoryRouter>
      </QueryClientProvider>
    )

    await waitFor(() => {
      expect(screen.getByText('Política de Seguridad')).toBeInTheDocument()
    })

    expect(screen.getByTestId('results-count')).toHaveTextContent('1 results')
    expect(screen.getByText('policy')).toBeInTheDocument()
    expect(screen.getByText('v1.0')).toBeInTheDocument()
  })

  it('shows exact empty message when no documents match', async () => {
    vi.mocked(api.get).mockResolvedValueOnce({
      data: {
        data: [],
        total: 0,
      },
    } as unknown as ReturnType<typeof api.get>)

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <DocumentsListPage />
        </MemoryRouter>
      </QueryClientProvider>
    )

    await waitFor(() => {
      expect(
        screen.getByText('No results found. Try changing or clearing the filters.')
      ).toBeInTheDocument()
    })
  })
})
