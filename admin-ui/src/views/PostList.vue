<script setup>
import { ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, formatDate } from '../api.js'
import { flash } from '../notice.js'

const props = defineProps({ type: { type: String, default: 'post' } })
const route = useRoute()
const router = useRouter()

const result = ref(null)
const status = ref(route.query.status ?? '')
const q = ref(route.query.q ?? '')
const page = ref(Number(route.query.page ?? 1))

async function load() {
  result.value = await api.get('posts', { type: props.type, status: status.value, q: q.value, page: page.value })
}

function search() {
  page.value = 1
  router.replace({ query: { status: status.value || undefined, q: q.value || undefined } })
  load()
}

async function remove(post) {
  if (!confirm(`Delete “${post.title}”? This can't be undone.`)) return
  await api.del(`posts/${post.id}`)
  flash('Deleted')
  load()
}

watch(() => props.type, () => { status.value = ''; q.value = ''; page.value = 1; load() }, { immediate: true })
watch(page, load)
</script>

<template>
  <div class="page-head">
    <h1>{{ type === 'page' ? 'Pages' : 'Posts' }}</h1>
    <RouterLink class="button primary" :to="type === 'page' ? '/pages/new' : '/posts/new'">New {{ type }}</RouterLink>
  </div>
  <form class="filters" @submit.prevent="search">
    <select v-model="status" @change="search" aria-label="Status">
      <option value="">All statuses</option>
      <option value="published">Published</option>
      <option value="draft">Draft</option>
      <option value="pending">Pending</option>
      <option value="private">Private</option>
    </select>
    <input v-model="q" type="search" placeholder="Search titles and text" aria-label="Search">
    <button class="button" type="submit">Search</button>
  </form>
  <template v-if="result">
    <p class="muted">{{ result.total }} {{ type === 'page' ? 'pages' : 'posts' }}</p>
    <table class="list">
      <thead>
        <tr><th>Title</th><th v-if="type === 'post'">Category</th><th>Status</th><th>Date</th><th></th></tr>
      </thead>
      <tbody>
        <tr v-for="post in result.items" :key="post.id">
          <td>
            <RouterLink :to="`/edit/${post.id}`">{{ post.title }}</RouterLink>
            <div class="muted small">{{ post.url }}</div>
          </td>
          <td v-if="type === 'post'">{{ post.category ?? '—' }}</td>
          <td><span class="badge" :class="post.status">{{ post.status }}</span></td>
          <td class="muted">{{ formatDate(post.published_at ?? post.updated_at) }}</td>
          <td class="actions">
            <a v-if="post.status === 'published'" :href="post.url" target="_blank" rel="noopener">View</a>
            <button type="button" class="link-button danger" @click="remove(post)">Delete</button>
          </td>
        </tr>
      </tbody>
    </table>
    <nav v-if="result.pages > 1" class="pager">
      <button class="button" :disabled="page <= 1" @click="page--">← Newer</button>
      <span>Page {{ page }} of {{ result.pages }}</span>
      <button class="button" :disabled="page >= result.pages" @click="page++">Older →</button>
    </nav>
  </template>
</template>
