import { nextTick, reactive } from 'vue'

// A single status line shown at the bottom of the screen ("Saved", errors).
export const notice = reactive({ text: '', kind: 'ok', timer: null })

export async function flash(text, kind = 'ok') {
  clearTimeout(notice.timer)
  // Cleared first, so the same message twice in a row still changes the live region and is read again.
  notice.text = ''
  await nextTick()
  notice.text = text
  notice.kind = kind
  notice.timer = setTimeout(() => (notice.text = ''), kind === 'error' ? 8000 : 3000)
}
