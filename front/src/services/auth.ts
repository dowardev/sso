import { ref } from 'vue';
import { api } from './api';
import type { User } from './api';

const TOKEN_KEY = 'token';
const USER_KEY = 'user';

export const isAuthenticated = ref<boolean>(!!localStorage.getItem(TOKEN_KEY));
export const currentUser = ref<User | null>(null);

export const auth = {
  async login(credentials: { user: string; password: string }) {
    const response = await api.post<{ token: string; user: User }>('/login', credentials);
    
    localStorage.setItem(TOKEN_KEY, response.token);
    localStorage.setItem(USER_KEY, JSON.stringify(response.user));
    
    isAuthenticated.value = true;
    currentUser.value = response.user;
    
    return response;
  },

  async logout() {
    try {
      await api.post('/logout');
    } catch (error) {
      console.error('Logout error:', error);
    }
    
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(USER_KEY);
    
    isAuthenticated.value = false;
    currentUser.value = null;
  },

  async getUser() {
    try {
      const response = await api.get<User>('/me');
      currentUser.value = response;
      return response;
    } catch (error) {
      this.logout();
      throw error;
    }
  },

  getToken(): string | null {
    return localStorage.getItem(TOKEN_KEY);
  },

  init() {
    const token = localStorage.getItem(TOKEN_KEY);
    const userStr = localStorage.getItem(USER_KEY);
    
    if (token && userStr) {
      isAuthenticated.value = true;
      currentUser.value = JSON.parse(userStr);
    }
  }
};

auth.init();
