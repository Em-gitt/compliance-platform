import { useState, useEffect } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { api } from '../lib/api'

interface RequirementItem {
  id: number
  name: string
  regulation: string
  deadline: string
  status: string
  missing_documents_count: number
  all_documents_provided: boolean
  missing_documents_label: string
}

interface RequirementsResponse {
  data: RequirementItem[]
  total: number
}

export default function RequirementsListPage() {
  const [searchParams, setSearchParams] = useSearchParams()

  const nameParam = searchParams.get('name') || ''
  const regParam = searchParams.get('regulation') || ''
  const statusParam = searchParams.get('status') || ''
  const fromParam = searchParams.get('deadline_from') || ''
  const toParam = searchParams.get('deadline_to') || ''
  const pageParam = parseInt(searchParams.get('page') || '1', 10)

  // Local state for debounced inputs
  const [nameInput, setNameInput] = useState(nameParam)
  const [regInput, setRegInput] = useState(regParam)

  // Debounce name input (300ms)
  useEffect(() => {
    const timer = setTimeout(() => {
      if (nameInput !== nameParam) {
        setSearchParams((prev) => {
          const next = new URLSearchParams(prev)
          if (nameInput) next.set('name', nameInput)
          else next.delete('name')
          next.set('page', '1')
          return next
        })
      }
    }, 300)
    return () => clearTimeout(timer)
  }, [nameInput, nameParam, setSearchParams])

  // Debounce regulation input (300ms)
  useEffect(() => {
    const timer = setTimeout(() => {
      if (regInput !== regParam) {
        setSearchParams((prev) => {
          const next = new URLSearchParams(prev)
          if (regInput) next.set('regulation', regInput)
          else next.delete('regulation')
          next.set('page', '1')
          return next
        })
      }
    }, 300)
    return () => clearTimeout(timer)
  }, [regInput, regParam, setSearchParams])

  const { data, isLoading } = useQuery<RequirementsResponse>({
    queryKey: ['requirements', searchParams.toString()],
    queryFn: async () => {
      const qs = searchParams.toString()
      const res = await api.get<any>(`/api/requirements?${qs ? `${qs}&page=${pageParam}` : `page=${pageParam}`}`)
      if (Array.isArray(res.data)) {
        return { data: res.data, total: res.data.length }
      }
      return { data: res.data.data, total: res.data.total }
    },
  })

  const requirements = data?.data || []
  const totalResults = data?.total ?? requirements.length

  const handleClearFilters = () => {
    setNameInput('')
    setRegInput('')
    setSearchParams(new URLSearchParams())
  }

  const handleStatusChange = (status: string) => {
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev)
      if (status) next.set('status', status)
      else next.delete('status')
      next.set('page', '1')
      return next
    })
  }

  const handleDateChange = (key: 'deadline_from' | 'deadline_to', val: string) => {
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev)
      if (val) next.set(key, val)
      else next.delete(key)
      next.set('page', '1')
      return next
    })
  }

  const hasActiveFilters = !!(nameParam || regParam || statusParam || fromParam || toParam)

  return (
    <div className="min-h-screen bg-slate-50 p-6">
      <div className="mx-auto max-w-6xl space-y-6">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold text-slate-800">Compliance Requirements</h1>
            <p className="text-sm text-slate-500">Search and filter regulatory compliance requirements.</p>
          </div>
          <Link
            to="/dashboard"
            className="rounded-lg bg-slate-100 px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-200"
          >
            Back to Dashboard
          </Link>
        </div>

        {/* Filter Bar */}
        <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm space-y-4">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <div>
              <label className="block text-xs font-medium text-slate-600 mb-1">Name</label>
              <input
                type="text"
                value={nameInput}
                onChange={(e) => setNameInput(e.target.value)}
                placeholder="Search name..."
                className="w-full rounded-lg border border-slate-300 p-2 text-xs focus:border-indigo-500 focus:outline-none"
              />
            </div>

            <div>
              <label className="block text-xs font-medium text-slate-600 mb-1">Regulation</label>
              <input
                type="text"
                value={regInput}
                onChange={(e) => setRegInput(e.target.value)}
                placeholder="Regulation standard..."
                className="w-full rounded-lg border border-slate-300 p-2 text-xs focus:border-indigo-500 focus:outline-none"
              />
            </div>

            <div>
              <label className="block text-xs font-medium text-slate-600 mb-1">Status</label>
              <select
                value={statusParam}
                onChange={(e) => handleStatusChange(e.target.value)}
                className="w-full rounded-lg border border-slate-300 p-2 text-xs focus:border-indigo-500 focus:outline-none"
              >
                <option value="">All statuses</option>
                <option value="compliant">Compliant</option>
                <option value="pending">Pending</option>
                <option value="at_risk">At Risk</option>
                <option value="overdue">Overdue</option>
              </select>
            </div>

            <div>
              <label className="block text-xs font-medium text-slate-600 mb-1">Deadline From</label>
              <input
                type="date"
                value={fromParam}
                onChange={(e) => handleDateChange('deadline_from', e.target.value)}
                className="w-full rounded-lg border border-slate-300 p-2 text-xs focus:border-indigo-500 focus:outline-none"
              />
            </div>

            <div>
              <label className="block text-xs font-medium text-slate-600 mb-1">Deadline To</label>
              <input
                type="date"
                value={toParam}
                onChange={(e) => handleDateChange('deadline_to', e.target.value)}
                className="w-full rounded-lg border border-slate-300 p-2 text-xs focus:border-indigo-500 focus:outline-none"
              />
            </div>
          </div>

          <div className="flex items-center justify-between border-t border-slate-100 pt-3">
            <span className="text-xs font-medium text-slate-500" data-testid="results-count">
              {totalResults} results
            </span>

            {hasActiveFilters && (
              <button
                type="button"
                onClick={handleClearFilters}
                className="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-50"
              >
                Clear filters
              </button>
            )}
          </div>
        </div>

        {/* Results List */}
        {isLoading && (
          <div className="p-8 text-center text-sm text-slate-500">Loading requirements...</div>
        )}

        {!isLoading && requirements.length === 0 && (
          <div className="rounded-xl border border-slate-200 bg-white p-12 text-center text-slate-500 shadow-sm">
            No results found. Try changing or clearing the filters.
          </div>
        )}

        {!isLoading && requirements.length > 0 && (
          <div className="grid gap-3">
            {requirements.map((req) => (
              <div
                key={req.id}
                className="flex items-center justify-between rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition hover:shadow-md"
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
                    className={`rounded-full px-2.5 py-1 text-xs font-semibold ${
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
                    View
                  </Link>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  )
}
