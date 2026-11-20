import { useState, useEffect } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link, useSearchParams, useNavigate } from 'react-router-dom'
import { api } from '../lib/api'

interface DocumentItem {
  id: number
  name: string
  category: string
  access_level: string
  created_at: string
  current_version?: {
    id: number
    version_label: string
    status: string
    expiration_date: string | null
    uploaded_at: string
  } | null
}

interface DocumentsResponse {
  data: DocumentItem[]
  total: number
}

export default function DocumentsListPage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const navigate = useNavigate()

  const nameParam = searchParams.get('name') || ''
  const categoryParam = searchParams.get('category') || ''
  const statusParam = searchParams.get('status') || ''
  const uploadedFromParam = searchParams.get('uploaded_from') || ''
  const uploadedToParam = searchParams.get('uploaded_to') || ''
  const expiresFromParam = searchParams.get('expires_from') || ''
  const expiresToParam = searchParams.get('expires_to') || ''
  const pageParam = parseInt(searchParams.get('page') || '1', 10)

  const [nameInput, setNameInput] = useState(nameParam)

  // Debounce name search (300ms)
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

  const { data, isLoading } = useQuery<DocumentsResponse>({
    queryKey: ['documents', searchParams.toString()],
    queryFn: async () => {
      const qs = searchParams.toString()
      const res = await api.get<DocumentItem[] | { data: DocumentItem[]; total: number }>(`/api/documents?${qs ? `${qs}&page=${pageParam}` : `page=${pageParam}`}`)
      if (Array.isArray(res.data)) {
        return { data: res.data, total: res.data.length }
      }
      return { data: res.data.data, total: res.data.total }
    },
  })

  const documents = data?.data || []
  const totalResults = data?.total ?? documents.length

  const handleFilterChange = (key: string, value: string) => {
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev)
      if (value) next.set(key, value)
      else next.delete(key)
      next.set('page', '1')
      return next
    })
  }

  const handleClearFilters = () => {
    setNameInput('')
    setSearchParams(new URLSearchParams())
  }

  const hasActiveFilters = !!(
    nameParam ||
    categoryParam ||
    statusParam ||
    uploadedFromParam ||
    uploadedToParam ||
    expiresFromParam ||
    expiresToParam
  )

  return (
    <div className="min-h-screen bg-slate-50 p-6">
      <div className="mx-auto max-w-6xl space-y-6">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold text-slate-800">Documents</h1>
            <p className="text-sm text-slate-500">Search and filter organizational compliance documents.</p>
          </div>
          <Link
            to="/documents/new"
            className="rounded-lg bg-indigo-600 px-4 py-2 text-xs font-semibold text-white hover:bg-indigo-700"
          >
            Upload New Document
          </Link>
        </div>

        {/* Filters */}
        <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm space-y-4">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div>
              <label className="block text-xs font-medium text-slate-600 mb-1">Name</label>
              <input
                type="text"
                value={nameInput}
                onChange={(e) => setNameInput(e.target.value)}
                placeholder="Search document name..."
                className="w-full rounded-lg border border-slate-300 p-2 text-xs focus:border-indigo-500 focus:outline-none"
              />
            </div>

            <div>
              <label className="block text-xs font-medium text-slate-600 mb-1">Category</label>
              <select
                value={categoryParam}
                onChange={(e) => handleFilterChange('category', e.target.value)}
                className="w-full rounded-lg border border-slate-300 p-2 text-xs focus:border-indigo-500 focus:outline-none"
              >
                <option value="">All Categories</option>
                <option value="permit_license">Permit / License</option>
                <option value="policy">Policy</option>
                <option value="contract">Contract</option>
                <option value="certificate">Certificate</option>
                <option value="report">Report</option>
                <option value="regulation">Regulation</option>
                <option value="other">Other</option>
              </select>
            </div>

            <div>
              <label className="block text-xs font-medium text-slate-600 mb-1">Processing Status</label>
              <select
                value={statusParam}
                onChange={(e) => handleFilterChange('status', e.target.value)}
                className="w-full rounded-lg border border-slate-300 p-2 text-xs focus:border-indigo-500 focus:outline-none"
              >
                <option value="">All Statuses</option>
                <option value="queued">Queued</option>
                <option value="processing">Processing</option>
                <option value="processed">Processed</option>
                <option value="processing_failed">Processing Failed</option>
              </select>
            </div>

            <div>
              <label className="block text-xs font-medium text-slate-600 mb-1">Expires From</label>
              <input
                type="date"
                value={expiresFromParam}
                onChange={(e) => handleFilterChange('expires_from', e.target.value)}
                className="w-full rounded-lg border border-slate-300 p-2 text-xs focus:border-indigo-500 focus:outline-none"
              />
            </div>

            <div>
              <label className="block text-xs font-medium text-slate-600 mb-1">Expires To</label>
              <input
                type="date"
                value={expiresToParam}
                onChange={(e) => handleFilterChange('expires_to', e.target.value)}
                className="w-full rounded-lg border border-slate-300 p-2 text-xs focus:border-indigo-500 focus:outline-none"
              />
            </div>

            <div>
              <label className="block text-xs font-medium text-slate-600 mb-1">Uploaded From</label>
              <input
                type="date"
                value={uploadedFromParam}
                onChange={(e) => handleFilterChange('uploaded_from', e.target.value)}
                className="w-full rounded-lg border border-slate-300 p-2 text-xs focus:border-indigo-500 focus:outline-none"
              />
            </div>

            <div>
              <label className="block text-xs font-medium text-slate-600 mb-1">Uploaded To</label>
              <input
                type="date"
                value={uploadedToParam}
                onChange={(e) => handleFilterChange('uploaded_to', e.target.value)}
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

        {/* Results */}
        {isLoading && (
          <div className="p-8 text-center text-sm text-slate-500">Loading documents...</div>
        )}

        {!isLoading && documents.length === 0 && (
          <div className="rounded-xl border border-slate-200 bg-white p-12 text-center text-slate-500 shadow-sm">
            No results found. Try changing or clearing the filters.
          </div>
        )}

        {!isLoading && documents.length > 0 && (
          <div className="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <table className="w-full text-left text-sm">
              <thead className="border-b border-slate-200 bg-slate-50 text-xs font-semibold uppercase text-slate-500">
                <tr>
                  <th className="px-6 py-3">Document Name</th>
                  <th className="px-6 py-3">Category</th>
                  <th className="px-6 py-3">Current Version</th>
                  <th className="px-6 py-3">Status</th>
                  <th className="px-6 py-3">Expiration Date</th>
                  <th className="px-6 py-3">Access Level</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {documents.map((doc) => {
                  const version = doc.current_version
                  return (
                    <tr
                      key={doc.id}
                      onClick={() => navigate(`/documents/${doc.id}`)}
                      className="cursor-pointer hover:bg-slate-50 transition"
                    >
                      <td className="px-6 py-4 font-medium text-slate-900 hover:text-indigo-600">
                        {doc.name}
                      </td>
                      <td className="px-6 py-4 text-slate-600">{doc.category}</td>
                      <td className="px-6 py-4 text-slate-600">
                        {version ? version.version_label : 'None'}
                      </td>
                      <td className="px-6 py-4">
                        <span className="inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700">
                          {version ? version.status : 'No Version'}
                        </span>
                      </td>
                      <td className="px-6 py-4 text-slate-600">
                        {version?.expiration_date ? new Date(version.expiration_date).toLocaleDateString() : 'No expiration'}
                      </td>
                      <td className="px-6 py-4 text-slate-500 text-xs">{doc.access_level}</td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  )
}
