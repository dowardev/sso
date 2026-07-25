// SPDX-License-Identifier: MIT
import { describe, it, expect } from 'vitest'
import { ref } from 'vue'
import { useFormValidation } from '@/composables/useFormValidation'
import { validators } from '@/composables/useValidation'
import { required } from '@vuelidate/validators'

const v = validators

describe('useFormValidation', () => {
  it('valida campo requerido - invalido', async () => {
    const state = ref({ name: '' })
    const rules = { name: { required: v.required('Nombre') } }
    const { validate } = useFormValidation(rules, state)
    expect(await validate()).toBe(false)
  })

  it('valida campo requerido - valido', async () => {
    const state = ref({ name: 'Test' })
    const rules = { name: { required: v.required('Nombre') } }
    const { validate } = useFormValidation(rules, state)
    expect(await validate()).toBe(true)
  })

  it('valida email formato invalido', async () => {
    const state = ref({ email: 'invalido' })
    const rules = { email: { email: v.email('Email') } }
    const { validate } = useFormValidation(rules, state)
    expect(await validate()).toBe(false)
  })

  it('valida email formato valido', async () => {
    const state = ref({ email: 'test@example.com' })
    const rules = { email: { email: v.email('Email') } }
    const { validate } = useFormValidation(rules, state)
    expect(await validate()).toBe(true)
  })

  it('valida minLength', async () => {
    const state = ref({ field: 'ab' })
    const rules = { field: { minLength: v.minLength('Campo', 3) } }
    const { validate } = useFormValidation(rules, state)
    expect(await validate()).toBe(false)

    state.value.field = 'abc'
    expect(await validate()).toBe(true)
  })

  it('valida maxLength', async () => {
    const state = ref({ field: 'a'.repeat(256) })
    const rules = { field: { maxLength: v.maxLength('Campo', 255) } }
    const { validate } = useFormValidation(rules, state)
    expect(await validate()).toBe(false)

    state.value.field = 'a'.repeat(255)
    expect(await validate()).toBe(true)
  })

  it('valida url formato invalido', async () => {
    const state = ref({ url: 'not-a-url' })
    const rules = { url: { url: v.url('URL') } }
    const { validate } = useFormValidation(rules, state)
    expect(await validate()).toBe(false)
  })

  it('valida url formato valido', async () => {
    const state = ref({ url: 'https://example.com' })
    const rules = { url: { url: v.url('URL') } }
    const { validate } = useFormValidation(rules, state)
    expect(await validate()).toBe(true)
  })

  it('valida alphaNum con caracteres invalidos', async () => {
    const state = ref({ slug: 'espacios y simbolos!' })
    const rules = { slug: { alphaNum: v.alphaNum('Slug') } }
    const { validate } = useFormValidation(rules, state)
    expect(await validate()).toBe(false)
  })

  it('valida alphaNum con caracteres validos', async () => {
    const state = ref({ slug: 'editor-rol_admin' })
    const rules = { slug: { alphaNum: v.alphaNum('Slug') } }
    const { validate } = useFormValidation(rules, state)
    expect(await validate()).toBe(true)
  })

  it('valida passwordComplex simple', async () => {
    const state = ref({ password: 'simple' })
    const rules = { password: { passwordComplex: v.passwordComplex('Password') } }
    const { validate } = useFormValidation(rules, state)
    expect(await validate()).toBe(false)
  })

  it('valida passwordComplex correcto', async () => {
    const state = ref({ password: 'Passw0rd!' })
    const rules = { password: { passwordComplex: v.passwordComplex('Password') } }
    const { validate } = useFormValidation(rules, state)
    expect(await validate()).toBe(true)
  })

  it('valida hostOrIp hostname valido', async () => {
    const state = ref({ host: 'mi-servidor.local' })
    const rules = { host: { hostOrIp: v.hostOrIp('Host') } }
    const { validate } = useFormValidation(rules, state)
    expect(await validate()).toBe(true)
  })

  it('valida hostOrIp IP valida', async () => {
    const state = ref({ host: '192.168.1.1' })
    const rules = { host: { hostOrIp: v.hostOrIp('Host') } }
    const { validate } = useFormValidation(rules, state)
    expect(await validate()).toBe(true)
  })

  it('valida hostOrIp invalido', async () => {
    const state = ref({ host: 'http://invalido.com/path' })
    const rules = { host: { hostOrIp: v.hostOrIp('Host') } }
    const { validate } = useFormValidation(rules, state)
    expect(await validate()).toBe(false)
  })

  it('valida hostOrIp IP con octetos fuera de rango', async () => {
    const state = ref({ host: '999.999.999.999' })
    const rules = { host: { hostOrIp: v.hostOrIp('Host') } }
    const { validate } = useFormValidation(rules, state)
    expect(await validate()).toBe(false)
  })

  it('valida hostOrIp IP invalida', async () => {
    const state = ref({ host: '256.0.0.1' })
    const rules = { host: { hostOrIp: v.hostOrIp('Host') } }
    const { validate } = useFormValidation(rules, state)
    expect(await validate()).toBe(false)
  })

  it('valida hostOrIp hostname con caracteres invalidos', async () => {
    const state = ref({ host: 'host with spaces' })
    const rules = { host: { hostOrIp: v.hostOrIp('Host') } }
    const { validate } = useFormValidation(rules, state)
    expect(await validate()).toBe(false)
  })

  it('valida hostOrIp localhost', async () => {
    const state = ref({ host: 'localhost' })
    const rules = { host: { hostOrIp: v.hostOrIp('Host') } }
    const { validate } = useFormValidation(rules, state)
    expect(await validate()).toBe(true)
  })

  it('touchAll marca todos los campos como tocados', () => {
    const state = ref({ name: '', email: '' })
    const rules = {
      name: { required },
      email: { required },
    }
    const { v$, touchAll } = useFormValidation(rules, state)
    expect(v$.value.name.$dirty).toBe(false)
    touchAll()
    expect(v$.value.name.$dirty).toBe(true)
    expect(v$.value.email.$dirty).toBe(true)
  })

  it('reset limpia estado de validacion', () => {
    const state = ref({ name: '' })
    const rules = { name: { required } }
    const { v$, touchAll, reset } = useFormValidation(rules, state)
    touchAll()
    expect(v$.value.name.$dirty).toBe(true)
    reset()
    expect(v$.value.name.$dirty).toBe(false)
  })
})
