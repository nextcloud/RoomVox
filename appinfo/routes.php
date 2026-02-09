<?php

declare(strict_types=1);

return [
    'routes' => [
        // Pages
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

        // Rooms API
        ['name' => 'room_api#index', 'url' => '/api/rooms', 'verb' => 'GET'],
        ['name' => 'room_api#create', 'url' => '/api/rooms', 'verb' => 'POST'],
        ['name' => 'room_api#show', 'url' => '/api/rooms/{id}', 'verb' => 'GET'],
        ['name' => 'room_api#update', 'url' => '/api/rooms/{id}', 'verb' => 'PUT'],
        ['name' => 'room_api#destroy', 'url' => '/api/rooms/{id}', 'verb' => 'DELETE'],

        // Permissions API
        ['name' => 'room_api#get_permissions', 'url' => '/api/rooms/{id}/permissions', 'verb' => 'GET'],
        ['name' => 'room_api#set_permissions', 'url' => '/api/rooms/{id}/permissions', 'verb' => 'PUT'],

        // Bookings API
        ['name' => 'booking_api#index', 'url' => '/api/rooms/{id}/bookings', 'verb' => 'GET'],
        ['name' => 'booking_api#respond', 'url' => '/api/rooms/{id}/bookings/{uid}/respond', 'verb' => 'POST'],

        // Settings API
        ['name' => 'settings#get', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#save', 'url' => '/api/settings', 'verb' => 'PUT'],

        // User/Group search (for permission editor)
        ['name' => 'room_api#search_sharees', 'url' => '/api/sharees', 'verb' => 'GET'],
    ],
];
