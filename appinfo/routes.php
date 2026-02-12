<?php

declare(strict_types=1);

return [
    'routes' => [
        // Pages
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

        // All Bookings API
        ['name' => 'room_api#all_bookings', 'url' => '/api/all-bookings', 'verb' => 'GET'],

        // Rooms API
        ['name' => 'room_api#index', 'url' => '/api/rooms', 'verb' => 'GET'],
        ['name' => 'room_api#create', 'url' => '/api/rooms', 'verb' => 'POST'],
        ['name' => 'room_api#show', 'url' => '/api/rooms/{id}', 'verb' => 'GET'],
        ['name' => 'room_api#update', 'url' => '/api/rooms/{id}', 'verb' => 'PUT'],
        ['name' => 'room_api#destroy', 'url' => '/api/rooms/{id}', 'verb' => 'DELETE'],

        // Permissions API
        ['name' => 'room_api#get_permissions', 'url' => '/api/rooms/{id}/permissions', 'verb' => 'GET'],
        ['name' => 'room_api#set_permissions', 'url' => '/api/rooms/{id}/permissions', 'verb' => 'PUT'],

        // Room-specific bookings
        ['name' => 'booking_api#index', 'url' => '/api/rooms/{id}/bookings', 'verb' => 'GET'],
        ['name' => 'booking_api#create', 'url' => '/api/rooms/{id}/bookings', 'verb' => 'POST'],
        ['name' => 'booking_api#update', 'url' => '/api/rooms/{id}/bookings/{uid}', 'verb' => 'PUT'],
        ['name' => 'booking_api#respond', 'url' => '/api/rooms/{id}/bookings/{uid}/respond', 'verb' => 'POST'],
        ['name' => 'booking_api#destroy', 'url' => '/api/rooms/{id}/bookings/{uid}', 'verb' => 'DELETE'],

        // Room Groups API
        ['name' => 'room_group_api#index', 'url' => '/api/room-groups', 'verb' => 'GET'],
        ['name' => 'room_group_api#create', 'url' => '/api/room-groups', 'verb' => 'POST'],
        ['name' => 'room_group_api#show', 'url' => '/api/room-groups/{id}', 'verb' => 'GET'],
        ['name' => 'room_group_api#update', 'url' => '/api/room-groups/{id}', 'verb' => 'PUT'],
        ['name' => 'room_group_api#destroy', 'url' => '/api/room-groups/{id}', 'verb' => 'DELETE'],
        ['name' => 'room_group_api#get_permissions', 'url' => '/api/room-groups/{id}/permissions', 'verb' => 'GET'],
        ['name' => 'room_group_api#set_permissions', 'url' => '/api/room-groups/{id}/permissions', 'verb' => 'PUT'],

        // Settings API
        ['name' => 'settings#get', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#save', 'url' => '/api/settings', 'verb' => 'PUT'],

        // User/Group search (for permission editor)
        ['name' => 'room_api#search_sharees', 'url' => '/api/sharees', 'verb' => 'GET'],

        // Debug endpoint
        ['name' => 'room_api#debug', 'url' => '/api/debug/rooms', 'verb' => 'GET'],
    ],
];
