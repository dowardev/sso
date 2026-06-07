import { toastController } from '@ionic/vue';

type ToastColor = 'success' | 'danger' | 'warning' | 'primary';

interface ToastParams {
  message: string;
  duration?: number;
  color?: ToastColor;
  position?: 'top' | 'bottom' | 'middle';
}

async function showToast(params: ToastParams) {
  const { message, duration = 3500, color = 'primary', position = 'bottom' } = params;
  const toast = await toastController.create({
    message,
    duration,
    color,
    position,
  });
  await toast.present();
}

export function useToast() {
  async function showSuccess(params: { message: string }) {
    await showToast({ message: params.message, color: 'success' });
  }

  async function showError(params: { message: string }) {
    await showToast({ message: params.message, color: 'danger' });
  }

  async function showWarning(params: { message: string }) {
    await showToast({ message: params.message, color: 'warning' });
  }

  async function showInfo(params: { message: string }) {
    await showToast({ message: params.message, color: 'primary' });
  }

  return { showSuccess, showError, showWarning, showInfo };
}
