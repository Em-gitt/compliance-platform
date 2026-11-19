import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from '../auth/AuthContext'
import { api } from '../lib/api'
import LoginPage from './LoginPage'

vi.mock('../lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), interceptors: { response: { use: vi.fn() } } },
  setUnauthorizedHandler: vi.fn(),
}))

function renderLogin() {
  return render(
    <MemoryRouter>
      <AuthProvider><LoginPage /></AuthProvider>
    </MemoryRouter>,
  )
}

describe('LoginPage', () => {
  afterEach(cleanup)
  beforeEach(() => vi.clearAllMocks())

  it('shows the exact Zod validation messages', async () => {
    renderLogin()
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }))

    expect(await screen.findByText('Email is required.')).toBeInTheDocument()
    expect(screen.getByText('Password is required.')).toBeInTheDocument()
  })

  it('shows an invalid email error', async () => {
    renderLogin()
    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'invalid' } })
    fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'password' } })
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }))

    expect(await screen.findByText('Enter a valid email address.')).toBeInTheDocument()
  })

  it('toggles password visibility', () => {
    renderLogin()
    const password = screen.getByLabelText('Password')
    expect(password).toHaveAttribute('type', 'password')
    fireEvent.click(screen.getByRole('button', { name: 'Show password' }))
    expect(password).toHaveAttribute('type', 'text')
    fireEvent.click(screen.getByRole('button', { name: 'Hide password' }))
    expect(password).toHaveAttribute('type', 'password')
  })

  it('shows yellow banner when session timed out with exact message', () => {
    render(
      <MemoryRouter initialEntries={['/login?reason=timeout']}>
        <AuthProvider><LoginPage /></AuthProvider>
      </MemoryRouter>,
    )

    expect(
      screen.getByText('Your session expired after 30 minutes of inactivity. Please sign in again.'),
    ).toBeInTheDocument()
  })
})
