import { act, cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '../lib/api'
import IdleTimeoutModal, { TIMEOUT_MS, WARNING_MS } from './IdleTimeoutModal'

const mockSetUser = vi.fn()
const mockUser = { id: 1, name: 'Test User', role: 'administrator', organization_id: 1 }

vi.mock('../auth/AuthContext', () => ({
  useAuth: () => ({
    user: mockUser,
    setUser: mockSetUser,
  }),
}))

vi.mock('../lib/api', () => ({
  api: {
    get: vi.fn(),
    post: vi.fn(),
    interceptors: { response: { use: vi.fn() } },
  },
  setUnauthorizedHandler: vi.fn(),
}))

describe('IdleTimeoutModal', () => {
  const assignMock = vi.fn()

  beforeEach(() => {
    vi.useFakeTimers()
    vi.clearAllMocks()
    vi.mocked(api.get).mockResolvedValue({
      data: { id: 1, name: 'Test User', role: 'administrator', organization_id: 1 },
    })
    assignMock.mockClear()
    Object.defineProperty(window, 'location', {
      value: { assign: assignMock, pathname: '/dashboard' },
      writable: true,
      configurable: true,
    })
  })

  afterEach(() => {
    cleanup()
    vi.useRealTimers()
  })

  it('renders modal at 28 minutes of inactivity with exact text', async () => {
    render(<IdleTimeoutModal />)

    expect(screen.queryByText('Are you still there?')).not.toBeInTheDocument()

    // Fast forward to 28 minutes
    await act(async () => {
      vi.advanceTimersByTime(WARNING_MS)
    })

    expect(screen.getByText('Are you still there?')).toBeInTheDocument()
    expect(
      screen.getByText('Your session will expire in 2 minutes due to inactivity.'),
    ).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Stay signed in' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Sign out' })).toBeInTheDocument()
  })

  it('stays signed in when clicking Stay signed in', async () => {
    render(<IdleTimeoutModal />)

    await act(async () => {
      vi.advanceTimersByTime(WARNING_MS)
    })
    expect(screen.getByText('Are you still there?')).toBeInTheDocument()

    await act(async () => {
      fireEvent.click(screen.getByRole('button', { name: 'Stay signed in' }))
    })
    expect(api.get).toHaveBeenCalledWith('/api/auth/me')

    expect(screen.queryByText('Are you still there?')).not.toBeInTheDocument()
  })

  it('redirects to /login?reason=timeout at 30 minutes of inactivity', async () => {
    render(<IdleTimeoutModal />)

    // Fast forward to 30 minutes
    await act(async () => {
      vi.advanceTimersByTime(TIMEOUT_MS)
    })

    expect(assignMock).toHaveBeenCalledWith('/login?reason=timeout')
  })
})
