export interface AuthGuardOptions {
  publicRoutes?: string[];
  loginRedirect?: boolean;
  redirectUri?: string;
}

export interface RouteLike {
  name?: string | symbol | null;
  path: string;
}

export interface NavigationGuardNext {
  (path?: string | { path: string }): void;
}

export interface SSOClientLike {
  readonly isAuthenticated: boolean;
  loginWithRedirect(options?: { redirectUri?: string; screenHint?: "login" | "register" }): Promise<void>;
}

export function createAuthGuard(
  ssoClient: SSOClientLike,
  options: AuthGuardOptions = {}
) {
  const { publicRoutes = [], loginRedirect = true, redirectUri } = options;

  return async (to: RouteLike, _from: RouteLike, next: NavigationGuardNext) => {
    const routeName = to.name ? String(to.name) : null;

    if (publicRoutes.includes(routeName || "")) {
      return next();
    }

    if (ssoClient.isAuthenticated) {
      return next();
    }

    if (loginRedirect) {
      const redirect = redirectUri || `${window.location.origin}/auth/callback`;
      await ssoClient.loginWithRedirect({ redirectUri: redirect });
      return next("/");
    }

    return next("/");
  };
}
