import { useState } from 'react'
import { Link } from 'react-router-dom'
import { api, messageOf } from '../api.js'
import BrandLogo from '../components/BrandLogo.jsx'
import { Alert, ErrorList } from '../components/UI.jsx'

export default function ForgotPassword() {
  const [email, setEmail] = useState('')
  const [errors, setErrors] = useState([])
  const [message, setMessage] = useState('')
  const [resetLink, setResetLink] = useState('')
  const [busy, setBusy] = useState(false)

  async function handleSubmit(event) {
    event.preventDefault()
    setErrors([])
    setMessage('')
    setResetLink('')
    setBusy(true)
    try {
      const data = await api('forgot-password', { method: 'POST', body: { email } })
      setMessage(data.message)
      if (data.reset_link) setResetLink(data.reset_link)
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
        <h1>Reset Your Password</h1>
        <p className="subtitle">Enter your institutional email to receive a reset link.</p>
        <ErrorList errors={errors} />
        {message && <Alert tone="success">{message}</Alert>}
        {resetLink && (
          <Alert tone="info">
            <a href={resetLink}>Open your password reset link</a>
          </Alert>
        )}

        <form onSubmit={handleSubmit} noValidate>
          <label className="field">
            <span>Institutional Email Address</span>
            <input type="email" value={email} onChange={(event) => setEmail(event.target.value)} placeholder="name@school.edu.ph" required />
          </label>
          <button type="submit" className="btn-primary" disabled={busy}>{busy ? 'Sending…' : 'Send Reset Link'}</button>
        </form>
        <p className="note"><Link to="/login" className="link">Back to login</Link></p>
      </section>
    </main>
  )
}
