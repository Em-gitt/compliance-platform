import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { type FormEvent, type KeyboardEvent, useEffect, useRef, useState } from 'react'
import ReactMarkdown from 'react-markdown'
import CitationDrawer from '../features/assistant/CitationDrawer'
import {
  askQuestion,
  fetchQuestionHistory,
  messageForError,
  sendAiFeedback,
  validateQuestion,
  type AiFeedbackData,
  type AiQuestion,
  type Citation,
} from '../features/assistant/api'
import { ASSISTANT_TEXTS as T, MAX_QUESTION_LENGTH } from '../features/assistant/messages'

type ChatMessage =
  | { id: number; role: 'user'; text: string }
  | { id: number; role: 'assistant'; kind: 'loading' }
  | { id: number; role: 'assistant'; kind: 'answer'; record: AiQuestion }
  | { id: number; role: 'assistant'; kind: 'error'; message: string; retryQuestion: string | null }

let nextId = 1
const newId = () => nextId++

function LoadingBubble() {
  return (
    <div role="status" aria-live="polite" className="flex items-center gap-3">
      <span className="flex gap-1" aria-hidden="true">
        {[0, 150, 300].map((delay) => (
          <span key={delay} className="h-2 w-2 animate-bounce rounded-full bg-gray-500" style={{ animationDelay: `${delay}ms` }} />
        ))}
      </span>
      <span className="text-sm text-gray-700">{T.loading}</span>
    </div>
  )
}

export function citationLabel(citation: Citation): string {
  const version = citation.version_label
    ? ` (${citation.version_label.startsWith('v') ? citation.version_label : 'v' + citation.version_label})`
    : ''
  const base = `${citation.document_name}${version}`

  if (citation.page_start != null || (citation.page != null && citation.version_label)) {
    const start = citation.page_start ?? citation.page
    const end = citation.page_end
    const pageStr = end != null && end !== start ? `pages ${start}-${end}` : `page ${start}`
    return `${base} - ${pageStr}`
  }

  if (citation.section && citation.version_label) {
    return `${base} - section "${citation.section}"`
  }

  if (citation.version_label) {
    return base
  }

  const parts = [citation.document_name]
  if (citation.page) parts.push(`p. ${citation.page}`)
  if (citation.section) parts.push(citation.section)
  return parts.join(', ')
}

function FeedbackWidget({ questionId, initialFeedback }: { questionId: number; initialFeedback?: AiFeedbackData | null }) {
  const [rating, setRating] = useState<'useful' | 'not_useful' | null>(initialFeedback?.rating ?? null)
  const [comment, setComment] = useState(initialFeedback?.comment ?? '')
  const [submitted, setSubmitted] = useState(Boolean(initialFeedback))
  const [statusMessage, setStatusMessage] = useState<string | null>(initialFeedback ? 'Thanks for your feedback.' : null)
  const [errorMessage, setErrorMessage] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)

  const handleRatingClick = async (newRating: 'useful' | 'not_useful') => {
    setRating(newRating)
    setErrorMessage(null)
    if (newRating === 'useful') {
      setIsSubmitting(true)
      try {
        const res = await sendAiFeedback(questionId, 'useful')
        setSubmitted(true)
        setStatusMessage(res.message || 'Thanks for your feedback.')
      } catch (err: unknown) {
        const axiosError = err as { response?: { data?: { message?: string } } }
        setErrorMessage(axiosError.response?.data?.message || 'Failed to submit feedback.')
      } finally {
        setIsSubmitting(false)
      }
    } else {
      setSubmitted(false)
    }
  }

  const handleSendFeedback = async () => {
    if (!rating) return
    if (comment.length > 500) {
      setErrorMessage('Comments can be up to 500 characters.')
      return
    }
    setIsSubmitting(true)
    setErrorMessage(null)
    try {
      const res = await sendAiFeedback(questionId, rating, comment)
      setSubmitted(true)
      setStatusMessage(res.message || 'Thanks for your feedback.')
    } catch (err: unknown) {
      const axiosError = err as { response?: { data?: { message?: string } } }
      setErrorMessage(axiosError.response?.data?.message || 'Failed to submit feedback.')
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <div data-testid="feedback-section" className="mt-3 border-t border-gray-100 pt-2">
      <div className="flex items-center gap-2">
        <span className="text-xs text-gray-500">Was this helpful?</span>
        <button
          type="button"
          title="Useful"
          aria-label="Useful"
          disabled={isSubmitting}
          onClick={() => handleRatingClick('useful')}
          className={`rounded p-1 text-gray-400 hover:text-gray-700 transition-colors ${rating === 'useful' ? 'bg-green-100 text-green-700 font-bold' : ''}`}
        >
          <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M14 10h4.764a2 2 0 011.789 2.894l-3.5 7A2 2 0 0115.263 21h-4.017c-.163 0-.326-.02-.485-.06L7 20m7-10V5a2 2 0 00-2-2h-.095c-.5 0-.905.405-.905.905 0 .714-.211 1.412-.608 2.006L7 11v9m7-10h-2M7 20H5a2 2 0 01-2-2v-6a2 2 0 012-2h2.5" />
          </svg>
        </button>
        <button
          type="button"
          title="Not useful"
          aria-label="Not useful"
          disabled={isSubmitting}
          onClick={() => handleRatingClick('not_useful')}
          className={`rounded p-1 text-gray-400 hover:text-gray-700 transition-colors ${rating === 'not_useful' ? 'bg-red-100 text-red-700 font-bold' : ''}`}
        >
          <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M10 14H5.236a2 2 0 01-1.789-2.894l3.5-7A2 2 0 018.736 3h4.018c.163 0 .326.02.485.06L17 4m-7 10v5a2 2 0 002 2h.095c.5 0 .905-.405.905-.905 0-.714.211-1.412.608-2.006L17 13V4m-7 10h2m5-10h2a2 2 0 012 2v6a2 2 0 01-2 2h-2.5" />
          </svg>
        </button>
        {statusMessage && (
          <span data-testid="feedback-status" className="text-xs text-green-700 font-medium ml-2">
            {statusMessage}
          </span>
        )}
      </div>

      {rating === 'not_useful' && !submitted && (
        <div className="mt-2 space-y-2">
          <textarea
            aria-label="Tell us what was wrong (optional)"
            placeholder="Tell us what was wrong (optional)"
            maxLength={500}
            rows={2}
            value={comment}
            onChange={(e) => setComment(e.target.value)}
            className="w-full text-xs rounded border border-gray-300 p-2 focus:ring-1 focus:ring-blue-500 focus:outline-none"
          />
          <div className="flex items-center justify-between">
            <span className="text-[10px] text-gray-400">{comment.length}/500</span>
            <button
              type="button"
              disabled={isSubmitting}
              onClick={handleSendFeedback}
              className="rounded bg-blue-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-blue-700 disabled:opacity-50"
            >
              Send feedback
            </button>
          </div>
        </div>
      )}

      {errorMessage && (
        <p data-testid="feedback-error" className="mt-1 text-xs text-red-600">
          {errorMessage}
        </p>
      )}
    </div>
  )
}

function AnswerBubble({ record, onSelectCitation }: { record: AiQuestion; onSelectCitation?: (citation: Citation) => void }) {
  return (
    <div data-testid="answer-bubble">
      <div className="prose prose-sm max-w-none text-sm leading-relaxed [&_ol]:list-decimal [&_ol]:pl-5 [&_p]:mb-2 [&_ul]:list-disc [&_ul]:pl-5">
        <ReactMarkdown>{record.answer}</ReactMarkdown>
      </div>
      {record.citations.length > 0 && (
        <ol aria-label="Sources" className="mt-3 space-y-1 border-t border-gray-200 pt-2 text-xs text-gray-700">
          {record.citations.map((citation, index) => (
            <li key={`${citation.chunk_id ?? index}-${index}`}>
              <button
                type="button"
                onClick={() => onSelectCitation?.(citation)}
                className="text-left font-medium text-indigo-600 hover:text-indigo-900 hover:underline"
              >
                <span className="font-semibold">[{index + 1}]</span> {citationLabel(citation)}
              </button>
            </li>
          ))}
        </ol>
      )}
      {record.disclaimer && <p className="mt-2 text-xs text-gray-500">{record.disclaimer}</p>}
      <FeedbackWidget questionId={record.id} initialFeedback={record.feedback} />
    </div>
  )
}

export default function AssistantPage() {
  const queryClient = useQueryClient()
  const [messages, setMessages] = useState<ChatMessage[]>([])
  const [draft, setDraft] = useState('')
  const [selectedCitation, setSelectedCitation] = useState<Citation | null>(null)
  const textarea = useRef<HTMLTextAreaElement | null>(null)
  const listEnd = useRef<HTMLDivElement | null>(null)

  const history = useQuery({ queryKey: ['assistant', 'questions'], queryFn: fetchQuestionHistory })

  const mutation = useMutation({
    mutationFn: ({ question }: { question: string; loadingId: number }) => askQuestion(question),
    onSuccess: (record, { loadingId }) => {
      setMessages((current) =>
        current.map((m) => (m.id === loadingId ? { id: loadingId, role: 'assistant', kind: 'answer', record } : m)),
      )
      void queryClient.invalidateQueries({ queryKey: ['assistant', 'questions'] })
    },
    onError: (error, { question, loadingId }) => {
      setMessages((current) =>
        current.map((m) =>
          m.id === loadingId
            ? { id: loadingId, role: 'assistant', kind: 'error', message: messageForError(error), retryQuestion: question }
            : m,
        ),
      )
    },
  })

  const waiting = mutation.isPending

  useEffect(() => {
    listEnd.current?.scrollIntoView?.({ behavior: 'smooth' })
  }, [messages])

  useEffect(() => {
    if (!waiting) textarea.current?.focus()
  }, [waiting])

  const send = (question: string) => {
    const loadingId = newId()
    setMessages((current) => [
      ...current,
      { id: newId(), role: 'user', text: question },
      { id: loadingId, role: 'assistant', kind: 'loading' },
    ])
    mutation.mutate({ question, loadingId })
  }

  const submit = () => {
    if (waiting) return
    const error = validateQuestion(draft)
    if (error) {
      // Validation errors are shown in the chat; "Try again" returns to the textarea.
      setMessages((current) => [...current, { id: newId(), role: 'assistant', kind: 'error', message: error, retryQuestion: null }])
      return
    }
    const question = draft.trim()
    setDraft('')
    send(question)
  }

  const retry = (message: Extract<ChatMessage, { kind: 'error' }>) => {
    setMessages((current) => current.filter((m) => m.id !== message.id))
    if (message.retryQuestion) {
      // Remove the user bubble of the failed attempt; send() adds it again.
      setMessages((current) => {
        const users = current.map((m, i) => (m.role === 'user' && m.text === message.retryQuestion ? i : -1)).filter((i) => i !== -1)
        const index = users.length > 0 ? users[users.length - 1] : -1
        return index === -1 ? current : current.filter((_, i) => i !== index)
      })
      send(message.retryQuestion)
    } else {
      textarea.current?.focus()
    }
  }

  const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
    // Enter sends; Shift+Enter keeps the default behavior (new line).
    if (event.key === 'Enter' && !event.shiftKey && !event.nativeEvent.isComposing) {
      event.preventDefault()
      submit()
    }
  }

  const onSubmit = (event: FormEvent) => {
    event.preventDefault()
    submit()
  }

  const tooLong = draft.length > MAX_QUESTION_LENGTH

  return (
    <main className="mx-auto flex h-screen max-w-6xl gap-6 p-4 sm:p-6">
      <section className="flex min-w-0 flex-1 flex-col rounded-lg border border-gray-200 bg-white">
        <header className="border-b border-gray-200 px-4 py-3">
          <h1 className="text-lg font-bold">AI assistant</h1>
        </header>

        <div role="log" aria-label="Conversation" className="flex-1 space-y-4 overflow-y-auto bg-gray-50 p-4">
          {messages.map((message) =>
            message.role === 'user' ? (
              <div key={message.id} className="flex justify-end">
                <div data-testid="user-bubble" className="max-w-[80%] whitespace-pre-wrap rounded-2xl rounded-br-sm bg-blue-700 px-4 py-2 text-sm text-white">
                  {message.text}
                </div>
              </div>
            ) : message.kind === 'error' ? (
              <div key={message.id} className="flex justify-start">
                <div
                  data-testid="error-bubble"
                  role="alert"
                  className="max-w-[80%] rounded-2xl rounded-bl-sm border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700"
                >
                  <p>{message.message}</p>
                  <button
                    type="button"
                    onClick={() => retry(message)}
                    className="mt-2 rounded-md border border-red-400 px-3 py-1 text-xs font-semibold text-red-700 hover:bg-red-100"
                  >
                    {T.tryAgain}
                  </button>
                </div>
              </div>
            ) : (
              <div key={message.id} className="flex justify-start">
                <div className="max-w-[80%] rounded-2xl rounded-bl-sm border border-gray-200 bg-white px-4 py-3 shadow-sm">
                  {message.kind === 'loading' ? <LoadingBubble /> : <AnswerBubble record={message.record} onSelectCitation={setSelectedCitation} />}
                </div>
              </div>
            ),
          )}
          <div ref={listEnd} />
        </div>

        <form onSubmit={onSubmit} className="border-t border-gray-200 p-3">
          <label htmlFor="assistant-question" className="sr-only">
            Question
          </label>
          <textarea
            id="assistant-question"
            ref={textarea}
            rows={3}
            value={draft}
            disabled={waiting}
            placeholder={T.placeholder}
            onChange={(event) => setDraft(event.target.value)}
            onKeyDown={onKeyDown}
            className="block w-full resize-none rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-600 disabled:bg-gray-100"
          />
          <div className="mt-2 flex items-center justify-between">
            <span data-testid="char-counter" className={`text-xs ${tooLong ? 'font-semibold text-red-600' : 'text-gray-500'}`}>
              {draft.length}/{MAX_QUESTION_LENGTH}
            </span>
            <button
              type="submit"
              disabled={waiting}
              className="rounded-md bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800 disabled:opacity-50"
            >
              {T.send}
            </button>
          </div>
        </form>
      </section>

      <aside className="hidden w-72 shrink-0 overflow-y-auto rounded-lg border border-gray-200 bg-white p-4 lg:block">
        <h2 className="text-sm font-semibold">Previous questions</h2>
        <ul className="mt-3 space-y-2" aria-label="Previous questions">
          {(history.data ?? []).map((item) => (
            <li key={item.id}>
              <details className="rounded-md border border-gray-200 p-2 text-xs">
                <summary className="cursor-pointer font-medium">{item.question}</summary>
                <p className="mt-2 whitespace-pre-wrap text-gray-700">{item.answer || '—'}</p>
              </details>
            </li>
          ))}
        </ul>
      </aside>

      <CitationDrawer citation={selectedCitation} onClose={() => setSelectedCitation(null)} />
    </main>
  )
}
