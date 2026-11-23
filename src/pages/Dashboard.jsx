import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, messageOf } from '../api.js'
import Icon from '../components/Icons.jsx'
import { Alert, Empty, Loading, Panel, StatCard } from '../components/UI.jsx'
import { formatDate, formatNumber } from '../constants.js'

function timeAgo(timestamp) {
  const diff = Math.floor((Date.now() - new Date(timestamp).getTime()) / 1000)
  if (diff < 60) return 'just now'
  if (diff < 3600) return `${Math.floor(diff / 60)}m ago`
  if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`
  if (diff < 604800) return `${Math.floor(diff / 86400)}d ago`
  return formatDate(timestamp)
}

export default function Dashboard() {
  const [data, setData] = useState(null)
  const [error, setError] = useState('')

  useEffect(() => {
    let active = true
    api('dashboard')
      .then((payload) => { if (active) setData(payload) })
      .catch((caught) => { if (active) setError(messageOf(caught)) })
    return () => { active = false }
  }, [])

  if (error) return <Alert>{error}</Alert>
  if (!data) return <Loading label="Loading dashboard…" />

  const { totals, stock_rows: stockRows, low_stock_rows: lowStock, expiring_rows: expiringRows, activities } = data
  const percent = (part) => totals.batches > 0 ? (part / totals.batches) * 100 : 0
  const maxStock = Math.max(1, ...stockRows.map((row) => row.usable))

  return (
    <div className="stack">
      <section className="stat-grid">
        <StatCard label="Total Medicines" value={formatNumber(totals.medicines)} hint="Registered medicine items" icon="medicine" href="#stock-overview" />
        <StatCard label="Total Stock Units" value={formatNumber(totals.units)} hint="Usable units (non-expired)" icon="box" href="#stock-overview" />
        <StatCard label="Low Stock Medicines" value={formatNumber(totals.low_stock)} hint="At or below threshold" icon="alert" tone="warn" href="#low-stock" />
        <StatCard label="Expired Batches" value={formatNumber(totals.expired)} hint="Ready for disposal" icon="expired" tone="danger" href="#expiration-overview" />
        <StatCard label="Expiring Soon" value={formatNumber(totals.expiring)} hint="Within the next 30 days" icon="clock" tone="warn" href="#expiring-soon" />
      </section>

      <section className="quick-actions">
        <Link className="btn primary" to="/inventory/new"><Icon name="plus" />Add Medicine</Link>
        <Link className="btn" to="/stock-in"><Icon name="stock-in" />Stock In</Link>
        <Link className="btn" to="/stock-out"><Icon name="stock-out" />Stock Out</Link>
        <Link className="btn" to="/reports"><Icon name="reports" />View Reports</Link>
      </section>

      <div className="grid-2">
        <Panel id="expiration-overview" title="Expiration Overview" action={<span className="badge teal">{totals.batches} batches</span>}>
          <div className="exp-bar">
            <span className="exp-safe" style={{ width: `${percent(totals.normal)}%` }} />
            <span className="exp-soon" style={{ width: `${percent(totals.expiring)}%` }} />
            <span className="exp-expired" style={{ width: `${percent(totals.expired)}%` }} />
          </div>
          <ul className="legend">
            <li><i className="dot green" />Normal <strong>{totals.normal}</strong></li>
            <li><i className="dot yellow" />Expiring soon <strong>{totals.expiring}</strong></li>
            <li><i className="dot red" />Expired <strong>{totals.expired}</strong></li>
          </ul>
        </Panel>

        <Panel id="stock-overview" title="Medicine Stock Overview" action={<span className="badge teal">Top {stockRows.length} by stock</span>}>
          {stockRows.length === 0 ? <Empty>No medicines found.</Empty> : (
            <div className="stock-list">
              {stockRows.map((row) => (
                <div className="stock-row" key={row.id}>
                  <span className="stock-name">{row.name}</span>
                  <span className="stock-qty">{formatNumber(row.usable)} {row.unit}</span>
                  <span className="stock-track">
                    <span className={`stock-fill ${row.usable <= row.threshold ? 'low' : 'ok'}`} style={{ width: `${Math.max(3, (row.usable / maxStock) * 100)}%` }} />
                  </span>
                </div>
              ))}
            </div>
          )}
        </Panel>
      </div>

      <div className="grid-2">
        <Panel id="expiring-soon" title="Medicines Nearing Expiration" action={<span className="badge yellow">{totals.expiring} within 30 days</span>}>
          <div className="table-wrap">
            <table className="data">
              <thead><tr><th>Medicine</th><th>Qty</th><th>Expiry</th><th>Status</th></tr></thead>
              <tbody>
                {expiringRows.length === 0 ? <tr><td colSpan="4"><Empty>No batches expiring within 30 days.</Empty></td></tr> : expiringRows.map((row) => (
                  <tr key={`${row.name}-${row.batch_no}`}>
                    <td className="strong">{row.name}</td>
                    <td>{formatNumber(row.quantity)}</td>
                    <td>{formatDate(row.expiration_date)}</td>
                    <td><span className="badge yellow">{Number(row.days_left) === 0 ? 'Expires today' : `${row.days_left} days left`}</span></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Panel>

        <Panel id="low-stock" title="Low Stock Medicines" action={<span className="badge red">{totals.low_stock} need restocking</span>}>
          <div className="table-wrap">
            <table className="data">
              <thead><tr><th>Medicine</th><th>Category</th><th>Stock</th><th>Status</th></tr></thead>
              <tbody>
                {lowStock.length === 0 ? <tr><td colSpan="4"><Empty>All medicines are above their thresholds.</Empty></td></tr> : lowStock.map((row) => (
                  <tr key={row.id}>
                    <td className="strong">{row.name}</td>
                    <td>{row.category}</td>
                    <td>{formatNumber(row.usable)} {row.unit}</td>
                    <td><span className="badge red">{row.usable === 0 ? 'Out of stock' : 'Low stock'}</span></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Panel>
      </div>

      <Panel title="Recent Inventory Activities">
        {activities.length === 0 ? <Empty>No inventory activities recorded yet.</Empty> : (
          <ul className="activity-list">
            {activities.map((activity, index) => (
              <li className="activity" key={`${activity.created_at}-${index}`}>
                <span className={`activity-icon ${activity.activity_type === 'stock_out' ? 'yellow' : 'teal'}`}>
                  <Icon name={activity.activity_type === 'stock_out' ? 'stock-out' : activity.activity_type === 'stock_in' ? 'stock-in' : 'medicine'} />
                </span>
                <div className="activity-main">
                  <div className="activity-title">
                    {activity.activity_type.split('_').map((word) => word[0].toUpperCase() + word.slice(1)).join(' ')}
                    {activity.name && ` - ${activity.name}`}
                    {Number(activity.quantity) !== 0 && <span className="qty">{Number(activity.quantity) > 0 ? '+' : ''}{formatNumber(activity.quantity)}</span>}
                  </div>
                  <div className="activity-meta">{activity.note || '—'} · by {activity.performed_by}</div>
                </div>
                <span className="activity-time">{timeAgo(activity.created_at)}</span>
              </li>
            ))}
          </ul>
        )}
      </Panel>
    </div>
  )
}
