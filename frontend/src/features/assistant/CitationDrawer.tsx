import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import axios from 'axios'
import { api } from '../../lib/api'
import type { Citation } from './api'
import { citationLabel } from '../../pages/AssistantPage'

export interface SourceChunk {
  id: number
  document_id: number
  document_name: string
  category: string
  access_level: string
  version_label: string
  version_id: number
  page_start: number | null
  page_end: number | null
  section: string | null
  content: string
  mime_type: string
}

interface CitationDrawerProps {
  citation: Citation | null
  onClose: () => void
}

export default function CitationDrawer({ citation, onClose }: CitationDrawerProps) {
  const [docViewerOpen, setDocViewerOpen] = useState(false)

  const chunkId = citation?.chunk_id

  const { data: source, isLoading, error } = useQuery<SourceChunk>({
    queryKey: ['citation', chunkId],
    queryFn: async () => {
      const res = await api.get<SourceChunk>(`/api/citations/${chunkId}`)
      return res.data
    },
    enabled: !!chunkId,
    retry: false,
  })

  if (!citation) return null

  let errorMessage: string | null = null
  if (axios.isAxiosError(error)) {
    if (error.response?.status === 403) {
      errorMessage = error.response.data?.message || 'You do not have permission to view this source.'
    } else if (error.response?.status === 404) {
      errorMessage = error.response.data?.message || 'This source is no longer available.'
    } else {
      errorMessage = 'Error loading source citation.'
    }
  }

  const pageOrSection = source?.page_start != null
    ? source.page_end != null && source.page_end !== source.page_start
      ? `pages ${source.page_start}-${source.page_end}`
      : `page ${source.page_start}`
    : source?.section
    ? `section "${source.section}"`
    : citation.page_start != null
    ? `page ${citation.page_start}`
    : citation.section
    ? `section "${citation.section}"`
    : 'Entire document'

  return (
    <>
      <div
        data-testid="citation-drawer"
        className="fixed inset-y-0 right-0 z-50 flex w-full max-w-md flex-col bg-white shadow-2xl border-l border-slate-200 transition-transform duration-300 ease-in-out sm:max-w-lg"
      >
        <div className="flex items-center justify-between border-b border-slate-200 px-6 py-4 bg-slate-50">
          <div>
            <h2 className="text-base font-bold text-slate-800">Source Citation</h2>
            <p className="text-xs text-slate-500">{citationLabel(citation)}</p>
          </div>
          <button
            type="button"
            data-testid="close-drawer"
            onClick={onClose}
            className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-200 hover:text-slate-600"
            aria-label="Close"
          >
            ✕
          </button>
        </div>

        <div className="flex-1 overflow-y-auto p-6 space-y-5">
          {isLoading && (
            <div className="py-12 text-center text-sm text-slate-500">
              Loading source details...
            </div>
          )}

          {errorMessage && (
            <div
              data-testid="citation-error"
              className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700"
            >
              {errorMessage}
            </div>
          )}

          {!isLoading && !errorMessage && source && (
            <>
              <div className="space-y-2 rounded-lg border border-slate-200 bg-slate-50/50 p-4 text-xs text-slate-600">
                <div>
                  <strong className="text-slate-700">Document:</strong> {source.document_name}
                </div>
                <div>
                  <strong className="text-slate-700">Version:</strong> {source.version_label}
                </div>
                <div>
                  <strong className="text-slate-700">Location:</strong>{' '}
                  <span data-testid="citation-location">{pageOrSection}</span>
                </div>
              </div>

              <div>
                <h3 className="text-xs font-semibold uppercase text-slate-500 mb-2">
                  Cited Fragment
                </h3>
                <div
                  data-testid="highlighted-fragment"
                  className="rounded-xl border border-yellow-300 bg-yellow-50 p-4 text-sm text-slate-800 leading-relaxed font-mono whitespace-pre-wrap"
                >
                  <mark className="bg-yellow-200 px-1 rounded text-slate-900">
                    {source.content}
                  </mark>
                </div>
              </div>

              <div className="pt-2">
                <button
                  type="button"
                  data-testid="open-document-button"
                  onClick={() => setDocViewerOpen(true)}
                  className="w-full rounded-lg bg-indigo-600 px-4 py-2.5 text-xs font-semibold text-white shadow-sm hover:bg-indigo-700 transition"
                >
                  Open document
                </button>
              </div>
            </>
          )}
        </div>
      </div>

      {/* Document Viewer Modal */}
      {docViewerOpen && source && (
        <div
          data-testid="document-viewer-modal"
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
        >
          <div className="flex h-[85vh] w-full max-w-4xl flex-col rounded-2xl bg-white shadow-2xl overflow-hidden">
            <div className="flex items-center justify-between border-b border-slate-200 px-6 py-4 bg-slate-50">
              <div>
                <h3 className="font-bold text-slate-800">{source.document_name}</h3>
                <p className="text-xs text-slate-500">
                  {source.version_label} | {pageOrSection}
                </p>
              </div>
              <button
                type="button"
                onClick={() => setDocViewerOpen(false)}
                className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-200 hover:text-slate-600"
              >
                ✕
              </button>
            </div>

            <div className="flex-1 overflow-y-auto p-6 bg-slate-100">
              <div className="mx-auto max-w-2xl rounded-xl bg-white p-8 shadow-sm border border-slate-200">
                <div className="mb-4 text-xs font-semibold text-indigo-600 uppercase tracking-wide">
                  {source.mime_type.includes('pdf') ? `PDF Viewer - ${pageOrSection}` : `Section Text - ${pageOrSection}`}
                </div>
                <div className="font-mono text-sm leading-relaxed text-slate-800 whitespace-pre-wrap">
                  <mark className="bg-yellow-200 rounded px-1">{source.content}</mark>
                </div>
              </div>
            </div>
          </div>
        </div>
      )}
    </>
  )
}
