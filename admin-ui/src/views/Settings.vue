<script setup>
import { onMounted, ref } from 'vue'
import { api } from '../api.js'
import { flash } from '../notice.js'

const settings = ref(null)
const pages = ref([])

const fields = [
  { key: 'site_name', label: 'Site name' },
  { key: 'tagline', label: 'Tagline' },
  { key: 'posts_per_page', label: 'Posts per page', type: 'number' },
  { key: 'contact_email', label: 'Contact form sends to', type: 'email' },
  { key: 'gtm_id', label: 'Google Tag Manager ID', hint: 'Only loaded in production. Leave empty for no analytics.' },
  { key: 'twitter', label: 'X / Twitter handle for share cards' },
  { key: 'fediverse', label: 'Fediverse creator (e.g. @you@mastodon.social)' },
  { key: 'footer_note', label: 'Footer note' },
]

onMounted(async () => {
  const [data, pageList] = await Promise.all([api.get('settings'), api.get('posts', { type: 'page', status: 'published' })])
  settings.value = data
  pages.value = pageList.items
})

async function save() {
  try {
    settings.value = await api.put('settings', settings.value)
    flash('Settings saved')
  } catch (e) {
    flash(e.message, 'error')
  }
}
</script>

<template>
  <h1>Settings</h1>
  <form v-if="settings" class="panel settings" @submit.prevent="save">
    <label v-for="f in fields" :key="f.key" class="field">
      <span>{{ f.label }}</span>
      <input v-model="settings[f.key]" :type="f.type ?? 'text'">
      <span v-if="f.hint" class="muted small">{{ f.hint }}</span>
    </label>
    <label class="field"><span>Home page</span>
      <select v-model="settings.front_page_id">
        <option value="">Latest posts</option>
        <option v-for="p in pages" :key="p.id" :value="String(p.id)">{{ p.title }}</option>
      </select>
    </label>
    <label class="field"><span>Blog page</span>
      <select v-model="settings.posts_page_id">
        <option value="">None</option>
        <option v-for="p in pages" :key="p.id" :value="String(p.id)">{{ p.title }}</option>
      </select>
    </label>
    <label class="field"><span>Site icon (favicon) media ID</span><input v-model="settings.site_icon_id" inputmode="numeric">
      <span class="muted small">Use a square image that has WordPress-style site_icon sizes; the imported one does.</span></label>
    <button class="button primary" type="submit">Save settings</button>
  </form>
</template>
