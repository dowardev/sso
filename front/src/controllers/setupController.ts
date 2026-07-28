// SPDX-License-Identifier: MIT
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

export function useSetupController() {
  async function getStatus(): Promise<SetupStatus> {
    return api.get<SetupStatus>({ endpoint: '/setup/status' });
  }

  async function createAdmin(params: SetupAdminParams): Promise<void> {
    await api.post({
      endpoint: '/setup/admin',
      data: params,
    });
  }

  return {
    getStatus,
    createAdmin,
  };
}