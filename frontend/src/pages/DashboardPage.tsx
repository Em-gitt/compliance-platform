import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { ResponsiveContainer, PieChart, Pie, Cell, Tooltip, Legend } from 'recharts'
import axios from 'axios'
import { api } from '../lib/api'

interface RequirementRow {
  id: number
  name: string
  regulation: string
  deadline: string
  deadline_formatted: string
  is_past_deadline: boolean
  is_deadline_within_7_days: boolean
  documents_provided: number
  documents_total: number
  documents_label: string
  status: 'compliant' | 'pending' | 'at_risk' | 'overdue'
  status_label: string
  responsible: string
  has_missing_documents: boolean
}

interface DashboardSummary {
  applicable_requirements: number
  compliant: number
  pending: number
  at_risk: number
  overdue: number
  missing_documents: number
}

interface DashboardResponse {
  summary: DashboardSummary
  requirements: RequirementRow[]
}

const STATUS_COLORS: Record<string, string> = {
  compliant: '#10b981', // green
  pending: '#94a3b8',   // gray
  at_risk: '#f59e0b',   // amber
  overdue: '#ef4444',   // red
}

export default function DashboardPage() {
  const navigate = useNavigate()
  const [selectedStatus, setSelectedStatus] = useState<string>('all')

  const { data, isLoading, error, refetch } = useQuery<DashboardResponse>({
    queryKey: ['dashboard'],
    queryFn: async () => {
      const res = await api.get<DashboardResponse>('/api/dashboard')
      return res.data
    },
    retry: false,
  })

  if (isLoading) {
    return (
      <div className="flex min-h-[60vh] items-center justify-center">
        <p className="text-sm font-medium text-slate-500">Loading compliance dashboard...</p>
      </div>
    )
  }

  // Handle 403 Forbidden
  if (axios.isAxiosError(error) && error.response?.status === 403) {
    return (
      <div className="min-h-screen bg-slate-50 p-6 flex items-center justify-center">
        <div className="rounded-xl border border-red-200 bg-white p-8 text-center max-w-md shadow-sm space-y-3">
          <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-red-100 text-red-600">
            <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
          </div>
          <h2 className="text-lg font-bold text-slate-800">Access Denied</h2>
          <p className="text-sm text-slate-600">
            You do not have permission to view the compliance dashboard.
          </p>
        </div>
      </div>
    )
  }

  // Handle other API errors (500)
  if (error || !data) {
    return (
      <div className="min-h-screen bg-slate-50 p-6 flex items-center justify-center">
        <div className="rounded-xl border border-red-200 bg-white p-8 text-center max-w-md shadow-sm space-y-4">
          <p className="text-sm text-red-700">
            The dashboard could not be loaded. Please refresh the page.
          </p>
          <button
            type="button"
            onClick={() => refetch()}
            className="rounded-lg bg-indigo-600 px-4 py-2 text-xs font-semibold text-white hover:bg-indigo-700"
          >
            Retry
          </button>
        </div>
      </div>
    )
  }

  const { summary, requirements } = data

  // Empty state: No applicable requirements
  if (summary.applicable_requirements === 0) {
    return (
      <div className="min-h-screen bg-slate-50 p-6">
        <div className="mx-auto max-w-7xl">
          <div className="rounded-xl border border-slate-200 bg-white p-12 text-center text-slate-600 shadow-sm">
            <h2 className="text-lg font-semibold text-slate-800">Compliance Dashboard</h2>
            <p className="mt-2 text-sm text-slate-500">
              No compliance requirements apply to your organization yet.
            </p>
          </div>
        </div>
      </div>
    )
  }

  // Filter requirements by status
  const filteredRequirements = selectedStatus === 'all'
    ? requirements
    : requirements.filter((r) => r.status.toLowerCase() === selectedStatus.toLowerCase())

  // Data for Donut Chart
  const chartData = [
    { name: 'Compliant', value: summary.compliant, color: STATUS_COLORS.compliant },
    { name: 'Pending', value: summary.pending, color: STATUS_COLORS.pending },
    { name: 'At Risk', value: summary.at_risk, color: STATUS_COLORS.at_risk },
    { name: 'Overdue', value: summary.overdue, color: STATUS_COLORS.overdue },
  ].filter((item) => item.value > 0)

  return (
    <div className="min-h-screen bg-slate-50 p-6">
      <div className="mx-auto max-w-7xl space-y-6">
        <div>
          <h1 className="text-2xl font-bold text-slate-900">Compliance Dashboard</h1>
          <p className="text-sm text-slate-500">Overview of applicable regulatory requirements and document status.</p>
        </div>

        {/* Summary Cards */}
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
          <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div className="text-xs font-medium text-slate-500">Applicable requirements</div>
            <div className="mt-2 text-2xl font-bold text-slate-900">{summary.applicable_requirements}</div>
          </div>

          <div className="rounded-xl border border-emerald-200 bg-emerald-50/40 p-4 shadow-sm">
            <div className="text-xs font-medium text-emerald-800">Compliant</div>
            <div className="mt-2 text-2xl font-bold text-emerald-700">{summary.compliant}</div>
          </div>

          <div className="rounded-xl border border-slate-200 bg-slate-50/60 p-4 shadow-sm">
            <div className="text-xs font-medium text-slate-600">Pending</div>
            <div className="mt-2 text-2xl font-bold text-slate-700">{summary.pending}</div>
          </div>

          <div className="rounded-xl border border-amber-200 bg-amber-50/50 p-4 shadow-sm">
            <div className="text-xs font-medium text-amber-800">At Risk</div>
            <div className="mt-2 text-2xl font-bold text-amber-700">{summary.at_risk}</div>
          </div>

          <div className="rounded-xl border border-red-200 bg-red-50/50 p-4 shadow-sm">
            <div className="text-xs font-medium text-red-800">Overdue</div>
            <div className="mt-2 text-2xl font-bold text-red-700">{summary.overdue}</div>
          </div>

          <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div className="text-xs font-medium text-slate-500">Missing documents</div>
            <div className="mt-2 text-2xl font-bold text-slate-900">{summary.missing_documents}</div>
          </div>
        </div>

        {/* Donut Chart & Status Breakdown */}
        <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
          <h2 className="text-base font-bold text-slate-800 mb-4">Requirements by Status</h2>
          <div className="h-64 w-full">
            <ResponsiveContainer width="100%" height="100%">
              <PieChart>
                <Pie
                  data={chartData}
                  cx="50%"
                  cy="50%"
                  innerRadius={60}
                  outerRadius={90}
                  paddingAngle={3}
                  dataKey="value"
                >
                  {chartData.map((entry) => (
                    <Cell key={`cell-${entry.name}`} fill={entry.color} />
                  ))}
                </Pie>
                <Tooltip />
                <Legend />
              </PieChart>
            </ResponsiveContainer>
          </div>
        </div>

        {/* Requirements Table Section */}
        <div className="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
          {/* Filter Chips */}
          <div className="flex flex-wrap items-center gap-2 border-b border-slate-100 p-4">
            <span className="text-xs font-medium text-slate-500 mr-2">Filter by status:</span>
            {['all', 'compliant', 'pending', 'at_risk', 'overdue'].map((chip) => {
              const label = chip === 'all' ? 'All' : chip === 'at_risk' ? 'At Risk' : chip.charAt(0).toUpperCase() + chip.slice(1)
              const isActive = selectedStatus === chip
              return (
                <button
                  key={chip}
                  type="button"
                  onClick={() => setSelectedStatus(chip)}
                  className={`rounded-full px-3 py-1 text-xs font-semibold transition ${
                    isActive
                      ? 'bg-slate-900 text-white'
                      : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                  }`}
                >
                  {label}
                </button>
              )
            })}
          </div>

          {/* Table */}
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="border-b border-slate-200 bg-slate-50 text-xs font-semibold uppercase text-slate-500">
                <tr>
                  <th className="px-6 py-3">Name</th>
                  <th className="px-6 py-3">Regulation</th>
                  <th className="px-6 py-3">Deadline</th>
                  <th className="px-6 py-3">Documents</th>
                  <th className="px-6 py-3">Status</th>
                  <th className="px-6 py-3">Responsible</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {filteredRequirements.map((row) => {
                  let deadlineColor = 'text-slate-700'
                  if (row.is_past_deadline) {
                    deadlineColor = 'text-red-600 font-semibold'
                  } else if (row.is_deadline_within_7_days) {
                    deadlineColor = 'text-amber-600 font-semibold'
                  }

                  let badgeColor = 'bg-slate-100 text-slate-700'
                  if (row.status === 'compliant') badgeColor = 'bg-emerald-100 text-emerald-800'
                  else if (row.status === 'at_risk') badgeColor = 'bg-amber-100 text-amber-800'
                  else if (row.status === 'overdue') badgeColor = 'bg-red-100 text-red-800'

                  return (
                    <tr
                      key={row.id}
                      onClick={() => navigate(`/requirements/${row.id}`)}
                      className="cursor-pointer hover:bg-slate-50 transition"
                    >
                      <td className="px-6 py-4 font-medium text-slate-900 hover:text-indigo-600">
                        {row.name}
                      </td>
                      <td className="px-6 py-4 text-slate-600">{row.regulation}</td>
                      <td className={`px-6 py-4 ${deadlineColor}`}>
                        {row.deadline_formatted}
                      </td>
                      <td className="px-6 py-4 text-slate-600">{row.documents_label}</td>
                      <td className="px-6 py-4">
                        <span className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ${badgeColor}`}>
                          {row.status_label}
                        </span>
                      </td>
                      <td className="px-6 py-4 text-slate-600">{row.responsible}</td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  )
}
