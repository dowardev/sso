// SPDX-License-Identifier: MIT

// Single source of truth for field labels. Both the form validation layer and
// the error rendering layer read from here, so a field is labelled once.

export const fieldLabelsEs: Record<string, string> = {
  name: 'Nombre',
  user: 'Usuario',
  email: 'Email',
  password: 'Contraseña',
  password_confirmation: 'Confirmación de contraseña',
  client_id: 'ID del cliente',
  client_secret: 'Secreto del cliente',
  redirect_url: 'URL de redirección',
  allowed_origins: 'Orígenes permitidos',
  grant_types: 'Tipos de concesión',
  description: 'Descripción',
  slug: 'Slug',
  role_id: 'ID del rol',
  action: 'Acción',
  token: 'Token',
  code: 'Código',
  remember_me: 'Recordar sesión',
}

export const fieldLabelsEn: Record<string, string> = {
  name: 'Name',
  user: 'Username',
  email: 'Email',
  password: 'Password',
  password_confirmation: 'Password confirmation',
  client_id: 'Client ID',
  client_secret: 'Client secret',
  redirect_url: 'Redirect URL',
  allowed_origins: 'Allowed origins',
  grant_types: 'Grant types',
  description: 'Description',
  slug: 'Slug',
  role_id: 'Role ID',
  action: 'Action',
  token: 'Token',
  code: 'Code',
  remember_me: 'Remember me',
}
