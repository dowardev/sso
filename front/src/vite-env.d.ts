// SPDX-License-Identifier: MIT
/// <reference types="vite/client" />
/// <reference types="vite-plugin-pwa/client" />

declare module '*.worker.ts' {
  const worker: Worker;
  export default worker;
}
