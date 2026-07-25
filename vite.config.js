// @lovable.dev/vite-tanstack-config already includes:
// TanStack Start, React, Tailwind, aliases, devtools, and Nitro.
// Do not add those plugins manually.

import { defineConfig } from "@lovable.dev/vite-tanstack-config";

const LARAVEL_BACKEND = "http://127.0.0.1:8000";

export default defineConfig({
  tanstackStart: {
    server: {
      entry: "server",
    },
  },

  vite: {
    server: {
      proxy: {
        "/api": {
          target: LARAVEL_BACKEND,
          changeOrigin: true,
          secure: false,
        },

        "/sanctum": {
          target: LARAVEL_BACKEND,
          changeOrigin: true,
          secure: false,
        },

        "/auth": {
          target: LARAVEL_BACKEND,
          changeOrigin: true,
          secure: false,
        },

        "/storage": {
          target: LARAVEL_BACKEND,
          changeOrigin: true,
          secure: false,
        },
      },
    },
  },
});