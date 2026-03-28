# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-03-27

### Added
- User 'user' field (username) to User model for username-based authentication
- Username generation in UserFactory using faker with unique constraint
- Test user seed data with username field in DatabaseSeeder

### Changed
- Updated DatabaseSeeder to include username for test user

## [0.0.2] - 2026-01-17

### Added
- Production environment template (`.env.example.production`) with complete configuration for database, sessions, Redis, mail, and Sanctum
- Comprehensive deployment checklist (`DEPLOYMENT_CHECKLIST.md`) covering:
  - DNS and domain configuration
  - SSL certificate setup with Let's Encrypt
  - Server configuration (PHP, MySQL, Nginx)
  - SSO Server deployment procedures
  - Backend deployment procedures
  - Frontend deployment options (Vercel/Netlify or custom server)
  - CORS and security configuration
  - Verification and testing procedures
  - Maintenance scripts (backups, logs, updates)
  - Troubleshooting guide

### Changed
- Updated README.md with English documentation
- Added production configuration section with domain examples
- Replaced localhost references with production domain placeholders
- Improved architecture diagram with production URLs
- Added deployment checklist reference in main documentation

## [0.0.1] - 2026-01-16

### Added
- Initial SSO Server implementation
- User registration endpoint
- User login endpoint
- User logout endpoint
- User profile endpoint (authenticated)
- Laravel Sanctum integration for API token authentication
