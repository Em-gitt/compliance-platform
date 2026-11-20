import { useEffect, useRef, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import { api } from '../lib/api'

export const WARNING_MS = 28 * 60 * 1000 // 28 minutes
export const TIMEOUT_MS = 30 * 60 * 1000 // 30 minutes

export default function IdleTimeoutModal() {
  const { user, setUser } = useAuth()
  const [showModal, setShowModal] = useState(false)
  const showModalRef = useRef(false)

  useEffect(() => {
    showModalRef.current = showModal
  }, [showModal])

  useEffect(() => {
    if (!user) {
      setShowModal(false)
      return
    }

    let warningTimer: ReturnType<typeof setTimeout>
    let expireTimer: ReturnType<typeof setTimeout>

    const resetTimers = () => {
      clearTimeout(warningTimer)
      clearTimeout(expireTimer)
      setShowModal(false)

      warningTimer = setTimeout(() => {
        setShowModal(true)
      }, WARNING_MS)

      expireTimer = setTimeout(() => {
        setUser(null)
        window.location.assign('/login?reason=timeout')
      }, TIMEOUT_MS)
    }

    const onUserActivity = () => {
      if (!showModalRef.current) {
        resetTimers()
      }
    }

    const events = ['mousemove', 'keydown', 'click', 'scroll', 'touchstart']
    events.forEach((event) => window.addEventListener(event, onUserActivity))

    resetTimers()

    return () => {
      clearTimeout(warningTimer)
      clearTimeout(expireTimer)
      events.forEach((event) => window.removeEventListener(event, onUserActivity))
    }
  }, [user?.id, setUser])

  const handleStaySignedIn = async () => {
    try {
      await api.get('/api/auth/me')
      setShowModal(false)
    } catch {
      window.location.assign('/login?reason=timeout')
    }
  }

  const handleSignOut = async () => {
    try {
      await api.post('/api/auth/logout')
    } finally {
      setUser(null)
      window.location.assign('/login')
    }
  }

  if (!showModal) return null

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby="idle-modal-title"
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
    >
      <div className="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
        <h2 id="idle-modal-title" className="text-lg font-bold">
          Are you still there?
        </h2>
        <p className="mt-2 text-gray-600">
          Your session will expire in 2 minutes due to inactivity.
        </p>
        <div className="mt-6 flex justify-end gap-3">
          <button
            type="button"
            onClick={handleSignOut}
            className="rounded px-4 py-2 border border-gray-300 text-gray-700 hover:bg-gray-100"
          >
            Sign out
          </button>
          <button
            type="button"
            onClick={handleStaySignedIn}
            className="rounded px-4 py-2 bg-blue-600 text-white hover:bg-blue-700"
          >
            Stay signed in
          </button>
        </div>
      </div>
    </div>
  )
}
