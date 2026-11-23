import { useEffect, useState } from 'react'
import { api, messageOf } from '../api.js'
import { useAuth } from '../AuthContext.jsx'
import Icon from '../components/Icons.jsx'
import { Alert, Badge, Empty, Loading, Panel } from '../components/UI.jsx'
import { formatDate } from '../constants.js'

export default function Notifications() {
  const { setUnread } = useAuth()
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  async function load() {
    try {
      const data = await api('notifications')
      setRows(data.rows)
      setUnread(data.unread_count)
    } catch (caught) {
      setError(messageOf(caught))
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    load()
  }, [])

  async function markRead(id) {
    try {
      const data = await api('notifications/read', { method: 'POST', body: id ? { id } : { all: true } })
      setUnread(data.unread_count)
      load()
    } catch (caught) {
      setError(messageOf(caught))
    }
  }

  async function remove(id) {
    try {
      const data = await api('notification/delete', { method: 'POST', body: { id } })
      setUnread(data.unread_count)
      load()
    } catch (caught) {
      setError(messageOf(caught))
    }
  }

  const unread = rows.filter((row) => Number(row.is_read) === 0).length

  return (
    <div className="stack">
      <div className="page-heading">
        <div><h2>Notifications</h2><p className="muted">Inventory alerts and system messages.</p></div>
        <button type="button" className="btn" onClick={() => markRead(null)} disabled={unread === 0}><Icon name="check" />Mark all as read</button>
      </div>
      {error && <Alert>{error}</Alert>}

      <Panel title="Recent Notifications" action={<span className="badge teal">{unread} unread</span>}>
        {loading ? <Loading label="Loading notifications…" /> : rows.length === 0 ? <Empty>No notifications yet.</Empty> : (
          <ul className="notification-list">
            {rows.map((row) => (
              <li key={row.id} className={`notification ${Number(row.is_read) === 0 ? 'unread' : ''}`}>
                <span className="notification-icon"><Icon name={Number(row.is_read) === 0 ? 'bell' : 'check'} /></span>
                <div className="notification-body">
                  <p>{row.message}</p>
                  <span className="muted">{formatDate(row.created_at)}</span>
                </div>
                <div className="row-actions">
                  {Number(row.is_read) === 0 && (
                    <button type="button" className="icon-link" title="Mark as read" onClick={() => markRead(row.id)}><Icon name="check" /></button>
                  )}
                  <button type="button" className="icon-link danger" title="Delete" onClick={() => remove(row.id)}><Icon name="trash" /></button>
                </div>
              </li>
            ))}
          </ul>
        )}
      </Panel>

      <p className="muted">Showing the latest 50 notifications. <Badge tone="teal">Automatic low-stock and expiry alerts</Badge></p>
    </div>
  )
}
