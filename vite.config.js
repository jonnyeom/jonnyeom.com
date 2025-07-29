import { defineConfig } from "vite";
import symfonyPlugin from "vite-plugin-symfony";
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        react(),
        symfonyPlugin({
            stimulus: true
        }),
    ],
    build: {
        sourcemap: true,
        rollupOptions: {
            input: {
                app: "./assets/app.js",
                submodulePost: "./assets/styles/post.scss"
            },
        }
    },
});
