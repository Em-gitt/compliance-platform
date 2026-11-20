import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '../lib/api'
import AuditLogPage from './AuditLogPage'

vi.mock('../lib/api', () => ({
  api: {
    get: vi.fn(),
  },
}))

describe('AuditLogPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(api.get).mockResolvedValue({
      data: {
        data: [
          {
            id: 1,
            created_at: '2026-11-19T10:00:00Z',
            created_at_mexico_city: '2026-11-19 04:00:00',
            user_id: 1,
            user: { id: 1, name: 'Security Admin', email: 'admin@security.test' },
            category: 'security',
            action: 'auth.login_succeeded',
            resource_type: 'user',
            resource_id: 1,
            ip_address: '127.0.0.1',
          },
        ],
        current_page: 1,
        last_page: 1,
        total: 1,
      },
    })
  })

  afterEach(cleanup)

  it('renders audit log page with table columns and records', async () => {
    render(<AuditLogPage />)

    expect(await screen.findByText('Security & System Audit Log')).toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: 'Date/time' })).toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: 'User' })).toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: 'Action' })).toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: 'Resource' })).toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: 'IP' })).toBeInTheDocument()

    expect(await screen.findByText('auth.login_succeeded')).toBeInTheDocument()
    expect(screen.getByText('Security Admin (admin@security.test)')).toBeInTheDocument()
    expect(screen.getByText('127.0.0.1')).toBeInTheDocument()
  })

  it('submits filters and queries API with params', async () => {
    render(<AuditLogPage />)
    await screen.findByText('Security & System Audit Log')

    fireEvent.change(screen.getByLabelText('Category'), { target: { value: 'security' } })
    fireEvent.change(screen.getByLabelText('Action'), { target: { value: 'auth.login_failed' } })
    fireEvent.click(screen.getByRole('button', { name: 'Filter' }))

    expect(api.get).toHaveBeenCalledWith('/api/audit-logs?page=1&category=security&action=auth.login_failed')
  })
})
