// SPDX-License-Identifier: MIT

import { describe, it, expect } from 'vitest'

import { ionicValidationClasses } from '@/utils/ionicValidation'

describe('ionicValidationClasses', () => {
  it('marks a pristine and valid field with neither class', () => {
    expect(ionicValidationClasses({ $dirty: false, $invalid: false })).toEqual({
      'ion-touched': false,
      'ion-invalid': false,
    })
  })

  it('marks a touched but valid field as touched only', () => {
    expect(ionicValidationClasses({ $dirty: true, $invalid: false })).toEqual({
      'ion-touched': true,
      'ion-invalid': false,
    })
  })

  it('marks a dirty and invalid field with both classes, the combination Ionic requires', () => {
    expect(ionicValidationClasses({ $dirty: true, $invalid: true })).toEqual({
      'ion-touched': true,
      'ion-invalid': true,
    })
  })

  it('does not flag a pristine invalid field, because Ionic requires ion-touched and ion-invalid together', () => {
    const classes = ionicValidationClasses({ $dirty: false, $invalid: true })

    expect(classes['ion-invalid']).toBe(true)
    expect(classes['ion-touched']).toBe(false)
  })
})
