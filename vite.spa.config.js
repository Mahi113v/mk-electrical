import { defineConfig } from "vite";
import vue from "@vitejs/plugin-vue";

// Standalone build (no Laravel): `npm run build:spa` -> dist/  (used for Netlify)
export default defineConfig({
  plugins: [vue()],
  publicDir: "public-spa",
  build: { outDir: "dist", emptyOutDir: true },
});
