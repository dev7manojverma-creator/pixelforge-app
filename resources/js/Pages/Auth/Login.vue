<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthLayout from '@/Layouts/AuthLayout.vue';

const form = useForm({ email: '', password: '', remember: true });
const submit = () => form.post('/login', { onFinish: () => form.reset('password') });
</script>

<template>
    <Head title="Log in" />
    <AuthLayout title="Log in to Forge Studio" subtitle="Generate images and see exactly what each one costs.">
        <form class="space-y-4" @submit.prevent="submit">
            <div>
                <label for="email" class="mb-1 block text-sm font-medium">Email</label>
                <input id="email" v-model="form.email" type="email" class="field" autocomplete="username" required autofocus />
                <p v-if="form.errors.email" class="mt-1 text-sm text-red-700">{{ form.errors.email }}</p>
            </div>
            <div>
                <label for="password" class="mb-1 block text-sm font-medium">Password</label>
                <input id="password" v-model="form.password" type="password" class="field" autocomplete="current-password" required />
                <p v-if="form.errors.password" class="mt-1 text-sm text-red-700">{{ form.errors.password }}</p>
            </div>
            <button type="submit" class="btn-primary w-full" :disabled="form.processing">
                {{ form.processing ? 'Logging in…' : 'Log in' }}
            </button>
        </form>
        <template #footer>
            New here? <Link href="/register" class="font-medium text-amber-700 hover:underline">Create an account</Link>
        </template>
    </AuthLayout>
</template>
