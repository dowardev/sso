// SPDX-License-Identifier: MIT
import { useVuelidate } from '@vuelidate/core'
import type { ValidationRule } from '@vuelidate/core'
import type { Ref, ComputedRef } from 'vue'

type RuleEntry = Record<string, ValidationRule | undefined>
type RulesRecord<T extends Record<string, unknown>> = Record<keyof T, RuleEntry>
type MaybeRefRules<T extends Record<string, unknown>> = RulesRecord<T> | Ref<RulesRecord<T>> | ComputedRef<RulesRecord<T>>

export function useFormValidation<T extends Record<string, unknown>>(
  rules: MaybeRefRules<T>,
  state: Ref<T>
) {
  const v$ = useVuelidate(rules, state)

  const validate = async (): Promise<boolean> => {
    const isValid = await v$.value.$validate()
    return isValid
  }

  const touchAll = () => v$.value.$touch()

  const reset = () => v$.value.$reset()

  return { v$, validate, touchAll, reset }
}
