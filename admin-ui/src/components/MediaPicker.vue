<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { api } from '../api.js'
import { flash } from '../notice.js'
import UploadButton from './UploadButton.vue'

const props = defineProps({ imagesOnly: { type: Boolean, default: false } })
const emit = defineEmits(['choose', 'close'])

const items = ref([])
const q = ref('')
const uploading = ref(false)
const dialog = ref(null)

async function load() {
  const result = await api.get('media', { q: q.value, images: props.imagesOnly ? '1' : '0' })
  items.value = result.items
}

async function upload([file]) {
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

// Escape closes the dialog, except while composing text (IME), or in the search box
// while it has text, where it clears the box first.
function onEscape(event) {
  if (event.isComposing) {
    event.preventDefault()
  } else if (event.target.type === 'search' && event.target.value !== '') {
    event.preventDefault()
    q.value = ''
  }
}

// A click outside the box lands on the <dialog> itself (its backdrop).
function onClick(event) {
  if (event.target === dialog.value) emit('close')
}

// showModal() makes the rest of the page inert and keeps focus inside.
onMounted(() => {
  dialog.value.showModal()
  load()
})

// close() has the browser return focus to the button that opened the dialog.
onBeforeUnmount(() => {
  if (dialog.value?.open) dialog.value.close()
})
</script>

<template>
  <dialog ref="dialog" class="modal" aria-labelledby="media-picker-title" @keydown.esc="onEscape" @close="emit('close')" @click="onClick">
    <div class="modal-body">
      <div class="page-head">
        <h2 id="media-picker-title">Choose {{ imagesOnly ? 'an image' : 'a file' }}</h2>
        <button type="button" class="link-button" @click="emit('close')">Close</button>
      </div>
      <form class="filters" @submit.prevent="load">
        <input v-model="q" type="search" placeholder="Search file names and alt text" aria-label="Search media" autofocus>
        <button class="button" type="submit">Search</button>
        <UploadButton :accept="imagesOnly ? 'image/*' : undefined" :busy="uploading" @files="upload">{{ uploading ? 'Uploading…' : 'Upload' }}</UploadButton>
      </form>
      <div class="media-grid">
        <button v-for="item in items" :key="item.id" type="button" class="media-tile" @click="emit('choose', item)">
          <img v-if="item.mime.startsWith('image/')" :src="item.thumb" :alt="item.alt" loading="lazy">
          <span v-else class="file-icon">{{ item.path.split('.').pop() }}</span>
          <span class="media-name">{{ item.path.split('/').pop() }}</span>
        </button>
      </div>
    </div>
  </dialog>
</template>
