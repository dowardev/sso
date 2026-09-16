// SPDX-License-Identifier: MIT
import { createRouter, createWebHistory } from '@ionic/vue-router';
import type { RouteRecordRaw } from 'vue-router';
import { useAuthStore } from '@/stores/auth';

const routes: Array<RouteRecordRaw> = [
  {
    path: '/',
    redirect: '/login',
  },
  {
    path: '/login',
    name: 'AdminLogin',
    component: () => import('../views/LoginPage.vue'),
  },
  {
    path: '/setup',
    name: 'Setup',
    component: () => import('../views/SetupPage.vue'),
  },
  {
    path: '/register',
    name: 'AdminRegister',
    component: () => import('../views/RegisterPage.vue'),
  },
  {
    path: '/auth/login',
    name: 'AuthLogin',
    component: () => import('../views/PublicLoginPage.vue'),
  },
  {
    path: '/auth/register',
    name: 'AuthRegister',
    component: () => import('../views/PublicRegisterPage.vue'),
  },
  {
    path: '/verify-email',
    name: 'VerifyEmailPending',
    component: () => import('../views/VerifyEmailPendingPage.vue'),
  },
  {
    path: '/verify/:token',
    name: 'VerifyEmail',
    component: () => import('../views/VerifyEmailPage.vue'),
  },
  {
    path: '/recovery',
    name: 'recovery',
    component: () => import('../views/RecoveryPage.vue'),
  },
  {
    path: '/reset-password/:token',
    name: 'reset-password',
    component: () => import('../views/RecoveryPage.vue'),
  },
  {
    path: '/auth/logout',
    name: 'AuthLogout',
    component: () => import('../views/LogoutPage.vue'),
  },
  {
    path: '/auth/authorize',
    name: 'OAuthAuthorize',
    component: () => import('../views/OAuthAuthorizationPage.vue'),
  },
  {
    path: '/auth/callback',
    redirect: 'http://localhost:5173/auth/callback',
  },
{
  path: '/user/profile',
  redirect: '/account',
},
{
  path: '/account',
  name: 'Account',
  component: () => import('../views/user/AccountPage.vue'),
  meta: { requiresAuth: true },
},
  {
    path: '/home',
    name: 'Home',
    component: () => import('../views/HomePage.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/admin',
    component: () => import('../views/admin/AdminLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      {
        path: '',
        redirect: 'dashboard',
      },
      {
        path: 'dashboard',
        name: 'AdminDashboard',
        component: () => import('../views/admin/DashboardPage.vue'),
      },
      {
        path: 'users',
        name: 'AdminUsers',
        component: () => import('../views/admin/UsersPage.vue'),
      },
      {
        path: 'users/:id',
        name: 'AdminUserDetail',
        component: () => import('../views/admin/UserDetailPage.vue'),
      },
      {
        path: 'sessions',
        name: 'AdminSessions',
        component: () => import('../views/admin/SessionsPage.vue'),
      },
      {
        path: 'clients',
        name: 'AdminClients',
        component: () => import('../views/admin/ClientsPage.vue'),
      },
{
  path: '/admin/clients/by-client-id/:clientId',
  name: 'AdminClientByClientId',
  meta: { requiresAuth: true },
  component: () => import('../views/admin/ClientByClientIdRedirect.vue'),
},
{
  path: '/admin/clients/:id',
  name: 'AdminClientDetail',
  component: () => import('../views/admin/ClientDetailPage.vue'),
},
      {
        path: 'roles',
        name: 'AdminRoles',
        component: () => import('../views/admin/RolesPage.vue'),
      },
      {
        path: 'config',
        name: 'AdminConfig',
        component: () => import('../views/admin/ConfigPage.vue'),
      },
      {
        path: 'config/design',
        name: 'AdminConfigDesign',
        component: () => import('../views/admin/DesignPage.vue'),
      },
      {
        path: 'config/security',
        name: 'AdminConfigSecurity',
        component: () => import('../views/admin/SecurityPage.vue'),
      },
      {
        path: 'health',
        name: 'AdminHealth',
        component: () => import('../views/admin/HealthPage.vue'),
      },
    ],
  },
];

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
});

// Setup-check state (LBDS-265). GET /setup/status is re-queried on every
// visit to / or /login: after a DB reset the stored token may be stale while
// an old confirmation (or localStorage) still claims setup is done, so no
// confirmation is cached across navigations — only the in-flight request is
// shared to dedup concurrent guard runs.
let setupCompletedConfirmed = false;
let setupStatusRequest: Promise<boolean> | null = null;

// Single in-flight GET /setup/status shared by concurrent guard runs (LBDS-265).
// It only dedups concurrent guard executions: the confirmation is NOT cached
// across navigations, because a stored token may be stale (DB reset, revoked
// session) while an old confirmation is still in memory. Every visit to / or
// /login re-queries, so a mid-session wipe still redirects to /setup.
async function fetchSetupCompleted(): Promise<boolean> {
    if (!setupStatusRequest) {
        const { api } = await import('@/services/api');
        setupStatusRequest = api
            .get<{ setup_completed: boolean }>({ endpoint: '/setup/status' })
            .then((status) => {
                if (status.setup_completed) {
                    localStorage.setItem('setup_completed', 'true');
                    setupCompletedConfirmed = true;
                } else {
                    // Drop the stale claim so a wiped DB never looks done.
                    localStorage.removeItem('setup_completed');
                    setupCompletedConfirmed = false;
                }
                return status.setup_completed;
            })
            .finally(() => {
                setupStatusRequest = null;
            });
    }
    return setupStatusRequest;
}

router.beforeEach(async (to, from, next) => {
    const authStore = useAuthStore();
    await authStore.init();
    const requiresAuth = to.matched.some((record) => record.meta.requiresAuth);
    const isAuthRoute = to.path.startsWith('/auth/');

    if (requiresAuth && !authStore.isAuthenticated) {
        next('/login');
        return;
    }

    if (isAuthRoute) {
        next();
        return;
    }

    if (to.path === '/setup') {
        next();
        return;
    }

    if (to.path === '/login' || to.path === '/') {
        // Always re-query: a stored token may be stale while an old
        // confirmation survives in memory, skipping the /setup redirect.
        setupCompletedConfirmed = false;
        if (!setupCompletedConfirmed) {
            try {
                if (!(await fetchSetupCompleted())) {
                    next('/setup');
                    return;
                }
            } catch {
                next();
                return;
            }
        }
        if (authStore.isAuthenticated) {
            let user = authStore.user;
            if (user && (!user.roles || user.roles.length === 0)) {
                try {
                    await authStore.getUser();
                    user = authStore.user;
                } catch {
                    next('/login');
                    return;
                }
            }
            if (user && user.roles && user.roles.some((role: { slug: string }) => role.slug === 'admin')) {
                next('/admin/dashboard');
            } else {
                next('/account');
            }
            return;
        }
        next();
        return;
    }

    // Protect admin routes - only admins can access
    if (to.path.startsWith('/admin/')) {
        let user = authStore.user;
        if (user && (!user.roles || user.roles.length === 0)) {
            try {
                await authStore.getUser();
                user = authStore.user;
            } catch {
                next('/login');
                return;
            }
        }
        if (!user || !user.roles || !user.roles.some((role: { slug: string }) => role.slug === 'admin')) {
            next('/account');
            return;
        }
    }

    next();
});

export default router;
