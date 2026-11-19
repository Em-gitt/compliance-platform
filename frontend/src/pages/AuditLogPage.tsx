import { useEffect, useState } from 'react'
import { api } from '../lib/api'

type AuditLogItem = {
  id: number
  created_at: string
  created_at_mexico_city?: string
  user_id: number | null
  user?: { id: number; name: string; email: string } | null
  category: string
  action: string
  resource_type: string | null
  resource_id: number | string | null
  ip_address: string | null
}

type PaginatedAuditLogs = {
  data: AuditLogItem[]
  current_page: number
  last_page: number
  total: number
}

export default function AuditLogPage() {
  const [logs, setLogs] = useState<AuditLogItem[]>([])
  const [loading, setLoading] = useState(true)
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [filters, setFilters] = useState({
    from: '',
    to: '',
    category: '',
    action: '',
  })

  const fetchLogs = async () => {
    setLoading(true)
    try {
      const params = new URLSearchParams()
      params.set('page', String(page))
      if (filters.from) params.set('from', filters.from)
      if (filters.to) params.set('to', filters.to)
      if (filters.category) params.set('category', filters.category)
      if (filters.action) params.set('action', filters.action)

      const response = await api.get<PaginatedAuditLogs>(`/api/audit-logs?${params.toString()}`)
      setLogs(response.data.data)
      setLastPage(response.data.last_page)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void fetchLogs()
  }, [page])

  const handleFilterSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    setPage(1)
    void fetchLogs()
  }

  return (
    <main className="p-6">
      <h1 className="text-2xl font-bold mb-4">Security & System Audit Log</h1>

      <form onSubmit={handleFilterSubmit} className="mb-6 flex flex-wrap gap-4 items-end bg-gray-50 p-4 rounded">
        <div>
          <label htmlFor="filter-from" className="block text-sm font-medium text-gray-700">From</label>
          <input
            id="filter-from"
            type="date"
            value={filters.from}
            onChange={(e) => setFilters({ ...filters, from: e.target.value })}
            className="rounded border p-2 text-sm"
          />
        </div>
        <div>
          <label htmlFor="filter-to" className="block text-sm font-medium text-gray-700">To</label>
          <input
            id="filter-to"
            type="date"
            value={filters.to}
            onChange={(e) => setFilters({ ...filters, to: e.target.value })}
            className="rounded border p-2 text-sm"
          />
        </div>
        <div>
          <label htmlFor="filter-category" className="block text-sm font-medium text-gray-700">Category</label>
          <select
            id="filter-category"
            value={filters.category}
            onChange={(e) => setFilters({ ...filters, category: e.target.value })}
            className="rounded border p-2 text-sm"
          >
            <option value="">All Categories</option>
            <option value="security">Security</option>
            <option value="functional">Functional</option>
          </select>
        </div>
        <div>
          <label htmlFor="filter-action" className="block text-sm font-medium text-gray-700">Action</label>
          <input
            id="filter-action"
            type="text"
            placeholder="e.g. auth.login_failed"
            value={filters.action}
            onChange={(e) => setFilters({ ...filters, action: e.target.value })}
            className="rounded border p-2 text-sm"
          />
        </div>
        <button
          type="submit"
          className="rounded bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700"
        >
          Filter
        </button>
      </form>

      {loading ? (
        <p role="status">Loading audit records...</p>
      ) : (
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200 border">
            <thead className="bg-gray-100">
              <tr>
                <th className="px-4 py-2 text-left text-xs font-semibold text-gray-600">Date/time</th>
                <th className="px-4 py-2 text-left text-xs font-semibold text-gray-600">User</th>
                <th className="px-4 py-2 text-left text-xs font-semibold text-gray-600">Action</th>
                <th className="px-4 py-2 text-left text-xs font-semibold text-gray-600">Resource</th>
                <th className="px-4 py-2 text-left text-xs font-semibold text-gray-600">IP</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 bg-white">
              {logs.length === 0 ? (
                <tr>
                  <td colSpan={5} className="px-4 py-4 text-center text-sm text-gray-500">
                    No audit records found.
                  </td>
                </tr>
              ) : (
                logs.map((log) => (
                  <tr key={log.id} data-testid={`audit-row-${log.id}`}>
                    <td className="px-4 py-2 text-sm text-gray-900">
                      {log.created_at_mexico_city ?? new Date(log.created_at).toLocaleString()}
                    </td>
                    <td className="px-4 py-2 text-sm text-gray-900">
                      {log.user ? `${log.user.name} (${log.user.email})` : 'System / Anonymous'}
                    </td>
                    <td className="px-4 py-2 text-sm font-mono text-xs text-blue-700">{log.action}</td>
                    <td className="px-4 py-2 text-sm text-gray-600">
                      {log.resource_type ? `${log.resource_type} #${log.resource_id ?? ''}` : '-'}
                    </td>
                    <td className="px-4 py-2 text-sm font-mono text-xs text-gray-500">{log.ip_address ?? '-'}</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>

          <div className="mt-4 flex items-center justify-between">
            <button
              type="button"
              disabled={page <= 1}
              onClick={() => setPage((p) => Math.max(1, p - 1))}
              className="rounded border px-3 py-1 text-sm disabled:opacity-50"
            >
              Previous
            </button>
            <span className="text-sm text-gray-600">
              Page {page} of {lastPage || 1}
            </span>
            <button
              type="button"
              disabled={page >= lastPage}
              onClick={() => setPage((p) => p + 1)}
              className="rounded border px-3 py-1 text-sm disabled:opacity-50"
            >
              Next
            </button>
          </div>
        </div>
      )}
    </main>
  )
}
