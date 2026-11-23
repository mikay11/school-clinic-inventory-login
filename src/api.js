const apiBase = (import.meta.env.VITE_API_URL || '/api/').replace(/\/?$/, '/')

let csrfToken = ''

export function setCsrf(token) {
  csrfToken = token || ''
}

export function getCsrf() {
  return csrfToken
}

export async function api(route, { method = 'GET', body, params } = {}) {
  const url = new URL(apiBase, window.location.origin)
  url.searchParams.set('route', route)
  Object.entries(params || {}).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') {
      url.searchParams.set(key, value)
    }
  })

  const options = {
    method,
    credentials: 'include',
    headers: { Accept: 'application/json' },
  }

  if (body !== undefined) {
    options.headers['Content-Type'] = 'application/json'
    options.body = JSON.stringify({ ...body, csrf_token: csrfToken })
  }

  const response = await fetch(url, options)
  let payload
  try {
    payload = await response.json()
  } catch {
    payload = { error: 'The server returned an unexpected response.' }
  }

  if (payload.csrf_token) {
    setCsrf(payload.csrf_token)
  }

  if (!response.ok) {
    const error = new Error(payload.error || payload.errors?.join(' ') || 'Request failed.')
    error.payload = payload
    error.status = response.status
    throw error
  }

  return payload
}

export function messageOf(error) {
  const payload = error?.payload
  if (payload?.errors?.length) {
    return payload.errors.join(' ')
  }
  return error?.message || 'Something went wrong. Please try again.'
}
