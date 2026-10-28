<script setup>
import { onMounted, reactive, ref } from 'vue'
import { api, formatDate } from '../api.js'
import { flash } from '../notice.js'

const account = ref(null)
const busy = ref('')
const errors = ref({})

const passwordForm = reactive({ current_password: '', new_password: '', confirm_password: '', code: '' })
const emailForm = reactive({ current_password: '', email: '', code: '' })
const othersForm = reactive({ current_password: '', code: '' })

onMounted(async () => {
  account.value = await api.get('account')
})

async function submit(kind, method, path, form) {
  busy.value = kind
  errors.value = {}
  try {
    const result = await api[method](path, form)
    flash(result.message ?? 'Saved')
    if (result.email) account.value.email = result.email
    for (const key of Object.keys(form)) form[key] = ''
  } catch (e) {
    errors.value = e.fields ?? {}
    flash(e.message, 'error')
  } finally {
    busy.value = ''
  }
}
</script>

<template>
  <h1>Account</h1>
  <template v-if="account">
    <section class="panel settings">
      <p><strong>{{ account.name }}</strong> signs in as <strong>{{ account.email }}</strong>.</p>
      <p class="muted small">Last sign-in: {{ formatDate(account.lastLoginAt) }} {{ account.lastLoginAt?.slice(11, 16) }}</p>
      <p v-if="account.twoFactor" class="small">Two-factor sign-in is <strong>on</strong>.</p>
      <p v-else class="small">Two-factor sign-in is <strong>off</strong>. Turn it on from the server with <code>php spark user:totp {{ account.email }}</code>.</p>
      <p class="muted small">Each change below asks for your current password{{ account.twoFactor ? ' and an authenticator code' : '' }}, and signs out every other session.</p>
    </section>

    <form class="panel settings" @submit.prevent="submit('password', 'put', 'account/password', passwordForm)">
      <h2>Change password</h2>
      <label class="field"><span>Current password</span><input v-model="passwordForm.current_password" type="password" autocomplete="current-password" required :class="{ invalid: errors.current_password && busy === '' }"></label>
      <label class="field"><span>New password (at least 12 characters)</span><input v-model="passwordForm.new_password" type="password" autocomplete="new-password" minlength="12" required></label>
      <label class="field"><span>New password again</span><input v-model="passwordForm.confirm_password" type="password" autocomplete="new-password" minlength="12" required></label>
      <label v-if="account.twoFactor" class="field"><span>Authenticator code</span><input v-model="passwordForm.code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required></label>
      <button class="button primary" type="submit" :disabled="busy !== ''">Change password</button>
    </form>

    <form class="panel settings" @submit.prevent="submit('email', 'put', 'account/email', emailForm)">
      <h2>Change sign-in email</h2>
      <p class="muted small">Use an address that isn't public, such as a private alias. Then nobody can guess your sign-in, or lock you out by spamming it with wrong passwords.</p>
      <label class="field"><span>New sign-in email</span><input v-model="emailForm.email" type="email" autocomplete="username" required></label>
      <label class="field"><span>Current password</span><input v-model="emailForm.current_password" type="password" autocomplete="current-password" required></label>
      <label v-if="account.twoFactor" class="field"><span>Authenticator code</span><input v-model="emailForm.code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required></label>
      <button class="button primary" type="submit" :disabled="busy !== ''">Change email</button>
    </form>

    <form class="panel settings" @submit.prevent="submit('others', 'post', 'account/sign-out-others', othersForm)">
      <h2>Sign out everywhere else</h2>
      <p class="muted small">Ends every session except this one, for example on a computer you no longer use.</p>
      <label class="field"><span>Current password</span><input v-model="othersForm.current_password" type="password" autocomplete="current-password" required></label>
      <label v-if="account.twoFactor" class="field"><span>Authenticator code</span><input v-model="othersForm.code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required></label>
      <button class="button" type="submit" :disabled="busy !== ''">Sign out other sessions</button>
    </form>
  </template>
</template>
