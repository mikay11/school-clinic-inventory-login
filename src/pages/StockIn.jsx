import { useEffect, useState } from 'react'
import { api, messageOf } from '../api.js'
import { Alert, ErrorList, Field, Loading, Panel } from '../components/UI.jsx'
import { formatDate, formatNumber } from '../constants.js'

const today = new Date().toISOString().slice(0, 10)

export default function StockIn() {
  const [options, setOptions] = useState(null)
  const [form, setForm] = useState({
    medicine_id: '', batch_no: '', quantity: '', received_date: today,
    expiration_date: '', supplier: '', remarks: '',
  })
  const [errors, setErrors] = useState([])
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    let active = true
    api('stock-options')
      .then((data) => { if (active) setOptions(data) })
      .catch((error) => { if (active) setErrors([messageOf(error)]) })
    return () => { active = false }
  }, [])

  function update(key, value) {
    setForm((current) => ({ ...current, [key]: value, ...(key === 'medicine_id' ? { batch_no: '' } : {}) }))
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setErrors([])
    setNotice('')
    setBusy(true)
    try {
      const data = await api('stock-in', { method: 'POST', body: form })
      setNotice(data.message)
      setForm((current) => ({ ...current, batch_no: '', quantity: '', remarks: '' }))
      const refreshed = await api('stock-options')
      setOptions(refreshed)
    } catch (error) {
      setErrors(error.payload?.errors || [messageOf(error)])
    } finally {
      setBusy(false)
    }
  }

  if (!options) return <Loading label="Loading stock-in form…" />

  const selectedMedicine = options.medicines.find((item) => String(item.id) === String(form.medicine_id))
  const batches = options.batches.filter((batch) => String(batch.medicine_id) === String(form.medicine_id))

  return (
    <div className="stack">
      <div className="page-heading">
        <div><h2>Stock In</h2><p className="muted">Record medicines received into the clinic inventory.</p></div>
      </div>
      {notice && <Alert tone="success">{notice}</Alert>}
      <ErrorList errors={errors} />

      <form className="panel form-grid" onSubmit={handleSubmit}>
        <Field label="Medicine">
          <select value={form.medicine_id} onChange={(event) => update('medicine_id', event.target.value)} required>
            <option value="">Select a medicine</option>
            {options.medicines.map((medicine) => (
              <option key={medicine.id} value={medicine.id}>{medicine.name} ({medicine.unit})</option>
            ))}
          </select>
        </Field>
        <Field label="Batch Number" hint={selectedMedicine ? `Unit: ${selectedMedicine.unit}` : undefined}>
          <input list="stock-in-batches" value={form.batch_no} onChange={(event) => update('batch_no', event.target.value)} required />
          <datalist id="stock-in-batches">
            {batches.map((batch) => <option key={batch.id} value={batch.batch_no} />)}
          </datalist>
        </Field>
        <Field label="Quantity Received">
          <input type="number" min="1" value={form.quantity} onChange={(event) => update('quantity', event.target.value)} required />
        </Field>
        <Field label="Date Received">
          <input type="date" value={form.received_date} onChange={(event) => update('received_date', event.target.value)} required />
        </Field>
        <Field label="Expiration Date">
          <input type="date" value={form.expiration_date} onChange={(event) => update('expiration_date', event.target.value)} required />
        </Field>
        <Field label="Supplier">
          <input value={form.supplier} onChange={(event) => update('supplier', event.target.value)} />
        </Field>
        <Field label="Remarks">
          <input value={form.remarks} onChange={(event) => update('remarks', event.target.value)} maxLength="255" />
        </Field>
        <div className="form-actions">
          <button type="submit" className="btn primary" disabled={busy}>{busy ? 'Saving…' : 'Record Stock In'}</button>
        </div>
      </form>

      <Panel title="Recent Stock Movements">
        <div className="table-wrap">
          <table className="data">
            <thead><tr><th>Date</th><th>Type</th><th>Medicine</th><th>Quantity</th><th>Details</th><th>Recorded By</th></tr></thead>
            <tbody>
              {options.history.length === 0 ? <tr><td colSpan="6" className="empty">No movements recorded yet.</td></tr> : options.history.map((item, index) => (
                <tr key={`${item.created_at}-${index}`}>
                  <td>{formatDate(item.created_at)}</td>
                  <td>{item.activity_type === 'stock_in' ? 'Stock In' : 'Stock Out'}</td>
                  <td className="strong">{item.name || '—'}</td>
                  <td>{formatNumber(item.quantity)}</td>
                  <td>{item.note || '—'}</td>
                  <td>{item.performed_by}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Panel>
    </div>
  )
}
