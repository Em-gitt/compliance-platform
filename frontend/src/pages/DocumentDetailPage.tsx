import { useEffect, useState } from 'react'
import { useParams, Link } from 'react-router-dom'
import axios from 'axios'
import { api } from '../lib/api'
import { useAuth } from '../auth/AuthContext'

export interface DocumentVersionData {
  id: number | string
  version: string
  version_label?: string
  uploaded_by: string
  upload_date: string | null
  uploaded_at?: string | null
  status: string
  expiration_date: string | null
  is_current: boolean
  download_url?: string
  failure_reason?: string | null
}

export interface DocumentData {
  id: number | string
  name: string
  category: string
  category_name?: string
  access_level: string
  visibility?: string
  is_archived?: boolean
  current_version?: string
  expiration_date?: string | null
  expiration_date_formatted?: string
  upload_date?: string | null
  uploaded_at?: string | null
  uploaded_by?: string
  processing_status?: string
  status?: string
  versions?: DocumentVersionData[]
}

function useSafeAuth() {
  try {
    return useAuth()
  } catch {
    return null
  }
}

export default function DocumentDetailPage() {
  const { id } = useParams<{ id: string }>()
  const auth = useSafeAuth()

  const [document, setDocument] = useState<DocumentData | null>(null)
  const [versions, setVersions] = useState<DocumentVersionData[]>([])
  const [activeTab, setActiveTab] = useState<'overview' | 'versions'>('overview')
  const [loading, setLoading] = useState(true)
  const [errorMessage, setErrorMessage] = useState<string | null>(null)
  const [processingFailureMessage, setProcessingFailureMessage] = useState<string | null>(null)

  // Upload new version modal state
  const [isModalOpen, setIsModalOpen] = useState(false)
  const [uploadVersionLabel, setUploadVersionLabel] = useState('')
  const [uploadExpirationDate, setUploadExpirationDate] = useState('')
  const [uploadFile, setUploadFile] = useState<File | null>(null)
  const [uploadError, setUploadError] = useState<string | null>(null)
  const [uploading, setUploading] = useState(false)

  const userRole = auth?.user?.role
  const canUpload = userRole ? ['administrator', 'compliance_staff'].includes(userRole) : true

  const fetchDocumentAndVersions = async () => {
    try {
      setLoading(true)
      setErrorMessage(null)
      const response = await api.get(`/api/documents/${id}`)
      const doc = response.data?.document || response.data
      setDocument(doc)

      let verList: DocumentVersionData[] = []
      if (doc.versions && Array.isArray(doc.versions)) {
        verList = doc.versions
      } else {
        const verResponse = await api.get(`/api/documents/${id}/versions`)
        verList = verResponse.data || []
      }
      setVersions(verList)

      // Check for processing failure alert
      const currentVer = verList.find((v) => v.is_current)
      const failedVer = verList.find((v) => v.status === 'failed' || v.status === 'processing_failed')
      if (failedVer) {
        const currentLabel = currentVer?.version || currentVer?.version_label || '1.0'
        const failedLabel = failedVer.version || failedVer.version_label || 'new'
        setProcessingFailureMessage(
          `Version ${failedLabel} could not be processed. Version ${currentLabel} remains the current version.`
        )
      }
    } catch (err) {
      if (axios.isAxiosError(err)) {
        if (err.response?.status === 403) {
          setErrorMessage('You do not have permission to view this document.')
        } else if (err.response?.status === 404) {
          setErrorMessage('Document not found.')
        } else {
          setErrorMessage(err.response?.data?.message || 'Failed to load document details.')
        }
      } else {
        setErrorMessage('An unexpected error occurred.')
      }
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    if (id) {
      fetchDocumentAndVersions()
    }
  }, [id])

  const handleUploadNewVersion = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!uploadFile) {
      setUploadError('Please select a file.')
      return
    }
    if (!uploadVersionLabel.trim()) {
      setUploadError('Please provide a version label.')
      return
    }

    try {
      setUploading(true)
      setUploadError(null)

      const formData = new FormData()
      formData.append('file', uploadFile)
      formData.append('version', uploadVersionLabel.trim())
      if (uploadExpirationDate) {
        formData.append('expiration_date', uploadExpirationDate)
      }

      await api.post(`/api/documents/${id}/versions`, formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })

      setIsModalOpen(false)
      setUploadFile(null)
      setUploadVersionLabel('')
      setUploadExpirationDate('')
      await fetchDocumentAndVersions()
    } catch (err) {
      if (axios.isAxiosError(err)) {
        const currentVer = versions.find((v) => v.is_current)
        const currentLabel = currentVer?.version || currentVer?.version_label || '1.0'
        const failedMessage = `Version ${uploadVersionLabel} could not be processed. Version ${currentLabel} remains the current version.`
        setProcessingFailureMessage(failedMessage)
        setUploadError(err.response?.data?.message || failedMessage)
      } else {
        setUploadError('An unexpected error occurred while uploading.')
      }
    } finally {
      setUploading(false)
    }
  }

  const handleDownload = (versionId: number | string) => {
    window.open(`/api/documents/${id}/versions/${versionId}/download`, '_blank')
  }

  if (loading) {
    return (
      <div className="max-w-4xl mx-auto py-8 px-4" role="status">
        <p className="text-gray-600">Loading document details...</p>
      </div>
    )
  }

  if (errorMessage) {
    return (
      <div className="max-w-4xl mx-auto py-8 px-4">
        <div
          role="alert"
          className="p-4 rounded-md bg-red-50 border border-red-200 text-red-700 font-medium mb-6"
        >
          {errorMessage}
        </div>
        <Link
          to="/documents/new"
          className="text-blue-600 hover:text-blue-800 underline font-medium"
        >
          Upload a document
        </Link>
      </div>
    )
  }

  if (!document) {
    return null
  }

  const categoryDisplay = document.category_name || document.category
  const visibilityDisplay =
    document.visibility || (document.access_level === 'compliance_only' ? 'Compliance only' : 'All staff')
  const versionDisplay = document.current_version || '1.0.0'
  const expirationDisplay = document.expiration_date_formatted || (document.expiration_date ? document.expiration_date : 'No expiration')
  const uploadedByDisplay = document.uploaded_by || 'Unknown'
  const uploadDateDisplay = document.upload_date || document.uploaded_at || 'Unknown'
  const statusDisplay = document.processing_status || document.status || 'Queued'

  return (
    <div className="max-w-4xl mx-auto py-8 px-4">
      {processingFailureMessage && (
        <div
          role="alert"
          className="p-4 rounded-md bg-red-50 border border-red-200 text-red-700 font-medium mb-6"
          data-testid="version-failure-alert"
        >
          {processingFailureMessage}
        </div>
      )}

      <div className="flex items-center justify-between mb-6">
        <h1 className="text-2xl font-bold text-gray-900" data-testid="doc-name">
          {document.name}
        </h1>
        <div className="flex items-center space-x-4">
          {canUpload && (
            <button
              onClick={() => setIsModalOpen(true)}
              className="px-4 py-2 bg-blue-600 text-white rounded-md text-sm font-medium hover:bg-blue-700 shadow-sm"
              data-testid="upload-new-version-button"
            >
              Upload new version
            </button>
          )}
          <Link
            to="/documents/new"
            className="text-sm font-medium text-blue-600 hover:text-blue-800 underline"
          >
            Upload another document
          </Link>
        </div>
      </div>

      {/* Tabs */}
      <div className="border-b border-gray-200 mb-6">
        <nav className="-mb-px flex space-x-8" aria-label="Tabs">
          <button
            onClick={() => setActiveTab('overview')}
            className={`py-4 px-1 border-b-2 font-medium text-sm ${
              activeTab === 'overview'
                ? 'border-blue-500 text-blue-600'
                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
            }`}
            data-testid="tab-overview"
          >
            Overview
          </button>
          <button
            onClick={() => setActiveTab('versions')}
            className={`py-4 px-1 border-b-2 font-medium text-sm ${
              activeTab === 'versions'
                ? 'border-blue-500 text-blue-600'
                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
            }`}
            data-testid="tab-versions"
          >
            Versions
          </button>
        </nav>
      </div>

      {/* Tab Content: Overview */}
      {activeTab === 'overview' && (
        <div className="bg-white shadow rounded-lg border border-gray-200 divide-y divide-gray-200">
          <div className="px-6 py-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <span className="text-xs font-semibold text-gray-500 uppercase tracking-wider block">
                Category
              </span>
              <span className="text-sm font-medium text-gray-900" data-testid="doc-category">
                {categoryDisplay}
              </span>
            </div>

            <div>
              <span className="text-xs font-semibold text-gray-500 uppercase tracking-wider block">
                Current version
              </span>
              <span className="text-sm font-medium text-gray-900" data-testid="doc-version">
                {versionDisplay}
              </span>
            </div>

            <div>
              <span className="text-xs font-semibold text-gray-500 uppercase tracking-wider block">
                Visibility
              </span>
              <span className="text-sm font-medium text-gray-900" data-testid="doc-visibility">
                {visibilityDisplay}
              </span>
            </div>

            <div>
              <span className="text-xs font-semibold text-gray-500 uppercase tracking-wider block">
                Expiration date
              </span>
              <span className="text-sm font-medium text-gray-900" data-testid="doc-expiration">
                {expirationDisplay}
              </span>
            </div>

            <div>
              <span className="text-xs font-semibold text-gray-500 uppercase tracking-wider block">
                Uploaded by
              </span>
              <span className="text-sm font-medium text-gray-900" data-testid="doc-uploaded-by">
                {uploadedByDisplay}
              </span>
            </div>

            <div>
              <span className="text-xs font-semibold text-gray-500 uppercase tracking-wider block">
                Upload date
              </span>
              <span className="text-sm font-medium text-gray-900" data-testid="doc-upload-date">
                {uploadDateDisplay}
              </span>
            </div>

            <div className="sm:col-span-2">
              <span className="text-xs font-semibold text-gray-500 uppercase tracking-wider block">
                Processing status
              </span>
              <span
                className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium capitalize mt-1 bg-blue-100 text-blue-800"
                data-testid="doc-status"
              >
                {statusDisplay}
              </span>
            </div>
          </div>
        </div>
      )}

      {/* Tab Content: Versions */}
      {activeTab === 'versions' && (
        <div className="bg-white shadow rounded-lg border border-gray-200 overflow-hidden">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Version
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Uploaded by
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Upload date
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Status
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Expiration date
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Current
                </th>
                <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Download
                </th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-200" data-testid="versions-table-body">
              {versions.map((ver) => (
                <tr key={ver.id} data-testid={`version-row-${ver.version || ver.version_label}`}>
                  <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                    {ver.version || ver.version_label}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                    {ver.uploaded_by}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                    {ver.upload_date || ver.uploaded_at || 'Unknown'}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 capitalize">
                    {ver.status}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                    {ver.expiration_date || 'No expiration'}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                    {ver.is_current ? (
                      <span
                        className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-green-100 text-green-800"
                        data-testid={`badge-current-${ver.version || ver.version_label}`}
                      >
                        Current
                      </span>
                    ) : null}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                    <button
                      onClick={() => handleDownload(ver.id)}
                      className="text-blue-600 hover:text-blue-900 font-medium"
                      data-testid={`download-button-${ver.version || ver.version_label}`}
                    >
                      Download
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {/* Upload new version modal */}
      {isModalOpen && (
        <div
          className="fixed inset-0 z-50 overflow-y-auto bg-black bg-opacity-50 flex items-center justify-center p-4"
          data-testid="upload-version-modal"
        >
          <div className="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
            <h2 className="text-lg font-bold text-gray-900 mb-4">Upload new version</h2>

            {uploadError && (
              <div className="p-3 mb-4 rounded-md bg-red-50 text-red-700 text-sm">
                {uploadError}
              </div>
            )}

            <form onSubmit={handleUploadNewVersion} className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700">File</label>
                <input
                  type="file"
                  required
                  accept=".pdf"
                  onChange={(e) => setUploadFile(e.target.files?.[0] || null)}
                  className="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100"
                  data-testid="upload-file-input"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700">Version label</label>
                <input
                  type="text"
                  required
                  placeholder="e.g. 2.0"
                  value={uploadVersionLabel}
                  onChange={(e) => setUploadVersionLabel(e.target.value)}
                  className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm border p-2"
                  data-testid="upload-version-input"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700">Expiration date (optional)</label>
                <input
                  type="date"
                  value={uploadExpirationDate}
                  onChange={(e) => setUploadExpirationDate(e.target.value)}
                  className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm border p-2"
                  data-testid="upload-expiration-input"
                />
              </div>

              <div className="flex justify-end space-x-3 pt-4 border-t border-gray-200">
                <button
                  type="button"
                  onClick={() => {
                    setIsModalOpen(false)
                    setUploadError(null)
                  }}
                  className="px-4 py-2 bg-white text-gray-700 border border-gray-300 rounded-md text-sm font-medium hover:bg-gray-50"
                  data-testid="cancel-upload-button"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={uploading}
                  className="px-4 py-2 bg-blue-600 text-white rounded-md text-sm font-medium hover:bg-blue-700 disabled:opacity-50"
                  data-testid="submit-upload-button"
                >
                  {uploading ? 'Uploading...' : 'Upload'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
