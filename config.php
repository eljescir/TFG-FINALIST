<?php
// All the settings you change when moving to your live host live here.
return [
  // Database (on your host, use the database name, user and password from cPanel, NOT root)
  'db_host' => '127.0.0.1',
  'db_name' => 'tfg_garage',
  'db_user' => 'root',
  'db_pass' => '',

  'timezone' => 'Asia/Manila',
  'utc_offset' => '+08:00',            // keeps MySQL dates in Philippine time too

  // Booking emails
  'mail_enabled' => true,             // set to true on the live host
  'owner_email' => 'thefengs.garage26@gmail.com',   // where new booking notices go
  'mail_from' => 'thefengs.garage26@gmail.com',   // must be an address on your own domain
  'site_url' => 'http://localhost/tfg',       // live: https://yourdomain.com (no trailing slash)

  'shop_name' => "The Feng's Garage",
  'shop_phone' => '+63 917 704 3243',
  'shop_address' => '3759 Estrella St., Bangkal, Makati City',
];
