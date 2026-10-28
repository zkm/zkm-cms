// JSON client for /admin/api. Sends the CSRF token from the page and handles sign-out.
const csrfHeader = document.querySelector('meta[name="csrf-header"]')?.content ?? 'X-CSRF-TOKEN'
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? ''

export class ApiError extends Error {
  constructor(message, status, fields = {}) {
    super(message)
    this.status = status
    this.fields = fields
  }
}

async function request(method, path, body) {
  const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', [csrfHeader]: csrfToken }
  const init = { method, headers, credentials: 'same-origin' }

  if (body instanceof FormData) {
    init.body = body
  } else if (body !== undefined) {
    headers['Content-Type'] = 'application/json'
    init.body = JSON.stringify(body)
  }

  const res = await fetch('/admin/api/' + path.replace(/^\//, ''), init)

  if (res.status === 401) {
    window.location.href = '/admin/login'
    throw new ApiError('Signed out', 401)
  }

  const data = res.headers.get('content-type')?.includes('json') ? await res.json() : null

  if (!res.ok) {
    throw new ApiError(data?.error ?? data?.message ?? `Request failed (${res.status})`, res.status, data?.fields ?? {})
  }

  return data
}

export const api = {
  get: (path, params) => request('GET', params ? `${path}?${new URLSearchParams(params)}` : path),
  post: (path, body) => request('POST', path, body ?? {}),
  put: (path, body) => request('PUT', path, body ?? {}),
  del: (path) => request('DELETE', path),
}

export function signOut() {
  const form = document.createElement('form')
  form.method = 'post'
  form.action = '/admin/logout'
  const input = Object.assign(document.createElement('input'), { type: 'hidden', name: 'csrf_token', value: csrfToken })
  form.append(input)
  document.body.append(form)
  form.submit()
}

export function formatDate(value) {
  if (!value) return '—'
  const date = new Date(value.replace(' ', 'T'))
  return date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
}
