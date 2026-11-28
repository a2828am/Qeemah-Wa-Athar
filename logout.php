<?php
/**
 * logout.php — revokes the database session, clears the cookie and returns home.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

if (Auth::check()) {
    Auth::logout();
    flash('info', 'تم تسجيل الخروج بنجاح.');
}

redirect('index.php');
