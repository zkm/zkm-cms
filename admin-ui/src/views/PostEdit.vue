<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { onBeforeRouteLeave, useRouter } from 'vue-router'
import { api, formatDate } from '../api.js'
import { flash } from '../notice.js'
import MediaPicker from '../components/MediaPicker.vue'

const props = defineProps({
  id: { type: Number, default: null },
  type: { type: String, default: 'post' },
})
const router = useRouter()

const blank = (type) => ({
  id: null, type, status: 'draft', title: '', slug: '', format: 'markdown', body: '', excerpt: '',
  meta_description: '', template: '', featured_media_id: null, featured_media: null, category_id: null,
  parent_id: null, menu_order: 0, published_at: '', term_ids: [], url: null,
})

const post = ref(null)
const saved = ref('')
const saving = ref(false)
const errors = ref({})
const terms = ref([])
const pages = ref([])
const revisions = ref(null)
const tagInput = ref('')
const picker = ref(null) // 'featured' | 'insert' | null
const previewHtml = ref('')
const previewTheme = ref(document.documentElement.dataset.theme ?? 'light')
const showPreview = ref(true)
const bodyField = ref(null)

const categories = computed(() => terms.value.filter((t) => t.taxonomy === 'category'))
const tags = computed(() => terms.value.filter((t) => t.taxonomy === 'tag'))
const selectedTags = computed(() => tags.value.filter((t) => post.value.term_ids.includes(Number(t.id))))
const selectedCategories = computed(() => categories.value.filter((t) => post.value.term_ids.includes(Number(t.id))))
const dirty = computed(() => post.value && JSON.stringify(snapshot(post.value)) !== saved.value)

function snapshot(p) {
  const { featured_media, url, updated_at, created_at, ...rest } = p
  return rest
}

// The API sends numbers from MySQL as strings and NULL text as null; make the form values consistent.
function normalize(data) {
  const id = (v) => (v === null || v === undefined || v === '' ? null : Number(v))
  return {
    ...data,
    id: id(data.id),
    category_id: id(data.category_id),
    parent_id: id(data.parent_id),
    featured_media_id: id(data.featured_media_id),
    menu_order: Number(data.menu_order ?? 0),
    published_at: data.published_at ? data.published_at.slice(0, 16).replace(' ', 'T') : '',
    excerpt: data.excerpt ?? '',
    meta_description: data.meta_description ?? '',
    template: data.template ?? '',
    body: data.body ?? '',
  }
}

async function load() {
  const [termList, pageList] = await Promise.all([api.get('terms'), api.get('posts', { type: 'page' })])
  terms.value = termList
  pages.value = pageList.items

  const data = normalize(props.id ? await api.get(`posts/${props.id}`) : blank(props.type))
  post.value = data
  saved.value = JSON.stringify(snapshot(data))
  revisions.value = null
  refreshPreview()
}

async function save() {
  if (saving.value) return
  saving.value = true
  errors.value = {}

  try {
    const body = { ...post.value, published_at: post.value.published_at ? post.value.published_at.replace('T', ' ') : '' }
    const data = normalize(props.id ? await api.put(`posts/${props.id}`, body) : await api.post('posts', body))
    post.value = data
    saved.value = JSON.stringify(snapshot(data))
    flash('Saved')

    if (!props.id) router.replace(`/edit/${data.id}`)
  } catch (e) {
    errors.value = e.fields ?? {}
    flash(e.message, 'error')
  } finally {
    saving.value = false
  }
}

function publish() {
  post.value.status = 'published'
  save()
}

let previewTimer = null
function refreshPreview() {
  clearTimeout(previewTimer)
  previewTimer = setTimeout(async () => {
    if (!post.value || !showPreview.value) return
    const { html } = await api.post('preview', { id: post.value.id, format: post.value.format, body: post.value.body })
    previewHtml.value = html
  }, 350)
}

const previewDoc = computed(() => `<!doctype html><html data-theme="${previewTheme.value}"><head><meta charset="utf-8">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Orbitron:wght@600;700;800&family=Rajdhani:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="/theme/blocks.css"><link rel="stylesheet" href="/theme/style.css">
<style>body{padding:1rem 0}.site-main{padding-top:0}</style></head>
<body class="zkm-ready"><main class="site-main"><div class="content-area"><article class="post hentry${post.value?.slug === 'about-2' || post.value?.slug === 'about' ? ' is-about-page' : ''}">
<header class="entry-header"><h1 class="entry-title">${escapeHtml(post.value?.title ?? '')}</h1></header>
<div class="entry-content">${previewHtml.value}</div></article></div></main></body></html>`)

function escapeHtml(text) {
  return text.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c])
}

function toggleTerm(id) {
  const ids = post.value.term_ids
  post.value.term_ids = ids.includes(id) ? ids.filter((t) => t !== id) : [...ids, id]
}

async function addTag() {
  const name = tagInput.value.trim()
  if (!name) return
  let tag = tags.value.find((t) => t.name.toLowerCase() === name.toLowerCase() || t.slug === name.toLowerCase())

  if (!tag) {
    try {
      tag = await api.post('terms', { taxonomy: 'tag', name })
      terms.value = [...terms.value, tag]
    } catch (e) {
      flash(e.message, 'error')
      return
    }
  }

  if (!post.value.term_ids.includes(Number(tag.id))) post.value.term_ids = [...post.value.term_ids, Number(tag.id)]
  tagInput.value = ''
}

function chooseMedia(media) {
  if (picker.value === 'featured') {
    post.value.featured_media_id = media.id
    post.value.featured_media = media
  } else {
    insertAtCursor(mediaSnippet(media))
  }
  picker.value = null
}

function mediaSnippet(media) {
  const isImage = media.mime.startsWith('image/')
  const src = media.large
  const alt = media.alt || ''

  if (post.value.format === 'markdown') {
    return isImage ? `![${alt}](${src})\n` : `[${media.path.split('/').pop()}](${media.url})\n`
  }

  return isImage
    ? `<figure class="wp-block-image aligncenter"><img src="${src}" alt="${escapeHtml(alt)}"/></figure>\n`
    : `<p><a href="${media.url}">${media.path.split('/').pop()}</a></p>\n`
}

function insertAtCursor(text) {
  const el = bodyField.value
  const start = el?.selectionStart ?? post.value.body.length
  const end = el?.selectionEnd ?? start
  post.value.body = post.value.body.slice(0, start) + text + post.value.body.slice(end)
  requestAnimationFrame(() => {
    el?.focus()
    el?.setSelectionRange(start + text.length, start + text.length)
  })
}

function insertMore() {
  insertAtCursor('\n<!--more-->\n')
}

async function loadRevisions() {
  revisions.value = await api.get(`posts/${post.value.id}/revisions`)
}

function restore(rev) {
  if (!confirm(`Load the version from ${formatDate(rev.created_at)} into the editor? Nothing is saved until you press Save.`)) return
  post.value.title = rev.title
  post.value.format = rev.format
  post.value.body = rev.body ?? ''
  post.value.excerpt = rev.excerpt ?? ''
}

function onKey(event) {
  if ((event.ctrlKey || event.metaKey) && event.key === 's') {
    event.preventDefault()
    save()
  }
}

function onBeforeUnload(event) {
  if (dirty.value) event.preventDefault()
}

onMounted(() => {
  window.addEventListener('keydown', onKey)
  window.addEventListener('beforeunload', onBeforeUnload)
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey)
  window.removeEventListener('beforeunload', onBeforeUnload)
})
onBeforeRouteLeave(() => !dirty.value || confirm('Leave without saving your changes?'))

watch(() => [props.id, props.type], load, { immediate: true })
watch(() => post.value && [post.value.body, post.value.format], refreshPreview)
watch(showPreview, refreshPreview)
</script>

<template>
  <div v-if="post" class="editor">
    <div class="page-head">
      <h1>{{ post.id ? `Edit ${post.type}` : `New ${post.type}` }}</h1>
      <div class="row-actions">
        <a v-if="post.id && post.url && post.status === 'published'" class="button" :href="post.url" target="_blank" rel="noopener">View ↗</a>
        <span v-if="dirty" class="muted">Unsaved changes</span>
        <button v-if="post.status !== 'published'" class="button" type="button" :disabled="saving" @click="save">Save draft</button>
        <button v-if="post.status !== 'published'" class="button primary" type="button" :disabled="saving" @click="publish">Publish</button>
        <button v-else class="button primary" type="button" :disabled="saving" @click="save">Update</button>
      </div>
    </div>

    <div class="editor-grid">
      <section class="editor-main">
        <label class="field">
          <span>Title</span>
          <input v-model="post.title" class="title-input" :class="{ invalid: errors.title }" required>
        </label>
        <label class="field slug-field">
          <span>URL slug</span>
          <input v-model="post.slug" placeholder="made from the title">
          <span v-if="post.url" class="muted small">{{ post.url }}</span>
        </label>

        <div class="body-toolbar">
          <label>Format
            <select v-model="post.format">
              <option value="markdown">Markdown</option>
              <option value="html">HTML</option>
            </select>
          </label>
          <button type="button" class="button small" @click="picker = 'insert'">Insert media</button>
          <button v-if="post.type === 'post'" type="button" class="button small" @click="insertMore">“Read more” break</button>
          <label class="push"><input v-model="showPreview" type="checkbox"> Preview</label>
          <select v-if="showPreview" v-model="previewTheme" aria-label="Preview colour mode">
            <option value="light">Light</option>
            <option value="dark">Dark</option>
          </select>
        </div>

        <div class="body-panes" :class="{ split: showPreview }">
          <textarea ref="bodyField" v-model="post.body" class="body-input" spellcheck="true" :placeholder="post.format === 'markdown' ? 'Write in Markdown…' : 'HTML…'"></textarea>
          <iframe v-if="showPreview" class="preview" title="Preview" :srcdoc="previewDoc"></iframe>
        </div>
        <p v-if="post.format === 'html'" class="muted small">This post was imported from WordPress as HTML. Switching to Markdown doesn't convert it. Raw HTML also works inside Markdown.</p>
      </section>

      <aside class="editor-side">
        <fieldset>
          <legend>Publish</legend>
          <label class="field"><span>Status</span>
            <select v-model="post.status">
              <option value="draft">Draft</option>
              <option value="pending">Pending review</option>
              <option value="private">Private (only you)</option>
              <option value="published">Published</option>
            </select>
          </label>
          <label class="field"><span>Date</span>
            <input v-model="post.published_at" type="datetime-local" :class="{ invalid: errors.published_at }">
          </label>
          <p class="muted small">A future date schedules the post. Leave it empty to use the time you publish.</p>
        </fieldset>

        <fieldset v-if="post.type === 'post'">
          <legend>Categories</legend>
          <div class="checklist">
            <label v-for="cat in categories" :key="cat.id">
              <input type="checkbox" :checked="post.term_ids.includes(Number(cat.id))" @change="toggleTerm(Number(cat.id))"> {{ cat.name }}
            </label>
          </div>
          <label v-if="selectedCategories.length > 1" class="field"><span>Category in the URL</span>
            <select v-model.number="post.category_id">
              <option v-for="cat in selectedCategories" :key="cat.id" :value="Number(cat.id)">{{ cat.slug }}</option>
            </select>
          </label>
        </fieldset>

        <fieldset v-if="post.type === 'post'">
          <legend>Tags</legend>
          <div class="chips">
            <button v-for="tag in selectedTags" :key="tag.id" type="button" class="chip" :title="`Remove ${tag.name}`" @click="toggleTerm(Number(tag.id))">{{ tag.name }} ×</button>
          </div>
          <form class="inline" @submit.prevent="addTag">
            <input v-model="tagInput" list="tag-options" placeholder="Add a tag">
            <datalist id="tag-options"><option v-for="tag in tags" :key="tag.id" :value="tag.name" /></datalist>
            <button class="button small" type="submit">Add</button>
          </form>
        </fieldset>

        <fieldset>
          <legend>Featured image</legend>
          <img v-if="post.featured_media" :src="post.featured_media.thumb ?? `/uploads/${post.featured_media.path}`" alt="" class="featured-thumb">
          <div class="row-actions">
            <button type="button" class="button small" @click="picker = 'featured'">{{ post.featured_media ? 'Change' : 'Choose' }}</button>
            <button v-if="post.featured_media" type="button" class="link-button danger" @click="post.featured_media_id = null; post.featured_media = null">Remove</button>
          </div>
        </fieldset>

        <fieldset v-if="post.type === 'page'">
          <legend>Page</legend>
          <label class="field"><span>Parent page</span>
            <select v-model="post.parent_id">
              <option :value="null">None (top level)</option>
              <option v-for="page in pages.filter((p) => p.id !== post.id)" :key="page.id" :value="Number(page.id)">{{ page.title }}</option>
            </select>
          </label>
          <label class="field"><span>Template</span>
            <select v-model="post.template">
              <option value="">Default</option>
              <option value="contact">Contact form below the text</option>
            </select>
          </label>
        </fieldset>

        <fieldset>
          <legend>Summary &amp; sharing</legend>
          <label class="field"><span>Excerpt</span>
            <textarea v-model="post.excerpt" rows="3" placeholder="Shown in the blog list. Defaults to the first 55 words."></textarea>
          </label>
          <label class="field"><span>Search &amp; social description</span>
            <textarea v-model="post.meta_description" rows="2" maxlength="300" placeholder="Defaults to the excerpt."></textarea>
          </label>
        </fieldset>

        <fieldset v-if="post.id">
          <legend>Revisions</legend>
          <button v-if="revisions === null" type="button" class="button small" @click="loadRevisions">Show earlier versions</button>
          <p v-else-if="revisions.length === 0" class="muted small">None yet. A copy is kept each time you save.</p>
          <ul v-else class="revisions">
            <li v-for="rev in revisions" :key="rev.id">
              <button type="button" class="link-button" @click="restore(rev)">{{ formatDate(rev.created_at) }} {{ rev.created_at.slice(11, 16) }}</button>
            </li>
          </ul>
        </fieldset>
      </aside>
    </div>

    <MediaPicker v-if="picker" :images-only="picker === 'featured'" @choose="chooseMedia" @close="picker = null" />
  </div>
  <p v-else class="muted">Loading…</p>
</template>
