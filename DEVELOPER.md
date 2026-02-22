# ZAO Bank Mobile - Developer Documentation

## Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [Integration with ZAO Bank Core](#integration-with-zao-bank-core)
3. [Integration with Theme](#integration-with-theme)
4. [JWT Authentication System](#jwt-authentication-system)
5. [Geolocation System](#geolocation-system)
6. [REST API Reference](#rest-api-reference)
7. [Database Schema](#database-schema)
8. [Hooks & Filters](#hooks--filters)
9. [Security Considerations](#security-considerations)
10. [Troubleshooting](#troubleshooting)

## Architecture Overview

### Plugin Structure

```
zaobank-mobile/
├── admin/
│   ├── class-zaobank-mobile-admin.php    # Admin settings
│   └── partials/
│       └── settings-page.php              # Settings UI
├── assets/
│   └── js/
│       └── admin.js                       # Batch geocoding UI
├── includes/
│   ├── auth/
│   │   ├── class-zaobank-jwt-tokens.php   # Token generation/validation
│   │   └── class-zaobank-jwt-auth.php     # WP authentication integration
│   ├── geolocation/
│   │   ├── class-zaobank-geocoder.php     # Address → coordinates
│   │   ├── class-zaobank-distance-calculator.php
│   │   └── class-zaobank-location-privacy.php
│   ├── rest-api/
│   │   ├── class-zaobank-mobile-rest-auth.php
│   │   ├── class-zaobank-mobile-rest-jobs.php
│   │   ├── class-zaobank-mobile-rest-location.php
│   │   └── class-zaobank-mobile-rest-config.php
│   ├── class-zaobank-mobile.php           # Main orchestrator
│   ├── class-zaobank-mobile-activator.php
│   └── class-zaobank-mobile-deactivator.php
├── zaobank-mobile.php                     # Plugin entry point
├── uninstall.php
├── README.md
└── DEVELOPER.md                           # This file
```

### Design Principles

1. **API-Only** - No templates, no frontend assets, pure REST API
2. **Stateless Auth** - JWT tokens, no server-side sessions
3. **Privacy by Design** - Location fuzzing, user-controlled precision
4. **Defensive Dependencies** - Checks for zaobank-core but handles absence
5. **Mobile-First** - Endpoints optimized for app consumption

### Data Flow

```
Mobile App → JWT Token → REST API → Security Check → Business Logic → Response
                              ↓
                    zaobank-core classes
                    (if available)
```

## Integration with ZAO Bank Core

### Dependency Matrix

| zaobank-mobile Feature | zaobank-core Dependency | Fallback Behavior |
|------------------------|------------------------|-------------------|
| Job listings | `ZAOBank_Jobs::format_job_data()` | Basic WP_Post data only |
| Content visibility | `ZAOBank_Security::is_content_visible()` | **ALLOWS ALL ACCESS** |
| Region filtering | `zaobank_region` taxonomy | Non-functional |
| Admin menu | `zaobank` menu slug | Menu not visible |
| Job post type | `timebank_job` | Endpoints return empty |

### Critical: Security Bypass Risk

The following code pattern is used throughout:

```php
// In class-zaobank-mobile-rest-jobs.php
if (class_exists('ZAOBank_Security') && !ZAOBank_Security::is_content_visible('job', $job_id)) {
    return new WP_Error('forbidden', 'Content not accessible', array('status' => 403));
}
// If ZAOBank_Security doesn't exist, this check is SKIPPED!
```

**Impact:** Without zaobank-core, ALL content is publicly accessible via the mobile API.

**Recommended Fix:**

```php
// Secure default when zaobank-core is missing
if (!class_exists('ZAOBank_Security')) {
    return new WP_Error('plugin_missing', 'Required plugin not active', array('status' => 503));
}

if (!ZAOBank_Security::is_content_visible('job', $job_id)) {
    return new WP_Error('forbidden', 'Content not accessible', array('status' => 403));
}
```

### Shared Classes Used

```php
// Job data formatting
if (class_exists('ZAOBank_Jobs') && method_exists('ZAOBank_Jobs', 'format_job_data')) {
    $job = ZAOBank_Jobs::format_job_data($job_id);
}

// Security/visibility checks
if (class_exists('ZAOBank_Security')) {
    $visible = ZAOBank_Security::is_content_visible('job', $job_id);
}

// ACF fields (optional)
if (function_exists('get_field')) {
    $hours = get_field('hours_required', $job_id);
}
```

### Post Type & Taxonomy

This plugin relies on these registrations from zaobank-core:

```php
// Post type (registered by zaobank-core)
register_post_type('timebank_job', [...]);

// Taxonomy (registered by zaobank-core)
register_taxonomy('zaobank_region', ['timebank_job'], [...]);
```

### Hook Priority Considerations

| Hook | This Plugin | zaobank-core | Interaction |
|------|-------------|--------------|-------------|
| `determine_current_user` | Priority 20 | Not used | JWT auth runs after WP default |
| `rest_authentication_errors` | Priority 15 | Not used | JWT errors handled early |
| `save_post_timebank_job` | Priority 20 | Various | Geocoding runs after core saves |
| `admin_menu` | Default | Default | Submenu added to core's menu |

## Integration with Theme

### No Template Conflicts

This plugin is **API-only**. It does not:
- Load any template files
- Override any templates
- Enqueue any frontend CSS/JS
- Interfere with shortcode rendering

### Web vs. Mobile: Two Separate Paths

```
┌─────────────────────────────────────────────────────────────────┐
│                        User Access                               │
├────────────────────────────┬────────────────────────────────────┤
│       Web Browser          │         Native Mobile App          │
├────────────────────────────┼────────────────────────────────────┤
│                            │                                    │
│  Theme Templates           │  REST API Only                     │
│  ├── header-app.php        │  └── /zaobank-mobile/v1/*          │
│  ├── footer-app.php        │                                    │
│  └── zaobank/templates/*   │  Authentication                    │
│                            │  └── JWT tokens                    │
│  Shortcodes                │                                    │
│  └── [zaobank_dashboard]   │  No templates loaded               │
│                            │  No theme interaction              │
│  Authentication            │                                    │
│  └── WordPress sessions    │                                    │
│                            │                                    │
└────────────────────────────┴────────────────────────────────────┘
```

### Theme Helper Functions

These zaobank-core functions are for **web templates only**:

| Function | Purpose | Used by Mobile? |
|----------|---------|-----------------|
| `zaobank_get_urls()` | Get page URLs for nav | No - use `/config` endpoint |
| `zaobank_is_app_section()` | Check if on /app/ page | No - mobile is API-only |
| `zaobank_render_template()` | Render template HTML | No - mobile renders natively |
| `zaobank_bottom_nav()` | Output bottom nav | No - mobile has native nav |
| `zaobank_user_balance()` | Get time balance | Use `/auth/me` endpoint |
| `zaobank_unread_count()` | Get unread messages | Use `/messages/unread` (if exists) |

### /app/ Page Structure Compatibility

The theme's `/app/` page structure:
```
/app/
├── dashboard/
├── jobs/
├── messages/
└── ...
```

This is for **web-based responsive** access. Mobile apps:
1. Never load these pages
2. Use REST endpoints directly
3. Handle routing natively

**No conflicts** - they serve different access methods.

### Body Classes

The theme adds `zaobank-app-page` class on app pages. This has **no effect** on mobile API responses.

## JWT Authentication System

### Token Flow

```
1. Login Request
   POST /auth/login {username, password}

2. Server Response
   {access_token, refresh_token, expires_in, user}

3. Authenticated Requests
   Authorization: Bearer <access_token>

4. Token Refresh (when access_token expires)
   POST /auth/refresh {refresh_token}
   → New access_token + rotated refresh_token

5. Logout
   POST /auth/logout
   → All tokens revoked
```

### Token Structure

Access tokens are base64-encoded JSON:

```json
{
  "sub": 42,           // User ID
  "iat": 1704067200,   // Issued at
  "exp": 1706659200,   // Expires at
  "jti": "unique-id"   // Token ID
}
```

Tokens are signed with HMAC-SHA256 using the stored secret.

### WordPress Integration

```php
// Priority 20 on determine_current_user filter
add_filter('determine_current_user', array($this, 'authenticate_jwt'), 20);

public function authenticate_jwt($user_id) {
    // Only process if no user already set and Authorization header present
    if ($user_id) {
        return $user_id;
    }

    $token = $this->get_bearer_token();
    if (!$token) {
        return $user_id;
    }

    $payload = ZAOBank_JWT_Tokens::validate_access_token($token);
    if (is_wp_error($payload)) {
        return $user_id; // Let other auth methods try
    }

    return $payload['sub']; // Return user ID from token
}
```

### Security: JWT Secret

The secret is generated on activation:

```php
$secret = wp_generate_password(64, true, true);
add_option('zaobank_mobile_jwt_secret', $secret);
```

**Critical:** Changing this invalidates ALL existing tokens.

## Geolocation System

### Geocoding Flow

```
Job Saved → Extract Location → Google Maps API → Store Coordinates
                                      ↓
                              wp_zaobank_locations table
```

### Privacy: Location Fuzzing

```php
// Precision levels
'exact' → No fuzzing (0m offset)
'block' → ~200m random offset
'city'  → ~5km random offset

// Applied on storage, not retrieval
$fuzzed = ZAOBank_Location_Privacy::fuzz_location($lat, $lng, $precision);
```

**Warning:** Fuzzing is permanent. Original coordinates are not stored.

### Distance Calculations

```php
// Haversine formula for great-circle distance
$distance = ZAOBank_Distance_Calculator::calculate($lat1, $lng1, $lat2, $lng2);

// Returns distance in configured unit (miles or km)
```

### API Key Resolution

```php
// Check plugin setting first
$key = get_option('zaobank_mobile_google_api_key');

// Fallback to Formidable Geo
if (empty($key)) {
    $frm_settings = get_option('frm_geo_options');
    $key = $frm_settings['google_api_key'] ?? '';
}
```

## REST API Reference

### Base URL

```
/wp-json/zaobank-mobile/v1/
```

### Authentication Endpoints

#### POST /auth/login

```json
// Request
{
  "username": "user@example.com",
  "password": "secret"
}

// Response (200)
{
  "access_token": "eyJ...",
  "refresh_token": "abc123...",
  "expires_in": 2592000,
  "user": {
    "id": 42,
    "email": "user@example.com",
    "display_name": "John Doe",
    "avatar_url": "https://..."
  }
}

// Error (401)
{
  "code": "invalid_credentials",
  "message": "Invalid username or password"
}
```

#### POST /auth/refresh

```json
// Request
{
  "refresh_token": "abc123..."
}

// Response (200)
{
  "access_token": "eyJ...",
  "refresh_token": "def456...",  // Rotated!
  "expires_in": 2592000
}
```

#### GET /auth/me (JWT Required)

```json
// Response (200)
{
  "id": 42,
  "email": "user@example.com",
  "display_name": "John Doe",
  "avatar_url": "https://...",
  "balance": 12.5,
  "profile": {
    "bio": "...",
    "skills": "...",
    "primary_region": {...}
  }
}
```

### Jobs Endpoints

#### GET /jobs (JWT Required)

Query parameters:
- `page` (int) - Page number
- `per_page` (int) - Items per page (max 100)
- `region` (int) - Filter by region ID
- `status` (string) - Filter by status
- `lat`, `lng` (float) - Include distance from point
- `radius` (float) - Filter by distance (requires lat/lng)

```json
// Response
{
  "jobs": [
    {
      "id": 123,
      "title": "Help with gardening",
      "excerpt": "...",
      "hours": 2.5,
      "status": "available",
      "requester": {...},
      "distance": 3.2,  // Only if lat/lng provided
      "regions": [...]
    }
  ],
  "total": 42,
  "pages": 3
}
```

#### GET /jobs/nearby (JWT Required)

Uses user's saved location.

```json
// Query: ?radius=10

// Response
{
  "jobs": [...],
  "user_location": {
    "lat": 43.0389,
    "lng": -87.9065,
    "precision": "block"
  }
}
```

### Location Endpoints

#### GET /location (JWT Required)

```json
// Response
{
  "enabled": true,
  "precision": "block",
  "lat": 43.04,      // May be fuzzed
  "lng": -87.91,
  "updated_at": "2024-01-15T10:30:00Z"
}
```

#### POST /location/update (JWT Required)

```json
// Request
{
  "lat": 43.0389,
  "lng": -87.9065
}

// Response (200)
{
  "success": true,
  "location": {
    "lat": 43.04,    // Fuzzed based on precision
    "lng": -87.91
  }
}
```

#### POST /location/settings (JWT Required)

```json
// Request
{
  "enabled": true,
  "precision": "city"  // exact, block, or city
}

// Response (200)
{
  "enabled": true,
  "precision": "city"
}
```

### Config Endpoints

#### GET /config

```json
{
  "min_version": "1.0.0",
  "features": {
    "registration_enabled": true,
    "location_enabled": true
  },
  "urls": {
    "testflight": "https://...",
    "appstore": "https://...",
    "playstore": "https://..."
  },
  "settings": {
    "default_radius": 25,
    "max_radius": 100,
    "distance_unit": "miles"
  }
}
```

#### GET /config/version-check

```json
// Query: ?version=1.0.0

// Response (supported)
{
  "supported": true,
  "current_version": "1.0.0",
  "min_version": "1.0.0"
}

// Response (unsupported)
{
  "supported": false,
  "current_version": "0.9.0",
  "min_version": "1.0.0",
  "update_url": "https://..."
}
```

## Database Schema

### wp_zaobank_mobile_refresh_tokens

```sql
CREATE TABLE wp_zaobank_mobile_refresh_tokens (
    id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id bigint(20) UNSIGNED NOT NULL,
    token_hash varchar(64) NOT NULL,        -- SHA-256 hash
    expires_at datetime NOT NULL,
    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at datetime DEFAULT NULL,
    user_agent varchar(255) DEFAULT NULL,
    ip_address varchar(45) DEFAULT NULL,
    PRIMARY KEY (id),
    KEY user_id (user_id),
    KEY token_hash (token_hash),
    KEY expires_at (expires_at)
);
```

### wp_zaobank_locations

```sql
CREATE TABLE wp_zaobank_locations (
    id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    object_type varchar(50) NOT NULL,       -- 'job' or 'user'
    object_id bigint(20) UNSIGNED NOT NULL,
    lat decimal(10,8) NOT NULL,
    lng decimal(11,8) NOT NULL,
    address varchar(255) DEFAULT NULL,
    precision varchar(20) DEFAULT 'exact',
    geocoded_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY object (object_type, object_id),
    KEY coordinates (lat, lng)
);
```

### User Meta Keys

```php
'zaobank_location_enabled'     // bool: Location services on/off
'zaobank_location_precision'   // string: exact, block, city
'zaobank_last_lat'             // float: Last known latitude
'zaobank_last_lng'             // float: Last known longitude
'zaobank_location_updated_at'  // datetime: Last update time
```

## Hooks & Filters

### Actions

```php
// After successful mobile registration
do_action('zaobank_mobile_user_registered', $user_id, $user_data);

// After successful login
do_action('zaobank_mobile_user_logged_in', $user_id);

// After token refresh
do_action('zaobank_mobile_token_refreshed', $user_id);

// After logout (tokens revoked)
do_action('zaobank_mobile_user_logged_out', $user_id);

// After location update
do_action('zaobank_mobile_location_updated', $user_id, $lat, $lng);

// After job geocoded
do_action('zaobank_mobile_job_geocoded', $job_id, $lat, $lng);
```

### Filters

```php
// Modify JWT expiration (seconds)
$expiration = apply_filters('zaobank_mobile_jwt_expiration', 30 * DAY_IN_SECONDS);

// Modify refresh token expiration
$expiration = apply_filters('zaobank_mobile_refresh_expiration', 90 * DAY_IN_SECONDS);

// Modify user data in auth response
$user_data = apply_filters('zaobank_mobile_auth_user_data', $user_data, $user_id);

// Modify job data in API response
$job_data = apply_filters('zaobank_mobile_job_data', $job_data, $job_id);

// Modify location precision options
$options = apply_filters('zaobank_mobile_precision_options', ['exact', 'block', 'city']);

// Modify fuzzing distance (meters)
$distance = apply_filters('zaobank_mobile_fuzz_distance', 200, 'block');
$distance = apply_filters('zaobank_mobile_fuzz_distance', 5000, 'city');
```

## Security Considerations

### Authentication Security

1. **Tokens are signed** with HMAC-SHA256
2. **Refresh tokens are hashed** before storage
3. **Token rotation** on refresh (old token invalidated)
4. **All tokens revoked** on logout

### Rate Limiting

Consider adding rate limiting for:
- `/auth/login` - Prevent brute force
- `/auth/register` - Prevent spam
- `/location/update` - Prevent abuse

### Input Validation

All endpoints sanitize input:
```php
$lat = floatval($request->get_param('lat'));
$lng = floatval($request->get_param('lng'));
$username = sanitize_user($request->get_param('username'));
```

### CORS Configuration

The plugin does not set CORS headers. Configure in `.htaccess` or server config:

```apache
Header set Access-Control-Allow-Origin "*"
Header set Access-Control-Allow-Headers "Authorization, Content-Type"
Header set Access-Control-Allow-Methods "GET, POST, PUT, DELETE, OPTIONS"
```

## Troubleshooting

### Debug Mode

Enable WordPress debug logging:

```php
// wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Check `/wp-content/debug.log` for errors.

### Common Issues

#### "Required plugin not active"

zaobank-core is not activated. Activate it first.

#### "Invalid token"

- Token expired - refresh it
- Token malformed - re-authenticate
- JWT secret changed - all tokens invalid

#### "Geocoding failed"

- Google API key not configured
- API key doesn't have Geocoding API enabled
- API quota exceeded

#### "Location not updating"

- Check user's precision setting
- Verify coordinates are valid floats
- Check for rate limiting

### Testing Endpoints

```bash
# Test login
curl -X POST http://localhost/wp-json/zaobank-mobile/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"test","password":"test"}'

# Test authenticated endpoint
curl http://localhost/wp-json/zaobank-mobile/v1/auth/me \
  -H "Authorization: Bearer <token>"

# Test jobs with location
curl "http://localhost/wp-json/zaobank-mobile/v1/jobs?lat=43.0389&lng=-87.9065&radius=10"
```

### Database Queries

```sql
-- Check token status
SELECT user_id, expires_at, last_used_at
FROM wp_zaobank_mobile_refresh_tokens
WHERE user_id = 42;

-- Check geocoded jobs
SELECT object_id, lat, lng, geocoded_at
FROM wp_zaobank_locations
WHERE object_type = 'job'
ORDER BY geocoded_at DESC
LIMIT 10;

-- Check user location settings
SELECT user_id, meta_key, meta_value
FROM wp_usermeta
WHERE meta_key LIKE 'zaobank_location%'
AND user_id = 42;
```

## License

GPL v2 or later
