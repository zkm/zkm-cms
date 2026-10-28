// Clicks through the admin in a real browser, as a person would.
//
//   ADMIN_EMAIL=you@example.com ADMIN_PASSWORD='…' node tests/smoke.mjs http://localhost:8090
//
// Signs in, opens every page from the side menu, opens a post in the editor, and checks
// that "Sign out everywhere else" ends a second browser's session but not this one.
// Fails on any browser console error. Set CHROMIUM_PATH to use an installed Chromium
// instead of Playwright's (npx playwright install chromium).
import { chromium } from 'playwright'

const base = (process.argv[2] ?? 'http://localhost:8090').replace(/\/$/, '')
const email = process.env.ADMIN_EMAIL
const password = process.env.ADMIN_PASSWORD

if (!email || !password) {
  console.error('Set ADMIN_EMAIL and ADMIN_PASSWORD.')
  process.exit(2)
}

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined })
const failures = []
const check = (ok, label, detail = '') => {
  console.log(`  ${ok ? 'ok  ' : 'FAIL'}  ${label}${!ok && detail ? `  (${detail})` : ''}`)
  if (!ok) failures.push(label)
}

async function signIn() {
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } })
  const page = await context.newPage()
  page.consoleErrors = []
  page.on('console', (m) => m.type() === 'error' && page.consoleErrors.push(m.text()))
  page.on('pageerror', (e) => page.consoleErrors.push(e.message))
  await page.goto(`${base}/admin/login`)
  await page.fill('input[name=email]', email)
  await page.fill('input[name=password]', password)
  await page.click('button[type=submit]')
  await page.waitForSelector('.cards', { timeout: 10000 })
  return page
}

try {
  console.log('Side menu')
  const page = await signIn()
  check(true, 'signed in')

  const pages = [
    ['Posts', 'Posts'], ['Pages', 'Pages'], ['Media', 'Media'], ['Categories & tags', 'Categories & tags'],
    ['Menus', 'Menus'], ['Redirects', 'Redirects'], ['Messages', 'Messages'], ['Settings', 'Settings'],
    ['Account', 'Account'], ['Dashboard', 'Dashboard'],
  ]

  // Each link clicked from the page the previous click opened, like moving around for real.
  for (const [link, heading] of pages) {
    await page.click(`.sidenav >> text="${link}"`)
    const shown = await page.waitForFunction((h) => document.querySelector('main h1')?.textContent?.trim() === h, heading, { timeout: 5000 }).then(() => true, () => false)
    check(shown, `${link} opens`, `on ${new URL(page.url()).pathname}`)
  }

  console.log('Editor')
  await page.click('.sidenav >> text="Posts"')
  await page.waitForSelector('table.list tbody tr a')
  await page.click('table.list tbody tr a >> nth=0')
  await page.waitForSelector('.body-input')
  const preview = await page.frameLocator('iframe.preview').locator('.entry-title').textContent({ timeout: 5000 }).catch(() => null)
  check(Boolean(preview), 'a post opens in the editor with a preview')
  await page.click('.sidenav >> text="Dashboard"')

  console.log('Sessions')
  const other = await signIn()
  await page.click('.sidenav >> text="Account"')
  const form = page.locator('form', { hasText: 'Sign out everywhere else' })
  await form.locator('input[type=password]').fill(password)
  await form.locator('button').click()
  await page.waitForSelector('.notice')
  check((await page.request.get(`${base}/admin/api/me`)).status() === 200, 'this session stays signed in')
  check((await other.request.get(`${base}/admin/api/me`)).status() === 401, 'the other session is signed out')

  check(page.consoleErrors.length === 0, 'no browser console errors', page.consoleErrors.join(' | '))
} catch (error) {
  check(false, 'smoke test ran to the end', error.message.split('\n')[0])
} finally {
  await browser.close()
}

console.log(failures.length ? `\n${failures.length} admin check(s) failed.` : '\nAll admin checks passed.')
process.exit(failures.length ? 1 : 0)
