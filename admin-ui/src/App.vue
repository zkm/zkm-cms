<script setup>
import { ref } from 'vue'
import { signOut } from './api.js'
import { notice } from './notice.js'

const userName = document.getElementById('app')?.dataset.user ?? ''
const navOpen = ref(false)

const nav = [
  { to: '/', label: 'Dashboard', exact: true },
  { to: '/posts', label: 'Posts' },
  { to: '/pages', label: 'Pages' },
  { to: '/media', label: 'Media' },
  { to: '/terms', label: 'Categories & tags' },
  { to: '/menus', label: 'Menus' },
  { to: '/redirects', label: 'Redirects' },
  { to: '/messages', label: 'Messages' },
  { to: '/settings', label: 'Settings' },
  { to: '/account', label: 'Account' },
]
</script>

<template>
  <div class="shell" :class="{ 'nav-open': navOpen }">
    <header class="topbar">
      <button class="nav-toggle" type="button" :aria-expanded="navOpen" aria-controls="admin-nav" @click="navOpen = !navOpen">☰<span class="sr-only">Menu</span></button>
      <RouterLink to="/" class="brand">zkm-cms</RouterLink>
      <div class="topbar-actions">
        <a href="/" target="_blank" rel="noopener">View site ↗</a>
        <RouterLink to="/account" class="user">{{ userName }}</RouterLink>
        <button type="button" class="link-button" @click="signOut">Sign out</button>
      </div>
    </header>
    <nav id="admin-nav" class="sidenav" aria-label="Admin" @click="navOpen = false">
      <RouterLink v-for="item in nav" :key="item.to" :to="item.to" :exact-active-class="item.exact ? 'active' : ''" :active-class="item.exact ? '' : 'active'">{{ item.label }}</RouterLink>
    </nav>
    <main class="content">
      <RouterView />
    </main>
    <!-- Live regions stay in the page; screen readers miss one that appears with its text.
         Errors go in an alert, so they're read at once rather than queued. -->
    <div role="status">
      <p v-if="notice.text && notice.kind !== 'error'" class="notice" :class="notice.kind">{{ notice.text }}</p>
    </div>
    <div role="alert">
      <p v-if="notice.text && notice.kind === 'error'" class="notice error">{{ notice.text }}</p>
    </div>
  </div>
</template>
