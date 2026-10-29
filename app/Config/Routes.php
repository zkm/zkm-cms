<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */

// Admin: the sign-in page is server rendered; everything after it is the Vue app and its JSON API.
$routes->group('admin', static function (RouteCollection $routes) {
    $routes->get('login', 'Admin\Auth::login');
    $routes->post('login', 'Admin\Auth::attempt');
    $routes->post('logout', 'Admin\Auth::logout');

    $routes->group('api', ['filter' => 'adminauth', 'namespace' => 'App\Controllers\Admin\Api'], static function (RouteCollection $routes) {
        $routes->get('me', 'Session::me');
        $routes->get('account', 'Account::show');
        $routes->put('account/password', 'Account::password');
        $routes->put('account/email', 'Account::email');
        $routes->post('account/sign-out-others', 'Account::signOutOthers');
        $routes->get('dashboard', 'Session::dashboard');

        $routes->get('posts', 'Posts::index');
        $routes->get('posts/(:num)', 'Posts::show/$1');
        $routes->post('posts', 'Posts::create');
        $routes->put('posts/(:num)', 'Posts::update/$1');
        $routes->delete('posts/(:num)', 'Posts::delete/$1');
        $routes->get('posts/(:num)/revisions', 'Posts::revisions/$1');
        $routes->post('preview', 'Posts::preview');

        $routes->get('terms', 'Terms::index');
        $routes->post('terms', 'Terms::create');
        $routes->put('terms/(:num)', 'Terms::update/$1');
        $routes->delete('terms/(:num)', 'Terms::delete/$1');

        $routes->get('media', 'Media::index');
        $routes->post('media', 'Media::upload');
        $routes->put('media/(:num)', 'Media::update/$1');
        $routes->delete('media/(:num)', 'Media::delete/$1');

        $routes->get('menus', 'Menus::index');
        $routes->put('menus/(:segment)', 'Menus::save/$1');

        $routes->get('settings', 'Settings::index');
        $routes->put('settings', 'Settings::save');

        $routes->get('redirects', 'Redirects::index');
        $routes->post('redirects', 'Redirects::create');
        $routes->delete('redirects/(:num)', 'Redirects::delete/$1');

        $routes->get('messages', 'Messages::index');
        $routes->put('messages/(:num)/read', 'Messages::markRead/$1');
        $routes->delete('messages/(:num)', 'Messages::delete/$1');
    });

    $routes->get('/', 'Admin\Spa::index', ['filter' => 'adminauth']);
    $routes->get('(:any)', 'Admin\Spa::index', ['filter' => 'adminauth']);
});

// Public pages answer HEAD as well as GET: link checkers and uptime monitors send HEAD.
$read = ['GET', 'HEAD'];
$routes->match($read, '/', 'Site::home');
$routes->match($read, 'feed', 'Site::feed');
$routes->match($read, 'sitemap.xml', 'Site::sitemap');
// Not addRedirect(): its any-method routes lose to the catch-all below.
$routes->match($read, 'wp-sitemap.xml', 'Site::oldSitemap');
$routes->match($read, 'robots.txt', 'Site::robots');
$routes->match($read, 'favicon.ico', 'Site::favicon');
$routes->post('contact-form', 'Site::contact');

// Everything else: posts, pages, archives, redirects.
$routes->match($read, '(:any)', 'Site::resolve/$1');

$routes->set404Override('App\Controllers\Site::notFound');
