<script setup>
import { ref } from 'vue'

// A real button for a hidden file input, so the keyboard can reach it. While busy it is
// aria-disabled rather than disabled, so it keeps focus when an upload starts.
const props = defineProps({
  accept: { type: String, default: undefined },
  multiple: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
})
const emit = defineEmits(['files'])
const input = ref(null)

function open() {
  if (!props.busy) input.value.click()
}

function picked(event) {
  const files = [...event.target.files]
  // Cleared, so choosing the same file again still fires change.
  event.target.value = ''
  if (files.length) emit('files', files)
}
</script>

<template>
  <button type="button" class="button primary" :aria-disabled="busy ? 'true' : undefined" @click="open"><slot /></button>
  <input ref="input" type="file" :accept="accept" :multiple="multiple" hidden @change="picked">
</template>
