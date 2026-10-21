import { defineConfig } from "vite";
import laravel from "laravel-vite-plugin";
import vue from "@vitejs/plugin-vue";

// Docker (Windows / Mac) does not send file-change events from your PC into the
// container, so Vite must "poll" (check files every few ms). docker-compose.yml
// sets CHOKIDAR_USEPOLLING=true. Running without Docker -> polling stays off.
const usePolling = process.env.CHOKIDAR_USEPOLLING === "true";

export default defineConfig({
  plugins: [
    laravel({
      input: ["resources/js/app.js"],
      // reload the browser when a blade / route file changes
      refresh: ["resources/views/**", "routes/**"],
    }),
    vue({
      template: { transformAssetUrls: { base: null, includeAbsolute: false } },
    }),
  ],
  server: {
    host: "0.0.0.0",
    port: 5173,
    strictPort: true,
    // the browser loads JS/CSS from here (this is what public/hot points to)
    origin: "http://localhost:5173",
    cors: true,
    // hot reload websocket: the browser connects to localhost:5173
    hmr: { host: "localhost", clientPort: 5173 },
    watch: {
      usePolling,
      interval: 300,
      ignored: ["**/vendor/**", "**/storage/**", "**/node_modules/**"],
    },
  },
});