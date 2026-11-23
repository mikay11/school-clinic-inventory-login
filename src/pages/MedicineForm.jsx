import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api, messageOf } from '../api.js'
import { ErrorList, Field } from '../components/UI.jsx'
import { CATEGORIES, GENERIC_NAMES, MEDICINE_NAMES, STOCK_LOCATIONS } from '../constants.js'

const emptyForm = {
  name: '', generic_name: '', category: 'Tablet', unit: 'tablet', location: '',
  description: '', low_stock_threshold: 20, initial_batch_no: '', initial_quantity: '',
  received_date: '', expiration_date: '', supplier: '', remarks: '',
}

export default function MedicineForm() {
  const { id } = useParams()
  const navigate = useNavigate()
  const isEdit = Boolean(id)
  const [form, setForm] = useState(emptyForm)
  const [errors, setErrors] = useState([])
  const [loading, setLoading] = useState(isEdit)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    if (!isEdit) return
    let active = true
    api('medicine', { params: { id } })
      .then((data) => {
        if (!active) return
        setForm({
          ...emptyForm,
          ...data.medicine,
          low_stock_threshold: String(data.medicine.low_stock_threshold),
          initial_batch_no: '', initial_quantity: '', received_date: '',
          expiration_date: '', supplier: '', remarks: '',
        })
      })
      .catch((error) => setErrors([messageOf(error)]))
      .finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [id, isEdit])

  function update(key, value) {
    setForm((current) => ({ ...current, [key]: value }))
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setErrors([])
    setBusy(true)
    try {
      if (isEdit) {
        await api('medicine', { method: 'PUT', body: { ...form, id } })
      } else {
        await api('medicine', { method: 'POST', body: form })
      }
      navigate('/inventory')
    } catch (error) {
      setErrors(error.payload?.errors || [messageOf(error)])
    } finally {
      setBusy(false)
    }
  }

  if (loading) return <div className="loading">Loading medicine…</div>

  return (
    <div className="stack">
      <div className="page-heading">
        <div>
          <h2>{isEdit ? 'Edit Medicine' : 'Add Medicine'}</h2>
          <p className="muted">{isEdit ? 'Update the details of this medicine.' : 'Register a new medicine in the clinic inventory.'}</p>
        </div>
        <Link className="btn" to="/inventory">Cancel</Link>
      </div>

      <ErrorList errors={errors} />

      <form className="panel form-grid" onSubmit={handleSubmit}>
        <Field label="Medicine Name">
          <input list="medicine-names" value={form.name} onChange={(event) => update('name', event.target.value)} required />
          <datalist id="medicine-names">
            {MEDICINE_NAMES.map((name) => <option key={name} value={name} />)}
          </datalist>
        </Field>
        <Field label="Generic Name">
          <input list="generic-names" value={form.generic_name} onChange={(event) => update('generic_name', event.target.value)} />
          <datalist id="generic-names">
            {GENERIC_NAMES.map((name) => <option key={name} value={name} />)}
          </datalist>
        </Field>
        <Field label="Category">
          <select value={form.category} onChange={(event) => update('category', event.target.value)}>
            {CATEGORIES.map((category) => <option key={category} value={category}>{category}</option>)}
          </select>
        </Field>
        <Field label="Unit">
          <input value={form.unit} onChange={(event) => update('unit', event.target.value)} placeholder="tablet, capsule, mL…" />
        </Field>
        <Field label="Storage Location">
          <input list="stock-locations" value={form.location} onChange={(event) => update('location', event.target.value)} />
          <datalist id="stock-locations">
            {STOCK_LOCATIONS.map((location) => <option key={location} value={location} />)}
          </datalist>
        </Field>
        <Field label="Low-Stock Threshold">
          <input type="number" min="0" value={form.low_stock_threshold} onChange={(event) => update('low_stock_threshold', event.target.value)} />
        </Field>
        <Field label="Description">
          <input value={form.description} onChange={(event) => update('description', event.target.value)} maxLength="255" />
        </Field>

        {!isEdit && (
          <>
            <div className="form-divider">Optional initial batch</div>
            <Field label="Batch Number">
              <input value={form.initial_batch_no} onChange={(event) => update('initial_batch_no', event.target.value)} />
            </Field>
            <Field label="Quantity">
              <input type="number" min="1" value={form.initial_quantity} onChange={(event) => update('initial_quantity', event.target.value)} />
            </Field>
            <Field label="Received Date">
              <input type="date" value={form.received_date} onChange={(event) => update('received_date', event.target.value)} />
            </Field>
            <Field label="Expiration Date">
              <input type="date" value={form.expiration_date} onChange={(event) => update('expiration_date', event.target.value)} />
            </Field>
            <Field label="Supplier">
              <input value={form.supplier} onChange={(event) => update('supplier', event.target.value)} />
            </Field>
            <Field label="Remarks">
              <input value={form.remarks} onChange={(event) => update('remarks', event.target.value)} maxLength="255" />
            </Field>
          </>
        )}

        <div className="form-actions">
          <button type="submit" className="btn primary" disabled={busy}>{busy ? 'Saving…' : isEdit ? 'Save Changes' : 'Add Medicine'}</button>
          <Link className="btn" to="/inventory">Cancel</Link>
        </div>
      </form>
    </div>
  )
}
