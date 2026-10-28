<script setup>
import { onMounted, ref } from 'vue'
import { api, formatDate } from '../api.js'

const messages = ref([])
const open = ref(null)

async function load() {
  messages.value = await api.get('messages')
}

async function show(message) {
  open.value = open.value === message.id ? null : message.id
  if (!message.read_at) {
    await api.put(`messages/${message.id}/read`)
    message.read_at = new Date().toISOString()
  }
}

async function remove(message) {
  if (!confirm('Delete this message?')) return
  await api.del(`messages/${message.id}`)
  load()
}

onMounted(load)
</script>

<template>
  <h1>Messages</h1>
  <p class="muted">Sent through the contact form. Each one is also emailed to the contact address in Settings.</p>
  <ul class="messages">
    <li v-for="m in messages" :key="m.id" :class="{ unread: !m.read_at }">
      <button type="button" class="message-head" :aria-expanded="open === m.id" @click="show(m)">
        <strong>{{ m.name }}</strong> <span class="muted">&lt;{{ m.email }}&gt;</span>
        <span class="muted push">{{ formatDate(m.created_at) }}<span v-if="m.emailed === '0' || m.emailed === 0"> · email failed</span></span>
      </button>
      <div v-if="open === m.id" class="message-body">
        <p class="pre">{{ m.message }}</p>
        <div class="row-actions">
          <a class="button" :href="`mailto:${m.email}?subject=${encodeURIComponent('Re: your message')}`">Reply</a>
          <button type="button" class="link-button danger" @click="remove(m)">Delete</button>
        </div>
      </div>
    </li>
    <li v-if="messages.length === 0" class="muted">No messages.</li>
  </ul>
</template>
