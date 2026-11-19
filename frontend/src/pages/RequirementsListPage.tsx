import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { api } from '../lib/api'

interface RequirementSummary {
  id: number
  name: string
  regulation: string
  deadline: string
  status: string
  missing_documents_count: number
  all_documents_provided: boolean
  missing_documents_label: string
}

export default function RequirementsListPage() {
  const { data: requirements = [], isLoading, error } = useQuery<RequirementSummary[]>({
    queryKey: ['requirements'],
    queryFn: async () => {
      const res = await api.get<RequirementSummary[]>('/api/requirements')
      return res.data
    },
  })

  return (
    <div className="min-h-screen bg-slate-50 p-6">
      <div className="mx-auto max-w-5xl">
        <div className="mb-6 flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold text-slate-800">Compliance Requirements</h1>
            <p className="text-sm text-slate-500">Track and manage regulatory compliance requirements and required documents.</p>
          </div>
          <Link
            to="/dashboard"
            className="rounded-lg bg-slate-100 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-200"
          >
            Back to Dashboard
          </Link>
        </div>

        {isLoading && <div className="text-center py-12 text-slate-500">Loading requirements...</div>}
        {error && <div className="rounded-lg bg-red-50 p-4 text-sm text-red-600">Error loading requirements.</div>}

        {!isLoading && requirements.length === 0 && (
          <div className="rounded-xl border border-slate-200 bg-white p-8 text-center text-slate-500">
            No compliance requirements found.
          </div>
        )}

        <div className="grid gap-4">
          {requirements.map((req) => (
            <div
              key={req.id}
              className="flex items-center justify-between rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition hover:shadow-md"
            >
              <div className="space-y-1">
                <div className="flex items-center gap-3">
                  <Link
                    to={`/requirements/${req.id}`}
                    className="font-semibold text-slate-900 hover:text-indigo-600"
                  >
                    {req.name}
                  </Link>
                  <span className="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600 font-medium">
                    {req.regulation}
                  </span>
                </div>
                <div className="text-xs text-slate-500">
                  Deadline: {new Date(req.deadline).toLocaleDateString()}
                </div>
              </div>

              <div className="flex items-center gap-4">
                <span
                  data-testid="missing-counter"
                  className={`rounded-full px-3 py-1 text-xs font-semibold ${
                    req.all_documents_provided
                      ? 'bg-emerald-100 text-emerald-800'
                      : 'bg-red-100 text-red-800'
                  }`}
                >
                  {req.missing_documents_label}
                </span>

                <Link
                  to={`/requirements/${req.id}`}
                  className="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50"
                >
                  View Details
                </Link>
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  )
}
