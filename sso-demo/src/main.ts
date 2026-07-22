// SPDX-License-Identifier: MIT
import { SSOClient } from "@dowardev/sso-client";
import { initApp } from "./app";

let ssoClient: SSOClient;

async function bootstrap() {
  ssoClient = new SSOClient({
    ssoUrl: import.meta.env.VITE_SSO_URL,
    ssoFrontendUrl: import.meta.env.VITE_SSO_FRONTEND_URL,
    clientId: import.meta.env.VITE_SSO_CLIENT_ID,
    apiBaseUrl: "",
  });

  await ssoClient.init();

  ssoClient.onAuthStateChange((state) => {
    initApp(ssoClient, state);
  });
}

bootstrap().catch(console.error);

export { ssoClient };