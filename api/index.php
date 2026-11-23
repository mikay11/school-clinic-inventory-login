<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/constants.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

function cross_origin_enabled(): bool
{
    return API_ALLOWED_ORIGIN !== '';
}

function send_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function request_body(): array
{
    static $body = null;
    if ($body !== null) {
        return $body;
    }
    $raw = file_get_contents('php://input');
    $decoded = json_decode(is_string($raw) ? $raw : '', true);
    $body = is_array($decoded) ? $decoded : [];
    return $body;
}

function str_input(string $key, string $default = ''): string
{
    $body = request_body();
    $value = $body[$key] ?? $_GET[$key] ?? $default;
    return trim(is_scalar($value) ? (string)$value : $default);
}

function int_input(string $key, int $default = 0): int
{
    $value = str_input($key, (string)$default);
    return ctype_digit($value) ? (int)$value : $default;
}

function csrf_guard(): void
{
    $body = request_body();
    $token = $body['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!csrf_valid(is_scalar($token) ? (string)$token : null)) {
        send_json(['errors' => ['Your session expired. Please refresh the page and try again.']], 419);
    }
}

function clear_auth_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?? '/',
            'secure' => (bool)($params['secure'] ?? false) || cross_origin_enabled(),
            'httponly' => true,
            'samesite' => cross_origin_enabled() ? 'None' : 'Lax',
        ]);
    }
    session_destroy();
}

function api_require_auth(): void
{
    if (!is_authenticated()) {
        send_json(['error' => 'Please log in to continue.'], 401);
    }
    $idleLimit = SESSION_IDLE_MINUTES * 60;
    $lastActivity = (int)($_SESSION['last_activity'] ?? 0);
    if ($lastActivity > 0 && (time() - $lastActivity) > $idleLimit) {
        clear_auth_session();
        send_json(['error' => 'Your session expired after a period of inactivity.'], 401);
    }
    $_SESSION['last_activity'] = time();
}

function unread_count(?PDO $pdo): int
{
    if ($pdo === null) {
        return 0;
    }
    try {
        return (int)$pdo->query('SELECT COUNT(*) FROM notifications WHERE is_read = 0')->fetchColumn();
    } catch (Throwable $exception) {
        error_log('API notification count error: ' . $exception->getMessage());
        return 0;
    }
}

function session_payload(?PDO $pdo): array
{
    return [
        'authenticated' => is_authenticated(),
        'csrf_token' => csrf_token(),
        'user' => is_authenticated() ? session_identity() : null,
        'unread_count' => is_authenticated() ? unread_count($pdo) : 0,
    ];
}

function medicine_rows_sql(): string
{
    return 'SELECT m.id, m.name, m.generic_name, m.category, m.unit, m.location, m.description,
                   m.low_stock_threshold, m.created_at, m.updated_at,
                   COUNT(b.id) AS batch_count,
                   COALESCE(SUM(b.quantity), 0) AS total_quantity,
                   COALESCE(SUM(CASE WHEN b.expiration_date >= CURDATE() THEN b.quantity ELSE 0 END), 0) AS usable
            FROM medicines m
            LEFT JOIN medicine_batches b ON b.medicine_id = m.id';
}

function medicine_group_sql(): string
{
    return ' GROUP BY m.id, m.name, m.generic_name, m.category, m.unit, m.location,
                      m.description, m.low_stock_threshold, m.created_at, m.updated_at';
}

function status_of(int $usable, int $threshold): string
{
    if ($usable <= 0) {
        return 'Out of stock';
    }
    if ($usable <= $threshold) {
        return 'Low stock';
    }
    return 'In stock';
}

function valid_date(string $value): bool
{
    return $value !== '' && DateTime::createFromFormat('Y-m-d', $value) !== false;
}

function app_base_url(): string
{
    if (APP_BASE_URL !== '') {
        return APP_BASE_URL;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $dir = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = rtrim(str_replace('/index.php', '', $dir), '/');
    if (str_ends_with($dir, '/api')) {
        $dir = substr($dir, 0, -4);
    }
    return ($https ? 'https' : 'http') . '://' . $host . $dir;
}

$pdo = null;
try {
    $pdo = get_db_connection();
} catch (Throwable $exception) {
    error_log('API database connection error: ' . $exception->getMessage());
}

$db = static function () use ($pdo): PDO {
    if ($pdo === null) {
        send_json(['error' => 'The database is unavailable. Please try again later.'], 503);
    }
    return $pdo;
};

$route = (string)($_GET['route'] ?? '');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

$allowedOrigin = API_ALLOWED_ORIGIN;
if ($allowedOrigin !== '' && isset($_SERVER['HTTP_ORIGIN']) && hash_equals($allowedOrigin, (string)$_SERVER['HTTP_ORIGIN'])) {
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
    header('Vary: Origin');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

if ($route === 'session' && $method === 'GET') {
    send_json(session_payload($pdo));
}

if ($route === 'login' && $method === 'POST') {
    csrf_guard();
    $connection = $db();
    $email = strtolower(str_input('email'));
    $password = (string)(request_body()['password'] ?? '');
    $errors = [];

    if (!preg_match('/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.edu\.ph$/', $email)) {
        $errors[] = 'Use your institutional email address ending in @edu.ph.';
    }
    if ($password === '') {
        $errors[] = 'Please enter your password.';
    }

    if (!$errors) {
        $statement = $connection->prepare('SELECT id, email, password_hash, full_name, role, is_active FROM users WHERE email = :email LIMIT 1');
        $statement->execute(['email' => $email]);
        $user = $statement->fetch();
        if (!$user || (int)$user['is_active'] !== 1 || !password_verify($password, (string)$user['password_hash'])) {
            $errors[] = 'Invalid email or password.';
        } else {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['email'] = (string)$user['email'];
            $_SESSION['full_name'] = (string)$user['full_name'];
            $_SESSION['role'] = (string)$user['role'];
            $_SESSION['last_activity'] = time();

            if (!empty(request_body()['remember_me'])) {
                $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
                    || cross_origin_enabled();
                setcookie('clinic_remember', (string)$user['id'], [
                    'expires' => time() + 60 * 60 * 24 * 30,
                    'path' => '/',
                    'secure' => $secure,
                    'httponly' => true,
                    'samesite' => cross_origin_enabled() ? 'None' : 'Lax',
                ]);
            }

            send_json(session_payload($pdo));
        }
    }

    send_json(['errors' => $errors], 422);
}

if ($route === 'logout' && $method === 'POST') {
    csrf_guard();
    clear_auth_session();
    send_json(['ok' => true]);
}

if ($route === 'forgot-password' && $method === 'POST') {
    csrf_guard();
    $connection = $db();
    $email = strtolower(str_input('email'));
    if (!preg_match('/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.edu\.ph$/', $email)) {
        send_json(['errors' => ['Enter a valid institutional email address.']], 422);
    }

    $statement = $connection->prepare('SELECT id, email FROM users WHERE email = :email AND is_active = 1 LIMIT 1');
    $statement->execute(['email' => $email]);
    $user = $statement->fetch();

    if ($user) {
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $connection->prepare('DELETE FROM password_resets WHERE user_id = :id OR expires_at < NOW()')->execute(['id' => (int)$user['id']]);
        $insert = $connection->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (:id, :hash, DATE_ADD(NOW(), INTERVAL 1 HOUR))');
        $insert->execute(['id' => (int)$user['id'], 'hash' => $tokenHash]);

        $link = app_base_url() . '/reset-password?token=' . $token;
        $subject = 'School Clinic Inventory - Password Reset';
        $message = "Use this link within one hour to reset your password:\r\n\r\n" . $link . "\r\n\r\nIf you did not request this, ignore this message.";
        $headers = 'From: ' . MAIL_FROM . "\r\n" . 'Content-Type: text/plain; charset=UTF-8';
        if (!SHOW_RESET_LINK) {
            @mail($email, $subject, $message, $headers);
        }
    }

    $response = ['ok' => true, 'message' => 'If the address is registered, a reset link has been sent.'];
    if (SHOW_RESET_LINK && isset($link)) {
        $response['reset_link'] = $link;
    }
    send_json($response);
}

if ($route === 'reset-password' && $method === 'POST') {
    csrf_guard();
    $connection = $db();
    $token = str_input('token');
    $password = (string)(request_body()['password'] ?? '');
    $confirm = (string)(request_body()['confirm_password'] ?? '');
    $errors = [];

    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        $errors[] = 'This reset link is invalid.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }
    if ($errors) {
        send_json(['errors' => $errors], 422);
    }

    $statement = $connection->prepare(
        'SELECT r.id, r.user_id FROM password_resets r
         WHERE r.token_hash = :hash AND r.expires_at >= NOW() AND r.used_at IS NULL LIMIT 1'
    );
    $statement->execute(['hash' => hash('sha256', $token)]);
    $reset = $statement->fetch();
    if (!$reset) {
        send_json(['errors' => ['This reset link is invalid or has expired.']], 422);
    }

    try {
        $connection->beginTransaction();
        $update = $connection->prepare('UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :id');
        $update->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => (int)$reset['user_id']]);
        $connection->prepare('DELETE FROM password_resets WHERE user_id = :id')->execute(['id' => (int)$reset['user_id']]);
        $connection->commit();
        send_json(['ok' => true]);
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        error_log('API reset password error: ' . $exception->getMessage());
        send_json(['error' => 'The password could not be reset. Please request a new link.'], 500);
    }
}

if ($route === 'dashboard' && $method === 'GET') {
    api_require_auth();
    $connection = $db();
    $medicines = $connection->query(medicine_rows_sql() . medicine_group_sql() . ' ORDER BY m.name')->fetchAll();

    $totalUnits = 0;
    $lowStock = [];
    foreach ($medicines as $medicine) {
        $usable = (int)$medicine['usable'];
        $totalUnits += $usable;
        if ($usable <= (int)$medicine['low_stock_threshold']) {
            $lowStock[] = [
                'id' => (int)$medicine['id'],
                'name' => (string)$medicine['name'],
                'category' => (string)$medicine['category'],
                'unit' => (string)$medicine['unit'],
                'usable' => $usable,
                'threshold' => (int)$medicine['low_stock_threshold'],
            ];
        }
    }
    usort($lowStock, static fn(array $a, array $b): int => $a['usable'] <=> $b['usable']);

    $stockRows = array_map(static fn(array $row): array => [
        'id' => (int)$row['id'],
        'name' => (string)$row['name'],
        'unit' => (string)$row['unit'],
        'usable' => (int)$row['usable'],
        'threshold' => (int)$row['low_stock_threshold'],
    ], $medicines);
    usort($stockRows, static fn(array $a, array $b): int => $b['usable'] <=> $a['usable']);

    $expired = (int)$connection->query('SELECT COUNT(*) FROM medicine_batches WHERE expiration_date < CURDATE()')->fetchColumn();
    $expiring = (int)$connection->query('SELECT COUNT(*) FROM medicine_batches WHERE expiration_date >= CURDATE() AND expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)')->fetchColumn();
    $normal = (int)$connection->query('SELECT COUNT(*) FROM medicine_batches WHERE expiration_date > DATE_ADD(CURDATE(), INTERVAL 30 DAY)')->fetchColumn();

    $expiringRows = $connection->query(
        'SELECT m.name, b.batch_no, b.quantity, b.expiration_date, DATEDIFF(b.expiration_date, CURDATE()) AS days_left
         FROM medicine_batches b JOIN medicines m ON m.id = b.medicine_id
         WHERE b.expiration_date >= CURDATE() AND b.expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
         ORDER BY b.expiration_date ASC LIMIT 8'
    )->fetchAll();

    $activities = $connection->query(
        'SELECT a.activity_type, a.quantity, a.note, a.performed_by, a.created_at, m.name
         FROM inventory_activities a LEFT JOIN medicines m ON m.id = a.medicine_id
         WHERE a.medicine_id IS NOT NULL ORDER BY a.created_at DESC LIMIT 8'
    )->fetchAll();

    generate_inventory_alerts($connection);

    send_json([
        'totals' => [
            'medicines' => count($medicines),
            'units' => $totalUnits,
            'low_stock' => count($lowStock),
            'expired' => $expired,
            'expiring' => $expiring,
            'normal' => $normal,
            'batches' => $expired + $expiring + $normal,
        ],
        'low_stock_rows' => array_slice($lowStock, 0, 8),
        'stock_rows' => array_slice($stockRows, 0, 6),
        'expiring_rows' => $expiringRows,
        'activities' => $activities,
        'unread_count' => unread_count($connection),
    ]);
}

if ($route === 'medicines' && $method === 'GET') {
    api_require_auth();
    $connection = $db();
    $params = [];
    $where = [];
    $q = trim((string)($_GET['q'] ?? ''));
    $category = trim((string)($_GET['category'] ?? ''));
    $status = trim((string)($_GET['status'] ?? 'all'));

    if ($q !== '') {
        $where[] = '(m.name LIKE :q OR m.generic_name LIKE :q OR m.location LIKE :q)';
        $params['q'] = '%' . $q . '%';
    }
    if ($category !== '') {
        $where[] = 'm.category = :category';
        $params['category'] = $category;
    }

    $sql = medicine_rows_sql();
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= medicine_group_sql();
    if ($status === 'in') {
        $sql .= ' HAVING usable > m.low_stock_threshold';
    } elseif ($status === 'low') {
        $sql .= ' HAVING usable > 0 AND usable <= m.low_stock_threshold';
    } elseif ($status === 'out') {
        $sql .= ' HAVING usable = 0';
    }

    $countStatement = $connection->prepare('SELECT COUNT(*) FROM (' . $sql . ') AS filtered');
    $countStatement->execute($params);
    $total = (int)$countStatement->fetchColumn();

    $perPage = max(1, min(100, int_input('per_page', 10)));
    $page = max(1, int_input('page', 1));
    $offset = ($page - 1) * $perPage;

    $sql .= ' ORDER BY m.name LIMIT :limit OFFSET :offset';
    $statement = $connection->prepare($sql);
    foreach ($params as $key => $value) {
        $statement->bindValue($key, $value);
    }
    $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $statement->execute();

    $rows = array_map(static function (array $row): array {
        $row['id'] = (int)$row['id'];
        $row['usable'] = (int)$row['usable'];
        $row['total_quantity'] = (int)$row['total_quantity'];
        $row['batch_count'] = (int)$row['batch_count'];
        $row['low_stock_threshold'] = (int)$row['low_stock_threshold'];
        $row['status'] = status_of($row['usable'], $row['low_stock_threshold']);
        return $row;
    }, $statement->fetchAll());

    send_json(['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
}

if ($route === 'medicine' && $method === 'GET') {
    api_require_auth();
    $connection = $db();
    $id = int_input('id');
    $statement = $connection->prepare(medicine_rows_sql() . medicine_group_sql() . ' HAVING m.id = :id LIMIT 1');
    $statement->execute(['id' => $id]);
    $medicine = $statement->fetch();
    if (!$medicine) {
        send_json(['error' => 'Medicine not found.'], 404);
    }
    $batchStatement = $connection->prepare('SELECT * FROM medicine_batches WHERE medicine_id = :id ORDER BY expiration_date ASC');
    $batchStatement->execute(['id' => $id]);
    $medicine['id'] = (int)$medicine['id'];
    $medicine['usable'] = (int)$medicine['usable'];
    $medicine['low_stock_threshold'] = (int)$medicine['low_stock_threshold'];
    send_json(['medicine' => $medicine, 'batches' => $batchStatement->fetchAll()]);
}

if ($route === 'medicine' && $method === 'POST') {
    api_require_auth();
    csrf_guard();
    $connection = $db();
    $errors = [];
    $name = str_input('name');
    $category = str_input('category', 'Tablet');
    $threshold = int_input('low_stock_threshold', 20);
    $batchNo = str_input('initial_batch_no');
    $quantity = str_input('initial_quantity');
    $received = str_input('received_date');
    $expiration = str_input('expiration_date');

    if ($name === '') {
        $errors[] = 'Medicine name is required.';
    } elseif (mb_strlen($name) > 150) {
        $errors[] = 'Medicine name must be 150 characters or fewer.';
    }
    if (!in_array($category, CATEGORIES, true)) {
        $errors[] = 'Select a valid category.';
    }
    if ($threshold < 0) {
        $errors[] = 'Low-stock threshold cannot be negative.';
    }

    $hasBatch = $batchNo !== '' || $quantity !== '' || $received !== '' || $expiration !== '';
    if ($hasBatch) {
        if ($batchNo === '') {
            $errors[] = 'Initial batch number is required.';
        } elseif (mb_strlen($batchNo) > 60) {
            $errors[] = 'Batch number must be 60 characters or fewer.';
        }
        if (!ctype_digit($quantity) || (int)$quantity <= 0) {
            $errors[] = 'Initial batch quantity must be a positive whole number.';
        }
        if (!valid_date($received)) {
            $errors[] = 'Enter a valid received date.';
        }
        if (!valid_date($expiration)) {
            $errors[] = 'Enter a valid expiration date.';
        }
    }
    if ($errors) {
        send_json(['errors' => $errors], 422);
    }

    try {
        $connection->beginTransaction();
        $duplicate = $connection->prepare('SELECT id FROM medicines WHERE name = :name LIMIT 1');
        $duplicate->execute(['name' => $name]);
        if ($duplicate->fetch()) {
            throw new RuntimeException('A medicine with this name already exists.');
        }

        $insert = $connection->prepare(
            'INSERT INTO medicines (name, generic_name, category, unit, location, description, low_stock_threshold)
             VALUES (:name, :generic, :category, :unit, :location, :description, :threshold)'
        );
        $insert->execute([
            'name' => $name,
            'generic' => mb_substr(str_input('generic_name'), 0, 150),
            'category' => $category,
            'unit' => mb_substr(str_input('unit', 'tablet'), 0, 40),
            'location' => mb_substr(str_input('location'), 0, 80),
            'description' => mb_substr(str_input('description'), 0, 255),
            'threshold' => $threshold,
        ]);
        $id = (int)$connection->lastInsertId();

        if ($hasBatch) {
            $batch = $connection->prepare(
                'INSERT INTO medicine_batches (medicine_id, batch_no, quantity, received_date, expiration_date, supplier, remarks)
                 VALUES (:id, :batch, :quantity, :received, :expiration, :supplier, :remarks)'
            );
            $batch->execute([
                'id' => $id,
                'batch' => $batchNo,
                'quantity' => (int)$quantity,
                'received' => $received,
                'expiration' => $expiration,
                'supplier' => mb_substr(str_input('supplier'), 0, 120),
                'remarks' => mb_substr(str_input('remarks'), 0, 255),
            ]);
        }

        $activity = $connection->prepare(
            'INSERT INTO inventory_activities (activity_type, medicine_id, quantity, note, performed_by)
             VALUES (:type, :id, :quantity, :note, :performed_by)'
        );
        $activity->execute([
            'type' => 'add_medicine',
            'id' => $id,
            'quantity' => $hasBatch ? (int)$quantity : 0,
            'note' => 'Medicine registered' . ($hasBatch ? ' with initial batch ' . $batchNo : ''),
            'performed_by' => (string)($_SESSION['full_name'] ?? 'Clinic Personnel'),
        ]);
        create_notification($connection, 'New medicine "' . $name . '" was added to the inventory.');
        $connection->commit();

        send_json(['ok' => true, 'id' => $id, 'message' => 'Medicine added successfully.'], 201);
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        error_log('API create medicine error: ' . $exception->getMessage());
        send_json(['errors' => [$exception->getMessage()]], 422);
    }
}

if ($route === 'medicine' && $method === 'PUT') {
    api_require_auth();
    csrf_guard();
    $connection = $db();
    $id = int_input('id');
    $errors = [];
    $name = str_input('name');
    $category = str_input('category', 'Tablet');

    if ($name === '') {
        $errors[] = 'Medicine name is required.';
    } elseif (mb_strlen($name) > 150) {
        $errors[] = 'Medicine name must be 150 characters or fewer.';
    }
    if (!in_array($category, CATEGORIES, true)) {
        $errors[] = 'Select a valid category.';
    }
    if ($errors) {
        send_json(['errors' => $errors], 422);
    }

    try {
        $connection->beginTransaction();
        $duplicate = $connection->prepare('SELECT id FROM medicines WHERE name = :name AND id <> :id LIMIT 1');
        $duplicate->execute(['name' => $name, 'id' => $id]);
        if ($duplicate->fetch()) {
            throw new RuntimeException('A medicine with this name already exists.');
        }
        $update = $connection->prepare(
            'UPDATE medicines SET name = :name, generic_name = :generic, category = :category, unit = :unit,
             location = :location, description = :description, low_stock_threshold = :threshold, updated_at = NOW()
             WHERE id = :id'
        );
        $update->execute([
            'name' => $name,
            'generic' => mb_substr(str_input('generic_name'), 0, 150),
            'category' => $category,
            'unit' => mb_substr(str_input('unit', 'tablet'), 0, 40),
            'location' => mb_substr(str_input('location'), 0, 80),
            'description' => mb_substr(str_input('description'), 0, 255),
            'threshold' => int_input('low_stock_threshold', 20),
            'id' => $id,
        ]);
        if ($update->rowCount() < 1) {
            throw new RuntimeException('Medicine not found.');
        }
        $activity = $connection->prepare(
            'INSERT INTO inventory_activities (activity_type, medicine_id, quantity, note, performed_by)
             VALUES (:type, :id, 0, :note, :performed_by)'
        );
        $activity->execute([
            'type' => 'update_medicine',
            'id' => $id,
            'note' => 'Medicine details updated',
            'performed_by' => (string)($_SESSION['full_name'] ?? 'Clinic Personnel'),
        ]);
        $connection->commit();
        send_json(['ok' => true, 'message' => 'Medicine updated successfully.']);
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        error_log('API update medicine error: ' . $exception->getMessage());
        send_json(['errors' => [$exception->getMessage()]], 422);
    }
}

if ($route === 'medicine' && $method === 'DELETE') {
    api_require_auth();
    csrf_guard();
    $connection = $db();
    $id = int_input('id');

    $statement = $connection->prepare('SELECT name FROM medicines WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $id]);
    $medicine = $statement->fetch();
    if (!$medicine) {
        send_json(['error' => 'Medicine not found.'], 404);
    }

    try {
        $connection->beginTransaction();
        $activity = $connection->prepare(
            'INSERT INTO inventory_activities (activity_type, medicine_id, quantity, note, performed_by)
             VALUES (:type, :id, 0, :note, :performed_by)'
        );
        $activity->execute([
            'type' => 'delete_medicine',
            'id' => $id,
            'note' => 'Medicine "' . $medicine['name'] . '" deleted',
            'performed_by' => (string)($_SESSION['full_name'] ?? 'Clinic Personnel'),
        ]);
        delete_notifications_mentioning($connection, (string)$medicine['name']);
        $connection->prepare('DELETE FROM medicines WHERE id = :id')->execute(['id' => $id]);
        $connection->commit();
        send_json(['ok' => true, 'message' => 'Medicine deleted successfully.']);
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        error_log('API delete medicine error: ' . $exception->getMessage());
        send_json(['error' => 'The medicine could not be deleted.'], 500);
    }
}

if ($route === 'medicines/bulk-delete' && $method === 'POST') {
    api_require_auth();
    csrf_guard();
    $connection = $db();
    $body = request_body();
    $ids = $body['ids'] ?? [];
    $ids = is_array($ids) ? array_values(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)) : [];
    if (!$ids) {
        send_json(['errors' => ['Select at least one medicine to delete.']], 422);
    }

    try {
        $connection->beginTransaction();
        $deleted = 0;
        foreach ($ids as $id) {
            $statement = $connection->prepare('SELECT name FROM medicines WHERE id = :id LIMIT 1');
            $statement->execute(['id' => $id]);
            $medicine = $statement->fetch();
            if (!$medicine) {
                continue;
            }
            $activity = $connection->prepare(
                'INSERT INTO inventory_activities (activity_type, medicine_id, quantity, note, performed_by)
                 VALUES (:type, :id, 0, :note, :performed_by)'
            );
            $activity->execute([
                'type' => 'delete_medicine',
                'id' => $id,
                'note' => 'Medicine "' . $medicine['name'] . '" deleted',
                'performed_by' => (string)($_SESSION['full_name'] ?? 'Clinic Personnel'),
            ]);
            delete_notifications_mentioning($connection, (string)$medicine['name']);
            $connection->prepare('DELETE FROM medicines WHERE id = :id')->execute(['id' => $id]);
            $deleted++;
        }
        $connection->commit();
        send_json(['ok' => true, 'deleted' => $deleted, 'message' => $deleted . ' medicine(s) deleted.']);
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        error_log('API bulk delete error: ' . $exception->getMessage());
        send_json(['error' => 'The medicines could not be deleted.'], 500);
    }
}

if ($route === 'stock-options' && $method === 'GET') {
    api_require_auth();
    $connection = $db();
    $medicineId = int_input('medicine_id');
    $medicines = $connection->query('SELECT id, name, unit FROM medicines ORDER BY name')->fetchAll();

    $batchSql = 'SELECT b.id, b.medicine_id, b.batch_no, b.quantity, b.expiration_date, m.name AS medicine_name, m.unit
                 FROM medicine_batches b JOIN medicines m ON m.id = b.medicine_id';
    $params = [];
    if ($medicineId > 0) {
        $batchSql .= ' WHERE b.medicine_id = :id';
        $params['id'] = $medicineId;
    }
    $batchSql .= ' ORDER BY m.name, b.batch_no';
    $batchStatement = $connection->prepare($batchSql);
    $batchStatement->execute($params);

    $history = $connection->query(
        "SELECT a.created_at, a.activity_type, a.quantity, a.note, a.performed_by, m.name
         FROM inventory_activities a LEFT JOIN medicines m ON m.id = a.medicine_id
         WHERE a.activity_type IN ('stock_in', 'stock_out') AND a.medicine_id IS NOT NULL
         ORDER BY a.id DESC LIMIT 6"
    )->fetchAll();

    send_json([
        'medicines' => $medicines,
        'batches' => $batchStatement->fetchAll(),
        'history' => $history,
        'reasons' => STOCK_OUT_REASONS,
    ]);
}

if ($route === 'stock-in' && $method === 'POST') {
    api_require_auth();
    csrf_guard();
    $connection = $db();
    $errors = [];
    $medicineId = int_input('medicine_id');
    $batchNo = str_input('batch_no');
    $quantity = str_input('quantity');
    $received = str_input('received_date');
    $expiration = str_input('expiration_date');

    $medicineStatement = $connection->prepare('SELECT id, name, unit FROM medicines WHERE id = :id LIMIT 1');
    $medicineStatement->execute(['id' => $medicineId]);
    $medicine = $medicineStatement->fetch();

    if (!$medicine) {
        $errors[] = 'Select a medicine from the list.';
    }
    if ($batchNo === '' || mb_strlen($batchNo) > 60) {
        $errors[] = 'Batch number is required (60 characters or fewer).';
    }
    if (!ctype_digit($quantity) || (int)$quantity <= 0) {
        $errors[] = 'Quantity received must be a positive whole number.';
    }
    if (!valid_date($received)) {
        $errors[] = 'Enter a valid received date.';
    }
    if (!valid_date($expiration)) {
        $errors[] = 'Enter a valid expiration date.';
    }
    if ($errors) {
        send_json(['errors' => $errors], 422);
    }

    try {
        $connection->beginTransaction();
        $existing = $connection->prepare('SELECT id FROM medicine_batches WHERE medicine_id = :id AND batch_no = :batch LIMIT 1');
        $existing->execute(['id' => $medicineId, 'batch' => $batchNo]);
        $batchId = (int)$existing->fetchColumn();

        if ($batchId > 0) {
            $update = $connection->prepare(
                'UPDATE medicine_batches SET quantity = quantity + :quantity, received_date = :received,
                 expiration_date = :expiration, supplier = :supplier, remarks = :remarks WHERE id = :id'
            );
            $update->execute([
                'quantity' => (int)$quantity,
                'received' => $received,
                'expiration' => $expiration,
                'supplier' => mb_substr(str_input('supplier'), 0, 120),
                'remarks' => mb_substr(str_input('remarks'), 0, 255),
                'id' => $batchId,
            ]);
        } else {
            $insert = $connection->prepare(
                'INSERT INTO medicine_batches (medicine_id, batch_no, quantity, received_date, expiration_date, supplier, remarks)
                 VALUES (:id, :batch, :quantity, :received, :expiration, :supplier, :remarks)'
            );
            $insert->execute([
                'id' => $medicineId,
                'batch' => $batchNo,
                'quantity' => (int)$quantity,
                'received' => $received,
                'expiration' => $expiration,
                'supplier' => mb_substr(str_input('supplier'), 0, 120),
                'remarks' => mb_substr(str_input('remarks'), 0, 255),
            ]);
        }

        $activity = $connection->prepare(
            'INSERT INTO inventory_activities (activity_type, medicine_id, quantity, note, performed_by)
             VALUES (:type, :id, :quantity, :note, :performed_by)'
        );
        $activity->execute([
            'type' => 'stock_in',
            'id' => $medicineId,
            'quantity' => (int)$quantity,
            'note' => 'Batch ' . $batchNo . ' received',
            'performed_by' => (string)($_SESSION['full_name'] ?? 'Clinic Personnel'),
        ]);
        create_notification(
            $connection,
            (int)$quantity . ' ' . $medicine['unit'] . '(s) of ' . $medicine['name']
                . ' (batch ' . $batchNo . ') were added to stock.'
        );
        $connection->commit();
        send_json(['ok' => true, 'message' => number_format((int)$quantity) . ' unit(s) of ' . $medicine['name'] . ' added to stock.']);
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        error_log('API stock in error: ' . $exception->getMessage());
        send_json(['error' => 'The stock-in record could not be saved.'], 500);
    }
}

if ($route === 'stock-out' && $method === 'POST') {
    api_require_auth();
    csrf_guard();
    $connection = $db();
    $errors = [];
    $medicineId = int_input('medicine_id');
    $batchNo = str_input('batch_no');
    $quantity = str_input('quantity');
    $released = str_input('released_date');
    $reason = str_input('reason');
    $batchStatement = $connection->prepare(
        'SELECT b.id, b.quantity, m.name, m.unit FROM medicine_batches b
         JOIN medicines m ON m.id = b.medicine_id
         WHERE b.medicine_id = :id AND b.batch_no = :batch LIMIT 1'
    );
    $batchStatement->execute(['id' => $medicineId, 'batch' => $batchNo]);
    $batch = $batchStatement->fetch();

    if (!$batch) {
        $errors[] = 'This batch does not exist for the selected medicine.';
    }
    if (!ctype_digit($quantity) || (int)$quantity <= 0) {
        $errors[] = 'Quantity released must be a positive whole number.';
    } elseif ($batch && (int)$quantity > (int)$batch['quantity']) {
        $errors[] = 'Only ' . number_format((int)$batch['quantity']) . ' unit(s) are available in this batch.';
    }
    if (!valid_date($released)) {
        $errors[] = 'Enter a valid date released.';
    }
    if (!isset(STOCK_OUT_REASONS[$reason])) {
        $errors[] = 'Select a reason for the stock-out.';
    }
    if ($errors) {
        send_json(['errors' => $errors], 422);
    }

    try {
        $connection->beginTransaction();
        $deduct = $connection->prepare(
            'UPDATE medicine_batches SET quantity = quantity - :quantity
             WHERE id = :id AND quantity >= :available'
        );
        $deduct->execute([
            'quantity' => (int)$quantity,
            'id' => (int)$batch['id'],
            'available' => (int)$quantity,
        ]);
        if ($deduct->rowCount() !== 1) {
            throw new RuntimeException('Insufficient stock available for this batch.');
        }

        $note = 'Batch ' . $batchNo . ' - ' . strtolower(STOCK_OUT_REASONS[$reason]);
        $remarks = str_input('remarks');
        if ($remarks !== '') {
            $note .= ' - ' . $remarks;
        }
        $activity = $connection->prepare(
            'INSERT INTO inventory_activities (activity_type, medicine_id, quantity, note, performed_by)
             VALUES (:type, :id, :quantity, :note, :performed_by)'
        );
        $activity->execute([
            'type' => 'stock_out',
            'id' => $medicineId,
            'quantity' => -(int)$quantity,
            'note' => mb_substr($note, 0, 255),
            'performed_by' => (string)($_SESSION['full_name'] ?? 'Clinic Personnel'),
        ]);
        create_notification(
            $connection,
            (int)$quantity . ' ' . $batch['unit'] . '(s) of ' . $batch['name']
                . ' (batch ' . $batchNo . ') were recorded as stock out: ' . strtolower(STOCK_OUT_REASONS[$reason]) . '.'
        );
        $connection->commit();
        send_json(['ok' => true, 'message' => number_format((int)$quantity) . ' unit(s) of ' . $batch['name'] . ' recorded as stock out.']);
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        error_log('API stock out error: ' . $exception->getMessage());
        send_json(['error' => 'The stock-out record could not be saved.'], 500);
    }
}

if ($route === 'expiration' && $method === 'GET') {
    api_require_auth();
    $connection = $db();
    $status = trim((string)($_GET['status'] ?? 'all'));
    $search = trim((string)($_GET['search'] ?? ''));

    $conditions = [];
    $params = [];
    if ($status === 'expired') {
        $conditions[] = 'b.expiration_date < CURDATE()';
    } elseif ($status === 'soon') {
        $conditions[] = 'b.expiration_date >= CURDATE() AND b.expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)';
    } elseif ($status === 'valid') {
        $conditions[] = 'b.expiration_date > DATE_ADD(CURDATE(), INTERVAL 30 DAY)';
    }
    if ($search !== '') {
        $conditions[] = '(m.name LIKE :search OR b.batch_no LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }

    $sql = 'SELECT m.name, m.unit, m.location, b.batch_no, b.quantity, b.expiration_date,
                   DATEDIFF(b.expiration_date, CURDATE()) AS days_left
            FROM medicine_batches b JOIN medicines m ON m.id = b.medicine_id';
    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $sql .= ' ORDER BY b.expiration_date ASC LIMIT 100';
    $statement = $connection->prepare($sql);
    $statement->execute($params);

    send_json([
        'summary' => [
            'expired' => (int)$connection->query('SELECT COUNT(*) FROM medicine_batches WHERE expiration_date < CURDATE()')->fetchColumn(),
            'soon' => (int)$connection->query('SELECT COUNT(*) FROM medicine_batches WHERE expiration_date >= CURDATE() AND expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)')->fetchColumn(),
            'valid' => (int)$connection->query('SELECT COUNT(*) FROM medicine_batches WHERE expiration_date > DATE_ADD(CURDATE(), INTERVAL 30 DAY)')->fetchColumn(),
            'units_at_risk' => (int)$connection->query('SELECT COALESCE(SUM(quantity), 0) FROM medicine_batches WHERE expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)')->fetchColumn(),
        ],
        'rows' => $statement->fetchAll(),
    ]);
}

if ($route === 'reports' && $method === 'GET') {
    api_require_auth();
    $connection = $db();
    $types = ['inventory' => 'Inventory Summary', 'movement' => 'Stock Movement', 'expiration' => 'Expiration Report', 'low-stock' => 'Low Stock Report'];
    $type = (string)($_GET['report_type'] ?? 'inventory');
    if (!isset($types[$type])) {
        $type = 'inventory';
    }
    $from = trim((string)($_GET['date_from'] ?? ''));
    $to = trim((string)($_GET['date_to'] ?? ''));
    if ($from !== '' && !valid_date($from)) {
        $from = '';
    }
    if ($to !== '' && !valid_date($to)) {
        $to = '';
    }

    $summary = [
        'medicines' => (int)$connection->query('SELECT COUNT(*) FROM medicines')->fetchColumn(),
        'batches' => (int)$connection->query('SELECT COUNT(*) FROM medicine_batches')->fetchColumn(),
        'usable_units' => (int)$connection->query('SELECT COALESCE(SUM(quantity), 0) FROM medicine_batches WHERE expiration_date >= CURDATE()')->fetchColumn(),
        'expired_batches' => (int)$connection->query('SELECT COUNT(*) FROM medicine_batches WHERE expiration_date < CURDATE()')->fetchColumn(),
    ];
    $snapshot = $connection->query(
        'SELECT m.name, m.category, m.unit, m.low_stock_threshold,
                COUNT(b.id) AS batch_count, COALESCE(SUM(b.quantity), 0) AS total_quantity,
                COALESCE(SUM(CASE WHEN b.expiration_date >= CURDATE() THEN b.quantity ELSE 0 END), 0) AS usable_quantity
         FROM medicines m LEFT JOIN medicine_batches b ON b.medicine_id = m.id
         GROUP BY m.id, m.name, m.category, m.unit, m.low_stock_threshold
         ORDER BY m.name ASC LIMIT 10'
    )->fetchAll();

    $headers = [];
    $rows = [];
    if ($type === 'inventory' || $type === 'low-stock') {
        $sql = 'SELECT m.name, m.category, m.unit, m.low_stock_threshold,
                       COUNT(b.id) AS batch_count, COALESCE(SUM(b.quantity), 0) AS total_quantity,
                       COALESCE(SUM(CASE WHEN b.expiration_date >= CURDATE() THEN b.quantity ELSE 0 END), 0) AS usable_quantity
                FROM medicines m LEFT JOIN medicine_batches b ON b.medicine_id = m.id
                GROUP BY m.id, m.name, m.category, m.unit, m.low_stock_threshold';
        if ($type === 'low-stock') {
            $sql .= ' HAVING usable_quantity <= m.low_stock_threshold';
        }
        $sql .= ' ORDER BY m.name';
        $headers = ['Medicine', 'Category', 'Unit', 'Batches', 'Total Qty', 'Usable Qty', 'Min Level', 'Status'];
        foreach ($connection->query($sql)->fetchAll() as $row) {
            $usable = (int)$row['usable_quantity'];
            $rows[] = [
                $row['name'], $row['category'], $row['unit'], (int)$row['batch_count'],
                (int)$row['total_quantity'], $usable, (int)$row['low_stock_threshold'],
                status_of($usable, (int)$row['low_stock_threshold']),
            ];
        }
    } elseif ($type === 'movement') {
        $conditions = ['a.medicine_id IS NOT NULL'];
        $params = [];
        if ($from !== '') {
            $conditions[] = 'a.created_at >= :from';
            $params['from'] = $from . ' 00:00:00';
        }
        if ($to !== '') {
            $conditions[] = 'a.created_at <= :to';
            $params['to'] = $to . ' 23:59:59';
        }
        $sql = 'SELECT a.created_at, a.activity_type, COALESCE(m.name, "-") AS medicine,
                       a.quantity, a.note, a.performed_by
                FROM inventory_activities a LEFT JOIN medicines m ON m.id = a.medicine_id
                WHERE ' . implode(' AND ', $conditions) . ' ORDER BY a.id DESC LIMIT 500';
        $statement = $connection->prepare($sql);
        $statement->execute($params);
        $headers = ['Date', 'Type', 'Medicine', 'Quantity', 'Details', 'Recorded By'];
        foreach ($statement->fetchAll() as $row) {
            $rows[] = [
                date('Y-m-d H:i', strtotime((string)$row['created_at'])),
                ucwords(str_replace('_', ' ', (string)$row['activity_type'])),
                $row['medicine'], (int)$row['quantity'], (string)($row['note'] ?? ''), $row['performed_by'],
            ];
        }
    } else {
        $conditions = [];
        $params = [];
        if ($from !== '') {
            $conditions[] = 'b.expiration_date >= :from';
            $params['from'] = $from;
        }
        if ($to !== '') {
            $conditions[] = 'b.expiration_date <= :to';
            $params['to'] = $to;
        }
        $sql = 'SELECT m.name, b.batch_no, b.quantity, b.expiration_date,
                       DATEDIFF(b.expiration_date, CURDATE()) AS days_left, b.supplier
                FROM medicine_batches b JOIN medicines m ON m.id = b.medicine_id';
        if ($conditions) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY b.expiration_date ASC';
        $statement = $connection->prepare($sql);
        $statement->execute($params);
        $headers = ['Medicine', 'Batch', 'Quantity', 'Expiry', 'Days Left', 'Supplier', 'Status'];
        foreach ($statement->fetchAll() as $row) {
            $days = (int)$row['days_left'];
            $rows[] = [
                $row['name'], $row['batch_no'], (int)$row['quantity'], $row['expiration_date'], $days,
                $row['supplier'], $days < 0 ? 'Expired' : ($days <= 30 ? 'Expiring soon' : 'Valid'),
            ];
        }
    }

    send_json([
        'types' => $types,
        'type' => $type,
        'from' => $from,
        'to' => $to,
        'title' => $types[$type] . ' - School Clinic',
        'period_label' => ($from !== '' || $to !== '')
            ? 'Period: ' . ($from !== '' ? $from : 'beginning') . ' to ' . ($to !== '' ? $to : 'present')
            : 'All recorded data',
        'summary' => $summary,
        'snapshot' => $snapshot,
        'headers' => $headers,
        'rows' => $rows,
    ]);
}

if ($route === 'notifications' && $method === 'GET') {
    api_require_auth();
    $connection = $db();
    $rows = $connection->query('SELECT id, message, is_read, created_at FROM notifications ORDER BY id DESC LIMIT 50')->fetchAll();
    send_json(['rows' => $rows, 'unread_count' => unread_count($connection)]);
}

if ($route === 'notifications/read' && $method === 'POST') {
    api_require_auth();
    csrf_guard();
    $connection = $db();
    $body = request_body();
    if (!empty($body['all'])) {
        $connection->query('UPDATE notifications SET is_read = 1 WHERE is_read = 0');
    } else {
        $statement = $connection->prepare('UPDATE notifications SET is_read = 1 WHERE id = :id');
        $statement->execute(['id' => int_input('id')]);
    }
    send_json(['ok' => true, 'unread_count' => unread_count($connection)]);
}

if ($route === 'notification/delete' && $method === 'POST') {
    api_require_auth();
    csrf_guard();
    $connection = $db();
    $id = int_input('id');
    $statement = $connection->prepare('SELECT message FROM notifications WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $id]);
    $notification = $statement->fetch();
    if (!$notification) {
        send_json(['error' => 'Notification not found.'], 404);
    }
    suppress_notification_message($connection, (string)$notification['message']);
    $connection->prepare('DELETE FROM notifications WHERE id = :id')->execute(['id' => $id]);
    send_json(['ok' => true, 'unread_count' => unread_count($connection)]);
}

if ($route === 'settings' && $method === 'GET') {
    api_require_auth();
    $connection = $db();
    $settings = [];
    foreach ($connection->query('SELECT setting_key, setting_value FROM app_settings') as $row) {
        $settings[(string)$row['setting_key']] = (string)$row['setting_value'];
    }
    $statement = $connection->prepare('SELECT id, email, full_name, role FROM users WHERE id = :id LIMIT 1');
    $statement->execute(['id' => current_user_id()]);
    send_json(['user' => $statement->fetch(), 'settings' => $settings]);
}

if ($route === 'settings' && $method === 'POST') {
    api_require_auth();
    csrf_guard();
    $connection = $db();
    $fullName = str_input('full_name');
    $email = strtolower(str_input('email'));
    $newPassword = (string)(request_body()['new_password'] ?? '');
    $confirmPassword = (string)(request_body()['confirm_password'] ?? '');
    $errors = [];

    if ($fullName === '' || mb_strlen($fullName) > 120) {
        $errors[] = 'Full name is required (120 characters or fewer).';
    }
    if (!preg_match('/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.edu\.ph$/', $email)) {
        $errors[] = 'Enter a valid institutional email address.';
    }
    if ($newPassword !== '') {
        if (strlen($newPassword) < 8) {
            $errors[] = 'New password must be at least 8 characters long.';
        }
        if ($newPassword !== $confirmPassword) {
            $errors[] = 'New passwords do not match.';
        }
    }
    if ($errors) {
        send_json(['errors' => $errors], 422);
    }

    try {
        $connection->beginTransaction();
        $duplicate = $connection->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
        $duplicate->execute(['email' => $email, 'id' => current_user_id()]);
        if ($duplicate->fetch()) {
            throw new RuntimeException('That email address is already in use.');
        }
        if ($newPassword !== '') {
            $updateUser = $connection->prepare('UPDATE users SET email = :email, full_name = :name, password_hash = :hash, updated_at = NOW() WHERE id = :id');
            $updateUser->execute([
                'email' => $email,
                'name' => $fullName,
                'hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                'id' => current_user_id(),
            ]);
        } else {
            $updateUser = $connection->prepare('UPDATE users SET email = :email, full_name = :name, updated_at = NOW() WHERE id = :id');
            $updateUser->execute(['email' => $email, 'name' => $fullName, 'id' => current_user_id()]);
        }

        $keys = [
            'clinic_name', 'clinic_address', 'clinic_contact', 'low_stock_threshold', 'expiry_window',
            'notify_low_stock', 'notify_expiring', 'notify_expired', 'notify_summary',
            'items_per_page', 'date_format', 'landing_page',
        ];
        $upsert = $connection->prepare(
            'INSERT INTO app_settings (setting_key, setting_value) VALUES (:key, :value)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
        );
        foreach ($keys as $key) {
            $value = str_input($key);
            if (str_starts_with($key, 'notify_')) {
                $value = !empty(request_body()[$key]) ? '1' : '0';
            }
            $upsert->execute(['key' => $key, 'value' => mb_substr($value, 0, 255)]);
        }
        $connection->commit();

        $_SESSION['email'] = $email;
        $_SESSION['full_name'] = $fullName;
        send_json(['ok' => true, 'message' => 'Settings saved successfully.', 'session' => session_payload($pdo)]);
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        error_log('API settings save error: ' . $exception->getMessage());
        send_json(['errors' => [$exception->getMessage()]], 422);
    }
}

send_json(['error' => 'Unknown API endpoint.'], 404);
