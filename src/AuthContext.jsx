import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import { api, setCsrf } from './api.js'

const AuthContext = createContext(null)

export function AuthProvider({ children }) {
  const [session, setSession] = useState({ authenticated: false, csrf_token: '', user: null, unread_count: 0 })
  const [loading, setLoading] = useState(true)

  const refresh = useCallback(async () => {
    try {
      const data = await api('session')
      setCsrf(data.csrf_token)
      setSession(data)
      return data
    } catch {
      const fallback = { authenticated: false, csrf_token: '', user: null, unread_count: 0 }
      setSession(fallback)
      return fallback
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    refresh()
  }, [refresh])

  const setUnread = useCallback((count) => {
    setSession((current) => ({ ...current, unread_count: count }))
  }, [])

  const value = useMemo(
    () => ({ ...session, loading, refresh, setSession, setUnread }),
    [session, loading, refresh, setUnread],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth() {
  return useContext(AuthContext)
}
