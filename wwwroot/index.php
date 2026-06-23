<?php

require_once '../vendor/autoload.php';

$f3 = \Base::instance();
$f3->set('TEMP', '../tmp/');
$f3->set('UI', '../ui/');
$f3->set('ADMIN_NAME', 'fiktivfalu_admin'); // Default admin username, can be changed in config.ini

$db = new \DB\SQL('sqlite:' . dirname(__DIR__) . '/db/db.sqlite');

$db->exec(
    'CREATE TABLE IF NOT EXISTS placemarks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        description TEXT DEFAULT \'\',
        lat REAL NOT NULL,
        lng REAL NOT NULL,
        postcode INT NOT NULL DEFAULT 0,
        email TEXT DEFAULT NULL,
        category_id INTEGER,
        visible BOOLEAN NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL
    )'
);

$db->exec(
    'CREATE TABLE IF NOT EXISTS categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        color TEXT NOT NULL,
        icon TEXT NOT NULL,
        created_at TEXT NOT NULL
    )'
);





$getPublicPlacemarksWithCategory = static function (\DB\SQL $db): array {
    return $db->exec(
        'SELECT p.id,
                p.name,
                p.description,
                p.lat,
                p.lng,
                p.postcode,
                p.created_at AS createdAt,
                p.category_id AS categoryId,
                c.name AS categoryName,
                c.color AS categoryColor,
                c.icon AS categoryIcon
         FROM placemarks p
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE p.visible = 1
         ORDER BY p.id DESC'
    );
};

$getAdminPlacemarksWithCategory = static function (\DB\SQL $db): array {
    return $db->exec(
        'SELECT p.id,
                p.name,
                p.description,
                p.lat,
                p.lng,
                p.postcode,
                p.email,
                p.visible,
                p.created_at AS createdAt,
                p.category_id AS categoryId,
                c.name AS categoryName,
                c.color AS categoryColor,
                c.icon AS categoryIcon
         FROM placemarks p
         LEFT JOIN categories c ON c.id = p.category_id
         ORDER BY p.id DESC'
    );
};

$getCategories = static function (\DB\SQL $db): array {
    return $db->exec(
        'SELECT id, name, color, icon, created_at AS createdAt
         FROM categories
         ORDER BY name ASC'
    );
};

$sendJson = static function (array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};

/**
 * Sanitize a raw user-supplied string:
 *   - cast to string
 *   - strip NUL bytes
 *   - normalise line endings
 *   - remove control characters except \t, \n, \r
 *   - trim surrounding whitespace
 */
$sanitizeString = static function ($value, int $maxLength = 1000): string {
    $str = (string) $value;
    $str = str_replace("\0", '', $str);                   // strip NUL
    $str = preg_replace('/[\x01-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $str); // strip control chars
    $str = mb_substr(trim($str), 0, $maxLength, 'UTF-8');
    return $str;
};

/**
 * Parse and sanitize the JSON body common to all write routes.
 * Returns the decoded array or null on failure.
 */
$parseBody = static function (string $raw): ?array {
    $decoded = json_decode(trim($raw), true);
    return is_array($decoded) ? $decoded : null;
};

$f3->route('OPTIONS /api/placemarks',
    function () {
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        http_response_code(204);
    }
);

$f3->route('OPTIONS /api/admin/placemarks',
    function () {
        header('Access-Control-Allow-Methods: GET, PUT, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        http_response_code(204);
    }
);

$f3->route('OPTIONS /api/admin/placemarks/@id',
    function () {
        header('Access-Control-Allow-Methods: PUT, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        http_response_code(204);
    }
);

$f3->route('OPTIONS /api/categories',
    function () {
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        http_response_code(204);
    }
);

$f3->route('OPTIONS /api/admin/categories',
    function () {
        header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        http_response_code(204);
    }
);

$f3->route('OPTIONS /api/admin/categories/@id',
    function () {
        header('Access-Control-Allow-Methods: PUT, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        http_response_code(204);
    }
);

$f3->route('OPTIONS /api/admin/search',
    function () {
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        http_response_code(204);
    }
);

$f3->route('GET /api/admin/placemarks',
    function ($f3) use ($db, $sendJson, $getAdminPlacemarksWithCategory) {
        auth();
        try {
            $placemarks = $getAdminPlacemarksWithCategory($db);

            $sendJson([
                'ok' => true,
                'count' => count($placemarks),
                'data' => $placemarks,
            ]);
        } catch (\Throwable $e) {
            $sendJson([
                'ok' => false,
                'error' => 'Failed to load admin placemark list',
            ], 500);
        }
    }
);

$f3->route('GET /api/admin/categories',
    function ($f3) use ($db, $sendJson, $getCategories) {
        auth();
        try {
            $categories = $getCategories($db);
            $sendJson([
                'ok' => true,
                'count' => count($categories),
                'data' => $categories,
            ]);
        } catch (\Throwable $e) {
            $sendJson([
                'ok' => false,
                'error' => 'Failed to load categories',
            ], 500);
        }
    }
);

$f3->route('GET /api/admin/search',
    function ($f3) use ($db, $sendJson, $sanitizeString) {
        auth();
        $query = $sanitizeString($f3->get('GET.q') ?? '', 200);
        
        if ($query === '') {
            $sendJson([
                'ok' => true,
                'data' => [],
            ]);
            return;
        }

        try {
            // Escape special SQLite characters for LIKE
            $searchTerm = '%' . str_replace(['%', '_'], ['\%', '\_'], $query) . '%';
            
            $placemarks = $db->exec(
                'SELECT p.id,
                        p.name,
                        p.description,
                        p.lat,
                        p.lng,
                        p.postcode,
                        p.email,
                        p.visible,
                        p.created_at AS createdAt,
                        p.category_id AS categoryId,
                        c.name AS categoryName,
                        c.color AS categoryColor,
                        c.icon AS categoryIcon
                 FROM placemarks p
                 LEFT JOIN categories c ON c.id = p.category_id
                 WHERE p.name LIKE ? ESCAPE \'\\\'
                    OR p.postcode LIKE ? ESCAPE \'\\\'
                    OR p.email LIKE ? ESCAPE \'\\\'
                    OR p.description LIKE ? ESCAPE \'\\\'
                 ORDER BY p.id DESC',
                [$searchTerm, $searchTerm, $searchTerm, $searchTerm]
            );

            $sendJson([
                'ok' => true,
                'count' => count($placemarks),
                'data' => $placemarks,
            ]);
        } catch (\Throwable $e) {
            $sendJson([
                'ok' => false,
                'error' => 'Failed to search placemarks',
            ], 500);
        }
    }
);

$f3->route('POST /api/admin/categories',
    function ($f3) use ($db, $sendJson, $parseBody, $sanitizeString, $getCategories) {
        auth();
        $payload = $parseBody((string) $f3->get('BODY'));

        if ($payload === null) {
            $sendJson([
                'ok' => false,
                'error' => 'Invalid JSON body',
            ], 400);
            return;
        }

        $name  = $sanitizeString($payload['name']  ?? '', 120);
        $color = $sanitizeString($payload['color'] ?? '', 7);
        $icon  = $sanitizeString($payload['icon']  ?? '', 60);

        if ($name === '' || $color === '' || $icon === '') {
            $sendJson([
                'ok' => false,
                'error' => 'Fields name, color and icon are required',
            ], 422);
            return;
        }

        if (!preg_match('/^#[A-Fa-f0-9]{6}$/', $color)) {
            $sendJson([
                'ok' => false,
                'error' => 'Color must be a hex code like #1A2B3C',
            ], 422);
            return;
        }

        if (!preg_match('/^bi-[a-z0-9-]+$/i', $icon)) {
            $sendJson([
                'ok' => false,
                'error' => 'Icon must be a Bootstrap Icons class like bi-tree-fill',
            ], 422);
            return;
        }

        try {
            $db->exec(
                'INSERT INTO categories (name, color, icon, created_at)
                 VALUES (?, ?, ?, ?)',
                [$name, $color, $icon, gmdate('c')]
            );

            $created = $db->exec(
                'SELECT id, name, color, icon, created_at AS createdAt
                 FROM categories
                 WHERE id = ?',
                [$db->lastInsertId()]
            );

            $sendJson([
                'ok' => true,
                'data' => $created[0] ?? null,
            ], 201);
        } catch (\Throwable $e) {
            $sendJson([
                'ok' => false,
                'error' => 'Failed to create category. Name must be unique.',
            ], 409);
        }
    }
);

$f3->route('PUT /api/admin/categories/@id',
    function ($f3) use ($db, $sendJson, $parseBody, $sanitizeString) {
        auth();
        $id = (int) $f3->get('PARAMS.id');
        if ($id <= 0) {
            $sendJson([
                'ok' => false,
                'error' => 'Invalid category id',
            ], 422);
            return;
        }

        $payload = $parseBody((string) $f3->get('BODY'));

        if ($payload === null) {
            $sendJson([
                'ok' => false,
                'error' => 'Invalid JSON body',
            ], 400);
            return;
        }

        $name  = $sanitizeString($payload['name']  ?? '', 120);
        $color = $sanitizeString($payload['color'] ?? '', 7);
        $icon  = $sanitizeString($payload['icon']  ?? '', 60);

        if ($name === '' || $color === '' || $icon === '') {
            $sendJson([
                'ok' => false,
                'error' => 'Fields name, color and icon are required',
            ], 422);
            return;
        }

        if (!preg_match('/^#[A-Fa-f0-9]{6}$/', $color)) {
            $sendJson([
                'ok' => false,
                'error' => 'Color must be a hex code like #1A2B3C',
            ], 422);
            return;
        }

        if (!preg_match('/^bi-[a-z0-9-]+$/i', $icon)) {
            $sendJson([
                'ok' => false,
                'error' => 'Icon must be a Bootstrap Icons class like bi-tree-fill',
            ], 422);
            return;
        }

        try {
            $existing = $db->exec('SELECT id FROM categories WHERE id = ?', [$id]);
            if (count($existing) === 0) {
                $sendJson([
                    'ok' => false,
                    'error' => 'Category not found',
                ], 404);
                return;
            }

            $db->exec(
                'UPDATE categories
                 SET name = ?, color = ?, icon = ?
                 WHERE id = ?',
                [$name, $color, $icon, $id]
            );

            $updated = $db->exec(
                'SELECT id, name, color, icon, created_at AS createdAt
                 FROM categories
                 WHERE id = ?',
                [$id]
            );

            $sendJson([
                'ok' => true,
                'data' => $updated[0] ?? null,
            ]);
        } catch (\Throwable $e) {
            $sendJson([
                'ok' => false,
                'error' => 'Failed to update category. Name must be unique.',
            ], 409);
        }
    }
);

$f3->route('GET /api/categories',
    function ($f3) use ($db, $sendJson, $getCategories) {
        try {
            $categories = $getCategories($db);
            $sendJson([
                'ok' => true,
                'data' => $categories,
            ]);
        } catch (\Throwable $e) {
            $sendJson([
                'ok' => false,
                'error' => 'Failed to load categories',
            ], 500);
        }
    }
);

$f3->route('GET /api/placemarks',
    function ($f3) use ($db, $sendJson, $getPublicPlacemarksWithCategory) {
        try {
            $placemarks = $getPublicPlacemarksWithCategory($db);

            $sendJson([
                'ok' => true,
                'data' => $placemarks,
            ]);
        } catch (\Throwable $e) {
            $sendJson([
                'ok' => false,
                'error' => 'Failed to load placemarks',
            ], 500);
        }
    }
);

$f3->route('POST /api/placemarks',
    function ($f3) use ($db, $sendJson, $parseBody, $sanitizeString) {
        $payload = $parseBody((string) $f3->get('BODY'));

        if ($payload === null) {
            $sendJson([
                'ok' => false,
                'error' => 'Invalid JSON body',
            ], 400);
            return;
        }

        $name        = $sanitizeString($payload['name']        ?? '', 200);
        $lat         = $payload['lat']        ?? null;
        $lng         = $payload['lng']        ?? null;
        $description = $sanitizeString($payload['description'] ?? '', 2000);
        $categoryId  = $payload['categoryId'] ?? null;
        $postcode    = $sanitizeString($payload['postcode']    ?? '', 20);
        $email       = $sanitizeString($payload['email']      ?? '', 254);

        if ($name === '' || !is_numeric($lat) || !is_numeric($lng) || !is_numeric($categoryId) || $postcode === '') {
            $sendJson([
                'ok' => false,
                'error' => 'Fields name, lat, lng, postcode and categoryId are required',
            ], 422);
            return;
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $sendJson([
                'ok' => false,
                'error' => 'Email must be a valid email address when provided',
            ], 422);
            return;
        }

        try {
            $category = $db->exec(
                'SELECT id FROM categories WHERE id = ?',
                [(int) $categoryId]
            );

            if (count($category) === 0) {
                $sendJson([
                    'ok' => false,
                    'error' => 'Selected category does not exist',
                ], 422);
                return;
            }

            $db->exec(
                'INSERT INTO placemarks (name, description, lat, lng, postcode, email, created_at, category_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $name,
                    $description,
                    (float) $lat,
                    (float) $lng,
                    $postcode,
                    $email === '' ? null : $email,
                    gmdate('c'),
                    (int) $categoryId,
                ]
            );

            $created = $db->exec(
                'SELECT p.id,
                        p.name,
                        p.description,
                        p.lat,
                        p.lng,
                        p.postcode,
                        p.created_at AS createdAt,
                        p.category_id AS categoryId,
                        c.name AS categoryName,
                        c.color AS categoryColor,
                        c.icon AS categoryIcon
                 FROM placemarks p
                 LEFT JOIN categories c ON c.id = p.category_id
                 WHERE p.id = ?',
                [$db->lastInsertId()]
            );

            $sendJson([
                'ok' => true,
                'data' => $created[0] ?? null,
            ], 201);
        } catch (\Throwable $e) {
            $sendJson([
                'ok' => false,
                'error' => 'Failed to create placemark',
            ], 500);
        }
    }
);

$f3->route('PUT /api/admin/placemarks/@id',
    function ($f3) use ($db, $sendJson, $parseBody, $sanitizeString) {
        auth();
        $id = (int) $f3->get('PARAMS.id');
        if ($id <= 0) {
            $sendJson([
                'ok' => false,
                'error' => 'Invalid placemark id',
            ], 422);
            return;
        }

        $payload = $parseBody((string) $f3->get('BODY'));

        if ($payload === null) {
            $sendJson([
                'ok' => false,
                'error' => 'Invalid JSON body',
            ], 400);
            return;
        }

        $name        = $sanitizeString($payload['name']        ?? '', 200);
        $lat         = $payload['lat']        ?? null;
        $lng         = $payload['lng']        ?? null;
        $description = $sanitizeString($payload['description'] ?? '', 2000);
        $categoryId  = $payload['categoryId'] ?? null;
        $postcode    = $sanitizeString($payload['postcode']    ?? '', 20);
        $email       = $sanitizeString($payload['email']      ?? '', 254);
        $visible     = !empty($payload['visible']) ? 1 : 0;

        if ($name === '' || !is_numeric($lat) || !is_numeric($lng) || !is_numeric($categoryId) || $postcode === '') {
            $sendJson([
                'ok' => false,
                'error' => 'Fields name, lat, lng, postcode and categoryId are required',
            ], 422);
            return;
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $sendJson([
                'ok' => false,
                'error' => 'Email must be a valid email address when provided',
            ], 422);
            return;
        }

        try {
            $existingPlacemark = $db->exec('SELECT id FROM placemarks WHERE id = ?', [$id]);
            if (count($existingPlacemark) === 0) {
                $sendJson([
                    'ok' => false,
                    'error' => 'Placemark not found',
                ], 404);
                return;
            }

            $category = $db->exec('SELECT id FROM categories WHERE id = ?', [(int) $categoryId]);
            if (count($category) === 0) {
                $sendJson([
                    'ok' => false,
                    'error' => 'Selected category does not exist',
                ], 422);
                return;
            }

            $db->exec(
                'UPDATE placemarks
                 SET name = ?,
                     description = ?,
                     lat = ?,
                     lng = ?,
                     postcode = ?,
                     email = ?,
                     category_id = ?,
                     visible = ?
                 WHERE id = ?',
                [
                    $name,
                    $description,
                    (float) $lat,
                    (float) $lng,
                    $postcode,
                    $email === '' ? null : $email,
                    (int) $categoryId,
                    $visible,
                    $id,
                ]
            );

            $updated = $db->exec(
                'SELECT p.id,
                        p.name,
                        p.description,
                        p.lat,
                        p.lng,
                        p.postcode,
                        p.email,
                        p.visible,
                        p.created_at AS createdAt,
                        p.category_id AS categoryId,
                        c.name AS categoryName,
                        c.color AS categoryColor,
                        c.icon AS categoryIcon
                 FROM placemarks p
                 LEFT JOIN categories c ON c.id = p.category_id
                 WHERE p.id = ?',
                [$id]
            );

            $sendJson([
                'ok' => true,
                'data' => $updated[0] ?? null,
            ]);
        } catch (\Throwable $e) {
            $sendJson([
                'ok' => false,
                'error' => 'Failed to update placemark',
            ], 500);
        }
    }
);

$f3->route('GET /',
    function($f3) {
        echo View::instance()->render('index.html');
        
    }
);
$f3->route('GET /admin',
    function($f3) {
        auth();
        echo View::instance()->render('admin.html');
    }
);

// AUTH RELATED FUNCTIONS
function auth() {
  $db  = new \DB\Jig ( '../db/users/' ); 
  $f3 = Base::instance();
  $db_mapper = new \DB\Jig\Mapper($db, 'users');
  // if there is no data in the db, create a new user with a hashed random password and display the password
  if ($db_mapper->count() === 0) {
    $random_password = bin2hex(random_bytes(16));
    $db_mapper->copyfrom(['username' => $f3->get('ADMIN_NAME'), 'password' => md5($random_password)] );
    $db_mapper->insert();
    echo 'Your password is: ' . $random_password;
    //set the name of the user to the session
    new Session();
    $f3->set('SESSION.username', $db_mapper->username);
    die();
  }

  $auth = new \Auth($db_mapper, array('id'=>'username','pw' =>'password'));
  if (!$auth->basic('md5')) {
    echo 'Unauthenticated'; 
    die();
  }
}

// Create a new user with a hashed random password and display the password
// use the parameter 'name' for the username
$f3->route('GET /create-user/@name', function($f3) {
  auth();
  // check if the username in the session is $f3->get('ADMIN_NAME'
  new Session();
  if ($f3->get('SESSION.username') == $f3->get('ADMIN_NAME')) {
    $random_password = bin2hex(random_bytes(16));
    $db  = new \DB\Jig ( '../db/users/' ); 
    $db_mapper = new \DB\Jig\Mapper($db, 'users');
    $db_mapper->copyfrom(['username' => $f3->get('PARAMS.name'), 'password' => md5($random_password)] );
    $db_mapper->insert();
    echo 'Your password is: ' . $random_password;
    die();
  } else {
    echo 'You are not allowed to create a new user';
    die();
  }

});


$f3->route('GET /reset-password/@name', function($f3) {
  auth();
  new Session();
  if ($f3->get('SESSION.username') == $f3->get('ADMIN_NAME')) {
    $random_password = bin2hex(random_bytes(16));
    $db  = new \DB\Jig ( '../db/users/' ); 
    $db_mapper = new \DB\Jig\Mapper($db, 'users');
    $user = $db_mapper->find(['username=?', $f3->get('PARAMS.name')]);
    if ($user) {
      $db_mapper->copyfrom(['username' => $f3->get('PARAMS.name'), 'password' => md5($random_password)] );
      $db_mapper->insert();
      echo 'The new password is: ' . $random_password;
      die();
    }
  } else {
    echo 'You are not allowed to reset the password of any user';
    die();
  }
});

//logout and remove the session
$f3->route('GET /logout', function($f3) {
  new Session();
  $f3->set('SESSION.username', '');
  echo 'You are now logged out';
  echo '<a href="/">Back</a>';
  die();
});


$f3->run();
