import { useState, useRef, useEffect } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '../lib/api'
import { useAuth } from '../auth/AuthContext'

export interface AlertItem {
  id: number
  user_id: number
  compliance_requirement_id: number | null
  document_version_id: number | null
  type: string
  period_days: number
  message: string
  due_date: string
  read_at: string | null
  created_at: string
  compliance_requirement?: { id: number; name: string } | null
  document_version?: { id: number; document_id: number; document?: { id: number; name: string } } | null
}

interface AlertsResponse {
  alerts: AlertItem[]
  unread_count: number
}

export default function Header() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [dropdownOpen, setDropdownOpen] = useState(false)
  const dropdownRef = useRef<HTMLDivElement>(null)

  const { data } = useQuery<AlertsResponse>({
    queryKey: ['alerts'],
    queryFn: async () => {
      const res = await api.get<AlertsResponse>('/api/alerts')
      return res.data
    },
    enabled: !!user,
    refetchInterval: 15000,
  })

  const alerts = data?.alerts || []
  const unreadCount = data?.unread_count || 0
  const displayCount = unreadCount > 9 ? '9+' : unreadCount.toString()

  const markReadMutation = useMutation({
    mutationFn: async (id: number) => {
      await api.post(`/api/alerts/${id}/read`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['alerts'] })
    },
  })

  const markAllReadMutation = useMutation({
    mutationFn: async () => {
      await api.post('/api/alerts/read-all')
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['alerts'] })
    },
  })

  const handleAlertClick = (alert: AlertItem) => {
    if (!alert.read_at) {
      markReadMutation.mutate(alert.id)
    }
    setDropdownOpen(false)

    if (alert.compliance_requirement_id) {
      navigate(`/requirements/${alert.compliance_requirement_id}`)
    } else if (alert.document_version?.document_id) {
      navigate(`/documents/${alert.document_version.document_id}`)
    }
  }

  // Close dropdown on outside click
  useEffect(() => {
    function handleClickOutside(event: MouseEvent) {
      if (dropdownRef.current && !dropdownRef.current.contains(event.target as Node)) {
        setDropdownOpen(false)
      }
    }
    document.addEventListener('mousedown', handleClickOutside)
    return () => document.removeEventListener('mousedown', handleClickOutside)
  }, [])

  if (!user) return null

  return (
    <header className="border-b border-slate-200 bg-white sticky top-0 z-40">
      <div className="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
        <div className="flex items-center gap-6">
          <Link to="/dashboard" className="text-xl font-bold text-slate-800 tracking-tight">
            Compliance Platform
          </Link>
          <nav className="hidden md:flex items-center gap-4 text-sm font-medium text-slate-600">
            <Link to="/dashboard" className="hover:text-slate-900 transition-colors">
              Dashboard
            </Link>
            <Link to="/requirements" className="hover:text-slate-900 transition-colors">
              Requirements
            </Link>
            <Link to="/documents" className="hover:text-slate-900 transition-colors">
              Documents
            </Link>
            <Link to="/assistant" className="hover:text-slate-900 transition-colors">
              AI Assistant
            </Link>
          </nav>
        </div>

        <div className="flex items-center gap-4">
          {/* Bell Icon */}
          <div className="relative" ref={dropdownRef}>
            <button
              type="button"
              data-testid="alerts-bell-button"
              onClick={() => setDropdownOpen(!dropdownOpen)}
              className="relative rounded-full p-2 text-slate-600 hover:bg-slate-100 focus:outline-none"
              aria-label="Alerts"
            >
              {/* Bell SVG */}
              <svg
                className="h-6 w-6"
                fill="none"
                viewBox="0 0 24 24"
                strokeWidth="1.5"
                stroke="currentColor"
              >
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0"
                />
              </svg>

              {unreadCount > 0 && (
                <span
                  data-testid="unread-badge"
                  className="absolute -top-0.5 -right-0.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-red-600 px-1 text-[11px] font-bold text-white shadow-sm"
                >
                  {displayCount}
                </span>
              )}
            </button>

            {/* Dropdown */}
            {dropdownOpen && (
              <div
                data-testid="alerts-dropdown"
                className="absolute right-0 mt-2 w-80 sm:w-96 rounded-xl border border-slate-200 bg-white py-2 shadow-xl ring-1 ring-black ring-opacity-5 z-50"
              >
                <div className="flex items-center justify-between border-b border-slate-100 px-4 py-2">
                  <h3 className="text-sm font-semibold text-slate-800">Alerts</h3>
                  {unreadCount > 0 && (
                    <button
                      type="button"
                      onClick={() => markAllReadMutation.mutate()}
                      className="text-xs font-medium text-indigo-600 hover:text-indigo-800"
                    >
                      Mark all as read
                    </button>
                  )}
                </div>

                <div className="max-h-80 overflow-y-auto divide-y divide-slate-100">
                  {alerts.length === 0 ? (
                    <div className="p-6 text-center text-sm text-slate-500">
                      You have no alerts.
                    </div>
                  ) : (
                    alerts.slice(0, 10).map((alert) => (
                      <div
                        key={alert.id}
                        onClick={() => handleAlertClick(alert)}
                        className={`cursor-pointer p-3.5 transition hover:bg-slate-50 ${
                          !alert.read_at ? 'bg-indigo-50/50' : ''
                        }`}
                      >
                        <div className="flex items-start justify-between gap-2">
                          <p className={`text-xs ${!alert.read_at ? 'font-semibold text-slate-900' : 'text-slate-600'}`}>
                            {alert.message}
                          </p>
                          {!alert.read_at && (
                            <span className="h-2 w-2 rounded-full bg-indigo-600 shrink-0 mt-1" />
                          )}
                        </div>
                        <span className="mt-1 block text-[10px] text-slate-400">
                          {new Date(alert.created_at).toLocaleDateString()}
                        </span>
                      </div>
                    ))
                  )}
                </div>

                <div className="border-t border-slate-100 px-4 py-2 text-center">
                  <Link
                    to="/alerts"
                    onClick={() => setDropdownOpen(false)}
                    className="text-xs font-semibold text-indigo-600 hover:text-indigo-800"
                  >
                    View all alerts
                  </Link>
                </div>
              </div>
            )}
          </div>

          <span className="text-xs text-slate-600 font-medium hidden sm:inline">
            {user.name}
          </span>
          <button
            type="button"
            onClick={logout}
            className="rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-200"
          >
            Logout
          </button>
        </div>
      </div>
    </header>
  )
}
