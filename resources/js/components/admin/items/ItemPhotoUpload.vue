<template>
    <div
        class="item-photo-upload rounded-2xl border-2 border-dashed border-gray-300 bg-white p-5 text-center transition focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
        :class="{
            'is-dragover border-solid border-blue-500 bg-blue-50': uploadState === 'dragover',
            'is-uploading cursor-wait border-solid border-blue-400 bg-blue-50': uploadState === 'uploading',
            'is-success border-solid border-green-500 bg-green-50': uploadState === 'success',
            'is-error border-solid border-red-500 bg-red-50': uploadState === 'error',
        }"
        @dragover.prevent="onDragOver"
        @dragleave.prevent="onDragLeave"
        @drop.prevent="onDrop"
        @click="openFileDialog"
        role="button"
        tabindex="0"
        :aria-label="$t('studio.image.upload_idle')"
        @keydown.enter.prevent="openFileDialog"
        @keydown.space.prevent="openFileDialog"
    >
        <input
            ref="fileInput"
            type="file"
            :accept="acceptedMimes.join(',')"
            class="hidden"
            @change="onFileSelected"
        />

        <div v-if="uploadState === 'idle'" class="flex flex-col items-center gap-3 text-gray-600">
            <img
                v-if="previewImage"
                :src="previewImage"
                alt=""
                class="h-24 w-24 rounded-xl object-cover"
            />
            <svg
                v-else
                class="h-10 w-10 text-gray-400"
                viewBox="0 0 24 24"
                fill="none"
                aria-hidden="true"
            >
                <path
                    d="M4 8.5A2.5 2.5 0 0 1 6.5 6h1.75l1.2-1.8A1.5 1.5 0 0 1 10.7 3.5h2.6a1.5 1.5 0 0 1 1.25.7L15.75 6h1.75A2.5 2.5 0 0 1 20 8.5v8A2.5 2.5 0 0 1 17.5 19h-11A2.5 2.5 0 0 1 4 16.5v-8Z"
                    stroke="currentColor"
                    stroke-width="1.7"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                />
                <path
                    d="M12 15.5a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"
                    stroke="currentColor"
                    stroke-width="1.7"
                />
            </svg>
            <p class="text-sm font-medium">{{ $t('studio.image.upload_idle') }}</p>
        </div>

        <div v-else-if="uploadState === 'dragover'" class="flex flex-col items-center gap-3 text-blue-700">
            <svg class="h-10 w-10" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path
                    d="M12 16V4m0 0 4 4m-4-4L8 8M5 16.5V18a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-1.5"
                    stroke="currentColor"
                    stroke-width="1.8"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                />
            </svg>
            <p class="text-sm font-semibold">{{ $t('studio.image.upload_drag_over') }}</p>
        </div>

        <div v-else-if="uploadState === 'uploading'" class="flex flex-col items-center gap-3 text-blue-700">
            <svg class="h-8 w-8 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4Z" />
            </svg>
            <progress class="h-2 w-full max-w-xs overflow-hidden rounded-full" :value="progress" max="100" />
            <span class="text-sm font-medium">{{ $t('studio.image.upload_uploading') }} {{ progress }}%</span>
        </div>

        <div v-else-if="uploadState === 'success'" class="flex flex-col items-center gap-3 text-green-700">
            <svg class="h-10 w-10" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path
                    d="m5 12.5 4.2 4.2L19 6.8"
                    stroke="currentColor"
                    stroke-width="2.2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                />
            </svg>
            <p class="text-sm font-semibold">{{ $t('studio.image.upload_success') }}</p>
        </div>

        <div v-else-if="uploadState === 'error'" class="flex flex-col items-center gap-3 text-red-700">
            <p class="text-sm font-medium">{{ errorMessage }}</p>
            <button
                type="button"
                class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2"
                @click.stop="openFileDialog"
            >
                {{ $t('studio.image.upload_retry') }}
            </button>
        </div>
    </div>
</template>

<script>
import axios from 'axios';

export default {
    name: 'ItemPhotoUpload',
    props: {
        itemId: { type: Number, required: true },
        currentImage: { type: String, default: null },
        maxSizeBytes: { type: Number, default: 4 * 1024 * 1024 },
        acceptedMimes: {
            type: Array,
            default: () => ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'],
        },
    },
    emits: ['upload-success', 'upload-error'],
    data() {
        return {
            uploadState: 'idle',
            progress: 0,
            errorMessage: null,
            previewUrl: null,
            successTimer: null,
        };
    },
    computed: {
        previewImage() {
            return this.previewUrl || this.currentImage;
        },
    },
    beforeUnmount() {
        if (this.successTimer) {
            clearTimeout(this.successTimer);
        }
    },
    methods: {
        openFileDialog() {
            if (this.uploadState === 'uploading') {
                return;
            }

            this.$refs.fileInput?.click();
        },
        validateFile(file) {
            if (file.size > this.maxSizeBytes) {
                return this.$t('studio.image.upload_oversize');
            }

            if (!this.acceptedMimes.includes(file.type)) {
                return this.$t('studio.image.upload_invalid_mime');
            }

            return null;
        },
        async upload(file) {
            const error = this.validateFile(file);
            if (error) {
                this.uploadState = 'error';
                this.errorMessage = error;
                this.$emit('upload-error', error);
                return;
            }

            const formData = new FormData();
            formData.append('photo', file);

            this.uploadState = 'uploading';
            this.progress = 0;
            this.errorMessage = null;

            try {
                const response = await axios.post(`/admin/items/${this.itemId}/photo`, formData, {
                    headers: { 'Content-Type': 'multipart/form-data' },
                    onUploadProgress: (e) => {
                        if (e.total) {
                            this.progress = Math.round((e.loaded / e.total) * 100);
                        }
                    },
                });

                this.uploadState = 'success';
                this.previewUrl = response.data?.url ?? null;
                this.$emit('upload-success', response.data);
                this.successTimer = setTimeout(() => {
                    this.uploadState = 'idle';
                }, 1500);
            } catch (err) {
                this.uploadState = 'error';
                this.errorMessage = err.response?.data?.message ?? this.$t('studio.image.upload_error');
                this.$emit('upload-error', this.errorMessage);
            }
        },
        onDragOver() {
            if (this.uploadState === 'idle') {
                this.uploadState = 'dragover';
            }
        },
        onDragLeave() {
            if (this.uploadState === 'dragover') {
                this.uploadState = 'idle';
            }
        },
        onDrop(e) {
            const file = e.dataTransfer?.files?.[0];
            if (file) {
                this.upload(file);
            }
        },
        onFileSelected(e) {
            const file = e.target.files?.[0];
            if (file) {
                this.upload(file);
            }
            e.target.value = '';
        },
    },
};
</script>
