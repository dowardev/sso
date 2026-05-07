import { defineConfig } from "vite";
import { resolve } from "path";

export default defineConfig({
  resolve: {
    alias: {
      "@tu-org/sso-client": resolve(
        __dirname,
        "../../sso-client/packages/sso-client/src/index.ts"
      ),
    },
  },
  optimizeDeps: {
    exclude: ["@tu-org/sso-client"],
  },
  server: {
    host: "0.0.0.0",
    port: 5174,
  },
});