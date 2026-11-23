import { useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { api, messageOf } from '../api.js'
import BrandLogo from '../components/BrandLogo.jsx'
import { Alert, ErrorList } from '../components/UI.jsx'

export default function ResetPassword() {
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const token = params.get('token') || ''
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [errors, setErrors] = useState([])
  const [busy, setBusy] = useState(false)

  async function handleSubmit(event) {
    event.preventDefault()
    setErrors([])
    setBusy(true)
    try {
      await api('reset-password', { method: 'POST', body: { token, password, confirm_password: confirm } })
      navigate('/login?reset=1', { replace: true })
    } catch (error) {
      setErrors(error.payload?.errors || [messageOf(error)])
    } finally {
      setBusy(false)
    }
  }

  return (
    <main className="page">
      <section className="login-card">
        <div className="logo-wrap"><BrandLogo className="logo-img" /></div>
        <h1>Choose a New Password</h1>
        <p className="subtitle">Use at least 8 characters for your new password.</p>
        {!token && <Alert>This reset link is missing its token.</Alert>}
        <ErrorList errors={errors} />

        <form onSubmit={handleSubmit} noValidate>
          <label className="field">
            <span>New Password</span>
            <input type="password" value={password} onChange={(event) => setPassword(event.target.value)} autoComplete="new-password" required />
          </label>
          <label className="field">
            <span>Confirm New Password</span>
            <input type="password" value={confirm} onChange={(event) => setConfirm(event.target.value)} autoComplete="new-password" required />
          </label>
          <button type="submit" className="btn-primary" disabled={busy || !token}>{busy ? 'Saving…' : 'Update Password'}</button>
        </form>
        <p className="note"><Link to="/login" className="link">Back to login</Link></p>
      </section>
    </main>
  )
}
