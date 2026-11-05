import { defineConfig } from "vite";
import Symfony from "@symfony/reprise/vite";
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        react(),
        Symfony({
            stimulus: "assets/controllers.json"
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
