<script setup>
import { onMounted, ref } from 'vue'
import { api } from '../api.js'
import { flash } from '../notice.js'

const props = defineProps({ imagesOnly: { type: Boolean, default: false } })
const emit = defineEmits(['choose', 'close'])

const items = ref([])
const q = ref('')
const uploading = ref(false)

async function load() {
  const result = await api.get('media', { q: q.value, images: props.imagesOnly ? '1' : '0' })
  items.value = result.items
}

async function upload(event) {
  const file = event.target.files?.[0]
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

onMounted(load)
</script>

<template>
  <div class="modal-backdrop" @click.self="emit('close')" @keydown.esc="emit('close')">
    <div class="modal" role="dialog" aria-modal="true" aria-label="Choose media">
      <div class="page-head">
        <h2>Choose {{ imagesOnly ? 'an image' : 'a file' }}</h2>
        <button type="button" class="link-button" @click="emit('close')">Close</button>
      </div>
      <form class="filters" @submit.prevent="load">
        <input v-model="q" type="search" placeholder="Search file names and alt text" aria-label="Search media">
        <button class="button" type="submit">Search</button>
        <label class="button primary upload">
          {{ uploading ? 'Uploading…' : 'Upload' }}
          <input type="file" :accept="imagesOnly ? 'image/*' : undefined" hidden @change="upload">
        </label>
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
