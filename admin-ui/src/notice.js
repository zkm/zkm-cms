import { reactive } from 'vue'

// A single status line shown at the bottom of the screen ("Saved", errors).
export const notice = reactive({ text: '', kind: 'ok', timer: null })

export function flash(text, kind = 'ok') {
  clearTimeout(notice.timer)
  notice.text = text
  notice.kind = kind
  notice.timer = setTimeout(() => (notice.text = ''), kind === 'error' ? 8000 : 3000)
}
