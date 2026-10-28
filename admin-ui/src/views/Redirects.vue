<script setup>
import { onMounted, ref } from 'vue'
import { api } from '../api.js'
import { flash } from '../notice.js'

const rows = ref([])
const form = ref({ from_path: '', to_path: '', code: 301 })

async function load() {
  rows.value = await api.get('redirects')
}

async function add() {
  try {
    rows.value = await api.post('redirects', form.value)
    form.value = { from_path: '', to_path: '', code: 301 }
    flash('Saved')
  } catch (e) {
    flash(e.message, 'error')
  }
}

async function remove(row) {
  rows.value = await api.del(`redirects/${row.id}`)
}

onMounted(load)
</script>

<template>
  <h1>Redirects</h1>
  <p class="muted">Old URLs that should send visitors somewhere else. Renaming a published post adds one automatically.</p>
  <form class="panel inline-form" @submit.prevent="add">
    <label class="field"><span>From path</span><input v-model="form.from_path" placeholder="/old/path/" required></label>
    <label class="field"><span>To</span><input v-model="form.to_path" placeholder="/new/path/ or https://…" :required="form.code !== 410"></label>
    <label class="field"><span>Type</span>
      <select v-model.number="form.code">
        <option :value="301">301 moved permanently</option>
        <option :value="302">302 temporary</option>
        <option :value="410">410 gone</option>
      </select>
    </label>
    <button class="button primary" type="submit">Add</button>
  </form>
  <table class="list">
    <thead><tr><th>From</th><th>To</th><th>Type</th><th>Hits</th><th></th></tr></thead>
    <tbody>
      <tr v-for="row in rows" :key="row.id">
        <td>{{ row.from_path }}</td>
        <td>{{ row.to_path }}</td>
        <td>{{ row.code }}</td>
        <td>{{ row.hits }}</td>
        <td class="actions"><button type="button" class="link-button danger" @click="remove(row)">Delete</button></td>
      </tr>
      <tr v-if="rows.length === 0"><td colspan="5" class="muted">No redirects yet.</td></tr>
    </tbody>
  </table>
</template>
