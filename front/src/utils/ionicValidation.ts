// SPDX-License-Identifier: MIT

/**
 * Ionic 8 exposes its native validation surface through two host classes:
 * `ion-touched` and `ion-invalid`.
 *
 * Ionic's `checkInvalidState()` requires BOTH classes to flag a field as
 * invalid, and its `MutationObserver` watches the host `class` attribute to
 * recompute the state. Ionic then sets `aria-invalid` on the native input and
 * applies its own invalid styling.
 *
 * This utility maps Vuelidate's `$dirty` to `ion-touched` and `$invalid` to
 * `ion-invalid`. Because Ionic only flags a field invalid when BOTH classes
 * are present, this utility flags a field exactly when it is dirty AND invalid
 * (Vuelidate's `$error`), which is the same condition the app uses to render
 * its error messages.
 *
 * A field carrying `ion-invalid` alone (pristine but invalid) is deliberately
 * NOT flagged and is not styled as invalid by Ionic.
 *
 * Binding `aria-invalid` on the host does NOT work: Ionic inherits ARIA
 * attributes into the native input and removes them from the host, so the
 * value captured at load time becomes stale on the native input and any host
 * selector used for styling matches only by accident.
 *
 * @see https://ionicframework.com/docs/v8/components/input
 */
export interface IonicValidationField {
  $dirty: boolean
  $invalid: boolean
}

export function ionicValidationClasses(field: IonicValidationField): Record<string, boolean> {
  return {
    'ion-touched': field.$dirty,
    'ion-invalid': field.$invalid,
  }
}
