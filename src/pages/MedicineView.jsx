import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api, messageOf } from '../api.js'
import Icon from '../components/Icons.jsx'
import { Alert, Badge, Empty, Loading, Panel } from '../components/UI.jsx'
import { formatDate, formatNumber } from '../constants.js'

export default function MedicineView() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [data, setData] = useState(null)
  const [error, setError] = useState('')

  useEffect(() => {
    let active = true
    api('medicine', { params: { id } })
      .then((payload) => { if (active) setData(payload) })
      .catch((caught) => { if (active) setError(messageOf(caught)) })
    return () => { active = false }
  }, [id])

  async function remove() {
    if (!window.confirm('Delete this medicine and all of its batches?')) return
    try {
      await api('medicine', { method: 'DELETE', body: { id } })
      navigate('/inventory')
    } catch (error) {
      setError(messageOf(error))
    }
  }

  if (error) return <Alert>{error}</Alert>
  if (!data) return <Loading label="Loading medicine…" />

  const medicine = data.medicine
  const tone = medicine.usable === 0 ? 'red' : medicine.usable <= medicine.low_stock_threshold ? 'yellow' : 'green'

  return (
    <div className="stack">
      <div className="page-heading">
        <div>
          <h2>{medicine.name}</h2>
          <p className="muted">{medicine.generic_name || 'No generic name recorded'} · {medicine.category}</p>
        </div>
        <div className="row-actions">
          <Link className="btn" to="/inventory"><Icon name="back" />Back</Link>
          <Link className="btn primary" to={`/inventory/${id}/edit`}><Icon name="edit" />Edit</Link>
          <button type="button" className="btn danger" onClick={remove}><Icon name="trash" />Delete</button>
        </div>
      </div>

      <div className="stat-grid compact">
        <div className="stat-card"><span className="stat-label">Usable Stock</span><span className="stat-value">{formatNumber(medicine.usable)} {medicine.unit}</span></div>
        <div className="stat-card"><span className="stat-label">Total Units</span><span className="stat-value">{formatNumber(medicine.total_quantity)}</span></div>
        <div className="stat-card"><span className="stat-label">Batches</span><span className="stat-value">{medicine.batch_count}</span></div>
        <div className="stat-card"><span className="stat-label">Status</span><span className="stat-value"><Badge tone={tone}>{medicine.usable === 0 ? 'Out of stock' : medicine.usable <= medicine.low_stock_threshold ? 'Low stock' : 'In stock'}</Badge></span></div>
      </div>

      <Panel title="Medicine Details">
        <dl className="detail-list">
          <div><dt>Category</dt><dd>{medicine.category}</dd></div>
          <div><dt>Unit</dt><dd>{medicine.unit}</dd></div>
          <div><dt>Location</dt><dd>{medicine.location || '—'}</dd></div>
          <div><dt>Low-Stock Threshold</dt><dd>{formatNumber(medicine.low_stock_threshold)}</dd></div>
          <div><dt>Description</dt><dd>{medicine.description || '—'}</dd></div>
        </dl>
      </Panel>

      <Panel title="Batches" action={<Link className="btn small" to="/stock-in"><Icon name="plus" />Add Stock</Link>}>
        <div className="table-wrap">
          <table className="data">
            <thead><tr><th>Batch No.</th><th>Quantity</th><th>Received</th><th>Expiration</th><th>Supplier</th><th>Remarks</th></tr></thead>
            <tbody>
              {data.batches.length === 0 ? <tr><td colSpan="6"><Empty>No batches recorded.</Empty></td></tr> : data.batches.map((batch) => (
                <tr key={batch.id}>
                  <td className="strong">{batch.batch_no}</td>
                  <td>{formatNumber(batch.quantity)}</td>
                  <td>{formatDate(batch.received_date)}</td>
                  <td>{formatDate(batch.expiration_date)}</td>
                  <td>{batch.supplier || '—'}</td>
                  <td>{batch.remarks || '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Panel>
    </div>
  )
}
