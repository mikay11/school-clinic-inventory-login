import { useEffect, useState } from 'react'
import { api, messageOf } from '../api.js'
import { useAuth } from '../AuthContext.jsx'
import { Alert, ErrorList, Field, Loading, Panel } from '../components/UI.jsx'

const emptyForm = {
  full_name: '', email: '', new_password: '', confirm_password: '',
  clinic_name: '', clinic_address: '', clinic_contact: '',
  low_stock_threshold: '20', expiry_window: '30',
  notify_low_stock: true, notify_expiring: true, notify_expired: true, notify_summary: true,
  items_per_page: '10', date_format: 'M j, Y', landing_page: '/dashboard',
}

export default function Settings() {
  const { refresh } = useAuth()
  const [form, setForm] = useState(emptyForm)
  const [loading, setLoading] = useState(true)
  const [errors, setErrors] = useState([])
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    let active = true
    api('settings')
      .then((data) => {
        if (!active) return
        setForm({
          ...emptyForm,
          full_name: data.user?.full_name || '',
          email: data.user?.email || '',
          ...data.settings,
          notify_low_stock: data.settings.notify_low_stock !== '0',
          notify_expiring: data.settings.notify_expiring !== '0',
          notify_expired: data.settings.notify_expired !== '0',
          notify_summary: data.settings.notify_summary !== '0',
        })
      })
      .catch((error) => setErrors([messageOf(error)]))
      .finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [])

  function update(key, value) {
    setForm((current) => ({ ...current, [key]: value }))
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setErrors([])
    setNotice('')
    setBusy(true)
    try {
      const data = await api('settings', { method: 'POST', body: form })
      setNotice(data.message)
      setForm((current) => ({ ...current, new_password: '', confirm_password: '' }))
      await refresh()
    } catch (error) {
      setErrors(error.payload?.errors || [messageOf(error)])
    } finally {
      setBusy(false)
    }
  }

  if (loading) return <Loading label="Loading settings…" />

  return (
    <form className="stack" onSubmit={handleSubmit}>
      <div className="page-heading">
        <div><h2>Settings</h2><p className="muted">Manage your account and clinic preferences.</p></div>
        <button type="submit" className="btn primary" disabled={busy}>{busy ? 'Saving…' : 'Save Changes'}</button>
      </div>
      {notice && <Alert tone="success">{notice}</Alert>}
      <ErrorList errors={errors} />

      <Panel title="Account">
        <div className="form-grid">
          <Field label="Full Name"><input value={form.full_name} onChange={(event) => update('full_name', event.target.value)} /></Field>
          <Field label="Institutional Email"><input type="email" value={form.email} onChange={(event) => update('email', event.target.value)} /></Field>
          <Field label="New Password" hint="Leave blank to keep your current password."><input type="password" value={form.new_password} onChange={(event) => update('new_password', event.target.value)} autoComplete="new-password" /></Field>
          <Field label="Confirm New Password"><input type="password" value={form.confirm_password} onChange={(event) => update('confirm_password', event.target.value)} autoComplete="new-password" /></Field>
        </div>
      </Panel>

      <Panel title="Clinic Information">
        <div className="form-grid">
          <Field label="Clinic Name"><input value={form.clinic_name} onChange={(event) => update('clinic_name', event.target.value)} /></Field>
          <Field label="Contact"><input value={form.clinic_contact} onChange={(event) => update('clinic_contact', event.target.value)} /></Field>
          <Field label="Address"><input value={form.clinic_address} onChange={(event) => update('clinic_address', event.target.value)} /></Field>
        </div>
      </Panel>

      <Panel title="Notification Preferences">
        <div className="checkbox-grid">
          {[
            ['notify_low_stock', 'Low-stock alerts'],
            ['notify_expiring', 'Expiration warnings'],
            ['notify_expired', 'Expired batch alerts'],
            ['notify_summary', 'Daily summary'],
          ].map(([key, label]) => (
            <label className="checkbox" key={key}>
              <input type="checkbox" checked={Boolean(form[key])} onChange={(event) => update(key, event.target.checked)} />
              <span>{label}</span>
            </label>
          ))}
        </div>
        <div className="form-grid">
          <Field label="Low-Stock Threshold"><input type="number" min="0" value={form.low_stock_threshold} onChange={(event) => update('low_stock_threshold', event.target.value)} /></Field>
          <Field label="Expiry Window (days)"><input type="number" min="1" value={form.expiry_window} onChange={(event) => update('expiry_window', event.target.value)} /></Field>
        </div>
      </Panel>

      <Panel title="Display Preferences">
        <div className="form-grid">
          <Field label="Items Per Page"><input type="number" min="1" max="100" value={form.items_per_page} onChange={(event) => update('items_per_page', event.target.value)} /></Field>
          <Field label="Date Format"><input value={form.date_format} onChange={(event) => update('date_format', event.target.value)} /></Field>
          <Field label="Landing Page"><select value={form.landing_page} onChange={(event) => update('landing_page', event.target.value)}><option value="/dashboard">Dashboard</option><option value="/inventory">Inventory</option></select></Field>
        </div>
      </Panel>
    </form>
  )
}
