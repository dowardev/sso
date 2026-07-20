import { onMounted, onUnmounted, type Ref } from 'vue'

export function useFocusTrap(params: { containerRef: Ref<HTMLElement | null>; active: Ref<boolean> }) {
  const { containerRef, active } = params
  let previousActiveElement: HTMLElement | null = null

  function getFocusableElements(): HTMLElement[] {
    if (!containerRef.value) return []
    const selectors = [
      'a[href]',
      'button:not([disabled])',
      'textarea:not([disabled])',
      'input:not([disabled])',
      'select:not([disabled])',
      '[tabindex]:not([tabindex="-1"])',
      'ion-button:not([disabled])',
    ]
    return Array.from(
      containerRef.value.querySelectorAll<HTMLElement>(selectors.join(', '))
    )
  }

  function handleKeydown(event: KeyboardEvent) {
    if (!active.value) return

    if (event.key === 'Escape') {
      return
    }

    if (event.key !== 'Tab') return

    const focusable = getFocusableElements()
    if (focusable.length === 0) return

    const first = focusable[0]
    const last = focusable[focusable.length - 1]

    if (event.shiftKey) {
      if (document.activeElement === first) {
        event.preventDefault()
        last.focus()
      }
    } else {
      if (document.activeElement === last) {
        event.preventDefault()
        first.focus()
      }
    }
  }

  function trap() {
    previousActiveElement = document.activeElement as HTMLElement | null
    const focusable = getFocusableElements()
    if (focusable.length > 0) {
      focusable[0].focus()
    }
    document.addEventListener('keydown', handleKeydown)
  }

  function release() {
    document.removeEventListener('keydown', handleKeydown)
    if (previousActiveElement && typeof previousActiveElement.focus === 'function') {
      previousActiveElement.focus()
    }
    previousActiveElement = null
  }

  onMounted(() => {
    if (active.value) trap()
  })

  onUnmounted(() => {
    release()
  })

  return { trap, release }
}
