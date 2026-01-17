# Checklist de Despliegue - Sistema SSO

Este documento contiene una checklist completa para desplegar el sistema SSO en producción.

## Arquitectura del Sistema

```
┌─────────────────────────────────────────────────────────────────┐
│                         FRONTEND (Ionic/Vue)                     │
│                    https://www.miapp.com                         │
└─────────────────────────────────────────────────────────────────┘
                                │
                                ▼
┌─────────────────────────────────────────────────────────────────┐
│                     BACKEND PORTAFOLIO                           │
│                      https://api.miapp.com                       │
│                              │                                   │
│                              ▼                                   │
│              Middleware SSOTokenAuth valida token                │
│                              │                                   │
│                              ▼                                   │
│              GET https://sso.miapp.com/api/me                    │
└─────────────────────────────────────────────────────────────────┘
                                │
                                ▼
┌─────────────────────────────────────────────────────────────────┐
│                          SSO SERVER                              │
│                     https://sso.miapp.com                        │
└─────────────────────────────────────────────────────────────────┘
```

---

## 1. DNS y Dominios

### 1.1 Configurar DNS

| Dominio | Tipo | Valor |
|---------|------|-------|
| `miapp.com` | A | IP del servidor web |
| `www.miapp.com` | CNAME | miapp.com |
| `api.miapp.com` | A | IP del servidor API |
| `sso.miapp.com` | A | IP del servidor SSO |

### 1.2 Verificar DNS

```bash
# Verificar propagación DNS
dig miapp.com
dig api.miapp.com
dig sso.miapp.com

# Verificar que apunten a la IP correcta
nslookup miapp.com
nslookup api.miapp.com
nslookup sso.miapp.com
```

---

## 2. Certificados SSL

### 2.1 Obtener certificados con Let's Encrypt

```bash
# Instalar certbot
sudo apt update
sudo apt install certbot python3-certbot-nginx

# Obtener certificado para cada dominio
sudo certbot --nginx -d miapp.com -d www.miapp.com
sudo certbot --nginx -d api.miapp.com
sudo certbot --nginx -d sso.miapp.com

# Verificar renovación automática
sudo certbot renew --dry-run
```

### 2.2 Configurar renovación automática

```bash
# Añadir al crontab
crontab -e

# Añadir línea para renovación automática cada día a las 2am
0 2 * * * certbot renew --quiet
```

---

## 3. Servidor - Configuración General

### 3.1 Actualizar sistema

```bash
sudo apt update && sudo apt upgrade -y
```

### 3.2 Instalar dependencias

```bash
# PHP 8.2+
sudo add-apt-repository ppa:ondrej/php
sudo apt update
sudo apt install php8.2 php8.2-cli php8.2-fpm php8.2-mysql php8.2-xml php8.2-mbstring php8.2-tokenizer php8.2-curl php8.2-zip php8.2-gd php8.2-intl php8.2-imagick

# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Node.js (usando nvm)
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.0/install.sh | bash
source ~/.bashrc
nvm install --lts
npm install -g pnpm

# MySQL
sudo apt install mysql-server
sudo mysql_secure_installation

# Nginx
sudo apt install nginx
```

### 3.3 Configurar firewall

```bash
# Habilitar UFW
sudo ufw enable

# Permitir SSH, HTTP, HTTPS
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw status
```

---

## 4. Base de Datos - MySQL

### 4.1 Crear base de datos y usuarios

```sql
-- Conectar a MySQL
sudo mysql -u root -p

-- Crear base de datos
CREATE DATABASE sso_server CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE portafolio_backend CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Crear usuarios
CREATE USER 'sso_user'@'localhost' IDENTIFIED BY 'password_seguro_aqui';
CREATE USER 'portafolio_user'@'localhost' IDENTIFIED BY 'password_seguro_aqui';

-- Otorgar permisos
GRANT ALL PRIVILEGES ON sso_server.* TO 'sso_user'@'localhost';
GRANT ALL PRIVILEGES ON portafolio_backend.* TO 'portafolio_user'@'localhost';

FLUSH PRIVILEGES;
EXIT;
```

### 4.2 Verificar conexión

```bash
mysql -u sso_user -p -e "SHOW DATABASES;"
mysql -u portafolio_user -p -e "SHOW DATABASES;"
```

---

## 5. SSO Server - Despliegue

### 5.1 Preparar directorio

```bash
sudo mkdir -p /var/www/sso-server
sudo chown -R $USER:$USER /var/www/sso-server
cd /var/www/sso-server
```

### 5.2 Clonar y configurar

```bash
# Clonar repositorio
git clone https://gitlab.com/dowardev/sso-server.git .

# Copiar configuración de producción
cp .env.example.production .env
nano .env

# Instalar dependencias
composer install --optimize-autoloader --no-dev

# Generar clave de aplicación
php artisan key:generate

# Ejecutar migraciones
php artisan migrate --force

# Configurar permisos
sudo chown -R www-data:www-data /var/www/sso-server
sudo chmod -R 755 /var/www/sso-server
sudo chmod -R 775 /var/www/sso-server/storage
sudo chmod -R 775 /var/www/sso-server/bootstrap/cache
```

### 5.3 Configurar variables críticas

```env
APP_NAME=SSO Server
APP_ENV=production
APP_DEBUG=false
APP_URL=https://sso.miapp.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sso_server
DB_USERNAME=sso_user
DB_PASSWORD=password_seguro_aqui

SANCTUM_STATEFUL_DOMAINS=miapp.com,www.miapp.com,api.miapp.com
```

### 5.4 Configurar Nginx

```nginx
server {
    server_name sso.miapp.com;
    root /var/www/sso-server/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Headers de seguridad
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
}

# Redirect HTTP a HTTPS
server {
    server_name sso.miapp.com;
    listen 80;
    return 301 https://$host$request_uri;
}
```

### 5.5 Probar SSO Server

```bash
# Verificar que responde
curl -X POST https://sso.miapp.com/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@admin.com","password":"123"}'
```

---

## 6. Backend Portafolio - Despliegue

### 6.1 Preparar directorio

```bash
sudo mkdir -p /var/www/portafolio-backend
sudo chown -R $USER:$USER /var/www/portafolio-backend
cd /var/www/portafolio-backend
```

### 6.2 Clonar y configurar

```bash
# Clonar repositorio
git clone https://gitlab.com/dowardev/portfolio-backend.git .

# Copiar configuración de producción
cp .env.example.production .env
nano .env

# Instalar dependencias
composer install --optimize-autoloader --no-dev

# Generar clave de aplicación
php artisan key:generate

# Ejecutar migraciones
php artisan migrate --force

# Configurar permisos
sudo chown -R www-data:www-data /var/www/portafolio-backend
sudo chmod -R 755 /var/www/portafolio-backend
sudo chmod -R 775 /var/www/portafolio-backend/storage
sudo chmod -R 775 /var/www/portafolio-backend/bootstrap/cache
```

### 6.3 Configurar variables críticas

```env
APP_NAME=Portafolio Backend
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.miapp.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=portafolio_backend
DB_USERNAME=portafolio_user
DB_PASSWORD=password_seguro_aqui

SSO_URL=https://sso.miapp.com/api
```

### 6.4 Configurar Nginx

```nginx
server {
    server_name api.miapp.com;
    root /var/www/portafolio-backend/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Headers de seguridad
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    # CORS - configurar según necesidad
    add_header Access-Control-Allow-Origin "https://miapp.com" always;
    add_header Access-Control-Allow-Methods "GET, POST, PUT, DELETE, OPTIONS" always;
    add_header Access-Control-Allow-Headers "Authorization, Content-Type" always;
}

# Redirect HTTP a HTTPS
server {
    server_name api.miapp.com;
    listen 80;
    return 301 https://$host$request_uri;
}
```

### 6.5 Probar Backend

```bash
# Verificar que responde
curl https://api.miapp.com/api/post-dates
```

---

## 7. Frontend - Despliegue

### 7.1 Configurar variables de producción

```bash
# En el directorio del frontend
cp .env.example.production .env.production

# Editar con URLs reales
VITE_API_URL=https://api.miapp.com/api/
VITE_SSO_URL=https://sso.miapp.com/api/
```

### 7.2 Compilar

```bash
pnpm install
ionic build --prod
```

### 7.3 Desplegar

**Opción A: Servidor web propio**

```bash
sudo mkdir -p /var/www/portafolio-frontend
sudo chown -R $USER:$USER /var/www/portafolio-frontend

# Copiar build
cp -r dist/* /var/www/portafolio-frontend/

# Configurar Nginx
```

```nginx
server {
    server_name miapp.com www.miapp.com;
    root /var/www/portafolio-frontend;
    index index.html;

    location / {
        try_files $uri $uri/ /index.html;
    }

    # Headers de seguridad
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    # Service Worker para PWA
    location /sw.js {
        add_header Content-Type application/javascript;
        add_header Cache-Control "no-cache";
    }
}
```

**Opción B: Vercel/Netlify**

```bash
# Vercel
npm i -g vercel
vercel --prod

# Netlify
npm i -g netlify-cli
netlify deploy --prod --dir=dist
```

---

## 8. CORS y Configuración de Seguridad

### 8.1 Configurar CORS en Backend Portafolio

En `app/Http/Middleware/Cors.php`:

```php
class Cors
{
    public function handle($request, Closure $next)
    {
        return $next($request)
            ->header('Access-Control-Allow-Origin', 'https://miapp.com')
            ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Authorization, Content-Type, X-Requested-With')
            ->header('Access-Control-Allow-Credentials', 'true');
    }
}
```

### 8.2 Configurar SANCTUM_STATEFUL_DOMAINS

En `.env` del SSO Server:

```env
SANCTUM_STATEFUL_DOMAINS=miapp.com,www.miapp.com,api.miapp.com
```

---

## 9. Verificación y Pruebas

### 9.1 Probar flujo completo

```bash
# 1. Login en SSO
TOKEN=$(curl -s -X POST https://sso.miapp.com/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@admin.com","password":"123"}' | jq -r '.token')

echo "Token: $TOKEN"

# 2. Verificar usuario en SSO
curl https://sso.miapp.com/api/me \
  -H "Authorization: Bearer $TOKEN"

# 3. Verificar usuario en Backend Portafolio
curl https://api.miapp.com/api/user-auth \
  -H "Authorization: Bearer $TOKEN"
```

### 9.2 Checklist de verificación

- [ ] DNS propagado correctamente
- [ ] Certificados SSL válidos
- [ ] Bases de datos creadas y conectables
- [ ] SSO Server responde en https://sso.miapp.com/api/me
- [ ] Backend responde en https://api.miapp.com/api/post-dates
- [ ] Frontend carga en https://miapp.com
- [ ] Login funciona correctamente
- [ ] Logout funciona correctamente
- [ ] Usuario se guarda en ambas bases de datos
- [ ] Headers de seguridad configurados
- [ ] CORS configurado correctamente
- [ ] Rate limiting activo (recomendado)

---

## 10. Mantenimiento

### 10.1 Backups

```bash
# Añadir al crontab para backups diarios
0 3 * * * mysqldump -u sso_user -p'sso_password' sso_server | gzip > /var/backups/sso_server_$(date +\%Y\%m\%d).sql.gz
0 3 * * * mysqldump -u portafolio_user -p'portafolio_password' portafolio_backend | gzip > /var/backups/portafolio_backend_$(date +\%Y\%m\%d).sql.gz
```

### 10.2 Logs

```bash
# Ver logs del SSO Server
tail -f /var/www/sso-server/storage/logs/laravel.log

# Ver logs del Backend
tail -f /var/www/portafolio-backend/storage/logs/laravel.log

# Ver logs de Nginx
sudo tail -f /var/log/nginx/sso.miapp.com.error.log
sudo tail -f /var/log/nginx/api.miapp.com.error.log
```

### 10.3 Actualizaciones

```bash
# Actualizar SSO Server
cd /var/www/sso-server
git pull
composer install --optimize-autoloader --no-dev
php artisan migrate --force
sudo chown -R www-data:www-data /var/www/sso-server

# Actualizar Backend
cd /var/www/portafolio-backend
git pull
composer install --optimize-autoloader --no-dev
php artisan migrate --force
sudo chown -R www-data:www-data /var/www/portafolio-backend
```

---

## 11. Solución de Problemas Comunes

### 11.1 Error 500 en API

```bash
# Verificar logs
tail -f /var/www/sso-server/storage/logs/laravel.log
tail -f /var/www/portafolio-backend/storage/logs/laravel.log

# Verificar permisos
sudo chown -R www-data:www-data /var/www/sso-server
sudo chown -R www-data:www-data /var/www/portafolio-backend

# Verificar conexión a BD
php artisan tinker --execute="DB::connection()->getPdo();"
```

### 11.2 Error de CORS

```bash
# Verificar configuración de CORS
# En el navegador: Console > Network > Response Headers
# Debe tener: Access-Control-Allow-Origin: https://miapp.com
```

### 11.3 Token inválido

```bash
# Verificar que SSO_URL es correcto en Backend
php artisan tinker --execute="config('app.sso_url')"

# Verificar que el token no ha expirado
# Los tokens de Sanctum no expiran por defecto
```

---

## Contacto y Soporte

Para reportar problemas o sugerencias, abrir un issue en:
- SSO Server: https://gitlab.com/dowardev/sso-server
- Backend Portafolio: https://gitlab.com/dowardev/portfolio-backend
- Frontend: https://gitlab.com/dowardev/portafolio
