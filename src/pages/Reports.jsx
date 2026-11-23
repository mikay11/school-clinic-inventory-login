import { useEffect, useState } from 'react'
import { api, messageOf } from '../api.js'
import Icon from '../components/Icons.jsx'
import { Alert, Empty, Loading, Panel } from '../components/UI.jsx'
import { formatNumber } from '../constants.js'

function csvCell(value) {
  return `"${String(value ?? '').replaceAll('"', '""')}"`
}

export default function Reports() {
  const [type, setType] = useState('inventory')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [data, setData] = useState(null)
  const [error, setError] = useState('')

  useEffect(() => {
    let active = true
    setData(null)
    api('reports', { params: { report_type: type, date_from: from, date_to: to } })
      .then((payload) => { if (active) setData(payload) })
      .catch((caught) => { if (active) setError(messageOf(caught)) })
    return () => { active = false }
  }, [type, from, to])

  function downloadCsv() {
    if (!data) return
    const lines = [
      [data.title],
      [data.period_label],
      [],
      data.headers,
      ...data.rows,
    ]
    const csv = lines.map((line) => line.map(csvCell).join(',')).join('\r\n')
    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }))
    const link = document.createElement('a')
    link.href = url
    link.download = `clinic-report-${data.type}-${new Date().toISOString().slice(0, 10)}.csv`
    link.click()
    URL.revokeObjectURL(url)
  }

  if (error) return <Alert>{error}</Alert>

  return (
    <div className="stack">
      <div className="page-heading">
        <div><h2>Reports</h2><p className="muted">Generate inventory, movement, expiration, and low-stock reports.</p></div>
        <div className="row-actions">
          <button type="button" className="btn" onClick={downloadCsv} disabled={!data}><Icon name="download" />CSV</button>
          <button type="button" className="btn" onClick={() => window.print()} disabled={!data}><Icon name="print" />Print</button>
        </div>
      </div>

      <div className="filter-bar">
        <select value={type} onChange={(event) => setType(event.target.value)} aria-label="Report type">
          {data?.types && Object.entries(data.types).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
          {!data && <>
            <option value="inventory">Inventory Summary</option>
            <option value="movement">Stock Movement</option>
            <option value="expiration">Expiration Report</option>
            <option value="low-stock">Low Stock Report</option>
          </>}
        </select>
        <label className="date-filter">From <input type="date" value={from} onChange={(event) => setFrom(event.target.value)} /></label>
        <label className="date-filter">To <input type="date" value={to} onChange={(event) => setTo(event.target.value)} /></label>
      </div>

      {!data ? <Loading label="Generating report…" /> : (
        <>
          <section className="stat-grid compact">
            <div className="stat-card"><span className="stat-label">Medicines</span><span className="stat-value">{formatNumber(data.summary.medicines)}</span></div>
            <div className="stat-card"><span className="stat-label">Batches</span><span className="stat-value">{formatNumber(data.summary.batches)}</span></div>
            <div className="stat-card"><span className="stat-label">Usable Units</span><span className="stat-value">{formatNumber(data.summary.usable_units)}</span></div>
            <div className="stat-card"><span className="stat-label">Expired Batches</span><span className="stat-value">{formatNumber(data.summary.expired_batches)}</span></div>
          </section>

          <Panel title={data.title} subtitle={data.period_label}>
            <div className="table-wrap">
              <table className="data">
                <thead><tr>{data.headers.map((header) => <th key={header}>{header}</th>)}</tr></thead>
                <tbody>
                  {data.rows.length === 0 ? <tr><td colSpan={data.headers.length}><Empty>No records for this report.</Empty></td></tr> : data.rows.map((row, index) => (
                    <tr key={index}>{row.map((cell, cellIndex) => <td key={cellIndex}>{cell}</td>)}</tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Panel>

          <Panel title="Top 10 Inventory Snapshot">
            <div className="table-wrap">
              <table className="data">
                <thead><tr><th>Medicine</th><th>Category</th><th>Batches</th><th>Total Qty</th><th>Usable Qty</th></tr></thead>
                <tbody>
                  {data.snapshot.length === 0 ? <tr><td colSpan="5"><Empty>No inventory data.</Empty></td></tr> : data.snapshot.map((row) => (
                    <tr key={row.name}>
                      <td className="strong">{row.name}</td>
                      <td>{row.category}</td>
                      <td>{row.batch_count}</td>
                      <td>{formatNumber(row.total_quantity)}</td>
                      <td>{formatNumber(row.usable_quantity)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Panel>
        </>
      )}
    </div>
  )
}
