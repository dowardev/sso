// SPDX-License-Identifier: MIT
import type { SSOClient } from "@tu-org/sso-client";
import type { AuthState, User } from "@tu-org/sso-client";
import "./style.css";

let ssoClient: SSOClient;
let isRedirecting = false;
let callbackProcessed = false;

function initApp(client: SSOClient, state: AuthState): void {
  ssoClient = client;

  const urlParams = new URLSearchParams(window.location.search);
  const hasAuthCode = urlParams.has("code");

  if (hasAuthCode && !callbackProcessed) {
    callbackProcessed = true;
    showCallbackView();
    handleCallback();
    return;
  }

  if (state.isAuthenticated && state.user) {
    isRedirecting = false;
    renderUserInfo(state.user);
  } else {
    redirectToSSO();
  }
}

async function handleCallback(): Promise<void> {
  try {
    const { user } = await ssoClient.handleCallback(window.location.href);
    window.history.replaceState({}, "", window.location.pathname);
    if (user) {
      renderUserInfo(user);
    } else {
      const user2 = await ssoClient.getUser();
      renderUserInfo(user2);
    }
  } catch (e) {
    showError(`Error en autenticación: ${(e as Error).message}`);
  }
}

async function redirectToSSO(): Promise<void> {
  if (isRedirecting) return;
  isRedirecting = true;
  callbackProcessed = false;

  const app = document.getElementById("app");
  if (app) {
    app.innerHTML = `
      <div class="container">
        <div class="callback-view">
          <div class="spinner"></div>
          <p>Redirigiendo al SSO...</p>
        </div>
      </div>
    `;
  }

  await ssoClient.loginWithRedirect({
    redirectUri: `${window.location.origin}/auth/callback`,
  });
}

function showCallbackView(): void {
  const app = document.getElementById("app");
  if (!app) return;

  app.innerHTML = `
    <div class="container">
      <div class="callback-view">
        <div class="spinner"></div>
        <p>Autenticando...</p>
      </div>
    </div>
  `;
}

function renderUserInfo(user: User | null): void {
  if (!user) {
    redirectToSSO();
    return;
  }

  const app = document.getElementById("app");
  if (!app) return;

  const name = user.name || user.user || "Usuario";
  const email = user.email || "";
  const username = user.user || "";
  const roles = user.roles?.map((r) => r.name).join(", ") || "";
  const isAdmin = user.isAdmin ? "Sí" : "No";

  app.innerHTML = `
    <div class="container">
      <h1>SSO Client Demo</h1>
      <div id="userInfo">
        <div class="user-card">
          <h2>¡Bienvenido!</h2>
          
          <div class="user-field">
            <span class="label">Nombre:</span>
            <span class="value">${escapeHtml(name)}</span>
          </div>
          
          ${username ? `
          <div class="user-field">
            <span class="label">Usuario:</span>
            <span class="value">${escapeHtml(username)}</span>
          </div>
          ` : ""}
          
          <div class="user-field">
            <span class="label">Email:</span>
            <span class="value">${escapeHtml(email)}</span>
          </div>
          
          <div class="user-field">
            <span class="label">Roles:</span>
            <span class="value">${roles || "Sin roles"}</span>
          </div>
          
          <div class="user-field">
            <span class="label">Admin:</span>
            <span class="value">${isAdmin}</span>
          </div>
          
          <button id="logoutBtn" class="logout-btn">Cerrar Sesión</button>
        </div>
      </div>
    </div>
  `;

  document.getElementById("logoutBtn")?.addEventListener("click", handleLogout);
}

async function handleLogout(): Promise<void> {
  await ssoClient.logout();
  isRedirecting = false;
  callbackProcessed = false;
  renderLoginPrompt();
}

function renderLoginPrompt(): void {
  const app = document.getElementById("app");
  if (!app) return;

  app.innerHTML = `
    <div class="container">
      <h1>SSO Client Demo</h1>
      <div id="loginPrompt">
        <p class="message">Sesión cerrada</p>
        <button id="loginBtn" class="sso-btn">Iniciar sesión con SSO</button>
      </div>
    </div>
  `;

  document.getElementById("loginBtn")?.addEventListener("click", () => {
    callbackProcessed = false;
    redirectToSSO();
  });
}

function showError(message: string): void {
  const app = document.getElementById("app");
  if (!app) return;

  app.innerHTML = `
    <div class="container">
      <div class="error-view">
        <p class="error-message">${escapeHtml(message)}</p>
        <button id="retryBtn" class="retry-btn">Reintentar</button>
        <button id="homeBtn" class="home-btn">Empezar de nuevo</button>
      </div>
    </div>
  `;

  document.getElementById("retryBtn")?.addEventListener("click", () => {
    callbackProcessed = false;
    redirectToSSO();
  });

  document.getElementById("homeBtn")?.addEventListener("click", () => {
    callbackProcessed = false;
    isRedirecting = false;
    window.history.replaceState({}, "", window.location.pathname);
    renderLoginPrompt();
  });
}

function escapeHtml(text: string | undefined): string {
  if (!text) return "";
  const div = document.createElement("div");
  div.textContent = text;
  return div.innerHTML;
}

export { initApp };