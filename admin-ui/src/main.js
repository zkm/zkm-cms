import { createApp } from 'vue'
import { createRouter, createWebHistory } from 'vue-router'
import App from './App.vue'
import './admin.css'

const routes = [
  { path: '/', component: () => import('./views/Dashboard.vue'), meta: { title: 'Dashboard' } },
  { path: '/posts', component: () => import('./views/PostList.vue'), props: { type: 'post' }, meta: { title: 'Posts' } },
  { path: '/pages', component: () => import('./views/PostList.vue'), props: { type: 'page' }, meta: { title: 'Pages' } },
  { path: '/posts/new', component: () => import('./views/PostEdit.vue'), props: { type: 'post' }, meta: { title: 'New post' } },
  { path: '/pages/new', component: () => import('./views/PostEdit.vue'), props: { type: 'page' }, meta: { title: 'New page' } },
  { path: '/edit/:id(\\d+)', component: () => import('./views/PostEdit.vue'), props: (r) => ({ id: Number(r.params.id) }), meta: { title: 'Edit' } },
  { path: '/media', component: () => import('./views/MediaLibrary.vue'), meta: { title: 'Media' } },
  { path: '/terms', component: () => import('./views/Terms.vue'), meta: { title: 'Categories & tags' } },
  { path: '/menus', component: () => import('./views/Menus.vue'), meta: { title: 'Menus' } },
  { path: '/redirects', component: () => import('./views/Redirects.vue'), meta: { title: 'Redirects' } },
  { path: '/messages', component: () => import('./views/Messages.vue'), meta: { title: 'Messages' } },
  { path: '/settings', component: () => import('./views/Settings.vue'), meta: { title: 'Settings' } },
  { path: '/account', component: () => import('./views/Account.vue'), meta: { title: 'Account' } },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

const router = createRouter({ history: createWebHistory('/admin/'), routes })

router.afterEach((to) => {
  document.title = `${to.meta.title ?? 'Admin'} – zkm-cms`
})

createApp(App).use(router).mount('#app')
