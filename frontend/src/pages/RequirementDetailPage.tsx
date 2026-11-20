import { useState } from 'react'
import { useParams, Link, useLocation } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import axios from 'axios'
import { api } from '../lib/api'
import StatusBadge from '../components/StatusBadge'
import Toast from '../components/Toast'
import { isoToDisplayDate } from '../features/requirements/dates'
import { REQUIREMENT_LABELS as L } from '../features/requirements/messages'
import StatusChanger from '../features/requirements/StatusChanger'
import { canCreateRequirements, useCurrentUser } from '../lib/auth'

interface DocumentItem {
  id: number
  name: string
  category: string | { code?: string; name?: string } | null
  access_level: string
  current_version?: {
    id: number
    version_label: string
    status: string
    expiration_date?: string | null
  }
}

interface RequiredDocumentItem {
  id: number
  name: string
  category: string | { code?: string; name?: string } | null
  document_id: number | null
  status: 'provided' | 'missing' | 'processing' | 'expired'
  badge: 'Provided' | 'Missing Document' | 'Processing'
  tooltip: string | null
  is_satisfied: boolean
  document?: DocumentItem | null
}

interface RequirementDetail {
  id: number
  name: string
  description: string
  regulation: string
  deadline: string
  status: string | { code?: string; label?: string } | null
  responsible_user?: { id: number; name: string } | null
  missing_documents_count?: number
  all_documents_provided?: boolean
  missing_documents_label?: string
  required_documents?: RequiredDocumentItem[]
}

export default function RequirementDetailPage() {
  const { id = '' } = useParams<{ id: string }>()
  const location = useLocation()
  const toast = (location.state as { toast?: string } | null)?.toast
  const { data: user } = useCurrentUser()
  const queryClient = useQueryClient()
  const [activeLinkId, setActiveLinkId] = useState<number | null>(null)
  const [selectedDocId, setSelectedDocId] = useState<string>('')
  const [errorMessage, setErrorMessage] = useState<string | null>(null)

  const { data: requirement, isLoading, error } = useQuery<RequirementDetail>({
    queryKey: ['requirements', id],
    queryFn: async () => {
      try {
        const res = await api.get(`/api/compliance-requirements/${id}`)
        if (res.data?.data?.name || res.data?.name) {
          return res.data?.data ?? res.data
        }
      } catch {
        // Fallback to legacy endpoint if compliance-requirements route fails
      }
      const res = await api.get<{ data?: RequirementDetail } & RequirementDetail>(`/api/requirements/${id}`)
      return res.data?.data ?? res.data
    },
  })

  // Fetch available documents when link modal/dropdown opens
  const activeRequiredDoc = (requirement?.required_documents ?? []).find((d) => d.id === activeLinkId)

  const { data: availableDocuments = [] } = useQuery<DocumentItem[]>({
    queryKey: ['documents', activeRequiredDoc?.category],
    enabled: !!activeRequiredDoc,
    queryFn: async () => {
      const cat = typeof activeRequiredDoc?.category === 'object' ? activeRequiredDoc?.category?.code : activeRequiredDoc?.category
      const res = await api.get<DocumentItem[]>(`/api/documents?category=${cat}`)
      return res.data
    },
  })

  const linkMutation = useMutation({
    mutationFn: async ({ requiredDocumentId, documentId }: { requiredDocumentId: number; documentId: number }) => {
      const res = await api.post(`/api/requirements/${id}/documents`, {
        required_document_id: requiredDocumentId,
        document_id: documentId,
      })
      return res.data
    },
    onSuccess: (data) => {
      setErrorMessage(null)
      setActiveLinkId(null)
      setSelectedDocId('')
      // Update cache in-place without page reload (AC #2)
      queryClient.setQueryData(['requirements', id], (old: RequirementDetail | undefined) => {
        if (!old) return old
        const updatedDocs = (old.required_documents ?? []).map((d) => {
          if (d.id === data.required_document.id) {
            return {
              ...d,
              ...data.required_document,
            }
          }
          return d
        })
        const missingCount = updatedDocs.filter((d) => !d.is_satisfied).length
        return {
          ...old,
          required_documents: updatedDocs,
          missing_documents_count: missingCount,
          all_documents_provided: missingCount === 0,
          missing_documents_label: missingCount === 0 ? 'All documents provided' : `${missingCount} missing documents`,
        }
      })
    },
    onError: (err) => {
      if (axios.isAxiosError(err) && err.response?.data?.message) {
        setErrorMessage(err.response.data.message)
      } else {
        setErrorMessage('Failed to link document.')
      }
    },
  })

  const unlinkMutation = useMutation({
    mutationFn: async (requiredDocumentId: number) => {
      const res = await api.delete(`/api/requirements/${id}/documents/${requiredDocumentId}`)
      return res.data
    },
    onSuccess: (data) => {
      setErrorMessage(null)
      queryClient.setQueryData(['requirements', id], (old: RequirementDetail | undefined) => {
        if (!old) return old
        const updatedDocs = (old.required_documents ?? []).map((d) => {
          if (d.id === data.required_document.id) {
            return {
              ...d,
              ...data.required_document,
            }
          }
          return d
        })
        const missingCount = updatedDocs.filter((d) => !d.is_satisfied).length
        return {
          ...old,
          required_documents: updatedDocs,
          missing_documents_count: missingCount,
          all_documents_provided: missingCount === 0,
          missing_documents_label: missingCount === 0 ? 'All documents provided' : `${missingCount} missing documents`,
        }
      })
    },
  })

  return (
    <div className="min-h-screen bg-slate-50 p-6">
      <div className="mx-auto max-w-4xl space-y-6">
        {toast && <Toast message={toast} />}

        {isLoading && <div className="p-8 text-center text-slate-500">Loading requirement...</div>}

        {(error || (!requirement && !isLoading)) && (
          <div className="p-8 text-center text-red-600">Requirement not found or error loading.</div>
        )}

        {requirement && (
          <>
            <div className="flex items-center justify-between">
              <Link
                to="/requirements"
                className="text-sm font-medium text-indigo-600 hover:text-indigo-800"
              >
                ← Back to Requirements
              </Link>
              {requirement.missing_documents_label && (
                <span
                  className={`rounded-full px-3 py-1 text-xs font-semibold ${
                    requirement.all_documents_provided
                      ? 'bg-emerald-100 text-emerald-800'
                      : 'bg-red-100 text-red-800'
                  }`}
                >
                  {requirement.missing_documents_label}
                </span>
              )}
            </div>

            {errorMessage && (
              <div className="rounded-lg bg-red-50 p-4 text-sm text-red-700 border border-red-200">
                {errorMessage}
              </div>
            )}

            <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
              <div className="flex items-center justify-between">
                <h1 className="text-2xl font-bold text-slate-900">{requirement.name}</h1>
                <div className="flex items-center gap-3">
                  <StatusBadge status={requirement.status} />
                  {user?.role === 'compliance_staff' && (
                    <StatusChanger requirement={requirement} />
                  )}
                  {canCreateRequirements(user?.role) && (
                    <Link
                      to={`/requirements/${requirement.id}/criteria`}
                      className="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-blue-700 hover:bg-slate-50 hover:underline"
                    >
                      Applicability criteria
                    </Link>
                  )}
                </div>
              </div>

              <dl className="mt-6 divide-y divide-gray-200 rounded-md border border-gray-200">
                {[
                  [L.description, requirement.description],
                  [L.regulation, requirement.regulation],
                  [L.deadline, isoToDisplayDate(requirement.deadline)],
                  [L.responsible_user_id, requirement.responsible_user?.name ?? ''],
                  ['Status', typeof requirement.status === 'object' ? requirement.status?.label : requirement.status],
                ].map(([label, value]) => (
                  <div key={label} className="grid grid-cols-3 gap-4 px-4 py-3">
                    <dt className="text-sm font-medium text-gray-600">{label}</dt>
                    <dd className="col-span-2 whitespace-pre-line text-sm">{value}</dd>
                  </div>
                ))}
                <div className="grid grid-cols-3 gap-4 px-4 py-3">
                  <dt className="text-sm font-medium text-gray-600">{L.required_documents}</dt>
                  <dd className="col-span-2 text-sm">
                    <ul className="space-y-1">
                      {(requirement.required_documents ?? []).map((document: RequiredDocumentItem) => (
                        <li key={document.id}>
                          {document.name}{' '}
                          <span className="text-gray-500">
                            ({typeof document.category === 'object' && document.category !== null ? (document.category as { name?: string }).name : String(document.category ?? '')})
                          </span>
                        </li>
                      ))}
                    </ul>
                  </dd>
                </div>
              </dl>
            </div>

            {requirement.required_documents && requirement.required_documents.length > 0 && (
              <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 className="text-lg font-bold text-slate-800 mb-4">Required Documents Management</h2>

                <div className="divide-y divide-slate-100">
                  {requirement.required_documents.map((doc) => {
                    const isProvided = doc.badge === 'Provided'
                    const isProcessing = doc.badge === 'Processing'
                    const isMissing = doc.badge === 'Missing Document'

                    return (
                      <div key={doc.id} className="py-4 flex items-center justify-between gap-4">
                        <div className="space-y-1">
                          <div className="flex items-center gap-2">
                            <span className="font-semibold text-slate-800">{doc.name}</span>
                            <span className="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600">
                              {typeof doc.category === 'object' ? doc.category?.name : doc.category}
                            </span>
                          </div>

                          <div className="flex items-center gap-2 text-xs">
                            {isProvided && (
                              <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-2.5 py-0.5 font-semibold text-emerald-800">
                                Provided
                                {doc.document && (
                                  <Link
                                    to={`/documents/${doc.document.id}`}
                                    className="underline hover:text-emerald-950 font-medium ml-1"
                                  >
                                    ({doc.document.name})
                                  </Link>
                                )}
                              </span>
                            )}

                            {isProcessing && (
                              <span className="inline-flex items-center rounded-full bg-gray-200 px-2.5 py-0.5 font-semibold text-gray-800">
                                Processing
                              </span>
                            )}

                            {isMissing && (
                              <span
                                title={doc.tooltip || undefined}
                                className="inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 font-semibold text-red-800"
                              >
                                Missing Document
                                {doc.tooltip && (
                                  <span className="ml-1 text-[11px] font-normal italic text-red-600">
                                    - {doc.tooltip}
                                  </span>
                                )}
                              </span>
                            )}
                          </div>
                        </div>

                        <div className="flex items-center gap-2">
                          {doc.document_id ? (
                            <button
                              type="button"
                              onClick={() => unlinkMutation.mutate(doc.id)}
                              disabled={unlinkMutation.isPending}
                              className="rounded border border-red-200 px-3 py-1 text-xs font-medium text-red-600 hover:bg-red-50"
                            >
                              Unlink
                            </button>
                          ) : (
                            <button
                              type="button"
                              onClick={() => {
                                setActiveLinkId(doc.id)
                                setErrorMessage(null)
                              }}
                              className="rounded border border-indigo-200 bg-indigo-50 px-3 py-1 text-xs font-medium text-indigo-700 hover:bg-indigo-100"
                            >
                              Link document
                            </button>
                          )}
                        </div>
                      </div>
                    )
                  })}
                </div>
              </div>
            )}

            {activeLinkId && activeRequiredDoc && (
              <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
                <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl space-y-4">
                  <h3 className="text-lg font-bold text-slate-800">
                    Link Document for &quot;{activeRequiredDoc.name}&quot;
                  </h3>
                  <p className="text-xs text-slate-500">
                    Showing documents with matching category:{' '}
                    <strong>
                      {typeof activeRequiredDoc.category === 'object'
                        ? activeRequiredDoc.category?.name
                        : activeRequiredDoc.category}
                    </strong>
                  </p>

                  <select
                    className="w-full rounded-lg border border-slate-300 p-2 text-sm"
                    value={selectedDocId}
                    onChange={(e) => setSelectedDocId(e.target.value)}
                  >
                    <option value="">Select a document...</option>
                    {availableDocuments.map((d) => (
                      <option key={d.id} value={d.id}>
                        {d.name} {d.current_version ? `(${d.current_version.status})` : ''}
                      </option>
                    ))}
                  </select>

                  <div className="flex justify-end gap-3 pt-2">
                    <button
                      type="button"
                      onClick={() => {
                        setActiveLinkId(null)
                        setSelectedDocId('')
                      }}
                      className="rounded-lg px-4 py-2 text-xs font-medium text-slate-600 hover:bg-slate-100"
                    >
                      Cancel
                    </button>
                    <button
                      type="button"
                      disabled={!selectedDocId || linkMutation.isPending}
                      onClick={() => {
                        if (selectedDocId) {
                          linkMutation.mutate({
                            requiredDocumentId: activeLinkId,
                            documentId: Number(selectedDocId),
                          })
                        }
                      }}
                      className="rounded-lg bg-indigo-600 px-4 py-2 text-xs font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
                    >
                      {linkMutation.isPending ? 'Linking...' : 'Link Document'}
                    </button>
                  </div>
                </div>
              </div>
            )}
          </>
        )}
      </div>
    </div>
  )
}
