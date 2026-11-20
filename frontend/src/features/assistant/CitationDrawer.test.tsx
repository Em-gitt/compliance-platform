import { render, screen, fireEvent, waitFor, cleanup } from '@testing-library/react'
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import CitationDrawer from './CitationDrawer'
import { api } from '../../lib/api'
import type { Citation } from './api'

vi.mock('../../lib/api', () => ({
  api: {
    get: vi.fn(),
  },
}))

describe('CitationDrawer (CMP-34)', () => {
  let queryClient: QueryClient

  beforeEach(() => {
    vi.clearAllMocks()
    queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    })
  })

  afterEach(() => {
    cleanup()
  })

  const sampleCitation: Citation = {
    document_id: 10,
    document_name: 'Environmental Permit 2026.pdf',
    version_label: 'v2.0',
    chunk_id: 101,
    page: 4,
    page_start: 4,
    page_end: 4,
    section: null,
  }

  it('AC #1: Selecting a citation opens the drawer with the highlighted fragment and correct page/section', async () => {
    vi.mocked(api.get).mockResolvedValueOnce({
      data: {
        id: 101,
        document_id: 10,
        document_name: 'Environmental Permit 2026.pdf',
        category: 'permit_license',
        access_level: 'all_staff',
        version_label: 'v2.0',
        version_id: 5,
        page_start: 4,
        page_end: 4,
        section: null,
        content: 'This facility is authorized to discharge treated wastewater under standard limits.',
        mime_type: 'application/pdf',
      },
    } as unknown as ReturnType<typeof api.get>)

    render(
      <QueryClientProvider client={queryClient}>
        <CitationDrawer citation={sampleCitation} onClose={vi.fn()} />
      </QueryClientProvider>
    )

    await waitFor(() => {
      expect(screen.getByTestId('citation-location')).toHaveTextContent('page 4')
    })

    expect(screen.getByTestId('highlighted-fragment')).toHaveTextContent(
      'This facility is authorized to discharge treated wastewater under standard limits.'
    )
    expect(screen.getByRole('button', { name: 'Open document' })).toBeInTheDocument()

    // Test clicking Open Document opens modal
    fireEvent.click(screen.getByRole('button', { name: 'Open document' }))
    expect(screen.getByTestId('document-viewer-modal')).toBeInTheDocument()
  })

  it('displays exact 403 error message when user lacks permission to view the source', async () => {
    vi.mocked(api.get).mockRejectedValueOnce({
      isAxiosError: true,
      response: {
        status: 403,
        data: { message: 'You do not have permission to view this source.' },
      },
    })

    render(
      <QueryClientProvider client={queryClient}>
        <CitationDrawer citation={sampleCitation} onClose={vi.fn()} />
      </QueryClientProvider>
    )

    await waitFor(() => {
      expect(
        screen.getByText('You do not have permission to view this source.')
      ).toBeInTheDocument()
    })

    expect(screen.queryByTestId('highlighted-fragment')).not.toBeInTheDocument()
  })

  it('displays exact 404 error message when chunk or document no longer exists', async () => {
    vi.mocked(api.get).mockRejectedValueOnce({
      isAxiosError: true,
      response: {
        status: 404,
        data: { message: 'This source is no longer available.' },
      },
    })

    render(
      <QueryClientProvider client={queryClient}>
        <CitationDrawer citation={sampleCitation} onClose={vi.fn()} />
      </QueryClientProvider>
    )

    await waitFor(() => {
      expect(
        screen.getByText('This source is no longer available.')
      ).toBeInTheDocument()
    })
  })
})
