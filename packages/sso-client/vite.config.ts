import { defineConfig } from "vite";
import { resolve } from "path";
import dts from "vite-plugin-dts";

export default defineConfig({
  plugins: [dts({ rollupTypes: true })],
  // Relative base so emitted worker URLs resolve against this package's own
  // dist/ when bundled by consumers (absolute "/assets/..." breaks PWA builds).
  base: "./",
  build: {
    lib: {
      entry: resolve(__dirname, "src/index.ts"),
      name: "SSOClient",
      fileName: "index",
      formats: ["es", "umd"],
    },
    rollupOptions: {
      external: ["axios"],
      output: {
        globals: {
          axios: "axios",
        },
      },
    },
  },
});