<script setup>
import { computed, onMounted, ref } from 'vue'
import { api, formatDate } from '../api.js'

const data = ref(null)

onMounted(async () => {
  data.value = await api.get('dashboard')
})

const count = (type, status) => Number(data.value?.counts.find((c) => c.type === type && c.status === status)?.n ?? 0)
const cards = computed(() => [
  { label: 'Published posts', value: count('post', 'published'), to: '/posts?status=published' },
  { label: 'Drafts', value: count('post', 'draft') + count('post', 'pending'), to: '/posts?status=draft' },
  { label: 'Private posts', value: count('post', 'private'), to: '/posts?status=private' },
  { label: 'Pages', value: ['published', 'draft', 'pending', 'private'].reduce((n, s) => n + count('page', s), 0), to: '/pages' },
  { label: 'Unread messages', value: data.value?.unread ?? 0, to: '/messages' },
])
</script>

<template>
  <h1>Dashboard</h1>
  <div v-if="data">
    <div class="cards">
      <RouterLink v-for="card in cards" :key="card.label" :to="card.to" class="card">
        <strong>{{ card.value }}</strong>
        <span>{{ card.label }}</span>
      </RouterLink>
    </div>
    <div class="row-actions">
      <RouterLink class="button primary" to="/posts/new">Write a post</RouterLink>
      <RouterLink class="button" to="/pages/new">New page</RouterLink>
    </div>
    <h2>Recently edited</h2>
    <table class="list">
      <tbody>
        <tr v-for="post in data.recent" :key="post.id">
          <td><RouterLink :to="`/edit/${post.id}`">{{ post.title }}</RouterLink></td>
          <td>{{ post.type }}</td>
          <td><span class="badge" :class="post.status">{{ post.status }}</span></td>
          <td class="muted">{{ formatDate(post.updated_at) }}</td>
        </tr>
      </tbody>
    </table>
  </div>
  <p v-else class="muted">Loading…</p>
</template>
