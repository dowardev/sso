# Plantilla CI/CD — `.gitlab-ci.yml` estandarizado

Plantilla canónica para los pipelines GitLab CI de los proyectos de `dowardev`.
Este documento es la **fuente de verdad** del patrón: cada `.gitlab-ci.yml` del
repositorio lo implementa con las adaptaciones de su stack.

---

## Reglas universales

| Decisión | Valor | Justificación |
|----------|-------|---------------|
| Disparo | Tag semver con prereleases | `^v\d+\.\d+\.\d+(-(alpha\|beta\|rc)\.\d+)?$` — coherencia entre proyectos |
| `SSH_KNOWN_HOSTS` | CI var *masked* con el fingerprint | Sustituye a `ssh-keyscan` (MITM) |
| `SSH_PRIVATE_KEY` | CI var *masked* | Nunca en el repo, nunca en logs |
| `APP_KEY` (Laravel) | CI var *masked* *protected* | Elimina el hardcode del yml |
| Imágenes | Fijadas a versión menor | `node:24.4-alpine`, `composer:2.8`, `php:8.3-cli` — nunca `latest`/`lts` |
| pnpm | `corepack enable` + `--frozen-lockfile` | Determinismo; respeta `packageManager` |
| Cache | `pnpm-store/` y `vendor/` (o `.composer/cache/`) con `COMPOSER_HOME` explícito | No cachear `node_modules/` por subcarpeta en monorepos |
| `needs:` + `artifacts:true` | Dependencias explícitas | Pipeline DAG, no lineal |
| `artifacts.expire_in: 1h` | Limpieza automática | Ahorro de storage |
| Deploy | `tar + scp` (nunca `git pull` en el server) | El server no necesita git/node; idempotente |
| Deploy Laravel | `composer install --no-dev --optimize-autoloader` + `migrate --force` + `config:cache` + `route:cache` | Estado de arte producción |
| `--exclude` en el tar | `.env`, `.git`, `node_modules`, `vendor`, `storage/*`, `bootstrap/cache/*.php` | No sobreescribir secretos/estado del server |
| Healthcheck | `post-deploy` con `wget --spider $HEALTHCHECK_URL`, `allow_failure: false` | El deploy falla si la URL no responde |
| Rollback | Job `when: manual` que redepliega el tag anterior | Recuperación rápida sin tácticas del server |

---

## Plantilla base

```yaml
image: alpine:latest   # default; cada job puede fijar la suya

stages:
  - lint
  - test
  - build
  - deploy
  - post-deploy    # healthcheck
  - rollback       # manual

cache:
  key: "$CI_COMMIT_REF_SLUG"
  paths:
    - .pnpm-store/
    - vendor/

# ---------- Templates reutilizables (jobs ocultos) ----------

# Dispara solo en tags vX.Y.Z con prerelease opcional (-alpha.N | -beta.N | -rc.N)
.tag_semver:
  rules:
    - if: '$CI_COMMIT_TAG =~ /^v\d+\.\d+\.\d+(-(alpha|beta|rc)\.\d+)?$/'

# SSH con known_hosts explícito (NO ssh-keyscan)
.ssh_setup:
  before_script:
    - apk add --no-cache openssh-client
    - mkdir -p ~/.ssh && chmod 700 ~/.ssh
    - echo "$SSH_PRIVATE_KEY" > ~/.ssh/id_rsa && chmod 600 ~/.ssh/id_rsa
    - echo "$SSH_KNOWN_HOSTS" >> ~/.ssh/known_hosts
    - eval $(ssh-agent -s) && ssh-add ~/.ssh/id_rsa

# Instalación de dependencias JS determinista (monorepo pnpm)
.pnpm_setup:
  before_script:
    - corepack enable
    - pnpm install --frozen-lockfile

# Instalación de dependencias PHP determinista
.composer_setup:
  before_script:
    - composer install --prefer-dist --no-progress --no-interaction

# ---------- Jobs de ejemplo ----------

lint:
  stage: lint
  extends: [.tag_semver, .pnpm_setup]
  script:
    - pnpm lint

test:
  stage: test
  extends: [.tag_semver, .pnpm_setup]
  script:
    - pnpm test:unit

build:
  stage: build
  extends: [.tag_semver, .pnpm_setup]
  script:
    - pnpm build
  artifacts:
    paths:
      - dist/
    expire_in: 1 hour

deploy:
  stage: deploy
  image: alpine
  extends: [.tag_semver, .ssh_setup]
  needs: [build]
  script:
    - tar czvf dist.tar.gz -C dist .
    - scp -P "$SSH_PORT" dist.tar.gz "$SSH_USER@$SSH_HOST:$DEPLOY_PATH"
    - ssh -p "$SSH_PORT" "$SSH_USER@$SSH_HOST" "cd $DEPLOY_PATH && tar xzvf dist.tar.gz && rm dist.tar.gz"

post-deploy:
  stage: post-deploy
  image: alpine
  extends: [.tag_semver]
  needs: [deploy]
  before_script:
    - apk add --no-cache wget
  script:
    - wget -q -S --spider "$HEALTHCHECK_URL"

# Redespliega el tag anterior. Se ejecuta manualmente desde el pipeline del tag.
rollback:
  stage: rollback
  image: alpine
  extends: [.tag_semver, .ssh_setup]
  when: manual
  script:
    - PREVIOUS_TAG=$(git tag --list "v*" --sort=-version:refname | sed -n '2p')
    - echo "Rolling back to $PREVIOUS_TAG"
    - git checkout "$PREVIOUS_TAG"
    - tar czvf dist.tar.gz -C dist .
    - scp -P "$SSH_PORT" dist.tar.gz "$SSH_USER@$SSH_HOST:$DEPLOY_PATH"
    - ssh -p "$SSH_PORT" "$SSH_USER@$SSH_HOST" "cd $DEPLOY_PATH && tar xzvf dist.tar.gz && rm dist.tar.gz"
```

---

## Variables CI/CD obligatorias

Configurar en **GitLab → Settings → CI/CD → Variables**. Marcadas **Protected**
(aplica en tags/branches protegidos) y **Masked** (secretos):

| Variable | Tipo | Proyectos | Descripción |
|----------|------|-----------|-------------|
| `SSH_PRIVATE_KEY` | masked | todos | Clave privada SSH del deploy (sin passphrase) |
| `SSH_KNOWN_HOSTS` | masked | todos | Fingerprint del host: `ssh-keygen -F <host> -p <puerto> -f ~/.ssh/known_hosts` |
| `SSH_HOST` | normal | todos | IP/dominio del servidor |
| `SSH_PORT` | normal | todos | Puerto SSH |
| `SSH_USER` | normal | todos | Usuario SSH |
| `DEPLOY_PATH` | normal | todos | Ruta absoluta del deploy en el server |
| `HEALTHCHECK_URL` | normal | todos | URL pública de la app para el healthcheck |
| `APP_KEY` | masked + protected | Laravel | `php artisan key:generate --show` |
| `VITE_API_URL` | normal | frontends Ionic | URL del backend para el build |
| `VITE_SKIP_WAYFINDER` | normal | landing | `"1"` para saltar el plugin Wayfinder en CI |

> **`SSH_KNOWN_HOSTS`**: obtener el fingerprint una vez con
> `ssh-keyscan -p <port> <host>` localmente y guardar la línea en la variable.
> Nunca llamar a `ssh-keyscan` dentro del pipeline (riesgo MITM).

---

## Adaptaciones por stack

### Monorepo pnpm (ui-lib, ionic-extra-components, ionic-components-page)

- `image: node:24.4-alpine`
- Cache: `.pnpm-store/` (no `node_modules/` por subcarpeta)
- Deploy: solo docs/landing via `tar + scp`

### Laravel + Vue/Vite (landing-lambdaSoftware)

- Jobs PHP con `composer:2.8`, jobs frontend con `node:24.4-alpine`
- **Orden `lint → build → test → deploy`**: `build` va antes que `test`
  porque `php_test` necesita el manifest `public/build/` generado por Vite
  (dependencia real, no antipatrón — documentar con comentario)
- `php_test`: sqlite en memoria + `APP_KEY` desde CI var
- Deploy: `tar + scp` + en server `composer install --no-dev --optimize-autoloader`,
  `migrate --force`, `config:cache`, `route:cache`

### Monorepo Laravel + Ionic (portafolio, oneSpend)

- Stages comunes `lint/test/build/deploy/post-deploy/rollback` con jobs
  `backend_*` y `frontend_*` en cada stage
- `backend_test`: `php:8.3-cli`, sqlite `:memory:`, `APP_KEY` desde CI var,
  `php artisan test --parallel`
- `backend_deploy`: `tar + scp` del subdir `backend/`, en server
  `composer install --no-dev --optimize-autoloader` + `migrate --force`
  + `config:cache` + `route:cache`
- `frontend_deploy`: build vite + `tar + scp` de `dist/`
- Electron (oneSpend): fuera de CI por ahora — ver nota futura abajo

---

## Nota futura: Electron (oneSpend)

`front/package.json` define `electron:build` y `build:linux/win/mac`.
No se incluyen en CI: requieren runners por OS y secrets de signing,
y no son un deploy web. Revisitar cuando haya runners dedicados.

---

## Convenciones de estilo en los `.gitlab-ci.yml`

- Comentarios `#` solo para justificar decisiones no obvias (no documentar lo obvio)
- Templates ocultos con prefijo `.` y sustantivo (`tag_semver`, `ssh_setup`)
- `extends:` con lista cuando hay varios templates
- `needs:` explícito entre jobs dependientes
- Un stage `post-deploy` con healthcheck y uno `rollback` manual en todos
