<script setup>
import { onMounted, ref } from 'vue'
import { api } from '../api.js'
import { flash } from '../notice.js'

const result = ref(null)
const q = ref('')
const page = ref(1)
const selected = ref(null)
const uploading = ref(0)
const dragging = ref(false)

async function load() {
  result.value = await api.get('media', { q: q.value, page: page.value })
}

async function uploadFiles(files) {
  for (const file of files) {
    uploading.value++
    try {
      const form = new FormData()
      form.append('file', file)
      await api.post('media', form)
    } catch (e) {
      flash(`${file.name}: ${e.message}`, 'error')
    } finally {
      uploading.value--
    }
  }
  page.value = 1
  load()
}

function onDrop(event) {
  dragging.value = false
  uploadFiles([...event.dataTransfer.files])
}

async function saveSelected() {
  selected.value = await api.put(`media/${selected.value.id}`, { alt: selected.value.alt, caption: selected.value.caption })
  flash('Saved')
  load()
}

async function removeSelected(force = false) {
  if (!force && !confirm('Delete this file? It is removed from the server.')) return
  try {
    await api.del(`media/${selected.value.id}${force ? '?force=1' : ''}`)
    selected.value = null
    flash('Deleted')
    load()
  } catch (e) {
    if (e.status === 409 && confirm(`${e.message}\n\nDelete it anyway? Those posts will show a broken image.`)) {
      return removeSelected(true)
    }
    flash(e.message, 'error')
  }
}

function copy(text) {
  navigator.clipboard?.writeText(window.location.origin + text)
  flash('Copied')
}

onMounted(load)
</script>

<template>
  <div class="page-head">
    <h1>Media</h1>
    <label class="button primary upload">
      {{ uploading ? `Uploading ${uploading}…` : 'Upload files' }}
      <input type="file" multiple hidden @change="uploadFiles([...$event.target.files])">
    </label>
  </div>
  <div class="dropzone" :class="{ dragging }" @dragover.prevent="dragging = true" @dragleave="dragging = false" @drop.prevent="onDrop">
    Drop files here to upload. Images get 300px and 1024px copies, like WordPress made.
  </div>
  <form class="filters" @submit.prevent="page = 1; load()">
    <input v-model="q" type="search" placeholder="Search file names and alt text" aria-label="Search media">
    <button class="button" type="submit">Search</button>
  </form>
  <div v-if="result" class="media-layout">
    <div>
      <p class="muted">{{ result.total }} files</p>
      <div class="media-grid">
        <button v-for="item in result.items" :key="item.id" type="button" class="media-tile" :class="{ active: selected?.id === item.id }" @click="selected = { ...item }">
          <img v-if="item.mime.startsWith('image/')" :src="item.thumb" :alt="item.alt" loading="lazy">
          <span v-else class="file-icon">{{ item.path.split('.').pop() }}</span>
          <span class="media-name">{{ item.path.split('/').pop() }}</span>
        </button>
      </div>
      <nav v-if="result.pages > 1" class="pager">
        <button class="button" :disabled="page <= 1" @click="page--; load()">← Newer</button>
        <span>Page {{ page }} of {{ result.pages }}</span>
        <button class="button" :disabled="page >= result.pages" @click="page++; load()">Older →</button>
      </nav>
    </div>
    <aside v-if="selected" class="media-detail">
      <img v-if="selected.mime.startsWith('image/')" :src="selected.large" :alt="selected.alt">
      <p class="small"><strong>{{ selected.path }}</strong><br>{{ selected.mime }}<span v-if="selected.width"> · {{ selected.width }}×{{ selected.height }}</span></p>
      <label class="field"><span>Alt text (describe the image)</span><textarea v-model="selected.alt" rows="3"></textarea></label>
      <label class="field"><span>Caption</span><textarea v-model="selected.caption" rows="2"></textarea></label>
      <div class="row-actions">
        <button class="button primary" type="button" @click="saveSelected">Save</button>
        <button class="button" type="button" @click="copy(selected.url)">Copy URL</button>
        <button class="link-button danger" type="button" @click="removeSelected()">Delete</button>
      </div>
    </aside>
  </div>
</template>
