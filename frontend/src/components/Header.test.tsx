import { render, screen, fireEvent, waitFor, cleanup } from '@testing-library/react'
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import Header from './Header'
import { api } from '../lib/api'
import * as AuthContext from '../auth/AuthContext'

vi.mock('../lib/api', () => ({
  api: {
    get: vi.fn(),
    post: vi.fn(),
  },
}))

describe('Header and Alerts Bell', () => {
  let queryClient: QueryClient

  beforeEach(() => {
    vi.clearAllMocks()
    queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    })

    vi.spyOn(AuthContext, 'useAuth').mockReturnValue({
      user: {
        id: 1,
        name: 'Jane Responsible',
        email: 'jane@example.com',
        role: 'compliance_staff',
        organization_id: 1,
        is_active: true,
      },
      token: 'valid-token',
      login: vi.fn(),
      logout: vi.fn(),
      isLoading: false,
      setUser: vi.fn(),
      refreshUser: vi.fn(),
    })
  })

  afterEach(() => {
    cleanup()
  })

  it('renders bell icon and shows exact unread count up to 9', async () => {
    vi.mocked(api.get).mockResolvedValueOnce({
      data: {
        alerts: [
          {
            id: 1,
            user_id: 1,
            compliance_requirement_id: 10,
            document_version_id: null,
            type: 'requirement_due',
            period_days: 7,
            message: 'Requirement "Safety" is due in 7 days (26/11/2026). Responsible: Jane Responsible.',
            due_date: '2026-11-26',
            read_at: null,
            created_at: '2026-11-19T10:00:00Z',
          },
        ],
        unread_count: 1,
      },
    } as any)

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <Header />
        </MemoryRouter>
      </QueryClientProvider>
    )

    await waitFor(() => {
      const badge = screen.getByTestId('unread-badge')
      expect(badge).toHaveTextContent('1')
    })
  })

  it('displays "9+" when unread count exceeds 9', async () => {
    vi.mocked(api.get).mockResolvedValueOnce({
      data: {
        alerts: [],
        unread_count: 14,
      },
    } as any)

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <Header />
        </MemoryRouter>
      </QueryClientProvider>
    )

    await waitFor(() => {
      const badge = screen.getByTestId('unread-badge')
      expect(badge).toHaveTextContent('9+')
    })
  })

  it('opens dropdown on clicking bell and shows alerts and mark all read button', async () => {
    vi.mocked(api.get).mockResolvedValueOnce({
      data: {
        alerts: [
          {
            id: 1,
            user_id: 1,
            compliance_requirement_id: 10,
            document_version_id: null,
            type: 'requirement_due',
            period_days: 7,
            message: 'Requirement "Safety" is due in 7 days (26/11/2026). Responsible: Jane Responsible.',
            due_date: '2026-11-26',
            read_at: null,
            created_at: '2026-11-19T10:00:00Z',
          },
        ],
        unread_count: 1,
      },
    } as any)

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <Header />
        </MemoryRouter>
      </QueryClientProvider>
    )

    const bellBtn = screen.getByTestId('alerts-bell-button')
    fireEvent.click(bellBtn)

    await waitFor(() => {
      expect(screen.getByTestId('alerts-dropdown')).toBeInTheDocument()
      expect(screen.getByText(/Requirement "Safety" is due in 7 days/)).toBeInTheDocument()
      expect(screen.getByText('Mark all as read')).toBeInTheDocument()
    })
  })

  it('shows "You have no alerts." when alert list is empty', async () => {
    vi.mocked(api.get).mockResolvedValueOnce({
      data: {
        alerts: [],
        unread_count: 0,
      },
    } as any)

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <Header />
        </MemoryRouter>
      </QueryClientProvider>
    )

    const bellBtn = screen.getByTestId('alerts-bell-button')
    fireEvent.click(bellBtn)

    await waitFor(() => {
      expect(screen.getByText('You have no alerts.')).toBeInTheDocument()
    })
  })
})
