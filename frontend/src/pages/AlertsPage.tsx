import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate, Link } from 'react-router-dom'
import { api } from '../lib/api'
import { AlertItem } from '../components/Header'

interface AlertsResponse {
  alerts: AlertItem[]
  unread_count: number
}

export default function AlertsPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const { data, isLoading } = useQuery<AlertsResponse>({
    queryKey: ['alerts'],
    queryFn: async () => {
      const res = await api.get<AlertsResponse>('/api/alerts')
      return res.data
    },
  })

  const alerts = data?.alerts || []
  const unreadCount = data?.unread_count || 0

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

    if (alert.compliance_requirement_id) {
      navigate(`/requirements/${alert.compliance_requirement_id}`)
    } else if (alert.document_version?.document_id) {
      navigate(`/documents/${alert.document_version.document_id}`)
    }
  }

  return (
    <div className="min-h-screen bg-slate-50 p-6">
      <div className="mx-auto max-w-4xl space-y-6">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold text-slate-800">In-Application Alerts</h1>
            <p className="text-sm text-slate-500">Notifications for approaching deadlines, document expirations, and overdue items.</p>
          </div>
          <div className="flex items-center gap-3">
            {unreadCount > 0 && (
              <button
                type="button"
                onClick={() => markAllReadMutation.mutate()}
                className="rounded-lg bg-indigo-600 px-4 py-2 text-xs font-semibold text-white hover:bg-indigo-700"
              >
                Mark all as read
              </button>
            )}
            <Link
              to="/dashboard"
              className="rounded-lg bg-slate-100 px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-200"
            >
              Back to Dashboard
            </Link>
          </div>
        </div>

        {isLoading && (
          <div className="p-12 text-center text-slate-500">Loading alerts...</div>
        )}

        {!isLoading && alerts.length === 0 && (
          <div className="rounded-xl border border-slate-200 bg-white p-12 text-center text-slate-500">
            You have no alerts.
          </div>
        )}

        {!isLoading && alerts.length > 0 && (
          <div className="rounded-xl border border-slate-200 bg-white divide-y divide-slate-100 shadow-sm overflow-hidden">
            {alerts.map((alert) => (
              <div
                key={alert.id}
                onClick={() => handleAlertClick(alert)}
                className={`cursor-pointer p-4 transition hover:bg-slate-50 flex items-center justify-between gap-4 ${
                  !alert.read_at ? 'bg-indigo-50/40' : ''
                }`}
              >
                <div className="space-y-1">
                  <div className="flex items-center gap-2">
                    <p className={`text-sm ${!alert.read_at ? 'font-semibold text-slate-900' : 'text-slate-700'}`}>
                      {alert.message}
                    </p>
                    {!alert.read_at && (
                      <span className="rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-bold text-indigo-700">
                        New
                      </span>
                    )}
                  </div>
                  <div className="text-xs text-slate-400">
                    Created: {new Date(alert.created_at).toLocaleDateString()} | Due date: {new Date(alert.due_date).toLocaleDateString()}
                  </div>
                </div>

                <span className="text-xs font-medium text-indigo-600 hover:text-indigo-800 shrink-0">
                  View item →
                </span>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  )
}
