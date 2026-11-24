import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

// Frontend unit and component tests (assets/**/*.test.js(x)), in a simulated browser (jsdom).
export default defineConfig({
    plugins: [react()],
    test: {
        environment: 'jsdom',
        include: ['assets/**/*.test.{js,jsx}'],
        setupFiles: ['./assets/test/setup.js'],
        restoreMocks: true,
    },
});
