<template>
  <slot v-if="!error" />
  <div v-else class="error-boundary" role="alert">
    <p class="error-boundary-msg">{{ $t('dashboard.error_boundary.message') }}</p>
    <button class="error-boundary-btn" @click="reset">{{ $t('dashboard.error_boundary.retry') }}</button>
  </div>
</template>

<script>
export default {
  name: 'ErrorBoundary',
  data: () => ({ error: null }),
  methods: {
    reset() {
      this.error = null;
    },
  },
  errorCaptured(err, vm, info) {
    this.error = { message: err.message, info };
    console.error('[ErrorBoundary]', err, info);
    return false;
  },
};
</script>

<style scoped>
.error-boundary {
  background: #fef2f2;
  border: 1px solid #fca5a5;
  border-radius: 8px;
  padding: 16px;
  text-align: center;
  margin: 8px 0;
}
.error-boundary-msg {
  color: #991b1b;
  font-size: 0.9rem;
  margin-bottom: 8px;
}
.error-boundary-btn {
  background: #dc2626;
  color: #fff;
  border: none;
  border-radius: 6px;
  padding: 6px 16px;
  font-size: 0.85rem;
  cursor: pointer;
}
.error-boundary-btn:hover {
  background: #b91c1c;
}
</style>
