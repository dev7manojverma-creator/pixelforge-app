<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthLayout from '@/Layouts/AuthLayout.vue';

const form = useForm({ name: '', email: '', password: '', password_confirmation: '' });
const submit = () => form.post('/register', { onFinish: () => form.reset('password', 'password_confirmation') });
</script>

<template>
    <Head title="Create an account" />
    <AuthLayout title="Create your account" subtitle="It takes a few seconds. No card needed.">
        <form class="space-y-4" @submit.prevent="submit">
            <div>
                <label for="name" class="mb-1 block text-sm font-medium">Name</label>
                <input id="name" v-model="form.name" type="text" class="field" autocomplete="name" required autofocus />
                <p v-if="form.errors.name" class="mt-1 text-sm text-red-700">{{ form.errors.name }}</p>
            </div>
            <div>
                <label for="email" class="mb-1 block text-sm font-medium">Email</label>
                <input id="email" v-model="form.email" type="email" class="field" autocomplete="username" required />
                <p v-if="form.errors.email" class="mt-1 text-sm text-red-700">{{ form.errors.email }}</p>
            </div>
            <div>
                <label for="password" class="mb-1 block text-sm font-medium">Password</label>
                <input id="password" v-model="form.password" type="password" class="field" autocomplete="new-password" required />
                <p v-if="form.errors.password" class="mt-1 text-sm text-red-700">{{ form.errors.password }}</p>
                <p v-else class="mt-1 text-xs text-stone-500">At least 8 characters.</p>
            </div>
            <div>
                <label for="password_confirmation" class="mb-1 block text-sm font-medium">Confirm password</label>
                <input id="password_confirmation" v-model="form.password_confirmation" type="password" class="field" autocomplete="new-password" required />
            </div>
            <button type="submit" class="btn-primary w-full" :disabled="form.processing">
                {{ form.processing ? 'Creating account…' : 'Create account' }}
            </button>
        </form>
        <template #footer>
            Already have an account? <Link href="/login" class="font-medium text-amber-700 hover:underline">Log in</Link>
        </template>
    </AuthLayout>
</template>
