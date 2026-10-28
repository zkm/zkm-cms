<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../api.js'
import { flash } from '../notice.js'

const terms = ref([])
const editing = ref(null)
const tab = ref('category')

const shown = computed(() => terms.value.filter((t) => t.taxonomy === tab.value))
const categories = computed(() => terms.value.filter((t) => t.taxonomy === 'category'))

async function load() {
  terms.value = await api.get('terms')
}

function startNew() {
  editing.value = { id: null, taxonomy: tab.value, name: '', slug: '', description: '', parent_id: null }
}

async function save() {
  try {
    const { id, ...body } = editing.value
    id ? await api.put(`terms/${id}`, body) : await api.post('terms', body)
    editing.value = null
    flash('Saved')
    load()
  } catch (e) {
    flash(e.message, 'error')
  }
}

async function remove(term) {
  if (!confirm(`Delete “${term.name}”? Posts keep their other categories and tags.`)) return
  await api.del(`terms/${term.id}`)
  flash('Deleted')
  load()
}

onMounted(load)
</script>

<template>
  <div class="page-head">
    <h1>Categories &amp; tags</h1>
    <button class="button primary" type="button" @click="startNew">New {{ tab }}</button>
  </div>
  <div class="tabs" role="tablist">
    <button type="button" role="tab" :aria-selected="tab === 'category'" @click="tab = 'category'">Categories</button>
    <button type="button" role="tab" :aria-selected="tab === 'tag'" @click="tab = 'tag'">Tags</button>
  </div>
  <form v-if="editing" class="panel" @submit.prevent="save">
    <label class="field"><span>Name</span><input v-model="editing.name" required></label>
    <label class="field"><span>Slug</span><input v-model="editing.slug" placeholder="made from the name"></label>
    <label v-if="editing.taxonomy === 'category'" class="field"><span>Parent</span>
      <select v-model="editing.parent_id">
        <option :value="null">None</option>
        <option v-for="cat in categories.filter((c) => c.id !== editing.id)" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
      </select>
    </label>
    <label class="field"><span>Description</span><textarea v-model="editing.description" rows="2"></textarea></label>
    <p v-if="editing.id" class="muted small">Changing the slug changes this archive's URL{{ editing.taxonomy === 'category' ? ' and the URL of posts filed under it' : '' }}.</p>
    <div class="row-actions">
      <button class="button primary" type="submit">Save</button>
      <button class="button" type="button" @click="editing = null">Cancel</button>
    </div>
  </form>
  <table class="list">
    <thead><tr><th>Name</th><th>Slug</th><th>Posts</th><th></th></tr></thead>
    <tbody>
      <tr v-for="term in shown" :key="term.id">
        <td>{{ term.name }}</td>
        <td class="muted">{{ term.slug }}</td>
        <td>{{ term.posts }}</td>
        <td class="actions">
          <a :href="`/${term.taxonomy}/${term.slug}/`" target="_blank" rel="noopener">View</a>
          <button type="button" class="link-button" @click="editing = { ...term, description: term.description ?? '' }">Edit</button>
          <button type="button" class="link-button danger" @click="remove(term)">Delete</button>
        </td>
      </tr>
    </tbody>
  </table>
</template>
