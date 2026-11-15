<?php
return [
  'title' => env('APP_TITLE', 'Option'),
  'domain' => env('APP_DOMAIN', ''),
  'first_title' => env('APP_FIRST_TITLE', ''),
  'second_title' => env('APP_SECOND_TITLE', 'Option'),
  'support_mail' => env('APP_SUPPORT_MAIL', 'info@'),
  'support_telegram' => env('APP_SUPPORT_TELEGRAM', '@'),
  'logo_url' => env('APP_LOGO_URL', '/images/logo/logo.png'),
  'logo_url_auth' => env('APP_LOGO_URL_AUTH', '/images/logo/logo.png'),
  'icon_url' => env('APP_ICON_URL', '/favicon.ico'),
  'currency' => env('APP_CURRENCY', 'USD'),
  'demo' => env('DEMO_MODE', false),
  'websocket_host' => env('WEBSOCKET_HOST', 'chart.binaries.site')
];
