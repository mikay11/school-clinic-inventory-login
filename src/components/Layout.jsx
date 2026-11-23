import { useEffect, useState } from 'react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { api, messageOf } from '../api.js'
import { useAuth } from '../AuthContext.jsx'
import { NAV_ITEMS } from '../constants.js'
import BrandLogo from './BrandLogo.jsx'
import Icon from './Icons.jsx'
import { Alert } from './UI.jsx'

export default function Layout() {
  const { user, unread_count: unread, refresh, setUnread } = useAuth()
  const [menuOpen, setMenuOpen] = useState(false)
  const [error, setError] = useState('')
  const location = useLocation()
  const navigate = useNavigate()

  useEffect(() => {
    document.body.classList.add('app')
    return () => document.body.classList.remove('app')
  }, [])

  useEffect(() => {
    setMenuOpen(false)
  }, [location.pathname])

  const active = NAV_ITEMS.find((item) => location.pathname.startsWith(item.to))
  const title = active?.label || 'Dashboard'
  const subtitle = active?.to === '/dashboard'
    ? `Welcome back, ${user?.full_name || 'Clinic Personnel'}`
    : 'School Clinic Inventory System'

  async function handleLogout() {
    setError('')
    try {
      await api('logout', { method: 'POST', body: {} })
      await refresh()
      navigate('/login', { replace: true })
    } catch (caught) {
      setError(messageOf(caught))
    }
  }

  return (
    <div className="app-shell">
      <div className={`sidebar-overlay ${menuOpen ? 'visible' : ''}`} onClick={() => setMenuOpen(false)} />
      <aside className={`sidebar ${menuOpen ? 'open' : ''}`}>
        <div className="brand">
          <BrandLogo />
          <div className="brand-text">
            <span className="brand-name">School Clinic</span>
            <span className="brand-sub">Inventory System</span>
          </div>
        </div>

        <nav className="nav" aria-label="Main navigation">
          {NAV_ITEMS.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              className={({ isActive }) => `nav-item ${isActive ? 'active' : ''}`}
            >
              <Icon name={item.icon} />
              <span>{item.label}</span>
              {item.to === '/notifications' && unread > 0 && <span className="nav-badge">{unread}</span>}
            </NavLink>
          ))}
        </nav>

        <nav className="nav nav-secondary" aria-label="Account">
          <button type="button" className="nav-item nav-button" onClick={handleLogout}>
            <Icon name="logout" /><span>Log Out</span>
          </button>
        </nav>
      </aside>

      <div className="app-body">
        <header className="topbar">
          <div className="topbar-left">
            <button
              type="button"
              className="menu-toggle"
              aria-label="Open menu"
              aria-expanded={menuOpen}
              onClick={() => setMenuOpen((open) => !open)}
            >
              <Icon name="menu" size={20} />
            </button>
            <div className="topbar-title">
              <h1>{title}</h1>
              <p className="welcome">{subtitle}</p>
            </div>
          </div>
          <div className="topbar-actions">
            <NavLink to="/notifications" className="bell" aria-label={`${unread} unread notifications`}>
              <Icon name="bell" size={20} />
              {unread > 0 && <span className="bell-count">{unread > 99 ? '99+' : unread}</span>}
            </NavLink>
            <div className="avatar" title={`${user?.full_name || ''} ${user?.email || ''}`.trim()}>
              {(user?.initials || 'CP')}
            </div>
          </div>
        </header>

        <main className="content">
          {error && <Alert>{error}</Alert>}
          <Outlet context={{ setUnread }} />
        </main>
      </div>
    </div>
  )
}
