<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { api } from '../api.js'
import { flash } from '../notice.js'

const props = defineProps({ imagesOnly: { type: Boolean, default: false } })
const emit = defineEmits(['choose', 'close'])

const items = ref([])
const q = ref('')
const uploading = ref(false)
const dialog = ref(null)
const search = ref(null)
const fileInput = ref(null)
let opener = null

async function load() {
  const result = await api.get('media', { q: q.value, images: props.imagesOnly ? '1' : '0' })
  items.value = result.items
}

async function upload(event) {
  const file = event.target.files?.[0]
  event.target.value = ''
  if (!file) return
  uploading.value = true
  try {
    const form = new FormData()
    form.append('file', file)
    const media = await api.post('media', form)
    emit('choose', media)
  } catch (e) {
    flash(e.message, 'error')
  } finally {
    uploading.value = false
  }
}

function focusables() {
  return [...dialog.value.querySelectorAll('button, input, select, textarea, a[href]')].filter((el) => !el.disabled && !el.hidden)
}

// A modal dialog: Escape closes it from anywhere, and Tab stays inside it.
function onKeydown(event) {
  if (event.key === 'Escape') {
    emit('close')
    return
  }

  if (event.key !== 'Tab') return

  const els = focusables()
  const first = els[0]
  const last = els[els.length - 1]

  if (!dialog.value.contains(document.activeElement)) {
    event.preventDefault()
    first.focus()
  } else if (event.shiftKey && document.activeElement === first) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault()
    first.focus()
  }
}

onMounted(() => {
  opener = document.activeElement
  document.addEventListener('keydown', onKeydown)
  search.value.focus()
  load()
})

// Back to the button that opened the dialog.
onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKeydown)
  opener?.focus?.()
})
</script>

<template>
  <div class="modal-backdrop" @click.self="emit('close')">
    <div ref="dialog" class="modal" role="dialog" aria-modal="true" aria-labelledby="media-picker-title">
      <div class="page-head">
        <h2 id="media-picker-title">Choose {{ imagesOnly ? 'an image' : 'a file' }}</h2>
        <button type="button" class="link-button" @click="emit('close')">Close</button>
      </div>
      <form class="filters" @submit.prevent="load">
        <input ref="search" v-model="q" type="search" placeholder="Search file names and alt text" aria-label="Search media">
        <button class="button" type="submit">Search</button>
        <button type="button" class="button primary" @click="fileInput.click()">{{ uploading ? 'Uploading…' : 'Upload' }}</button>
        <input ref="fileInput" type="file" :accept="imagesOnly ? 'image/*' : undefined" hidden @change="upload">
      </form>
      <div class="media-grid">
        <button v-for="item in items" :key="item.id" type="button" class="media-tile" @click="emit('choose', item)">
          <img v-if="item.mime.startsWith('image/')" :src="item.thumb" :alt="item.alt" loading="lazy">
          <span v-else class="file-icon">{{ item.path.split('.').pop() }}</span>
          <span class="media-name">{{ item.path.split('/').pop() }}</span>
        </button>
      </div>
    </div>
  </div>
</template>
