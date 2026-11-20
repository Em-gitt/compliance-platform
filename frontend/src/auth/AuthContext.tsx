import { createContext, ReactNode, useContext, useEffect, useMemo, useState } from 'react'
import { api, setUnauthorizedHandler } from '../lib/api'

export type AuthUser = {
  id: number
  name: string
  email?: string
  role: string
  organization_id: number
  is_active?: boolean
}

type AuthContextValue = {
  user: AuthUser | null
  isLoading: boolean
  setUser: (user: AuthUser | null) => void
  refreshUser: () => Promise<void>
  logout: () => Promise<void>
  login?: (credentials: Record<string, unknown>) => Promise<unknown>
  token?: string | null
}

export const AuthContext = createContext<AuthContextValue | undefined>(undefined)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<AuthUser | null>(null)
  const [isLoading, setIsLoading] = useState(true)

  const refreshUser = async () => {
    try {
      const response = await api.get<AuthUser>('/api/auth/me')
      setUser(response.data)
    } catch {
      setUser(null)
    } finally {
      setIsLoading(false)
    }
  }

  useEffect(() => {
    setUnauthorizedHandler(() => {
      setUser(null)
      if (window.location.pathname !== '/login') {
        window.location.assign(`/login?redirect=${encodeURIComponent(window.location.pathname)}`)
      }
    })
    void refreshUser()

    return () => setUnauthorizedHandler()
  }, [])

  const logout = async () => {
    try {
      await api.post('/api/auth/logout')
    } catch {
      // ignore
    } finally {
      setUser(null)
      window.location.assign('/login')
    }
  }

  const value = useMemo(() => ({ user, isLoading, setUser, refreshUser, logout }), [user, isLoading])

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth() {
  const context = useContext(AuthContext)
  if (!context) throw new Error('useAuth must be used inside AuthProvider')
  return context
}
