import { ref } from 'vue';
import { api } from '@/services/api';

interface SetupStatus {
  setup_completed: boolean;
}

interface SetupAdminParams {
  name: string;
  user: string;
  email: string;
  password: string;
  password_confirmation: string;
}

interface SetupAdminResponse {
  message: string;
  user: {
    id: number;
    name: string;
    user: string;
    email: string;
  };
}

export function useSetupController() {
  const loading = ref(false);
  const error = ref('');

  async function getStatus(): Promise<SetupStatus> {
    return api.get<SetupStatus>({ endpoint: '/setup/status' });
  }

  async function createAdmin(params: SetupAdminParams): Promise<void> {
    loading.value = true;
    error.value = '';
    try {
      await api.post<SetupAdminResponse>({
        endpoint: '/setup/admin',
        data: params,
      });
    } catch (err: unknown) {
      const message = err instanceof Error ? err.message : 'Error al crear el administrador';
      error.value = message;
      throw err;
    } finally {
      loading.value = false;
    }
  }

  return {
    loading,
    error,
    getStatus,
    createAdmin,
  };
}