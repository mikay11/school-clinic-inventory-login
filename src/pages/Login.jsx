import { useState } from 'react'
import { Link, Navigate, useNavigate, useSearchParams } from 'react-router-dom'
import { api, messageOf } from '../api.js'
import { useAuth } from '../AuthContext.jsx'
import BrandLogo from '../components/BrandLogo.jsx'
import Icon from '../components/Icons.jsx'
import { Alert, ErrorList } from '../components/UI.jsx'

export default function Login() {
  const { authenticated, loading, refresh } = useAuth()
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [remember, setRemember] = useState(false)
  const [showPassword, setShowPassword] = useState(false)
  const [errors, setErrors] = useState([])
  const [busy, setBusy] = useState(false)

  if (!loading && authenticated) {
    return <Navigate to="/dashboard" replace />
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setErrors([])
    setBusy(true)
    try {
      await api('login', { method: 'POST', body: { email, password, remember_me: remember } })
      await refresh()
      navigate('/dashboard', { replace: true })
    } catch (error) {
      setErrors(error.payload?.errors || [messageOf(error)])
    } finally {
      setBusy(false)
    }
  }

  return (
    <main className="page">
      <section className="login-card">
        <div className="logo-wrap">
          <BrandLogo className="logo-img" />
        </div>
        <h1>School Clinic Inventory System</h1>
        <p className="subtitle">Medicine Inventory and Expiration Monitoring System</p>

        {params.has('timeout') && <Alert tone="info">Your session expired after a period of inactivity. Please log in again.</Alert>}
        {params.has('reset') && <Alert tone="success">Your password has been updated. Please log in with your new password.</Alert>}
        <ErrorList errors={errors} />

        <form onSubmit={handleSubmit} noValidate>
          <label className="field">
            <span>Institutional Email Address</span>
            <input
              type="email"
              value={email}
              onChange={(event) => setEmail(event.target.value)}
              autoComplete="username"
              placeholder="name@school.edu.ph"
              pattern="^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.edu\.ph$"
              required
            />
          </label>

          <label className="field">
            <span>Password</span>
            <span className="password-wrap">
              <input
                type={showPassword ? 'text' : 'password'}
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                autoComplete="current-password"
                required
              />
              <button
                type="button"
                className="icon-btn"
                aria-label={showPassword ? 'Hide password' : 'Show password'}
                aria-pressed={showPassword}
                onClick={() => setShowPassword((visible) => !visible)}
              >
                <Icon name="eye" size={20} />
              </button>
            </span>
          </label>

          <div className="form-row">
            <label className="checkbox">
              <input type="checkbox" checked={remember} onChange={(event) => setRemember(event.target.checked)} />
              <span>Remember Me</span>
            </label>
            <Link to="/forgot-password" className="link">Forgot Password?</Link>
          </div>

          <button type="submit" className="btn-primary" disabled={busy}>{busy ? 'Signing in…' : 'Login'}</button>
        </form>

        <p className="note">Access is restricted to authorized school clinic personnel only. No public registration.</p>
      </section>
    </main>
  )
}
