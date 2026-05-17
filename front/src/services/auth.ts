import { useAuthStore } from '@/stores/auth';

export const auth = {
  get isAuthenticated() {
    return useAuthStore().isAuthenticated;
  },

  get currentUser() {
    return useAuthStore().user;
  },

  async login(credentials: {
    user: string;
    password: string;
    client_id?: string;
  }) {
    return useAuthStore().login(credentials);
  },

  async logout() {
    return useAuthStore().logout();
  },

  async getUser() {
    return useAuthStore().getUser();
  },

  getToken() {
    return useAuthStore().getToken();
  },

  getDpopPublicKey() {
    return useAuthStore().getDpopPublicKey();
  },

  init() {
    return useAuthStore().init();
  },
};
