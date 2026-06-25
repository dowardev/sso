import { createRouter, createWebHistory } from '@ionic/vue-router';
import { RouteRecordRaw } from 'vue-router';
import HomePage from '../views/HomePage.vue';
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
    component: HomePage,
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
    ],
  },
];

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
});

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

    if (
        (to.path === '/login' || to.path === '/') &&
        authStore.isAuthenticated
    ) {
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

    if (to.path === '/login' || to.path === '/') {
        const setupCompleted = localStorage.getItem('setup_completed');
        if (setupCompleted === 'true') {
            next();
            return;
        }
        try {
            const { api } = await import('@/services/api');
            const status = await api.get<{ setup_completed: boolean }>({ endpoint: '/setup/status' });
            if (!status.setup_completed) {
                next('/setup');
            } else {
                localStorage.setItem('setup_completed', 'true');
                next();
            }
        } catch {
            next();
        }
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
