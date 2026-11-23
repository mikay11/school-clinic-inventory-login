import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, messageOf } from '../api.js'
import Icon from '../components/Icons.jsx'
import { Alert, Badge, Empty, ErrorList, Loading } from '../components/UI.jsx'
import { CATEGORIES, formatNumber } from '../constants.js'

export default function Inventory() {
  const [rows, setRows] = useState([])
  const [total, setTotal] = useState(0)
  const [page, setPage] = useState(1)
  const [query, setQuery] = useState('')
  const [category, setCategory] = useState('')
  const [status, setStatus] = useState('all')
  const [selected, setSelected] = useState([])
  const [loading, setLoading] = useState(true)
  const [errors, setErrors] = useState([])
  const [notice, setNotice] = useState('')

  const perPage = 10
  const pages = Math.max(1, Math.ceil(total / perPage))

  async function load() {
    setLoading(true)
    try {
      const data = await api('medicines', { params: { q: query, category, status, page, per_page: perPage } })
      setRows(data.rows)
      setTotal(data.total)
      setSelected([])
    } catch (error) {
      setErrors([messageOf(error)])
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    load()
  }, [query, category, status, page])

  async function remove(id) {
    if (!window.confirm('Delete this medicine and all of its batches?')) return
    setErrors([])
    setNotice('')
    try {
      const data = await api('medicine', { method: 'DELETE', body: { id } })
      setNotice(data.message)
      load()
    } catch (error) {
      setErrors([messageOf(error)])
    }
  }

  async function removeSelected() {
    if (selected.length === 0) return
    if (!window.confirm(`Delete ${selected.length} selected medicine(s)?`)) return
    setErrors([])
    setNotice('')
    try {
      const data = await api('medicines/bulk-delete', { method: 'POST', body: { ids: selected } })
      setNotice(data.message)
      load()
    } catch (error) {
      setErrors([messageOf(error)])
    }
  }

  function toggle(id) {
    setSelected((current) => current.includes(id) ? current.filter((item) => item !== id) : [...current, id])
  }

  const toneFor = (value) => value === 'In stock' ? 'green' : value === 'Low stock' ? 'yellow' : 'red'

  return (
    <div className="stack">
      <div className="page-heading">
        <div>
          <h2>Medicine Inventory</h2>
          <p className="muted">Search, filter, and manage all registered medicines.</p>
        </div>
        <Link className="btn primary" to="/inventory/new"><Icon name="plus" />Add Medicine</Link>
      </div>

      {notice && <Alert tone="success">{notice}</Alert>}
      <ErrorList errors={errors} />

      <div className="filter-bar">
        <label className="search-box">
          <Icon name="search" />
          <input
            type="search"
            placeholder="Search by name, generic name, or location"
            value={query}
            onChange={(event) => { setQuery(event.target.value); setPage(1) }}
          />
        </label>
        <select value={category} onChange={(event) => { setCategory(event.target.value); setPage(1) }} aria-label="Filter by category">
          <option value="">All categories</option>
          {CATEGORIES.map((item) => <option key={item} value={item}>{item}</option>)}
        </select>
        <select value={status} onChange={(event) => { setStatus(event.target.value); setPage(1) }} aria-label="Filter by stock status">
          <option value="all">All statuses</option>
          <option value="in">In stock</option>
          <option value="low">Low stock</option>
          <option value="out">Out of stock</option>
        </select>
      </div>

      {selected.length > 0 && (
        <div className="bulk-bar">
          <span>{selected.length} selected</span>
          <button type="button" className="btn danger small" onClick={removeSelected}><Icon name="trash" />Delete selected</button>
        </div>
      )}

      <section className="panel">
        {loading ? <Loading label="Loading inventory…" /> : (
          <div className="table-wrap">
            <table className="data">
              <thead>
                <tr>
                  <th aria-label="Select" />
                  <th>Medicine</th>
                  <th>Category</th>
                  <th>Location</th>
                  <th>Stock</th>
                  <th>Threshold</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {rows.length === 0 ? <tr><td colSpan="8"><Empty>No medicines match your filters.</Empty></td></tr> : rows.map((row) => (
                  <tr key={row.id}>
                    <td><input type="checkbox" checked={selected.includes(row.id)} onChange={() => toggle(row.id)} aria-label={`Select ${row.name}`} /></td>
                    <td className="strong">
                      <Link to={`/inventory/${row.id}`}>{row.name}</Link>
                      <div className="sub-line">{row.generic_name || '—'}</div>
                    </td>
                    <td>{row.category}</td>
                    <td>{row.location || '—'}</td>
                    <td>{formatNumber(row.usable)} {row.unit}</td>
                    <td>{formatNumber(row.low_stock_threshold)}</td>
                    <td><Badge tone={toneFor(row.status)}>{row.status}</Badge></td>
                    <td>
                      <div className="row-actions">
                        <Link to={`/inventory/${row.id}`} className="icon-link" title="View"><Icon name="search" /></Link>
                        <Link to={`/inventory/${row.id}/edit`} className="icon-link" title="Edit"><Icon name="edit" /></Link>
                        <button type="button" className="icon-link danger" title="Delete" onClick={() => remove(row.id)}><Icon name="trash" /></button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      <div className="pagination">
        <span>Showing {total === 0 ? 0 : (page - 1) * perPage + 1}–{Math.min(page * perPage, total)} of {total}</span>
        <div>
          <button type="button" className="btn small" disabled={page <= 1} onClick={() => setPage((value) => value - 1)}>Previous</button>
          <span className="page-number">Page {page} of {pages}</span>
          <button type="button" className="btn small" disabled={page >= pages} onClick={() => setPage((value) => value + 1)}>Next</button>
        </div>
      </div>
    </div>
  )
}
