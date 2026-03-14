<?php

/* ================= LEGACY DEBUG LOG ================= */

$logFile = dirname(__DIR__, 2) . '/var/log/legacyMpesa.log';

function legacyMpesaLog($message)
{
    $logFile = dirname(__DIR__, 2) . '/var/log/legacyMpesa.log';

    $entry = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;

    error_log($entry, 3, $logFile);
}

legacyMpesaLog("login.php loaded");


/* ================= SESSION START ================= */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
    legacyMpesaLog("Session started");
} else {
    legacyMpesaLog("Session already active");
}

legacyMpesaLog("Session ID: " . session_id());


/* ================= CREDENTIALS ================= */

$admin_email = 'info@komagardensresort.co.ke';
$admin_password = 'Komaresort@35';


/* ================= MANUAL LOGIN ================= */

if (isset($_POST['email'], $_POST['password'])) {

    legacyMpesaLog("Login attempt: " . $_POST['email']);

    if ($_POST['email'] === $admin_email && $_POST['password'] === $admin_password) {

        legacyMpesaLog("Login success");

        session_regenerate_id(true);

        $_SESSION['admin_logged_in'] = true;
        $_SESSION['user_locked'] = true;
        unset($_SESSION['user_unlocked'], $_SESSION['user_role']);

        if (isset($_POST['remember'])) {
            setcookie('admin_logged_in', '1', time() + (30 * 24 * 60 * 60), "/");
            legacyMpesaLog("Remember cookie set");
        }

        legacyMpesaLog("Redirecting to /legacy/mpesa/dashboard");

        header("Location: /legacy/mpesa/dashboard");
        exit;

    } else {

        legacyMpesaLog("Invalid credentials");

        $error = "Invalid credentials";
    }
}


/* ================= COOKIE AUTO LOGIN ================= */

if (!isset($_SESSION['admin_logged_in']) && isset($_COOKIE['admin_logged_in'])) {

    legacyMpesaLog("Cookie auto-login triggered");

    session_regenerate_id(true);

    $_SESSION['admin_logged_in'] = true;
    $_SESSION['user_locked'] = true;
    unset($_SESSION['user_unlocked'], $_SESSION['user_role']);

    legacyMpesaLog("Redirecting to /mpesa via cookie");

    header("Location: /mpesa");
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login | Koma Gardens & Resort</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 flex justify-center items-center min-h-screen">

<div class="bg-white p-8 rounded shadow w-full max-w-md">

    <h2 class="text-2xl font-bold mb-6 text-center">
        Admin Login
    </h2>

    <?php if (isset($error)): ?>
        <div class="text-red-500 mb-3 text-center">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="/legacy/mpesa/login" class="space-y-4">

        <input
            type="email"
            name="email"
            placeholder="Email"
            required
            class="w-full p-2 border rounded"
        >

        <input
            type="password"
            name="password"
            placeholder="Password"
            required
            class="w-full p-2 border rounded"
        >

        <div class="flex items-center">
            <input type="checkbox" name="remember" id="remember" class="mr-2">
            <label for="remember" class="text-sm">
                Remember me
            </label>
        </div>

        <button
            type="submit"
            class="w-full bg-blue-600 text-white p-2 rounded hover:bg-blue-700"
        >
            Login
        </button>

    </form>

</div>

</body>
</html>
