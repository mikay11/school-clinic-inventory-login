import { useEffect, useState } from 'react'
import { api, messageOf } from '../api.js'
import { Alert, Badge, Empty, Loading, Panel } from '../components/UI.jsx'
import { formatDate, formatNumber } from '../constants.js'

export default function Expiration() {
  const [status, setStatus] = useState('all')
  const [search, setSearch] = useState('')
  const [data, setData] = useState(null)
  const [error, setError] = useState('')

  useEffect(() => {
    let active = true
    setData(null)
    api('expiration', { params: { status, search } })
      .then((payload) => { if (active) setData(payload) })
      .catch((caught) => { if (active) setError(messageOf(caught)) })
    return () => { active = false }
  }, [status, search])

  if (error) return <Alert>{error}</Alert>
  if (!data) return <Loading label="Loading expiration data…" />

  const { summary, rows } = data
  const badgeFor = (days) => days < 0 ? 'red' : days <= 30 ? 'yellow' : 'green'

  return (
    <div className="stack">
      <div className="page-heading">
        <div><h2>Expiration Monitoring</h2><p className="muted">Track expired, expiring, and valid medicine batches.</p></div>
      </div>

      <section className="stat-grid compact">
        <div className="stat-card"><span className="stat-label">Expired</span><span className="stat-value">{summary.expired}</span></div>
        <div className="stat-card"><span className="stat-label">Expiring Soon</span><span className="stat-value">{summary.soon}</span></div>
        <div className="stat-card"><span className="stat-label">Valid</span><span className="stat-value">{summary.valid}</span></div>
        <div className="stat-card"><span className="stat-label">Units At Risk</span><span className="stat-value">{formatNumber(summary.units_at_risk)}</span></div>
      </section>

      <div className="filter-bar">
        <label className="search-box">
          <span className="search-icon">⌕</span>
          <input type="search" placeholder="Search medicine or batch" value={search} onChange={(event) => setSearch(event.target.value)} />
        </label>
        <select value={status} onChange={(event) => setStatus(event.target.value)} aria-label="Filter by expiration status">
          <option value="all">All batches</option>
          <option value="expired">Expired</option>
          <option value="soon">Expiring soon</option>
          <option value="valid">Valid</option>
        </select>
      </div>

      <Panel title="Batch Expiration List" action={<span className="badge teal">{rows.length} shown</span>}>
        <div className="table-wrap">
          <table className="data">
            <thead><tr><th>Medicine</th><th>Batch</th><th>Quantity</th><th>Expiry</th><th>Days Left</th><th>Status</th></tr></thead>
            <tbody>
              {rows.length === 0 ? <tr><td colSpan="6"><Empty>No batches match this filter.</Empty></td></tr> : rows.map((row) => (
                <tr key={`${row.name}-${row.batch_no}-${row.expiration_date}`}>
                  <td className="strong">{row.name}</td>
                  <td>{row.batch_no}</td>
                  <td>{formatNumber(row.quantity)} {row.unit}</td>
                  <td>{formatDate(row.expiration_date)}</td>
                  <td>{row.days_left}</td>
                  <td><Badge tone={badgeFor(Number(row.days_left))}>{Number(row.days_left) < 0 ? 'Expired' : Number(row.days_left) <= 30 ? 'Expiring soon' : 'Valid'}</Badge></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Panel>
    </div>
  )
}
