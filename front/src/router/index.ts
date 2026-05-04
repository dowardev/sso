import { createRouter, createWebHistory } from '@ionic/vue-router';
import { RouteRecordRaw } from 'vue-router';
import HomePage from '../views/HomePage.vue';
import { auth, isAuthenticated } from '@/services/auth';

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
    path: '/auth/callback',
    redirect: 'http://localhost:5173/auth/callback',
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
        path: 'roles',
        name: 'AdminRoles',
        component: () => import('../views/admin/RolesPage.vue'),
      },
    ],
  },
];

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
});

router.beforeEach((to, from, next) => {
  const requiresAuth = to.matched.some((record) => record.meta.requiresAuth);
  const isAuthRoute = to.path.startsWith('/auth/');

  if (requiresAuth && !isAuthenticated.value) {
    next('/login');
  } else if (isAuthRoute) {
    next();
  } else if (
    (to.path === '/login' || to.path === '/') &&
    isAuthenticated.value
  ) {
    next('/admin/dashboard');
  } else {
    next();
  }
});

export default router;
