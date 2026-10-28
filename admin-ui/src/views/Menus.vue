<script setup>
import { onMounted, ref } from 'vue'
import { api } from '../api.js'
import { flash } from '../notice.js'

const menus = ref(null)
const targets = ref([])

async function load() {
  const data = await api.get('menus')
  targets.value = data.targets

  for (const menu of Object.values(data.menus)) {
    menu.items = menu.items.map((i) => ({ label: i.label, post_id: i.post_id ? Number(i.post_id) : null, url: i.url ?? '' }))
  }

  menus.value = data.menus
}

function move(items, index, delta) {
  const target = index + delta
  if (target < 0 || target >= items.length) return
  ;[items[index], items[target]] = [items[target], items[index]]
}

async function save(location) {
  try {
    await api.put(`menus/${location}`, { items: menus.value[location].items })
    flash('Menu saved')
  } catch (e) {
    flash(e.message, 'error')
  }
}

onMounted(load)
</script>

<template>
  <h1>Menus</h1>
  <template v-if="menus">
    <section v-for="(menu, location) in menus" :key="location" class="panel">
      <h2>{{ menu.label }}</h2>
      <p v-if="location === 'social'" class="muted small">The icon is picked from the URL (GitHub, LinkedIn, Mastodon, X, Instagram, Stack Overflow).</p>
      <table class="list">
        <thead><tr><th>Label</th><th>Links to</th><th></th></tr></thead>
        <tbody>
          <tr v-for="(item, i) in menu.items" :key="i">
            <td><input v-model="item.label" :placeholder="item.post_id ? 'Page title' : 'Label'" aria-label="Label"></td>
            <td class="menu-target">
              <select v-model="item.post_id" aria-label="Page">
                <option :value="null">Custom URL…</option>
                <option v-for="t in targets" :key="t.id" :value="t.id">{{ t.type === 'page' ? 'Page' : 'Post' }}: {{ t.title }}</option>
              </select>
              <input v-if="!item.post_id" v-model="item.url" placeholder="https://… or /path/" aria-label="URL">
            </td>
            <td class="actions">
              <button type="button" class="link-button" :disabled="i === 0" aria-label="Move up" @click="move(menu.items, i, -1)">↑</button>
              <button type="button" class="link-button" :disabled="i === menu.items.length - 1" aria-label="Move down" @click="move(menu.items, i, 1)">↓</button>
              <button type="button" class="link-button danger" @click="menu.items.splice(i, 1)">Remove</button>
            </td>
          </tr>
        </tbody>
      </table>
      <div class="row-actions">
        <button type="button" class="button" @click="menu.items.push({ label: '', post_id: null, url: '' })">Add link</button>
        <button type="button" class="button primary" @click="save(location)">Save {{ menu.label.toLowerCase() }} menu</button>
      </div>
    </section>
  </template>
</template>
