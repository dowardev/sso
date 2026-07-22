import { defineConfig } from "vite";
import { resolve } from "path";

export default defineConfig({
  resolve: {
    alias: {
      "@dowardev/sso-client": resolve(
        __dirname,
        "../packages/sso-client/src/index.ts"
      ),
    },
  },
  optimizeDeps: {
    exclude: ["@dowardev/sso-client"],
  },
  server: {
    host: "0.0.0.0",
    port: 5174,
  },
});