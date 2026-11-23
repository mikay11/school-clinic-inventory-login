import Icon from './Icons.jsx'

export function Alert({ tone = 'error', children }) {
  return <div className={`alert alert-${tone}`} role={tone === 'error' ? 'alert' : 'status'}>{children}</div>
}

export function ErrorList({ errors }) {
  if (!errors?.length) return null
  return (
    <Alert>
      <ul className="alert-list">
        {errors.map((error, index) => <li key={index}>{error}</li>)}
      </ul>
    </Alert>
  )
}

export function Panel({ id, title, subtitle, action, children }) {
  return (
    <section className="panel" id={id}>
      <div className="panel-head">
        <div>
          <h2>{title}</h2>
          {subtitle && <p className="panel-sub">{subtitle}</p>}
        </div>
        {action}
      </div>
      {children}
    </section>
  )
}

export function Field({ label, hint, children, list }) {
  return (
    <label className="field" list={list}>
      <span>{label}</span>
      {children}
      {hint && <small className="help">{hint}</small>}
    </label>
  )
}

export function Badge({ tone = 'teal', children }) {
  return <span className={`badge ${tone}`}>{children}</span>
}

export function Empty({ children }) {
  return <div className="empty">{children}</div>
}

export function Loading({ label = 'Loading…' }) {
  return <div className="loading"><span className="spinner" />{label}</div>
}

export function StatCard({ label, value, hint, icon, tone = 'teal', href }) {
  const content = (
    <>
      <div className="stat-head">
        <span className="stat-label">{label}</span>
        <span className={`stat-icon ${tone}`}><Icon name={icon} size={20} /></span>
      </div>
      <span className="stat-value">{value}</span>
      <span className="stat-hint">{hint}</span>
    </>
  )
  return href ? <a className="stat-card" href={href}>{content}</a> : <div className="stat-card">{content}</div>
}

export function ToolbarButton({ to, onClick, icon, children, tone = '' }) {
  const className = `btn ${tone}`.trim()
  if (to) {
    return <a className={className} href={to}><Icon name={icon} />{children}</a>
  }
  return <button type="button" className={className} onClick={onClick}><Icon name={icon} />{children}</button>
}
