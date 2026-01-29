# ZAO Bank Mobile

Mobile app backend infrastructure for ZAO Bank, providing JWT authentication, geolocation services, and mobile-optimized REST API endpoints.

## Overview

This plugin extends ZAO Bank Core with features specifically designed for native mobile apps:

- **JWT Authentication** - Stateless token-based auth for mobile clients
- **Geolocation Services** - Geocoding, distance calculations, and privacy controls
- **Mobile REST API** - Optimized endpoints for app consumption
- **App Configuration** - Version checking and app store URLs

## Requirements

- WordPress 6.0+
- PHP 7.4+
- **ZAO Bank Core plugin** (required)
- Google Maps API key (for geocoding features)
- ACF Pro (optional, for extended profile fields)

## Dependencies

### Required: ZAO Bank Core

This plugin has a **hard dependency** on ZAO Bank Core. While it includes defensive checks, many features will not work correctly without it:

| Feature | Without zaobank-core |
|---------|---------------------|
| Job listings | Basic data only (no ACF fields) |
| Security checks | **BYPASSED** - all content visible |
| Admin menu | Won't appear (orphaned submenu) |
| Regions filtering | Non-functional |

**Important:** Always ensure zaobank-core is active before activating this plugin.

### Optional: Formidable Geo

If the Formidable Geo plugin is installed, this plugin will automatically use its Google Maps API key. Otherwise, configure the API key in the plugin settings.

## Installation

1. Ensure ZAO Bank Core is installed and activated
2. Upload the `zaobank-mobile` folder to `/wp-content/plugins/`
3. Activate the plugin through the Plugins menu
4. Configure settings at **ZAO Bank > Mobile App**

### Required Configuration

1. **Google Maps API Key** - Required for geocoding jobs and user locations
   - Or install Formidable Geo with an API key configured

2. **JWT Settings** - Auto-configured on activation, but review expiration settings

## Settings

Access via **ZAO Bank > Mobile App** in the WordPress admin.

### Authentication Settings

| Setting | Default | Description |
|---------|---------|-------------|
| JWT Token Expiration | 30 days | How long access tokens are valid |
| Refresh Token Expiration | 90 days | How long refresh tokens are valid |

### Location Settings

| Setting | Default | Description |
|---------|---------|-------------|
| Default Search Radius | 25 | Default radius for nearby job searches |
| Maximum Search Radius | 100 | Maximum allowed search radius |
| Distance Unit | miles | Display unit (miles or km) |
| Google Maps API Key | - | Required for geocoding |

### App Distribution

| Setting | Description |
|---------|-------------|
| Minimum App Version | Minimum version required to use API |
| TestFlight URL | iOS beta testing link |
| App Store URL | iOS production link |
| Google Play URL | Android production link |

## REST API Endpoints

Base URL: `/wp-json/zaobank-mobile/v1/`

### Authentication

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/auth/login` | POST | No | Login with username/password |
| `/auth/register` | POST | No | Register new user |
| `/auth/refresh` | POST | No | Refresh access token |
| `/auth/logout` | POST | No | Revoke all tokens |
| `/auth/me` | GET | JWT | Get current user profile |

### Jobs

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/jobs` | GET | No | List jobs (supports lat/lng for distance) |
| `/jobs/{id}` | GET | No | Get single job |
| `/jobs/nearby` | GET | JWT | Jobs near user's saved location |

### Location

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/location` | GET | JWT | Get user's saved location |
| `/location/update` | POST | JWT | Update user's location |
| `/location/settings` | GET/POST | JWT | Get/set location privacy |
| `/location/clear` | DELETE | JWT | Clear saved location |

### Configuration

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/config` | GET | No | Get app configuration |
| `/config/version-check` | GET | No | Check if app version is supported |

## API Usage Examples

### Login

```bash
curl -X POST https://yoursite.com/wp-json/zaobank-mobile/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username": "user@example.com", "password": "secret"}'
```

Response:
```json
{
  "access_token": "eyJ...",
  "refresh_token": "abc123...",
  "expires_in": 2592000,
  "user": {
    "id": 42,
    "email": "user@example.com",
    "display_name": "John Doe"
  }
}
```

### Authenticated Request

```bash
curl https://yoursite.com/wp-json/zaobank-mobile/v1/auth/me \
  -H "Authorization: Bearer eyJ..."
```

### Get Jobs with Distance

```bash
curl "https://yoursite.com/wp-json/zaobank-mobile/v1/jobs?lat=43.0389&lng=-87.9065&radius=10"
```

## Location Privacy

Users can control how precisely their location is stored:

| Precision | Fuzzing | Use Case |
|-----------|---------|----------|
| `exact` | None | Full accuracy needed |
| `block` | ~200m | Neighborhood-level |
| `city` | ~5km | City-level only |

**Important:** Location fuzzing is applied when coordinates are stored. Original precise coordinates cannot be recovered.

## Database Tables

The plugin creates two tables on activation:

### wp_zaobank_mobile_refresh_tokens

Stores JWT refresh tokens for token rotation.

### wp_zaobank_locations

Stores geocoded coordinates for jobs and users. Shared with zaobank-core.

**Warning:** Uninstalling the plugin will permanently delete these tables and all location data.

## Hooks & Filters

### Actions

```php
// Fired after successful mobile user registration
do_action('zaobank_mobile_user_registered', $user_id, $user_data);
```

### Filters

```php
// Modify JWT token expiration (in seconds)
add_filter('zaobank_mobile_jwt_expiration', function($seconds) {
    return 7 * DAY_IN_SECONDS; // 7 days
});

// Modify refresh token expiration
add_filter('zaobank_mobile_refresh_expiration', function($seconds) {
    return 30 * DAY_IN_SECONDS; // 30 days
});
```

## Integration with ZAO Bank Core

### How They Work Together

```
┌─────────────────────────────────────────────────────────────┐
│                     ZAO Bank Ecosystem                       │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  ┌─────────────────┐         ┌─────────────────────────┐    │
│  │  zaobank-core   │◄───────►│    zaobank-mobile       │    │
│  │                 │         │                         │    │
│  │ • Post Types    │         │ • JWT Auth              │    │
│  │ • Taxonomies    │         │ • Geolocation           │    │
│  │ • Security      │         │ • Mobile REST API       │    │
│  │ • Web Templates │         │ • App Config            │    │
│  │ • Shortcodes    │         │                         │    │
│  └────────┬────────┘         └────────────┬────────────┘    │
│           │                               │                  │
│           ▼                               ▼                  │
│  ┌─────────────────┐         ┌─────────────────────────┐    │
│  │   Web Browser   │         │    Native Mobile App    │    │
│  │   (Templates)   │         │    (REST API + JWT)     │    │
│  └─────────────────┘         └─────────────────────────┘    │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

### Shared Resources

| Resource | Owned By | Used By |
|----------|----------|---------|
| `timebank_job` post type | zaobank-core | Both |
| `zaobank_region` taxonomy | zaobank-core | Both |
| `wp_zaobank_locations` table | zaobank-mobile | Both |
| `ZAOBank_Security` class | zaobank-core | Both |
| `ZAOBank_Jobs` class | zaobank-core | Both |

## Integration with Theme

### No Template Conflicts

This plugin is **API-only** and does not load or override any templates. It works alongside the theme's template system without conflicts.

### Web vs. Mobile Access Patterns

| Access Method | Uses | Authentication |
|---------------|------|----------------|
| Web Browser | Theme templates, shortcodes | WordPress sessions/cookies |
| Mobile App | REST API endpoints | JWT tokens |

The `/app/` page structure in your theme (with `header-app.php`, `footer-app.php`) is for **web-based responsive** access. Native mobile apps use the REST API exclusively.

### Theme Helper Functions

The following functions from zaobank-core work independently of this plugin:

- `zaobank_get_urls()` - Web page URLs (not used by mobile)
- `zaobank_is_app_section()` - Checks web page context
- `zaobank_render_template()` - Renders web templates

Mobile apps should use the `/config` endpoint for navigation URLs and feature flags.

## Important Gotchas

### 1. Security Bypass Without zaobank-core

If zaobank-core is disabled, security checks in the REST API are bypassed. The plugin checks for `ZAOBank_Security` class existence but defaults to allowing access if missing.

**Mitigation:** Never run zaobank-mobile without zaobank-core active.

### 2. Geocoding on Every Job Save

Jobs are automatically geocoded when saved. This:
- Requires a valid Google Maps API key
- Makes external API calls (costs money)
- Adds latency to job saves
- Fails silently if API key is missing

**Mitigation:** Monitor API usage and ensure key is configured.

### 3. JWT Secret is Permanent

The JWT secret is generated once on activation. Changing it requires:
1. Direct database access to `wp_options`
2. All existing tokens become invalid
3. All mobile users must re-authenticate

**Mitigation:** Don't modify the secret unless absolutely necessary.

### 4. Location Data is Fuzzed Permanently

When a user sets location precision to `block` or `city`, coordinates are fuzzed before storage. The original precise location cannot be recovered.

**Mitigation:** Inform users about precision settings.

### 5. Uninstall Deletes All Data

Uninstalling (not just deactivating) the plugin permanently deletes:
- All JWT refresh tokens
- All geocoded location data
- All user location preferences

**Mitigation:** Only deactivate if you plan to reactivate. Backup data before uninstalling.

### 6. Admin Menu Requires zaobank-core

The settings page is added as a submenu under "ZAO Bank" which is registered by zaobank-core. If core is disabled, settings become inaccessible.

**Mitigation:** Keep zaobank-core active, or access settings via direct URL.

## Troubleshooting

### JWT Authentication Fails

1. Check that the `Authorization: Bearer <token>` header is being sent
2. Verify token hasn't expired (check `exp` claim)
3. Ensure JWT secret hasn't changed

### Geocoding Not Working

1. Verify Google Maps API key is configured
2. Check API key has Geocoding API enabled
3. Review API quota/billing in Google Cloud Console
4. Check WordPress debug.log for errors

### Jobs Not Showing Distance

1. Ensure `lat` and `lng` parameters are passed to `/jobs` endpoint
2. Check that jobs have been geocoded (have coordinates in wp_zaobank_locations)
3. Run batch geocoding from admin settings if needed

### Settings Page Not Appearing

1. Ensure zaobank-core is activated
2. Check user has appropriate capabilities
3. Try accessing directly: `/wp-admin/admin.php?page=zaobank-mobile`

## Development

### Running Batch Geocoding

Via admin UI:
1. Go to **ZAO Bank > Mobile App**
2. Scroll to "Batch Geocoding" section
3. Click "Start Geocoding"

Via WP-CLI (if implemented):
```bash
wp zaobank geocode-jobs --batch-size=50
```

### Testing JWT Authentication

```bash
# Get a token
TOKEN=$(curl -s -X POST https://yoursite.com/wp-json/zaobank-mobile/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"test","password":"test"}' | jq -r '.access_token')

# Use the token
curl https://yoursite.com/wp-json/zaobank-mobile/v1/auth/me \
  -H "Authorization: Bearer $TOKEN"
```

### Debugging Location Issues

```sql
-- Check if jobs have coordinates
SELECT object_type, object_id, lat, lng
FROM wp_zaobank_locations
WHERE object_type = 'job';

-- Check user location settings
SELECT user_id, meta_key, meta_value
FROM wp_usermeta
WHERE meta_key LIKE 'zaobank_location%';
```

## Changelog

### 1.0.0
- Initial release
- JWT authentication system
- Geolocation services with privacy controls
- Mobile-optimized REST API endpoints
- App configuration and version checking

## License

GPL v2 or later

## Support

For support and documentation, visit https://zaobank.org
