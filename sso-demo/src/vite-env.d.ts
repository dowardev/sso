// SPDX-License-Identifier: MIT
/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_SSO_URL: string;
  readonly VITE_SSO_FRONTEND_URL: string;
  readonly VITE_SSO_CLIENT_ID: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
  readonly dev: boolean;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}