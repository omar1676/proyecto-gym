<?php

$root = dirname(__DIR__, 2);
$ok = 0;
$fail = 0;
$check = static function (string $description, bool $condition) use (&$ok, &$fail): void {
    if ($condition) {
        $ok++;
    } else {
        $fail++;
        echo "FALLO: {$description}\n";
    }
};

$views = [
    'app/views/_header.php',
    'app/views/_header_admin.php',
    'app/views/auth/forgot.php',
    'app/views/auth/login.php',
    'app/views/auth/login_plataforma.php',
    'app/views/auth/reset.php',
];
$expectedSri = 'sha384-aJ9rL4k6lF+91guGvUFVSkpIcge7Zd9EiI4TQDLoK9kFaFJgKHgjEXVvG/qA5COj';
foreach ($views as $view) {
    $contents = (string) file_get_contents($root . '/' . $view);
    $check($view . ' fija Tailwind exacto', str_contains($contents, '@tailwindcss/browser@4.3.3'));
    $check($view . ' incluye SRI esperado', str_contains($contents, 'integrity="' . $expectedSri . '"'));
    $check($view . ' exige CORS anónimo para SRI', str_contains($contents, 'crossorigin="anonymous"'));
    $check($view . ' no conserva selector mutable @4', !str_contains($contents, '@tailwindcss/browser@4"'));
}

$footer = (string) file_get_contents($root . '/app/views/_footer.php');
$check('Simple Icons está fijado a versión exacta', substr_count($footer, 'simple-icons@11.15.0/') === 3);
$check('Simple Icons no conserva selector mayor mutable', !str_contains($footer, 'simple-icons@v11/'));

$headers = (string) file_get_contents($root . '/app/helpers/SecurityHeaders.php');
$check('CSP elimina cdnjs no utilizado', !str_contains($headers, 'cdnjs.cloudflare.com'));
$check('CSP elimina Google Fonts no utilizado', !str_contains($headers, 'fonts.googleapis.com')
    && !str_contains($headers, 'fonts.gstatic.com'));

echo "RESUMEN: {$ok} correctas, {$fail} fallidas\n";
exit($fail ? 1 : 0);
